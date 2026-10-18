<?php

namespace Tests\Unit;

use Modules\Product\Http\Controllers\ProductController;
use ReflectionMethod;
use Tests\TestCase;

class HospitalityMenuItemVisibilityContractTest extends TestCase
{
    public function test_legacy_menu_compatibility_requires_branch_price_and_active_menu_recipe(): void
    {
        $method = new ReflectionMethod(ProductController::class, 'applyBranchOnlyMenuVisibility');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString("->where('is_global', false)", $body);
        $this->assertStringContainsString("->whereIn('branch_id', \$branchIds)", $body);
        $this->assertStringContainsString("->where('is_global', true)", $body);
        $this->assertStringContainsString("->whereHas('prices'", $body);
        $this->assertStringContainsString("->where('is_active', true)", $body);
        $this->assertStringContainsString("->from('food_recipes')", $body);
        $this->assertStringContainsString("->where('food_recipes.recipe_type', 'menu')", $body);
        $this->assertStringContainsString("->where('food_recipes.is_active', true)", $body);
    }

    public function test_legacy_compatibility_is_enabled_only_in_hospitality_mode(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProductController::class))->getFileName());

        $this->assertStringContainsString(
            "\$scope['business_mode'] === 'hospitality' && Schema::hasTable('food_recipes')",
            $source
        );
    }
}
