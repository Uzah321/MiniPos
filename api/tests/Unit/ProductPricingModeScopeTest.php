<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\PriceController;
use Modules\Product\Http\Controllers\ProductController;
use ReflectionMethod;
use Tests\TestCase;

class ProductPricingModeScopeTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for product mode-scope tests.');
        }

        $this->databaseReady = true;
        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->boolean('is_global')->default(false);
            $table->string('branch_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('products')->insert([
            $this->product('global', null, true),
            $this->product('hospitality', 'hospitality-a', false),
            $this->product('retail', 'retail-a', false),
        ]);

        app()->instance('company', (object) ['id' => 'company-a']);
        app()->instance(BusinessModeBranchScopeService::class, new class extends BusinessModeBranchScopeService {
            public array $requested = [];

            public function __construct() {}

            public function resolve(Request $request, string $companyId, array $requestedBranchIds = []): array
            {
                $this->requested[] = $requestedBranchIds;

                return [
                    'business_mode' => 'hospitality',
                    'context_branch_id' => 'hospitality-a',
                    'branch_ids' => $requestedBranchIds ?: ['hospitality-a'],
                    'accessible_branch_ids' => ['hospitality-a', 'retail-a'],
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance(BusinessModeBranchScopeService::class);
        if ($this->databaseReady) Schema::dropIfExists('products');

        parent::tearDown();
    }

    public function test_product_and_price_queries_hide_branch_products_from_the_other_mode(): void
    {
        $branchIds = ['hospitality-a'];

        $productQuery = $this->method(ProductController::class, 'applyProductModeVisibility')
            ->invoke(new ProductController, \Modules\Product\Models\Product::query(), $branchIds);
        $priceQuery = $this->method(PriceController::class, 'modeVisibleProducts')
            ->invoke(new PriceController, 'company-a', $branchIds);

        $expected = ['global', 'hospitality'];
        $this->assertSame($expected, $productQuery->orderBy('id')->pluck('id')->sort()->values()->all());
        $this->assertSame($expected, $priceQuery->orderBy('id')->pluck('id')->sort()->values()->all());
    }

    public function test_product_scope_forwards_explicit_branch_filters_to_the_mode_resolver(): void
    {
        $request = Request::create('/api/pos/products', 'GET');
        $scope = $this->method(ProductController::class, 'productModeScope')
            ->invoke(new ProductController, $request, ['hospitality-a']);

        $this->assertSame('hospitality', $scope['business_mode']);
        $this->assertSame(['hospitality-a'], $scope['branch_ids']);
        $this->assertSame(
            [['hospitality-a']],
            app(BusinessModeBranchScopeService::class)->requested
        );
    }

    private function product(string $id, ?string $branchId, bool $global): array
    {
        return [
            'id' => $id,
            'company_id' => 'company-a',
            'is_global' => $global,
            'branch_id' => $branchId,
            'name' => ucfirst($id),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    private function method(string $class, string $method): ReflectionMethod
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection;
    }
}
