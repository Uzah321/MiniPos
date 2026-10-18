<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Modules\Sales\Http\Controllers\QuotationController;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class QuotationWorkspaceScopeTest extends TestCase
{
    public function test_front_office_quotation_scope_uses_the_resolved_till(): void
    {
        $request = Request::create('/api/pos/front/quotations', 'GET');
        $till = (object) [
            'id' => 'till-a',
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
        ];
        $request->attributes->set('resolved_till', $till);

        $this->assertSame($till, $this->resolveTill($request, 'company-a'));
    }

    public function test_front_office_quotation_scope_rejects_company_and_branch_overrides(): void
    {
        foreach ([
            ['company_id' => 'company-b', 'query' => []],
            ['company_id' => 'company-a', 'query' => ['branch_id' => 'branch-b']],
        ] as $case) {
            $request = Request::create('/api/pos/front/quotations', 'GET', $case['query']);
            $request->attributes->set('resolved_till', (object) [
                'id' => 'till-a',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
            ]);

            try {
                $this->resolveTill($request, $case['company_id']);
                $this->fail('A Front Office quotation request escaped its resolved till scope.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_front_office_queries_and_conversion_keep_branch_and_sale_till_guards(): void
    {
        foreach (['frontIndex', 'frontShow', 'frontUpdate', 'frontIssue', 'frontCancel'] as $method) {
            $this->assertStringContainsString(
                "->where('branch_id', (string) \$till->branch_id)",
                $this->methodSource($method),
                "{$method} must scope quotations to the resolved till branch."
            );
        }

        $store = $this->methodSource('frontStore');
        $this->assertStringContainsString("'branch_id' => (string) \$till->branch_id", $store);

        $convert = $this->methodSource('convert');
        $this->assertStringContainsString("->where('branch_id', (string) \$till->branch_id)", $convert);
        $this->assertStringContainsString("->where('till_id', (string) \$till->id)", $convert);
        $this->assertStringContainsString('QUOTATION_SALE_SCOPE_MISMATCH', $convert);
    }

    private function resolveTill(Request $request, string $companyId): object
    {
        $controller = (new ReflectionClass(QuotationController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(QuotationController::class, 'frontOfficeTill');
        $method->setAccessible(true);

        return $method->invoke($controller, $request, $companyId);
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(QuotationController::class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
