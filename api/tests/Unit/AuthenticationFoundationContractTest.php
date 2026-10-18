<?php

namespace Tests\Unit;

use LogicException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\AuthenticationEvent;
use Modules\Authentication\Models\AuthSession;
use Modules\Authentication\Models\CredentialHistory;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\AuthenticationEventRecorder;
use Modules\Authentication\Services\AuthenticationPolicyService;
use Modules\Authentication\Services\AuthPolicyMerger;
use Modules\Authentication\Services\AuthPolicyResolver;
use Tests\TestCase;

class AuthenticationFoundationContractTest extends TestCase
{
    public function test_pos_user_never_serializes_any_stored_login_code_material(): void
    {
        $user = new PosUser;
        $user->setRawAttributes([
            'id' => 'user-1',
            'username' => 'cashier',
            'password' => 'password-hash',
            'login_code' => 'slow-code-hash',
            'login_code_lookup' => str_repeat('a', 64),
            'login_code_lookup_key_id' => 'v1',
            'remember_token' => 'remember-me',
        ]);

        $payload = $user->toArray();

        foreach ([
            'password',
            'login_code',
            'login_code_lookup',
            'login_code_lookup_key_id',
            'remember_token',
        ] as $secret) {
            $this->assertArrayNotHasKey($secret, $payload);
        }
    }

    public function test_session_history_and_audit_models_hide_security_material(): void
    {
        $session = new AuthSession([
            'device_identifier_hash' => str_repeat('d', 64),
            'policy_snapshot' => ['session' => ['minutes' => 30]],
        ]);
        $history = new CredentialHistory(['credential_hash' => 'hash']);
        $event = new AuthenticationEvent(['identifier_hash' => str_repeat('i', 64)]);

        $this->assertArrayNotHasKey('device_identifier_hash', $session->toArray());
        $this->assertArrayNotHasKey('policy_snapshot', $session->toArray());
        $this->assertArrayNotHasKey('credential_hash', $history->toArray());
        $this->assertArrayNotHasKey('identifier_hash', $event->toArray());
    }

    public function test_foundation_migration_has_all_required_enterprise_tables_and_indexes(): void
    {
        $migration = file_get_contents(base_path(
            'Modules/Authentication/database/migrations/2026_10_13_190000_create_enterprise_authentication_foundation.php'
        ));

        foreach ([
            "Schema::create('auth_policies'",
            "Schema::create('user_access_assignments'",
            "Schema::create('auth_credential_histories'",
            "Schema::create('auth_sessions'",
            "Schema::create('authentication_events'",
            "'pos_users_company_code_lookup_uq'",
            "'auth_policy_scope_version_uq'",
            "'user_access_assignment_uq'",
        ] as $contract) {
            $this->assertStringContainsString($contract, $migration);
        }
    }

    public function test_back_office_default_is_password_only_and_pos_default_fails_closed(): void
    {
        $defaults = config('authentication.security.policy_defaults.authentication');

        $this->assertSame(
            'username_password',
            data_get($defaults, 'back_office.login_mode')
        );
        $this->assertSame(
            'username_password',
            data_get($defaults, 'pos.login_mode')
        );

        $service = new AuthenticationPolicyService(
            new AuthPolicyResolver(new AuthPolicyMerger)
        );
        $this->assertSame(
            [AuthenticationPolicyService::METHOD_USERNAME_PASSWORD],
            $service->allowedMethods('back-office', AuthorizationScope::global())
        );
        $this->assertSame(
            [AuthenticationPolicyService::METHOD_USERNAME_PASSWORD],
            $service->allowedMethods('pos', AuthorizationScope::global())
        );
        $this->assertSame(
            [AuthenticationPolicyService::METHOD_LOGIN_CODE],
            $service->allowedMethods('pos', AuthorizationScope::global(), 'code')
        );
        $this->assertSame(
            [AuthenticationPolicyService::METHOD_USERNAME_PASSWORD],
            $service->allowedMethods('back-office', AuthorizationScope::global(), 'code')
        );
    }

    public function test_audit_identifier_fingerprint_is_normalized_and_not_reversible(): void
    {
        config()->set('authentication.security.audit_identifier_key', str_repeat('k', 32));
        $recorder = new AuthenticationEventRecorder;

        $fingerprint = $recorder->identifierHash(' Cashier.One ');

        $this->assertSame($fingerprint, $recorder->identifierHash('cashier.one'));
        $this->assertNotSame(
            $recorder->identifierHash('cashier.one', 'company-1'),
            $recorder->identifierHash('cashier.one', 'company-2')
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        $this->assertStringNotContainsString('cashier', $fingerprint);
    }

    public function test_authentication_events_cannot_be_mutated_through_the_model(): void
    {
        $event = new AuthenticationEvent;
        $event->setRawAttributes([
            'id' => 'event-1',
            'event_type' => 'login.succeeded',
            'outcome' => 'success',
        ], true);
        $event->exists = true;
        $event->event_type = 'tampered';

        $this->expectException(LogicException::class);
        $event->save();
    }
}
