<?php

namespace Tests\Unit;

use Modules\GeneralSettings\Http\Controllers\WorkspaceBootstrapController;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class WorkspaceBootstrapCapabilityTest extends TestCase
{
    public function test_hospitality_workspace_exposes_category_management(): void
    {
        $capabilities = $this->modeCapabilities(true);

        $this->assertContains('category_management', $capabilities);
        $this->assertContains('menu_items', $capabilities);
    }

    public function test_hospitality_only_workspace_ignores_a_legacy_retail_hint(): void
    {
        $this->assertSame([
            'category_management',
            'menu_items',
            'recipes',
            'modifiers',
            'prep_messages',
            'boh_inventory',
            'production',
            'wastage',
            'kitchen_routing',
        ], $this->modeCapabilities(false));
    }

    /**
     * @return array<int, string>
     */
    private function modeCapabilities(bool $hospitality): array
    {
        $controller = (new ReflectionClass(WorkspaceBootstrapController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WorkspaceBootstrapController::class, 'modeCapabilities');
        $method->setAccessible(true);

        return $method->invoke($controller, $hospitality);
    }
}
