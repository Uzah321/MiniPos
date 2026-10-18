<?php

namespace Tests\Feature;

use App\Services\CompanySettingService;
use Modules\Authentication\Services\AuthenticationPolicyService;
use Modules\Authentication\Services\AuthPolicyMerger;
use Modules\Authentication\Services\AuthPolicyResolver;
use Modules\Authentication\Services\CredentialPolicyService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Authentication\Services\PosAuthenticationContextService;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Http\Controllers\TerminalConfigurationBootstrapController;
use Modules\GeneralSettings\Models\PosTill;
use ReflectionClass;
use Tests\TestCase;

class PosConfigurationBootstrapContractTest extends TestCase
{
    public function test_pre_login_bootstrap_exposes_effective_dynamic_code_requirements(): void
    {
        $company = new Company;
        $company->forceFill([
            'id' => 'company-1',
            'company_name' => 'Pizza Inn',
            'logo' => 'pizza.svg',
            'status' => 'active',
        ]);
        $company->exists = true;
        $till = new PosTill;
        $till->forceFill([
            'id' => 'till-1',
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'configuration_version' => 2,
            'is_active' => true,
        ]);
        $till->exists = true;
        $settings = $this->createMock(CompanySettingService::class);
        $settings->method('get')->willReturn('code');
        $resolver = new AuthPolicyResolver(new AuthPolicyMerger);
        $context = new PosAuthenticationContextService(
            new AuthenticationPolicyService($resolver),
            new CredentialPolicyService($resolver),
            new EnterpriseScopeResolver,
            $settings,
        );

        $effective = $context->resolve($company, $till);
        $this->assertSame('code', $effective['mode']);
        $this->assertSame(['login_code'], $effective['methods']);
        $this->assertSame([
            'minimum_length' => 4,
            'maximum_length' => 8,
            'numeric_only' => true,
            'reject_repeated_digits' => true,
            'reject_sequences' => true,
        ], $effective['login_code']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $effective['fingerprint']
        );

        $controllerPath = (new ReflectionClass(TerminalConfigurationBootstrapController::class))
            ->getFileName();
        $source = file_get_contents($controllerPath);
        $this->assertStringContainsString("'credential_policy' => [", $source);
        $this->assertStringContainsString("'login_code' => \$authContext['login_code']", $source);
        $this->assertStringContainsString("'policy_fingerprint' => \$authContext['fingerprint']", $source);

        $routes = file_get_contents(base_path('Modules/GeneralSettings/routes/api.php'));
        $this->assertStringContainsString(
            "Route::get('/configuration/bootstrap', [TerminalConfigurationBootstrapController::class, 'show'])",
            $routes
        );
        $this->assertStringNotContainsString(
            "Route::get('/configuration/bootstrap'",
            file_get_contents(base_path('Modules/Authentication/routes/api.php'))
        );
    }
}
