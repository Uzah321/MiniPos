<?php

namespace Tests\Unit;

use Modules\Sales\Http\Controllers\SalesController;
use Modules\Sales\Models\SalesTransaction;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SalesDuplicateScopeTest extends TestCase
{
    private const COMPANY = 'company-one';

    private const BRANCH = 'branch-one';

    private const TILL = 'till-one';

    private const CASHIER = 'cashier-one';

    public function test_same_company_branch_till_and_pos_user_can_replay(): void
    {
        $this->assertNull($this->scopeConflictResponse($this->sale()));
    }

    public function test_other_company_branch_till_or_cashier_gets_only_a_safe_conflict(): void
    {
        foreach ([
            ['company_id' => 'company-two'],
            ['branch_id' => 'branch-two'],
            ['till_id' => 'till-two'],
            ['cashier_id' => 'cashier-two'],
            ['user_id' => 'cashier-two'],
            ['cashier_id' => null],
            ['user_id' => null],
        ] as $changedFields) {
            $response = $this->scopeConflictResponse($this->sale($changedFields));

            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame([
                'message' => 'Client sale ID is already in use.',
                'code' => 'CLIENT_SALE_ID_SCOPE_CONFLICT',
            ], $response->getData(true));
            $this->assertStringNotContainsString('PRIVATE-RECEIPT-123', $response->getContent());
        }
    }

    public function test_both_initial_lookup_and_race_recovery_guard_before_receipt_replay(): void
    {
        $method = new ReflectionMethod(SalesController::class, 'store');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        foreach (['existingMobileSale', 'existingSale'] as $variable) {
            $this->assertMatchesRegularExpression(
                '/if \(\$'.$variable.'\) \{\s*'
                .'if \(\$conflict = \$this->existingSaleScopeConflictResponse\(\$'.$variable
                .', \$company, \$till, \$user\)\) \{\s*return \$conflict;\s*\}\s*'
                .'return \$this->buildDuplicateSaleCreatedResponse\(\$'.$variable.', \$till\);/s',
                $source
            );
        }
    }

    private function sale(array $changes = []): SalesTransaction
    {
        return new SalesTransaction(array_merge([
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'cashier_id' => self::CASHIER,
            'user_id' => self::CASHIER,
            'invoice_number' => 'PRIVATE-RECEIPT-123',
        ], $changes));
    }

    private function scopeConflictResponse(SalesTransaction $sale)
    {
        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'existingSaleScopeConflictResponse');
        $method->setAccessible(true);

        return $method->invoke(
            $controller,
            $sale,
            (object) ['id' => self::COMPANY],
            (object) ['id' => self::TILL, 'branch_id' => self::BRANCH],
            (object) ['id' => self::CASHIER]
        );
    }
}
