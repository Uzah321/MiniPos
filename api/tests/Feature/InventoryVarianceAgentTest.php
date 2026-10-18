<?php

namespace Tests\Feature;

use App\Jobs\ContinueInventoryVarianceScan;
use App\Jobs\ScanInventoryVariance;
use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryVarianceAgentTest extends TestCase
{
    private ?object $varianceMigration = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for the in-memory variance lifecycle test.');
        }

        Schema::create('general_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('setting_key');
            $table->longText('setting_value')->nullable();
            $table->string('setting_type');
            $table->string('module')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->string('stock_area', 20);
            $table->decimal('quantity_on_hand', 20, 6);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->string('stock_area', 20)->nullable();
            $table->string('movement_type');
            $table->decimal('quantity_in', 20, 6)->default(0);
            $table->decimal('quantity_out', 20, 6)->default(0);
            $table->decimal('balance_after', 20, 6)->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->timestamps();
        });
        Schema::create('product_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->decimal('average_cost', 20, 6)->default(0);
            $table->decimal('last_cost', 20, 6)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->string('currency', 8)->nullable();
        });

        $this->varianceMigration = require base_path('Modules/Product/database/migrations/2026_10_10_150000_create_inventory_variance_agent_tables.php');
        $this->varianceMigration->up();
    }

    protected function tearDown(): void
    {
        if ($this->varianceMigration) {
            $this->varianceMigration->down();
            Schema::dropIfExists('company_branches');
            Schema::dropIfExists('companies');
            Schema::dropIfExists('product_costs');
            Schema::dropIfExists('stock_movements');
            Schema::dropIfExists('product_stocks');
            Schema::dropIfExists('general_settings');
        }
        parent::tearDown();
    }

    public function test_it_keeps_company_branch_and_area_cases_separate_and_requires_a_reset(): void
    {
        $company = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $branch = (string) Str::uuid();
        $otherBranch = (string) Str::uuid();
        $product = (string) Str::uuid();
        $service = app(InventoryVarianceAgentService::class);
        $service->updateSettings($company, ['enabled' => true], null);

        $this->stock($company, $branch, $product, 'front', 10);
        $this->movement($company, $branch, $product, 'front', 10, 0);
        $this->stock($company, $branch, $product, 'back', 20);
        $this->movement($company, $branch, $product, 'back', 20, 0);
        $this->stock($company, $otherBranch, $product, 'front', 30);
        $this->stock($otherCompany, $branch, $product, 'front', 40);

        $first = $service->scanCompany($company, [$branch], null, 'manual');
        $this->assertSame(2, $first['baselines_created']);
        $this->assertSame(0, DB::table('inventory_variances')->count());

        $this->movement($company, $branch, $product, 'front', 0, 2);
        $this->setStock($company, $branch, $product, 'front', 8);
        $service->scanCompany($company, [$branch], null, 'manual');
        $this->assertSame(0, DB::table('inventory_variances')->count());

        $this->setStock($company, $branch, $product, 'front', 7);
        $this->setStock($company, $branch, $product, 'back', 18);
        $result = $service->scanCompany($company, [$branch], null, 'manual');
        $this->assertSame(2, $result['opened']);
        $this->assertSame(2, DB::table('inventory_variances')->where('company_id', $company)->where('status', 'open')->count());
        $front = DB::table('inventory_variances')->where('stock_area', 'front')->first();
        $this->assertEquals(-1, (float) $front->variance_quantity);
        $this->assertArrayHasKey('detection_window', json_decode($front->evidence, true));

        $service->scanCompany($company, [$branch], null, 'manual');
        $this->assertSame(2, DB::table('inventory_variances')->count());

        DB::transaction(function () use ($service, $company, $branch, $product): void {
            $this->setStock($company, $branch, $product, 'front', 8);
            $this->movement($company, $branch, $product, 'front', 1, 0);
            $service->resetProduct($company, $branch, $product, 'stock_adjustment',
                'stock_adjustment', 'ADJ-1', null, 'front');
        });
        $this->assertSame('resolved', DB::table('inventory_variances')->where('id', $front->id)->value('status'));
        $this->assertSame(1, DB::table('inventory_variances')->where('status', 'open')->count());
        $this->assertSame('back', DB::table('inventory_variances')->where('status', 'open')->value('stock_area'));
        $service->resetProduct($company, $branch, $product, 'stock_adjustment',
            'stock_adjustment', 'ADJ-1', null, 'front');
        $this->assertSame(3, DB::table('inventory_variance_cycles')->where('company_id', $company)->count());

        $service->scanCompany($company, [$branch], null, 'manual');
        $this->assertSame(2, DB::table('inventory_variances')->count());
        $this->assertSame(0, DB::table('inventory_variances')->where('company_id', $otherCompany)->count());
    }

    public function test_large_company_scans_continue_in_bounded_queue_batches(): void
    {
        Queue::fake();
        $company = (string) Str::uuid();
        $branch = (string) Str::uuid();
        $service = app(InventoryVarianceAgentService::class);
        $service->updateSettings($company, ['enabled' => true], null);
        for ($i = 0; $i < 251; $i++) {
            $this->stock($company, $branch, (string) Str::uuid(), 'front', 1);
        }

        $first = $service->scanCompany($company, [$branch], null, 'manual');
        $this->assertSame(250, $first['scanned']);
        $run = DB::table('inventory_variance_scan_runs')->where('id', $first['scan_id'])->first();
        $this->assertSame('running', $run->status);
        $this->assertSame(250, (int) $run->products_scanned);
        Queue::assertPushed(ContinueInventoryVarianceScan::class, 1);

        $service->scanCompany($company, [$branch], null, 'manual', $run->id, $run->last_stock_id);
        $finished = DB::table('inventory_variance_scan_runs')->where('id', $run->id)->first();
        $this->assertSame('completed', $finished->status);
        $this->assertSame(251, (int) $finished->products_scanned);
        $this->assertSame(251, DB::table('inventory_variance_cycles')->where('company_id', $company)->count());
    }

    public function test_scheduler_queues_only_enabled_company_scope(): void
    {
        Queue::fake();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->softDeletes();
        });
        $enabled = (string) Str::uuid();
        $disabled = (string) Str::uuid();
        $branch = (string) Str::uuid();
        DB::table('companies')->insert([['id' => $enabled], ['id' => $disabled]]);
        DB::table('company_branches')->insert([
            'id' => $branch, 'company_id' => $enabled,
        ]);
        $service = app(InventoryVarianceAgentService::class);
        $service->updateSettings($enabled, ['enabled' => true]);
        $service->updateSettings($disabled, ['enabled' => false]);

        $this->artisan('inventory:variance-scan-queue')->assertExitCode(0);
        Queue::assertPushed(ScanInventoryVariance::class, 1);
        $this->assertSame(1, DB::table('inventory_variance_scan_runs')->where('status', 'queued')->count());
        $this->assertSame($enabled, DB::table('inventory_variance_scan_runs')->value('company_id'));
        $runId = DB::table('inventory_variance_scan_runs')->value('id');
        $service->updateSettings($enabled, ['enabled' => false]);
        $service->scanCompany($enabled, [$branch], null, 'scheduled', $runId);
        $this->assertSame('cancelled', DB::table('inventory_variance_scan_runs')->where('id', $runId)->value('status'));
    }

    private function stock(string $company, string $branch, string $product, string $area, float $quantity): void
    {
        DB::table('product_stocks')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company,
            'branch_id' => $branch, 'product_id' => $product,
            'stock_area' => $area, 'quantity_on_hand' => $quantity,
        ]);
    }

    private function setStock(string $company, string $branch, string $product, string $area, float $quantity): void
    {
        DB::table('product_stocks')->where('company_id', $company)
            ->where('branch_id', $branch)->where('product_id', $product)
            ->where('stock_area', $area)->update(['quantity_on_hand' => $quantity]);
    }

    private function movement(string $company, string $branch, string $product, string $area, float $in, float $out): void
    {
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company,
            'branch_id' => $branch, 'product_id' => $product,
            'stock_area' => $area, 'movement_type' => 'adjustment',
            'quantity_in' => $in, 'quantity_out' => $out,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
