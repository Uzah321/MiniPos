<?php

namespace Tests\Feature;

use App\Services\CompanySettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Services\HospitalityOrderNumberService;
use PDO;
use Tests\TestCase;

class HospitalityOrderNumberServiceTest extends TestCase
{
    private const COMPANY_ID = '019f9d00-0000-7000-8000-000000000001';

    private const BRANCH_ID = '019f9d00-0000-7000-8000-000000000002';

    private bool $databaseAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is required for this isolated service test.');
        }

        $this->databaseAvailable = true;
        $this->createTables();
        $this->seedSettings();
    }

    protected function tearDown(): void
    {
        if ($this->databaseAvailable) {
            foreach (['sales_transactions', 'fast_food_order_sequences', 'general_settings'] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_public_numbers_start_at_a_persisted_daily_offset_then_increment(): void
    {
        $service = $this->deterministicService(100);

        $first = $service->allocate(self::COMPANY_ID, self::BRANCH_ID, '2026-10-13', 'day-1');
        $second = $service->allocate(self::COMPANY_ID, self::BRANCH_ID, '2026-10-13', 'day-1');

        $this->assertSame('SN-00100', $first['order_number']);
        $this->assertSame(100, $first['order_sequence']);
        $this->assertSame('day-1', $first['trading_day_id']);
        $this->assertSame('SN-00101', $second['order_number']);
        $this->assertSame(101, $second['order_sequence']);

        $sequence = DB::table('fast_food_order_sequences')->first();
        $this->assertSame(100, (int) $sequence->start_number);
        $this->assertSame(101, (int) $sequence->last_number);
        $this->assertSame(5, (int) $sequence->number_width);
        $this->assertSame('day-1', $sequence->trading_day_id);
    }

    public function test_existing_public_number_is_skipped_without_breaking_sequence(): void
    {
        $service = $this->deterministicService(100);
        $service->allocate(self::COMPANY_ID, self::BRANCH_ID, '2026-10-13');

        DB::table('sales_transactions')->insert([
            'id' => '019f9d00-0000-7000-8000-000000000099',
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => null,
            'order_number' => 'SN-00101',
            'order_business_date' => '2026-10-13',
        ]);

        $next = $service->allocate(self::COMPANY_ID, self::BRANCH_ID, '2026-10-13');

        $this->assertSame('SN-00102', $next['order_number']);
        $this->assertSame(102, $next['order_sequence']);
    }

    public function test_each_trading_date_has_its_own_seed_row(): void
    {
        $service = $this->deterministicService(100);
        $service->allocate(self::COMPANY_ID, self::BRANCH_ID, '2026-10-13');
        $service->allocate(self::COMPANY_ID, self::BRANCH_ID, '2026-10-14');

        $this->assertSame(2, DB::table('fast_food_order_sequences')->count());
        $this->assertSame(
            [100, 100],
            DB::table('fast_food_order_sequences')->orderBy('business_date')->pluck('start_number')
                ->map(fn ($value) => (int) $value)->all()
        );
    }

    private function deterministicService(int $seed): HospitalityOrderNumberService
    {
        return new class(new CompanySettingService, $seed) extends HospitalityOrderNumberService
        {
            public function __construct(CompanySettingService $settings, private readonly int $seed)
            {
                parent::__construct($settings);
            }

            protected function randomStart(int $minimum, int $maximum): int
            {
                return max($minimum, min($maximum, $this->seed));
            }
        };
    }

    private function createTables(): void
    {
        Schema::dropIfExists('sales_transactions');
        Schema::dropIfExists('fast_food_order_sequences');
        Schema::dropIfExists('general_settings');

        Schema::create('general_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('setting_key');
            $table->text('setting_value')->nullable();
            $table->string('setting_type');
            $table->string('module')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fast_food_order_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('trading_day_id')->nullable();
            $table->date('business_date');
            $table->string('prefix', 30)->default('ORD');
            $table->unsignedInteger('start_number')->nullable();
            $table->unsignedInteger('start_minimum')->nullable();
            $table->unsignedInteger('start_maximum')->nullable();
            $table->unsignedTinyInteger('number_width')->default(4);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'business_date']);
        });

        Schema::create('sales_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->string('order_number')->nullable();
            $table->date('order_business_date')->nullable();
            $table->softDeletes();
        });
    }

    private function seedSettings(): void
    {
        $settings = [
            ['hospitality_order_start_minimum', '20', 'integer'],
            ['hospitality_order_start_maximum', '899', 'integer'],
            ['hospitality_order_prefix', 'SN!', 'string'],
            ['hospitality_order_number_width', '5', 'integer'],
        ];

        foreach ($settings as $index => [$key, $value, $type]) {
            DB::table('general_settings')->insert([
                'id' => sprintf('019f9d00-0000-7000-8000-%012d', $index + 10),
                'company_id' => self::COMPANY_ID,
                'branch_id' => null,
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_type' => $type,
                'module' => 'hospitality',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
