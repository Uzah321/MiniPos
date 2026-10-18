<?php

namespace Tests\Unit;

use App\Models\SyncTerminalActivation;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Http\Request;
use Modules\GeneralSettings\Http\Controllers\TillController;
use ReflectionClass;
use Tests\TestCase;

class TerminalActivationContractTest extends TestCase
{
    public function test_activation_model_and_migration_keep_the_raw_code_out_of_storage(): void
    {
        $activation = new SyncTerminalActivation([
            'code_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame('sync_terminal_activations', $activation->getTable());
        $this->assertArrayNotHasKey('code_hash', $activation->toArray());
        $this->assertSame('datetime', $activation->getCasts()['expires_at']);
        $this->assertSame('datetime', $activation->getCasts()['consumed_at']);

        $migration = file_get_contents(base_path(
            'database/migrations/2026_10_06_100000_create_sync_terminal_activations_table.php'
        ));
        $this->assertStringContainsString("->char('code_hash', 64)", $migration);
        $this->assertStringContainsString("->timestamp('consumed_at')->nullable()", $migration);
        $this->assertStringContainsString("'sync_term_act_code_unique'", $migration);
        $this->assertStringNotContainsString('terminal_activation_code', $migration);
    }

    public function test_terminal_registration_credentials_are_not_in_configuration_snapshots(): void
    {
        $datasets = (new ReflectionClass(SyncConfigurationService::class))->getConstant('DATASETS');
        $columns = $datasets['pos_tills']['columns'];

        $this->assertArrayNotHasKey('sync_terminal_activations', $datasets);

        foreach ([
            'registration_token',
            'activation_code',
            'is_registered',
            'registered_by',
            'registered_at',
            'last_seen_at',
            'last_ip_address',
            'last_user_agent',
            'updated_at',
        ] as $localOnlyColumn) {
            $this->assertNotContains($localOnlyColumn, $columns);
        }
    }

    public function test_activation_routes_have_distinct_browser_node_and_admin_boundaries(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->keyBy(fn ($route) => implode(',', $route->methods()).' '.$route->uri());

        $redeem = $routes->get('POST api/pos/sync/terminal-activations/redeem');
        $consume = $routes->get('POST api/pos/sync/terminal-activations/consume');
        $tenantIssue = $routes->get('POST api/pos/sync/nodes/{node}/terminal-activation-codes');
        $platformIssue = $routes->get(
            'POST api/companies/{company}/sync/nodes/{node}/terminal-activation-codes'
        );

        $this->assertNotNull($redeem);
        $this->assertNotContains('auth:sanctum', $redeem->gatherMiddleware());
        $this->assertNotContains('sync.node', $redeem->gatherMiddleware());
        $this->assertContains('throttle:10,1,sync-terminal-redeem:', $redeem->gatherMiddleware());

        $this->assertNotNull($consume);
        $this->assertContains('sync.node', $consume->gatherMiddleware());

        $this->assertNotNull($tenantIssue);
        $this->assertContains('auth:sanctum', $tenantIssue->gatherMiddleware());
        $this->assertContains('workspace:back-office', $tenantIssue->gatherMiddleware());
        $this->assertContains(
            'company.permission:till_management,manage_license',
            $tenantIssue->gatherMiddleware()
        );

        $this->assertNotNull($platformIssue);
        $this->assertContains('platform.admin', $platformIssue->gatherMiddleware());
    }

    public function test_legacy_direct_activation_is_rejected_on_distributed_deployments(): void
    {
        $originalMode = config('salexa_sync.deployment_mode');
        $controller = app(TillController::class);

        try {
            config()->set('salexa_sync.deployment_mode', 'cloud');
            $cloud = $controller->activate(Request::create('/activate', 'POST'), 'terminal-id');
            $this->assertSame(409, $cloud->getStatusCode());
            $this->assertSame(
                'TERMINAL_ACTIVATION_CODE_REQUIRED',
                $cloud->getData(true)['code']
            );
            $this->assertStringContainsString(
                'Deployment & Sync',
                $cloud->getData(true)['message']
            );

            config()->set('salexa_sync.deployment_mode', 'edge');
            $edge = $controller->activate(Request::create('/activate', 'POST'), 'terminal-id');
            $this->assertSame(409, $edge->getStatusCode());
            $this->assertSame(
                'TERMINAL_ACTIVATION_REDEMPTION_REQUIRED',
                $edge->getData(true)['code']
            );
            $this->assertStringContainsString(
                'terminal activation code',
                $edge->getData(true)['message']
            );
        } finally {
            config()->set('salexa_sync.deployment_mode', $originalMode);
        }
    }
}
