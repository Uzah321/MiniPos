<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Modules\Sales\Http\Controllers\CreditCustomerController;
use Modules\Sales\Http\Controllers\QzController;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class TenantBranchFallbackContractTest extends TestCase
{
    public function test_qz_scope_uses_the_middleware_validated_company_branch(): void
    {
        $scope = $this->methodSource(QzController::class, 'resolveScope');
        $resolver = $this->methodSource(QzController::class, 'validatedCompanyBranchId');

        $this->assertStringContainsString('$this->validatedCompanyBranchId($request)', $scope);
        $this->assertStringNotContainsString('$request->user()?->branch_id', $scope);
        $this->assertStringContainsString("attributes->get('company_branch_id')", $resolver);
    }

    public function test_credit_payment_and_report_do_not_fall_back_to_a_raw_user_branch(): void
    {
        foreach (['recordPayment', 'report'] as $method) {
            $source = $this->methodSource(CreditCustomerController::class, $method);

            $this->assertStringContainsString('$this->validatedCompanyBranchId($request)', $source);
            $this->assertStringNotContainsString('$user->branch_id', $source);
        }
    }

    public function test_credit_document_authorization_filters_the_primary_branch_by_company(): void
    {
        $source = $this->methodSource(CreditCustomerController::class, 'authorizedBranchIds');

        $this->assertStringContainsString('$validatedBranchId = $this->validatedCompanyBranchId($request)', $source);
        $this->assertStringContainsString("->where('company_id', \$companyId)", $source);
        $this->assertStringContainsString("->where('id', \$primaryBranchId)", $source);
        $this->assertStringNotContainsString("push((string) data_get(\$user, 'branch_id'))", $source);
    }

    public function test_validated_branch_helpers_normalize_the_resolved_attribute(): void
    {
        $request = Request::create('/tenant-scope', 'GET');
        $request->attributes->set('company_branch_id', '  branch-a  ');

        foreach ([QzController::class, CreditCustomerController::class] as $controllerClass) {
            $controller = (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
            $method = new ReflectionMethod($controllerClass, 'validatedCompanyBranchId');

            $this->assertSame('branch-a', $method->invoke($controller, $request));
        }
    }

    public function test_branch_sensitive_controllers_share_the_company_scoped_resolver(): void
    {
        foreach ([
            'Modules/Product/Http/Controllers/HospitalityMonthEndController.php',
            'Modules/Product/Http/Controllers/StockWastageController.php',
            'Modules/Product/Http/Controllers/ClosingStockMovementReportController.php',
            'Modules/Product/Http/Controllers/DailyStockReconciliationController.php',
            'Modules/Product/Http/Controllers/FrontStockDailyClosingController.php',
            'Modules/Product/Http/Controllers/BackStockMonthEndStockTakeController.php',
            'Modules/Product/Http/Controllers/ProductBarcodePrintController.php',
            'Modules/GeneralSettings/Http/Controllers/SalesIndustryModeController.php',
            'Modules/Sales/Http/Controllers/QuotationController.php',
            'Modules/Sales/Http/Controllers/FastFoodPrepModifierController.php',
        ] as $relativePath) {
            $source = file_get_contents(base_path($relativePath));

            $this->assertStringContainsString(
                'CompanyBranchContextService',
                $source,
                "{$relativePath} must resolve branches through the tenant-scoped service."
            );
        }
    }

    public function test_inventory_branch_resolvers_never_read_raw_user_or_company_defaults(): void
    {
        foreach ([
            'Modules/Product/Http/Controllers/HospitalityMonthEndController.php',
            'Modules/Product/Http/Controllers/StockWastageController.php',
            'Modules/Product/Http/Controllers/ClosingStockMovementReportController.php',
            'Modules/Product/Http/Controllers/DailyStockReconciliationController.php',
            'Modules/Product/Http/Controllers/FrontStockDailyClosingController.php',
            'Modules/Product/Http/Controllers/BackStockMonthEndStockTakeController.php',
        ] as $relativePath) {
            $source = file_get_contents(base_path($relativePath));

            $this->assertStringNotContainsString('$request->user()?->branch_id', $source);
            $this->assertStringNotContainsString('$company?->branch_id', $source);
        }
    }

    public function test_sales_report_costing_context_uses_validated_middleware_context(): void
    {
        $source = file_get_contents(
            base_path('Modules/Sales/Http/Controllers/SalesReportController.php')
        );

        $this->assertStringContainsString(
            "attributes->get('company_branch_id', '')",
            $source
        );
        $this->assertStringNotContainsString(
            "\$userBranchId = trim((string) (\$request->user()?->branch_id ?? ''))",
            $source
        );
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
