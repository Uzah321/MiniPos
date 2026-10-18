<?php

namespace App\Services\Sync;

use App\Models\SyncLocalState;
use App\Models\SyncOutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SyncSaleBundleService
{
    private const TABLE_COLUMNS = [
        'trading_days' => [
            'id', 'trading_date', 'status', 'opened_at', 'day_end_started_at', 'closed_at',
            'day_end_started_by', 'closed_by', 'notes', 'metadata', 'created_at', 'updated_at',
        ],
        'pos_user_shift_sessions' => [
            'id', 'pos_user_id', 'till_id', 'shift_configuration_id', 'shift_name', 'opened_at', 'closed_at',
            'opening_float', 'closing_cash', 'status', 'created_at', 'updated_at',
        ],
        'pos_cashups' => [
            'id', 'cashup_number', 'till_id', 'shift_configuration_id', 'shift_session_id',
            'cashier_id', 'closed_by', 'business_mode', 'boh_enabled', 'fast_food_mode_enabled',
            'trading_date', 'cashup_period_key', 'cashup_bracket_key', 'cashup_period_label',
            'cashup_opened_at', 'cashup_closed_at', 'cashup_currency_code', 'base_currency_code',
            'opening_float', 'expected_cash', 'counted_cash', 'cash_variance', 'cashout_total',
            'cashout_count', 'expected_cash_after_cashouts', 'expected_total', 'counted_total',
            'total_variance', 'transaction_count', 'void_count', 'refund_count', 'payment_summary',
            'currency_summary', 'sales_summary', 'cashout_summary', 'shift_policy_snapshot',
            'business_mode_snapshot', 'status', 'notes', 'force_close_reason', 'opened_at',
            'closed_at', 'created_at', 'updated_at', 'deleted_at',
        ],
        'pos_cashup_denominations' => [
            'id', 'till_id', 'cashup_id', 'currency_code', 'denomination_value', 'quantity',
            'line_total', 'denomination_type', 'sort_order', 'metadata', 'created_at', 'updated_at',
        ],
        'sales_transactions' => [
            'id', 'trading_day_id', 'business_date', 'order_business_date', 'till_id', 'shift_id',
            'shift_session_id', 'cashier_id', 'user_id', 'invoice_number', 'client_sale_id',
            'order_number', 'order_sequence', 'transaction_date', 'document_type', 'sale_type',
            'status', 'kitchen_print_status', 'kitchen_printed_at', 'currency_code',
            'base_currency_code', 'exchange_rate', 'subtotal_amount', 'discount_amount',
            'tax_amount', 'total_amount', 'base_subtotal_amount', 'base_discount_amount',
            'base_tax_amount', 'base_total_amount', 'payment_type_id', 'payment_method',
            'amount_tendered', 'change_amount', 'change', 'customer_name', 'customer_phone',
            'customer_tax_number', 'credit_customer_id', 'credit_account_number', 'is_credit_sale',
            'credit_status', 'credit_due_date', 'credit_amount', 'credit_paid_amount',
            'credit_balance_amount', 'notes', 'metadata', 'is_fiscalised', 'fiscal_status',
            'fiscal_device_id', 'fiscal_day_id', 'receipt_counter', 'receipt_global_no',
            'fdms_receipt_id', 'qr_code_data', 'qr_url', 'zimra_signature_hash', 'fiscal_response',
            'fiscal_error', 'fiscalised_at', 'is_printed', 'printed_at', 'printed_by',
            'created_at', 'updated_at', 'deleted_at',
        ],
        'receipt_lines' => [
            'id', 'sales_transaction_id', 'product_id', 'product_price_id', 'price_level_id',
            'line_no', 'order_number', 'kitchen_print_status', 'kitchen_printed_at', 'line_name',
            'product_code', 'sku', 'barcode', 'hs_code', 'line_quantity', 'line_price',
            'line_subtotal', 'line_discount', 'line_tax', 'line_total', 'base_line_price',
            'base_line_subtotal', 'base_line_discount', 'base_line_tax', 'base_line_total',
            'currency_code', 'base_currency_code', 'metadata', 'created_at', 'updated_at',
        ],
        'receipt_line_taxes' => [
            'id', 'sales_transaction_id', 'receipt_line_id', 'tax_id', 'tax_code', 'tax_percent',
            'is_inclusive', 'obliged_to_zimra', 'tax_amount', 'sales_amount_with_tax',
            'currency_code', 'base_currency_code', 'base_tax_amount',
            'base_sales_amount_with_tax', 'metadata', 'created_at', 'updated_at',
        ],
        'sale_discounts' => [
            'id', 'sales_transaction_id', 'receipt_line_id', 'discount_id', 'discount_type_id',
            'discount_code', 'discount_name', 'scope', 'discount_basis', 'discount_value',
            'gross_amount', 'discount_amount', 'net_amount', 'currency', 'cashier_id',
            'approved_by', 'manager_approval_id', 'reason', 'metadata', 'created_at', 'updated_at',
        ],
        'sales_transaction_payments' => [
            'id', 'till_id', 'shift_id', 'shift_session_id', 'sales_transaction_id',
            'payment_type_id', 'payment_name', 'money_code', 'currency_code',
            'base_currency_code', 'exchange_rate', 'amount', 'base_amount', 'amount_tendered',
            'change_amount', 'reference', 'approval_code', 'card_last_four', 'metadata',
            'created_at', 'updated_at',
        ],
        'stock_movements' => [
            'id', 'warehouse_id', 'location_id', 'batch_id', 'till_id', 'shift_session_id',
            'product_id', 'stock_area', 'from_stock_area', 'to_stock_area', 'issue_destination',
            'stock_usage_type', 'expense_account_code', 'movement_type', 'quantity_in',
            'quantity_out', 'balance_after', 'unit_cost', 'total_cost', 'currency',
            'currency_code', 'reference_type', 'reference_id', 'notes', 'metadata',
            'created_by', 'created_at', 'updated_at',
        ],
        'pos_cash_drawer_movements' => [
            'id', 'till_id', 'shift_session_id', 'cashier_id', 'movement_type', 'amount',
            'currency_code', 'reason', 'notes', 'reference_no', 'approved_by', 'created_by',
            'occurred_at', 'metadata', 'created_at', 'updated_at', 'deleted_at',
        ],
    ];

    public function __construct(
        private readonly SyncPayloadHasher $hasher,
        private readonly SyncEventSetProof $eventSetProof
    ) {}

    /**
     * Discover completed local sales that have not yet entered the durable
     * outbox. This scan is intentionally request/command driven, so a sale is
     * still discovered after a process crash without requiring cron.
     */
    public function queueCompletedSales(SyncLocalState $state, int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));

        if (! $state->node_id || ! $state->company_id || ! $state->branch_id) {
            throw new RuntimeException('The local sync node is not paired to a company and branch.');
        }

        $sales = DB::table('sales_transactions as sales')
            ->where('sales.company_id', $state->company_id)
            ->where('sales.branch_id', $state->branch_id)
            ->where('sales.status', 'completed')
            ->whereNotExists(function ($query) use ($state) {
                $query->selectRaw('1')
                    ->from('sync_outbox_events as outbox')
                    ->whereColumn('outbox.aggregate_id', 'sales.id')
                    ->where('outbox.source_node_id', $state->node_id)
                    ->where('outbox.event_type', 'sale.completed');
            })
            ->orderBy('sales.created_at')
            ->limit($limit)
            ->get(['sales.id', 'sales.transaction_date', 'sales.created_at']);

        $queued = 0;

        foreach ($sales as $sale) {
            if ($this->queueSale($state, $sale)->wasRecentlyCreated) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * A void marks the original sale cancelled and creates a completed
     * reversal document. The scan only uploads completed sales, so a sale
     * voided before its first upload would otherwise never reach the cloud
     * while its reversal would. Queue the original first, while it is still
     * completed, so the cloud always receives the sale and then its linked
     * reversal. Returns false when this installation is not a paired edge.
     */
    public function queueSaleBeforeCancellation(string $companyId, string $branchId, string $saleId): bool
    {
        if (! Schema::hasTable('sync_local_states') || ! Schema::hasTable('sync_outbox_events')) {
            return false;
        }

        $state = SyncLocalState::query()->latest()->first();
        if (! $state
            || ! $state->node_id
            || (string) $state->company_id !== $companyId
            || (string) $state->branch_id !== $branchId) {
            return false;
        }

        $alreadyQueued = SyncOutboxEvent::query()
            ->where('source_node_id', $state->node_id)
            ->where('deduplication_key', 'sale.completed:'.$saleId)
            ->exists();
        if ($alreadyQueued) {
            return true;
        }

        $sale = DB::table('sales_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('id', $saleId)
            ->where('status', 'completed')
            ->first(['id', 'transaction_date', 'created_at']);
        if (! $sale) {
            return false;
        }

        $this->queueSale($state, $sale);

        return true;
    }

    private function queueSale(SyncLocalState $state, object $sale): SyncOutboxEvent
    {
        $bundle = $this->bundle(
            (string) $state->company_id,
            (string) $state->branch_id,
            (string) $sale->id
        );
        $eventId = (string) Str::uuid();

        return DB::transaction(function () use ($state, $sale, $eventId, $bundle): SyncOutboxEvent {
            // The local-state row is the append/proof fence. Status holds
            // the same lock, so it can never report equality between an
            // outbox insert and its accumulator update.
            $lockedState = SyncLocalState::query()
                ->whereKey($state->id)
                ->lockForUpdate()
                ->firstOrFail();
            $event = SyncOutboxEvent::query()->firstOrCreate(
                [
                    'source_node_id' => $lockedState->node_id,
                    'deduplication_key' => 'sale.completed:'.(string) $sale->id,
                ],
                [
                    'id' => $eventId,
                    'company_id' => $lockedState->company_id,
                    'branch_id' => $lockedState->branch_id,
                    'aggregate_type' => 'sale',
                    'aggregate_id' => (string) $sale->id,
                    'event_type' => 'sale.completed',
                    'schema_version' => 1,
                    'payload' => $bundle,
                    'payload_hash' => $this->hasher->hash($bundle),
                    'occurred_at' => $sale->transaction_date ?: $sale->created_at ?: now(),
                    'available_at' => now(),
                    'attempt_count' => 0,
                ]
            );
            if ($event->wasRecentlyCreated) {
                $this->recordOutboxProof($lockedState, $event);
            }

            return $event;
        }, 3);
    }

    /**
     * Append one immutable completion event per closed cashup. Cashup happens
     * after the sale event is normally delivered, and a shift may contain no
     * sales at all, so it must have its own durable outbox boundary.
     */
    public function queueCompletedShiftCashups(SyncLocalState $state, int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));

        if (! $state->node_id || ! $state->company_id || ! $state->branch_id) {
            throw new RuntimeException('The local sync node is not paired to a company and branch.');
        }
        if (! Schema::hasTable('pos_cashups') || ! Schema::hasTable('pos_user_shift_sessions')) {
            return 0;
        }

        // Releases written before semantic cashup reconciliation treated a
        // different UUID for the same shift as a permanent rejection. Give
        // only that obsolete dead-letter reason one controlled retry. The
        // cloud importer now proves immutable totals/scope before accepting;
        // a genuine mismatch is rejected again with SYNC_CASHUP_CONFLICT and
        // is deliberately not revived by future scans.
        $requeued = $this->requeueLegacyNaturalCashupConflicts($state);

        $occurredAtColumn = Schema::hasColumn('pos_cashups', 'cashup_closed_at')
            ? 'cashups.cashup_closed_at'
            : 'cashups.closed_at';
        $cashups = DB::table('pos_cashups as cashups')
            ->join('pos_user_shift_sessions as shifts', function ($join): void {
                $join->on('shifts.id', '=', 'cashups.shift_session_id')
                    ->on('shifts.company_id', '=', 'cashups.company_id')
                    ->on('shifts.branch_id', '=', 'cashups.branch_id');
            })
            ->where('cashups.company_id', $state->company_id)
            ->where('cashups.branch_id', $state->branch_id)
            ->whereIn(DB::raw('LOWER(cashups.status)'), ['closed', 'approved'])
            ->where('shifts.status', 'closed')
            ->whereNotNull('shifts.closed_at')
            ->when(
                Schema::hasColumn('pos_cashups', 'deleted_at'),
                fn ($query) => $query->whereNull('cashups.deleted_at')
            )
            ->whereNotExists(function ($query) use ($state): void {
                $query->selectRaw('1')
                    ->from('sync_outbox_events as outbox')
                    ->whereColumn('outbox.aggregate_id', 'cashups.id')
                    ->where('outbox.source_node_id', $state->node_id)
                    ->where('outbox.event_type', 'shift.cashup.completed');
            })
            ->orderBy('cashups.created_at')
            ->limit($limit)
            ->get([
                'cashups.id',
                'cashups.shift_session_id',
                DB::raw("COALESCE({$occurredAtColumn}, cashups.closed_at, cashups.updated_at, cashups.created_at) as occurred_at"),
            ]);

        $queued = 0;
        foreach ($cashups as $cashup) {
            $bundle = $this->shiftCashupBundle(
                (string) $state->company_id,
                (string) $state->branch_id,
                (string) $cashup->id
            );
            $eventId = (string) Str::uuid();
            $created = DB::transaction(function () use ($state, $cashup, $eventId, $bundle): SyncOutboxEvent {
                $lockedState = SyncLocalState::query()
                    ->whereKey($state->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $event = SyncOutboxEvent::query()->firstOrCreate(
                    [
                        'source_node_id' => $lockedState->node_id,
                        'deduplication_key' => 'shift.cashup.completed:'.(string) $cashup->id,
                    ],
                    [
                        'id' => $eventId,
                        'company_id' => $lockedState->company_id,
                        'branch_id' => $lockedState->branch_id,
                        'aggregate_type' => 'cashup',
                        'aggregate_id' => (string) $cashup->id,
                        'event_type' => 'shift.cashup.completed',
                        'schema_version' => 1,
                        'payload' => $bundle,
                        'payload_hash' => $this->hasher->hash($bundle),
                        'occurred_at' => $cashup->occurred_at ?: now(),
                        'available_at' => now(),
                        'attempt_count' => 0,
                    ]
                );
                if ($event->wasRecentlyCreated) {
                    $this->recordOutboxProof($lockedState, $event);
                }

                return $event;
            }, 3);

            if ($created->wasRecentlyCreated) {
                $queued++;
            }
        }

        return $requeued + $queued;
    }

    private function requeueLegacyNaturalCashupConflicts(SyncLocalState $state): int
    {
        return SyncOutboxEvent::query()
            ->where('source_node_id', $state->node_id)
            ->where('company_id', $state->company_id)
            ->where('branch_id', $state->branch_id)
            ->where('event_type', 'shift.cashup.completed')
            ->whereNull('delivered_at')
            ->whereNull('available_at')
            ->where('last_error', 'like', '%This shift already has a cashup under another UUID.%')
            ->update([
                'attempt_count' => 0,
                'last_attempt_at' => null,
                'last_error' => 'REQUEUED_CASHUP_SEMANTIC_RECONCILIATION',
                'available_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function recordOutboxProof(SyncLocalState $state, SyncOutboxEvent $event): void
    {
        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $proof = $metadata['outbox_event_set_proof'] ?? null;

        if ($this->eventSetProof->valid($proof)) {
            $proof = $this->eventSetProof->add(
                $proof,
                (string) $event->id,
                (string) $event->payload_hash
            );
        } else {
            // One-time upgrade/backfill. The persisted accumulator makes
            // later status checks O(1) rather than scanning all history.
            $proof = $this->eventSetProof->empty();
            foreach (SyncOutboxEvent::query()
                ->where('source_node_id', $state->node_id)
                ->where('company_id', $state->company_id)
                ->where('branch_id', $state->branch_id)
                ->orderBy('id')
                ->cursor(['id', 'payload_hash']) as $existing) {
                $proof = $this->eventSetProof->add(
                    $proof,
                    (string) $existing->id,
                    (string) $existing->payload_hash
                );
            }
        }

        $state->forceFill(['metadata' => [
            ...$metadata,
            'outbox_event_set_proof' => [
                ...$proof,
                'updated_at' => now()->toIso8601String(),
            ],
        ]])->save();
    }

    public function bundle(string $companyId, string $branchId, string $saleId): array
    {
        $sale = DB::table('sales_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('id', $saleId)
            ->first($this->columns('sales_transactions'));

        if (! $sale) {
            throw new RuntimeException('Completed sale not found for this local company and branch.');
        }

        $lines = $this->rowsForSale('receipt_lines', $companyId, $branchId, $saleId);
        $lineTaxes = $this->rowsForSale('receipt_line_taxes', $companyId, $branchId, $saleId);
        $saleDiscounts = $this->rowsForSale('sale_discounts', $companyId, $branchId, $saleId);
        $payments = $this->rowsForSale('sales_transaction_payments', $companyId, $branchId, $saleId);

        $stockMovements = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('reference_id', $saleId)
            ->whereIn('reference_type', [
                'sale',
                'sale_auto_break',
                'sale_recipe',
                'sale_modifier_recipe',
            ])
            ->orderBy('created_at')
            ->get($this->columns('stock_movements'))
            ->map(fn ($row) => (array) $row)
            ->all();

        $cashMovements = [];
        if (Schema::hasTable('pos_cash_drawer_movements')) {
            $invoice = (string) ($sale->invoice_number ?? '');
            $cashMovements = DB::table('pos_cash_drawer_movements')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where(function ($query) use ($saleId, $invoice) {
                    $query->whereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.source_sales_transaction_id')) = ?",
                        [$saleId]
                    );
                    if ($invoice !== '') {
                        $query->orWhere('reference_no', $invoice);
                    }
                })
                ->orderBy('created_at')
                ->get($this->columns('pos_cash_drawer_movements'))
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $cashup = $this->cashupForShift(
            $companyId,
            $branchId,
            $sale->shift_session_id ?? null
        );

        return [
            'trading_day' => $this->headerForSale(
                'trading_days',
                $companyId,
                $branchId,
                $sale->trading_day_id ?? null
            ),
            'shift_session' => $this->headerForSale(
                'pos_user_shift_sessions',
                $companyId,
                $branchId,
                $sale->shift_session_id ?? null
            ),
            // Included for recovery/replay. The normal post-sale closure path
            // is the separate shift.cashup.completed event above.
            'cashup' => $cashup,
            'cashup_denominations' => $cashup
                ? $this->cashupDenominations($companyId, $branchId, (string) $cashup['id'])
                : [],
            'sale' => (array) $sale,
            'receipt_lines' => $lines,
            'receipt_line_taxes' => $lineTaxes,
            'sale_discounts' => $saleDiscounts,
            'payments' => $payments,
            'stock_movements' => $stockMovements,
            'cash_drawer_movements' => $cashMovements,
            'captured_at' => Carbon::now()->toIso8601String(),
        ];
    }

    public function shiftCashupBundle(string $companyId, string $branchId, string $cashupId): array
    {
        $cashup = DB::table('pos_cashups')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('id', $cashupId)
            ->when(
                Schema::hasColumn('pos_cashups', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->first($this->columns('pos_cashups'));
        if (! $cashup) {
            throw new RuntimeException('Completed cashup not found for this local company and branch.');
        }
        if (! in_array(strtolower((string) ($cashup->status ?? '')), ['closed', 'approved'], true)) {
            throw new RuntimeException('Only completed cashups can enter the durable sync outbox.');
        }

        $shift = $this->headerForSale(
            'pos_user_shift_sessions',
            $companyId,
            $branchId,
            $cashup->shift_session_id ?? null
        );
        if (! $shift || strtolower((string) ($shift['status'] ?? '')) !== 'closed' || empty($shift['closed_at'])) {
            throw new RuntimeException('A completed cashup must reference a closed shift in the same branch.');
        }

        // Historical MySQL schemas accidentally changed shift.opened_at on
        // every update. Cashup captured the real opening time before closure,
        // so use it when constructing a repair/completion event.
        $cashupOpenedAt = $cashup->cashup_opened_at ?? $cashup->opened_at ?? null;
        if ($cashupOpenedAt) {
            $shift['opened_at'] = $cashupOpenedAt;
        }

        return [
            'shift_session' => $shift,
            'cashup' => (array) $cashup,
            'cashup_denominations' => $this->cashupDenominations(
                $companyId,
                $branchId,
                (string) $cashup->id
            ),
            'captured_at' => Carbon::now()->toIso8601String(),
        ];
    }

    private function cashupForShift(string $companyId, string $branchId, mixed $shiftSessionId): ?array
    {
        if (! Schema::hasTable('pos_cashups')
            || ! is_scalar($shiftSessionId)
            || trim((string) $shiftSessionId) === '') {
            return null;
        }

        $row = DB::table('pos_cashups')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('shift_session_id', trim((string) $shiftSessionId))
            ->whereIn(DB::raw('LOWER(status)'), ['closed', 'approved'])
            ->when(
                Schema::hasColumn('pos_cashups', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->first($this->columns('pos_cashups'));

        return $row ? (array) $row : null;
    }

    private function cashupDenominations(string $companyId, string $branchId, string $cashupId): array
    {
        if (! Schema::hasTable('pos_cashup_denominations')) {
            return [];
        }

        return DB::table('pos_cashup_denominations')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('cashup_id', $cashupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get($this->columns('pos_cashup_denominations'))
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function headerForSale(
        string $table,
        string $companyId,
        string $branchId,
        mixed $id
    ): ?array {
        if (! Schema::hasTable($table) || ! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }

        $row = DB::table($table)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('id', trim((string) $id))
            ->first($this->columns($table));

        return $row ? (array) $row : null;
    }

    private function rowsForSale(
        string $table,
        string $companyId,
        string $branchId,
        string $saleId
    ): array {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('sales_transaction_id', $saleId)
            ->orderBy('created_at')
            ->get($this->columns($table))
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function columns(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $available = array_flip(Schema::getColumnListing($table));

        return array_values(array_filter(
            self::TABLE_COLUMNS[$table] ?? [],
            static fn (string $column) => isset($available[$column])
        ));
    }
}
