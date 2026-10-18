<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\BackStockMonthEndStockTakeController;
use Modules\Product\Http\Controllers\FrontStockDailyClosingController;
use ReflectionMethod;
use Tests\TestCase;

class HospitalityStockTakeModeBoundaryTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for hospitality stock-take tests.');
        }

        $this->databaseReady = true;
        $this->createSchema();
        app()->instance('company', (object) ['id' => 'company-a']);

        DB::table('company_branches')->insert([
            ['id' => 'branch-a', 'company_id' => 'company-a'],
            ['id' => 'branch-b', 'company_id' => 'company-a'],
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');

        if ($this->databaseReady) {
            foreach (array_reverse($this->tables()) as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_front_stock_closing_list_and_detail_are_limited_to_the_active_hospitality_branch(): void
    {
        DB::table('front_stock_daily_closings')->insert([
            $this->frontClosing('front-a', 'branch-a', 'front'),
            $this->frontClosing('front-b', 'branch-b', 'front'),
            $this->frontClosing('back-a', 'branch-a', 'back'),
        ]);

        $request = $this->request('/front-stock/daily-closings', 'GET', 'branch-a');
        $controller = new FrontStockDailyClosingController;

        $ids = collect($controller->index($request)->getData(true)['data'])
            ->pluck('id')
            ->all();

        $this->assertSame(['front-a'], $ids);
        $this->assertSame('front-a', $controller->show($request, 'front-a')->getData(true)['data']['id']);
        $this->expectException(ModelNotFoundException::class);
        $controller->show($request, 'front-b');
    }

    public function test_back_stock_detail_cannot_cross_the_active_hospitality_branch(): void
    {
        DB::table('back_stock_month_end_counts')->insert([
            $this->backCount('back-a', 'branch-a'),
            $this->backCount('back-b', 'branch-b'),
        ]);

        $request = $this->request('/stock-takes/month-end/back-stock/back-a', 'GET', 'branch-a');
        $controller = new BackStockMonthEndStockTakeController;

        $this->assertSame(200, $controller->show($request, 'back-a')->getStatusCode());
        $this->assertSame(404, $controller->show($request, 'back-b')->getStatusCode());
    }

    public function test_conflicting_hospitality_branch_context_is_rejected_before_either_stock_take_runs(): void
    {
        $request = $this->request(
            '/hospitality-stock-take',
            'POST',
            'branch-a',
            ['branch_id' => 'branch-b']
        );

        foreach ([new FrontStockDailyClosingController, new BackStockMonthEndStockTakeController] as $controller) {
            try {
                $this->method($controller, 'resolveBranchId')->invoke($controller, $request);
                $this->fail('Conflicting active and submitted branch contexts must be rejected.');
            } catch (HttpResponseException $exception) {
                $this->assertSame(409, $exception->getResponse()->getStatusCode());
                $this->assertSame('BRANCH_CONTEXT_MISMATCH', $exception->getResponse()->getData(true)['code']);
            }
        }
    }

    public function test_front_and_back_stock_take_routes_are_registered(): void
    {
        $routeSource = file_get_contents(base_path('Modules/Product/routes/api.php'));

        foreach ([
            '/front-stock/daily-closings',
            '/front-stock/daily-closings/snapshot',
            '/stock-takes/month-end/back-stock/snapshot',
            '/stock-takes/month-end/back-stock/download-balance-snapshot',
            '/stock-takes/month-end/back-stock/download-trading-count-sheet',
            '/stock-takes/month-end/back-stock/begin-trading',
            '/stock-takes/month-end/back-stock/upload-trading-count-sheets',
        ] as $path) {
            $this->assertStringContainsString($path, $routeSource);
        }
    }

    private function method(object $controller, string $name): ReflectionMethod
    {
        $method = new ReflectionMethod($controller, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function request(
        string $uri,
        string $method,
        string $branchId,
        array $parameters = []
    ): Request {
        $request = Request::create($uri, $method, $parameters);
        $request->headers->set('X-Branch-ID', $branchId);
        $request->setUserResolver(function () {
            return new class
            {
                public string $branch_id = 'branch-a';

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
                            return collect(['branch-a', 'branch-b']);
                        }
                    };
                }
            };
        });

        return $request;
    }

    private function frontClosing(string $id, string $branchId, string $stockArea): array
    {
        return [
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => $branchId,
            'closing_date' => '2026-10-02',
            'stock_area' => $stockArea,
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function backCount(string $id, string $branchId): array
    {
        return [
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => $branchId,
            'month_end_date' => '2026-10-31',
            'stock_area' => 'back',
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createSchema(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('company_branches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->softDeletes();
        });
        Schema::create('front_stock_daily_closings', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->date('closing_date');
            $table->string('stock_area');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('front_stock_daily_closing_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('front_stock_daily_closing_id');
            $table->string('product_name')->nullable();
        });
        Schema::create('back_stock_month_end_counts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->date('month_end_date');
            $table->string('stock_area');
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('back_stock_month_end_count_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('back_stock_month_end_count_id');
            $table->string('product_name')->nullable();
        });
    }

    /** @return array<int, string> */
    private function tables(): array
    {
        return [
            'company_branches',
            'front_stock_daily_closings',
            'front_stock_daily_closing_lines',
            'back_stock_month_end_counts',
            'back_stock_month_end_count_lines',
        ];
    }
}
