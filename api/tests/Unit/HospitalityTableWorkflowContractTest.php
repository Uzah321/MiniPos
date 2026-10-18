<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HospitalityTableWorkflowContractTest extends TestCase
{
    public function test_schema_has_mysql_safe_tenant_race_and_idempotency_guards(): void
    {
        $migration = $this->source('Modules/Sales/database/migrations/2026_10_13_220000_create_hospitality_table_service.php');

        $this->assertStringContainsString("->unique('hospitality_active_table_uq')", $migration);
        $this->assertStringContainsString(
            "\$table->unique(['company_id', 'client_request_id'], 'hospitality_table_order_request_uq')",
            $migration
        );
        $this->assertStringContainsString(
            "\$table->unique(['table_order_id', 'command_id'], 'hospitality_table_order_command_uq')",
            $migration
        );
        $this->assertStringContainsString(
            "\$table->unique(['company_id', 'shop_id'], 'hospitality_table_settings_scope_uq')",
            $migration
        );
        $this->assertStringContainsString("->on('hospitality_tables')->restrictOnDelete()", $migration);
        $this->assertStringContainsString("->on('sales_transactions')->nullOnDelete()", $migration);
        $this->assertStringContainsString("->on('menu_item_channels')->nullOnDelete()", $migration);
        $this->assertStringNotContainsString("foreign('product_price_id')", $migration);
        $this->assertStringNotContainsString('enum(', strtolower($migration));
    }

    public function test_first_persisted_dine_in_line_allocates_a_table_but_takeaway_never_does(): void
    {
        $controller = $this->source('Modules/Sales/Http/Controllers/HospitalityTableOrderController.php');
        $service = $this->source('Modules/Sales/Services/HospitalityTableService.php');

        $this->assertStringContainsString("'items' => ['required', 'array', 'min:1', 'max:500']", $controller);
        $this->assertStringContainsString("\$usesTable = \$serviceType === 'dine_in'", $service);
        $this->assertStringContainsString("'takeaway_uses_tables' => false", $service);
        $this->assertStringContainsString("if (! \$usesTable) {\n            return null;", $service);
        $this->assertStringContainsString("if (\$mode === 'manual')", $service);
        $this->assertStringContainsString("->where('capacity', '>=', max(1, \$guestCount))", $service);
        $this->assertStringContainsString('No available table can be assigned automatically.', $service);
        $this->assertStringNotContainsString('retail', strtolower($service));
    }

    public function test_open_and_hanging_orders_are_scoped_versioned_and_recallable(): void
    {
        $controller = $this->source('Modules/Sales/Http/Controllers/HospitalityTableOrderController.php');
        $service = $this->source('Modules/Sales/Services/HospitalityTableService.php');

        foreach ([
            "->where('company_id', \$companyId)",
            "->where('branch_id', \$till->branch_id)",
            "->where('shop_id', \$shop->id)",
            'lockForUpdate()',
            "['open', 'hanging']",
            "'content_revision' => \$order->content_revision + 1",
            'assertRevision($order, $expectedRevision)',
            'commandReplay($order, $commandId, $eventType)',
        ] as $guard) {
            $this->assertStringContainsString($guard, $service);
        }

        $this->assertStringContainsString("'active_table_id' => null", $service);
        $this->assertStringContainsString(
            'The table order changed on another terminal. Reload it before continuing.',
            $service
        );
        $this->assertStringContainsString("'data' => ['current_revision' => \$exception->currentRevision]", $controller);
        $this->assertStringContainsString('], 409);', $controller);
    }

    public function test_kitchen_send_is_pre_payment_durable_and_idempotent_by_content_revision(): void
    {
        $service = $this->source('Modules/Sales/Services/HospitalityTableService.php');
        $dispatch = $this->source('Modules/Sales/Services/KitchenPrintDispatchService.php');
        $controller = $this->source('Modules/Sales/Http/Controllers/HospitalityTableOrderController.php');

        $this->assertStringContainsString('public function sendToKitchen(', $service);
        $this->assertStringContainsString('$this->kitchenPrints->enqueueTableOrder(', $service);
        $this->assertStringContainsString("'last_kitchen_content_revision' => \$order->content_revision", $service);
        $this->assertStringContainsString("'event_type'", $this->source(
            'Modules/Sales/database/migrations/2026_10_13_220000_create_hospitality_table_service.php'
        ));

        $this->assertStringContainsString("'hospitality_table_order'", $dispatch);
        $this->assertStringContainsString('$contentRevision', $dispatch);
        $this->assertStringContainsString("->where('dispatch_key', \$dispatchKey)", $dispatch);
        $this->assertStringContainsString("'sales_transaction_id' => null", $dispatch);
        $this->assertStringContainsString("'kitchen_print' => \$result['dispatch']", $controller);
    }

    public function test_clear_requires_dynamic_permission_or_bound_single_use_manager_approval(): void
    {
        $service = $this->source('Modules/Sales/Services/HospitalityTableService.php');
        $managerApproval = $this->source(
            'Modules/Authentication/Http/Controllers/ManagerApprovalController.php'
        );

        $this->assertStringContainsString("\$this->authorization->can(\$user, 'clear_open_tables', \$scope)", $service);
        $this->assertStringContainsString("->where('auditable_type', 'hospitality_table_order')", $service);
        $this->assertStringContainsString("->where('auditable_id', \$order->id)", $service);
        $this->assertStringContainsString("->where('created_at', '>=', now()->subMinutes(10))", $service);
        $this->assertStringContainsString("where('manager_approval_id', \$approvalId)->exists()", $service);
        $this->assertStringContainsString("['requested_by'] ?? '') !== (string) \$actor->id", $service);
        $this->assertStringContainsString('if (! $approvalId)', $service);
        $this->assertStringContainsString(
            'Clearing an open table requires clear_open_tables permission or a current manager approval.',
            $service
        );

        $this->assertStringContainsString("'clear_open_table' => 'clear_open_tables'", $managerApproval);
        $this->assertStringContainsString('managerHasPermission(', $managerApproval);
        $this->assertStringContainsString("->where('id', \$request->reference_id)", $managerApproval);
        $this->assertStringContainsString('$this->authorization->can($manager, $permission, $scope)', $managerApproval);
    }

    public function test_checkout_reuses_the_saved_order_and_atomically_releases_the_table(): void
    {
        $sales = $this->source('Modules/Sales/Http/Controllers/SalesController.php');
        $service = $this->source('Modules/Sales/Services/HospitalityTableService.php');

        $this->assertStringContainsString("'hospitality_table_order_id'", $sales);
        $this->assertStringContainsString("'hospitality_table_order_revision'", $sales);
        $this->assertStringContainsString('$this->hospitalityTables->claimForCheckout(', $sales);
        $this->assertStringContainsString('$this->hospitalityTables->completeCheckout(', $sales);
        $this->assertStringContainsString("'status' => 'processing'", $service);
        $this->assertStringContainsString("'status' => 'completed'", $service);
        $this->assertStringContainsString("'active_table_id' => null", $service);
        $this->assertStringContainsString('assertCheckoutProductsMatch($order, $saleProducts)', $service);
        $this->assertStringContainsString("'menu_item_channel_id'", $service);
        $this->assertStringContainsString("'prep_message_ids'", $service);
        $this->assertStringContainsString("'modifiers'", $service);

        $transactionStart = strpos($sales, 'DB::beginTransaction();');
        $release = strpos($sales, '$this->hospitalityTables->completeCheckout(');
        $commit = strpos($sales, 'DB::commit();', $release === false ? 0 : $release);
        $this->assertIsInt($transactionStart);
        $this->assertIsInt($release);
        $this->assertIsInt($commit);
        $this->assertLessThan($release, $transactionStart);
        $this->assertLessThan($commit, $release);
    }

    public function test_terminal_bootstrap_and_sync_include_only_scoped_table_configuration(): void
    {
        $bootstrap = $this->source(
            'Modules/GeneralSettings/Http/Controllers/TerminalConfigurationBootstrapController.php'
        );
        $sync = $this->source('app/Services/Sync/SyncConfigurationService.php');

        $this->assertStringContainsString("'table_service' => \$tableService", $bootstrap);
        $this->assertStringContainsString("->where('company_id', \$shop->company_id)", $bootstrap);
        $this->assertStringContainsString("->where('branch_id', \$shop->branch_id)", $bootstrap);
        $this->assertStringContainsString("->where('shop_id', \$shop->id)", $bootstrap);
        $this->assertStringContainsString("'hospitality_table_settings'", $sync);
        $this->assertStringContainsString("'hospitality_tables'", $sync);
    }

    public function test_management_settings_require_an_explicit_shop_within_the_branch(): void
    {
        $controller = $this->source('Modules/Sales/Http/Controllers/HospitalityTableManagementController.php');
        $service = $this->source('Modules/Sales/Services/HospitalityTableService.php');
        $permissionMiddleware = $this->source('app/Http/Middleware/RequireCompanyPermission.php');

        $this->assertStringContainsString("'shop_id' => ['required', 'uuid']", $controller);
        $this->assertStringContainsString('shopForManagementScope($companyId, $branchId, $shopId)', $service);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $service);
        $this->assertStringContainsString('->whereKey($shopId)', $service);
        $this->assertStringContainsString("\$request->input('shop_id')", $permissionMiddleware);
        $this->assertStringContainsString('The authorization shop does not belong', $permissionMiddleware);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        $this->assertIsString($source, "Unable to read {$relativePath}");

        return $source;
    }
}
