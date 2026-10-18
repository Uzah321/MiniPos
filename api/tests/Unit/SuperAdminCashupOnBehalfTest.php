<?php

namespace Tests\Unit;

use App\Services\Auth\SuperAdminService;
use App\Services\Shifts\ShiftPolicyService;
use Modules\Authentication\Models\PosUser;
use Modules\Sales\Http\Controllers\CashupController;
use ReflectionMethod;
use Tests\TestCase;

class SuperAdminCashupOnBehalfTest extends TestCase
{
    public function test_system_super_admin_bypasses_missing_company_cashup_role_pivots(): void
    {
        $controller = $this->controllerWithSuperAdmin(true);
        $method = new ReflectionMethod($controller, 'cashupPermission');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $controller,
            $this->actor(),
            'cashup_shift',
            'company-a'
        ));
    }

    public function test_system_super_admin_can_cashup_on_behalf_even_when_supervisor_cashup_is_disabled(): void
    {
        $controller = $this->controllerWithSuperAdmin(true);
        $method = new ReflectionMethod($controller, 'canCashupOnBehalf');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $controller,
            $this->actor(),
            'company-a',
            ['allow_supervisor_cashup' => false]
        ));
    }

    public function test_shift_selection_is_company_branch_open_and_unclosed_scoped(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Modules/Sales/Http/Controllers/CashupController.php'
        );

        $methodStart = strpos($source, 'private function resolveCashupShift(');
        $methodEnd = strpos($source, 'private function availableCashupShifts(', $methodStart);
        $methodSource = substr($source, $methodStart, $methodEnd - $methodStart);

        $this->assertStringContainsString("->where('company_id', \$companyId)", $methodSource);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $methodSource);
        $this->assertStringContainsString("->where('status', 'open')", $methodSource);
        $this->assertStringContainsString("->whereNull('closed_at')", $methodSource);
        $this->assertStringContainsString("abort(404, 'The selected open shift was not found for this branch.')", $methodSource);
        $this->assertStringContainsString("->where('pos_user_shift_sessions.company_id', \$companyId)", $source);
        $this->assertStringContainsString("->where('pos_user_shift_sessions.branch_id', \$branchId)", $source);
    }

    public function test_cashup_persists_cashier_ownership_and_super_admin_audit_separately(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Modules/Sales/Http/Controllers/CashupController.php'
        );

        $this->assertStringContainsString("'cashier_id' => \$shift->pos_user_id", $source);
        $this->assertStringContainsString("'closed_by' => \$user->id", $source);
        $this->assertStringContainsString('->lockForUpdate()', $source);
        $this->assertStringContainsString("'code' => 'CASHUP_SHIFT_ALREADY_CLOSED'", $source);
    }

    public function test_all_cashup_mutation_entry_points_use_the_central_permission_gate(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Modules/Sales/Http/Controllers/CashupController.php'
        );

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($source, "\$this->cashupPermission(\$user, 'cashup_shift'")
        );
        $this->assertStringContainsString(
            "\$this->cashupPermission(\$user, 'end_shift'",
            $source
        );

        $gateStart = strpos($source, 'private function cashupPermission(');
        $gateEnd = strpos($source, 'private function getActiveShift(', $gateStart);
        $gateSource = substr($source, $gateStart, $gateEnd - $gateStart);

        $this->assertStringContainsString('$this->superAdmins->isSuperAdmin($user)', $gateSource);
        $this->assertStringContainsString('return hasPermission($user->role_id, $permission, $companyId);', $gateSource);
    }

    public function test_existing_install_repair_migration_contains_every_required_cashup_permission(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Modules/RolesAndPermission/database/migrations/2026_10_07_121500_repair_cashup_permissions.php'
        );

        foreach (['cashup_shift', 'end_shift', 'cashup_on_behalf', 'view_cashup_financial_amounts'] as $permission) {
            $this->assertStringContainsString("'name' => '{$permission}'", $source);
        }

        $this->assertStringContainsString("Schema::hasTable('permissions')", $source);
        $this->assertStringContainsString("Schema::hasTable('role_permissions')", $source);
        $this->assertStringContainsString("['super_admin', 'super_administrator']", $source);
        $this->assertStringContainsString("['cashier', 'manager', 'supervisor']", $source);
        $this->assertStringContainsString("=> ['cashup_shift', 'end_shift']", $source);
    }

    private function controllerWithSuperAdmin(bool $allowed): CashupController
    {
        $superAdmins = $this->createMock(SuperAdminService::class);
        $superAdmins->method('isSuperAdmin')->willReturn($allowed);

        return new CashupController(
            shiftPolicyService: $this->createMock(ShiftPolicyService::class),
            superAdmins: $superAdmins
        );
    }

    private function actor(): PosUser
    {
        $actor = new PosUser([
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'user_type' => 'super_admin',
        ]);
        $actor->forceFill(['id' => 'system-owner']);

        return $actor;
    }

}
