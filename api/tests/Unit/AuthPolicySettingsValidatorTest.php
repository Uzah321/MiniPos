<?php

namespace Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Services\AuthPolicyManagementService;
use Modules\Authentication\Services\AuthPolicySettingsValidator;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Tests\TestCase;

class AuthPolicySettingsValidatorTest extends TestCase
{
    private AuthPolicySettingsValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AuthPolicySettingsValidator;
    }

    public function test_complete_supported_policy_documents_are_accepted_without_coercion(): void
    {
        $authentication = [
            'pos' => ['login_mode' => 'both'],
            'back_office' => ['login_mode' => 'username_password'],
            'lockout' => ['maximum_attempts' => 7, 'decay_seconds' => 600],
        ];
        $credential = [
            'password' => [
                'minimum_length' => 14,
                'maximum_length' => 200,
                'require_uppercase' => true,
                'require_lowercase' => true,
                'require_number' => true,
                'require_symbol' => false,
                'history_count' => 10,
            ],
            'login_code' => [
                'minimum_length' => 5,
                'maximum_length' => 10,
                'numeric_only' => false,
                'reject_repeated_digits' => true,
                'reject_sequences' => true,
            ],
        ];
        $session = [
            'pos' => ['lifetime_minutes' => 720, 'idle_timeout_minutes' => 45],
            'back_office' => ['lifetime_minutes' => 480, 'idle_timeout_minutes' => 20],
        ];

        $this->assertSame($authentication, $this->validator->validate('authentication', $authentication));
        $this->assertSame($credential, $this->validator->validate('credential', $credential));
        $this->assertSame($session, $this->validator->validate('session', $session));
    }

    public function test_nested_allowlists_and_scalar_types_are_strict(): void
    {
        $cases = [
            ['authentication', ['unknown' => ['enabled' => true]], 'settings.unknown'],
            ['authentication', ['pos' => ['unknown' => true]], 'settings.pos.unknown'],
            ['authentication', ['pos' => ['login_mode' => 'password']], 'settings.pos.login_mode'],
            ['authentication', ['back_office' => ['login_mode' => 'login_code']], 'settings.back_office.login_mode'],
            ['authentication', ['lockout' => ['maximum_attempts' => '5']], 'settings.lockout.maximum_attempts'],
            ['authentication', ['lockout' => ['decay_seconds' => 60.5]], 'settings.lockout.decay_seconds'],
            ['credential', ['password' => ['require_symbol' => 1]], 'settings.password.require_symbol'],
            ['credential', ['login_code' => ['numeric_only' => 'true']], 'settings.login_code.numeric_only'],
            ['session', ['pos' => ['lifetime_minutes' => '60', 'idle_timeout_minutes' => 10]], 'settings.pos.lifetime_minutes'],
            ['session', ['back_office' => []], 'settings.back_office'],
        ];

        foreach ($cases as [$policyKey, $settings, $errorKey]) {
            $this->assertInvalid($policyKey, $settings, $errorKey);
        }
    }

    public function test_security_ranges_and_cross_field_invariants_are_enforced(): void
    {
        $cases = [
            ['authentication', ['lockout' => ['maximum_attempts' => 0]], 'settings.lockout.maximum_attempts'],
            ['authentication', ['lockout' => ['maximum_attempts' => 101]], 'settings.lockout.maximum_attempts'],
            ['authentication', ['lockout' => ['decay_seconds' => 29]], 'settings.lockout.decay_seconds'],
            ['authentication', ['lockout' => ['decay_seconds' => 86401]], 'settings.lockout.decay_seconds'],
            ['credential', ['password' => ['minimum_length' => 5, 'maximum_length' => 50]], 'settings.password.minimum_length'],
            ['credential', ['password' => ['minimum_length' => 50, 'maximum_length' => 20]], 'settings.password.minimum_length'],
            ['credential', ['password' => ['minimum_length' => 12]], 'settings.password.maximum_length'],
            ['credential', ['password' => ['history_count' => 25]], 'settings.password.history_count'],
            ['credential', ['login_code' => ['minimum_length' => 3, 'maximum_length' => 8]], 'settings.login_code.minimum_length'],
            ['credential', ['login_code' => ['minimum_length' => 10, 'maximum_length' => 8]], 'settings.login_code.minimum_length'],
            ['credential', ['login_code' => ['maximum_length' => 13]], 'settings.login_code.minimum_length'],
            ['session', ['pos' => ['lifetime_minutes' => 4, 'idle_timeout_minutes' => 1]], 'settings.pos.lifetime_minutes'],
            ['session', ['pos' => ['lifetime_minutes' => 60, 'idle_timeout_minutes' => 61]], 'settings.pos.idle_timeout_minutes'],
            ['session', ['back_office' => ['idle_timeout_minutes' => 30]], 'settings.back_office.lifetime_minutes'],
            ['session', ['back_office' => ['lifetime_minutes' => 10081, 'idle_timeout_minutes' => 30]], 'settings.back_office.lifetime_minutes'],
        ];

        foreach ($cases as [$policyKey, $settings, $errorKey]) {
            $this->assertInvalid($policyKey, $settings, $errorKey);
        }
    }

    public function test_management_service_cannot_bypass_settings_validation(): void
    {
        $service = new AuthPolicyManagementService(
            new EnterpriseScopeResolver,
            $this->validator,
        );

        $this->expectException(ValidationException::class);
        $service->publish(
            'authentication',
            ['back_office' => ['login_mode' => 'login_code']],
            EnterpriseScope::Global,
            null,
        );
    }

    /** @param array<string, mixed> $settings */
    private function assertInvalid(string $policyKey, array $settings, string $errorKey): void
    {
        try {
            $this->validator->validate($policyKey, $settings);
            $this->fail("Policy [{$policyKey}] unexpectedly accepted invalid settings.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }
}
