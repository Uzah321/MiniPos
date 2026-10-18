<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\RolesAndPermission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'create_user', 'group' => 'Authentication', 'display_name' => 'User Create'],
            ['name' => 'manage_user', 'group' => 'Authentication', 'display_name' => 'Users Management'],
            ['name' => 'manage_access_assignments', 'group' => 'Authentication', 'display_name' => 'Manage Scoped Access Assignments'],
            ['name' => 'manage_authentication_policies', 'group' => 'Authentication', 'display_name' => 'Manage Authentication Policies'],
            ['name' => 'view_role', 'group' => 'Authentication', 'display_name' => 'View Role'],
            ['name' => 'create_role', 'group' => 'Authentication', 'display_name' => 'Create Role'],
            ['name' => 'update_role', 'group' => 'Authentication', 'display_name' => 'Update Role'],
            ['name' => 'delete_role', 'group' => 'Authentication', 'display_name' => 'Delete Role'],
            ['name' => 'view_permission', 'group' => 'Authentication', 'display_name' => 'View Permission'],
            ['name' => 'view_role_permission', 'group' => 'Authentication', 'display_name' => 'View Role Permission'],
            ['name' => 'assign_role_permission', 'group' => 'Authentication', 'display_name' => 'Assign Role Permission'],
            ['name' => 'sync_role_permission', 'group' => 'Authentication', 'display_name' => 'Sync Role Permission'],
            ['name' => 'remove_role_permission', 'group' => 'Authentication', 'display_name' => 'Remove Role Permission'],
            ['name' => 'manage_general_settings', 'group' => 'Settings', 'display_name' => 'Manage General Settings'],
            ['name' => 'manage_shift_settings', 'group' => 'Settings', 'display_name' => 'Manage Shift Settings'],
            ['name' => 'manage_branch_settings', 'group' => 'Settings', 'display_name' => 'Manage Branch Settings'],
            ['name' => 'view_currency', 'group' => 'Settings', 'display_name' => 'View Currency'],
            ['name' => 'create_currency', 'group' => 'Settings', 'display_name' => 'Create Currency'],
            ['name' => 'update_currency', 'group' => 'Settings', 'display_name' => 'Update Currency'],
            ['name' => 'delete_currency', 'group' => 'Settings', 'display_name' => 'Delete Currency'],
            ['name' => 'view_payment_type', 'group' => 'Settings', 'display_name' => 'View Payment Type'],
            ['name' => 'delete_payment_type', 'group' => 'Settings', 'display_name' => 'Delete Payment Type'],
            ['name' => 'create_payment_type', 'group' => 'Settings', 'display_name' => 'Create Payment Type'],
            ['name' => 'update_payment_type', 'group' => 'Settings', 'display_name' => 'Update Payment Type'],
            ['name' => 'till_management', 'group' => 'Settings', 'display_name' => 'Till Management'],
            ['name' => 'printer_management', 'group' => 'Settings', 'display_name' => 'Printer Management'],
            ['name' => 'manage_payment_types', 'group' => 'Settings', 'display_name' => 'Manage Payment Types'],
            ['name' => 'manage_license', 'group' => 'Settings', 'display_name' => 'Manage Company License'],
            ['name' => 'view_sale', 'group' => 'Sales', 'display_name' => 'View Sales'],
            ['name' => 'create_sale', 'group' => 'Sales', 'display_name' => 'Create Sale'],
            ['name' => 'view_hospitality_tables', 'group' => 'Hospitality Tables', 'display_name' => 'View hospitality table setup and availability'],
            ['name' => 'manage_hospitality_tables', 'group' => 'Hospitality Tables', 'display_name' => 'Configure hospitality tables and assignment policy'],
            ['name' => 'manage_open_tables', 'group' => 'Hospitality Tables', 'display_name' => 'Open, update, hang and recall hospitality table orders'],
            ['name' => 'clear_open_tables', 'group' => 'Hospitality Tables', 'display_name' => 'Clear unpaid hospitality table orders'],
            ['name' => 'void_sales', 'group' => 'Sales', 'display_name' => 'Void Sales'],
            ['name' => 'reprint_receipt', 'group' => 'Sales', 'display_name' => 'Reprint Receipt'],
            ['name' => 'view_cancelled_receipts', 'group' => 'Sales', 'display_name' => 'View Cancelled Receipts'],
            ['name' => 'view_receipt_register', 'group' => 'Sales', 'display_name' => 'View Receipt Register'],
            ['name' => 'cancel_receipt', 'group' => 'Sales', 'display_name' => 'Cancel Receipt'],
            ['name' => 'manage_discounts', 'group' => 'Sales', 'display_name' => 'Manage Discounts'],
            ['name' => 'end_shift', 'group' => 'Sales', 'display_name' => 'End Shift'],
            ['name' => 'cashup_shift', 'group' => 'Sales', 'display_name' => 'Cash Up Shift'],
            ['name' => 'cashup_on_behalf', 'group' => 'Sales', 'display_name' => 'Cash Up Other Cashiers'],
            ['name' => 'view_profit_loss', 'group' => 'Sales', 'display_name' => 'Profit & Loss'],
            ['name' => 'list_product', 'group' => 'Products', 'display_name' => 'List Product'],
            ['name' => 'tax_management', 'group' => 'Products', 'display_name' => 'Tax Management'],
            ['name' => 'product_management', 'group' => 'Products', 'display_name' => 'Product Management'],
            ['name' => 'create_product_category', 'group' => 'Products', 'display_name' => 'Create Product Category'],
            ['name' => 'update_product_category', 'group' => 'Products', 'display_name' => 'Update Product Category'],
            ['name' => 'delete_product_category', 'group' => 'Products', 'display_name' => 'Delete Product Category'],
            ['name' => 'view_product_category', 'group' => 'Products', 'display_name' => 'View Product Category'],
            ['name' => 'product_barcode_printing', 'group' => 'Products', 'display_name' => 'Product Barcode Printing'],
            ['name' => 'import_products', 'group' => 'Products', 'display_name' => 'Import Products'],
            ['name' => 'price_management', 'group' => 'Products', 'display_name' => 'Price Management'],
            ['name' => 'view_price_list', 'group' => 'Products', 'display_name' => 'View Price List'],
            ['name' => 'add_price', 'group' => 'Products', 'display_name' => 'Add Price'],
            ['name' => 'delete_price', 'group' => 'Products', 'display_name' => 'Delete Price'],
            ['name' => 'assign_role_price', 'group' => 'Products', 'display_name' => 'Assign Role Price'],
            ['name' => 'view_price_adjustment_history', 'group' => 'Products', 'display_name' => 'View Price Adjustment History'],
            ['name' => 'receive_stock', 'group' => 'Products', 'display_name' => 'Receive Stock'],
            ['name' => 'adjust_stock', 'group' => 'Products', 'display_name' => 'Adjust Stock'],
            ['name' => 'stock_take', 'group' => 'Products', 'display_name' => 'Stock Take'],
            ['name' => 'stock_transfers', 'group' => 'Products', 'display_name' => 'Interbranch Stock Transfers'],
            ['name' => 'view_supplier', 'group' => 'Supplier', 'display_name' => 'View Supplier'],
            ['name' => 'store_supplier', 'group' => 'Supplier', 'display_name' => 'Store Supplier'],
            ['name' => 'update_supplier', 'group' => 'Supplier', 'display_name' => 'Update Supplier'],
            ['name' => 'delete_supplier', 'group' => 'Supplier', 'display_name' => 'Delete Supplier'],
            ['name' => 'purchase_orders', 'group' => 'Purchases', 'display_name' => 'Purchase Orders'],
            ['name' => 'purchase_returns', 'group' => 'Purchases', 'display_name' => 'Purchase Returns'],
            ['name' => 'supplier_invoices', 'group' => 'Purchases', 'display_name' => 'Supplier Invoices'],
            ['name' => 'view_purchase_receipts', 'group' => 'Purchases', 'display_name' => 'View Goods Received Notes'],
            ['name' => 'view_supplier_payments', 'group' => 'Purchases', 'display_name' => 'View Supplier Payments'],
            ['name' => 'supplier_payments', 'group' => 'Purchases', 'display_name' => 'Post Supplier Payments'],
            ['name' => 'reverse_supplier_payments', 'group' => 'Purchases', 'display_name' => 'Reverse Supplier Payments'],
            ['name' => 'inventory_dashboard', 'group' => 'Inventory', 'display_name' => 'View Enterprise Inventory Dashboard'],
            ['name' => 'view_inventory_variance_agent', 'group' => 'Inventory', 'display_name' => 'View Inventory Variance Agent'],
            ['name' => 'manage_inventory_variance_agent', 'group' => 'Inventory', 'display_name' => 'Configure and Run Inventory Variance Agent'],
            ['name' => 'manage_warehouses', 'group' => 'Inventory', 'display_name' => 'Manage Warehouses and Locations'],
            ['name' => 'view_inventory_batches', 'group' => 'Inventory', 'display_name' => 'View Batches and Expiry'],
            ['name' => 'manage_inventory_batches', 'group' => 'Inventory', 'display_name' => 'Manage Inventory Batches'],
            ['name' => 'view_inventory_alerts', 'group' => 'Inventory', 'display_name' => 'View Inventory Alerts'],
            ['name' => 'manage_inventory_alerts', 'group' => 'Inventory', 'display_name' => 'Manage Inventory Alerts'],
            ['name' => 'view_inventory_recalls', 'group' => 'Inventory', 'display_name' => 'View Inventory Recalls'],
            ['name' => 'manage_inventory_recalls', 'group' => 'Inventory', 'display_name' => 'Manage Inventory Recalls'],
            ['name' => 'view_inventory_movements', 'group' => 'Inventory', 'display_name' => 'View Inventory Movements'],
            ['name' => 'view_warehouse_stock', 'group' => 'Inventory', 'display_name' => 'View Warehouse Stock and Valuation'],
            ['name' => 'request_warehouse_transfer', 'group' => 'Inventory', 'display_name' => 'Request Warehouse Transfers'],
            ['name' => 'approve_warehouse_transfer', 'group' => 'Inventory', 'display_name' => 'Approve Warehouse Transfers'],
            ['name' => 'dispatch_warehouse_transfer', 'group' => 'Inventory', 'display_name' => 'Dispatch Warehouse Transfers'],
            ['name' => 'receive_warehouse_transfer', 'group' => 'Inventory', 'display_name' => 'Receive Warehouse Transfers'],
            ['name' => 'cash_up_reports', 'group' => 'Reports', 'display_name' => 'Cash Up Reports'],
            ['name' => 'view_cashup_financial_amounts', 'group' => 'Reports', 'display_name' => 'View Sensitive Cashup Financial Amounts'],
            ['name' => 'view_sales_reports', 'group' => 'Reports', 'display_name' => 'View Sales Reports'],
            ['name' => 'view_day_end_reports', 'group' => 'Reports', 'display_name' => 'View Day End Reports'],
            ['name' => 'view_stock_reports', 'group' => 'Reports', 'display_name' => 'View Stock Reports'],
            ['name' => 'view_tax_reports', 'group' => 'Reports', 'display_name' => 'View Tax Reports'],
            ['name' => 'view_audit_logs', 'group' => 'Security', 'display_name' => 'View Audit Logs'],
            ['name' => 'view_manager_approvals', 'group' => 'Security', 'display_name' => 'View Manager Approvals'],
            ['name' => 'view_login_sessions', 'group' => 'Security', 'display_name' => 'View Login Sessions'],
            ['name' => 'manage_integrations', 'group' => 'Integrations', 'display_name' => 'Manage Integrations'],
            ['name' => 'view_create_sale_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Create Sale Modal'],
            ['name' => 'view_void_reprint_receipt_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Void/Reprint Receipt Modal'],
            ['name' => 'view_cancelled_receipts_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Cancelled Receipts Modal'],
            ['name' => 'view_receipt_register_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Receipt Register Modal'],
            ['name' => 'view_product_management_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Product Management Modal'],
            ['name' => 'view_product_category_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Product Category Modal'],
            ['name' => 'view_quotation_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Quotation Modal'],
            // Transition alias retained for installations and cached clients
            // that still carry the original misspelling.
            ['name' => 'view_qoutation_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Quotation Modal (Legacy Alias)'],
            ['name' => 'inventory_management', 'group' => 'Modal Permissions', 'display_name' => 'View Inventory Management Modal'],
            ['name' => 'view_product_barcode_printing_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Product Barcode Printing Modal'],
            ['name' => 'view_product_import_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Product Import Modal'],
            ['name' => 'view_price_level_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Price Level Modal'],
            ['name' => 'view_boh_admin', 'group' => 'Modal Permissions', 'display_name' => 'View Boh admin'],
            ['name' => 'view_price_list_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Price List Modal'],
            ['name' => 'view_product_price_adjustment_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Product Price Adjustment Modal'],
            ['name' => 'view_role_price_assignment_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Role Price Assignment Modal'],
            ['name' => 'view_stock_take_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Stock Take Modal'],
            ['name' => 'view_stock_adjustment_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Stock Adjustment Modal'],
            ['name' => 'view_stock_transfer_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Stock Transfer Modal'],
            ['name' => 'view_supplier_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Supplier Modal'],
            ['name' => 'view_receive_stock_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Receive Stock Modal'],
            ['name' => 'view_sales_reports_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Sales Reports Modal'],
            ['name' => 'view_cashup_history_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Cashup History Modal'],
            ['name' => 'view_cashout_report_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Cashout Report Modal'],
            ['name' => 'view_day_end_reports_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Day End Reports Modal'],
            ['name' => 'manufacturing_view', 'group' => 'Modal Permissions', 'display_name' => 'Manufacturing View'],
            ['name' => 'view_stock_reports_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Stock Reports Modal'],
            ['name' => 'food_manufacturing_view', 'group' => 'Modal Permissions', 'display_name' => 'View Food Manufacturing View'],
            ['name' => 'view_stock_adjustment_history_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Stock Adjustment History Modal'],
            ['name' => 'view_price_adjustment_history_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Price Adjustment History Modal'],
            ['name' => 'view_branch_settings_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Branch Settings Modal'],
            ['name' => 'view_till_management_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Till Management Modal'],
            ['name' => 'view_shift_settings_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Shift Settings Modal'],
            ['name' => 'view_currency_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Currency Modal'],
            ['name' => 'view_payment_type_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Payment Type Modal'],
            ['name' => 'general_procedure', 'group' => 'Modal Permissions', 'display_name' => 'View General Procedure'],
            ['name' => 'view_general_settings_modal', 'group' => 'Modal Permissions', 'display_name' => 'View General Settings Modal'],
            ['name' => 'view_user_management_modal', 'group' => 'Modal Permissions', 'display_name' => 'View User Management Modal'],
            ['name' => 'view_roles_permissions_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Roles & Permissions Modal'],
            ['name' => 'view_license_modal', 'group' => 'Modal Permissions', 'display_name' => 'View License Modal'],
            ['name' => 'view_printer_management_modal', 'group' => 'Modal Permissions', 'display_name' => 'View Printer Management Modal'],
            ['name' => 'view_discounts_page', 'group' => 'Page Permissions', 'display_name' => 'View Discounts Page'],
            ['name' => 'view_purchase_orders_page', 'group' => 'Page Permissions', 'display_name' => 'View Purchase Orders Page'],
            ['name' => 'view_purchase_returns_page', 'group' => 'Page Permissions', 'display_name' => 'View Purchase Returns Page'],
            ['name' => 'view_supplier_invoices_page', 'group' => 'Page Permissions', 'display_name' => 'View Supplier Invoices Page'],
            ['name' => 'view_tax_reports_page', 'group' => 'Page Permissions', 'display_name' => 'View Tax Reports Page'],
            ['name' => 'view_audit_logs_page', 'group' => 'Page Permissions', 'display_name' => 'View Audit Logs Page'],
            ['name' => 'view_manager_approvals_page', 'group' => 'Page Permissions', 'display_name' => 'View Manager Approvals Page'],
            ['name' => 'view_login_sessions_page', 'group' => 'Page Permissions', 'display_name' => 'View Login Sessions Page'],
            ['name' => 'view_fiscal_device_page', 'group' => 'Page Permissions', 'display_name' => 'View Fiscal Device Page'],
            ['name' => 'view_payment_gateway_page', 'group' => 'Page Permissions', 'display_name' => 'View Payment Gateway Page'],
            ['name' => 'view_barcode_scanner_page', 'group' => 'Page Permissions', 'display_name' => 'View Barcode Scanner Page'],
            ['name' => 'manager_approval', 'group' => 'manager-approval', 'display_name' => 'General Manager Approval'],
            ['name' => 'approve_cash_drawer_movement', 'group' => 'manager-approval', 'display_name' => 'Approve Cash Drawer Movement'],
            ['name' => 'approve_cashup_end_shift', 'group' => 'manager-approval', 'display_name' => 'Approve Cashup and End Shift'],
            ['name' => 'approve_discount_override', 'group' => 'manager-approval', 'display_name' => 'Approve Discount Override'],
            ['name' => 'approve_price_override', 'group' => 'manager-approval', 'display_name' => 'Approve POS Price Override'],
            ['name' => 'approve_void_sale', 'group' => 'manager-approval', 'display_name' => 'Approve Sale Void or Receipt Cancellation'],

            ['name' => 'price_level_override', 'group' => 'sales', 'display_name' => 'Use Price Level Override During Sale'],
            ['name' => 'view_product_price_levels', 'group' => 'sales', 'display_name' => 'View Product Price Levels During Sale'],
            ['name' => 'approve_price_override', 'group' => 'manager-approval', 'display_name' => 'Approve POS Price Level Override'],

            ['name' => 'view_discounts', 'group' => 'discounts', 'display_name' => 'View Discounts'],
            ['name' => 'manage_discounts', 'group' => 'discounts', 'display_name' => 'Manage Discounts'],
            ['name' => 'apply_discount', 'group' => 'discounts', 'display_name' => 'Apply Discount During Sale'],
            ['name' => 'approve_discount_override', 'group' => 'discounts', 'display_name' => 'Approve Discount Override'],
            ['name' => 'view_discount_reports', 'group' => 'reports', 'display_name' => 'View Discount Reports'],
            ['name' => 'export_discount_reports', 'group' => 'reports', 'display_name' => 'Export Discount Reports'],
            ['name' => 'view_discount_modal', 'group' => 'modal-permissions', 'display_name' => 'View Discount Modal'],
            ['name' => 'view_discount_reports_modal', 'group' => 'modal-permissions', 'display_name' => 'View Discount Reports Modal'],

            ['name' => 'view_quotations', 'group' => 'quotation', 'display_name' => 'View quotation desk and quotations'],
            ['name' => 'create_quotations', 'group' => 'quotation', 'display_name' => 'Create quotations'],
            ['name' => 'update_quotations', 'group' => 'quotation', 'display_name' => 'Update quotations'],
            ['name' => 'issue_quotations', 'group' => 'quotation', 'display_name' => 'Issue/print quotations'],
            ['name' => 'cancel_quotations', 'group' => 'quotation', 'display_name' => 'Cancel quotations'],
            ['name' => 'redeem_quotations', 'group' => 'quotation', 'display_name' => 'Load quotation into create sale'],
            ['name' => 'print_quotations', 'group' => 'quotation', 'display_name' => 'Print quotations'],
            ['name' => 'quotation_price_override', 'group' => 'quotation', 'display_name' => 'Use price override on quotations'],
            ['name' => 'quotation_discount', 'group' => 'quotation', 'display_name' => 'Apply discounts on quotations'],

            /*
   |--------------------------------------------------------------------------
   | Manufacturing Dashboard / Reports
   |--------------------------------------------------------------------------
   */
            ['name' => 'view_manufacturing', 'group' => 'manufacturing', 'display_name' => 'View manufacturing module'],
            ['name' => 'view_manufacturing_dashboard', 'group' => 'manufacturing', 'display_name' => 'View manufacturing dashboard'],
            ['name' => 'view_manufacturing_reports', 'group' => 'manufacturing', 'display_name' => 'View manufacturing reports'],
            ['name' => 'export_manufacturing_reports', 'group' => 'manufacturing', 'display_name' => 'Export manufacturing reports'],

            /*
             * Live manufacturing controllers use these operation-level names.
             * Keep them in the canonical catalogue so an assigned permission
             * can be projected to edge installations instead of failing closed
             * merely because its lookup row was never seeded.
             */
            ['name' => 'manufacturing_view_recipes', 'group' => 'manufacturing', 'display_name' => 'View manufacturing recipes'],
            ['name' => 'manufacturing_manage_recipes', 'group' => 'manufacturing', 'display_name' => 'Manage manufacturing recipes'],
            ['name' => 'manufacturing_view_reports', 'group' => 'manufacturing', 'display_name' => 'View manufacturing reports'],
            ['name' => 'manufacturing_create_plan', 'group' => 'manufacturing', 'display_name' => 'Create manufacturing production plans'],
            ['name' => 'manufacturing_approve_plan', 'group' => 'manufacturing', 'display_name' => 'Approve manufacturing production plans'],
            ['name' => 'manufacturing_create_order', 'group' => 'manufacturing', 'display_name' => 'Create manufacturing production orders'],
            ['name' => 'manufacturing_approve_order', 'group' => 'manufacturing', 'display_name' => 'Approve manufacturing production orders'],
            ['name' => 'manufacturing_issue_materials', 'group' => 'manufacturing', 'display_name' => 'Issue manufacturing materials'],
            ['name' => 'manufacturing_complete_order', 'group' => 'manufacturing', 'display_name' => 'Complete manufacturing production orders'],
            ['name' => 'manufacturing_cancel_order', 'group' => 'manufacturing', 'display_name' => 'Cancel manufacturing production orders'],
            ['name' => 'manufacturing_add_overheads', 'group' => 'manufacturing', 'display_name' => 'Add manufacturing production overheads'],

            /*
            |--------------------------------------------------------------------------
            | Production Recipes / BOM
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_recipes', 'group' => 'manufacturing', 'display_name' => 'View production recipes and bill of materials'],
            ['name' => 'create_production_recipes', 'group' => 'manufacturing', 'display_name' => 'Create production recipes and bill of materials'],
            ['name' => 'update_production_recipes', 'group' => 'manufacturing', 'display_name' => 'Update production recipes and bill of materials'],
            ['name' => 'delete_production_recipes', 'group' => 'manufacturing', 'display_name' => 'Delete production recipes and bill of materials'],
            ['name' => 'approve_production_recipes', 'group' => 'manufacturing', 'display_name' => 'Approve production recipes and bill of materials'],

            /*
            |--------------------------------------------------------------------------
            | Production Planning
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_plans', 'group' => 'manufacturing', 'display_name' => 'View production plans'],
            ['name' => 'create_production_plans', 'group' => 'manufacturing', 'display_name' => 'Create production plans'],
            ['name' => 'update_production_plans', 'group' => 'manufacturing', 'display_name' => 'Update production plans'],
            ['name' => 'delete_production_plans', 'group' => 'manufacturing', 'display_name' => 'Delete production plans'],
            ['name' => 'approve_production_plans', 'group' => 'manufacturing', 'display_name' => 'Approve production plans'],
            ['name' => 'cancel_production_plans', 'group' => 'manufacturing', 'display_name' => 'Cancel production plans'],
            ['name' => 'generate_material_requirements', 'group' => 'manufacturing', 'display_name' => 'Generate material requirements from production plans'],

            /*
            |--------------------------------------------------------------------------
            | Material Requirements / Raw Materials
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_material_requirements', 'group' => 'manufacturing', 'display_name' => 'View material requirements'],
            ['name' => 'reserve_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Reserve raw materials for production'],
            ['name' => 'release_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Release reserved raw materials'],
            ['name' => 'issue_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Issue raw materials to production'],
            ['name' => 'return_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Return unused raw materials from production'],

            /*
            |--------------------------------------------------------------------------
            | Production Orders
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_orders', 'group' => 'manufacturing', 'display_name' => 'View production orders'],
            ['name' => 'create_production_orders', 'group' => 'manufacturing', 'display_name' => 'Create production orders'],
            ['name' => 'update_production_orders', 'group' => 'manufacturing', 'display_name' => 'Update production orders'],
            ['name' => 'approve_production_orders', 'group' => 'manufacturing', 'display_name' => 'Approve production orders'],
            ['name' => 'start_production_orders', 'group' => 'manufacturing', 'display_name' => 'Start production orders'],
            ['name' => 'complete_production_orders', 'group' => 'manufacturing', 'display_name' => 'Complete production orders'],
            ['name' => 'cancel_production_orders', 'group' => 'manufacturing', 'display_name' => 'Cancel production orders'],
            ['name' => 'close_production_orders', 'group' => 'manufacturing', 'display_name' => 'Close production orders'],

            /*
            |--------------------------------------------------------------------------
            | Production Inputs / Outputs
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_inputs', 'group' => 'manufacturing', 'display_name' => 'View production material inputs'],
            ['name' => 'add_production_inputs', 'group' => 'manufacturing', 'display_name' => 'Add production material inputs'],
            ['name' => 'update_production_inputs', 'group' => 'manufacturing', 'display_name' => 'Update production material inputs'],
            ['name' => 'delete_production_inputs', 'group' => 'manufacturing', 'display_name' => 'Delete production material inputs'],

            ['name' => 'view_production_outputs', 'group' => 'manufacturing', 'display_name' => 'View production outputs'],
            ['name' => 'add_production_outputs', 'group' => 'manufacturing', 'display_name' => 'Add finished goods from production'],
            ['name' => 'update_production_outputs', 'group' => 'manufacturing', 'display_name' => 'Update production outputs'],
            ['name' => 'delete_production_outputs', 'group' => 'manufacturing', 'display_name' => 'Delete production outputs'],

            /*
            |--------------------------------------------------------------------------
            | Production Costing / Expenses
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_costing', 'group' => 'manufacturing', 'display_name' => 'View production costing'],
            ['name' => 'calculate_production_costing', 'group' => 'manufacturing', 'display_name' => 'Calculate production costing'],
            ['name' => 'add_production_overheads', 'group' => 'manufacturing', 'display_name' => 'Add production overheads and expenses'],
            ['name' => 'update_production_overheads', 'group' => 'manufacturing', 'display_name' => 'Update production overheads and expenses'],
            ['name' => 'delete_production_overheads', 'group' => 'manufacturing', 'display_name' => 'Delete production overheads and expenses'],
            ['name' => 'allocate_production_overheads', 'group' => 'manufacturing', 'display_name' => 'Allocate production overheads to finished goods'],

            /*
            |--------------------------------------------------------------------------
            | Labour / Salaries Allocation
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_labour', 'group' => 'manufacturing', 'display_name' => 'View production labour costs'],
            ['name' => 'add_production_labour', 'group' => 'manufacturing', 'display_name' => 'Add labour and salary costs to production'],
            ['name' => 'update_production_labour', 'group' => 'manufacturing', 'display_name' => 'Update labour and salary costs'],
            ['name' => 'delete_production_labour', 'group' => 'manufacturing', 'display_name' => 'Delete labour and salary costs'],

            /*
            |--------------------------------------------------------------------------
            | Wastage / Scrap / By-products
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_production_wastage', 'group' => 'manufacturing', 'display_name' => 'View production wastage and scrap'],
            ['name' => 'record_production_wastage', 'group' => 'manufacturing', 'display_name' => 'Record production wastage and scrap'],
            ['name' => 'approve_production_wastage', 'group' => 'manufacturing', 'display_name' => 'Approve production wastage and scrap'],
            ['name' => 'reverse_production_wastage', 'group' => 'manufacturing', 'display_name' => 'Reverse production wastage and scrap'],

            /*
            |--------------------------------------------------------------------------
            | Stock Posting / Inventory Integration
            |--------------------------------------------------------------------------
            */
            ['name' => 'post_production_stock', 'group' => 'manufacturing', 'display_name' => 'Post production stock movements'],
            ['name' => 'reverse_production_stock', 'group' => 'manufacturing', 'display_name' => 'Reverse production stock postings'],
            ['name' => 'view_production_stock_movements', 'group' => 'manufacturing', 'display_name' => 'View production stock movements'],

            /*
            |--------------------------------------------------------------------------
            | Audit / Administration
            |--------------------------------------------------------------------------
            */
            ['name' => 'view_manufacturing_audit', 'group' => 'manufacturing', 'display_name' => 'View manufacturing audit trail'],
            ['name' => 'manage_manufacturing_settings', 'group' => 'manufacturing', 'display_name' => 'Manage manufacturing settings'],

            ['name' => 'view_manufacturing_dashboard', 'group' => 'manufacturing', 'display_name' => 'View manufacturing dashboard'],

            ['name' => 'view_manufacturing_recipes', 'group' => 'manufacturing', 'display_name' => 'View manufacturing recipes'],
            ['name' => 'create_manufacturing_recipes', 'group' => 'manufacturing', 'display_name' => 'Create manufacturing recipes'],
            ['name' => 'update_manufacturing_recipes', 'group' => 'manufacturing', 'display_name' => 'Update manufacturing recipes'],
            ['name' => 'delete_manufacturing_recipes', 'group' => 'manufacturing', 'display_name' => 'Delete manufacturing recipes'],
            ['name' => 'approve_manufacturing_recipes', 'group' => 'manufacturing', 'display_name' => 'Approve manufacturing recipes'],

            ['name' => 'view_manufacturing_units', 'group' => 'manufacturing', 'display_name' => 'View manufacturing production units'],
            ['name' => 'view_work_centers', 'group' => 'manufacturing', 'display_name' => 'View manufacturing work centers'],

            ['name' => 'view_production_plans', 'group' => 'manufacturing', 'display_name' => 'View production plans'],
            ['name' => 'create_production_plans', 'group' => 'manufacturing', 'display_name' => 'Create production plans'],
            ['name' => 'update_production_plans', 'group' => 'manufacturing', 'display_name' => 'Update production plans'],
            ['name' => 'approve_production_plans', 'group' => 'manufacturing', 'display_name' => 'Approve production plans'],
            ['name' => 'cancel_production_plans', 'group' => 'manufacturing', 'display_name' => 'Cancel production plans'],

            ['name' => 'view_material_requirements', 'group' => 'manufacturing', 'display_name' => 'View material requirements'],
            ['name' => 'reserve_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Reserve raw materials'],
            ['name' => 'issue_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Issue raw materials'],
            ['name' => 'return_raw_materials', 'group' => 'manufacturing', 'display_name' => 'Return raw materials'],

            ['name' => 'view_production_orders', 'group' => 'manufacturing', 'display_name' => 'View production orders'],
            ['name' => 'create_production_orders', 'group' => 'manufacturing', 'display_name' => 'Create production orders'],
            ['name' => 'update_production_orders', 'group' => 'manufacturing', 'display_name' => 'Update production orders'],
            ['name' => 'approve_production_orders', 'group' => 'manufacturing', 'display_name' => 'Approve production orders'],
            ['name' => 'start_production_orders', 'group' => 'manufacturing', 'display_name' => 'Start production orders'],
            ['name' => 'complete_production_orders', 'group' => 'manufacturing', 'display_name' => 'Complete production orders'],
            ['name' => 'close_production_orders', 'group' => 'manufacturing', 'display_name' => 'Close production orders'],
            ['name' => 'cancel_production_orders', 'group' => 'manufacturing', 'display_name' => 'Cancel production orders'],

            ['name' => 'view_production_costing', 'group' => 'manufacturing', 'display_name' => 'View production costing'],
            ['name' => 'view_material_usage', 'group' => 'manufacturing', 'display_name' => 'View material usage'],
            ['name' => 'view_yield_analysis', 'group' => 'manufacturing', 'display_name' => 'View yield analysis'],

            ['name' => 'record_production_wastage', 'group' => 'manufacturing', 'display_name' => 'Record production wastage'],
            ['name' => 'view_production_wastage', 'group' => 'manufacturing', 'display_name' => 'View production wastage'],
            ['name' => 'approve_production_wastage', 'group' => 'manufacturing', 'display_name' => 'Approve production wastage'],
            ['name' => 'reverse_production_wastage', 'group' => 'manufacturing', 'display_name' => 'Reverse production wastage'],

            ['name' => 'view_manufacturing_reports', 'group' => 'manufacturing', 'display_name' => 'View manufacturing reports'],
            ['name' => 'view_production_cost_reports', 'group' => 'manufacturing', 'display_name' => 'View production cost reports'],
            ['name' => 'view_material_usage_reports', 'group' => 'manufacturing', 'display_name' => 'View material usage reports'],
            ['name' => 'view_wastage_reports', 'group' => 'manufacturing', 'display_name' => 'View wastage reports'],
            ['name' => 'view_manufacturing_profitability', 'group' => 'manufacturing', 'display_name' => 'View manufacturing profitability'],

            ['name' => 'manage_manufacturing_settings', 'group' => 'manufacturing', 'display_name' => 'Manage manufacturing settings'],
            ['name' => 'view_manufacturing_audit_trail', 'group' => 'manufacturing', 'display_name' => 'View manufacturing audit trail'],

            [
                'name' => 'view_credit_customers',
                'group' => 'Sales',
                'display_name' => 'View credit customers',
            ],
            [
                'name' => 'manage_credit_customers',
                'group' => 'Sales',
                'display_name' => 'Manage credit customers',
            ],
            [
                'name' => 'approve_credit_customers',
                'group' => 'Sales',
                'display_name' => 'Approve credit customers',
            ],
            [
                'name' => 'view_credit_customer_statement',
                'group' => 'Sales',
                'display_name' => 'View credit customer statement',
            ],
            [
                'name' => 'capture_credit_customer_payment',
                'group' => 'Sales',
                'display_name' => 'Capture credit customer payment',
            ],
            [
                'name' => 'reverse_credit_customer_payment',
                'group' => 'Sales',
                'display_name' => 'Reverse credit customer payment',
            ],
            [
                'name' => 'credit_customer_reports',
                'group' => 'Sales',
                'display_name' => 'Credit customer reports',
            ],
            [
                'name' => 'sell_on_credit',
                'group' => 'Sales',
                'display_name' => 'Sell on credit',
            ],    [
                'name' => 'boh_issue_stock',
                'group' => 'Stock',
                'display_name' => 'Boh Issue Stock',
            ],

        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'group' => Str::slug($permission['group']),
                    'description' => $permission['display_name'],
                ]
            );
        }
    }
}
