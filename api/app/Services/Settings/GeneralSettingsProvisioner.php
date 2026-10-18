<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\GeneralSetting;

/**
 * Gives a company the company-level general settings the General Settings
 * screen lists. Only settings the system actually reads are provisioned, so
 * every entry an administrator changes has an effect.
 *
 * Only missing keys are created; existing values are never overwritten.
 */
class GeneralSettingsProvisioner
{
    /** key => [value, type, module, description] */
    public const DEFAULTS = [
        'auto_shift_logout' => ['false', 'boolean', 'shifts', 'Sign cashiers out automatically when their shift ends.'],
        'allow_login_with_unclosed_previous_shift' => ['true', 'boolean', 'shifts', 'Let a cashier sign in while an earlier shift is still open.'],
        'allow_continue_unclosed_shift' => ['true', 'boolean', 'shifts', 'Let a cashier carry on with their own unclosed shift.'],
        'only_original_cashier_can_close_shift' => ['true', 'boolean', 'shifts', 'Only the cashier who opened a shift can close it.'],
        'manager_can_force_close_shift' => ['true', 'boolean', 'shifts', "Let a manager close another cashier's shift."],
        'allow_new_shift_when_previous_unclosed' => ['false', 'boolean', 'shifts', 'Allow a new shift while the previous one is still open.'],
        'allow_new_shift_within_same_shift_interval' => ['false', 'boolean', 'shifts', 'Allow a second shift within the same shift period.'],
        'require_cashup_before_new_shift' => ['true', 'boolean', 'shifts', 'Require the cash-up before a new shift can start.'],
        'shift_warning_before_end_minutes' => ['15', 'integer', 'shifts', 'Minutes before the shift ends to warn the cashier.'],
        'shift_grace_minutes_after_end' => ['10', 'integer', 'shifts', 'Minutes a shift may run past its end time.'],
        'show_shift_banner_in_admin' => ['true', 'boolean', 'shifts', 'Show the shift status banner in Back Office.'],
        'block_sales_after_shift_end' => ['false', 'boolean', 'shifts', 'Stop sales once the shift and its grace time have ended.'],
        'cashup_must_include_cashouts' => ['true', 'boolean', 'shifts', 'Include cash-outs in the cash-up.'],
        'require_cashout_reason' => ['true', 'boolean', 'shifts', 'Require a reason for every cash-out.'],
        'cashout_requires_manager_approval' => ['false', 'boolean', 'shifts', 'Require manager approval for cash-outs.'],

        'barcode_scan_enabled' => ['true', 'boolean', 'sales', 'Allow barcode scanning on the sales screen.'],
        'touch_keyboard_enabled' => ['true', 'boolean', 'sales', 'Show the on-screen keyboard on the sales screen.'],
        'sales_screen_layout' => ['standard', 'string', 'sales', 'Sales screen layout: standard or visual.'],
        'price_level_override_enabled' => ['true', 'boolean', 'sales', 'Allow switching an item to another price level at the till.'],
        'price_level_override_requires_manager_approval' => ['true', 'boolean', 'sales', 'Price level overrides need manager approval.'],
        'cashier_can_price_level_override_without_manager' => ['false', 'boolean', 'sales', 'Let cashiers override price levels without a manager.'],

        'shift_end_cashup_report_print_enabled' => ['true', 'boolean', 'cashup', 'Print the cash-up report when a shift ends.'],
        'shift_end_cashup_report_include_product_sales' => ['false', 'boolean', 'cashup', 'Include product sales in the shift-end cash-up report.'],

        'stock_transfer_requires_approval' => ['true', 'boolean', 'inventory', 'Stock transfers need approval before stock moves.'],
        'stock_transfer_allow_requester_approval' => ['false', 'boolean', 'inventory', 'Let the person who requested a transfer approve it.'],

        'receipt_auto_print' => ['true', 'boolean', 'receipt', 'Print the receipt automatically after each sale.'],
        'receipt_footer_message' => ['Thank you for shopping with us.', 'string', 'receipt', 'Message printed at the bottom of receipts.'],
        'receipt_size' => ['80mm', 'string', 'receipt', 'Receipt paper width: 80mm or 58mm.'],
        'receipt_barcode_enabled' => ['true', 'boolean', 'receipt', 'Print a barcode on receipts.'],
        'receipt_barcode_show_text' => ['true', 'boolean', 'receipt', 'Print the receipt number under the barcode.'],
        'receipt_barcode_height' => ['38', 'integer', 'receipt', 'Receipt barcode height in pixels.'],
        'receipt_barcode_width_factor' => ['1.35', 'decimal', 'receipt', 'Receipt barcode width multiplier.'],
        'receipt_font_family' => ['mono', 'string', 'receipt', 'Receipt font: mono or sans.'],
        'receipt_body_font_size' => ['12', 'decimal', 'receipt', 'Font size for receipt lines.'],
        'receipt_tiny_font_size' => ['10.5', 'decimal', 'receipt', 'Font size for small receipt print.'],
        'receipt_title_font_size' => ['14', 'decimal', 'receipt', 'Font size for receipt titles.'],
        'receipt_brand_font_size' => ['18', 'decimal', 'receipt', 'Font size for the brand name.'],
        'receipt_branch_font_size' => ['12', 'decimal', 'receipt', 'Font size for the shop name.'],
        'receipt_total_font_size' => ['16', 'decimal', 'receipt', 'Font size for the receipt total.'],

        'generate_product_code' => ['false', 'boolean', 'product', 'Create product codes automatically when left blank.'],
        'generate_product_sku' => ['false', 'boolean', 'product', 'Create SKUs automatically when left blank.'],
        'product_code_prefix' => ['PRD', 'string', 'product', 'Prefix for automatic product codes.'],
        'sku_code_prefix' => ['SKU', 'string', 'product', 'Prefix for automatic SKUs.'],

        'discount_module_enabled' => ['true', 'boolean', 'discounts', 'Allow discounts at the till.'],
        'manual_discount_requires_manager_approval' => ['true', 'boolean', 'discounts', 'Manual discounts need manager approval.'],
        'max_cashier_discount_percent_without_manager' => ['0', 'decimal', 'discounts', 'Largest discount (%) a cashier can give without a manager.'],

        'receive_stock_to_boh_first' => ['true', 'boolean', 'hospitality', 'Receive purchased stock into back stock first.'],
        'recipe_consumes_front_stock' => ['true', 'boolean', 'hospitality', 'Menu recipes use front (selling) stock.'],
        'hospitality_order_start_minimum' => ['20', 'integer', 'hospitality', 'Lowest starting order number each day.'],
        'hospitality_order_start_maximum' => ['899', 'integer', 'hospitality', 'Highest starting order number each day.'],
        'hospitality_order_prefix' => ['ORD', 'string', 'hospitality', 'Prefix for order numbers.'],
        'hospitality_order_number_width' => ['4', 'integer', 'hospitality', 'Number of digits in each order number.'],
    ];

