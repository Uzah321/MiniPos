<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\ConfigurationBranchScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfigurationBranchScopeServiceTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for configuration branch-scope tests.');
        }

        $this->databaseReady = true;
        Schema::dropIfExists('company_branches');
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->softDeletes();
        });

        DB::table('company_branches')->insert([
            ['id' => 'branch-a', 'company_id' => 'company-a', 'deleted_at' => null],
            ['id' => 'branch-b', 'company_id' => 'company-b', 'deleted_at' => null],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('company_branches');
        }

        parent::tearDown();
    }

    public function test_validated_middleware_branch_wins_over_stale_user_branch(): void
    {
        $branchScope = $this->createMock(BusinessModeBranchScopeService::class);
        $branchScope->expects($this->never())->method('resolve');
        $request = $this->request('branch-b');
        $request->attributes->set('company_branch_id', 'branch-a');

        $resolved = (new ConfigurationBranchScopeService($branchScope))
            ->resolve($request, 'company-a');

        $this->assertSame('branch-a', $resolved);
    }

    public function test_explicit_header_branch_is_forwarded_to_the_tenant_scope_validator(): void
    {
        $request = $this->request('branch-b', 'branch-a');
        $branchScope = $this->createMock(BusinessModeBranchScopeService::class);
        $branchScope->expects($this->once())
            ->method('resolve')
            ->with($request, 'company-a', ['branch-a'])
            ->willReturn([
                'business_mode' => 'retail',
                'context_branch_id' => 'branch-a',
                'branch_ids' => ['branch-a'],
                'accessible_branch_ids' => ['branch-a'],
            ]);

        $resolved = (new ConfigurationBranchScopeService($branchScope))
            ->resolve($request, 'company-a');

        $this->assertSame('branch-a', $resolved);
    }

    public function test_stale_user_branch_from_another_company_falls_back_to_global_scope(): void
    {
        $branchScope = $this->createMock(BusinessModeBranchScopeService::class);
        $branchScope->expects($this->never())->method('resolve');

        $resolved = (new ConfigurationBranchScopeService($branchScope))
            ->resolve($this->request('branch-b'), 'company-a');

        $this->assertNull($resolved);
    }

    public function test_user_branch_is_used_only_when_it_belongs_to_the_current_company(): void
    {
        $request = $this->request('branch-a');
        $branchScope = $this->createMock(BusinessModeBranchScopeService::class);
        $branchScope->expects($this->once())
            ->method('resolve')
            ->with($request, 'company-a', ['branch-a'])
            ->willReturn([
                'business_mode' => 'retail',
                'context_branch_id' => 'branch-a',
                'branch_ids' => ['branch-a'],
                'accessible_branch_ids' => ['branch-a'],
            ]);

        $resolved = (new ConfigurationBranchScopeService($branchScope))
            ->resolve($request, 'company-a');

        $this->assertSame('branch-a', $resolved);
    }

    public function test_currency_and_payment_controllers_share_the_validated_branch_resolver(): void
    {
        foreach ([
            base_path('Modules/Currency/Http/Controllers/CurrencyController.php') => 6,
            base_path('Modules/Currency/Http/Controllers/PaymentTypeController.php') => 5,
        ] as $path => $expectedCalls) {
            $source = file_get_contents($path);

            $this->assertStringContainsString('ConfigurationBranchScopeService', $source);
            $this->assertSame(
                $expectedCalls,
                substr_count($source, '$this->configurationBranchScope->resolve(')
            );
            $this->assertStringNotContainsString('$branchId = $user->branch_id ?? null;', $source);
        }
    }

    public function test_company_branch_selector_ignores_a_stale_home_branch(): void
    {
        $source = file_get_contents(base_path('Modules/Company/Http/Controllers/CompanyController.php'));

        $this->assertStringContainsString(
            '$currentCompanyUserBranchId = in_array($userBranchId, $companyBranchIds, true)',
            $source
        );
        $this->assertStringContainsString("\$request->attributes->get('company_branch_id')", $source);
        $this->assertStringContainsString('$currentCompanyUserBranchId,', $source);
    }

    private function request(?string $userBranchId, ?string $headerBranchId = null): Request
    {
        $request = Request::create('/api/configuration', 'GET');
        if ($headerBranchId !== null) {
            $request->headers->set('X-Branch-ID', $headerBranchId);
        }
        $request->setUserResolver(fn () => (object) ['branch_id' => $userBranchId]);

        return $request;
    }
}
