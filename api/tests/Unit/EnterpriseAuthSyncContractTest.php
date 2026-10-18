<?php

namespace Tests\Unit;

use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class EnterpriseAuthSyncContractTest extends TestCase
{
    public function test_edge_snapshot_orders_and_projects_only_required_authentication_data(): void
    {
        $reflection = new ReflectionClass(SyncConfigurationService::class);
        $datasets = $reflection->getConstant('DATASETS');
        $order = $reflection->getConstant('APPLY_ORDER');
        $android = $reflection->getConstant('ANDROID_DATASETS');

        $this->assertArrayHasKey('auth_policies', $datasets);
        $this->assertArrayHasKey('user_access_assignments', $datasets);
        $this->assertNotContains('auth_policies', $android);
        $this->assertNotContains('user_access_assignments', $android);
        $this->assertNotContains('pos_users', $android);
        foreach (['auth_sessions', 'authentication_events', 'auth_credential_histories'] as $cloudOnly) {
            $this->assertArrayNotHasKey($cloudOnly, $datasets);
        }

        $this->assertLessThan(
            array_search('auth_policies', $order, true),
            array_search('pos_tills', $order, true)
        );
        $this->assertLessThan(
            array_search('user_access_assignments', $order, true),
            array_search('pos_users', $order, true)
        );
        $this->assertNotContains('created_by', $datasets['auth_policies']['columns']);
        $this->assertNotContains('updated_by', $datasets['auth_policies']['columns']);
        foreach (['granted_by', 'grant_reason', 'revoked_by', 'revocation_reason'] as $cloudAuditField) {
            $this->assertNotContains(
                $cloudAuditField,
                $datasets['user_access_assignments']['columns']
            );
        }
        $this->assertContains('login_code_lookup', $datasets['pos_users']['columns']);
        $this->assertContains('login_code_lookup_key_id', $datasets['pos_users']['columns']);
    }

    public function test_edge_policy_projection_drops_cloud_and_unknown_settings(): void
    {
        $service = new SyncConfigurationService(new SyncPayloadHasher);
        $method = new ReflectionMethod($service, 'sanitizeEdgeAuthPolicySettings');
        $safe = $method->invoke($service, 'authentication', [
            'pos' => ['login_mode' => 'both', 'untrusted' => 'drop-me'],
            'back_office' => ['login_mode' => 'code'],
            'lockout' => ['maximum_attempts' => 7, 'secret' => 'drop-me'],
            'cloud_secret' => 'drop-me',
        ]);

        $this->assertSame([
            'pos' => ['login_mode' => 'both'],
            'lockout' => ['maximum_attempts' => 7],
        ], $safe);
    }
}
