<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\Manufacturing\ManufacturingEnterpriseReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Manufacturing\Http\Controllers\ManufacturingEnterpriseReportController;
use Modules\Manufacturing\Http\Controllers\ManufacturingReportController;
use ReflectionMethod;
use Tests\TestCase;

class ManufacturingReportScopeTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for manufacturing scope tests.');
        }

        $this->databaseReady = true;
        $this->createTables();
        $this->seedScopeData();
        app()->instance('company', (object) ['id' => 'company-a']);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');

        if ($this->databaseReady) {
            foreach ([
                'stock_movements',
                'production_order_outputs',
                'production_order_overheads',
                'production_order_inputs',
                'products',
                'production_orders',
                'production_plans',
                'general_settings',
                'company_branches',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_legacy_manufacturing_report_rejects_the_hospitality_only_scope(): void
    {
        $query = DB::table('production_orders');
        $method = new ReflectionMethod(ManufacturingReportController::class, 'applyOrderFilters');

        $this->assertRetailControllerRejected(fn () => $method->invoke(
            new ManufacturingReportController(app(BusinessModeBranchScopeService::class)),
            $query,
            $this->request()
        ));
    }

    public function test_explicit_opposite_mode_branch_is_rejected_by_manufacturing_report(): void
    {
        $query = DB::table('production_orders');
        $method = new ReflectionMethod(ManufacturingReportController::class, 'applyOrderFilters');

        try {
            $method->invoke(
                new ManufacturingReportController(app(BusinessModeBranchScopeService::class)),
                $query,
                $this->request(['branch_id' => 'hospitality-a'])
            );
            $this->fail('Expected an opposite-mode manufacturing branch to be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame(
                'BUSINESS_MODE_MISMATCH',
                json_decode($exception->getResponse()->getContent(), true)['code']
            );
        }
    }

    public function test_legacy_enterprise_manufacturing_report_rejects_the_hospitality_only_scope(): void
    {
        $controller = new ManufacturingEnterpriseReportController(
            app(ManufacturingEnterpriseReportService::class),
            app(BusinessModeBranchScopeService::class)
        );
        $method = new ReflectionMethod($controller, 'filters');

        $this->assertRetailControllerRejected(
            fn () => $method->invoke($controller, $this->request(['company_id' => 'company-b']))
        );
    }

    public function test_enterprise_plan_ids_validate_requested_ids_against_tenant_and_branch_scope(): void
    {
        $service = app(ManufacturingEnterpriseReportService::class);
        $method = new ReflectionMethod($service, 'planIds');

        $ids = $method->invoke($service, [
            'company_id' => 'company-a',
            'branch_ids' => ['retail-a', 'retail-b'],
            'production_plan_ids' => 'retail-plan-a,retail-plan-b,hospitality-plan,foreign-plan',
        ]);

        $this->assertEqualsCanonicalizing(['retail-plan-a', 'retail-plan-b'], $ids);
        $this->assertSame([], $method->invoke($service, [
            'production_plan_ids' => 'retail-plan-a,foreign-plan',
        ]));
    }

    public function test_enterprise_plan_history_scopes_orders_and_child_rows(): void
    {
        $history = app(ManufacturingEnterpriseReportService::class)->productionPlanHistory(
            'retail-plan-a',
            ['company_id' => 'company-a', 'branch_ids' => ['retail-a']]
        );

        $this->assertSame(['retail-order-a'], collect($history['orders'])->pluck('id')->all());
        $this->assertCount(1, $history['material_usage']);
        $this->assertSame('retail-order-a', $history['material_usage'][0]['production_order_id']);

        $foreign = app(ManufacturingEnterpriseReportService::class)->productionPlanHistory(
            'foreign-plan',
            ['company_id' => 'company-a', 'branch_ids' => ['retail-a', 'retail-b']]
        );

        $this->assertSame([], $foreign['orders']);
        $this->assertSame([], $foreign['summary']);
    }

    private function request(array $query = []): Request
    {
        $request = Request::create('/api/pos/manufacturing/reports', 'GET', $query);
        $request->headers->set('X-Branch-ID', 'retail-a');

        $user = new class
        {
            public string $branch_id = 'retail-a';

            public string $user_type = 'manager';

            public object $role;

            public function __construct()
            {
                $this->role = (object) ['name' => 'Manager'];
            }

            public function branches(): object
            {
                return new class
                {
                    public function where(string $column, mixed $value): self
                    {
                        return $this;
                    }

                    public function pluck(string $column)
                    {
                        return collect(['retail-a', 'retail-b', 'hospitality-a']);
                    }
                };
            }
        };
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function assertRetailControllerRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the legacy Retail manufacturing controller to reject hospitality-only scope.');
        } catch (HttpResponseException $exception) {
            $payload = json_decode($exception->getResponse()->getContent(), true);

            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('BUSINESS_MODE_MISMATCH', $payload['code']);
            $this->assertSame(['retail'], $payload['required_modes']);
            $this->assertSame('hospitality', $payload['current_mode']);
        }
    }

    private function createTables(): void
    {
        Schema::create('company_branches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->softDeletes();
        });

        Schema::create('general_settings', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->string('setting_key');
            $table->text('setting_value')->nullable();
            $table->string('setting_type')->default('boolean');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('production_plans', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('plan_number');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('production_plan_id')->nullable();
            $table->string('production_number');
            $table->string('status')->default('draft');
            $table->decimal('planned_quantity', 18, 4)->default(0);
            $table->decimal('actual_good_quantity', 18, 4)->default(0);
            $table->decimal('actual_reject_quantity', 18, 4)->default(0);
            $table->decimal('actual_waste_quantity', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
        });

        Schema::create('production_order_inputs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('production_order_id');
            $table->string('product_id')->nullable();
            $table->decimal('planned_quantity', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0);
            $table->decimal('issued_quantity', 18, 4)->default(0);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->decimal('wasted_quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->string('currency')->nullable();
        });

        Schema::create('production_order_overheads', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('production_order_id');
            $table->string('cost_type')->nullable();
            $table->string('accounting_class')->nullable();
            $table->string('description')->nullable();
            $table->string('staff_name')->nullable();
            $table->string('staff_id')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('currency')->nullable();
            $table->timestamps();
        });

        Schema::create('production_order_outputs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('production_order_id');
            $table->string('product_id')->nullable();
            $table->decimal('quantity_produced', 18, 4)->default(0);
            $table->decimal('quantity_rejected', 18, 4)->default(0);
            $table->decimal('quantity_wasted', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->string('currency')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('production_order_id')->nullable();
            $table->string('product_id')->nullable();
            $table->timestamps();
        });
    }

    private function seedScopeData(): void
    {
        DB::table('company_branches')->insert([
            ['id' => 'retail-a', 'company_id' => 'company-a'],
            ['id' => 'retail-b', 'company_id' => 'company-a'],
            ['id' => 'hospitality-a', 'company_id' => 'company-a'],
            ['id' => 'foreign-a', 'company_id' => 'company-b'],
        ]);

        foreach ([
            'boh_enabled',
            'fast_food_mode_enabled',
            'receive_stock_to_boh_first',
            'recipe_consumes_front_stock',
        ] as $key) {
            DB::table('general_settings')->insert([
                'id' => 'hospitality-a-'.$key,
                'company_id' => 'company-a',
                'branch_id' => 'hospitality-a',
                'setting_key' => $key,
                'setting_value' => 'true',
                'setting_type' => 'boolean',
                'is_active' => true,
            ]);
        }

        $now = now();
        DB::table('production_plans')->insert([
            ['id' => 'retail-plan-a', 'company_id' => 'company-a', 'branch_id' => 'retail-a', 'plan_number' => 'RPA', 'name' => 'Retail A', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'retail-plan-b', 'company_id' => 'company-a', 'branch_id' => 'retail-b', 'plan_number' => 'RPB', 'name' => 'Retail B', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'hospitality-plan', 'company_id' => 'company-a', 'branch_id' => 'hospitality-a', 'plan_number' => 'HP', 'name' => 'Hospitality', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'foreign-plan', 'company_id' => 'company-b', 'branch_id' => 'foreign-a', 'plan_number' => 'FP', 'name' => 'Foreign', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('production_orders')->insert([
            ['id' => 'retail-order-a', 'company_id' => 'company-a', 'branch_id' => 'retail-a', 'production_plan_id' => 'retail-plan-a', 'production_number' => 'ROA', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'retail-order-b', 'company_id' => 'company-a', 'branch_id' => 'retail-b', 'production_plan_id' => 'retail-plan-b', 'production_number' => 'ROB', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'hospitality-order', 'company_id' => 'company-a', 'branch_id' => 'hospitality-a', 'production_plan_id' => 'hospitality-plan', 'production_number' => 'HO', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'foreign-order', 'company_id' => 'company-b', 'branch_id' => 'foreign-a', 'production_plan_id' => 'foreign-plan', 'production_number' => 'FO', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('products')->insert(['id' => 'material-a', 'name' => 'Material A', 'code' => 'MAT-A']);
        DB::table('production_order_inputs')->insert([
            ['id' => 'retail-input', 'company_id' => 'company-a', 'branch_id' => 'retail-a', 'production_order_id' => 'retail-order-a', 'product_id' => 'material-a', 'issued_quantity' => 2, 'total_cost' => 10],
            ['id' => 'hospitality-input', 'company_id' => 'company-a', 'branch_id' => 'hospitality-a', 'production_order_id' => 'retail-order-a', 'product_id' => 'material-a', 'issued_quantity' => 50, 'total_cost' => 500],
            ['id' => 'foreign-input', 'company_id' => 'company-b', 'branch_id' => 'foreign-a', 'production_order_id' => 'retail-order-a', 'product_id' => 'material-a', 'issued_quantity' => 100, 'total_cost' => 1000],
        ]);
    }
}
