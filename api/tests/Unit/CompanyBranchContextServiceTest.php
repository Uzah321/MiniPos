<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanyBranchContextService;
use App\Services\Auth\SuperAdminService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyBranchContextServiceTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for branch-context tests.');
        }

        Schema::dropIfExists('company_branches');
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->softDeletes();
        });
        $this->databaseReady = true;

        DB::table('company_branches')->insert([
            ['id' => 'company-a-main', 'company_id' => 'company-a', 'deleted_at' => null],
            ['id' => 'company-a-second', 'company_id' => 'company-a', 'deleted_at' => null],
            ['id' => 'company-b-main', 'company_id' => 'company-b', 'deleted_at' => null],
        ]);

        app()->forgetInstance('till');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('till');
        if ($this->databaseReady) {
            Schema::dropIfExists('company_branches');
        }

        parent::tearDown();
    }

    public function test_resolve_ignores_a_stale_foreign_primary_branch_and_uses_the_sole_accessible_current_branch(): void
    {
        $request = $this->request(['branch_id' => 'company-b-main']);

        $resolved = $this->service(['company-a-main'])
            ->resolve($request, 'company-a');

        $this->assertSame('company-a-main', $resolved);
    }

    public function test_legacy_platform_session_can_select_any_branch_in_the_current_company(): void
    {
        $request = $this->request(['branch_id' => 'company-b-main'], [
            'branch_id' => 'company-a-second',
        ]);

        $resolved = $this->service([], true)->resolve($request, 'company-a');

        $this->assertSame('company-a-second', $resolved);
    }

    public function test_foreign_header_branch_is_rejected_for_the_current_company(): void
    {
        $request = $this->request(['branch_id' => 'company-b-main']);
        $request->headers->set('X-Branch-ID', 'company-b-main');

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main', 'company-a-second'])
                ->assertRequestBranches($request, 'company-a'),
            422,
            'WORKSPACE_BRANCH_INVALID'
        );
    }

    public function test_foreign_top_level_branch_is_rejected_for_the_current_company(): void
    {
        $request = $this->request(
            ['branch_id' => 'company-a-main'],
            ['branch_id' => 'company-b-main']
        );

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main', 'company-a-second'])
                ->assertRequestBranches($request, 'company-a'),
            422,
            'WORKSPACE_BRANCH_INVALID'
        );
    }

    public function test_branch_ids_accept_an_array_or_comma_separated_current_company_filter(): void
    {
        $service = $this->service(['company-a-main', 'company-a-second']);

        $arrayIds = $service->assertRequestBranches(
            $this->request(null, ['branch_ids' => ['company-a-main', 'company-a-second']]),
            'company-a'
        );
        $commaIds = $service->assertRequestBranches(
            $this->request(null, ['branch_ids' => 'company-a-main, company-a-second']),
            'company-a'
        );

        $this->assertSame(['company-a-main', 'company-a-second'], $arrayIds);
        $this->assertSame(['company-a-main', 'company-a-second'], $commaIds);
    }

    public function test_foreign_branch_inside_branch_ids_is_rejected(): void
    {
        $request = $this->request(null, [
            'branch_ids' => ['company-a-main', 'company-b-main'],
        ]);

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main', 'company-a-second'])
                ->assertRequestBranches($request, 'company-a'),
            422,
            'WORKSPACE_BRANCH_INVALID'
        );
    }

    public function test_current_company_branch_without_user_access_is_rejected(): void
    {
        $request = $this->request(null, ['branch_id' => 'company-a-second']);

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main'])
                ->assertRequestBranches($request, 'company-a'),
            403,
            'BRANCH_ACCESS_DENIED'
        );
    }

    public function test_conflicting_scalar_header_and_body_contexts_are_rejected(): void
    {
        $request = $this->request(null, ['branch_id' => 'company-a-second']);
        $request->headers->set('X-Branch-ID', 'company-a-main');

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main', 'company-a-second'])
                ->assertRequestBranches($request, 'company-a'),
            409,
            'BRANCH_CONTEXT_MISMATCH'
        );
    }

    public function test_record_branch_route_accepts_a_different_accessible_branch_without_moving_the_session(): void
    {
        $request = $this->recordBranchRequest(['branch_id' => 'company-a-second']);
        $request->headers->set('X-Branch-ID', 'company-a-main');
        $service = $this->service(['company-a-main', 'company-a-second']);

        $this->assertEqualsCanonicalizing(
            ['company-a-main', 'company-a-second'],
            $service->assertRequestBranches($request, 'company-a')
        );
        $this->assertSame('company-a-main', $service->resolve($request, 'company-a'));
    }

    public function test_record_branch_route_still_rejects_a_foreign_company_branch(): void
    {
        $request = $this->recordBranchRequest(['branch_id' => 'company-b-main']);
        $request->headers->set('X-Branch-ID', 'company-a-main');

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main', 'company-a-second'])
                ->assertRequestBranches($request, 'company-a'),
            422,
            'WORKSPACE_BRANCH_INVALID'
        );
    }

    public function test_record_branch_route_still_rejects_an_inaccessible_branch(): void
    {
        $request = $this->recordBranchRequest(['branch_id' => 'company-a-second']);
        $request->headers->set('X-Branch-ID', 'company-a-main');

        $this->assertBranchFailure(
            fn () => $this->service(['company-a-main'])
                ->assertRequestBranches($request, 'company-a'),
            403,
            'BRANCH_ACCESS_DENIED'
        );
    }

    private function recordBranchRequest(array $input): Request
    {
        $request = $this->request(null, $input);
        $route = new Route('POST', '/api/pos/tills', []);
        $route->defaults(CompanyBranchContextService::RECORD_BRANCH_ROUTE_FLAG, true);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /** @param array<int, string> $accessibleBranchIds */
    private function service(
        array $accessibleBranchIds,
        bool $platformSession = false
    ): CompanyBranchContextService
    {
        $scope = $this->createMock(BusinessModeBranchScopeService::class);
        $scope->method('accessibleBranchIds')->willReturn($accessibleBranchIds);
        $superAdmins = $this->createMock(SuperAdminService::class);
        $superAdmins->method('hasPlatformSessionAccess')->willReturn($platformSession);

        return new CompanyBranchContextService($scope, $superAdmins);
    }

    private function request(?array $user, array $input = []): Request
    {
        $request = Request::create('/api/configuration', 'GET', $input);
        $request->setUserResolver(fn () => $user === null ? null : (object) $user);

        return $request;
    }

    private function assertBranchFailure(
        callable $operation,
        int $expectedStatus,
        string $expectedCode
    ): void {
        try {
            $operation();
            $this->fail("Expected branch-context error {$expectedCode}.");
        } catch (HttpResponseException $exception) {
            $this->assertSame($expectedStatus, $exception->getResponse()->getStatusCode());
            $this->assertSame(
                $expectedCode,
                $exception->getResponse()->getData(true)['code'] ?? null
            );
        }
    }
}
