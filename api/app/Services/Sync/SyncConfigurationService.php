<?php

namespace App\Services\Sync;

use App\Models\SyncChange;
use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Models\SyncNodeCursor;
use App\Models\SyncOutboxEvent;
use App\Services\License\MobileLicenseEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Company\Models\Company;
use Modules\Product\Support\HospitalityTradingDatePolicy;
use RuntimeException;

class SyncConfigurationService
{
    /** @var array<string, bool> */
    private array $pendingSaleMemo = [];

    private ?bool $signalTablesAvailable = null;

    /**
     * Schema lookups cached while one snapshot is built or applied. On MySQL
     * every hasTable/hasColumn is an information_schema query, and applying a
     * full BOM snapshot issued ~68,000 of them (65 of 79 seconds). Null
     * outside those operations, so a long-running sync worker still sees
     * columns added by a later migration.
     *
     * @var array{tables: array<string, bool>, columns: array<string, array<int, string>>, column_sets: array<string, array<string, int>>}|null
     */
    private ?array $schemaMemo = null;

    /**
     * Snapshot schema versions an edge can apply. The cloud stamps both the
     * change envelope and the payload with servnest.configuration_schema_version.
     */
    public const EDGE_SNAPSHOT_SCHEMA_VERSIONS = [1, 2];

