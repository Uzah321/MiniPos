<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireBusinessMode;
use App\Services\Auth\SuperAdminService;
use App\Services\CompanySettingService;
use Illuminate\Http\Request;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\RolesAndPermission\Models\Role;
use Tests\TestCase;

class RequireBusinessModeTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance('till');
        config()->set('license.platform_admin_pos_user_ids', []);

        parent::tearDown();
    }

    public function test_it_allows_the_required_mode_and_exposes_the_resolved_context(): void
    {
        [$company, $branch] = $this->companyWithBranches('branch-hospitality');
        $user = $this->userFor($company, [$branch], 'branch-hospitality');
        $request = $this->requestFor($user, 'branch-hospitality');
        $settings = new FakeBusinessModeSettings(['branch-hospitality' => true]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hospitality', $request->attributes->get('business_mode'));
        $this->assertSame('branch-hospitality', $request->attributes->get('business_mode_branch_id'));
        $this->assertSame([['company-1', 'branch-hospitality']], $settings->calls);
    }

    public function test_it_rejects_an_opposite_mode_with_a_clear_409_response(): void
    {
        [$company, $branch] = $this->companyWithBranches('branch-retail');
        $user = $this->userFor($company, [$branch], 'branch-retail');
        $request = $this->requestFor($user, 'branch-retail');
        $settings = new FakeBusinessModeSettings(['branch-retail' => false]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('BUSINESS_MODE_MISMATCH', $payload['code']);
        $this->assertSame(['hospitality'], $payload['required_modes']);
        $this->assertSame('retail', $payload['current_mode']);
        $this->assertSame('branch-retail', $payload['branch_id']);
    }

    public function test_retail_workspace_cannot_target_a_hospitality_branch_with_branch_id(): void
    {
        [$company, $retailBranch, $hospitalityBranch] = $this->companyWithBranches(
            'branch-retail',
            'branch-hospitality'
        );
        $user = $this->userFor($company, [$retailBranch, $hospitalityBranch], 'branch-hospitality');
        $request = $this->requestFor($user, 'branch-retail');
        $request->merge(['branch_id' => 'branch-hospitality']);
        $settings = new FakeBusinessModeSettings([
            'branch-retail' => false,
            'branch-hospitality' => true,
        ]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'retail'
        );

        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('BUSINESS_MODE_MISMATCH', $payload['code']);
        $this->assertSame('retail', $payload['current_mode']);
        $this->assertSame('branch-retail', $payload['branch_id']);
        $this->assertSame('branch-hospitality', $payload['conflicting_branch_id']);
        $this->assertSame('hospitality', $payload['conflicting_branch_mode']);
    }

    public function test_hospitality_workspace_cannot_target_retail_branches_with_branch_ids(): void
    {
        [$company, $hospitalityBranch, $retailBranch] = $this->companyWithBranches(
            'branch-hospitality',
            'branch-retail'
        );
        $user = $this->userFor($company, [$hospitalityBranch, $retailBranch], 'branch-retail');
        $request = $this->requestFor($user, 'branch-hospitality');
        $request->merge(['branch_ids' => ['branch-retail']]);
        $settings = new FakeBusinessModeSettings([
            'branch-hospitality' => true,
            'branch-retail' => false,
        ]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('BUSINESS_MODE_MISMATCH', $payload['code']);
        $this->assertSame('hospitality', $payload['current_mode']);
        $this->assertSame('branch-hospitality', $payload['branch_id']);
        $this->assertSame('branch-retail', $payload['conflicting_branch_id']);
        $this->assertSame('retail', $payload['conflicting_branch_mode']);
    }

    public function test_workspace_can_target_another_allocated_branch_in_the_same_mode(): void
    {
        [$company, $branchOne, $branchTwo] = $this->companyWithBranches(
            'branch-hospitality-1',
            'branch-hospitality-2'
        );
        $user = $this->userFor($company, [$branchOne, $branchTwo], 'branch-hospitality-1');
        $request = $this->requestFor($user, 'branch-hospitality-1');
        $request->merge(['branch_ids' => 'branch-hospitality-1, branch-hospitality-2']);
        $settings = new FakeBusinessModeSettings([
            'branch-hospitality-1' => true,
            'branch-hospitality-2' => true,
        ]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('branch-hospitality-1', $request->attributes->get('business_mode_branch_id'));
    }

    public function test_it_rejects_a_branch_the_user_is_not_allocated_to(): void
    {
        [$company, $assignedBranch, $otherBranch] = $this->companyWithBranches('branch-1', 'branch-2');
        $user = $this->userFor($company, [$assignedBranch], 'branch-1');
        $request = $this->requestFor($user, 'branch-2');
        $settings = new FakeBusinessModeSettings(['branch-2' => true]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('BRANCH_ACCESS_DENIED', $response->getData(true)['code']);
        $this->assertSame([], $settings->calls);
    }

    public function test_it_uses_a_resolved_till_branch_when_no_request_or_user_branch_is_selected(): void
    {
        [$company, $branch] = $this->companyWithBranches('branch-hospitality');
        $user = $this->userFor($company, [$branch], null);
        $request = $this->requestFor($user);
        $settings = new FakeBusinessModeSettings(['branch-hospitality' => true]);
        app()->instance('till', (object) ['branch_id' => 'branch-hospitality']);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['company-1', 'branch-hospitality']], $settings->calls);
    }

    public function test_it_requires_an_unambiguous_branch_for_mode_specific_operations(): void
    {
        [$company, $branchOne, $branchTwo] = $this->companyWithBranches('branch-1', 'branch-2');
        $user = $this->userFor($company, [$branchOne, $branchTwo], null);
        $request = $this->requestFor($user);
        $settings = new FakeBusinessModeSettings([]);

        $response = (new RequireBusinessMode($settings))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('BUSINESS_MODE_BRANCH_REQUIRED', $response->getData(true)['code']);
        $this->assertSame([], $settings->calls);
    }

    public function test_platform_session_can_use_a_target_company_and_ignores_the_home_branch(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('license.platform_admin_pos_user_ids', ['platform-owner']);
        [$company, $targetBranch] = $this->companyWithBranches('target-branch');

        $role = new Role;
        $role->forceFill(['name' => 'Super Admin']);
        $user = new PosUser;
        $user->forceFill([
            'id' => 'platform-owner',
            'company_id' => 'home-company',
            'branch_id' => 'home-branch',
            'user_type' => 'super_admin',
        ]);
        $user->setRelation('role', $role);
        $user->setRelation('branches', collect());
        $user->withAccessToken(new class
        {
            public function can(string $ability): bool
            {
                return in_array($ability, ['workspace:back-office', 'platform:admin'], true);
            }
        });

        $request = $this->requestFor($user, (string) $targetBranch->id);
        $settings = new FakeBusinessModeSettings(['target-branch' => true]);
        $response = (new RequireBusinessMode(
            $settings,
            app(SuperAdminService::class)
        ))->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'hospitality'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('target-branch', $request->attributes->get('business_mode_branch_id'));
        $this->assertSame([['company-1', 'target-branch']], $settings->calls);
    }

    private function companyWithBranches(string ...$branchIds): array
    {
        $company = new Company;
        $company->forceFill(['id' => 'company-1']);

        $branches = collect($branchIds)->map(function (string $branchId) use ($company) {
            $branch = new CompanyBranch;
            $branch->forceFill([
                'id' => $branchId,
                'company_id' => $company->id,
            ]);

            return $branch;
        })->values();

        $company->setRelation('branches', $branches);
        app()->instance('company', $company);

        return [$company, ...$branches->all()];
    }

    private function userFor(Company $company, array $branches, ?string $defaultBranchId): PosUser
    {
        $role = new Role;
        $role->forceFill(['name' => 'manager']);

        $user = new PosUser;
        $user->forceFill([
            'id' => 'user-1',
            'company_id' => $company->id,
            'branch_id' => $defaultBranchId,
            'user_type' => 'manager',
        ]);
        $user->setRelation('role', $role);
        $user->setRelation('branches', collect($branches));

        return $user;
    }

    private function requestFor(PosUser $user, ?string $branchId = null): Request
    {
        $request = Request::create('/api/pos/example', 'GET');
        if ($branchId) {
            $request->headers->set('X-Branch-ID', $branchId);
        }
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}

class FakeBusinessModeSettings extends CompanySettingService
{
    public array $calls = [];

    public function __construct(
        private readonly array $hospitalityByBranch
    ) {}

    public function isHospitalityMode(string $companyId, ?string $branchId = null): bool
    {
        $this->calls[] = [$companyId, $branchId];

        return (bool) ($this->hospitalityByBranch[$branchId] ?? false);
    }
}
