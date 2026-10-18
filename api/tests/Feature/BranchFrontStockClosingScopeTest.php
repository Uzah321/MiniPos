<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\TradingDayEndController;
use ReflectionMethod;
use Tests\TestCase;

class BranchFrontStockClosingScopeTest extends TestCase
{
    private bool $sqliteAvailable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqliteAvailable = in_array('sqlite', \PDO::getAvailableDrivers(), true);
        if (!$this->sqliteAvailable) {
            return;
        }

        Schema::dropIfExists('front_stock_daily_closings');
        Schema::create('front_stock_daily_closings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->date('closing_date');
            $table->string('stock_area');
            $table->string('status')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if ($this->sqliteAvailable) {
            Schema::dropIfExists('front_stock_daily_closings');
        }

        parent::tearDown();
    }

    public function test_one_tills_closing_satisfies_every_till_in_the_same_branch_only(): void
    {
        if (!$this->sqliteAvailable) {
            // The Windows PHP runtime used by the repository may omit
            // pdo_sqlite. Keep a runnable contract assertion here while CI
            // environments with SQLite execute the full query test below.
            $method = new ReflectionMethod(
                TradingDayEndController::class,
                'frontStockClosingExists'
            );
            $lines = file($method->getFileName());
            $source = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            $this->assertStringContainsString("->where('company_id', \$companyId)", $source);
            $this->assertStringContainsString("->where('branch_id', \$branchId)", $source);
            $this->assertStringContainsString("->whereDate('closing_date', \$date)", $source);
            $this->assertStringNotContainsString("->where('till_id'", $source);

            return;
        }

        $company = '00000000-0000-0000-0000-000000000001';
        $branchA = '00000000-0000-0000-0000-000000000002';
        $branchB = '00000000-0000-0000-0000-000000000003';
        $branchWithoutClosing = '00000000-0000-0000-0000-000000000004';
        $closedAt = now();

        DB::table('front_stock_daily_closings')->insert([
            [
                'id' => '00000000-0000-0000-0000-000000000101',
                'company_id' => $company,
                'branch_id' => $branchA,
                // Till 1 captured it. The Day End query deliberately has no
                // till predicate, so Till 2 in branch A sees the same close.
                'till_id' => '00000000-0000-0000-0000-000000000201',
                'closing_date' => '2026-10-07',
                'stock_area' => 'front',
                'status' => 'closed',
                'metadata' => '{}',
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000102',
                'company_id' => $company,
                'branch_id' => $branchB,
                'till_id' => '00000000-0000-0000-0000-000000000202',
                'closing_date' => '2026-10-07',
                'stock_area' => 'front',
                'status' => 'closed',
                'metadata' => '{}',
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000103',
                'company_id' => $company,
                'branch_id' => $branchA,
                'till_id' => '00000000-0000-0000-0000-000000000201',
                'closing_date' => '2026-10-08',
                'stock_area' => 'front',
                'status' => 'draft',
                'metadata' => '{}',
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ],
        ]);

        $exists = new ReflectionMethod(TradingDayEndController::class, 'frontStockClosingExists');
        $exists->setAccessible(true);
        $controller = new TradingDayEndController;

        $this->assertTrue($exists->invoke($controller, $company, $branchA, '2026-10-07'));
        $this->assertTrue($exists->invoke($controller, $company, $branchB, '2026-10-07'));
        $this->assertFalse($exists->invoke($controller, $company, $branchWithoutClosing, '2026-10-07'));
        $this->assertFalse($exists->invoke($controller, $company, $branchA, '2026-10-08'));
        $this->assertFalse($exists->invoke(
            $controller,
            '00000000-0000-0000-0000-000000000999',
            $branchA,
            '2026-10-07'
        ));
    }
}
