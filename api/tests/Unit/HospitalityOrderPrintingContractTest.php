<?php

namespace Tests\Unit;

use App\Http\Middleware\ResolveTillFromToken;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Services\HospitalityOrderNumberService;
use Modules\Sales\Services\KitchenPrintDispatchService;
use ReflectionClass;
use Tests\TestCase;

class HospitalityOrderPrintingContractTest extends TestCase
{
    public function test_public_order_allocator_is_randomly_offset_and_serialized_per_branch_day(): void
    {
        $source = $this->source(HospitalityOrderNumberService::class);

        $this->assertStringContainsString('random_int($minimum, $maximum)', $source);
        $this->assertStringContainsString("'hospitality_order_start_minimum'", $source);
        $this->assertStringContainsString("'hospitality_order_start_maximum'", $source);
        $this->assertStringContainsString("->where('business_date', \$businessDate)", $source);
        $this->assertStringContainsString('insertOrIgnore', $source);
        $this->assertStringContainsString("'trading_day_id' => \$tradingDayId", $source);
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringNotContainsString("'last_number' => 0", $source);
    }

    public function test_pre_payment_dispatch_is_content_revision_idempotent(): void
    {
        $source = $this->source(KitchenPrintDispatchService::class);

        $this->assertStringContainsString(
            'public function enqueueTableOrder(',
            $source
        );
        foreach ([
            "'hospitality_table_order'",
            "'dispatch_revision' => \$contentRevision",
            "'dispatch_key' => \$dispatchKey",
            "'sales_transaction_id' => null",
            "'ticket_kind' => \$ticketKind",
            "'pending_configuration'",
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
        $this->assertStringContainsString("'pos_line.prep_messages'", $source);
        $this->assertStringContainsString("'pos_line.prep_note'", $source);
        $this->assertStringContainsString("'pos_line.modifiers'", $source);
        $this->assertStringContainsString('(string) $locked->shop_id !== (string) $shopId', $source);
        $this->assertStringNotContainsString(
            '(string) $locked->last_till_id !== (string) $till->id',
            $source
        );
    }

    public function test_qz_output_and_job_routes_are_till_bound(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->keyBy(fn ($route): string => implode(',', $route->methods()).' '.$route->uri());

        foreach ([
            'GET,HEAD api/pos/tills/current/kitchen-output',
            'PUT api/pos/tills/current/kitchen-output',
            'GET,HEAD api/pos/kitchen-print-jobs',
            'POST api/pos/kitchen-print-jobs/{id}/acknowledge',
            'POST api/pos/kitchen-print-jobs/{id}/retry',
        ] as $key) {
            $this->assertTrue($routes->has($key), "Missing route {$key}");
            $middleware = $routes->get($key)->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('workspace:front-office', $middleware);
            $this->assertContains(ResolveTillFromToken::class, $middleware);
        }

        $controller = file_get_contents(base_path(
            'Modules/Sales/Http/Controllers/KitchenPrintJobController.php'
        ));
        $this->assertStringContainsString('mirrorTableOrderKitchenStatus($job, $status)', $controller);
        $this->assertStringContainsString("->where('last_kitchen_content_revision', \$job->dispatch_revision)", $controller);
        $this->assertStringContainsString("mirrorTableOrderKitchenStatus(\$job, 'queued')", $controller);
        $this->assertStringContainsString("\$request->input('error_message') ?? \$request->input('error')", $controller);
    }

    public function test_kds_is_advertised_as_planned_but_cannot_be_selected(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/GeneralSettings/Http/Controllers/CurrentTerminalKitchenOutputController.php'
        ));
        $service = file_get_contents(base_path(
            'Modules/Sales/Services/HospitalityOutputConfigurationService.php'
        ));

        $this->assertStringContainsString("'code' => 'KDS_NOT_AVAILABLE'", $controller);
        $this->assertStringContainsString("'available' => false", $controller);
        $this->assertStringContainsString("'status' => 'planned'", $service);
        $this->assertStringContainsString("'transport' => 'qz'", $service);
    }

    public function test_printer_routing_syncs_without_qz_secrets_and_android_gets_sanitized_routes(): void
    {
        $reflection = new ReflectionClass(SyncConfigurationService::class);
        $datasets = $reflection->getReflectionConstant('DATASETS')->getValue();
        $android = $reflection->getReflectionConstant('ANDROID_DATASETS')->getValue();
        $androidColumns = $reflection->getReflectionConstant('ANDROID_COLUMN_OVERRIDES')->getValue();

        $this->assertArrayHasKey('printers', $datasets);
        $this->assertArrayHasKey('hospitality_output_configurations', $datasets);
        $this->assertArrayNotHasKey('qz_certificates', $datasets);
        $this->assertContains('printers', $android);
        $this->assertContains('hospitality_output_configurations', $android);
        $this->assertContains('printer_name', $androidColumns['printers']);
        $this->assertNotContains('ip_address', $androidColumns['printers']);
        $this->assertNotContains('port', $androidColumns['printers']);
        $this->assertNotContains('notes', $androidColumns['printers']);
    }

    public function test_mysql_upgrade_follows_table_order_schema_and_supports_both_job_sources(): void
    {
        $migration = base_path(
            'Modules/Sales/database/migrations/2026_10_13_222000_harden_hospitality_order_number_and_kitchen_output.php'
        );
        $source = file_get_contents($migration);

        $this->assertFileExists($migration);
        $this->assertStringContainsString("->uuid('sales_transaction_id')->nullable()->change()", $source);
        $this->assertStringContainsString("'hospitality_table_order_id'", $source);
        $this->assertStringContainsString("'kitchen_jobs_table_order_fk'", $source);
        $this->assertStringContainsString('->nullOnDelete()', $source);
        $this->assertStringContainsString("'dispatch_revision'", $source);
        $this->assertStringContainsString("'dispatch_key'", $source);
        $this->assertStringContainsString("'order_business_date'", $source);
        $this->assertStringContainsString('consolidateDailySequences()', $source);
        $this->assertStringContainsString(
            "['company_id', 'branch_id', 'business_date']",
            $source
        );
        $this->assertStringContainsString("'ff_order_seq_branch_day_unique'", $source);
        $this->assertStringContainsString('dropObsoleteTillDayUniqueIndexes()', $source);
    }

    private function source(string $class): string
    {
        return file_get_contents((new ReflectionClass($class))->getFileName());
    }
}
