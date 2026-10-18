<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockPosWhenDayEndRunning;
use App\Http\Middleware\ResolveTillFromToken;
use App\Services\Reports\EnterpriseReportDownloadService;
use App\Services\Sales\CreditCustomerService;
use App\Services\Shifts\ShiftPolicyService;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Modules\Sales\Http\Controllers\CreditCustomerController;
use Modules\Sales\Models\CreditCustomer;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreditCustomerAccountRoutesTest extends TestCase
{
    public function test_pos_account_routes_are_till_bound_while_back_office_payment_stays_till_free(): void
    {
        foreach ([
            ['GET', '/api/pos/credit-customers/pos-lookup?purpose=account_payment'],
            ['GET', '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/pos-account'],
            ['POST', '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/pos-payments'],
            ['POST', '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/pos-payments/00000000-0000-0000-0000-000000000002/reverse'],
        ] as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();

            $this->assertContains(ResolveTillFromToken::class, $middleware);
            $this->assertContains(BlockPosWhenDayEndRunning::class, $middleware);
        }

        $backOfficeRoute = app('router')->getRoutes()->match(Request::create(
            '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/payments',
            'POST'
        ));
        $this->assertNotContains(ResolveTillFromToken::class, $backOfficeRoute->gatherMiddleware());

        $backOfficeReversalRoute = app('router')->getRoutes()->match(Request::create(
            '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/payments/00000000-0000-0000-0000-000000000002/reverse',
            'POST'
        ));
        $this->assertNotContains(ResolveTillFromToken::class, $backOfficeReversalRoute->gatherMiddleware());
    }

    public function test_payment_receipt_route_is_authenticated_and_till_free(): void
    {
        $route = app('router')->getRoutes()->match(Request::create(
            '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/payments/00000000-0000-0000-0000-000000000002/receipt/pdf?paper=80mm&download=0',
            'GET'
        ));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:sanctum', $middleware);
        $this->assertNotContains(ResolveTillFromToken::class, $middleware);
        $this->assertSame(
            CreditCustomerController::class . '@paymentReceiptPdf',
            $route->getActionName()
        );
    }

    public function test_payment_receipt_controller_requires_an_allowed_permission_and_tenant_scopes_payment(): void
    {
        $source = file_get_contents((new \ReflectionClass(CreditCustomerController::class))->getFileName());

        $this->assertStringContainsString("'capture_credit_customer_payment'", $source);
        $this->assertStringContainsString("'view_credit_customer_statement'", $source);
        $this->assertStringContainsString(
            'if ($capturePermission !== true && $statementPermission !== true)',
            $source
        );
        $this->assertStringContainsString("->where('company_id', \$company->id)", $source);
        $this->assertStringContainsString("->where('credit_customer_id', \$customer->id)", $source);
        $this->assertStringContainsString('$this->authorizeCustomerBranch($request, $customer, $company->id)', $source);
        $this->assertStringContainsString('$this->authorizePaymentBranch($request, $payment->branch_id, $company->id)', $source);
        $this->assertStringContainsString("['Recorded By', \$recordedByName ?: \$cashierName ?: '-']", $source);
    }

    public function test_customer_branch_guard_allows_the_allocated_branch_and_rejects_another_branch(): void
    {
        $controller = new CreditCustomerController(
            new CreditCustomerService(),
            new ShiftPolicyService(),
            new EnterpriseReportDownloadService()
        );
        $method = new \ReflectionMethod($controller, 'authorizeCustomerBranch');
        $customer = new CreditCustomer([
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'account_number' => 'ACC-1',
            'name' => 'Test Customer',
        ]);

        $allowedRequest = Request::create('/receipt', 'GET');
        $allowedRequest->setUserResolver(fn () => new GenericUser([
            'id' => 'user-1',
            'branch_id' => 'branch-1',
            'user_type' => 'cashier',
            'role' => ['name' => 'Cashier'],
        ]));
        $allowedRequest->attributes->set('company_branch_id', 'branch-1');
        $method->invoke($controller, $allowedRequest, $customer, 'company-1');
        $this->addToAssertionCount(1);

        $deniedRequest = Request::create('/receipt', 'GET');
        $deniedRequest->setUserResolver(fn () => new GenericUser([
            'id' => 'user-2',
            'branch_id' => 'branch-2',
            'user_type' => 'cashier',
            'role' => ['name' => 'Cashier'],
        ]));
        $deniedRequest->attributes->set('company_branch_id', 'branch-2');

        try {
            $method->invoke($controller, $deniedRequest, $customer, 'company-1');
            $this->fail('An unallocated branch must not be allowed to download the payment receipt.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('You are not allocated to this credit account branch.', $exception->getMessage());
        }
    }

    public function test_payment_branch_guard_protects_global_customer_payment_documents(): void
    {
        $controller = new CreditCustomerController(
            new CreditCustomerService(),
            new ShiftPolicyService(),
            new EnterpriseReportDownloadService()
        );
        $method = new \ReflectionMethod($controller, 'authorizePaymentBranch');
        $request = Request::create('/receipt', 'GET');
        $request->setUserResolver(fn () => new GenericUser([
            'id' => 'user-2',
            'branch_id' => 'branch-2',
            'user_type' => 'cashier',
            'role' => ['name' => 'Cashier'],
        ]));
        $request->attributes->set('company_branch_id', 'branch-2');

        try {
            $method->invoke($controller, $request, 'branch-1', 'company-1');
            $this->fail('A payment from another branch must remain private even when its customer is global.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('You are not allocated to this credit payment branch.', $exception->getMessage());
        }
    }

    public function test_payment_receipt_uses_content_sized_thermal_paper(): void
    {
        $controller = new CreditCustomerController(
            new CreditCustomerService(),
            new ShiftPolicyService(),
            new EnterpriseReportDownloadService()
        );
        $method = new \ReflectionMethod($controller, 'paymentReceiptPaper');
        $paper = $method->invoke($controller, [[
            'rows' => array_fill(0, 24, ['Field', 'A reasonably sized value']),
        ]]);

        $this->assertSame(226.77, $paper[2]);
        $this->assertGreaterThanOrEqual(420, $paper[3]);
        $this->assertLessThan(841.89, $paper[3]);
    }
}
