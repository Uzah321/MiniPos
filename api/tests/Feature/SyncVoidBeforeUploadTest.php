<?php

namespace Tests\Feature;

use App\Models\SyncLocalState;
use App\Services\Sync\SyncEventSetProof;
use App\Services\Sync\SyncPayloadHasher;
use App\Services\Sync\SyncSaleBundleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A sale voided before its first upload must still reach the cloud before
 * its reversal document, otherwise the cloud receives a void without a sale.
 */
class SyncVoidBeforeUploadTest extends TestCase
{
    private const COMPANY = '50000000-0000-7000-8000-000000000001';

    private const BRANCH = '50000000-0000-7000-8000-000000000002';

    private const NODE = '50000000-0000-7000-8000-000000000003';

    private const SALE = '50000000-0000-7000-8000-000000000010';

    private const REVERSAL = '50000000-0000-7000-8000-000000000011';

    /** @var list<string> */
    private array $tables = [
        'sync_outbox_events', 'sync_local_states', 'stock_movements',
        'sales_transaction_payments', 'receipt_lines', 'sales_transactions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('sales_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_session_id')->nullable();
            $table->string('status');
            $table->string('sale_type')->nullable();
            $table->string('invoice_number')->nullable();
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->timestamp('transaction_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        foreach (['receipt_lines', 'sales_transaction_payments'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id');
                $table->uuid('sales_transaction_id')->nullable();
            });
        }
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable()->unique();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
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
            $table->timestamps();
            $table->unique(['source_node_id', 'deduplication_key']);
        });

        DB::table('sales_transactions')->insert([
            'id' => self::SALE, 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH,
            'status' => 'completed', 'sale_type' => 'sale', 'invoice_number' => 'INV-1', 'total_amount' => 8.5,
            'transaction_date' => '2026-10-14 12:00:00', 'created_at' => '2026-10-14 12:00:00', 'updated_at' => '2026-10-14 12:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_voiding_an_unsynced_sale_uploads_the_original_before_its_reversal(): void
    {
        $state = $this->pairedState(self::BRANCH);
        $bundles = $this->bundles();

        // The void path runs this before marking the sale cancelled.
        $this->assertTrue($bundles->queueSaleBeforeCancellation(self::COMPANY, self::BRANCH, self::SALE));
        DB::table('sales_transactions')->where('id', self::SALE)->update(['status' => 'cancelled']);
        DB::table('sales_transactions')->insert([
            'id' => self::REVERSAL, 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH,
            'status' => 'completed', 'sale_type' => 'sale_cancel', 'invoice_number' => 'CXL-1', 'total_amount' => -8.5,
            'transaction_date' => '2026-10-14 12:05:00', 'created_at' => '2026-10-14 12:05:00', 'updated_at' => '2026-10-14 12:05:00',
            'metadata' => json_encode(['cancelled_original_sale_id' => self::SALE]),
        ]);

        // The periodic scan then picks up only the reversal.
        $this->assertSame(1, $bundles->queueCompletedSales($state->fresh()));

        $events = DB::table('sync_outbox_events')->orderBy('occurred_at')->get();
        $this->assertSame([self::SALE, self::REVERSAL], $events->pluck('aggregate_id')->all());
        $original = json_decode((string) $events[0]->payload, true);
        $this->assertSame('completed', data_get($original, 'sale.status'));
        $this->assertSame(2, data_get($state->fresh()->metadata, 'outbox_event_set_proof.count'));
    }

    public function test_a_sale_already_in_the_outbox_is_not_queued_twice(): void
    {
        $state = $this->pairedState(self::BRANCH);
        $this->assertSame(1, $this->bundles()->queueCompletedSales($state));

        $this->assertTrue($this->bundles()->queueSaleBeforeCancellation(self::COMPANY, self::BRANCH, self::SALE));
        $this->assertSame(1, DB::table('sync_outbox_events')->count());
    }

    public function test_installations_that_are_not_the_sales_paired_edge_queue_nothing(): void
    {
        $this->assertFalse($this->bundles()->queueSaleBeforeCancellation(self::COMPANY, self::BRANCH, self::SALE));

        $this->pairedState('50000000-0000-7000-8000-0000000000ff');
        $this->assertFalse($this->bundles()->queueSaleBeforeCancellation(self::COMPANY, self::BRANCH, self::SALE));
        $this->assertSame(0, DB::table('sync_outbox_events')->count());
    }

    private function pairedState(string $branchId): SyncLocalState
    {
        return SyncLocalState::query()->create([
            'id' => '50000000-0000-7000-8000-000000000020',
            'node_id' => self::NODE,
            'company_id' => self::COMPANY,
            'branch_id' => $branchId,
            'status' => 'paired',
        ]);
    }

    private function bundles(): SyncSaleBundleService
    {
        return new SyncSaleBundleService(new SyncPayloadHasher, new SyncEventSetProof);
    }
}
