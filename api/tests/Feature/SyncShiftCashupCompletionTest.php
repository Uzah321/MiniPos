<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeSyncController;
use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Services\Sync\SyncEventSetProof;
use App\Services\Sync\SyncPayloadHasher;
use App\Services\Sync\SyncSaleBundleService;
use App\Services\Sync\SyncSaleImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

class SyncShiftCashupCompletionTest extends TestCase
{
    private const COMPANY = '10000000-0000-7000-8000-000000000001';

    private const BRANCH = '10000000-0000-7000-8000-000000000002';

    private const USER = '10000000-0000-7000-8000-000000000003';

    private const TILL = '10000000-0000-7000-8000-000000000004';

    private const NODE = '10000000-0000-7000-8000-000000000005';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
        });
        Schema::create('user_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('pos_user_id');
            $table->uuid('branch_id');
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
        });
        Schema::create('pos_user_shift_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('pos_user_id');
            $table->uuid('shift_configuration_id')->nullable();
            $table->string('shift_name');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 18, 4)->nullable();
            $table->decimal('closing_cash', 18, 4)->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });
        Schema::create('pos_cashups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_configuration_id')->nullable();
            $table->uuid('shift_session_id');
            $table->uuid('cashier_id');
            $table->uuid('closed_by')->nullable();
            $table->string('business_mode')->default('retail');
            $table->date('trading_date')->nullable();
            $table->string('cashup_period_key', 512)->nullable();
            $table->timestamp('cashup_opened_at')->nullable();
            $table->timestamp('cashup_closed_at')->nullable();
            $table->string('cashup_currency_code', 10)->nullable();
            $table->decimal('counted_cash', 18, 4)->default(0);
            $table->integer('transaction_count')->default(0);
            $table->json('payment_summary')->nullable();
            $table->string('status')->default('closed');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'shift_session_id']);
        });
        Schema::create('pos_cashup_denominations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('cashup_id');
            $table->string('currency_code', 10);
            $table->decimal('denomination_value', 18, 4);
            $table->integer('quantity');
            $table->decimal('line_total', 18, 4);
            $table->string('denomination_type');
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['cashup_id', 'currency_code', 'denomination_value'],
                'cashup_denomination_natural_unique'
            );
        });
        Schema::create('sales_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_session_id')->nullable();
            $table->string('status');
            $table->string('client_sale_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        foreach (['receipt_lines', 'sales_transaction_payments'] as $table) {
            Schema::create($table, function (Blueprint $definition): void {
                $definition->uuid('id')->primary();
                $definition->uuid('company_id');
                $definition->uuid('branch_id');
                $definition->uuid('sales_transaction_id')->nullable();
            });
        }
        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable()->unique();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->unsignedBigInteger('push_cursor')->default(0);
            $table->unsignedBigInteger('pull_cursor')->default(0);
            $table->string('status')->default('paired');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('source_node_id')->nullable();
            $table->string('aggregate_type', 96);
            $table->string('aggregate_id', 64);
            $table->string('event_type', 64);
            $table->string('deduplication_key', 128)->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('available_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['source_node_id', 'deduplication_key']);
        });

        DB::table('pos_users')->insert([
            'id' => self::USER,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
        ]);
        DB::table('pos_tills')->insert([
            'id' => self::TILL,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'sync_outbox_events',
            'sync_local_states',
            'sales_transaction_payments',
            'receipt_lines',
            'sales_transactions',
            'pos_cashup_denominations',
            'pos_cashups',
            'pos_user_shift_sessions',
            'pos_tills',
            'user_branches',
            'pos_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_duplicate_sale_reconciles_shift_closure_and_cashup_idempotently_without_overwriting_opened_at(): void
    {
        $shiftId = '20000000-0000-7000-8000-000000000001';
        $saleId = '20000000-0000-7000-8000-000000000002';
        $cashupId = '20000000-0000-7000-8000-000000000003';
        $denominationId = '20000000-0000-7000-8000-000000000004';
        DB::table('pos_user_shift_sessions')->insert($this->openShift($shiftId, '2026-10-03 08:00:00'));
        DB::table('sales_transactions')->insert([
            'id' => $saleId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'shift_session_id' => $shiftId,
            'status' => 'completed',
            'invoice_number' => 'EDGE-1',
            'created_at' => '2026-10-03 09:00:00',
            'updated_at' => '2026-10-03 09:00:00',
        ]);

        $payload = $this->completionPayload($shiftId, $cashupId, $denominationId);
        $payload['cashup']['transaction_count'] = 1;
        $payload['sale'] = [
            'id' => $saleId,
            'status' => 'completed',
            'till_id' => self::TILL,
            'shift_session_id' => $shiftId,
            'invoice_number' => 'EDGE-1',
        ];
        $payload['receipt_lines'] = [];
        $payload['payments'] = [];

        $service = new SyncSaleImportService;
        $first = $service->import($this->node(), $payload);
        $second = $service->import($this->node(), $payload);

        $this->assertTrue($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame('2026-10-03 08:00:00', DB::table('pos_user_shift_sessions')->where('id', $shiftId)->value('opened_at'));
        $this->assertDatabaseHas('pos_user_shift_sessions', [
            'id' => $shiftId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'status' => 'closed',
            'closed_at' => '2026-10-03 17:00:00',
        ]);
        $this->assertDatabaseHas('pos_cashups', [
            'id' => $cashupId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'shift_session_id' => $shiftId,
            'status' => 'closed',
        ]);
        $this->assertDatabaseHas('pos_cashup_denominations', [
            'id' => $denominationId,
            'cashup_id' => $cashupId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
        ]);
        $this->assertSame(1, DB::table('pos_cashups')->count());
        $this->assertSame(1, DB::table('pos_cashup_denominations')->count());
    }

    public function test_zero_sale_shift_completion_is_durable_and_uses_cashup_opening_snapshot(): void
    {
        $shiftId = '30000000-0000-7000-8000-000000000001';
        $cashupId = '30000000-0000-7000-8000-000000000002';
        $denominationId = '30000000-0000-7000-8000-000000000003';
        $payload = $this->completionPayload($shiftId, $cashupId, $denominationId);

        $service = new SyncSaleImportService;
        $first = $service->importShiftCashup($this->node(), $payload);
        $second = $service->importShiftCashup($this->node(), $payload);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertDatabaseHas('pos_user_shift_sessions', [
            'id' => $shiftId,
            'opened_at' => '2026-10-03 08:00:00',
            'closed_at' => '2026-10-03 17:00:00',
            'status' => 'closed',
        ]);
        $this->assertSame(0, DB::table('sales_transactions')->where('shift_session_id', $shiftId)->count());
    }

    public function test_long_retail_period_key_survives_a_queued_completion_retry_without_duplicates(): void
    {
        $shiftId = '30500000-0000-7000-8000-000000000001';
        $cashupId = '30500000-0000-7000-8000-000000000002';
        $denominationId = '30500000-0000-7000-8000-000000000003';
        $payload = $this->completionPayload($shiftId, $cashupId, $denominationId);
        $periodKey = implode('|', [
            'RETAIL',
            self::COMPANY,
            self::BRANCH,
            self::TILL,
            self::USER,
            $shiftId,
            'USD',
            '202610030800',
            '202610031700',
        ]);

        $this->assertGreaterThan(160, strlen($periodKey));
        $payload['cashup']['business_mode'] = 'retail';
        $payload['cashup']['cashup_period_key'] = $periodKey;

        $service = new SyncSaleImportService;
        $first = $service->importShiftCashup($this->node(), $payload);
        $retry = $service->importShiftCashup($this->node(), $payload);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame(1, DB::table('pos_cashups')->count());
        $this->assertSame(
            $periodKey,
            DB::table('pos_cashups')->where('id', $cashupId)->value('cashup_period_key')
        );
    }

    public function test_shift_cashup_accepts_a_user_whose_primary_branch_matches_the_node(): void
    {
        $result = (new SyncSaleImportService)->importShiftCashup(
            $this->node(),
            $this->completionPayload(
                '31000000-0000-7000-8000-000000000001',
                '31000000-0000-7000-8000-000000000002',
                '31000000-0000-7000-8000-000000000003'
            )
        );

        $this->assertFalse($result['duplicate']);
    }

    public function test_shift_cashup_accepts_a_company_wide_user_with_no_primary_branch(): void
    {
        DB::table('pos_users')->where('id', self::USER)->update(['branch_id' => null]);

        $result = (new SyncSaleImportService)->importShiftCashup(
            $this->node(),
            $this->completionPayload(
                '32000000-0000-7000-8000-000000000001',
                '32000000-0000-7000-8000-000000000002',
                '32000000-0000-7000-8000-000000000003'
            )
        );

        $this->assertFalse($result['duplicate']);
    }

    public function test_shift_cashup_accepts_a_user_assigned_to_the_node_branch_through_user_branches(): void
    {
        DB::table('pos_users')->where('id', self::USER)->update([
            'branch_id' => '33000000-0000-7000-8000-000000000099',
        ]);
        DB::table('user_branches')->insert([
            'id' => '33000000-0000-7000-8000-000000000098',
            'company_id' => self::COMPANY,
            'pos_user_id' => self::USER,
            'branch_id' => self::BRANCH,
        ]);

        $result = (new SyncSaleImportService)->importShiftCashup(
            $this->node(),
            $this->completionPayload(
                '33000000-0000-7000-8000-000000000001',
                '33000000-0000-7000-8000-000000000002',
                '33000000-0000-7000-8000-000000000003'
            )
        );

        $this->assertFalse($result['duplicate']);
    }

    public function test_shift_cashup_retains_optional_closed_by_for_a_user_assigned_through_user_branches(): void
    {
        $closerId = '33500000-0000-7000-8000-000000000097';
        DB::table('pos_users')->insert([
            'id' => $closerId,
            'company_id' => self::COMPANY,
            'branch_id' => '33500000-0000-7000-8000-000000000099',
        ]);
        DB::table('user_branches')->insert([
            'id' => '33500000-0000-7000-8000-000000000098',
            'company_id' => self::COMPANY,
            'pos_user_id' => $closerId,
            'branch_id' => self::BRANCH,
        ]);
        $cashupId = '33500000-0000-7000-8000-000000000002';
        $payload = $this->completionPayload(
            '33500000-0000-7000-8000-000000000001',
            $cashupId,
            '33500000-0000-7000-8000-000000000003'
        );
        $payload['cashup']['closed_by'] = $closerId;

        $result = (new SyncSaleImportService)->importShiftCashup($this->node(), $payload);

        $this->assertFalse($result['duplicate']);
        $this->assertSame($closerId, DB::table('pos_cashups')->where('id', $cashupId)->value('closed_by'));
    }

    public function test_shift_cashup_rejects_cross_company_optional_closed_by_even_with_a_forged_assignment(): void
    {
        $closerId = '33600000-0000-7000-8000-000000000097';
        DB::table('pos_users')->insert([
            'id' => $closerId,
            'company_id' => '33600000-0000-7000-8000-000000000099',
            'branch_id' => self::BRANCH,
        ]);
        DB::table('user_branches')->insert([
            'id' => '33600000-0000-7000-8000-000000000098',
            'company_id' => self::COMPANY,
            'pos_user_id' => $closerId,
            'branch_id' => self::BRANCH,
        ]);
        $payload = $this->completionPayload(
            '33600000-0000-7000-8000-000000000001',
            '33600000-0000-7000-8000-000000000002',
            '33600000-0000-7000-8000-000000000003'
        );
        $payload['cashup']['closed_by'] = $closerId;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('closed_by reference belongs to another tenant');
        (new SyncSaleImportService)->importShiftCashup($this->node(), $payload);
    }

    public function test_shift_cashup_rejects_optional_closed_by_when_the_branch_assignment_company_is_forged(): void
    {
        $closerId = '33700000-0000-7000-8000-000000000097';
        DB::table('pos_users')->insert([
            'id' => $closerId,
            'company_id' => self::COMPANY,
            'branch_id' => '33700000-0000-7000-8000-000000000099',
        ]);
        DB::table('user_branches')->insert([
            'id' => '33700000-0000-7000-8000-000000000098',
            'company_id' => '33700000-0000-7000-8000-000000000096',
            'pos_user_id' => $closerId,
            'branch_id' => self::BRANCH,
        ]);
        $payload = $this->completionPayload(
            '33700000-0000-7000-8000-000000000001',
            '33700000-0000-7000-8000-000000000002',
            '33700000-0000-7000-8000-000000000003'
        );
        $payload['cashup']['closed_by'] = $closerId;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('closed_by reference belongs to another tenant');
        (new SyncSaleImportService)->importShiftCashup($this->node(), $payload);
    }

    public function test_missing_optional_reference_is_nulled_and_its_source_uuid_is_preserved(): void
    {
        $missingId = '33800000-0000-7000-8000-000000000097';
        $row = ['closed_by' => $missingId];
        $sourceReferences = [];
        $normalise = new ReflectionMethod(SyncSaleImportService::class, 'normaliseOptionalReference');

        $normalise->invokeArgs(new SyncSaleImportService, [
            &$row,
            'closed_by',
            'pos_users',
            $this->node(),
            &$sourceReferences,
        ]);

        $this->assertNull($row['closed_by']);
        $this->assertSame($missingId, $sourceReferences['closed_by']);
    }

    public function test_shift_cashup_rejects_a_user_from_another_branch_without_an_assignment(): void
    {
        DB::table('pos_users')->where('id', self::USER)->update([
            'branch_id' => '34000000-0000-7000-8000-000000000099',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('shift-session user belongs to another company or branch');
        (new SyncSaleImportService)->importShiftCashup(
            $this->node(),
            $this->completionPayload(
                '34000000-0000-7000-8000-000000000001',
                '34000000-0000-7000-8000-000000000002',
                '34000000-0000-7000-8000-000000000003'
            )
        );
    }

    public function test_shift_cashup_rejects_a_cross_company_user_even_with_a_forged_branch_assignment(): void
    {
        DB::table('pos_users')->where('id', self::USER)->update([
            'company_id' => '35000000-0000-7000-8000-000000000099',
            'branch_id' => self::BRANCH,
        ]);
        DB::table('user_branches')->insert([
            'id' => '35000000-0000-7000-8000-000000000098',
            'company_id' => self::COMPANY,
            'pos_user_id' => self::USER,
            'branch_id' => self::BRANCH,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('shift-session user belongs to another company or branch');
        (new SyncSaleImportService)->importShiftCashup(
            $this->node(),
            $this->completionPayload(
                '35000000-0000-7000-8000-000000000001',
                '35000000-0000-7000-8000-000000000002',
                '35000000-0000-7000-8000-000000000003'
            )
        );
    }

    public function test_closed_zero_sale_shift_is_queued_once_as_its_own_outbox_event(): void
    {
        $shiftId = '40000000-0000-7000-8000-000000000001';
        $cashupId = '40000000-0000-7000-8000-000000000002';
        DB::table('pos_user_shift_sessions')->insert([
            ...$this->openShift($shiftId, '2026-10-03 08:00:00'),
            'status' => 'closed',
            'closed_at' => '2026-10-03 17:00:00',
            'closing_cash' => 20,
        ]);
        DB::table('pos_cashups')->insert($this->cashup($shiftId, $cashupId));
        $denominationId = '40000000-0000-7000-8000-000000000004';
        DB::table('pos_cashup_denominations')->insert([
            'id' => $denominationId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'cashup_id' => $cashupId,
            'currency_code' => 'USD',
            'denomination_value' => 10,
            'quantity' => 2,
            'line_total' => 20,
            'denomination_type' => 'note',
            'sort_order' => 1,
            'metadata' => json_encode([]),
            'created_at' => '2026-10-03 17:00:00',
            'updated_at' => '2026-10-03 17:00:00',
        ]);
        $state = SyncLocalState::query()->create([
            'id' => '40000000-0000-7000-8000-000000000003',
            'node_id' => self::NODE,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'status' => 'paired',
        ]);
        $bundles = new SyncSaleBundleService(new SyncPayloadHasher, new SyncEventSetProof);
        $undiscovered = new ReflectionMethod(EdgeSyncController::class, 'undiscoveredShiftCashups');
        $controller = new EdgeSyncController(new SyncEventSetProof);

        $this->assertSame(1, $undiscovered->invoke($controller, $state));
        $this->assertSame(1, $bundles->queueCompletedShiftCashups($state));
        $this->assertSame(0, $bundles->queueCompletedShiftCashups($state->fresh()));
        $this->assertSame(0, $undiscovered->invoke($controller, $state->fresh()));
        $this->assertDatabaseHas('sync_outbox_events', [
            'source_node_id' => self::NODE,
            'aggregate_type' => 'cashup',
            'aggregate_id' => $cashupId,
            'event_type' => 'shift.cashup.completed',
            'deduplication_key' => 'shift.cashup.completed:'.$cashupId,
        ]);
        $event = DB::table('sync_outbox_events')->first();
        $eventPayload = json_decode((string) $event->payload, true);
        $this->assertSame($shiftId, data_get($eventPayload, 'shift_session.id'));
        $this->assertSame($cashupId, data_get($eventPayload, 'cashup.id'));
        $this->assertSame('2026-10-03 08:00:00', data_get($eventPayload, 'shift_session.opened_at'));
        $this->assertSame($denominationId, data_get($eventPayload, 'cashup_denominations.0.id'));
        $this->assertSame(1, data_get($state->fresh()->metadata, 'outbox_event_set_proof.count'));
    }

    public function test_cloud_protocol_accepts_and_dispatches_shift_cashup_completion_events(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));

        $this->assertStringContainsString("'shift.cashup.completed'", $source);
        $this->assertStringContainsString("data_get(\$event, 'payload.cashup.id')", $source);
        $this->assertStringContainsString(
            "'shift.cashup.completed' => \$this->saleImporter->importShiftCashup",
            $source
        );
    }

    public function test_cashup_completion_waits_until_every_counted_sale_is_imported(): void
    {
        $shiftId = '45000000-0000-7000-8000-000000000001';
        $cashupId = '45000000-0000-7000-8000-000000000002';
        $denominationId = '45000000-0000-7000-8000-000000000003';
        $payload = $this->completionPayload($shiftId, $cashupId, $denominationId);
        $payload['cashup']['transaction_count'] = 1;

        try {
            (new SyncSaleImportService)->importShiftCashup($this->node(), $payload);
            $this->fail('Cashup completion must remain retryable while a counted sale is missing.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('SYNC_DEPENDENCY_MISSING: shift_sales:', $exception->getMessage());
        }

        $this->assertDatabaseMissing('pos_user_shift_sessions', ['id' => $shiftId]);
        $this->assertDatabaseMissing('pos_cashups', ['id' => $cashupId]);
    }

    public function test_same_shift_cashup_under_another_uuid_is_acknowledged_only_when_immutable_values_match(): void
    {
        $shiftId = '46000000-0000-7000-8000-000000000001';
        $cloudCashupId = '46000000-0000-7000-8000-000000000002';
        $edgeCashupId = '46000000-0000-7000-8000-000000000003';
        $cloudDenominationId = '46000000-0000-7000-8000-000000000004';
        $edgeDenominationId = '46000000-0000-7000-8000-000000000005';
        DB::table('pos_user_shift_sessions')->insert([
            ...$this->openShift($shiftId, '2026-10-03 08:00:00'),
            'till_id' => self::TILL,
            'closed_at' => '2026-10-03 17:00:00',
            'closing_cash' => 20,
            'status' => 'closed',
        ]);
        DB::table('pos_cashups')->insert([
            ...$this->cashup($shiftId, $cloudCashupId),
            'status' => 'approved',
        ]);
        DB::table('pos_cashup_denominations')->insert([
            'id' => $cloudDenominationId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'cashup_id' => $cloudCashupId,
            'currency_code' => 'USD',
            'denomination_value' => 10,
            'quantity' => 2,
            'line_total' => 20,
            'denomination_type' => 'note',
            'sort_order' => 1,
            'metadata' => json_encode([]),
            'created_at' => '2026-10-03 17:00:00',
            'updated_at' => '2026-10-03 17:00:00',
        ]);

        $payload = $this->completionPayload($shiftId, $edgeCashupId, $edgeDenominationId);
        $result = (new SyncSaleImportService)->importShiftCashup($this->node(), $payload);

        $this->assertTrue($result['duplicate']);
        $this->assertSame('shift_session', $result['reconciled_by']);
        $this->assertSame($cloudCashupId, $result['cashup_id']);
        $this->assertSame($edgeCashupId, $result['source_cashup_id']);
        $this->assertSame(1, DB::table('pos_cashups')->count());
        $this->assertSame(1, DB::table('pos_cashup_denominations')->count());
        $this->assertDatabaseMissing('pos_cashups', ['id' => $edgeCashupId]);
        $this->assertDatabaseMissing('pos_cashup_denominations', ['id' => $edgeDenominationId]);
        $this->assertSame('approved', DB::table('pos_cashups')->where('id', $cloudCashupId)->value('status'));
    }

    public function test_same_shift_cashup_under_another_uuid_is_rejected_when_immutable_totals_differ(): void
    {
        $shiftId = '47000000-0000-7000-8000-000000000001';
        $cloudCashupId = '47000000-0000-7000-8000-000000000002';
        DB::table('pos_user_shift_sessions')->insert([
            ...$this->openShift($shiftId, '2026-10-03 08:00:00'),
            'till_id' => self::TILL,
            'closed_at' => '2026-10-03 17:00:00',
            'closing_cash' => 20,
            'status' => 'closed',
        ]);
        DB::table('pos_cashups')->insert($this->cashup($shiftId, $cloudCashupId));
        DB::table('pos_cashup_denominations')->insert([
            'id' => '47000000-0000-7000-8000-000000000004',
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'cashup_id' => $cloudCashupId,
            'currency_code' => 'USD',
            'denomination_value' => 10,
            'quantity' => 2,
            'line_total' => 20,
            'denomination_type' => 'note',
            'sort_order' => 1,
            'metadata' => json_encode([]),
            'created_at' => '2026-10-03 17:00:00',
            'updated_at' => '2026-10-03 17:00:00',
        ]);
        $payload = $this->completionPayload(
            $shiftId,
            '47000000-0000-7000-8000-000000000003',
            '47000000-0000-7000-8000-000000000005'
        );
        $payload['cashup']['counted_cash'] = 25;

        try {
            (new SyncSaleImportService)->importShiftCashup($this->node(), $payload);
            $this->fail('A conflicting cashup must never be acknowledged as a duplicate.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('SYNC_CASHUP_CONFLICT', $exception->getMessage());
            $this->assertStringContainsString('counted_cash', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('pos_cashups')->count());
        $this->assertSame(20.0, (float) DB::table('pos_cashups')->where('id', $cloudCashupId)->value('counted_cash'));
    }

    public function test_legacy_natural_uuid_conflict_dead_letter_is_requeued_once_for_semantic_reconciliation(): void
    {
        $shiftId = '48000000-0000-7000-8000-000000000001';
        $cashupId = '48000000-0000-7000-8000-000000000002';
        DB::table('pos_user_shift_sessions')->insert([
            ...$this->openShift($shiftId, '2026-10-03 08:00:00'),
            'till_id' => self::TILL,
            'closed_at' => '2026-10-03 17:00:00',
            'closing_cash' => 20,
            'status' => 'closed',
        ]);
        DB::table('pos_cashups')->insert($this->cashup($shiftId, $cashupId));
        $state = SyncLocalState::query()->create([
            'id' => '48000000-0000-7000-8000-000000000003',
            'node_id' => self::NODE,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'status' => 'paired',
        ]);
        DB::table('sync_outbox_events')->insert([
            'id' => '48000000-0000-7000-8000-000000000004',
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'source_node_id' => self::NODE,
            'aggregate_type' => 'cashup',
            'aggregate_id' => $cashupId,
            'event_type' => 'shift.cashup.completed',
            'deduplication_key' => 'shift.cashup.completed:'.$cashupId,
            'schema_version' => 1,
            'payload' => json_encode(['cashup' => ['id' => $cashupId]]),
            'payload_hash' => str_repeat('a', 64),
            'occurred_at' => '2026-10-03 17:00:00',
            'available_at' => null,
            'attempt_count' => 20,
            'last_attempt_at' => '2026-10-03 17:01:00',
            'last_error' => 'This shift already has a cashup under another UUID.',
            'delivered_at' => null,
            'created_at' => '2026-10-03 17:00:00',
            'updated_at' => '2026-10-03 17:01:00',
        ]);
        $bundles = new SyncSaleBundleService(new SyncPayloadHasher, new SyncEventSetProof);

        $this->assertSame(1, $bundles->queueCompletedShiftCashups($state));
        $event = DB::table('sync_outbox_events')->where('aggregate_id', $cashupId)->first();
        $this->assertNotNull($event->available_at);
        $this->assertSame(0, (int) $event->attempt_count);
        $this->assertSame('REQUEUED_CASHUP_SEMANTIC_RECONCILIATION', $event->last_error);
        $this->assertSame(0, $bundles->queueCompletedShiftCashups($state->fresh()));
        $this->assertSame(1, DB::table('sync_outbox_events')->count());
    }

    public function test_cashup_uuid_from_another_tenant_is_never_overwritten(): void
    {
        $shiftId = '50000000-0000-7000-8000-000000000001';
        $cashupId = '50000000-0000-7000-8000-000000000002';
        $denominationId = '50000000-0000-7000-8000-000000000003';
        DB::table('pos_cashups')->insert([
            ...$this->cashup($shiftId, $cashupId),
            'company_id' => '50000000-0000-7000-8000-000000000099',
            'branch_id' => '50000000-0000-7000-8000-000000000098',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cashup UUID belongs to another tenant');
        (new SyncSaleImportService)->importShiftCashup(
            $this->node(),
            $this->completionPayload($shiftId, $cashupId, $denominationId)
        );
    }

    public function test_opened_at_repair_migration_restores_cashup_snapshot_without_reintroducing_on_update(): void
    {
        $shiftId = '60000000-0000-7000-8000-000000000001';
        $cashupId = '60000000-0000-7000-8000-000000000002';
        DB::table('pos_user_shift_sessions')->insert([
            ...$this->openShift($shiftId, '2026-10-03 17:00:00'),
            'closed_at' => '2026-10-03 17:00:00',
            'status' => 'closed',
            'created_at' => '2026-10-03 08:00:00',
        ]);
        DB::table('pos_cashups')->insert($this->cashup($shiftId, $cashupId));

        $migration = require base_path(
            'Modules/Authentication/database/migrations/2026_10_07_130000_fix_shift_opened_at_on_update.php'
        );
        $migration->up();

        $this->assertSame(
            '2026-10-03 08:00:00',
            DB::table('pos_user_shift_sessions')->where('id', $shiftId)->value('opened_at')
        );

        $source = file_get_contents(base_path(
            'Modules/Authentication/database/migrations/2026_10_07_130000_fix_shift_opened_at_on_update.php'
        ));
        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $source);
        $this->assertStringNotContainsString(
            'MODIFY `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE',
            $source
        );
    }

    private function node(): SyncNode
    {
        $node = new SyncNode([
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'node_code' => 'EDGE-TEST',
        ]);
        $node->setAttribute('id', self::NODE);

        return $node;
    }

    private function openShift(string $shiftId, string $openedAt): array
    {
        return [
            'id' => $shiftId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'pos_user_id' => self::USER,
            'shift_name' => 'Morning',
            'opened_at' => $openedAt,
            'closed_at' => null,
            'opening_float' => 10,
            'closing_cash' => null,
            'status' => 'open',
            'created_at' => $openedAt,
            'updated_at' => $openedAt,
        ];
    }

    private function cashup(string $shiftId, string $cashupId): array
    {
        return [
            'id' => $cashupId,
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH,
            'till_id' => self::TILL,
            'shift_configuration_id' => null,
            'shift_session_id' => $shiftId,
            'cashier_id' => self::USER,
            'business_mode' => 'fast_food_boh',
            'trading_date' => '2026-10-03',
            // The real outbox bundle includes every available cashup column,
            // including nullable immutable values. Keep the fixture faithful
            // so an idempotent replay can prove semantic equivalence.
            'cashup_period_key' => null,
            'cashup_opened_at' => '2026-10-03 08:00:00',
            'cashup_closed_at' => '2026-10-03 17:00:00',
            'cashup_currency_code' => 'USD',
            'counted_cash' => 20,
            'transaction_count' => 0,
            'payment_summary' => json_encode([]),
            'status' => 'closed',
            'opened_at' => '2026-10-03 08:00:00',
            'closed_at' => '2026-10-03 17:00:00',
            'created_at' => '2026-10-03 17:00:00',
            'updated_at' => '2026-10-03 17:00:00',
        ];
    }

    private function completionPayload(string $shiftId, string $cashupId, string $denominationId): array
    {
        return [
            'shift_session' => [
                'id' => $shiftId,
                'pos_user_id' => self::USER,
                'shift_name' => 'Morning',
                // Simulate the legacy ON UPDATE corruption. Import must use
                // the cashup opening snapshot for a missing shift and never
                // overwrite an already-imported shift's opening.
                'opened_at' => '2026-10-03 17:00:00',
                'closed_at' => '2026-10-03 17:00:00',
                'opening_float' => 10,
                'closing_cash' => 20,
                'status' => 'closed',
                'created_at' => '2026-10-03 08:00:00',
                'updated_at' => '2026-10-03 17:00:00',
            ],
            'cashup' => $this->cashup($shiftId, $cashupId),
            'cashup_denominations' => [[
                'id' => $denominationId,
                'till_id' => self::TILL,
                'cashup_id' => $cashupId,
                'currency_code' => 'usd',
                'denomination_value' => 10,
                'quantity' => 2,
                'line_total' => 20,
                'denomination_type' => 'note',
                'sort_order' => 1,
                'metadata' => [],
                'created_at' => '2026-10-03 17:00:00',
                'updated_at' => '2026-10-03 17:00:00',
            ]],
        ];
    }
}
