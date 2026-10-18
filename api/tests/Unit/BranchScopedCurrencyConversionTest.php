<?php

namespace Tests\Unit;

use App\Services\Sales\CreditCustomerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Models\Currency;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

use function App\Helpers\currencyConverter;

class BranchScopedCurrencyConversionTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for branch currency tests.');
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

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('currency_id')->index();
            $table->decimal('rate', 18, 6);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->seedBranchCurrencies();
        $this->seedGlobalCurrenciesWithBranchRates();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('currency_rates');
            Schema::dropIfExists('currencies');
        }

        parent::tearDown();
    }

    public function test_branch_currency_rows_are_authoritative_with_global_fallback(): void
    {
        $branchA = Currency::query()
            ->where('company_id', 'company-a')
            ->where('code', 'USD')
            ->forBranchOrGlobal('branch-a')
            ->firstOrFail();
        $branchB = Currency::query()
            ->where('company_id', 'company-a')
            ->where('code', 'USD')
            ->forBranchOrGlobal('branch-b')
            ->firstOrFail();
        $fallback = Currency::query()
            ->where('company_id', 'company-a')
            ->where('code', 'USD')
            ->forBranchOrGlobal('branch-c')
            ->firstOrFail();

        $this->assertSame('usd-a', $branchA->id);
        $this->assertSame('usd-b', $branchB->id);
        $this->assertSame('usd-global', $fallback->id);
        $this->assertSame(30.0, currencyConverter('USD', 'ZWG', 1, 'company-a', 'branch-a'));
        $this->assertSame(40.0, currencyConverter('USD', 'ZWG', 1, 'company-a', 'branch-b'));
        $this->assertSame(25.0, currencyConverter('USD', 'ZWG', 1, 'company-a', 'branch-c'));
        $this->assertSame(25.0, currencyConverter('USD', 'ZWG', 1, 'company-a'));
    }

    public function test_latest_rates_are_selected_inside_the_requested_branch_only(): void
    {
        // EUR and GBP have company-global currency rows with distinct branch
        // rate histories. Newer rows in another branch must never participate.
        $this->assertSame(0.9, currencyConverter('EUR', 'GBP', 1, 'company-a', 'branch-a'));
        $this->assertSame(0.5, currencyConverter('EUR', 'GBP', 1, 'company-a', 'branch-b'));
        $this->assertSame(0.8, currencyConverter('EUR', 'GBP', 1, 'company-a', 'branch-c'));
    }

    public function test_sales_conversion_entry_points_keep_branch_a_and_branch_b_rates_separate(): void
    {
        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $exchangeRate = new ReflectionMethod($controller, 'getExchangeRate');
        $exchangeRate->setAccessible(true);

        $this->assertSame(30.0, $exchangeRate->invoke($controller, 'USD', 'ZWG', 'company-a', 'branch-a'));
        $this->assertSame(40.0, $exchangeRate->invoke($controller, 'USD', 'ZWG', 'company-a', 'branch-b'));

        $creditService = new CreditCustomerService();
        $convertAmount = new ReflectionMethod($creditService, 'convertAmount');
        $convertAmount->setAccessible(true);

        $this->assertSame(30.0, $convertAmount->invoke($creditService, 'USD', 'ZWG', 1, 'company-a', 'branch-a'));
        $this->assertSame(40.0, $convertAmount->invoke($creditService, 'USD', 'ZWG', 1, 'company-a', 'branch-b'));

        $modifierHarness = new class
        {
            use \Modules\Sales\Http\Controllers\Concerns\FastFoodSalesIntegration;

            public function total(array $customisation, string $branchId): float
            {
                return $this->fastFoodModifierUnitPriceForSale(
                    $customisation,
                    'ZWG',
                    'company-a',
                    $branchId
                );
            }
        };

        $modifier = ['modifiers' => [[
            'price' => 1,
            'quantity' => 1,
            'currency_code' => 'USD',
        ]]];

        $this->assertSame(30.0, $modifierHarness->total($modifier, 'branch-a'));
        $this->assertSame(40.0, $modifierHarness->total($modifier, 'branch-b'));
    }

    public function test_active_sale_paths_propagate_the_till_or_sale_branch(): void
    {
        $store = $this->methodSource(SalesController::class, 'store');
        $receiptLines = $this->methodSource(SalesController::class, 'createReceiptLines');
        $creditSale = $this->methodSource(CreditCustomerService::class, 'postCreditSale');
        $creditPayment = $this->methodSource(CreditCustomerService::class, 'recordPayment');

        $this->assertGreaterThanOrEqual(2, substr_count($store, '->forBranchOrGlobal($till->branch_id)'));
        $this->assertStringContainsString('branchId: $till->branch_id', $store);
        $this->assertStringContainsString('$till->branch_id', $store);
        $this->assertStringContainsString('$branchId', $receiptLines);
        $this->assertStringContainsString('$sale->branch_id', $creditSale);
        $this->assertGreaterThanOrEqual(3, substr_count($creditPayment, "\$data['branch_id'] ?? null"));
    }

    private function seedBranchCurrencies(): void
    {
        $this->insertCurrency('usd-global', null, 'USD', true);
        $this->insertCurrency('zwg-global', null, 'ZWG');
        $this->insertCurrency('usd-a', 'branch-a', 'USD', true);
        $this->insertCurrency('zwg-a', 'branch-a', 'ZWG');
        $this->insertCurrency('usd-b', 'branch-b', 'USD', true);
        $this->insertCurrency('zwg-b', 'branch-b', 'ZWG');

        $this->insertRate('usd-global-rate', 'usd-global', null, 1, '2026-10-07 12:00:00');
        $this->insertRate('zwg-global-rate', 'zwg-global', null, 25, '2026-10-07 12:00:00');
        $this->insertRate('usd-a-rate', 'usd-a', 'branch-a', 2, '2026-10-07 10:00:00');
        $this->insertRate('zwg-a-rate', 'zwg-a', 'branch-a', 60, '2026-10-07 10:00:00');
        $this->insertRate('usd-b-rate', 'usd-b', 'branch-b', 4, '2026-10-07 14:00:00');
        $this->insertRate('zwg-b-rate', 'zwg-b', 'branch-b', 160, '2026-10-07 14:00:00');

        $this->insertForeignCompanyCurrency('foreign-usd', 'USD', 1);
        $this->insertForeignCompanyCurrency('foreign-zwg', 'ZWG', 999);
    }

    private function seedGlobalCurrenciesWithBranchRates(): void
    {
        $this->insertCurrency('eur-global', null, 'EUR');
        $this->insertCurrency('gbp-global', null, 'GBP');

        $this->insertRate('eur-global-rate', 'eur-global', null, 1, '2026-10-07 18:00:00');
        $this->insertRate('gbp-global-rate', 'gbp-global', null, 0.8, '2026-10-07 18:00:00');
        $this->insertRate('eur-a-old', 'eur-global', 'branch-a', 2, '2026-10-07 09:00:00');
        $this->insertRate('eur-a-latest', 'eur-global', 'branch-a', 3, '2026-10-07 10:00:00');
        $this->insertRate('gbp-a-latest', 'gbp-global', 'branch-a', 2.7, '2026-10-07 10:00:00');
        $this->insertRate('eur-b-latest', 'eur-global', 'branch-b', 4, '2026-10-07 17:00:00');
        $this->insertRate('gbp-b-latest', 'gbp-global', 'branch-b', 2, '2026-10-07 17:00:00');
    }

    private function insertCurrency(string $id, ?string $branchId, string $code, bool $isBase = false): void
    {
        DB::table('currencies')->insert([
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => $branchId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'is_base_currency' => $isBase,
            'created_at' => '2026-10-07 08:00:00',
            'updated_at' => '2026-10-07 08:00:00',
        ]);
    }

    private function insertRate(
        string $id,
        string $currencyId,
        ?string $branchId,
        float $rate,
        string $createdAt
    ): void {
        DB::table('currency_rates')->insert([
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => $branchId,
            'currency_id' => $currencyId,
            'rate' => $rate,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertForeignCompanyCurrency(string $id, string $code, float $rate): void
    {
        DB::table('currencies')->insert([
            'id' => $id,
            'company_id' => 'company-b',
            'branch_id' => null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'is_base_currency' => $code === 'USD',
            'created_at' => '2026-10-07 20:00:00',
            'updated_at' => '2026-10-07 20:00:00',
        ]);

        DB::table('currency_rates')->insert([
            'id' => $id.'-rate',
            'company_id' => 'company-b',
            'branch_id' => null,
            'currency_id' => $id,
            'rate' => $rate,
            'created_at' => '2026-10-07 20:00:00',
            'updated_at' => '2026-10-07 20:00:00',
        ]);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
