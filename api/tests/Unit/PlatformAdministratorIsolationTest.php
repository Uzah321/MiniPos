<?php

namespace Tests\Unit;

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\RequireCompanyPermission;
use App\Http\Middleware\RequirePlatformAdministrator;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Services\Auth\SuperAdminService;
use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanySettingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Modules\Authentication\Http\Controllers\PosUserController;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\RolesAndPermission\Models\Role;
use Tests\TestCase;

class PlatformAdministratorIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('license.platform_admin_pos_user_ids', []);
        app()->forgetInstance('company');
        app()->forgetInstance('license');
        app()->forgetInstance('license.status');
        app()->forgetInstance('till');
    }

    public function test_tenant_super_admin_is_not_a_platform_administrator_by_default(): void
    {
        $user = $this->posUser('tenant-admin', 'super_admin', 'Super Administrator');
        $service = app(SuperAdminService::class);

        $this->assertTrue($service->isSuperAdmin($user));
        $this->assertFalse($service->isPlatformAdministrator($user));
        $this->assertSame(
            ['workspace:back-office'],
            $service->tokenAbilitiesForWorkspace($user, 'back-office')
        );
        $this->assertFalse($service->hasPlatformSessionAccess($user));

        $response = $this->middlewareResponse($user);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('PLATFORM_ADMIN_REQUIRED', $response->getData(true)['code']);
    }

    public function test_explicitly_allowlisted_super_admin_gets_platform_access(): void
    {
        config()->set('license.platform_admin_pos_user_ids', [' PLATFORM-ADMIN ']);
        $user = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        $service = app(SuperAdminService::class);

        $this->assertTrue($service->isPlatformAdministrator($user));
        $this->assertSame(
            ['workspace:back-office', 'platform:admin'],
            $service->tokenAbilitiesForWorkspace($user, 'back-office')
        );
        $this->assertSame(
            ['workspace:front-office'],
            $service->tokenAbilitiesForWorkspace($user, 'front-office')
        );
        $this->assertTrue($service->hasPlatformSessionAccess($user));
        $this->assertFalse($service->hasPlatformSessionAccess($user, ['workspace:front-office', 'platform:admin']));
        $this->assertFalse($service->hasPlatformSessionAccess($user, ['workspace:front-office']));

        $response = $this->middlewareResponse($user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['ok']);
    }

    public function test_platform_session_is_treated_as_cross_company_branch_administrator(): void
    {
        $settings = $this->createMock(CompanySettingService::class);
        $platformAccess = $this->createMock(SuperAdminService::class);
        $platformAccess->expects($this->once())
            ->method('hasPlatformSessionAccess')
            ->willReturn(true);

        $scope = new BusinessModeBranchScopeService($settings, $platformAccess);

        $this->assertTrue($scope->isSuperAdmin((object) [
            'user_type' => 'administrator',
        ]));
    }

    public function test_allowlist_cannot_elevate_a_non_super_admin(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['ordinary-admin']);
        $user = $this->posUser('ordinary-admin', 'admin', 'Administrator');

        $this->assertFalse(app(SuperAdminService::class)->isPlatformAdministrator($user));
        $this->assertSame(403, $this->middlewareResponse($user)->getStatusCode());
    }

    public function test_edge_deployment_ignores_the_platform_allowlist(): void
    {
        config()->set('salexa_sync.deployment_mode', 'edge');
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $user = $this->posUser('platform-admin', 'super_admin', 'Super Admin');

        $this->assertFalse(app(SuperAdminService::class)->isPlatformAdministrator($user));

        $response = $this->middlewareResponse($user);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('DEPLOYMENT_WORKSPACE_DENIED', $response->getData(true)['code']);
    }

    public function test_local_hub_denies_platform_administration_even_to_allowlisted_super_admin(): void
    {
        config()->set('salexa_sync.local_hub', true);
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $user = $this->posUser('platform-admin', 'super_admin', 'Super Admin');

        $this->assertSame([], app(SuperAdminService::class)->platformAdministratorIds());
        $this->assertFalse(app(SuperAdminService::class)->isPlatformAdministrator($user));
        $this->assertSame(['workspace:back-office'], app(SuperAdminService::class)
            ->tokenAbilitiesForWorkspace($user, 'back-office'));

        $response = $this->middlewareResponse($user);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('DEPLOYMENT_WORKSPACE_DENIED', $response->getData(true)['code']);
    }

    public function test_platform_administrator_ids_are_normalized_deduplicated_and_disabled_on_edge(): void
    {
        config()->set('license.platform_admin_pos_user_ids', [
            ' OWNER-ID ',
            'owner-id',
            '',
        ]);
        $service = app(SuperAdminService::class);

        $this->assertSame(['owner-id'], $service->platformAdministratorIds());

        config()->set('salexa_sync.deployment_mode', 'edge');

        $this->assertSame([], $service->platformAdministratorIds());
    }

    public function test_company_permission_accepts_cross_company_platform_session_only(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $company = new Company;
        $company->forceFill(['id' => 'target-company']);
        app()->instance('company', $company);

        $platformOwner = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        $platformOwner->forceFill(['company_id' => 'home-company']);
        $request = Request::create('/api/target-resource', 'GET');
        $request->setUserResolver(fn () => $platformOwner);

        $allowed = app(RequireCompanyPermission::class)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'inventory_management'
        );

        $this->assertSame(200, $allowed->getStatusCode());

        $platformOwner->withAccessToken(new class
        {
            public function can(string $ability): bool
            {
                return $ability === 'workspace:front-office';
            }
        });
        $wrongWorkspace = app(RequireCompanyPermission::class)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'inventory_management'
        );

        $this->assertSame(403, $wrongWorkspace->getStatusCode());
        $this->assertSame('COMPANY_ACCESS_DENIED', $wrongWorkspace->getData(true)['code']);

        config()->set('license.platform_admin_pos_user_ids', []);
        $denied = app(RequireCompanyPermission::class)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'inventory_management'
        );

        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame('COMPANY_ACCESS_DENIED', $denied->getData(true)['code']);
        app()->forgetInstance('company');
    }

    public function test_me_exposes_server_derived_platform_flag_on_response_and_user(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $user = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        app()->instance('license.status', ['valid' => true]);
        $request = Request::create('/api/pos-auth/me', 'GET');
        $request->setUserResolver(fn () => $user);

        $payload = app(PosUserController::class)->me($request)->getData(true);

        $this->assertTrue($payload['is_platform_admin']);
        $this->assertTrue($payload['user']['is_platform_admin']);
    }

    public function test_companyless_login_is_only_admitted_to_the_cloud_back_office_authenticator(): void
    {
        $request = $this->controllerRequest(
            '/api/pos-auth/back-office/login',
            'POST',
            ['workspace' => 'back-office'],
            'loginBackOffice'
        );

        $response = app(ResolveCompanyFromDomain::class)->handle(
            $request,
            fn (Request $resolved) => response()->json([
                'allowed' => true,
                'platform_context_required' => $resolved->attributes->getBoolean('platform_context_required'),
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['platform_context_required']);

        $legacy = $this->controllerRequest(
            '/api/pos-auth/login',
            'POST',
            ['workspace' => 'back-office'],
            'login'
        );
        $legacyResponse = app(ResolveCompanyFromDomain::class)->handle(
            $legacy,
            fn (Request $resolved) => response()->json([
                'allowed' => true,
                'platform_context_required' => $resolved->attributes->getBoolean('platform_context_required'),
            ])
        );

        $this->assertSame(200, $legacyResponse->getStatusCode());
        $this->assertTrue($legacyResponse->getData(true)['platform_context_required']);

        $frontOffice = $this->controllerRequest(
            '/api/pos-auth/login',
            'POST',
            ['workspace' => 'front-office'],
            'login'
        );
        $denied = app(ResolveCompanyFromDomain::class)->handle(
            $frontOffice,
            fn () => response()->json(['allowed' => true])
        );

        $this->assertSame(422, $denied->getStatusCode());
        $this->assertSame('COMPANY_CODE_REQUIRED', $denied->getData(true)['code']);
    }

    public function test_companyless_platform_session_can_refresh_identity_but_still_needs_company_for_tenant_routes(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $owner = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        $identity = $this->controllerRequest('/api/pos-auth/me', 'GET', [], 'me');
        $identity->setUserResolver(fn () => $owner);

        $identityResponse = app(ResolveCompanyFromDomain::class)->handle(
            $identity,
            fn (Request $resolved) => response()->json([
                'allowed' => true,
                'platform_context_required' => $resolved->attributes->getBoolean('platform_context_required'),
            ])
        );

        $this->assertSame(200, $identityResponse->getStatusCode());
        $this->assertTrue($identityResponse->getData(true)['platform_context_required']);

        $tenantRequest = $this->controllerRequest(
            '/api/pos-users',
            'GET',
            [],
            'index'
        );
        $tenantRequest->setUserResolver(fn () => $owner);
        $tenantResponse = app(ResolveCompanyFromDomain::class)->handle(
            $tenantRequest,
            fn () => response()->json(['allowed' => true])
        );

        $this->assertSame(422, $tenantResponse->getStatusCode());
        $this->assertSame('COMPANY_CODE_REQUIRED', $tenantResponse->getData(true)['code']);
    }

    public function test_license_middleware_only_bypasses_an_unselected_context_for_the_allowlisted_owner(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $owner = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        $request = Request::create('/api/pos-auth/me', 'GET');
        $request->attributes->set('platform_context_required', true);
        $request->setUserResolver(fn () => $owner);

        $allowed = app(CheckCompanyLicense::class)->handle(
            $request,
            fn () => response()->json(['ok' => true])
        );
        $this->assertSame(200, $allowed->getStatusCode());

        $tenantAdmin = $this->posUser('tenant-admin', 'super_admin', 'Super Admin');
        $request->setUserResolver(fn () => $tenantAdmin);
        $denied = app(CheckCompanyLicense::class)->handle(
            $request,
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(400, $denied->getStatusCode());
        $this->assertSame('COMPANY_NOT_RESOLVED', $denied->getData(true)['code']);
    }

    public function test_me_returns_the_resolved_company_instead_of_only_the_owner_home_company(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $owner = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        $company = new Company;
        $company->forceFill(['id' => 'selected-company', 'company_name' => 'Selected Company']);
        app()->instance('company', $company);
        app()->instance('license.status', ['valid' => true]);
        $request = Request::create('/api/pos-auth/me', 'GET');
        $request->setUserResolver(fn () => $owner);

        $payload = app(PosUserController::class)->me($request)->getData(true);

        $this->assertSame('selected-company', $payload['company_id']);
        $this->assertSame('Selected Company', $payload['company']['company_name']);
        $this->assertSame('selected-company', $payload['workspace_context']['company_id']);
        $this->assertFalse($payload['platform_context_required']);
    }

    public function test_companyless_me_redacts_the_owner_canonical_tenant_from_the_response_only(): void
    {
        config()->set('license.platform_admin_pos_user_ids', ['platform-admin']);
        $owner = $this->posUser('platform-admin', 'super_admin', 'Super Admin');
        $owner->forceFill([
            'company_id' => 'canonical-company',
            'branch_id' => 'canonical-branch',
        ]);
        $owner->setRelation('company', tap(new Company, fn (Company $company) => $company->forceFill([
            'id' => 'canonical-company',
            'company_name' => 'Canonical Company',
        ])));
        $owner->setRelation('branch', tap(new CompanyBranch, fn (CompanyBranch $branch) => $branch->forceFill([
            'id' => 'canonical-branch',
            'company_id' => 'canonical-company',
        ])));
        $owner->setRelation('branches', collect([$owner->branch]));
        $request = Request::create('/api/pos-auth/me', 'GET');
        $request->setUserResolver(fn () => $owner);

        $payload = app(PosUserController::class)->me($request)->getData(true);

        $this->assertNull($payload['company']);
        $this->assertNull($payload['company_id']);
        $this->assertNull($payload['workspace_context']);
        $this->assertTrue($payload['platform_context_required']);
        $this->assertNull($payload['user']['company_id']);
        $this->assertNull($payload['user']['branch_id']);
        $this->assertNull($payload['user']['company']);
        $this->assertNull($payload['user']['branch']);
        $this->assertSame([], $payload['user']['branches']);

        $this->assertSame('canonical-company', $owner->company_id);
        $this->assertSame('canonical-branch', $owner->branch_id);
    }

    public function test_me_does_not_trust_tenant_super_admin_or_platform_ability_alone(): void
    {
        $user = $this->posUser('tenant-admin', 'super_admin', 'Super Admin');
        app()->instance('license.status', ['valid' => true]);
        $request = Request::create('/api/pos-auth/me', 'GET');
        $request->setUserResolver(fn () => $user);

        $payload = app(PosUserController::class)->me($request)->getData(true);

        $this->assertFalse($payload['is_platform_admin']);
        $this->assertFalse($payload['user']['is_platform_admin']);
    }

    public function test_login_contract_exposes_the_same_server_derived_flag(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Authentication/Http/Controllers/PosUserController.php'
        ));

        $this->assertStringContainsString(
            '$hasPlatformSessionAccess = $this->superAdmins->hasPlatformSessionAccess($user, $abilities);',
            $source
        );
        $this->assertStringContainsString(
            '$responseUser->setAttribute(\'is_platform_admin\', $hasPlatformSessionAccess);',
            $source
        );
        $this->assertStringContainsString(
            "'is_platform_admin' => \$hasPlatformSessionAccess",
            $source
        );
    }

    public function test_login_contract_keeps_the_owner_identity_canonical_and_returns_target_context(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Authentication/Http/Controllers/PosUserController.php'
        ));

        $this->assertStringContainsString('private function loginCandidateQuery(', $source);
        $this->assertStringContainsString("\$request->workspace === 'back-office'", $source);
        $this->assertStringContainsString("\$query->orWhereIn('id', \$platformAdministratorIds)", $source);
        $this->assertStringContainsString(
            'if ($isCrossCompanyLogin && ($isFrontOffice || ! $isPlatformAdministrator))',
            $source
        );
        $this->assertStringContainsString(
            'if (! $isPlatformAdministrator) {',
            $source
        );
        $this->assertStringContainsString(
            "'workspace_context' => [",
            $source
        );
        $this->assertStringContainsString(
            "'code' => \$isPlatformAdministrator\n                        ? 'COMPANY_BRANCH_REQUIRED'",
            $source
        );
        $this->assertStringContainsString('resolveBackOfficeLoginCompany', $source);
        $this->assertStringContainsString('platformCompanySelectionChallenge', $source);
        $this->assertStringContainsString('selectBackOfficeCompany', $source);
        $this->assertStringContainsString("'platform:select-company'", $source);
        $this->assertStringContainsString("'selection_token' => \$issuedToken->plainTextToken", $source);
        $this->assertStringContainsString("'company_selection_required' => true", $source);
        $this->assertStringContainsString("'company_selection_required' => false", $source);
        $this->assertStringContainsString("'platform_context_required' => false", $source);
        $this->assertStringContainsString("'platform_company_selected'", $source);
        $this->assertStringContainsString('companyForAuthenticationResponse', $source);
    }

    private function posUser(string $id, string $userType, string $roleName): PosUser
    {
        $user = new class(['user_type' => $userType]) extends PosUser
        {
            public function load($relations)
            {
                return $this;
            }
        };
        $user->forceFill(['id' => $id]);
        $role = new Role(['name' => $roleName]);
        $role->setRelation('permissions', collect());
        $user->setRelation('role', $role);
        $user->withAccessToken(new class
        {
            public function can(string $ability): bool
            {
                return in_array($ability, ['workspace:back-office', 'platform:admin'], true);
            }
        });

        return $user;
    }

    private function middlewareResponse(PosUser $user)
    {
        $request = Request::create('/api/companies', 'GET');
        $request->setUserResolver(fn () => $user);

        return app(RequirePlatformAdministrator::class)->handle(
            $request,
            fn () => response()->json(['ok' => true])
        );
    }

    private function controllerRequest(
        string $uri,
        string $method,
        array $parameters,
        string $controllerMethod
    ): Request {
        $request = Request::create($uri, $method, $parameters);
        $route = new RoutingRoute(
            [$method],
            ltrim($uri, '/'),
            [PosUserController::class, $controllerMethod]
        );
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