    /**
     * Values the system uses today when one of these settings is missing,
     * where that differs from DEFAULTS. Backfilling an existing company with
     * these keeps it behaving exactly as before; administrators can then
     * switch the features on from General Settings.
     */
    public const MISSING_SETTING_BEHAVIOUR = [
        'barcode_scan_enabled' => 'false',
        'touch_keyboard_enabled' => 'false',
        'price_level_override_enabled' => 'false',
        'shift_end_cashup_report_print_enabled' => 'false',
        'receipt_auto_print' => 'false',
        'receive_stock_to_boh_first' => 'false',
        'recipe_consumes_front_stock' => 'false',
    ];

    /** @return int number of settings created */
    public function ensureCompanyDefaults(string $companyId, bool $keepCurrentBehaviour = false): int
    {
        $existing = GeneralSetting::query()
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->pluck('setting_key')
            ->flip();
        $hasDescription = Schema::hasColumn('general_settings', 'description');

        $created = 0;
        foreach (self::DEFAULTS as $key => [$value, $type, $module, $description]) {
            if ($existing->has($key)) {
                continue;
            }

            if ($keepCurrentBehaviour && array_key_exists($key, self::MISSING_SETTING_BEHAVIOUR)) {
                $value = self::MISSING_SETTING_BEHAVIOUR[$key];
            }

            GeneralSetting::create(array_filter([
                'company_id' => $companyId,
                'branch_id' => null,
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_type' => $type,
                'module' => $module,
                'description' => $hasDescription ? $description : null,
                'is_active' => true,
            ], fn ($field) => $field !== null));
            $created++;
        }

        return $created;
    }
}
