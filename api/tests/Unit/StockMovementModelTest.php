<?php

namespace Tests\Unit;

use Modules\Product\Models\ProductCost;
use Modules\Product\Models\StockMovement;
use PHPUnit\Framework\TestCase;

class StockMovementModelTest extends TestCase
{
    public function test_enterprise_issue_classification_and_cost_fields_are_mass_assignable(): void
    {
        $movement = new StockMovement;

        foreach ([
            'balance_after',
            'currency_code',
            'issue_destination',
            'stock_usage_type',
            'expense_account_code',
        ] as $field) {
            $this->assertContains($field, $movement->getFillable(), "{$field} must be persisted on stock movement creation.");
        }
    }

    public function test_product_cost_currency_code_is_mass_assignable(): void
    {
        $this->assertContains('currency_code', (new ProductCost)->getFillable());
    }
}
