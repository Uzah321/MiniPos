<?php

namespace Tests\Unit;

use App\Http\Controllers\EdgeSyncController;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncEventSetProof;
use App\Services\Sync\SyncPayloadHasher;
use App\Services\Sync\SyncRequestSigner;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class DistributedSyncContractTest extends TestCase
{
    public function test_payload_hash_is_stable_across_associative_key_order(): void
    {
        $hasher = new SyncPayloadHasher;

        $this->assertSame(
            $hasher->hash(['sale' => ['id' => 'a', 'total' => 2], 'lines' => [['id' => 'b']]]),
            $hasher->hash(['lines' => [['id' => 'b']], 'sale' => ['total' => 2, 'id' => 'a']])
        );
    }

    public function test_event_set_proof_is_constant_size_and_order_independent(): void
    {
        $proofs = new SyncEventSetProof;
        $events = [
            ['019f0000-0000-7000-8000-000000000001', str_repeat('a', 64)],
            ['019f0000-0000-7000-8000-000000000002', str_repeat('b', 64)],
        ];
        $left = $proofs->empty();
        $right = $proofs->empty();
        foreach ($events as [$id, $hash]) {
            $left = $proofs->add($left, $id, $hash);
        }
        foreach (array_reverse($events) as [$id, $hash]) {
            $right = $proofs->add($right, $id, $hash);
        }

        $this->assertSame(2, $left['count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $left['digest']);
        $this->assertSame($left, $right);
    }

    public function test_download_equality_requires_matching_cloud_head_and_acknowledgement_proofs(): void
    {
        $controller = new EdgeSyncController(new SyncEventSetProof);
        $method = new ReflectionMethod($controller, 'downloadProofMatches');
        $eventId = '019f0000-0000-7000-8000-000000000001';
        $payloadHash = str_repeat('a', 64);
        $contentHash = str_repeat('b', 64);
        $cloud = [
            'acknowledgement_confirmed' => true,
            'head_cursor' => 7,
            'acknowledged_cursor' => 7,
            'head_event_id' => $eventId,
            'acknowledged_event_id' => $eventId,
            'head_payload_hash' => $payloadHash,
            'acknowledged_payload_hash' => $payloadHash,
            'head_content_hash' => $contentHash,
            'acknowledged_content_hash' => $contentHash,
        ];
        $head = [
            'cursor' => 7,
            'event_id' => $eventId,
            'payload_hash' => $payloadHash,
            'content_hash' => $contentHash,
        ];
        $applied = $head;

        $this->assertTrue($method->invoke($controller, 7, $cloud, $head, $applied));
        $cloud['acknowledged_payload_hash'] = str_repeat('c', 64);
        $this->assertFalse($method->invoke($controller, 7, $cloud, $head, $applied));
        $cloud['acknowledged_payload_hash'] = $payloadHash;
        $cloud['acknowledgement_confirmed'] = false;
        $this->assertFalse($method->invoke($controller, 7, $cloud, $head, $applied));
    }

    public function test_node_signature_binds_method_target_node_nonce_and_body(): void
    {
        $signer = new SyncRequestSigner;
        $arguments = ['POST', '/api/pos/sync/push', '1791200000', 'nonce-1234567890ab', '{"events":[]}', 'secret', 'node-a'];
        $signature = $signer->signature(...$arguments);
        $canonical = $signer->canonicalRequest(
            $arguments[0],
            $arguments[1],
            $arguments[2],
            $arguments[3],
            $arguments[4],
            $arguments[6]
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
        $this->assertSame(hash_hmac('sha256', $canonical, 'secret'), $signature);
        $this->assertNotSame(
            hash_hmac('sha256', $canonical, hex2bin(hash('sha256', 'secret'))),
            $signature
        );
        $this->assertFalse(method_exists($signer, 'signatureFromCredentialHash'));
        $this->assertSame($signature, $signer->signature(...$arguments));
        $arguments[6] = 'node-b';
        $this->assertNotSame($signature, $signer->signature(...$arguments));
        $arguments[6] = 'node-a';
        $arguments[4] = '{"events":[1]}';
        $this->assertNotSame($signature, $signer->signature(...$arguments));
    }

    public function test_configuration_dependencies_precede_dependents_and_self_parents_are_two_pass(): void
    {
        $reflection = new ReflectionClass(SyncConfigurationService::class);
        $order = $reflection->getConstant('APPLY_ORDER');
        $foreign = $reflection->getConstant('FOREIGN_DATASETS');
        $selfParents = $reflection->getConstant('SELF_PARENT_COLUMNS');

        foreach ($foreign as $child => $references) {
            foreach (array_unique(array_values($references)) as $parent) {
                if ($parent === $child) {
                    $this->assertArrayHasKey($child, $selfParents);

                    continue;
                }
                $this->assertLessThan(
                    array_search($child, $order, true),
                    array_search($parent, $order, true),
                    "{$parent} must be applied before {$child}"
                );
            }
        }

        $source = file_get_contents(app_path('Services/Sync/SyncConfigurationService.php'));
        $this->assertStringContainsString('$initialRow[$parentColumn] = null', $source);
        $this->assertStringContainsString('guardLicenseRevision', $source);
        $datasets = $reflection->getConstant('DATASETS');
        foreach ([
            'credit_customers',
            'product_costs',
            'product_stocks',
            'inventory_batches',
            'inventory_location_balances',
            'trading_days',
        ] as $dataset) {
            $this->assertArrayHasKey($dataset, $datasets);
        }
        $this->assertSame('branch', $datasets['trading_days']['scope']);
        $this->assertContains('trading_date', $datasets['trading_days']['columns']);
        $this->assertContains('status', $datasets['trading_days']['columns']);
        $this->assertContains('trading_days', $order);
        $this->assertSame('branch-user-roles', $datasets['roles']['scope']);
        $this->assertSame('branch-user-shift-assignments', $datasets['pos_user_shift_assignments']['scope']);
        $this->assertSame('node-brand-prep-message-relations', $datasets['product_prep_message_assignments']['scope']);
        $this->assertSame('node-brand-legacy-modifier-relations', $datasets['product_modifier_assignments']['scope']);
        $this->assertSame('company-branch-product-relations', $datasets['product_prices']['scope']);
        $this->assertSame('company-branch-product-relations', $datasets['product_taxes']['scope']);
        $this->assertStringContainsString('preservePendingEdgeOperationalState', $source);
        $this->assertStringContainsString('alignOperationalProjectionIdentity', $source);
        $this->assertStringContainsString('alignPermissionIdentity', $source);
        $this->assertStringContainsString('isSensitiveSettingKey', $source);
        $this->assertStringContainsString('public function publishBranchSnapshot(', $source);
        $this->assertStringContainsString("config('salexa_sync.deployment_mode') !== 'cloud'", $source);
        $this->assertStringContainsString("->where('company_id', \$companyId)", $source);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $source);
        $this->assertStringContainsString("->whereIn('deployment_mode', ['edge', 'android'])", $source);
        $this->assertStringContainsString("->where('status', 'active')", $source);
        $this->assertStringContainsString("->whereNull('revoked_at')", $source);

        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $this->assertStringContainsString('$latestSnapshotId', $protocol);
        $this->assertStringContainsString("->orWhere('id', \$latestSnapshotId)", $protocol);
    }

    public function test_login_is_tenant_scoped_with_an_allowlisted_platform_owner_exception_and_front_office_is_locked_to_resolved_till(): void
    {
        $source = file_get_contents(base_path('Modules/Authentication/Http/Controllers/PosUserController.php'));

        $this->assertStringContainsString('private function loginCandidateQuery(', $source);
        $this->assertStringContainsString("\$query->where('company_id', \$company->id)", $source);
        $this->assertStringContainsString("\$query->orWhereIn('id', \$platformAdministratorIds)", $source);
        $this->assertStringContainsString("\$request->workspace === 'back-office'", $source);
        $this->assertStringContainsString(
            'if ($isCrossCompanyLogin && ($isFrontOffice || ! $isPlatformAdministrator))',
            $source
        );
        $this->assertStringContainsString("'code' => 'TILL_BRANCH_MISMATCH'", $source);
        $this->assertStringContainsString("'code' => 'DEPLOYMENT_WORKSPACE_DENIED'", $source);
        $this->assertStringContainsString("'code' => 'TILL_TOKEN_REQUIRED'", $source);
        $this->assertStringContainsString("Rule::unique('pos_users', 'username')->where('company_id', \$company->id)", $source);
    }

    public function test_resolver_fails_closed_for_bad_till_and_enforces_edge_identity(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/ResolveCompanyFromDomain.php'));

        $this->assertStringContainsString("'code' => 'INVALID_TILL_TOKEN'", $source);
        $this->assertStringContainsString("\$claims['edge_node']", $source);
        $this->assertStringNotContainsString("'code' => 'EDGE_TILL_CONTEXT_MISMATCH'", $source);
        $this->assertStringContainsString("'code' => 'EDGE_BRANCH_CONTEXT_MISMATCH'", $source);
        $this->assertStringContainsString("'code' => 'COMPANY_CONTEXT_MISMATCH'", $source);
        $this->assertStringContainsString('terminal activation is invalid or inactive', $source);
        $this->assertStringContainsString('allow_legacy_domain_resolution', $source);
        $this->assertStringContainsString('deploymentWorkspaceError', $source);
    }

    public function test_public_and_signed_sync_routes_are_registered_with_expected_boundaries(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->keyBy(fn ($route) => implode(',', $route->methods()).' '.$route->uri());

        $this->assertTrue($routes->has('POST api/pos/sync/pair'));
        $this->assertTrue($routes->has('GET,HEAD api/pos/sync/status'));
        $this->assertTrue($routes->has('POST api/pos/sync/run'));
        $this->assertContains('sync.node', $routes->get('POST api/pos/sync/push')->gatherMiddleware());
        $this->assertContains('sync.node', $routes->get('GET,HEAD api/pos/sync/pull')->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $routes->get('GET,HEAD api/pos/sync/status')->gatherMiddleware());
    }

    public function test_pairing_and_sale_import_keep_tenant_credentials_server_side(): void
    {
        $transport = file_get_contents(app_path('Services/Sync/SyncTransportService.php'));
        $importer = file_get_contents(app_path('Services/Sync/SyncSaleImportService.php'));
        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));

        $this->assertStringContainsString('SERVENEST_SYNC_CLOUD_URL before pairing', $transport);
        $this->assertStringContainsString("'registration_token' => hash('sha256', \$tillToken)", $transport);
        $this->assertStringContainsString("'pairing_till_token'", $transport);
        $this->assertStringContainsString("'pairing_local_control_token'", $transport);
        $this->assertStringContainsString('Node replacement is blocked while', $transport);
        $this->assertStringContainsString("'replace_node_id' => \$replacementNodeId", $transport);
        $this->assertStringContainsString("'replaced_node_id'", $transport);
        $this->assertStringContainsString('immutable company, branch and till binding', $transport);
        $this->assertStringContainsString("Str::isUuid(\$data['node']['id'])", $transport);
        $this->assertStringContainsString('$node->setAttribute($node->getKeyName(), $remoteNodeId)', $transport);
        $this->assertStringNotContainsString("SyncNode::query()->updateOrCreate(\n                ['id' =>", $transport);
        $this->assertStringContainsString("\$row['company_id'] = (string) \$node->company_id", $importer);
        $this->assertStringContainsString('requireTenantReference', $importer);
        $this->assertStringContainsString('applyStockProjection', $importer);
        $this->assertStringContainsString('applyCostProjection', $importer);
        $this->assertStringContainsString('postCreditSaleProjection', $importer);
        $this->assertStringContainsString("'sale_recipe'", $importer);
        $this->assertStringContainsString("'sale_modifier_recipe'", $importer);
        $this->assertStringContainsString('SYNC_AGGREGATE_ID_MISMATCH', $protocol);
        $this->assertStringContainsString("whereNotIn('status', ['processing', 'processed'])", $protocol);
        $this->assertGreaterThanOrEqual(3, substr_count($protocol, '$this->cloudEndpointUnavailable()'));

        $nodeAuthentication = file_get_contents(app_path('Http/Middleware/AuthenticateSyncNode.php'));
        $this->assertStringContainsString('SYNC_CLOUD_ENDPOINT_UNAVAILABLE', $nodeAuthentication);
    }

    public function test_local_control_and_recovery_contracts_fail_closed(): void
    {
        $edge = file_get_contents(app_path('Http/Controllers/EdgeSyncController.php'));
        $pair = file_get_contents(app_path('Http/Controllers/SyncPairController.php'));
        $management = file_get_contents(app_path('Http/Controllers/SyncNodeManagementController.php'));
        $orchestrator = file_get_contents(app_path('Services/Sync/SyncOrchestrator.php'));

        $this->assertStringContainsString("'X-Sync-Local-Control'", $edge);
        $this->assertStringContainsString("'SYNC_LOCAL_CONTROL_REQUIRED'", $edge);
        $this->assertGreaterThanOrEqual(2, substr_count($edge, '], 428);'));
        $this->assertStringContainsString("'dead_letter_events'", $edge);
        $this->assertStringContainsString("'pos_to_cloud'", $edge);
        $this->assertStringContainsString("'cloud_to_pos'", $edge);
        $this->assertStringContainsString("'equal' => false", $edge);
        $this->assertStringNotContainsString("'recovery_authorized' => ['nullable'", $pair);
        $this->assertStringContainsString('SYNC_CREDENTIAL_ROTATION_REQUIRES_REPAIR', $management);
        $this->assertStringContainsString('$queuedThisPass === 500', $orchestrator);
        $this->assertStringContainsString("config('salexa_sync.run_lock_seconds'", $orchestrator);
        $this->assertStringContainsString("'last_remote_check_at'", $orchestrator);

        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $transport = file_get_contents(app_path('Services/Sync/SyncTransportService.php'));
        $this->assertStringContainsString("'head_cursor'", $protocol);
        $this->assertStringContainsString("'acknowledged_digest'", $protocol);
        $this->assertStringContainsString('acknowledgeConfigurationCursor', $protocol);
        $this->assertStringContainsString('remote_configuration_head', $transport);
        $this->assertStringContainsString('last_applied_change', $transport);
    }
}
