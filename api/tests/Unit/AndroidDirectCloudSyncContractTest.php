<?php

namespace Tests\Unit;

use App\Http\Middleware\BlockPosWhenDayEndRunning;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveMobileSaleSnapshot;
use App\Http\Middleware\ResolveTillFromToken;
use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

class AndroidDirectCloudSyncContractTest extends TestCase
{
    public function test_android_pairing_and_signed_presence_routes_are_exposed(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->keyBy(fn ($route): string => implode(',', $route->methods()).' '.$route->uri());

        $this->assertTrue($routes->has('POST api/pos/sync/pair'));
        $this->assertTrue($routes->has('POST api/pos/sync/heartbeat'));
        $this->assertTrue($routes->has('GET,HEAD api/pos/sync/pull'));
        $this->assertTrue($routes->has('GET,HEAD api/pos/sync/license'));
        $this->assertContains(
            'sync.node',
            $routes->get('POST api/pos/sync/heartbeat')->gatherMiddleware()
        );
        $this->assertContains(
            'sync.node',
            $routes->get('GET,HEAD api/pos/sync/pull')->gatherMiddleware()
        );
        $licenseMiddleware = $routes->get('GET,HEAD api/pos/sync/license')->gatherMiddleware();
        $this->assertContains('sync.node', $licenseMiddleware);
        $this->assertNotContains('auth:sanctum', $licenseMiddleware);
        $this->assertNotContains('check.license', $licenseMiddleware);
    }

