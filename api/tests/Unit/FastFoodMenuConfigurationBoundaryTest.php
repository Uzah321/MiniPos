<?php

namespace Tests\Unit;

use Modules\Sales\Http\Controllers\FastFoodPrepModifierController;
use ReflectionMethod;
use Tests\TestCase;

class FastFoodMenuConfigurationBoundaryTest extends TestCase
{
    public function test_every_back_office_master_and_assignment_operation_resolves_the_hospitality_branch(): void
    {
        foreach ([
            'prepMessages',
            'showPrepMessage',
            'storePrepMessage',
            'updatePrepMessage',
            'destroyPrepMessage',
            'assignPrepMessageToProducts',
            'removePrepMessageFromProduct',
            'modifiers',
            'showModifier',
            'storeModifier',
            'updateModifier',
            'destroyModifier',
            'assignModifierToProducts',
            'removeModifierFromProduct',
        ] as $method) {
            $this->assertStringContainsString(
                'resolveHospitalityBranchId',
                $this->methodBody($method),
                "{$method} must resolve and authorize its Hospitality branch."
            );
        }
    }

    public function test_assignment_writes_reject_non_menu_and_cross_branch_products(): void
    {
        foreach ([
            'storePrepMessage',
            'updatePrepMessage',
            'assignPrepMessageToProducts',
            'storeModifier',
            'updateModifier',
            'assignModifierToProducts',
        ] as $method) {
            $this->assertStringContainsString(
                'invalidMenuProductsResponse',
                $this->methodBody($method),
                "{$method} must validate products against the active Hospitality menu."
            );
        }

        $scope = $this->methodBody('configurableMenuProducts');
        $this->assertStringContainsString("->where('product_type', 'finished_good')", $scope);
        $this->assertStringContainsString("->where('is_active', true)", $scope);
        $this->assertStringContainsString("->where('is_global', false)", $scope);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $scope);
        $this->assertStringContainsString("->whereHas('prices'", $scope);
        $this->assertStringContainsString("->from('food_recipes')", $scope);
        $this->assertStringContainsString("->where('food_recipes.recipe_type', 'menu')", $scope);
    }

    public function test_configuration_read_uses_the_same_strict_menu_product_scope(): void
    {
        $this->assertStringContainsString(
            'configurableMenuProducts',
            $this->methodBody('configurationProductOptions')
        );
    }

    private function methodBody(string $method): string
    {
        $reflection = new ReflectionMethod(FastFoodPrepModifierController::class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