    /**
     * Explicit schema-v1 cloud -> edge allowlist. Secrets, access tokens,
     * fiscal private keys, QZ private keys and terminal registration credentials are
     * deliberately absent.
     */
    private const DATASETS = [
        'countries' => [
            'scope' => 'node-country-row',
            'columns' => ['id', 'code', 'iso_alpha_2', 'iso_alpha_3', 'name', 'currency_code', 'timezone', 'locale', 'image_path', 'image_url', 'metadata', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'companies' => [
            'scope' => 'company-row',
            'columns' => ['id', 'country_id', 'company_code', 'company_name', 'location_address', 'city', 'country', 'domain', 'host_ip', 'license_support_phone', 'logo', 'status', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'brands' => [
            'scope' => 'node-brand-row',
            'columns' => ['id', 'company_id', 'country_id', 'code', 'name', 'description', 'logo_path', 'logo_url', 'status', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'complexes' => [
            'scope' => 'node-complex-row',
            'columns' => ['id', 'company_id', 'country_id', 'code', 'name', 'address', 'city', 'timezone', 'latitude', 'longitude', 'status', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'company_branches' => [
            'scope' => 'branch-row',
            'columns' => ['id', 'company_id', 'branch_name', 'branch_code', 'branch_address', 'branch_city', 'branch_country', 'level', 'is_main_branch', 'status', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'shops' => [
            'scope' => 'shop-row',
            'columns' => ['id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'code', 'name', 'address', 'city', 'timezone', 'pos_login_mode', 'configuration_version', 'status', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'pos_tills' => [
            'scope' => 'branch',
            // Registration/activation credentials, presence fields and the
            // generic updated_at timestamp are operational state. Real till
            // configuration fields below remain part of the content hash.
            'columns' => ['id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'shop_id', 'till_code', 'till_name', 'device_label', 'configuration_version', 'is_active', 'created_at', 'deleted_at'],
        ],
        'printers' => [
            'scope' => 'branch-printers',
            // QZ certificates and private keys are deliberately excluded.
            // Printer names and routing metadata are safe configuration.
            'columns' => ['id', 'company_id', 'branch_id', 'till_id', 'printer_name', 'target_printing', 'printer_type', 'is_default', 'is_active', 'paper_size', 'connection_type', 'ip_address', 'port', 'notes', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'hospitality_output_configurations' => [
            'scope' => 'node-output-configurations',
            'columns' => ['id', 'company_id', 'branch_id', 'till_id', 'mode', 'auto_print', 'copies', 'dispatch_on', 'version', 'configured_by', 'created_at', 'updated_at'],
        ],
        'hospitality_table_settings' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'shop_id', 'assignment_mode', 'require_table_for_dine_in', 'allow_cross_till_recall', 'is_active', 'metadata', 'created_at', 'updated_at'],
        ],
        'hospitality_tables' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'shop_id', 'code', 'name', 'section', 'table_type', 'capacity', 'sort_order', 'is_active', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'auth_policies' => [
            'scope' => 'edge-auth-policies',
            // Only enforcement settings are projected. Cloud actor IDs and
            // arbitrary policy keys/settings never leave Central.
            'columns' => ['id', 'policy_key', 'scope_type', 'scope_key', 'country_id', 'company_id', 'brand_id', 'complex_id', 'shop_id', 'till_id', 'priority', 'version', 'settings', 'is_active', 'effective_from', 'effective_until', 'created_at', 'updated_at'],
        ],
        'licenses' => [
            'scope' => 'company',
            'columns' => ['id', 'company_id', 'license_key', 'license_type', 'max_branches', 'max_users', 'max_devices', 'starts_at', 'expires_at', 'status', 'renewal_source', 'entitlement_payload', 'entitlement_signature', 'entitlement_payload_v2', 'entitlement_signature_v2', 'signature_algorithm', 'signature_key_id', 'entitlement_version', 'entitlement_revision', 'signed_at', 'activated_at', 'revoked_at', 'created_at', 'updated_at'],
        ],
        'roles' => [
            // Company roles plus any global role referenced by a user who is
            // allowed to sign in at this paired branch. Global platform
            // administration remains disabled on edge deployments.
            'scope' => 'branch-user-roles',
            'columns' => ['id', 'company_id', 'name', 'group', 'description', 'created_at', 'updated_at'],
        ],
        'permissions' => [
            // Permission definitions contain no tenant data. Project the
            // complete catalogue so edge authorization never depends on a
            // permission already being assigned when a snapshot is built.
            'scope' => 'global-catalogue',
            'columns' => ['id', 'name', 'group', 'description', 'created_at', 'updated_at'],
        ],
        'role_permissions' => [
            'scope' => 'role-permissions',
            'columns' => ['id', 'company_id', 'role_id', 'permission_id', 'created_at', 'updated_at'],
        ],
        'pos_users' => [
            'scope' => 'branch-users',
            // Password/code values are one-way verifiers required for offline
            // edge authentication. Plaintext credentials and the HMAC key are
            // never stored in this table or projected. Android gets no users.
            'columns' => ['id', 'company_id', 'branch_id', 'role_id', 'name', 'username', 'phone', 'password', 'login_code', 'login_code_lookup', 'login_code_lookup_key_id', 'credential_version', 'credentials_changed_at', 'employee_code', 'user_type', 'can_login_pos', 'can_access_front_office', 'can_access_back_office', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'user_access_assignments' => [
            'scope' => 'edge-user-access',
            // Grant/revoke actor IDs and free-text reasons are Central audit
            // data. Edge receives only the effective authorization facts.
            'columns' => ['id', 'pos_user_id', 'role_id', 'scope_type', 'scope_key', 'country_id', 'company_id', 'brand_id', 'complex_id', 'shop_id', 'till_id', 'status', 'valid_from', 'valid_until', 'revoked_at', 'created_at', 'updated_at'],
        ],
        'user_branches' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'pos_user_id', 'branch_id', 'is_default', 'created_at', 'updated_at'],
        ],
        'pos_user_shift_assignments' => [
            'scope' => 'branch-user-shift-assignments',
            'columns' => ['id', 'company_id', 'branch_id', 'pos_user_id', 'shift_configuration_id', 'shift_name', 'created_at', 'updated_at'],
        ],
        'credit_customers' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'account_number', 'customer_code', 'name', 'trading_name', 'tax_number', 'phone', 'email', 'billing_address', 'delivery_address', 'credit_limit', 'opening_balance', 'current_balance', 'available_credit', 'currency_code', 'payment_terms_days', 'allow_credit_sales', 'requires_po_number', 'is_active', 'status', 'approved_at', 'blocked_at', 'block_reason', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_categories' => [
            'scope' => 'branch-product-categories',
            'columns' => ['id', 'company_id', 'parent_id', 'name', 'code', 'description', 'image_path', 'image_url', 'banner_image_path', 'banner_image_url', 'display_area', 'sort_order', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_sub_categories' => [
            'scope' => 'branch-product-sub-categories',
            'columns' => ['id', 'company_id', 'category_id', 'name', 'code', 'description', 'image_path', 'image_url', 'banner_image_path', 'banner_image_url', 'display_area', 'is_active', 'sort_order', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'uoms' => [
            // Standard UOMs are a shared catalogue (company_id = NULL), while
            // an imported company-specific UOM remains visible only to its
            // owner. Never expose another tenant's custom units.
            'scope' => 'branch-product-uoms',
            'columns' => ['id', 'company_id', 'name', 'code', 'quantity_type', 'decimal_places', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'price_levels' => [
            'scope' => 'company',
            'columns' => ['id', 'company_id', 'name', 'code', 'description', 'is_default', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'role_price_levels' => [
            'scope' => 'company',
            'columns' => ['id', 'company_id', 'role_id', 'price_level_id', 'created_at', 'updated_at'],
        ],
        'suppliers' => [
            'scope' => 'branch-product-suppliers',
            'columns' => ['id', 'company_id', 'name', 'code', 'contact_person', 'phone', 'email', 'tax_number', 'address', 'notes', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'inventory_warehouses' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'code', 'name', 'warehouse_type', 'address', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'inventory_locations' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'warehouse_id', 'code', 'name', 'location_type', 'pick_sequence', 'is_quarantine', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'products' => [
            'scope' => 'branch-products',
            'columns' => ['id', 'company_id', 'is_global', 'branch_id', 'category_id', 'sub_category_id', 'supplier_id', 'parent_product_id', 'base_uom_id', 'name', 'code', 'sku', 'barcode', 'description', 'hs_code', 'image_path', 'image_url', 'product_type', 'stock_usage_type', 'default_issue_destination', 'expense_account_code', 'stock_tracking_enabled', 'batch_tracking_enabled', 'expiry_tracking_enabled', 'default_shelf_life_days', 'allow_negative_stock', 'allow_discount', 'taxable', 'tax_id', 'reorder_level', 'minimum_stock', 'maximum_stock', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'brand_products' => [
            'scope' => 'node-brand-products',
            'columns' => ['id', 'company_id', 'brand_id', 'product_id', 'item_kind', 'external_code', 'legacy_code', 'market', 'department', 'flavour', 'size_label', 'stock_group', 'source_metadata', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_uom_conversions' => [
            'scope' => 'branch-product-relations',
            'columns' => ['id', 'company_id', 'product_id', 'from_uom_id', 'to_uom_id', 'conversion_factor', 'source_key', 'is_active', 'created_at', 'updated_at'],
        ],
        'menu_item_channels' => [
            'scope' => 'node-brand-product-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'product_id', 'channel_code', 'price_exclusive', 'price_inclusive', 'currency_code', 'is_available', 'starts_at', 'ends_at', 'metadata', 'created_at', 'updated_at'],
        ],
        'menu_item_trading_schedules' => [
            'scope' => 'node-brand-product-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'product_id', 'schedule_code', 'weekdays', 'starts_at', 'ends_at', 'valid_from', 'valid_to', 'is_active', 'source_metadata', 'created_at', 'updated_at'],
        ],
        'modifier_groups' => [
            'scope' => 'node-brand',
            'columns' => ['id', 'company_id', 'brand_id', 'code', 'name', 'minimum_selections', 'maximum_selections', 'is_required', 'is_active', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'modifier_group_options' => [
            'scope' => 'node-brand-modifier-options',
            'columns' => ['id', 'company_id', 'brand_id', 'modifier_group_id', 'modifier_product_id', 'external_code', 'sort_order', 'price_delta', 'is_default', 'is_active', 'metadata', 'created_at', 'updated_at'],
        ],
        'menu_item_modifier_groups' => [
            'scope' => 'node-brand-product-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'menu_product_id', 'modifier_group_id', 'sort_order', 'minimum_selections', 'maximum_selections', 'is_active', 'created_at', 'updated_at'],
        ],
        'menu_item_modifier_exclusions' => [
            'scope' => 'node-brand-product-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'menu_product_id', 'modifier_option_id', 'reason', 'created_at', 'updated_at'],
        ],
        'shop_menu_item_overrides' => [
            'scope' => 'node-shop-product-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'shop_id', 'product_id', 'is_available', 'channel_code', 'channel_key', 'starts_at', 'ends_at', 'reason', 'configuration_version', 'created_at', 'updated_at'],
        ],
        'product_break_rules' => [
            'scope' => 'branch-product-break-rules',
            'columns' => ['id', 'company_id', 'parent_product_id', 'child_product_id', 'parent_quantity', 'units_per_parent', 'is_auto_break', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'food_recipes' => [
            'scope' => 'branch-recipes',
            'columns' => ['id', 'company_id', 'brand_id', 'menu_product_id', 'produced_product_id', 'name', 'external_code', 'source_key', 'recipe_type', 'version_no', 'yield_quantity', 'yield_uom_id', 'currency', 'source_stock_area', 'destination_stock_area', 'ingredient_cost', 'wastage_cost', 'packaging_cost', 'labour_cost', 'overhead_cost', 'total_cost', 'cost_per_unit', 'notes', 'effective_from', 'effective_to', 'is_current', 'last_costed_at', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'food_recipe_ingredients' => [
            'scope' => 'branch-recipe-ingredients',
            'columns' => ['id', 'company_id', 'food_recipe_id', 'child_recipe_id', 'ingredient_product_id', 'uom_id', 'line_type', 'source_line_key', 'sequence_no', 'quantity', 'unit_cost', 'line_total', 'wastage_percent', 'notes', 'created_at', 'updated_at'],
        ],
        'product_prep_messages' => [
            'scope' => 'node-brand-prep-messages',
            'columns' => ['id', 'company_id', 'brand_id', 'branch_id', 'message', 'code', 'allow_free_text', 'is_active', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_prep_message_assignments' => [
            'scope' => 'node-brand-prep-message-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'branch_id', 'product_id', 'prep_message_id', 'is_default', 'sort_order', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_modifiers' => [
            'scope' => 'node-brand-legacy-modifiers',
            'columns' => ['id', 'company_id', 'brand_id', 'branch_id', 'name', 'code', 'modifier_product_id', 'recipe_id', 'price', 'currency_code', 'deduct_recipe_stock', 'print_on_kitchen', 'print_on_customer_receipt', 'is_active', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_modifier_assignments' => [
            'scope' => 'node-brand-legacy-modifier-relations',
            'columns' => ['id', 'company_id', 'brand_id', 'branch_id', 'product_id', 'modifier_id', 'is_required', 'min_select', 'max_select', 'sort_order', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'discount_types' => [
            'scope' => 'company',
            'columns' => ['id', 'company_id', 'code', 'name', 'scope', 'calculation_type', 'requires_manager_approval', 'requires_reason', 'allow_stacking', 'is_system', 'is_active', 'rules', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'discounts' => [
            'scope' => 'company-branch',
            // usage_count is operational and deliberately not pulled over a local counter.
            'columns' => ['id', 'company_id', 'branch_id', 'discount_type_id', 'code', 'name', 'description', 'type', 'discount_basis', 'value', 'max_discount_amount', 'min_sale_amount', 'min_quantity', 'applies_to_all_products', 'applies_to_all_categories', 'requires_customer', 'requires_coupon_code', 'coupon_code', 'usage_limit', 'per_customer_limit', 'starts_at', 'ends_at', 'requires_manager_approval', 'requires_reason', 'allow_stacking', 'priority', 'rules', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'discount_products' => [
            'scope' => 'branch-discount-relations',
            'columns' => ['id', 'company_id', 'discount_id', 'product_id', 'created_at', 'updated_at'],
        ],
        'discount_categories' => [
            'scope' => 'branch-discount-relations',
            'columns' => ['id', 'company_id', 'discount_id', 'category_id', 'created_at', 'updated_at'],
        ],
        'discount_role_permissions' => [
            'scope' => 'branch-discount-relations',
            'columns' => ['id', 'company_id', 'discount_id', 'role_id', 'max_percentage', 'max_amount', 'can_apply_without_manager', 'created_at', 'updated_at'],
        ],
        'product_costs' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'product_id', 'average_cost', 'last_cost', 'total_quantity', 'total_value', 'currency', 'currency_code', 'created_at', 'updated_at'],
        ],
        'product_stocks' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'product_id', 'stock_area', 'quantity_on_hand', 'reserved_quantity', 'available_quantity', 'created_at', 'updated_at'],
        ],
        'inventory_batches' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'warehouse_id', 'location_id', 'product_id', 'lot_number', 'manufactured_at', 'expires_at', 'quantity_received', 'quantity_on_hand', 'reserved_quantity', 'quarantined_quantity', 'unit_cost', 'base_unit_cost', 'currency_code', 'exchange_rate', 'status', 'quality_status', 'notes', 'created_at', 'updated_at'],
        ],
        'inventory_location_balances' => [
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'warehouse_id', 'location_id', 'product_id', 'batch_id', 'quantity_on_hand', 'reserved_quantity', 'available_quantity', 'created_at', 'updated_at'],
        ],
        'product_barcodes' => [
            'scope' => 'branch-product-relations',
            'columns' => ['id', 'company_id', 'product_id', 'barcode', 'type', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_prices' => [
            'scope' => 'company-branch-product-relations',
            'columns' => ['id', 'company_id', 'product_id', 'branch_id', 'price_level_id', 'price', 'currency', 'is_default', 'is_active', 'starts_at', 'ends_at', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'taxes' => [
            'scope' => 'company',
            'columns' => ['id', 'company_id', 'name', 'tax_code', 'tax_id', 'type', 'rate', 'obliged_to_zimra', 'is_active', 'valid_from', 'valid_till', 'description', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'product_taxes' => [
            'scope' => 'company-branch-product-relations',
            'columns' => ['id', 'company_id', 'branch_id', 'product_id', 'tax_id', 'is_inclusive', 'is_active', 'starts_at', 'ends_at', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'currencies' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'code', 'name', 'country', 'is_active', 'is_base_currency', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'currency_rates' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'currency_id', 'rate', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'payment_types' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'name', 'money_code', 'type', 'supportsMultiCurrency', 'requiresAmountTendered', 'default_currency_code', 'integration_status', 'integration_number', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'general_settings' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'setting_key', 'setting_value', 'setting_type', 'module', 'description', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'shiftconfigurations' => [
            'scope' => 'company-branch',
            'columns' => ['id', 'company_id', 'branch_id', 'enable_shifts', 'shift_mode', 'morning_shift_start', 'morning_shift_end', 'afternoon_shift_start', 'afternoon_shift_end', 'night_shift_start', 'night_shift_end', 'auto_shift_logout', 'auto_logout_after_minutes', 'require_opening_float', 'require_closing_cashup', 'allow_multiple_open_shifts', 'allow_unscheduled_shifts', 'early_clock_in_minutes', 'shift_warning_before_end_minutes', 'shift_grace_minutes_after_end', 'allow_continue_unclosed_shift', 'require_cashup_before_new_shift', 'block_sales_after_shift_end', 'allow_supervisor_cashup', 'require_on_behalf_reason', 'status', 'created_at', 'updated_at', 'deleted_at'],
        ],
        'trading_days' => [
            // The Back Office is authoritative for a forced Day End. Pull the
            // complete branch calendar so an edge POS immediately observes the
            // closed day and its newly opened successor.
            'scope' => 'branch',
            'columns' => ['id', 'company_id', 'branch_id', 'trading_date', 'status', 'opened_at', 'day_end_started_at', 'closed_at', 'day_end_started_by', 'closed_by', 'lock_token', 'notes', 'metadata', 'created_at', 'updated_at'],
        ],
        'front_stock_daily_closings' => [
            // Branch-wide operational marker used by Day End. till_id is
            // provenance only; every till at this branch consumes the same
            // company + branch + date + FRONT closing.
            'scope' => 'branch-front-stock-closings',
            'columns' => ['id', 'company_id', 'branch_id', 'till_id', 'closed_by', 'closing_date', 'stock_area', 'status', 'total_lines', 'variance_lines', 'expected_total_quantity', 'counted_total_quantity', 'variance_total_quantity', 'variance_total_value', 'notes', 'metadata', 'created_at', 'updated_at', 'deleted_at'],
        ],
    ];

    /**
     * Android receives only the operational Front Office projection. In
     * particular, user password/PIN hashes, raw licensing rows and activation
     * keys, roles, permissions, supplier/back-office data and unrelated tills
     * never leave the cloud database for a mobile device. A separate sanitized
     * signed mobile entitlement is added to mobile_context below.
     */
    private const ANDROID_DATASETS = [
        'countries',
        'companies',
        'brands',
        'complexes',
        'company_branches',
        'shops',
        'pos_tills',
        'printers',
        'hospitality_output_configurations',
        'hospitality_table_settings',
        'hospitality_tables',
        'product_categories',
        'product_sub_categories',
        'uoms',
        'price_levels',
        'products',
        'brand_products',
        'product_uom_conversions',
        'menu_item_channels',
        'menu_item_trading_schedules',
        'modifier_groups',
        'modifier_group_options',
        'menu_item_modifier_groups',
        'menu_item_modifier_exclusions',
        'shop_menu_item_overrides',
        'product_break_rules',
        'food_recipes',
        'food_recipe_ingredients',
        'product_prep_messages',
        'product_prep_message_assignments',
        'product_modifiers',
        'product_modifier_assignments',
        'discount_types',
        'discounts',
        'discount_products',
        'discount_categories',
        'product_stocks',
        'product_barcodes',
        'product_prices',
        'taxes',
        'product_taxes',
        'currencies',
        'currency_rates',
        'payment_types',
        'general_settings',
        'shiftconfigurations',
        'trading_days',
    ];

    /**
     * Pre-login mobile snapshots use a deliberately smaller projection for
     * rows that also contain Back Office financial or network details. The
     * BOM quantities required to deduct stock remain available, while recipe
     * costing and printer network/notes fields stay in Central/edge only.
     *
     * @var array<string, list<string>>
     */
    private const ANDROID_COLUMN_OVERRIDES = [
        'printers' => [
            'id', 'company_id', 'branch_id', 'till_id', 'printer_name',
            'target_printing', 'printer_type', 'is_default', 'is_active',
            'paper_size', 'connection_type', 'created_at', 'updated_at', 'deleted_at',
        ],
        'hospitality_output_configurations' => [
            'id', 'company_id', 'branch_id', 'till_id', 'mode', 'auto_print',
            'copies', 'dispatch_on', 'version', 'created_at', 'updated_at',
        ],
        'food_recipes' => [
            'id', 'company_id', 'brand_id', 'menu_product_id', 'produced_product_id',
            'name', 'external_code', 'source_key', 'recipe_type', 'version_no',
            'yield_quantity', 'yield_uom_id', 'source_stock_area',
            'destination_stock_area', 'notes', 'effective_from', 'effective_to',
            'is_current', 'is_active', 'created_at', 'updated_at', 'deleted_at',
        ],
        'food_recipe_ingredients' => [
            'id', 'company_id', 'food_recipe_id', 'child_recipe_id',
            'ingredient_product_id', 'uom_id', 'line_type', 'source_line_key',
            'sequence_no', 'quantity', 'wastage_percent', 'notes',
            'created_at', 'updated_at',
        ],
    ];

    private const APPLY_ORDER = [
        'countries', 'companies', 'brands', 'complexes', 'company_branches', 'shops', 'pos_tills',
        'printers', 'hospitality_output_configurations', 'hospitality_table_settings', 'hospitality_tables', 'auth_policies',
        'licenses', 'permissions', 'roles', 'role_permissions',
        'pos_users', 'user_access_assignments', 'user_branches', 'credit_customers', 'product_categories', 'product_sub_categories', 'uoms',
        'price_levels', 'role_price_levels', 'taxes', 'suppliers', 'inventory_warehouses', 'inventory_locations',
        'currencies', 'currency_rates', 'payment_types', 'products', 'brand_products',
        'product_uom_conversions', 'menu_item_channels', 'menu_item_trading_schedules',
        'modifier_groups', 'modifier_group_options', 'menu_item_modifier_groups',
        'menu_item_modifier_exclusions', 'shop_menu_item_overrides',
        'product_break_rules', 'food_recipes', 'food_recipe_ingredients',
        'product_prep_messages', 'product_prep_message_assignments',
        'product_modifiers', 'product_modifier_assignments',
        'discount_types', 'discounts', 'discount_products', 'discount_categories', 'discount_role_permissions',
        'product_costs', 'product_stocks', 'inventory_batches', 'inventory_location_balances',
        'product_barcodes', 'product_prices', 'product_taxes', 'general_settings', 'shiftconfigurations',
        'pos_user_shift_assignments', 'trading_days', 'front_stock_daily_closings',
    ];

    /** @var array<string, array<string, string>> */
    private const FOREIGN_DATASETS = [
        'companies' => ['country_id' => 'countries'],
        'brands' => ['country_id' => 'countries'],
        'complexes' => ['country_id' => 'countries'],
        'shops' => [
            'country_id' => 'countries',
            'brand_id' => 'brands',
            'complex_id' => 'complexes',
            'branch_id' => 'company_branches',
        ],
        'pos_tills' => [
            'country_id' => 'countries',
            'brand_id' => 'brands',
            'complex_id' => 'complexes',
            'branch_id' => 'company_branches',
            'shop_id' => 'shops',
        ],
        'printers' => ['branch_id' => 'company_branches', 'till_id' => 'pos_tills'],
        'hospitality_output_configurations' => [
            'branch_id' => 'company_branches',
            'till_id' => 'pos_tills',
        ],
        'hospitality_table_settings' => [
            'branch_id' => 'company_branches',
            'shop_id' => 'shops',
        ],
        'hospitality_tables' => [
            'branch_id' => 'company_branches',
            'shop_id' => 'shops',
        ],
        'auth_policies' => [
            'country_id' => 'countries',
            'company_id' => 'companies',
            'brand_id' => 'brands',
            'complex_id' => 'complexes',
            'shop_id' => 'shops',
            'till_id' => 'pos_tills',
        ],
        'role_permissions' => ['role_id' => 'roles', 'permission_id' => 'permissions'],
        'pos_users' => ['role_id' => 'roles'],
        'user_access_assignments' => [
            'pos_user_id' => 'pos_users',
            'role_id' => 'roles',
            'country_id' => 'countries',
            'company_id' => 'companies',
            'brand_id' => 'brands',
            'complex_id' => 'complexes',
            'shop_id' => 'shops',
            'till_id' => 'pos_tills',
        ],
        'user_branches' => ['pos_user_id' => 'pos_users', 'branch_id' => 'company_branches'],
        'pos_user_shift_assignments' => [
            'branch_id' => 'company_branches',
            'pos_user_id' => 'pos_users',
            'shift_configuration_id' => 'shiftconfigurations',
        ],
        'product_categories' => ['parent_id' => 'product_categories'],
        'product_sub_categories' => ['category_id' => 'product_categories'],
        'role_price_levels' => ['role_id' => 'roles', 'price_level_id' => 'price_levels'],
        'inventory_locations' => ['warehouse_id' => 'inventory_warehouses'],
        'products' => [
            'category_id' => 'product_categories',
            'sub_category_id' => 'product_sub_categories',
            'supplier_id' => 'suppliers',
            'parent_product_id' => 'products',
            'base_uom_id' => 'uoms',
            'tax_id' => 'taxes',
        ],
        'product_break_rules' => [
            'parent_product_id' => 'products',
            'child_product_id' => 'products',
        ],
        'food_recipes' => [
            'brand_id' => 'brands',
            'menu_product_id' => 'products',
            'produced_product_id' => 'products',
            'yield_uom_id' => 'uoms',
        ],
        'food_recipe_ingredients' => [
            'food_recipe_id' => 'food_recipes',
            'child_recipe_id' => 'food_recipes',
            'ingredient_product_id' => 'products',
            'uom_id' => 'uoms',
        ],
        'product_prep_messages' => ['brand_id' => 'brands', 'branch_id' => 'company_branches'],
        'product_prep_message_assignments' => [
            'brand_id' => 'brands',
            'branch_id' => 'company_branches',
            'product_id' => 'products',
            'prep_message_id' => 'product_prep_messages',
        ],
        'product_modifiers' => [
            'brand_id' => 'brands',
            'branch_id' => 'company_branches',
            'modifier_product_id' => 'products',
            'recipe_id' => 'food_recipes',
        ],
        'product_modifier_assignments' => [
            'brand_id' => 'brands',
            'branch_id' => 'company_branches',
            'product_id' => 'products',
            'modifier_id' => 'product_modifiers',
        ],
        'discounts' => [
            'branch_id' => 'company_branches',
            'discount_type_id' => 'discount_types',
        ],
        'discount_products' => ['discount_id' => 'discounts', 'product_id' => 'products'],
        'discount_categories' => ['discount_id' => 'discounts', 'category_id' => 'product_categories'],
        'discount_role_permissions' => ['discount_id' => 'discounts', 'role_id' => 'roles'],
        'product_costs' => ['product_id' => 'products'],
        'product_stocks' => ['product_id' => 'products'],
        'inventory_batches' => [
            'warehouse_id' => 'inventory_warehouses',
            'location_id' => 'inventory_locations',
            'product_id' => 'products',
        ],
        'inventory_location_balances' => [
            'warehouse_id' => 'inventory_warehouses',
            'location_id' => 'inventory_locations',
            'product_id' => 'products',
            'batch_id' => 'inventory_batches',
        ],
        'product_barcodes' => ['product_id' => 'products'],
        'brand_products' => ['brand_id' => 'brands', 'product_id' => 'products'],
        'product_uom_conversions' => [
            'product_id' => 'products',
            'from_uom_id' => 'uoms',
            'to_uom_id' => 'uoms',
        ],
        'menu_item_channels' => ['brand_id' => 'brands', 'product_id' => 'products'],
        'menu_item_trading_schedules' => ['brand_id' => 'brands', 'product_id' => 'products'],
        'modifier_groups' => ['brand_id' => 'brands'],
        'modifier_group_options' => [
            'brand_id' => 'brands',
            'modifier_group_id' => 'modifier_groups',
            'modifier_product_id' => 'products',
        ],
        'menu_item_modifier_groups' => [
            'brand_id' => 'brands',
            'menu_product_id' => 'products',
            'modifier_group_id' => 'modifier_groups',
        ],
        'menu_item_modifier_exclusions' => [
            'brand_id' => 'brands',
            'menu_product_id' => 'products',
            'modifier_option_id' => 'modifier_group_options',
        ],
        'shop_menu_item_overrides' => [
            'brand_id' => 'brands',
            'shop_id' => 'shops',
            'product_id' => 'products',
        ],
        'product_prices' => ['product_id' => 'products', 'price_level_id' => 'price_levels'],
        'product_taxes' => ['product_id' => 'products', 'tax_id' => 'taxes'],
        'currency_rates' => ['currency_id' => 'currencies'],
    ];

    private const SELF_PARENT_COLUMNS = [
        'product_categories' => 'parent_id',
        'products' => 'parent_product_id',
    ];

    public function __construct(private readonly SyncPayloadHasher $hasher) {}

    /**
     * Materialize the latest authoritative configuration for one paired
     * branch immediately after a cloud-side mutation.
     *
     * Pull remains the delivery mechanism (cloud installations cannot safely
     * initiate a connection into a branch LAN), but the snapshot head must be
     * committed when Day End completes rather than waiting for a till browser
     * to make the next status or pull request. Edge servers share one branch
     * stream; each Android terminal has a separate reduced, till-bound stream.
     */
    public function publishBranchSnapshot(string $companyId, string $branchId): ?SyncChange
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud' || ! $this->hasSignalTables()) {
            return null;
        }

        $nodes = SyncNode::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('deployment_mode', ['edge', 'android'])
            ->when((bool) config('salexa_sync.local_hub', false), fn ($query) => $query->where('deployment_mode', 'edge'))
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();

        // Edge nodes at one branch share the same complete projection. Each
        // Android node has a till-specific reduced projection and therefore
        // receives its own snapshot stream.
        $targets = $nodes
            ->filter(fn (SyncNode $node): bool => $node->deployment_mode === 'android')
            ->values();
        if ($edge = $nodes->firstWhere('deployment_mode', 'edge')) {
            $targets->prepend($edge);
        }

        $published = null;
        foreach ($targets as $node) {
            $change = $this->ensureSnapshotChange($node);
            $published ??= $change;
        }

        return $published;
    }

    /**
     * Publish the current configuration to every active branch stream for a
     * company. Company-wide settings (for example a currency with no branch)
     * affect every paired edge and Android terminal, so publishing only the
     * administrator's current branch would leave other branches stale.
     */
    public function publishCompanySnapshots(string $companyId): int
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud' || ! $this->hasSignalTables()) {
            return 0;
        }

        $branchIds = SyncNode::query()
            ->where('company_id', $companyId)
            ->whereIn('deployment_mode', (bool) config('salexa_sync.local_hub', false) ? ['edge'] : ['edge', 'android'])
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNotNull('branch_id')
            ->distinct()
            ->orderBy('branch_id')
            ->pluck('branch_id');

        $published = 0;
        foreach ($branchIds as $branchId) {
            if ($this->publishBranchSnapshot($companyId, (string) $branchId)) {
                $published++;
            }
        }

        return $published;
    }

    /**
     * Record a lightweight branch signal for any Eloquent mutation that can
     * change a web/Android configuration snapshot. The next signed pull builds
     * the complete immutable snapshot; heartbeats only compare cursor numbers.
     * This avoids rebuilding a large catalogue for every individual stock,
     * product, UOM or settings row changed in one Back Office operation.
     */
    public function markModelDirty(Model $model): void
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud') {
            return;
        }

        $table = $model->getTable();
        $configurationTables = [
            ...self::ANDROID_DATASETS,
            'roles',
            'permissions',
            'role_permissions',
            'pos_users',
            'user_branches',
            'pos_user_shift_assignments',
            'auth_policies',
            'user_access_assignments',
        ];
        if (! in_array($table, $configurationTables, true)) {
            return;
        }

        if ($table === 'pos_users' && $model->exists && ! $model->wasChanged([
            'company_id', 'branch_id', 'role_id', 'name', 'username', 'phone',
            'password', 'login_code', 'login_code_lookup', 'login_code_lookup_key_id',
            'credential_version', 'credentials_changed_at', 'employee_code', 'user_type',
            'can_login_pos', 'can_access_front_office', 'can_access_back_office',
            'is_active', 'deleted_at',
        ])) {
            // last_login_at is operational presence, not configuration.
            return;
        }

        if ($table === 'countries') {
            $this->markCountryCompaniesDirty((string) $model->getKey(), $table);

            return;
        }

        $companyId = $table === 'companies'
            ? (string) $model->getKey()
            : (string) $model->getAttribute('company_id');

        if ($table === 'user_access_assignments' && $companyId === '' && $this->hasTable('pos_users')) {
            $companyId = (string) DB::table('pos_users')
                ->where('id', $model->getAttribute('pos_user_id'))
                ->value('company_id');
        }

        $isGlobalUomMutation = $table === 'uoms' && $companyId === '';
        $isGlobalAuthPolicyMutation = $table === 'auth_policies' && $companyId === '';
        if ($isGlobalUomMutation || $isGlobalAuthPolicyMutation) {
            // A global UOM catalogue mutation changes the configuration of
            // every tenant. Global/country authentication policies are also
            // conservatively signalled to every edge; dataset scoping still
            // ensures that a node receives only a relevant policy row.
            if (! $this->hasSignalTables()) {
                return;
            }

            SyncNode::query()
                ->whereIn('deployment_mode', ['edge', 'android'])
                ->when((bool) config('salexa_sync.local_hub', false), fn ($query) => $query->where('deployment_mode', 'edge'))
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->whereNotNull('company_id')
                ->whereNotNull('branch_id')
                ->select(['company_id', 'branch_id'])
                ->distinct()
                ->orderBy('company_id')
                ->orderBy('branch_id')
                ->get()
                ->each(fn (SyncNode $node) => $this->markBranchDirty(
                    (string) $node->company_id,
                    (string) $node->branch_id,
                    $table
                ));

            return;
        }

        if ($companyId === '') {
            return;
        }

        // Configuration models are also used by installation/bootstrap tests
        // and during migrations. Never make an ordinary CRUD write depend on
        // sync tables that have not been installed yet.
        if (! $this->hasSignalTables()) {
            return;
        }

        $branchId = $table === 'company_branches'
            ? (string) $model->getKey()
            : (string) ($model->getAttribute('branch_id') ?: '');
        if ($branchId !== '') {
            $this->markBranchDirty($companyId, $branchId, $table);

            return;
        }

        SyncNode::query()
            ->where('company_id', $companyId)
            ->whereIn('deployment_mode', (bool) config('salexa_sync.local_hub', false) ? ['edge'] : ['edge', 'android'])
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNotNull('branch_id')
            ->distinct()
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->each(fn ($id) => $this->markBranchDirty($companyId, (string) $id, $table));
    }

    public function markBranchDirty(string $companyId, string $branchId, string $source = 'configuration'): ?SyncChange
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud'
            || ! $this->hasSignalTables()
            || ! SyncNode::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereIn('deployment_mode', (bool) config('salexa_sync.local_hub', false) ? ['edge'] : ['edge', 'android'])
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->exists()) {
            return null;
        }

        $latestDirty = (int) (SyncChange::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('resource_type', 'configuration.dirty')
            ->max('id') ?? 0);
        $latestSnapshot = (int) (SyncChange::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('resource_type', 'like', 'configuration.snapshot%')
            ->max('id') ?? 0);

        // One outstanding marker covers every mutation until a newer snapshot
        // is materialized. This keeps the signal table bounded under imports.
        if ($latestDirty > $latestSnapshot) {
            return null;
        }

        $payload = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'source' => Str::limit($source, 80, ''),
            'changed_at' => now()->toIso8601String(),
        ];
        $change = SyncChange::query()->create([
            'event_id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'source_node_id' => null,
            'resource_type' => 'configuration.dirty',
            'resource_id' => $branchId,
            'operation' => 'dirty',
            'schema_version' => 1,
            'payload' => $payload,
            'payload_hash' => $this->hasher->hash($payload),
            'occurred_at' => now(),
        ]);

        SyncChange::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('resource_type', 'configuration.dirty')
            ->whereKeyNot($change->id)
            ->delete();

        return $change;
    }

    private function hasSignalTables(): bool
    {
        return $this->signalTablesAvailable ??= $this->hasTable('sync_nodes')
            && $this->hasTable('sync_changes');
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withSchemaMemo(callable $callback): mixed
    {
        if ($this->schemaMemo !== null) {
            return $callback();
        }

        $this->schemaMemo = ['tables' => [], 'columns' => [], 'column_sets' => []];
        try {
            return $callback();
        } finally {
            $this->schemaMemo = null;
        }
    }

    private function hasTable(string $table): bool
    {
        if ($this->schemaMemo === null) {
            return Schema::hasTable($table);
        }

        return $this->schemaMemo['tables'][$table] ??= Schema::hasTable($table);
    }

    /** @return array<int, string> */
    private function columnListing(string $table): array
    {
        if ($this->schemaMemo === null) {
            return Schema::getColumnListing($table);
        }

        return $this->schemaMemo['columns'][$table] ??= Schema::getColumnListing($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        if ($this->schemaMemo === null) {
            return Schema::hasColumn($table, $column);
        }

        // Same case-insensitive comparison as Schema::hasColumn().
        $this->schemaMemo['column_sets'][$table] ??= array_flip(
            array_map('strtolower', $this->columnListing($table))
        );

        return isset($this->schemaMemo['column_sets'][$table][strtolower($column)]);
    }

    private function markCountryCompaniesDirty(string $countryId, string $source): void
    {
        if ($countryId === '' || ! $this->hasTable('companies') || ! $this->hasSignalTables()) {
            return;
        }

        $companyIds = DB::table('companies')
            ->where('country_id', $countryId)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->values();
        if ($companyIds->isEmpty()) {
            return;
        }

        SyncNode::query()
            ->whereIn('company_id', $companyIds->all())
            ->whereIn('deployment_mode', (bool) config('salexa_sync.local_hub', false) ? ['edge'] : ['edge', 'android'])
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNotNull('branch_id')
            ->select(['company_id', 'branch_id'])
            ->distinct()
            ->orderBy('company_id')
            ->orderBy('branch_id')
            ->get()
            ->each(fn (SyncNode $node) => $this->markBranchDirty(
                (string) $node->company_id,
                (string) $node->branch_id,
                $source
            ));
    }

    public function snapshotResourceType(SyncNode $node): string
    {
        return $node->deployment_mode === 'android'
            ? 'configuration.snapshot.android.'.strtolower((string) $node->id)
            : 'configuration.snapshot';
    }

    /**
     * Newest snapshot change in the scope, optionally the newest whose payload
     * has the given content hash. Snapshot rows hold the whole configuration
     * (megabytes of JSON); ordering whole rows makes MySQL 8 sort payloads and
     * fail with "Out of sort memory" (error 1038), so ids are ordered first
     * and each candidate row is read by primary key.
     */
    public function newestSnapshotIn(Builder $scope, ?string $contentHash = null): ?SyncChange
    {
        foreach ((clone $scope)->orderByDesc('id')->pluck('id') as $id) {
            $candidate = SyncChange::query()->find($id);
            if ($candidate && ($contentHash === null || hash_equals(
                strtolower($contentHash),
                strtolower((string) data_get($candidate->payload, 'content_hash', ''))
            ))) {
                return $candidate;
            }
        }

        return null;
    }

    public function ensureSnapshotChange(SyncNode $node): ?SyncChange
    {
        return DB::transaction(function () use ($node): ?SyncChange {
            // Serialize snapshot creation for every node in this company.
            DB::table('companies')->where('id', $node->company_id)->lockForUpdate()->value('id');
            // Build every dataset inside the same database transaction. This
            // gives the snapshot one repeatable-read view instead of mixing
            // rows observed before and after a concurrent Back Office commit.
            $snapshotAt = CarbonImmutable::instance(now());
            $datasets = $this->datasets($node, $snapshotAt);
            $contentHash = $this->hasher->hash($datasets);
            $schemaVersion = $node->deployment_mode === 'android'
                ? 3
                : (int) config('servnest.configuration_schema_version', 2);
            $resourceType = $this->snapshotResourceType($node);
            $scope = SyncChange::query()
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('resource_type', $resourceType);
            $latest = $this->newestSnapshotIn($scope);

            if ($latest
                && (int) data_get($latest->payload, 'schema_version', 0) === $schemaVersion
                && data_get($latest->payload, 'content_hash') === $contentHash) {
                return null;
            }

            $payload = [
                'schema_version' => $schemaVersion,
                'company_id' => (string) $node->company_id,
                'branch_id' => (string) $node->branch_id,
                'till_id' => $node->till_id ? (string) $node->till_id : null,
                'target_platform' => $node->deployment_mode === 'android' ? 'android' : 'web',
                'generated_at' => $snapshotAt->toIso8601String(),
                'content_hash' => $contentHash,
                ...$this->terminalContext($node, $datasets),
                'datasets' => $datasets,
                'tombstones' => $this->tombstones(
                    is_array($latest?->payload) ? ($latest->payload['datasets'] ?? []) : [],
                    $datasets,
                    is_array($latest?->payload) ? ($latest->payload['tombstones'] ?? []) : []
                ),
            ];

            $change = SyncChange::query()->create([
                'event_id' => (string) Str::uuid(),
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'source_node_id' => null,
                'resource_type' => $resourceType,
                'resource_id' => $node->deployment_mode === 'android'
                    ? (string) $node->id
                    : (string) $node->branch_id,
                'operation' => 'snapshot',
                'schema_version' => $schemaVersion,
                'payload' => $payload,
                'payload_hash' => $this->hasher->hash($payload),
                'occurred_at' => $snapshotAt,
            ]);

            // Changes are complete snapshots, not deltas. Keep the latest two
            // plus the exact configuration this node most recently proved it
            // applied. An Android terminal may remain offline while Back
            // Office publishes many newer snapshots; its queued receipts must
            // still be priced from that acknowledged server-held snapshot.
            $keep = (clone $scope)->latest('id')->limit(2)->pluck('id');
            $acknowledgedHash = strtolower((string) data_get(
                SyncNodeCursor::query()
                    ->where('sync_node_id', $node->id)
                    ->where('stream', (string) config('salexa_sync.streams.changes', 'changes'))
                    ->first()?->metadata,
                'acknowledged_content_hash',
                ''
            ));
            if ($acknowledgedHash !== '') {
                $acknowledgedSnapshot = $this->newestSnapshotIn($scope, $acknowledgedHash);
                if ($acknowledgedSnapshot) {
                    $keep->push($acknowledgedSnapshot->id);
                }
            }
            $keep = $keep->unique()->values();
            (clone $scope)->whereNotIn('id', $keep)->delete();

            return $change;
        }, 3);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function datasets(SyncNode $node, ?CarbonImmutable $snapshotAt = null): array
    {
        return $this->withSchemaMemo(fn (): array => $this->buildDatasets($node, $snapshotAt));
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function buildDatasets(SyncNode $node, ?CarbonImmutable $snapshotAt): array
    {
        $snapshotAt ??= CarbonImmutable::instance(now());
        $result = [];

        foreach ($this->datasetDefinitions($node) as $table => $definition) {
            if (! $this->hasTable($table)) {
                $result[$table] = [];

                continue;
            }

            $columns = $this->availableColumns($table, $definition['columns']);
            $query = DB::table($table);

            match ($definition['scope']) {
                'node-country-row' => $this->scopeNodeCountryRow($query, $node),
                'company-row' => $query->where('id', $node->company_id),
                'node-brand-row' => $this->scopeNodeBrandRow($query, $node),
                'node-complex-row' => $this->scopeNodeComplexRow($query, $node),
                'branch-row' => $query->where('company_id', $node->company_id)->where('id', $node->branch_id),
                'shop-row' => $this->scopeNodeShopRow($query, $node),
                'edge-auth-policies' => $this->scopeEdgeAuthPolicies($query, $node),
                'company' => $query->where('company_id', $node->company_id),
                'global-company' => $query->where(fn ($scope) => $scope
                    ->whereNull('company_id')
                    ->orWhere('company_id', $node->company_id)),
                'branch' => $query->where('company_id', $node->company_id)->where('branch_id', $node->branch_id),
                'company-branch' => $this->scopeCompanyBranch($query, $table, $node),
                'branch-users' => $this->scopeBranchUsers($query, $node),
                'edge-user-access' => $this->scopeEdgeUserAccess($query, $node),
                'branch-user-roles' => $this->scopeBranchUserRoles($query, $node),
                'branch-user-shift-assignments' => $this->scopeBranchUserShiftAssignments($query, $node),
                'global-catalogue' => $query,
                'role-permissions' => $this->scopeRolePermissions($query, $node),
                'branch-products' => $this->scopeBranchProducts($query, $node),
                'branch-product-categories' => $this->scopeBranchProductCategories($query, $node),
                'branch-product-sub-categories' => $this->scopeBranchProductSubCategories($query, $node),
                'branch-product-suppliers' => $this->scopeBranchProductSuppliers($query, $node),
                'branch-printers' => $this->scopeBranchPrinters($query, $node),
                'node-output-configurations' => $this->scopeNodeOutputConfigurations($query, $node),
                'branch-product-uoms' => $this->scopeBranchProductUoms($query, $node),
                'node-brand-products' => $this->scopeNodeBrandProducts($query, $node),
                'node-brand' => $this->scopeNodeBrand($query, $table, $node),
                'node-brand-product-relations' => $this->scopeNodeBrandProductRelations($query, $table, $node),
                'node-brand-modifier-options' => $this->scopeNodeBrandModifierOptions($query, $node),
                'node-shop-product-relations' => $this->scopeNodeShopProductRelations($query, $table, $node),
                'node-brand-prep-messages' => $this->scopeNodeBrandPrepMessages($query, $node),
                'node-brand-prep-message-relations' => $this->scopeNodeBrandPrepMessageRelations($query, $node),
                'node-brand-legacy-modifiers' => $this->scopeNodeBrandLegacyModifiers($query, $node),
                'node-brand-legacy-modifier-relations' => $this->scopeNodeBrandLegacyModifierRelations($query, $node),
                'branch-product-relations' => $this->scopeBranchProductRelations($query, $table, 'product_id', $node),
                'company-branch-product-relations' => $this->scopeCompanyBranch(
                    $this->scopeBranchProductRelations($query, $table, 'product_id', $node),
                    $table,
                    $node
                ),
                'branch-product-break-rules' => $this->scopeBranchProductBreakRules($query, $node),
                'branch-recipes' => $this->scopeBranchRecipes($query, $node),
                'branch-recipe-ingredients' => $this->scopeBranchRecipeIngredients($query, $node),
                'branch-discount-relations' => $this->scopeBranchDiscountRelations($query, $table, $node),
                'branch-front-stock-closings' => $this->scopeBranchFrontStockClosings($query, $node),
                default => throw new RuntimeException("Unknown sync dataset scope: {$definition['scope']}"),
            };

            if ($table === 'pos_tills' && $node->deployment_mode === 'android') {
                // A mobile installation is exactly one till. Do not disclose
                // sibling terminal names or device labels in its snapshot.
                $query->where('id', $node->till_id);
            }

            $rows = $query->orderBy('id')->get($columns)
                ->map(fn ($row) => (array) $row)
                ->all();
            if ($table === 'pos_users') {
                // A multi-branch user's default branch may be a different
                // cloud branch even though user_branches grants this edge.
                // The edge stores only its paired branch, so the assignment
                // row remains authoritative and an out-of-scope default is
                // represented as null locally.
                $rows = array_map(function (array $row) use ($node): array {
                    if (! empty($row['branch_id'])
                        && (string) $row['branch_id'] !== (string) $node->branch_id) {
                        $row['branch_id'] = null;
                    }

                    return $row;
                }, $rows);
            }
            if ($table === 'general_settings') {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => ! $this->isSensitiveSettingKey((string) ($row['setting_key'] ?? ''))
                ));
            }
            if ($table === 'auth_policies') {
                $rows = array_map(function (array $row): array {
                    $row['settings'] = json_encode(
                        $this->sanitizeEdgeAuthPolicySettings(
                            (string) ($row['policy_key'] ?? ''),
                            $row['settings'] ?? []
                        ),
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );

                    return $row;
                }, $rows);
            }
            $result[$table] = $rows;
        }

        if ($node->deployment_mode === 'android') {
            // This synthetic row is deliberately part of datasets so its
            // business date and effective-window state participate in the
            // content hash. Retail terminals do not have trading_days rows,
            // and scheduled prices/taxes must still roll to a new snapshot
            // identity when their time window changes.
            $result['mobile_context'] = [$this->mobileContext(
                $node,
                $result,
                $snapshotAt,
                $this->mobileLicenseState($node)
            )];
        }

        return $result;
    }

    /**
     * Stable, bounded description of the exact estate scope carried by a
     * configuration snapshot. Dataset rows remain the source of truth; this
     * context saves clients from guessing identities or which tables form the
     * Hospitality catalogue contract.
     *
     * @param  array<string, array<int, array<string, mixed>>>|null  $datasets
     * @return array<string, mixed>
     */
    public function terminalContext(SyncNode $node, ?array $datasets = null): array
    {
        $datasets ??= $this->datasets($node);
        $company = collect($datasets['companies'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) $node->company_id
        );
        $shop = collect($datasets['shops'] ?? [])->first(function (array $row) use ($node): bool {
            if ($node->shop_id) {
                return (string) ($row['id'] ?? '') === (string) $node->shop_id;
            }

            return (string) ($row['branch_id'] ?? '') === (string) $node->branch_id;
        });
        $brand = collect($datasets['brands'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) ($shop['brand_id'] ?? '')
        );
        $complex = collect($datasets['complexes'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) ($shop['complex_id'] ?? '')
        );
        $country = collect($datasets['countries'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) (
                $shop['country_id'] ?? $company['country_id'] ?? ''
            )
        );
        $branch = collect($datasets['company_branches'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) $node->branch_id
        );
        $till = collect($datasets['pos_tills'] ?? [])->first(
            fn (array $row): bool => $node->till_id
                && (string) ($row['id'] ?? '') === (string) $node->till_id
        );
        $brandLogo = $this->publicAssetReference(
            $brand['logo_url'] ?? null,
            $brand['logo_path'] ?? null
        );

        return [
            'identity' => [
                'country' => $country ? [
                    'id' => (string) $country['id'],
                    'code' => $country['code'] ?? null,
                    'name' => $country['name'] ?? null,
                    'image' => $this->publicAssetReference(
                        $country['image_url'] ?? null,
                        $country['image_path'] ?? null
                    ),
                ] : null,
                'company' => $company ? [
                    'id' => (string) $company['id'],
                    'code' => $company['company_code'] ?? null,
                    'name' => $company['company_name'] ?? null,
                    'logo' => $this->publicAssetReference(null, $company['logo'] ?? null),
                ] : null,
                'brand' => $brand ? [
                    'id' => (string) $brand['id'],
                    'code' => $brand['code'] ?? null,
                    'name' => $brand['name'] ?? null,
                    'logo' => $brandLogo,
                    'logo_url' => $brand['logo_url'] ?? null,
                    'logo_path' => $brand['logo_path'] ?? null,
                ] : null,
                'complex' => $complex ? [
                    'id' => (string) $complex['id'],
                    'code' => $complex['code'] ?? null,
                    'name' => $complex['name'] ?? null,
                ] : null,
                'shop' => $shop ? [
                    'id' => (string) $shop['id'],
                    'code' => $shop['code'] ?? null,
                    'name' => $shop['name'] ?? null,
                    'branch_id' => $shop['branch_id'] ?? null,
                    'configuration_version' => (int) ($shop['configuration_version'] ?? 1),
                ] : null,
                'till' => $till ? [
                    'id' => (string) $till['id'],
                    'code' => $till['till_code'] ?? null,
                    'name' => $till['till_name'] ?? null,
                    'shop_id' => $till['shop_id'] ?? null,
                    'configuration_version' => (int) ($till['configuration_version'] ?? 1),
                ] : null,
                'branch' => $branch ? [
                    'id' => (string) $branch['id'],
                    'code' => $branch['branch_code'] ?? null,
                    'name' => $branch['branch_name'] ?? null,
                ] : null,
            ],
            'branding' => [
                'name' => $brand['name'] ?? $company['company_name'] ?? null,
                'logo' => $brandLogo ?: $this->publicAssetReference(null, $company['logo'] ?? null),
                'brand_logo' => [
                    'url' => $brandLogo,
                    'source_url' => $brand['logo_url'] ?? null,
                    'source_path' => $brand['logo_path'] ?? null,
                    'cache_key' => $brandLogo ? hash('sha256', implode('|', [
                        (string) ($brand['id'] ?? ''),
                        (string) ($brand['updated_at'] ?? ''),
                        $brandLogo,
                    ])) : null,
                ],
            ],
            'configuration_contract' => [
                'hospitality_only' => true,
                'brand_scoped' => true,
                'shop_visibility_authoritative' => true,
                'catalogue_datasets' => [
                    'products', 'brand_products', 'menu_item_channels',
                    'menu_item_trading_schedules', 'food_recipes',
                    'food_recipe_ingredients', 'modifier_groups',
                    'modifier_group_options', 'menu_item_modifier_groups',
                    'menu_item_modifier_exclusions', 'product_modifiers',
                    'product_modifier_assignments', 'product_prep_messages',
                    'product_prep_message_assignments', 'shop_menu_item_overrides',
                ],
                'kitchen_output_datasets' => [
                    'printers', 'hospitality_output_configurations',
                ],
            ],
        ];
    }

    private function publicAssetReference(mixed $explicitUrl, mixed $storedPath): ?string
    {
        $explicitUrl = trim((string) $explicitUrl);
        if ($explicitUrl !== '') {
            return $explicitUrl;
        }

        $storedPath = trim((string) $storedPath);
        if ($storedPath === '') {
            return null;
        }
        if (Str::startsWith(strtolower($storedPath), ['http://', 'https://', 'data:'])) {
            return $storedPath;
        }

        $path = '/'.ltrim(str_replace('\\', '/', $storedPath), '/');
        if (! Str::startsWith($path, ['/storage/', '/api/assets/', '/assets/'])) {
            $path = '/storage'.$path;
        }

        return url($path);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $datasets
     * @return array<string, mixed>
     */
    private function mobileContext(
        SyncNode $node,
        array $datasets,
        ?CarbonImmutable $snapshotAt = null,
        ?array $licenseContext = null
    ): array {
        $snapshotAt ??= CarbonImmutable::instance(now());
        $hospitality = true;

        $systemDate = HospitalityTradingDatePolicy::systemDate($snapshotAt);
        $businessDate = $systemDate;
        $salesAllowed = true;
        $blockReason = null;

        if ($hospitality) {
            $days = array_values(array_filter(
                $datasets['trading_days'] ?? [],
                fn (mixed $row): bool => is_array($row)
            ));
            $active = array_values(array_filter(
                $days,
                fn (array $row): bool => in_array(
                    strtolower(trim((string) ($row['status'] ?? ''))),
                    ['open', 'day_end_running'],
                    true
                )
            ));

            if (count($active) === 1) {
                $state = HospitalityTradingDatePolicy::state(
                    $active[0]['trading_date'] ?? null,
                    isset($active[0]['status']) ? (string) $active[0]['status'] : null,
                    $snapshotAt
                );
                $salesAllowed = (bool) ($state['can_sell'] ?? false);
                $businessDate = $salesAllowed
                    ? HospitalityTradingDatePolicy::normalize($active[0]['trading_date'] ?? null)
                    : null;
                $blockReason = $state['sale_block_reason'] ?? null;
            } elseif (count($active) > 1) {
                $salesAllowed = false;
                $businessDate = null;
                $blockReason = 'Multiple open Hospitality trading dates exist for this branch.';
            } else {
                $lastClosed = collect($days)
                    ->filter(fn (array $row): bool => strtolower(trim((string) ($row['status'] ?? ''))) === 'closed')
                    ->sortByDesc(fn (array $row): string => (string) ($row['trading_date'] ?? ''))
                    ->first();
                // A fresh Hospitality branch is allowed to create today's
                // first trading day on upload, matching the web FOH contract.
                $candidate = $lastClosed
                    ? HospitalityTradingDatePolicy::nextOpenDate(
                        $lastClosed['trading_date'] ?? null,
                        $snapshotAt
                    )
                    : $systemDate;
                $salesAllowed = $candidate !== null;
                $businessDate = $candidate;
                $blockReason = $salesAllowed
                    ? null
                    : 'The next Hospitality trading date is not open yet.';
            }
        }

        return [
            'id' => 'current',
            'company_id' => (string) $node->company_id,
            'branch_id' => (string) $node->branch_id,
            'till_id' => (string) $node->till_id,
            'business_mode' => 'hospitality',
            'hospitality_only' => true,
            'business_date' => $businessDate,
            'system_date' => $systemDate,
            'system_timezone' => HospitalityTradingDatePolicy::TIMEZONE,
            'sales_allowed' => $salesAllowed,
            'sale_block_reason' => $blockReason,
            'effective_window_hash' => $this->mobileEffectiveWindowHash($datasets, $snapshotAt),
            ...($licenseContext ?? $this->mobileLicenseProjection([
                'valid' => false,
                'code' => 'LICENSE_STATUS_UNKNOWN',
                'warning_days' => max((int) config('license.warning_days', 90), 0),
                'license_support_phone' => config('license.default_support_phone'),
            ])),
        ];
    }

    /**
     * Project only the non-secret runtime license facts needed by Android FOH.
     *
     * The row is covered by the mobile snapshot content hash and authenticated
     * sync transport. Human license keys and raw database entitlement material
     * remain server-side; the mobile-specific signed envelope contains only the
     * minimum company entitlement claims needed for offline enforcement.
     *
     * @return array<string, mixed>
     */
    private function mobileLicenseState(SyncNode $node): array
    {
        $company = Company::query()->find($node->company_id);
        if (! $company) {
            return $this->mobileLicenseProjection([
                'valid' => false,
                'code' => 'LICENSE_STATUS_UNKNOWN',
                'warning_days' => max((int) config('license.warning_days', 90), 0),
                'license_support_phone' => config('license.default_support_phone'),
            ]);
        }

        $delivery = app(MobileLicenseEntitlementService::class)->issue($company);

        return [
            ...$this->mobileLicenseProjection($delivery['license']),
            'license_entitlement' => $delivery['license_entitlement'],
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function mobileLicenseProjection(array $status): array
    {
        return [
            'license_status_known' => ($status['code'] ?? null) !== 'LICENSE_STATUS_UNKNOWN',
            'license_id' => isset($status['license_id']) ? (string) $status['license_id'] : null,
            'license_valid' => (bool) ($status['valid'] ?? false),
            'license_code' => (string) ($status['code'] ?? 'LICENSE_STATUS_UNKNOWN'),
            'license_expires_at' => isset($status['expires_at']) ? (string) $status['expires_at'] : null,
            'license_days_remaining' => isset($status['days_remaining'])
                ? (int) $status['days_remaining']
                : null,
            'license_warning_days' => max((int) ($status['warning_days'] ?? 90), 0),
            'license_warning_required' => (bool) ($status['warning_required'] ?? false),
            'license_support_phone' => isset($status['license_support_phone'])
                ? trim((string) $status['license_support_phone'])
                : null,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function mobileSettingEnabled(array $rows, string $key, string $branchId): bool
    {
        $candidates = array_values(array_filter($rows, function (mixed $row) use ($key, $branchId): bool {
            if (! is_array($row)
                || (string) ($row['setting_key'] ?? '') !== $key
                || ! empty($row['deleted_at'])
                || (array_key_exists('is_active', $row) && ! $this->mobileBoolean($row['is_active']))) {
                return false;
            }

            $rowBranch = trim((string) ($row['branch_id'] ?? ''));

            return $rowBranch === '' || $rowBranch === $branchId;
        }));
        usort($candidates, function (array $left, array $right) use ($branchId): int {
            $branchComparison = ((string) ($right['branch_id'] ?? '') === $branchId)
                <=> ((string) ($left['branch_id'] ?? '') === $branchId);
            if ($branchComparison !== 0) {
                return $branchComparison;
            }

            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        return isset($candidates[0]) && $this->mobileBoolean($candidates[0]['setting_value'] ?? false);
    }

    /** @param array<string, array<int, array<string, mixed>>> $datasets */
    private function mobileEffectiveWindowHash(array $datasets, CarbonImmutable $now): string
    {
        $state = [];
        foreach ([
            'product_prices' => ['starts_at', 'ends_at'],
            'product_taxes' => ['starts_at', 'ends_at'],
        ] as $dataset => [$startColumn, $endColumn]) {
            foreach ($datasets[$dataset] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $state[] = [
                    'dataset' => $dataset,
                    'id' => (string) ($row['id'] ?? ''),
                    'effective' => $this->mobileWindowContains($row, $startColumn, $endColumn, $now),
                ];
            }
        }
        usort($state, fn (array $left, array $right): int => strcmp(
            $left['dataset'].':'.$left['id'],
            $right['dataset'].':'.$right['id']
        ));

        return $this->hasher->hash($state);
    }

    private function mobileWindowContains(
        array $row,
        string $startColumn,
        string $endColumn,
        CarbonImmutable $now
    ): bool {
        if (! empty($row['deleted_at'])
            || (array_key_exists('is_active', $row) && ! $this->mobileBoolean($row['is_active']))) {
            return false;
        }

        $startsAt = $this->mobileWindowDate($row[$startColumn] ?? null, false);
        $endsAt = $this->mobileWindowDate($row[$endColumn] ?? null, true);

        return (! $startsAt || ! $startsAt->isAfter($now))
            && (! $endsAt || ! $endsAt->isBefore($now));
    }

    private function mobileWindowDate(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse((string) $value, HospitalityTradingDatePolicy::TIMEZONE);

            return $endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value))
                ? $date->endOfDay()
                : $date;
        } catch (\Throwable) {
            return null;
        }
    }

    private function mobileBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, array{scope: string, columns: array<int, string>}> */
    private function datasetDefinitions(SyncNode $node): array
    {
        if ($node->deployment_mode !== 'android') {
            return self::DATASETS;
        }

        $definitions = array_intersect_key(self::DATASETS, array_flip(self::ANDROID_DATASETS));
        foreach (self::ANDROID_COLUMN_OVERRIDES as $table => $columns) {
            if (isset($definitions[$table])) {
                $definitions[$table]['columns'] = $columns;
            }
        }

        return $definitions;
    }

    public function applySnapshot(SyncLocalState $state, array $payload): array
    {
        return $this->withSchemaMemo(fn (): array => $this->applySnapshotPayload($state, $payload));
    }

    private function applySnapshotPayload(SyncLocalState $state, array $payload): array
    {
        $this->pendingSaleMemo = [];

        if (! in_array((int) ($payload['schema_version'] ?? 0), self::EDGE_SNAPSHOT_SCHEMA_VERSIONS, true)
            || ! is_array($payload['datasets'] ?? null)) {
            throw new InvalidArgumentException('Unsupported configuration snapshot schema.');
        }

        if ((string) ($payload['company_id'] ?? '') !== (string) $state->company_id
            || (string) ($payload['branch_id'] ?? '') !== (string) $state->branch_id) {
            throw new InvalidArgumentException('Configuration snapshot does not belong to this paired edge node.');
        }

        $expectedContentHash = $this->hasher->hash($payload['datasets']);
        if (! hash_equals((string) ($payload['content_hash'] ?? ''), $expectedContentHash)) {
            throw new InvalidArgumentException('Configuration snapshot content hash is invalid.');
        }
        $this->validateForeignKeyClosure($payload['datasets']);

        $counts = [];
        DB::transaction(function () use ($state, $payload, &$counts) {
            foreach (self::APPLY_ORDER as $table) {
                $rows = $payload['datasets'][$table] ?? [];
                if (! is_array($rows)) {
                    throw new InvalidArgumentException("Configuration dataset {$table} is invalid.");
                }

                $counts[$table] = 0;
                $parentColumn = self::SELF_PARENT_COLUMNS[$table] ?? null;
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        throw new InvalidArgumentException("Configuration dataset {$table} contains an invalid row.");
                    }
                    $initialRow = $row;
                    if ($parentColumn !== null) {
                        $initialRow[$parentColumn] = null;
                    }
                    $this->upsertSnapshotRow($state, $table, $initialRow);
                    $counts[$table]++;
                }
                if ($parentColumn !== null) {
                    // UUID ordering does not imply parent-before-child. All
                    // rows now exist, so restoring self references is FK-safe.
                    foreach ($rows as $row) {
                        $this->upsertSnapshotRow($state, $table, $row);
                    }
                }
                if ($table === 'role_permissions') {
                    $this->pruneRolePermissionsAbsentFromSnapshot($payload['datasets']);
                }
            }

            foreach (($payload['tombstones'] ?? []) as $table => $ids) {
                if (! isset(self::DATASETS[$table]) || ! is_array($ids) || ! $this->hasTable($table)) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (is_scalar($id)) {
                        $this->applyTombstone($state, $table, (string) $id);
                    }
                }
            }
        });

        return $counts;
    }

    private function upsertSnapshotRow(SyncLocalState $state, string $table, array $row): void
    {
        $definition = self::DATASETS[$table] ?? null;
        if (! $definition || ! $this->hasTable($table)) {
            return;
        }
        if (! is_scalar($row['id'] ?? null) || trim((string) $row['id']) === '') {
            throw new InvalidArgumentException("Configuration dataset {$table} contains a row without an id.");
        }

        $columns = array_flip($this->availableColumns($table, $definition['columns']));
        $row = array_intersect_key($row, $columns);

        if ($this->hasColumn($table, 'company_id')) {
            $preservesNullableCompanyScope = in_array($table, [
                'auth_policies',
                'user_access_assignments',
            ], true);
            if ($preservesNullableCompanyScope) {
                $incomingCompanyId = isset($row['company_id']) && is_scalar($row['company_id'])
                    ? trim((string) $row['company_id'])
                    : '';
                if ($incomingCompanyId !== '' && $incomingCompanyId !== (string) $state->company_id) {
                    throw new InvalidArgumentException("Configuration dataset {$table} contains another company.");
                }
                $row['company_id'] = $incomingCompanyId !== '' ? $incomingCompanyId : null;
            } elseif (! ($table === 'uoms'
                && array_key_exists('company_id', $row)
                && $row['company_id'] === null)) {
                $row['company_id'] = (string) $state->company_id;
            }
        }
        if ($this->hasColumn($table, 'branch_id')) {
            $incomingBranch = isset($row['branch_id']) && is_scalar($row['branch_id'])
                ? trim((string) $row['branch_id'])
                : null;
            if ($incomingBranch !== null && $incomingBranch !== '' && $incomingBranch !== (string) $state->branch_id) {
                throw new InvalidArgumentException("Configuration dataset {$table} contains another branch.");
            }
            $row['branch_id'] = $incomingBranch ?: null;
        }

        if ($table === 'companies' && (string) $row['id'] !== (string) $state->company_id) {
            throw new InvalidArgumentException('Configuration snapshot contains another company.');
        }
        if ($table === 'company_branches' && (string) $row['id'] !== (string) $state->branch_id) {
            throw new InvalidArgumentException('Configuration snapshot contains another branch.');
        }

        if ($table === 'licenses') {
            $this->guardLicenseRevision($row);
        }
        if ($table === 'permissions') {
            $this->alignPermissionIdentity($row);
        }
        if ($table === 'role_permissions') {
            $this->alignRolePermissionIdentity($row);
        }

        $this->alignOperationalProjectionIdentity($table, $row);
        $this->preservePendingEdgeOperationalState($state, $table, $row);

        foreach (['metadata', 'rules', 'settings'] as $jsonColumn) {
            if (isset($row[$jsonColumn]) && is_array($row[$jsonColumn])) {
                $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_UNESCAPED_SLASHES);
            }
        }

        $updateColumns = array_values(array_diff(array_keys($row), ['id', 'created_at']));
        DB::table($table)->upsert([$row], ['id'], $updateColumns);
    }

    /**
     * Permission migrations may seed the same globally unique name with a
     * different UUID on an offline installation. The cloud ID is canonical:
     * replace the local lookup row inside the snapshot transaction before
     * role_permissions are applied, avoiding a unique-name/FK split identity.
     */
    private function alignPermissionIdentity(array $row): void
    {
        $incomingId = trim((string) ($row['id'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($incomingId === '' || $name === '') {
            return;
        }

        $conflictingIds = DB::table('permissions')
            ->where('name', $name)
            ->where('id', '!=', $incomingId)
            ->pluck('id');
        if ($conflictingIds->isEmpty()) {
            return;
        }

        if ($this->hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_id', $conflictingIds)->delete();
        }
        DB::table('permissions')->whereIn('id', $conflictingIds)->delete();
    }

    /**
     * role_permissions is unique by its business key even though snapshots
     * carry a UUID primary key. A legacy edge or an older role sync may have
     * the same assignment under another UUID. Replace that local identity
     * before upsert so one duplicate cannot roll back the entire snapshot.
     */
    private function alignRolePermissionIdentity(array $row): void
    {
        $incomingId = trim((string) ($row['id'] ?? ''));
        $roleId = trim((string) ($row['role_id'] ?? ''));
        $permissionId = trim((string) ($row['permission_id'] ?? ''));
        if ($incomingId === '' || $roleId === '' || $permissionId === '') {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->where('id', '!=', $incomingId)
            ->delete();
    }

    /**
     * Configuration changes are complete snapshots. Reconcile assignments by
     * their natural key for roles that are explicitly in this edge's incoming
     * scope, including assignments created only on the edge. Roles omitted
     * from the snapshot may belong to another tenant/scope and are untouched.
     */
    private function pruneRolePermissionsAbsentFromSnapshot(array $datasets): void
    {
        if (! $this->hasTable('role_permissions')) {
            return;
        }

        $roleIds = collect($datasets['roles'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && is_scalar($row['id'] ?? null))
            ->map(fn (array $row): string => trim((string) $row['id']))
            ->filter()
            ->unique();
        $permissionsByRole = collect($datasets['role_permissions'] ?? [])
            ->filter(fn ($row): bool => is_array($row)
                && is_scalar($row['role_id'] ?? null)
                && is_scalar($row['permission_id'] ?? null))
            ->groupBy(fn (array $row): string => (string) $row['role_id'])
            ->map(fn ($rows) => $rows
                ->map(fn (array $row): string => (string) $row['permission_id'])
                ->unique()
                ->values()
                ->all());

        foreach ($roleIds as $roleId) {
            $authoritativePermissionIds = $permissionsByRole->get($roleId, []);
            $stale = DB::table('role_permissions')->where('role_id', $roleId);
            $authoritativePermissionIds === []
                ? $stale->delete()
                : $stale->whereNotIn('permission_id', $authoritativePermissionIds)->delete();
        }
    }

    private function applyTombstone(SyncLocalState $state, string $table, string $id): void
    {
        $query = DB::table($table)->where('id', $id);
        if ($this->hasColumn($table, 'company_id')) {
            in_array($table, ['uoms', 'auth_policies', 'user_access_assignments'], true)
                ? $query->where(fn ($scope) => $scope
                    ->whereNull('company_id')
                    ->orWhere('company_id', $state->company_id))
                : $query->where('company_id', $state->company_id);
        }
        if ($this->hasColumn($table, 'branch_id')) {
            $query->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $state->branch_id));
        }

        if ($this->hasColumn($table, 'deleted_at')) {
            $values = ['deleted_at' => now()];
            if ($this->hasColumn($table, 'is_active')) {
                $values['is_active'] = false;
            }
            if ($this->hasColumn($table, 'updated_at')) {
                $values['updated_at'] = now();
            }
            $query->update($values);

            return;
        }

        // Only relationship rows are hard-deleted. Master rows without a
        // soft-delete column are retained to avoid breaking historical sales.
        if (in_array($table, [
            'auth_policies',
            'user_access_assignments',
            'role_permissions',
            'user_branches',
            'pos_user_shift_assignments',
            'role_price_levels',
            'brand_products',
            'product_uom_conversions',
            'menu_item_channels',
            'menu_item_trading_schedules',
            'modifier_group_options',
            'menu_item_modifier_groups',
            'menu_item_modifier_exclusions',
            'shop_menu_item_overrides',
            'food_recipe_ingredients',
            'product_prep_message_assignments',
            'product_modifier_assignments',
            'discount_products',
            'discount_categories',
            'discount_role_permissions',
        ], true)) {
            $query->delete();
        }
    }

    private function tombstones(array $previous, array $current, array $existing = []): array
    {
        $tombstones = [];
        foreach (array_keys($current) as $table) {
            if ($table === 'front_stock_daily_closings') {
                // This projection intentionally keeps a bounded recent
                // window. A row leaving that window is historical, not a
                // deletion, so it must never become a tombstone on the edge.
                continue;
            }
            $before = collect($previous[$table] ?? [])->pluck('id')->map(fn ($id) => (string) $id);
            $after = collect($current[$table] ?? [])->pluck('id')->map(fn ($id) => (string) $id);
            $removed = collect($existing[$table] ?? [])
                ->map(fn ($id) => (string) $id)
                ->merge($before->diff($after))
                ->diff($after)
                ->unique()
                ->values()
                ->all();
            if ($removed !== []) {
                $tombstones[$table] = $removed;
            }
        }

        return $tombstones;
    }

    private function scopeNodeCountryRow($query, SyncNode $node)
    {
        return $query->whereIn('countries.id', function ($countries) use ($node): void {
            $countries->select('companies.country_id')
                ->from('companies')
                ->where('companies.id', $node->company_id)
                ->whereNotNull('companies.country_id');
        });
    }

    private function scopeNodeBrandRow($query, SyncNode $node)
    {
        return $query->where('brands.company_id', $node->company_id)
            ->whereExists(function ($shops) use ($node): void {
                $shops->selectRaw('1')
                    ->from('shops')
                    ->whereColumn('shops.brand_id', 'brands.id')
                    ->where('shops.company_id', $node->company_id)
                    ->where('shops.branch_id', $node->branch_id);
            });
    }

    private function scopeNodeComplexRow($query, SyncNode $node)
    {
        return $query->where('complexes.company_id', $node->company_id)
            ->whereExists(function ($shops) use ($node): void {
                $shops->selectRaw('1')
                    ->from('shops')
                    ->whereColumn('shops.complex_id', 'complexes.id')
                    ->where('shops.company_id', $node->company_id)
                    ->where('shops.branch_id', $node->branch_id);
            });
    }

    private function scopeNodeShopRow($query, SyncNode $node)
    {
        return $query->where('shops.company_id', $node->company_id)
            ->where('shops.branch_id', $node->branch_id);
    }

    private function scopeEdgeAuthPolicies($query, SyncNode $node)
    {
        return $query
            ->whereIn('auth_policies.policy_key', ['authentication', 'credential', 'session'])
            ->where(function ($scope) use ($node): void {
                $scope->where(function ($level): void {
                    $level->where('auth_policies.scope_type', 'global')
                        ->where('auth_policies.scope_key', 'global');
                })->orWhere(function ($level) use ($node): void {
                    $level->where('auth_policies.scope_type', 'country')
                        ->whereIn('auth_policies.country_id', function ($countries) use ($node): void {
                            $countries->select('companies.country_id')
                                ->from('companies')
                                ->where('companies.id', $node->company_id)
                                ->whereNotNull('companies.country_id');
                        });
                })->orWhere(function ($level) use ($node): void {
                    $level->where('auth_policies.scope_type', 'company')
                        ->where('auth_policies.company_id', $node->company_id);
                })->orWhere(function ($level) use ($node): void {
                    $level->where('auth_policies.scope_type', 'brand')
                        ->whereIn('auth_policies.brand_id', function ($brands) use ($node): void {
                            $brands->select('shops.brand_id')->from('shops')
                                ->where('shops.company_id', $node->company_id)
                                ->where('shops.branch_id', $node->branch_id);
                        });
                })->orWhere(function ($level) use ($node): void {
                    $level->where('auth_policies.scope_type', 'complex')
                        ->whereIn('auth_policies.complex_id', function ($complexes) use ($node): void {
                            $complexes->select('shops.complex_id')->from('shops')
                                ->where('shops.company_id', $node->company_id)
                                ->where('shops.branch_id', $node->branch_id);
                        });
                })->orWhere(function ($level) use ($node): void {
                    $level->where('auth_policies.scope_type', 'shop')
                        ->whereIn('auth_policies.shop_id', function ($shops) use ($node): void {
                            $shops->select('shops.id')->from('shops')
                                ->where('shops.company_id', $node->company_id)
                                ->where('shops.branch_id', $node->branch_id);
                        });
                })->orWhere(function ($level) use ($node): void {
                    $level->where('auth_policies.scope_type', 'till')
                        ->whereIn('auth_policies.till_id', function ($tills) use ($node): void {
                            $tills->select('pos_tills.id')->from('pos_tills')
                                ->where('pos_tills.company_id', $node->company_id)
                                ->where('pos_tills.branch_id', $node->branch_id);
                        });
                });
            });
    }

    private function scopeEdgeUserAccess($query, SyncNode $node)
    {
        $platformAdministratorIds = array_values(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            (array) config('license.platform_admin_pos_user_ids', [])
        )));

        $query->where('user_access_assignments.scope_type', '!=', 'global')
            ->whereExists(function ($users) use ($node, $platformAdministratorIds): void {
                $users->selectRaw('1')->from('pos_users')
                    ->whereColumn('pos_users.id', 'user_access_assignments.pos_user_id');
                $this->scopeBranchUsers($users, $node);
                if ($platformAdministratorIds !== []) {
                    $users->whereNotIn('pos_users.id', $platformAdministratorIds);
                }
            })
            ->whereExists(function ($roles) use ($node): void {
                $roles->selectRaw('1')->from('roles')
                    ->whereColumn('roles.id', 'user_access_assignments.role_id')
                    ->where('roles.company_id', $node->company_id);
            });

        return $query->where(function ($scope) use ($node): void {
            $scope->where(function ($level) use ($node): void {
                $level->where('user_access_assignments.scope_type', 'country')
                    ->whereIn('user_access_assignments.country_id', function ($countries) use ($node): void {
                        $countries->select('companies.country_id')->from('companies')
                            ->where('companies.id', $node->company_id)
                            ->whereNotNull('companies.country_id');
                    });
            })->orWhere(function ($level) use ($node): void {
                $level->where('user_access_assignments.scope_type', 'company')
                    ->where('user_access_assignments.company_id', $node->company_id);
            })->orWhere(function ($level) use ($node): void {
                $level->where('user_access_assignments.scope_type', 'brand')
                    ->whereIn('user_access_assignments.brand_id', function ($brands) use ($node): void {
                        $brands->select('shops.brand_id')->from('shops')
                            ->where('shops.company_id', $node->company_id)
                            ->where('shops.branch_id', $node->branch_id);
                    });
            })->orWhere(function ($level) use ($node): void {
                $level->where('user_access_assignments.scope_type', 'complex')
                    ->whereIn('user_access_assignments.complex_id', function ($complexes) use ($node): void {
                        $complexes->select('shops.complex_id')->from('shops')
                            ->where('shops.company_id', $node->company_id)
                            ->where('shops.branch_id', $node->branch_id);
                    });
            })->orWhere(function ($level) use ($node): void {
                $level->where('user_access_assignments.scope_type', 'shop')
                    ->whereIn('user_access_assignments.shop_id', function ($shops) use ($node): void {
                        $shops->select('shops.id')->from('shops')
                            ->where('shops.company_id', $node->company_id)
                            ->where('shops.branch_id', $node->branch_id);
                    });
            })->orWhere(function ($level) use ($node): void {
                $level->where('user_access_assignments.scope_type', 'till')
                    ->whereIn('user_access_assignments.till_id', function ($tills) use ($node): void {
                        $tills->select('pos_tills.id')->from('pos_tills')
                            ->where('pos_tills.company_id', $node->company_id)
                            ->where('pos_tills.branch_id', $node->branch_id);
                    });
            });
        });
    }

    /** @return array<string, mixed> */
    private function sanitizeEdgeAuthPolicySettings(string $policyKey, mixed $settings): array
    {
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (! is_array($settings)) {
            return [];
        }

        $allowedPaths = match ($policyKey) {
            'authentication' => [
                'pos.login_mode',
                'lockout.maximum_attempts',
                'lockout.decay_seconds',
            ],
            'credential' => [
                'password.minimum_length',
                'password.maximum_length',
                'password.require_uppercase',
                'password.require_lowercase',
                'password.require_number',
                'password.require_symbol',
                'password.history_count',
                'login_code.minimum_length',
                'login_code.maximum_length',
                'login_code.numeric_only',
                'login_code.reject_repeated_digits',
                'login_code.reject_sequences',
            ],
            'session' => [
                'pos.lifetime_minutes',
                'pos.idle_timeout_minutes',
            ],
            default => [],
        };

        $safe = [];
        foreach ($allowedPaths as $path) {
            if (Arr::has($settings, $path)) {
                data_set($safe, $path, data_get($settings, $path));
            }
        }

        return $safe;
    }

    private function scopeNodeBrand($query, string $table, SyncNode $node)
    {
        return $query->where("{$table}.company_id", $node->company_id)
            ->whereIn("{$table}.brand_id", function ($brands) use ($node): void {
                $brands->select('shops.brand_id')
                    ->from('shops')
                    ->where('shops.company_id', $node->company_id)
                    ->where('shops.branch_id', $node->branch_id);
            });
    }

    private function scopeNodeBrandProducts($query, SyncNode $node)
    {
        $this->scopeNodeBrand($query, 'brand_products', $node);

        return $query->whereExists(function ($products) use ($node): void {
            $products->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', 'brand_products.product_id');
            $this->scopeBranchProducts($products, $node);
        });
    }

    private function scopeNodeBrandProductRelations($query, string $table, SyncNode $node)
    {
        $this->scopeNodeBrand($query, $table, $node);
        $productColumn = in_array($table, [
            'menu_item_modifier_groups',
            'menu_item_modifier_exclusions',
        ], true) ? 'menu_product_id' : 'product_id';

        return $query->whereExists(function ($products) use ($table, $productColumn, $node): void {
            $products->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', "{$table}.{$productColumn}");
            $this->scopeBranchProducts($products, $node);
        });
    }

    private function scopeNodeBrandModifierOptions($query, SyncNode $node)
    {
        $this->scopeNodeBrand($query, 'modifier_group_options', $node);

        return $query->whereExists(function ($products) use ($node): void {
            $products->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', 'modifier_group_options.modifier_product_id');
            $this->scopeBranchProducts($products, $node);
        });
    }

    private function scopeNodeShopProductRelations($query, string $table, SyncNode $node)
    {
        $query->where("{$table}.company_id", $node->company_id)
            ->whereIn("{$table}.shop_id", function ($shops) use ($node): void {
                $shops->select('shops.id')
                    ->from('shops')
                    ->where('shops.company_id', $node->company_id)
                    ->where('shops.branch_id', $node->branch_id);
            });

        return $query->whereExists(function ($products) use ($table, $node): void {
            $products->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', "{$table}.product_id");
            $this->scopeBranchProducts($products, $node);
        });
    }

    private function scopeNodeBrandPrepMessages($query, SyncNode $node)
    {
        $this->scopeCompanyBranch($query, 'product_prep_messages', $node);
        if ($this->hasColumn('product_prep_messages', 'brand_id')) {
            $this->scopeNodeBrand($query, 'product_prep_messages', $node);
        }

        // An edge only needs messages reachable from its own menu. Keeping
        // unassigned Back Office templates in Central materially inflates
        // snapshots for large multi-brand estates.
        return $query->whereExists(function ($assignments) use ($node): void {
            $assignments->selectRaw('1')
                ->from('product_prep_message_assignments')
                ->whereColumn(
                    'product_prep_message_assignments.prep_message_id',
                    'product_prep_messages.id'
                )
                ->where('product_prep_message_assignments.company_id', $node->company_id)
                ->where(function ($branch) use ($node): void {
                    $branch->whereNull('product_prep_message_assignments.branch_id')
                        ->orWhere('product_prep_message_assignments.branch_id', $node->branch_id);
                })
                ->whereExists(function ($products) use ($node): void {
                    $products->selectRaw('1')
                        ->from('products')
                        ->whereColumn(
                            'products.id',
                            'product_prep_message_assignments.product_id'
                        );
                    $this->scopeBranchProducts($products, $node);
                });
            if ($this->hasColumn('product_prep_message_assignments', 'brand_id')) {
                $assignments->whereIn(
                    'product_prep_message_assignments.brand_id',
                    $this->nodeBrandIds($node)
                );
            }
        });
    }

    private function scopeNodeBrandPrepMessageRelations($query, SyncNode $node)
    {
        $this->scopeCompanyBranch($query, 'product_prep_message_assignments', $node);
        if ($this->hasColumn('product_prep_message_assignments', 'brand_id')) {
            $this->scopeNodeBrand($query, 'product_prep_message_assignments', $node);
        }

        $query->whereExists(function ($products) use ($node): void {
            $products->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', 'product_prep_message_assignments.product_id');
            $this->scopeBranchProducts($products, $node);
        });

        return $query->whereExists(function ($messages) use ($node): void {
            $messages->selectRaw('1')
                ->from('product_prep_messages')
                ->whereColumn('product_prep_messages.id', 'product_prep_message_assignments.prep_message_id')
                ->where('product_prep_messages.company_id', $node->company_id);
            if ($this->hasColumn('product_prep_messages', 'brand_id')) {
                $messages->whereIn('product_prep_messages.brand_id', $this->nodeBrandIds($node));
            }
        });
    }

    private function scopeNodeBrandLegacyModifiers($query, SyncNode $node)
    {
        $this->scopeCompanyBranch($query, 'product_modifiers', $node);
        if ($this->hasColumn('product_modifiers', 'brand_id')) {
            $this->scopeNodeBrand($query, 'product_modifiers', $node);
        }

        return $query->whereExists(function ($assignments) use ($node): void {
            $assignments->selectRaw('1')
                ->from('product_modifier_assignments')
                ->whereColumn('product_modifier_assignments.modifier_id', 'product_modifiers.id')
                ->where('product_modifier_assignments.company_id', $node->company_id)
                ->where(function ($branch) use ($node): void {
                    $branch->whereNull('product_modifier_assignments.branch_id')
                        ->orWhere('product_modifier_assignments.branch_id', $node->branch_id);
                })
                ->whereExists(function ($products) use ($node): void {
                    $products->selectRaw('1')
                        ->from('products')
                        ->whereColumn('products.id', 'product_modifier_assignments.product_id');
                    $this->scopeBranchProducts($products, $node);
                });
            if ($this->hasColumn('product_modifier_assignments', 'brand_id')) {
                $assignments->whereIn(
                    'product_modifier_assignments.brand_id',
                    $this->nodeBrandIds($node)
                );
            }
        });
    }

    private function scopeNodeBrandLegacyModifierRelations($query, SyncNode $node)
    {
        $this->scopeCompanyBranch($query, 'product_modifier_assignments', $node);
        if ($this->hasColumn('product_modifier_assignments', 'brand_id')) {
            $this->scopeNodeBrand($query, 'product_modifier_assignments', $node);
        }

        $query->whereExists(function ($products) use ($node): void {
            $products->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', 'product_modifier_assignments.product_id');
            $this->scopeBranchProducts($products, $node);
        });

        return $query->whereExists(function ($modifiers) use ($node): void {
            $modifiers->selectRaw('1')
                ->from('product_modifiers')
                ->whereColumn('product_modifiers.id', 'product_modifier_assignments.modifier_id')
                ->where('product_modifiers.company_id', $node->company_id);
            if ($this->hasColumn('product_modifiers', 'brand_id')) {
                $modifiers->whereIn('product_modifiers.brand_id', $this->nodeBrandIds($node));
            }
        });
    }

    private function scopeBranchUsers($query, SyncNode $node)
    {
        $platformAdministratorIds = array_values(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            (array) config('license.platform_admin_pos_user_ids', [])
        )));

        $query->where('pos_users.company_id', $node->company_id)
            ->where(function ($scope) use ($node) {
                $scope->whereNull('pos_users.branch_id')
                    ->orWhere('pos_users.branch_id', $node->branch_id)
                    ->orWhereExists(function ($branches) use ($node) {
                        $branches->selectRaw('1')
                            ->from('user_branches')
                            ->whereColumn('user_branches.pos_user_id', 'pos_users.id')
                            ->where('user_branches.company_id', $node->company_id)
                            ->where('user_branches.branch_id', $node->branch_id);
                    });
            });

        if ($platformAdministratorIds !== []) {
            $query->whereNotIn('pos_users.id', $platformAdministratorIds);
        }

        return $query;
    }

    private function scopeBranchUserRoles($query, SyncNode $node)
    {
        return $query->where(function ($roles) use ($node): void {
            $roles->where('roles.company_id', $node->company_id)
                ->orWhereExists(function ($users) use ($node): void {
                    $users->selectRaw('1')
                        ->from('pos_users')
                        ->whereColumn('pos_users.role_id', 'roles.id');
                    $this->scopeBranchUsers($users, $node);
                });
        });
    }

    private function scopeCompanyBranch($query, string $table, SyncNode $node)
    {
        $query->where("{$table}.company_id", $node->company_id);
        if ($this->hasColumn($table, 'branch_id')) {
            $query->where(fn ($scope) => $scope
                ->whereNull("{$table}.branch_id")
                ->orWhere("{$table}.branch_id", $node->branch_id));
        }

        return $query;
    }

    private function scopeBranchPrinters($query, SyncNode $node)
    {
        if ($node->deployment_mode === 'android') {
            // A mobile terminal receives its exact till routes and only the
            // explicitly selected branch/company defaults. The Android column
            // override separately removes IP, port and notes, so this remains
            // a usable QZ route without disclosing sibling/LAN configuration.
            $query
                ->where('printers.company_id', $node->company_id)
                ->where(function ($scope) use ($node): void {
                    $scope->where(function ($exactTill) use ($node): void {
                        $exactTill->where('printers.branch_id', $node->branch_id)
                            ->where('printers.till_id', $node->till_id);
                    });
                    if ($this->hasColumn('printers', 'is_default')) {
                        $scope->orWhere(function ($defaultRoute) use ($node): void {
                            $defaultRoute->whereNull('printers.till_id')
                                ->where('printers.is_default', true)
                                ->where(function ($location) use ($node): void {
                                    $location->whereNull('printers.branch_id')
                                        ->orWhere('printers.branch_id', $node->branch_id);
                                });
                        });
                    }
                });
            if ($this->hasColumn('printers', 'is_active')) {
                $query->where('printers.is_active', true);
            }
            if ($this->hasColumn('printers', 'deleted_at')) {
                $query->whereNull('printers.deleted_at');
            }

            return $query;
        }

        return $query
            ->where('printers.company_id', $node->company_id)
            ->where(function ($scope) use ($node): void {
                $scope->where('printers.branch_id', $node->branch_id)
                    ->orWhere(function ($companyDefault) use ($node): void {
                        $companyDefault->whereNull('printers.branch_id')
                            ->where(function ($tillScope) use ($node): void {
                                $tillScope->whereNull('printers.till_id')
                                    ->orWhereIn('printers.till_id', DB::table('pos_tills')
                                        ->select('id')
                                        ->where('company_id', $node->company_id)
                                        ->where('branch_id', $node->branch_id));
                            });
                    });
            });
    }

    private function scopeNodeOutputConfigurations($query, SyncNode $node)
    {
        $query->where('hospitality_output_configurations.company_id', $node->company_id)
            ->where('hospitality_output_configurations.branch_id', $node->branch_id);

        if ($node->deployment_mode === 'android') {
            $query->where('hospitality_output_configurations.till_id', $node->till_id);
        }

        return $query;
    }

    private function scopeBranchFrontStockClosings($query, SyncNode $node)
    {
        $windowStart = HospitalityTradingDatePolicy::systemDate(now()->subDays(120));
        $activeTradingDates = $this->hasTable('trading_days')
            ? DB::table('trading_days')
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->whereIn('status', ['open', 'day_end_running'])
                ->pluck('trading_date')
                ->map(fn ($date): string => HospitalityTradingDatePolicy::normalize($date) ?: '')
                ->filter()
                ->values()
                ->all()
            : [];

        $query->where('front_stock_daily_closings.company_id', $node->company_id)
            ->where('front_stock_daily_closings.branch_id', $node->branch_id)
            ->whereRaw("LOWER(COALESCE(front_stock_daily_closings.stock_area, '')) = 'front'")
            ->where(function ($window) use ($windowStart, $activeTradingDates): void {
                $window->whereDate('front_stock_daily_closings.closing_date', '>=', $windowStart);
                if ($activeTradingDates !== []) {
                    $window->orWhereIn('front_stock_daily_closings.closing_date', $activeTradingDates);
                }
            });
        if ($this->hasColumn('front_stock_daily_closings', 'deleted_at')) {
            $query->whereNull('front_stock_daily_closings.deleted_at');
        }

        return $query;
    }

    private function scopeBranchUserShiftAssignments($query, SyncNode $node)
    {
        $this->scopeCompanyBranch($query, 'pos_user_shift_assignments', $node);

        return $query->whereExists(function ($users) use ($node): void {
            $users->selectRaw('1')
                ->from('pos_users')
                ->whereColumn('pos_users.id', 'pos_user_shift_assignments.pos_user_id');
            $this->scopeBranchUsers($users, $node);
        });
    }

    private function scopeBranchProducts($query, SyncNode $node)
    {
        $query->where('products.company_id', $node->company_id);
        if (! $this->hasTable('brand_products') || ! $this->hasTable('shops')) {
            // Upgrade compatibility before the enterprise migration is run.
            if ($this->hasColumn('products', 'branch_id')) {
                $query->where(fn ($scope) => $scope
                    ->whereNull('products.branch_id')
                    ->orWhere('products.branch_id', $node->branch_id));
            }

            return $query;
        }

        // A branch-local product belongs to this shop. A shared/global row is
        // included only when the paired brand catalogues it or references it
        // as a recipe/modifier dependency; company-global no longer means
        // every brand may download it.
        if ($this->hasColumn('products', 'branch_id')) {
            $query->where(fn ($eligible) => $eligible
                ->whereNull('products.branch_id')
                ->orWhere('products.branch_id', $node->branch_id));
        }

        // Every dependency source below is an uncorrelated product-id list.
        // The database evaluates each list once per statement. Correlated
        // EXISTS clauses joined with OR were re-run for every company product
        // (recipes x ingredients each time), and this scope is embedded in
        // several datasets, so a full BOM catalogue pushed one snapshot past
        // the 30 second request limit and blocked edge pairing.
        return $query->where(function ($allowed) use ($node): void {
            if ($this->hasColumn('products', 'branch_id')) {
                $allowed->where('products.branch_id', $node->branch_id);
            }

            $method = $this->hasColumn('products', 'branch_id') ? 'orWhereIn' : 'whereIn';
            $allowed->{$method}('products.id', function ($catalogue) use ($node): void {
                $catalogue->select('scoped_brand_products.product_id')
                    ->from('brand_products as scoped_brand_products')
                    ->where('scoped_brand_products.company_id', $node->company_id)
                    ->whereIn('scoped_brand_products.brand_id', $this->nodeBrandIds($node))
                    ->where('scoped_brand_products.is_active', true)
                    ->whereNull('scoped_brand_products.deleted_at');
            });

            if ($this->hasColumn('products', 'parent_product_id')) {
                $allowed->orWhereIn('products.id', function ($children) use ($node): void {
                    $children->select('branded_children.parent_product_id')
                        ->from('products as branded_children')
                        ->join('brand_products as child_catalogue', 'child_catalogue.product_id', '=', 'branded_children.id')
                        ->whereNotNull('branded_children.parent_product_id')
                        ->where('branded_children.company_id', $node->company_id)
                        ->where('child_catalogue.company_id', $node->company_id)
                        ->whereIn('child_catalogue.brand_id', $this->nodeBrandIds($node))
                        ->where('child_catalogue.is_active', true)
                        ->whereNull('child_catalogue.deleted_at');
                });
            }

            if ($this->hasTable('food_recipes')) {
                foreach (['menu_product_id', 'produced_product_id'] as $column) {
                    if (! $this->hasColumn('food_recipes', $column)) {
                        continue;
                    }
                    $allowed->orWhereIn('products.id', function ($recipes) use ($node, $column): void {
                        $recipes->select("food_recipes.{$column}")
                            ->from('food_recipes')
                            ->where('food_recipes.company_id', $node->company_id)
                            ->whereNotNull("food_recipes.{$column}");
                        $this->scopeRecipeOwnership($recipes, $node);
                    });
                }

                if ($this->hasTable('food_recipe_ingredients')
                    && $this->hasColumn('food_recipe_ingredients', 'ingredient_product_id')) {
                    $allowed->orWhereIn('products.id', function ($ingredients) use ($node): void {
                        $ingredients->select('food_recipe_ingredients.ingredient_product_id')
                            ->from('food_recipe_ingredients')
                            ->join('food_recipes', 'food_recipes.id', '=', 'food_recipe_ingredients.food_recipe_id')
                            ->where('food_recipes.company_id', $node->company_id)
                            ->whereNotNull('food_recipe_ingredients.ingredient_product_id');
                        $this->scopeRecipeOwnership($ingredients, $node);
                    });
                }
            }

            if ($this->hasTable('modifier_group_options')) {
                $allowed->orWhereIn('products.id', function ($options) use ($node): void {
                    $options->select('modifier_group_options.modifier_product_id')
                        ->from('modifier_group_options')
                        ->where('modifier_group_options.company_id', $node->company_id)
                        ->whereIn('modifier_group_options.brand_id', $this->nodeBrandIds($node))
                        ->where('modifier_group_options.is_active', true)
                        ->whereNotNull('modifier_group_options.modifier_product_id');
                });
            }

            if ($this->hasTable('product_modifiers')
                && $this->hasColumn('product_modifiers', 'brand_id')
                && $this->hasColumn('product_modifiers', 'modifier_product_id')) {
                $allowed->orWhereIn('products.id', function ($modifiers) use ($node): void {
                    $modifiers->select('product_modifiers.modifier_product_id')
                        ->from('product_modifiers')
                        ->where('product_modifiers.company_id', $node->company_id)
                        ->whereIn('product_modifiers.brand_id', $this->nodeBrandIds($node))
                        ->where('product_modifiers.is_active', true)
                        ->whereNotNull('product_modifiers.modifier_product_id');
                });
            }
        });
    }

    private function nodeBrandIds(SyncNode $node)
    {
        return DB::table('shops')
            ->select('shops.brand_id')
            ->where('shops.company_id', $node->company_id)
            ->where('shops.branch_id', $node->branch_id)
            ->whereNotNull('shops.brand_id');
    }

    private function scopeBranchProductCategories($query, SyncNode $node)
    {
        if (! $this->hasColumn('products', 'category_id')) {
            return $query->where('product_categories.company_id', $node->company_id);
        }

        $productQuery = DB::table('products')->select('products.category_id')->whereNotNull('products.category_id');
        $this->scopeBranchProducts($productQuery, $node);
        $categoryIds = $productQuery->pluck('category_id')->map(fn ($id): string => (string) $id)->unique();
        $frontier = $categoryIds;

        // Preserve category-tree FK closure without shipping unrelated brand
        // metadata. Twenty levels is a defensive cycle/abuse ceiling.
        for ($depth = 0; $depth < 20 && $frontier->isNotEmpty(); $depth++) {
            $parents = DB::table('product_categories')
                ->where('company_id', $node->company_id)
                ->whereIn('id', $frontier->all())
                ->whereNotNull('parent_id')
                ->pluck('parent_id')
                ->map(fn ($id): string => (string) $id)
                ->diff($categoryIds)
                ->unique();
            $categoryIds = $categoryIds->merge($parents)->unique();
            $frontier = $parents;
        }

        $query->where('product_categories.company_id', $node->company_id);

        return $categoryIds->isEmpty()
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('product_categories.id', $categoryIds->all());
    }

    private function scopeBranchProductSubCategories($query, SyncNode $node)
    {
        if (! $this->hasColumn('products', 'sub_category_id')) {
            return $query->where('product_sub_categories.company_id', $node->company_id);
        }

        return $query->where('product_sub_categories.company_id', $node->company_id)
            ->whereIn('product_sub_categories.id', function ($products) use ($node): void {
                $products->select('products.sub_category_id')
                    ->from('products')
                    ->whereNotNull('products.sub_category_id');
                $this->scopeBranchProducts($products, $node);
            });
    }

    private function scopeBranchProductSuppliers($query, SyncNode $node)
    {
        if (! $this->hasColumn('products', 'supplier_id')
            && ! $this->hasTable('product_suppliers')) {
            return $query->where('suppliers.company_id', $node->company_id);
        }

        return $query->where('suppliers.company_id', $node->company_id)
            ->where(function ($suppliers) use ($node): void {
                if ($this->hasColumn('products', 'supplier_id')) {
                    $suppliers->whereIn('suppliers.id', function ($products) use ($node): void {
                        $products->select('products.supplier_id')
                            ->from('products')
                            ->whereNotNull('products.supplier_id');
                        $this->scopeBranchProducts($products, $node);
                    });
                }
                if ($this->hasTable('product_suppliers')) {
                    $method = $this->hasColumn('products', 'supplier_id') ? 'orWhereIn' : 'whereIn';
                    $suppliers->{$method}('suppliers.id', function ($links) use ($node): void {
                        $links->select('product_suppliers.supplier_id')
                            ->from('product_suppliers')
                            ->whereIn('product_suppliers.product_id', function ($products) use ($node): void {
                                $products->select('products.id')->from('products');
                                $this->scopeBranchProducts($products, $node);
                            });
                    });
                }
            });
    }

    private function scopeBranchProductUoms($query, SyncNode $node)
    {
        $hasReferences = $this->hasColumn('products', 'base_uom_id')
            || $this->hasTable('product_uom_conversions')
            || ($this->hasTable('food_recipes') && $this->hasColumn('food_recipes', 'yield_uom_id'))
            || ($this->hasTable('food_recipe_ingredients') && $this->hasColumn('food_recipe_ingredients', 'uom_id'));
        if (! $hasReferences) {
            return $query->where(fn ($scope) => $scope
                ->whereNull('uoms.company_id')
                ->orWhere('uoms.company_id', $node->company_id));
        }

        return $query->where(function ($uoms) use ($node): void {
            // Standard shared UOMs are intentionally available to every edge.
            $uoms->whereNull('uoms.company_id')
                ->orWhere(function ($companyUoms) use ($node): void {
                    $companyUoms->where('uoms.company_id', $node->company_id)
                        ->where(function ($referenced) use ($node): void {
                            if ($this->hasColumn('products', 'base_uom_id')) {
                                $referenced->whereIn('uoms.id', function ($products) use ($node): void {
                                    $products->select('products.base_uom_id')
                                        ->from('products')
                                        ->whereNotNull('products.base_uom_id');
                                    $this->scopeBranchProducts($products, $node);
                                });
                            }

                            if ($this->hasTable('product_uom_conversions')) {
                                foreach (['from_uom_id', 'to_uom_id'] as $column) {
                                    $method = $this->hasColumn('products', 'base_uom_id') ? 'orWhereIn' : 'whereIn';
                                    $referenced->{$method}('uoms.id', function ($conversions) use ($node, $column): void {
                                        $conversions->select("product_uom_conversions.{$column}")
                                            ->from('product_uom_conversions')
                                            ->whereIn('product_uom_conversions.product_id', function ($products) use ($node): void {
                                                $products->select('products.id')->from('products');
                                                $this->scopeBranchProducts($products, $node);
                                            });
                                    });
                                }
                            }

                            if ($this->hasTable('food_recipes')
                                && $this->hasColumn('food_recipes', 'yield_uom_id')) {
                                $referenced->orWhereIn('uoms.id', function ($recipes) use ($node): void {
                                    $recipes->select('food_recipes.yield_uom_id')
                                        ->from('food_recipes')
                                        ->whereNotNull('food_recipes.yield_uom_id');
                                    $this->scopeBranchRecipes($recipes, $node);
                                });
                            }

                            if ($this->hasTable('food_recipe_ingredients')
                                && $this->hasColumn('food_recipe_ingredients', 'uom_id')) {
                                $referenced->orWhereIn('uoms.id', function ($ingredients) use ($node): void {
                                    $ingredients->select('food_recipe_ingredients.uom_id')
                                        ->from('food_recipe_ingredients')
                                        ->whereNotNull('food_recipe_ingredients.uom_id');
                                    $this->scopeBranchRecipeIngredients($ingredients, $node);
                                });
                            }
                        });
                });
        });
    }

    private function scopeBranchProductRelations(
        $query,
        string $table,
        string $productColumn,
        SyncNode $node
    ) {
        return $query->where("{$table}.company_id", $node->company_id)
            ->whereExists(function ($products) use ($table, $productColumn, $node): void {
                $products->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.id', "{$table}.{$productColumn}");
                $this->scopeBranchProducts($products, $node);
            });
    }

    private function scopeBranchProductBreakRules($query, SyncNode $node)
    {
        $query->where('product_break_rules.company_id', $node->company_id);
        foreach (['parent_product_id', 'child_product_id'] as $column) {
            $query->whereExists(function ($products) use ($column, $node): void {
                $products->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.id', "product_break_rules.{$column}");
                $this->scopeBranchProducts($products, $node);
            });
        }

        return $query;
    }

    private function scopeBranchRecipes($query, SyncNode $node)
    {
        $query->where('food_recipes.company_id', $node->company_id);
        $this->scopeRecipeOwnership($query, $node);

        $query
            ->where(function ($recipes) use ($node): void {
                $recipes->whereExists(function ($products) use ($node): void {
                    $products->selectRaw('1')
                        ->from('products')
                        ->whereColumn('products.id', 'food_recipes.menu_product_id');
                    $this->scopeBranchProducts($products, $node);
                });
                if ($this->hasColumn('food_recipes', 'produced_product_id')) {
                    $recipes->orWhereExists(function ($products) use ($node): void {
                        $products->selectRaw('1')
                            ->from('products')
                            ->whereColumn('products.id', 'food_recipes.produced_product_id');
                        $this->scopeBranchProducts($products, $node);
                    });
                }
            });

        return $query;
    }

    /**
     * A recipe with an explicit brand belongs only to that brand. Legacy
     * brand-null recipes are accepted only when their output/menu product is
     * catalogued by the paired brand. In particular, a shared product used as
     * an ingredient by a sibling brand must never make that sibling recipe (or
     * its other ingredients) reachable from this terminal.
     */
    private function scopeRecipeOwnership($query, SyncNode $node)
    {
        $hasBrand = $this->hasColumn('food_recipes', 'brand_id');
        $canInfer = $this->hasTable('brand_products') && $this->hasTable('shops')
            && ($this->hasColumn('food_recipes', 'menu_product_id')
                || $this->hasColumn('food_recipes', 'produced_product_id'));

        if (! $hasBrand) {
            return $canInfer
                ? $this->scopeLegacyRecipeCatalogueOwnership($query, $node)
                : $query;
        }

        return $query->where(function ($ownership) use ($node, $canInfer): void {
            $ownership->whereIn('food_recipes.brand_id', $this->nodeBrandIds($node));
            if ($canInfer) {
                $ownership->orWhere(function ($legacy) use ($node): void {
                    $legacy->whereNull('food_recipes.brand_id');
                    $this->scopeLegacyRecipeCatalogueOwnership($legacy, $node);
                });
            }
        });
    }

    private function scopeLegacyRecipeCatalogueOwnership($query, SyncNode $node)
    {
        return $query->whereExists(function ($catalogue) use ($node): void {
            $catalogue->selectRaw('1')
                ->from('brand_products as recipe_catalogue')
                ->where('recipe_catalogue.company_id', $node->company_id)
                ->whereIn('recipe_catalogue.brand_id', $this->nodeBrandIds($node))
                ->where('recipe_catalogue.is_active', true);
            if ($this->hasColumn('brand_products', 'deleted_at')) {
                $catalogue->whereNull('recipe_catalogue.deleted_at');
            }
            $catalogue->where(function ($product): void {
                $hasMenuProduct = $this->hasColumn('food_recipes', 'menu_product_id');
                $hasProducedProduct = $this->hasColumn('food_recipes', 'produced_product_id');
                if ($hasMenuProduct) {
                    $product->whereColumn(
                        'recipe_catalogue.product_id',
                        'food_recipes.menu_product_id'
                    );
                }
                if ($hasProducedProduct) {
                    $method = $hasMenuProduct ? 'orWhereColumn' : 'whereColumn';
                    $product->{$method}(
                        'recipe_catalogue.product_id',
                        'food_recipes.produced_product_id'
                    );
                }
            });
        });
    }

    private function scopeBranchRecipeIngredients($query, SyncNode $node)
    {
        return $query->where('food_recipe_ingredients.company_id', $node->company_id)
            ->whereExists(function ($recipes) use ($node): void {
                $recipes->selectRaw('1')
                    ->from('food_recipes')
                    ->whereColumn('food_recipes.id', 'food_recipe_ingredients.food_recipe_id');
                $this->scopeBranchRecipes($recipes, $node);
            });
    }

    private function scopeBranchDiscountRelations($query, string $table, SyncNode $node)
    {
        $query->where("{$table}.company_id", $node->company_id)
            ->whereExists(function ($discounts) use ($table, $node): void {
                $discounts->selectRaw('1')
                    ->from('discounts')
                    ->whereColumn('discounts.id', "{$table}.discount_id")
                    ->where('discounts.company_id', $node->company_id);
                if ($this->hasColumn('discounts', 'branch_id')) {
                    $discounts->where(fn ($scope) => $scope
                        ->whereNull('discounts.branch_id')
                        ->orWhere('discounts.branch_id', $node->branch_id));
                }
            });

        if ($table === 'discount_products') {
            $this->scopeBranchProductRelations($query, $table, 'product_id', $node);
        }

        return $query;
    }

    private function scopeRolePermissions($query, SyncNode $node)
    {
        // The referenced role is the tenant authority. role_permissions was
        // introduced without company_id and legacy/imported rows may contain
        // null or stale values; filtering on that denormalized column would
        // make cloud authorization disagree with the edge projection.
        return $query->whereExists(function ($roles) use ($node) {
            $roles->selectRaw('1')
                ->from('roles')
                ->whereColumn('roles.id', 'role_permissions.role_id');
            $this->scopeBranchUserRoles($roles, $node);
        });
    }

    private function validateForeignKeyClosure(array $datasets): void
    {
        $ids = [];
        foreach (self::DATASETS as $table => $_definition) {
            $rows = $datasets[$table] ?? [];
            if (! is_array($rows)) {
                throw new InvalidArgumentException("Configuration dataset {$table} is invalid.");
            }
            $ids[$table] = collect($rows)
                ->filter(fn ($row) => is_array($row) && is_scalar($row['id'] ?? null))
                ->mapWithKeys(fn ($row) => [(string) $row['id'] => true])
                ->all();
        }

        foreach (self::FOREIGN_DATASETS as $table => $foreignKeys) {
            foreach (($datasets[$table] ?? []) as $row) {
                if (! is_array($row)) {
                    throw new InvalidArgumentException("Configuration dataset {$table} contains an invalid row.");
                }
                foreach ($foreignKeys as $column => $parentTable) {
                    $foreignId = $row[$column] ?? null;
                    if ($foreignId === null || $foreignId === '') {
                        continue;
                    }
                    if (! is_scalar($foreignId) || ! isset($ids[$parentTable][(string) $foreignId])) {
                        throw new InvalidArgumentException(
                            "Configuration dataset {$table}.{$column} references a row outside this snapshot."
                        );
                    }
                }
            }
        }
    }

    private function guardLicenseRevision(array $row): void
    {
        if (! $this->hasColumn('licenses', 'entitlement_revision')) {
            return;
        }
        $existing = DB::table('licenses')->where('id', $row['id'])->first([
            'entitlement_revision',
            ...array_values(array_filter(
                ['entitlement_payload_v2', 'entitlement_signature_v2'],
                fn (string $column) => $this->hasColumn('licenses', $column)
            )),
        ]);
        if (! $existing) {
            return;
        }

        $currentRevision = (int) ($existing->entitlement_revision ?? 0);
        $incomingRevision = (int) ($row['entitlement_revision'] ?? 0);
        if ($incomingRevision < $currentRevision) {
            throw new InvalidArgumentException('A license entitlement revision cannot move backwards.');
        }
        if ($incomingRevision === $currentRevision && $currentRevision > 0) {
            foreach (['entitlement_payload_v2', 'entitlement_signature_v2'] as $column) {
                if (array_key_exists($column, $row)
                    && isset($existing->{$column})
                    && ! hash_equals((string) $existing->{$column}, (string) $row[$column])) {
                    throw new InvalidArgumentException('A license entitlement revision was reused with different signed content.');
                }
            }
        }
    }

    private function preservePendingEdgeOperationalState(
        SyncLocalState $state,
        string $table,
        array &$row
    ): void {
        $mutableColumns = match ($table) {
            'product_stocks', 'inventory_location_balances' => [
                'quantity_on_hand', 'reserved_quantity', 'available_quantity',
            ],
            'inventory_batches' => [
                'quantity_on_hand', 'reserved_quantity', 'quarantined_quantity',
            ],
            'product_costs' => [
                'average_cost', 'last_cost', 'total_quantity', 'total_value',
            ],
            'credit_customers' => ['current_balance', 'available_credit'],
            default => [],
        };
        if ($mutableColumns === []
            || ! $this->hasPendingSaleEvents($state)
            || ! DB::table($table)->where('id', $row['id'])->exists()) {
            return;
        }

        // A pull must never overwrite stock or customer balance mutations
        // belonging to an undelivered local sale. Push runs first; once every
        // sale is acknowledged, the cloud projection becomes authoritative.
        foreach ($mutableColumns as $column) {
            unset($row[$column]);
        }
    }

    /**
     * Projection UUIDs are not business identities. An edge converted from a
     * pre-existing standalone database may already have the same projection
     * under a different UUID, so resolve it by its unique natural key before
     * upsert. This prevents a unique-key collision and lets pending values be
     * preserved on the row that the local sale actually mutated.
     */
    private function alignOperationalProjectionIdentity(string $table, array &$row): void
    {
        $columns = match ($table) {
            'product_stocks' => array_values(array_filter([
                'company_id', 'branch_id', 'product_id',
                $this->hasColumn($table, 'stock_area') ? 'stock_area' : null,
            ])),
            'product_costs' => ['company_id', 'branch_id', 'product_id'],
            'inventory_location_balances' => [
                'company_id', 'branch_id', 'location_id', 'product_id', 'batch_id',
            ],
            // A pre-existing local POS may have opened the same business date
            // with a different UUID before it was paired. Preserve that local
            // row identity (and any sale foreign keys pointing at it) while
            // applying the cloud's authoritative status and timestamps.
            'trading_days' => ['company_id', 'branch_id', 'trading_date'],
            'front_stock_daily_closings' => [
                'company_id', 'branch_id', 'closing_date', 'stock_area',
            ],
            default => [],
        };
        if ($columns === []) {
            return;
        }

        $query = DB::table($table);
        foreach ($columns as $column) {
            if (! array_key_exists($column, $row)) {
                return;
            }
            $row[$column] === null || $row[$column] === ''
                ? $query->whereNull($column)
                : $query->where($column, $row[$column]);
        }
        $existingId = $query->value('id');
        if ($existingId) {
            $row['id'] = (string) $existingId;
        }
    }

    private function hasPendingSaleEvents(SyncLocalState $state): bool
    {
        $key = (string) $state->node_id;
        if (! array_key_exists($key, $this->pendingSaleMemo)) {
            $pendingOutbox = SyncOutboxEvent::query()
                ->where('source_node_id', $state->node_id)
                ->where('event_type', 'sale.completed')
                ->whereNull('delivered_at')
                ->exists();
            $undiscoveredSale = $this->hasTable('sales_transactions')
                && DB::table('sales_transactions as sales')
                    ->where('sales.company_id', $state->company_id)
                    ->where('sales.branch_id', $state->branch_id)
                    ->where('sales.status', 'completed')
                    ->whereNotExists(function ($query) use ($state) {
                        $query->selectRaw('1')
                            ->from('sync_outbox_events as outbox')
                            ->whereColumn('outbox.aggregate_id', 'sales.id')
                            ->where('outbox.source_node_id', $state->node_id)
                            ->where('outbox.event_type', 'sale.completed');
                    })
                    ->exists();
            $this->pendingSaleMemo[$key] = $pendingOutbox || $undiscoveredSale;
        }

        return $this->pendingSaleMemo[$key];
    }

    private function isSensitiveSettingKey(string $key): bool
    {
        $normalised = strtolower(trim($key));
        if ($normalised === '') {
            return true;
        }

        foreach ([
            'secret', 'password', 'passwd', 'token', 'private_key', 'private-key',
            'api_key', 'api-key', 'apikey', 'credential', 'client_secret',
            'signing_key', 'encryption_key', 'qz_', 'fiscal_private',
        ] as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function availableColumns(string $table, array $allowlist): array
    {
        $available = array_flip($this->columnListing($table));

        return array_values(array_filter(
            $allowlist,
            static fn (string $column) => isset($available[$column])
        ));
    }
}