    public function test_android_sale_requires_both_cashier_and_node_authentication(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route): bool => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/pos/mobile/sales');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('sync.node', $middleware);
        $this->assertContains(ResolveCompanyFromDomain::class, $middleware);
        $this->assertContains(ResolveTillFromToken::class, $middleware);
        $this->assertContains(ResolveMobileSaleSnapshot::class, $middleware);
        $this->assertContains(BlockPosWhenDayEndRunning::class, $middleware);
    }

    public function test_android_tills_cannot_fall_back_to_unsigned_sale_routes_or_client_adjustments(): void
    {
        $controller = file_get_contents(base_path('Modules/Sales/Http/Controllers/SalesController.php'));

        $this->assertStringContainsString("'code' => 'SIGNED_MOBILE_ROUTE_REQUIRED'", $controller);
        $this->assertStringContainsString('if ($isAndroidTill && ! $mobileSaleSnapshot)', $controller);
        $this->assertStringContainsString("'code' => 'MOBILE_SALE_ADJUSTMENT_UNSUPPORTED'", $controller);
        $this->assertStringContainsString("'discount_payload'", $controller);
        $this->assertStringContainsString("'price_level_override'", $controller);
        $this->assertStringContainsString(
            "\$authenticatedSyncNode = \$request->attributes->get('sync_node')",
            $controller
        );
        $this->assertMatchesRegularExpression(
            '/whereKey\(\$authenticatedSyncNode->id\).*?whereNotNull\(\'activated_at\'\).*?lockForUpdate\(\)/s',
            $controller
        );
    }

    public function test_snapshot_pruning_preserves_the_node_acknowledged_sale_contract(): void
    {
        $configuration = file_get_contents(app_path('Services/Sync/SyncConfigurationService.php'));
        $resolver = file_get_contents(app_path('Services/Sync/MobileSaleSnapshotService.php'));

        $this->assertStringContainsString("'acknowledged_content_hash'", $configuration);
        $this->assertStringContainsString('$keep->push($acknowledgedSnapshot->id)', $configuration);
        $this->assertStringContainsString("'MOBILE_CONFIGURATION_NOT_ACKNOWLEDGED'", $resolver);
        $this->assertStringContainsString("'MOBILE_CONFIGURATION_INTEGRITY_FAILED'", $resolver);
        $this->assertStringContainsString('$this->hasher->hash($datasets)', $resolver);
        $this->assertStringContainsString("\$result['mobile_context']", $configuration);
        $this->assertStringContainsString("'effective_window_hash'", $configuration);
        $this->assertStringContainsString(
            "\$schemaVersion = \$node->deployment_mode === 'android'",
            $configuration
        );
        $this->assertStringContainsString(
            ": (int) config('servnest.configuration_schema_version', 2)",
            $configuration
        );
        $this->assertStringContainsString(
            "data_get(\$latest->payload, 'schema_version', 0) === \$schemaVersion",
            $configuration
        );
        $this->assertStringContainsString("'MOBILE_CONFIGURATION_RESYNC_REQUIRED'", $resolver);
        $this->assertStringContainsString("(\$payload['schema_version'] ?? 0) !== 3", $resolver);
        $this->assertStringContainsString("'license_entitlement' => \$delivery['license_entitlement']", $configuration);
    }

    public function test_mobile_snapshot_allowlist_excludes_credentials_and_administration_data(): void
    {
        $reflection = new ReflectionClass(SyncConfigurationService::class);
        $datasets = $reflection->getReflectionConstant('ANDROID_DATASETS')->getValue();

        foreach ([
            'companies', 'company_branches', 'pos_tills', 'products', 'product_prices',
            'product_stocks', 'currencies', 'currency_rates', 'payment_types', 'taxes',
            'product_taxes', 'general_settings', 'shiftconfigurations', 'trading_days',
            'printers', 'hospitality_output_configurations',
        ] as $required) {
            $this->assertContains($required, $datasets);
        }

        foreach ([
            'licenses', 'pos_users', 'user_branches', 'roles', 'permissions',
            'role_permissions', 'qz_certificates',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $datasets);
        }
    }

    public function test_android_snapshots_have_a_node_private_stream(): void
    {
        $service = app(SyncConfigurationService::class);
        $android = new SyncNode([
            'deployment_mode' => 'android',
        ]);
        $android->setAttribute('id', '019f9b00-0000-7000-8000-000000000001');
        $edge = new SyncNode(['deployment_mode' => 'edge']);

        $this->assertSame(
            'configuration.snapshot.android.019f9b00-0000-7000-8000-000000000001',
            $service->snapshotResourceType($android)
        );
        $this->assertSame('configuration.snapshot', $service->snapshotResourceType($edge));

        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $this->assertStringContainsString("if (\$node->deployment_mode === 'android')", $protocol);
        $this->assertStringContainsString("\$changesQuery->where('resource_type', \$snapshotType)", $protocol);
    }

    public function test_pairing_contract_requires_till_bound_android_identity_and_returns_one_time_till_token(): void
    {
        $management = file_get_contents(app_path('Http/Controllers/SyncNodeManagementController.php'));
        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));

        $this->assertStringContainsString("'target_platform' => ['nullable', Rule::in(['edge', 'android'])]", $management);
        $this->assertStringContainsString("'code' => 'ANDROID_TILL_REQUIRED'", $management);
        $this->assertStringContainsString("'code' => 'TERMINAL_NAME_ALREADY_USED'", $management);
        $this->assertStringContainsString("'code' => 'ANDROID_TILL_ALREADY_PAIRED'", $management);

        $this->assertStringContainsString("'device_fingerprint' =>", $protocol);
        $this->assertStringContainsString("'device_fingerprint_hash' =>", $protocol);
        $this->assertStringContainsString("'till_token' => \$tillToken", $protocol);
        $this->assertStringContainsString("'license_entitlement' => \$licenseDelivery['license_entitlement'] ?? null", $protocol);
        $this->assertStringContainsString("'supported_license_key_ids' =>", $protocol);
        $this->assertStringContainsString("'supported_license_algorithms' =>", $protocol);
        $this->assertStringContainsString("'supported_license_key_fingerprints' =>", $protocol);
        $this->assertStringContainsString("->header('Cache-Control', 'no-store')", $protocol);
        $this->assertStringContainsString("'code' => 'SYNC_PLATFORM_MISMATCH'", $protocol);
        $this->assertStringContainsString("'code' => 'SYNC_ANDROID_REST_REQUIRED'", $protocol);
        $this->assertStringContainsString("'code' => 'SYNC_EDGE_NODE_REQUIRED'", $protocol);
        $this->assertStringContainsString(
            "->where('resource_type', \$this->configuration->snapshotResourceType(\$node))",
            $protocol
        );
    }

    public function test_pairing_serializes_on_stable_branch_and_till_rows_before_duplicate_checks_and_insert(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SyncNodeManagementController.php'));
        $entry = explode('private function createPairingCodeLocked(',
            explode('public function createPairingCode(', $source, 2)[1], 2)[0];
        $body = explode('public function createTerminalActivationCode(',
            explode('private function createPairingCodeLocked(', $source, 2)[1], 2)[0];

        $this->assertStringContainsString('return DB::transaction(', $entry);
        $this->assertStringContainsString('$this->createPairingCodeLocked($request, $company, $validated)', $entry);
        $this->assertMatchesRegularExpression(
            '/CompanyBranch::query\(\).*?->lockForUpdate\(\).*?->find\(\$validated\[\'branch_id\'\]\)/s',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/PosTill::query\(\).*?->lockForUpdate\(\).*?->find\(\$validated\[\'till_id\'\]\)/s',
            $body
        );

        $branchLock = strpos($body, 'CompanyBranch::query()');
        $tillLock = strpos($body, 'PosTill::query()');
        $nameCheck = strpos($body, '$duplicateName = SyncNode::query()');
        $tillCheck = strpos($body, '$duplicateTill = SyncNode::query()');
        $insert = strpos($body, '$node->forceFill($nodeValues)->save()');

        foreach ([$branchLock, $tillLock, $nameCheck, $tillCheck, $insert] as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertTrue($branchLock < $tillLock && $tillLock < $nameCheck);
        $this->assertTrue($nameCheck < $tillCheck && $tillCheck < $insert);
    }

    public function test_cloud_front_office_exception_is_limited_to_an_active_android_node(): void
    {
        $login = file_get_contents(base_path('Modules/Authentication/Http/Controllers/PosUserController.php'));
        $tenant = file_get_contents(app_path('Http/Middleware/ResolveCompanyFromDomain.php'));

        foreach ([$login, $tenant] as $source) {
            $this->assertStringContainsString("->where('deployment_mode', 'android')", $source);
            $this->assertStringContainsString("->where('status', 'active')", $source);
            $this->assertStringContainsString("->whereNull('revoked_at')", $source);
            $this->assertStringContainsString("->where('till_id', \$resolvedTill->id)", $source);
        }

        $this->assertStringContainsString("'server_time' => now()->toIso8601String()", $login);
        $this->assertStringContainsString("'shift_sales_cutoff_at' =>", $login);
        $this->assertStringContainsString("'shift_status' => \$activeShiftPayload ? 'open' : 'not_open'", $login);
    }

    public function test_presence_is_reported_for_each_android_and_web_terminal_without_driving_sync(): void
    {
        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $management = file_get_contents(app_path('Http/Controllers/SyncNodeManagementController.php'));
        $transport = file_get_contents(app_path('Services/Sync/SyncTransportService.php'));

        $this->assertStringContainsString("'terminals.*.till_id' => ['required', 'uuid', 'distinct']", $protocol);
        $this->assertStringContainsString("->where('company_id', \$node->company_id)", $protocol);
        $this->assertStringContainsString("->where('branch_id', \$node->branch_id)", $protocol);
        $this->assertStringContainsString("'code' => 'SYNC_TERMINAL_SCOPE_INVALID'", $protocol);

        $this->assertStringContainsString('private function reportWebTerminalPresence(', $transport);
        $this->assertStringContainsString("'/api/pos/sync/heartbeat'", $transport);
        $this->assertStringContainsString('catch (Throwable)', $transport);

        $this->assertStringContainsString("'terminals' => \$terminals", $management);
        $this->assertStringContainsString("'client_platform'", $management);
        $this->assertStringContainsString("'is_online'", $management);
        $this->assertStringContainsString("'presence_stale_seconds'", $management);
    }

    public function test_android_heartbeat_reports_till_scoped_cashier_shift_control(): void
    {
        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $heartbeat = explode('public function dayEnd(', explode('public function heartbeat(', $protocol, 2)[1], 2)[0];

        $this->assertStringContainsString("'cashier_id' => ['nullable', 'uuid']", $heartbeat);
        $this->assertStringContainsString("->where('company_id', \$node->company_id)", $heartbeat);
        $this->assertStringContainsString("->where('branch_id', \$node->branch_id)", $heartbeat);
        $this->assertStringContainsString("->where('till_id', \$node->till_id)", $heartbeat);
        $this->assertStringContainsString("->where('pos_user_id', \$cashier->id)", $heartbeat);
        $this->assertStringContainsString("'shift_status' => \$shiftStatus", $heartbeat);
        $this->assertStringContainsString("'shift_sales_cutoff_at' => \$shiftSalesCutoffAt", $heartbeat);
        $this->assertStringContainsString("'shift_sales_block_reason' => \$shiftSalesBlockReason", $heartbeat);
        $this->assertStringContainsString("\$shiftStatus = 'not_open'", $heartbeat);
        $this->assertStringContainsString("\$shiftStatus = 'open'", $heartbeat);
    }

    public function test_signed_mobile_sale_uses_occurrence_time_and_does_not_deadlock_after_commit(): void
    {
        $controller = file_get_contents(base_path('Modules/Sales/Http/Controllers/SalesController.php'));

        $this->assertStringContainsString(
            "'occurred_at' => [\$mobileSaleSnapshot ? 'required' : 'nullable', 'date']",
            $controller
        );
        $this->assertStringContainsString("'code' => 'MOBILE_SALE_OCCURRED_AT_INVALID'", $controller);
        $this->assertStringContainsString("'transaction_date' => \$mobileOccurredAt ?? now()", $controller);
        $this->assertStringContainsString("'code' => 'MOBILE_SALE_ACCEPTED_AFTER_SHIFT_CUTOFF'", $controller);
        $this->assertStringContainsString("'shift_policy_warning' => \$shiftPolicyWarning", $controller);
        $this->assertStringContainsString("'code' => 'SALE_SAVED_FISCALISATION_PENDING'", $controller);
        $this->assertStringContainsString('return response()->json($payload, 202)', $controller);
    }
}
