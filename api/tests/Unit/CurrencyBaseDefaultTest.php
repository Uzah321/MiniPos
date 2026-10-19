<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Http\Controllers\CurrencyController;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A shop's only active currency must become its base currency; otherwise
 * every sale fails with "Base currency is not configured".
 */
class CurrencyBaseDefaultTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for currency tests.');
        }

        $this->databaseReady = true;

        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('code', 3);
            $table->string('name');
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_base_currency')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('currencies');
        }

        parent::tearDown();
    }

    public function test_branch_without_any_base_currency_needs_one(): void
    {
        $this->currency('usd-a', 'branch-a', 'USD', base: false);
        $this->currency('usd-b', 'branch-b', 'USD', base: true);

        $this->assertFalse($this->hasOtherBase('branch-a'));
    }

    public function test_branch_or_company_wide_active_base_counts(): void
    {
        $this->currency('usd-a', 'branch-a', 'USD', base: true);
        $this->currency('usd-global', null, 'USD', base: true);

        $this->assertTrue($this->hasOtherBase('branch-a'));
        $this->assertTrue($this->hasOtherBase('branch-c'), 'A company-wide base covers every shop.');
    }

    public function test_inactive_deleted_and_edited_currencies_do_not_count(): void
    {
        $this->currency('usd-inactive', 'branch-a', 'USD', base: true, active: false);
        $this->currency('zwg-deleted', 'branch-a', 'ZWG', base: true, deleted: true);
        $this->currency('zar-edited', 'branch-a', 'ZAR', base: true);

        $this->assertFalse($this->hasOtherBase('branch-a', except: 'zar-edited'));
        $this->assertTrue($this->hasOtherBase('branch-a'));
    }

    public function test_company_wide_scope_only_considers_company_wide_currencies(): void
    {
        $this->currency('usd-a', 'branch-a', 'USD', base: true);

        $this->assertFalse($this->hasOtherBase(null));
    }

    private function hasOtherBase(?string $branchId, ?string $except = null): bool
    {
        $controller = (new ReflectionClass(CurrencyController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CurrencyController::class, 'hasOtherActiveBaseCurrency');
        $method->setAccessible(true);

        return $method->invoke($controller, 'company-a', $branchId, $except);
    }

    private function currency(string $id, ?string $branchId, string $code, bool $base, bool $active = true, bool $deleted = false): void
    {
        DB::table('currencies')->insert([
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => $branchId,
            'code' => $code,
            'name' => $code,
            'is_active' => $active,
            'is_base_currency' => $base,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
