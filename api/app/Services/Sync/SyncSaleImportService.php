<?php

namespace App\Services\Sync;

use App\Models\SyncNode;
use App\Services\Sales\CreditCustomerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Sales\Models\CreditCustomer;
use Modules\Sales\Models\SalesTransaction;
use RuntimeException;

class SyncSaleImportService
{
    private const ALLOWED = [
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

    public function import(SyncNode $node, array $payload): array
    {
        return DB::transaction(fn () => $this->importWithinTransaction($node, $payload), 3);
    }

    private function importWithinTransaction(SyncNode $node, array $payload): array
    {
        foreach (['sales_transactions', 'receipt_lines', 'sales_transaction_payments'] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("SYNC_DEPENDENCY_MISSING: table:{$requiredTable}");
            }
        }

        $sale = $payload['sale'] ?? null;
        if (! is_array($sale) || ! Str::isUuid((string) ($sale['id'] ?? ''))) {
            throw new InvalidArgumentException('A sale bundle must contain a UUID sale id.');
        }

        if (strtolower((string) ($sale['status'] ?? '')) !== 'completed') {
            throw new InvalidArgumentException('Only completed POS sales can be uploaded in schema version 1.');
        }

        $saleId = (string) $sale['id'];
        $existing = DB::table('sales_transactions')->where('id', $saleId)->first();
        if ($existing) {
            if ((string) $existing->company_id !== (string) $node->company_id
                || (string) $existing->branch_id !== (string) $node->branch_id) {
                throw new InvalidArgumentException('The sale UUID is already owned by another tenant or branch.');
            }
        }

        $tillId = $this->validatedTillId($node, $sale['till_id'] ?? null);
        if ($existing
            && $existing->till_id
            && $tillId
            && (string) $existing->till_id !== $tillId) {
            throw new InvalidArgumentException('A duplicate sale cannot be reassigned to another terminal.');
        }
        $sale['till_id'] = $tillId;
        $sale['status'] = 'completed';
        $sale['trading_day_id'] = $this->importTradingDayHeader(
            $node,
            $payload['trading_day'] ?? null,
            $sale['trading_day_id'] ?? null
        );
        $shiftHeader = $payload['shift_session'] ?? null;
        if (is_array($shiftHeader) && is_array($payload['cashup'] ?? null)) {
            $capturedOpening = $payload['cashup']['cashup_opened_at']
                ?? $payload['cashup']['opened_at']
                ?? null;
            if ($capturedOpening) {
                $shiftHeader['opened_at'] = $capturedOpening;
            }
        }
        $sale['shift_session_id'] = $this->importShiftSessionHeader(
            $node,
            $shiftHeader,
            $sale['shift_session_id'] ?? null,
            $tillId
        );
        if ($existing
            && $existing->shift_session_id
            && $sale['shift_session_id']
            && (string) $existing->shift_session_id !== (string) $sale['shift_session_id']) {
            throw new InvalidArgumentException('A duplicate sale cannot be reassigned to another shift session.');
        }

        // A retry/recovery bundle may carry the closure that occurred after
        // the original sale was accepted. Reconcile that mutable completion
        // state before the duplicate guard returns.
        if ($existing) {
            $cashupResult = $this->importOptionalCashupFromSaleBundle($node, $payload);

            return [
                'sale_id' => $saleId,
                'duplicate' => true,
                'shift_reconciled' => is_array($payload['shift_session'] ?? null),
                'cashup' => $cashupResult,
            ];
        }
        $saleSourceReferences = [];
        foreach ([
            'trading_day_id' => 'trading_days',
            'shift_session_id' => 'pos_user_shift_sessions',
            'cashier_id' => 'pos_users',
            'user_id' => 'pos_users',
            'printed_by' => 'pos_users',
            'payment_type_id' => 'payment_types',
            'fiscal_device_id' => 'fiscal_devices',
            'fiscal_day_id' => 'fiscal_days',
        ] as $column => $table) {
            $this->normaliseOptionalReference($sale, $column, $table, $node, $saleSourceReferences);
        }
        $isCreditSale = filter_var($sale['is_credit_sale'] ?? false, FILTER_VALIDATE_BOOL);
        if ($isCreditSale) {
            $sale['credit_customer_id'] = $this->requireTenantReference(
                'credit_customers',
                $sale['credit_customer_id'] ?? null,
                $node,
                'credit customer'
            );
        } else {
            $this->normaliseOptionalReference(
                $sale,
                'credit_customer_id',
                'credit_customers',
                $node,
                $saleSourceReferences
            );
        }
        $this->normaliseOptionalReference(
            $sale,
            'shift_id',
            'shiftconfigurations',
            $node,
            $saleSourceReferences
        );
        $sale['client_sale_id'] = $this->cloudClientSaleId($node, $saleId, $sale['client_sale_id'] ?? null);
        $sale['invoice_number'] = $this->availableInvoiceNumber(
            $node,
            $saleId,
            (string) ($sale['invoice_number'] ?? $saleId)
        );
        $sale['metadata'] = $this->withSyncMetadata($sale['metadata'] ?? null, $node, [
            'source_invoice_number' => $payload['sale']['invoice_number'] ?? null,
            'source_client_sale_id' => $payload['sale']['client_sale_id'] ?? null,
            'source_references' => $saleSourceReferences ?: null,
        ]);

        $this->insertTenantRow('sales_transactions', $sale, $node);

        $lineIds = [];
        foreach ($this->rows($payload, 'receipt_lines') as $line) {
            $this->assertUuid($line, 'receipt line');
            $this->requireTenantReference('products', $line['product_id'] ?? null, $node, 'receipt product');
            $sourceReferences = [];
            $this->normaliseOptionalReference($line, 'product_price_id', 'product_prices', $node, $sourceReferences);
            $this->normaliseOptionalReference($line, 'price_level_id', 'price_levels', $node, $sourceReferences);
            $line['sales_transaction_id'] = $saleId;
            $line['metadata'] = $this->withSyncMetadata($line['metadata'] ?? null, $node, [
                'source_references' => $sourceReferences ?: null,
            ]);
            $this->insertTenantRow('receipt_lines', $line, $node);
            $lineIds[] = (string) $line['id'];
        }

        foreach ($this->rows($payload, 'receipt_line_taxes') as $tax) {
            $this->assertUuid($tax, 'receipt tax');
            if (! in_array((string) ($tax['receipt_line_id'] ?? ''), $lineIds, true)) {
                throw new InvalidArgumentException('Receipt tax references a line outside this sale bundle.');
            }
            $sourceReferences = [];
            $this->normaliseOptionalReference($tax, 'tax_id', 'taxes', $node, $sourceReferences);
            $tax['sales_transaction_id'] = $saleId;
            $tax['metadata'] = $this->withSyncMetadata($tax['metadata'] ?? null, $node, [
                'source_references' => $sourceReferences ?: null,
            ]);
            $this->insertTenantRow('receipt_line_taxes', $tax, $node);
        }

        $saleDiscounts = $this->rows($payload, 'sale_discounts');
        if ($saleDiscounts !== [] && ! Schema::hasTable('sale_discounts')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:sale_discounts');
        }
        foreach ($saleDiscounts as $discount) {
            $this->assertUuid($discount, 'sale discount');
            $receiptLineId = isset($discount['receipt_line_id']) && is_scalar($discount['receipt_line_id'])
                ? trim((string) $discount['receipt_line_id'])
                : '';
            if ($receiptLineId !== '' && ! in_array($receiptLineId, $lineIds, true)) {
                throw new InvalidArgumentException('Sale discount references a line outside this sale bundle.');
            }
            $sourceReferences = [];
            foreach ([
                'discount_id' => 'discounts',
                'discount_type_id' => 'discount_types',
                'cashier_id' => 'pos_users',
                'approved_by' => 'pos_users',
            ] as $column => $table) {
                $this->normaliseOptionalReference($discount, $column, $table, $node, $sourceReferences);
            }
            $discount['sales_transaction_id'] = $saleId;
            $discount['receipt_line_id'] = $receiptLineId !== '' ? $receiptLineId : null;
            $discount['metadata'] = $this->withSyncMetadata($discount['metadata'] ?? null, $node, [
                'source_references' => $sourceReferences ?: null,
            ]);
            $this->insertTenantRow('sale_discounts', $discount, $node);
        }

        foreach ($this->rows($payload, 'payments') as $payment) {
            $this->assertUuid($payment, 'sale payment');
            $this->requireTenantReference(
                'payment_types',
                $payment['payment_type_id'] ?? null,
                $node,
                'sale payment type'
            );
            $sourceReferences = [];
            $this->normaliseOptionalReference(
                $payment,
                'shift_id',
                'shiftconfigurations',
                $node,
                $sourceReferences
            );
            $this->normaliseOptionalReference(
                $payment,
                'shift_session_id',
                'pos_user_shift_sessions',
                $node,
                $sourceReferences
            );
            $payment['sales_transaction_id'] = $saleId;
            $payment['till_id'] = $tillId;
            $payment['metadata'] = $this->withSyncMetadata($payment['metadata'] ?? null, $node, [
                'source_references' => $sourceReferences ?: null,
            ]);
            $this->insertTenantRow('sales_transaction_payments', $payment, $node);
        }

        foreach ($this->rows($payload, 'stock_movements') as $movement) {
            $this->assertUuid($movement, 'stock movement');
            if (! in_array((string) ($movement['reference_type'] ?? ''), [
                'sale',
                'sale_auto_break',
                'sale_recipe',
                'sale_modifier_recipe',
            ], true)) {
                throw new InvalidArgumentException('Sale bundle contains an unrelated stock movement.');
            }
            $this->requireTenantReference('products', $movement['product_id'] ?? null, $node, 'stock product');
            $sourceReferences = [];
            foreach ([
                'warehouse_id' => 'inventory_warehouses',
                'location_id' => 'inventory_locations',
                'batch_id' => 'inventory_batches',
                'created_by' => 'pos_users',
                'shift_session_id' => 'pos_user_shift_sessions',
            ] as $column => $table) {
                $this->normaliseOptionalReference($movement, $column, $table, $node, $sourceReferences);
            }
            $movement['reference_id'] = $saleId;
            $movement['till_id'] = $tillId;
            $movement['metadata'] = $this->withSyncMetadata($movement['metadata'] ?? null, $node, [
                'source_references' => $sourceReferences ?: null,
            ]);
            $movement['balance_after'] = $this->applyStockProjection($node, $movement);
            $this->insertTenantRow('stock_movements', $movement, $node);
        }

        foreach ($this->rows($payload, 'cash_drawer_movements') as $movement) {
            $this->assertUuid($movement, 'cash drawer movement');
            if (! $this->cashMovementBelongsToSale($movement, $saleId, (string) ($payload['sale']['invoice_number'] ?? ''))) {
                throw new InvalidArgumentException('Sale bundle contains an unrelated cash drawer movement.');
            }
            $sourceReferences = [];
            foreach ([
                'shift_session_id' => 'pos_user_shift_sessions',
                'cashier_id' => 'pos_users',
                'approved_by' => 'pos_users',
                'created_by' => 'pos_users',
            ] as $column => $table) {
                $this->normaliseOptionalReference($movement, $column, $table, $node, $sourceReferences);
            }
            $movement['till_id'] = $tillId;
            $movement['metadata'] = $this->withSyncMetadata($movement['metadata'] ?? null, $node, [
                'source_sales_transaction_id' => $saleId,
                'source_references' => $sourceReferences ?: null,
            ]);
            $this->insertTenantRow('pos_cash_drawer_movements', $movement, $node);
        }

        if ($isCreditSale) {
            $this->postCreditSaleProjection($node, $saleId);
        }

        $cashupResult = $this->importOptionalCashupFromSaleBundle($node, $payload);

        return [
            'sale_id' => $saleId,
            'duplicate' => false,
            'line_count' => count($lineIds),
            'cashup' => $cashupResult,
        ];
    }

    public function importShiftCashup(SyncNode $node, array $payload): array
    {
        return DB::transaction(
            fn (): array => $this->importShiftCashupWithinTransaction($node, $payload),
            3
        );
    }

    private function importOptionalCashupFromSaleBundle(SyncNode $node, array $payload): ?array
    {
        if (! is_array($payload['cashup'] ?? null)) {
            return null;
        }

        return $this->importShiftCashupWithinTransaction($node, [
            'shift_session' => $payload['shift_session'] ?? null,
            'cashup' => $payload['cashup'],
            'cashup_denominations' => $payload['cashup_denominations'] ?? [],
        ]);
    }

    private function importShiftCashupWithinTransaction(SyncNode $node, array $payload): array
    {
        foreach (['pos_user_shift_sessions', 'pos_cashups'] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("SYNC_DEPENDENCY_MISSING: table:{$requiredTable}");
            }
        }

        $shift = $payload['shift_session'] ?? null;
        $cashup = $payload['cashup'] ?? null;
        if (! is_array($shift) || ! Str::isUuid((string) ($shift['id'] ?? ''))) {
            throw new InvalidArgumentException('A shift cashup bundle must contain a UUID shift-session id.');
        }
        if (! is_array($cashup) || ! Str::isUuid((string) ($cashup['id'] ?? ''))) {
            throw new InvalidArgumentException('A shift cashup bundle must contain a UUID cashup id.');
        }

        $shiftId = (string) $shift['id'];
        $cashupId = (string) $cashup['id'];
        if ((string) ($cashup['shift_session_id'] ?? '') !== $shiftId) {
            throw new InvalidArgumentException('The cashup does not belong to the supplied shift session.');
        }
        if (strtolower((string) ($shift['status'] ?? '')) !== 'closed'
            || empty($shift['closed_at'])) {
            throw new InvalidArgumentException('A shift completion event must contain a closed shift.');
        }
        $cashupStatus = strtolower((string) ($cashup['status'] ?? ''));
        if (! in_array($cashupStatus, ['closed', 'approved'], true)) {
            throw new InvalidArgumentException('A shift completion event must contain a closed or approved cashup.');
        }
        if (! empty($cashup['deleted_at'])) {
            throw new InvalidArgumentException('A deleted cashup cannot complete a shift.');
        }

        // Older edge schemas accidentally used ON UPDATE for opened_at. The
        // cashup row captured the correct opening before closing the shift.
        $capturedOpening = $cashup['cashup_opened_at'] ?? $cashup['opened_at'] ?? null;
        if ($capturedOpening) {
            $shift['opened_at'] = $capturedOpening;
        }
        $cashupTillId = $this->validatedTillId($node, $cashup['till_id'] ?? $shift['till_id'] ?? null);
        $this->importShiftSessionHeader($node, $shift, $shiftId, $cashupTillId);

        if (array_key_exists('transaction_count', $cashup)
            && is_numeric($cashup['transaction_count'])) {
            $expectedSales = max(0, (int) $cashup['transaction_count']);
            $importedSales = DB::table('sales_transactions')
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('shift_session_id', $shiftId)
                ->where('till_id', $cashupTillId)
                ->where('status', 'completed')
                ->count();
            if ($importedSales < $expectedSales) {
                // Event ordering is normally sale(s), then cashup. If a sale
                // was deferred for a missing dependency, keep completion
                // retryable rather than making Day End see a false-ready day.
                throw new RuntimeException(
                    "SYNC_DEPENDENCY_MISSING: shift_sales:{$shiftId}:{$importedSales}/{$expectedSales}"
                );
            }
            if ($importedSales > $expectedSales) {
                throw new InvalidArgumentException(
                    'The cashup transaction count is lower than the imported sales for its shift.'
                );
            }
        }

        $cashup['shift_session_id'] = $shiftId;
        $cashup['status'] = $cashupStatus;
        $cashup['till_id'] = $cashupTillId;
        $cashup['cashier_id'] = $this->requireTenantReference(
            'pos_users',
            $cashup['cashier_id'] ?? $shift['pos_user_id'] ?? null,
            $node,
            'cashup cashier'
        );
        if ((string) $cashup['cashier_id'] !== (string) ($shift['pos_user_id'] ?? '')) {
            throw new InvalidArgumentException('The cashup cashier does not own the supplied shift session.');
        }
        $sourceReferences = [];
        foreach ([
            'shift_configuration_id' => 'shiftconfigurations',
            'closed_by' => 'pos_users',
        ] as $column => $table) {
            $this->normaliseOptionalReference($cashup, $column, $table, $node, $sourceReferences);
        }
        unset($cashup['deleted_at']);

        $prepared = $this->prepareTenantRow('pos_cashups', $cashup, $node);

        $existingById = DB::table('pos_cashups')->where('id', $cashupId)->lockForUpdate()->first();
        if ($existingById
            && ((string) $existingById->company_id !== (string) $node->company_id
                || (string) $existingById->branch_id !== (string) $node->branch_id)) {
            throw new InvalidArgumentException('The cashup UUID belongs to another tenant or branch.');
        }
        if ($existingById && (string) $existingById->shift_session_id !== $shiftId) {
            throw new InvalidArgumentException('The cashup UUID is already assigned to another shift session.');
        }
        if ($existingById
            && property_exists($existingById, 'deleted_at')
            && $existingById->deleted_at !== null) {
            throw new InvalidArgumentException(
                'SYNC_CASHUP_CONFLICT: the existing cashup UUID was deleted and cannot complete a shift.'
            );
        }

        $natural = DB::table('pos_cashups')
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('shift_session_id', $shiftId)
            ->lockForUpdate()
            ->first();
        if ($natural && (string) $natural->id !== $cashupId) {
            // A disconnected edge and Central can legitimately allocate
            // different UUIDs for the same one-cashup-per-shift business
            // record. Treat that as an idempotent replay only after proving
            // that all immutable scope, financial totals and denomination
            // lines are equivalent. A real disagreement remains a hard
            // conflict and is never silently overwritten.
            $this->assertEquivalentNaturalCashup(
                $node,
                $natural,
                $prepared,
                $payload['cashup_denominations'] ?? [],
                $cashupId,
                $cashupTillId
            );

            return [
                'shift_session_id' => $shiftId,
                'cashup_id' => (string) $natural->id,
                'source_cashup_id' => $cashupId,
                'duplicate' => true,
                'reconciled_by' => 'shift_session',
                'denomination_count' => is_array($payload['cashup_denominations'] ?? null)
                    ? count($payload['cashup_denominations'])
                    : 0,
            ];
        }

        if ($existingById) {
            // Closed cashups are immutable even when their UUID matches. A
            // later event may prove it is the same record (including a cloud
            // approval), but it may never rewrite financial history.
            $this->assertEquivalentNaturalCashup(
                $node,
                $existingById,
                $prepared,
                $payload['cashup_denominations'] ?? [],
                $cashupId,
                $cashupTillId
            );

            return [
                'shift_session_id' => $shiftId,
                'cashup_id' => $cashupId,
                'duplicate' => true,
                'reconciled_by' => 'cashup_id',
                'denomination_count' => is_array($payload['cashup_denominations'] ?? null)
                    ? count($payload['cashup_denominations'])
                    : 0,
            ];
        }

        DB::table('pos_cashups')->insert($prepared);

        $denominationCount = $this->importCashupDenominations(
            $node,
            $cashupId,
            $cashup['till_id'] ?? null,
            $payload['cashup_denominations'] ?? []
        );

        return [
            'shift_session_id' => $shiftId,
            'cashup_id' => $cashupId,
            'duplicate' => (bool) $existingById,
            'denomination_count' => $denominationCount,
        ];
    }

    /**
     * Prove that a natural-key collision is the same immutable cashup rather
     * than accepting whichever disconnected writer happened to arrive last.
     */
    private function assertEquivalentNaturalCashup(
        SyncNode $node,
        object $existing,
        array $incoming,
        mixed $incomingDenominations,
        string $sourceCashupId,
        ?string $cashupTillId
    ): void {
        if (property_exists($existing, 'deleted_at') && $existing->deleted_at !== null) {
            throw new InvalidArgumentException(
                'SYNC_CASHUP_CONFLICT: the existing cashup for this shift was deleted.'
            );
        }

        $existingStatus = strtolower(trim((string) ($existing->status ?? '')));
        $incomingStatus = strtolower(trim((string) ($incoming['status'] ?? '')));
        if (! in_array($existingStatus, ['closed', 'approved'], true)
            || ! in_array($incomingStatus, ['closed', 'approved'], true)) {
            throw new InvalidArgumentException(
                'SYNC_CASHUP_CONFLICT: both cashups must be completed before they can be reconciled.'
            );
        }

        $mismatches = $this->cashupImmutableMismatches($existing, $incoming);

        try {
            $this->assertEquivalentCashupDenominations(
                $node,
                (string) $existing->id,
                $sourceCashupId,
                $cashupTillId,
                $incomingDenominations
            );
        } catch (InvalidArgumentException $exception) {
            if (str_starts_with($exception->getMessage(), 'SYNC_CASHUP_CONFLICT:')) {
                $mismatches[] = 'denominations';
            } else {
                throw $exception;
            }
        }

        if ($mismatches !== []) {
            throw new InvalidArgumentException(
                'SYNC_CASHUP_CONFLICT: the existing cashup for this shift differs in immutable field(s): '
                .implode(', ', array_values(array_unique($mismatches))).'.'
            );
        }
    }

    /** @return list<string> */
    private function cashupImmutableMismatches(object $existing, array $incoming): array
    {
        $immutableFields = [
            'till_id', 'shift_configuration_id', 'shift_session_id', 'cashier_id',
            'business_mode', 'boh_enabled', 'fast_food_mode_enabled', 'trading_date',
            'cashup_period_key', 'cashup_bracket_key', 'cashup_period_label',
            'cashup_opened_at', 'cashup_closed_at', 'cashup_currency_code',
            'base_currency_code', 'opening_float', 'expected_cash', 'counted_cash',
            'cash_variance', 'cashout_total', 'cashout_count',
            'expected_cash_after_cashouts', 'expected_total', 'counted_total',
            'total_variance', 'transaction_count', 'void_count', 'refund_count',
            'payment_summary', 'currency_summary', 'sales_summary', 'cashout_summary',
            'shift_policy_snapshot', 'business_mode_snapshot', 'notes',
            'force_close_reason', 'opened_at', 'closed_at',
        ];
        $mismatches = [];
        foreach ($immutableFields as $field) {
            if (! property_exists($existing, $field)) {
                continue;
            }
            // Absence cannot prove equivalence. A schema-old source must be
            // upgraded or reviewed instead of silently accepting a natural
            // key collision without all immutable destination values.
            if (! array_key_exists($field, $incoming)) {
                $mismatches[] = $field;

                continue;
            }
            if ($this->cashupSemanticValue($field, $existing->{$field})
                !== $this->cashupSemanticValue($field, $incoming[$field])) {
                $mismatches[] = $field;
            }
        }

        return $mismatches;
    }

    private function assertEquivalentCashupDenominations(
        SyncNode $node,
        string $existingCashupId,
        string $sourceCashupId,
        ?string $cashupTillId,
        mixed $rows
    ): void {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('cashup_denominations must be a JSON array.');
        }
        if (! Schema::hasTable('pos_cashup_denominations')) {
            if ($rows !== []) {
                throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:pos_cashup_denominations');
            }

            return;
        }

        $incoming = [];
        foreach ($rows as $denomination) {
            if (! is_array($denomination)) {
                throw new InvalidArgumentException('cashup_denominations contains an invalid row.');
            }
            $this->assertUuid($denomination, 'cashup denomination');
            if (isset($denomination['cashup_id'])
                && (string) $denomination['cashup_id'] !== $sourceCashupId) {
                throw new InvalidArgumentException('A cashup denomination references another cashup.');
            }
            $currency = strtoupper(trim((string) ($denomination['currency_code'] ?? '')));
            $quantity = filter_var($denomination['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($currency === ''
                || ! is_numeric($denomination['denomination_value'] ?? null)
                || (float) $denomination['denomination_value'] < 0
                || $quantity === false
                || (int) $quantity < 0
                || ! is_numeric($denomination['line_total'] ?? null)) {
                throw new InvalidArgumentException('A cashup denomination contains invalid values.');
            }
            $tillId = $this->validatedTillId(
                $node,
                $denomination['till_id'] ?? $cashupTillId
            );
            if ($cashupTillId && $tillId !== $cashupTillId) {
                throw new InvalidArgumentException('A cashup denomination belongs to another terminal.');
            }
            $incoming[] = $this->cashupDenominationSignature([
                ...$denomination,
                'till_id' => $tillId,
                'currency_code' => $currency,
                'quantity' => (int) $quantity,
            ]);
        }

        $existing = DB::table('pos_cashup_denominations')
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('cashup_id', $existingCashupId)
            ->lockForUpdate()
            ->get()
            ->map(fn ($row): string => $this->cashupDenominationSignature((array) $row))
            ->all();

        sort($incoming, SORT_STRING);
        sort($existing, SORT_STRING);
        if ($incoming !== $existing) {
            throw new InvalidArgumentException(
                'SYNC_CASHUP_CONFLICT: denomination lines do not match the existing cashup.'
            );
        }
    }

    private function cashupDenominationSignature(array $row): string
    {
        return implode('|', [
            trim((string) ($row['till_id'] ?? '')),
            strtoupper(trim((string) ($row['currency_code'] ?? ''))),
            $this->cashupNumericValue($row['denomination_value'] ?? null),
            (string) ((int) ($row['quantity'] ?? 0)),
            $this->cashupNumericValue($row['line_total'] ?? null),
            strtolower(trim((string) ($row['denomination_type'] ?? ''))),
            (string) ((int) ($row['sort_order'] ?? 0)),
        ]);
    }

    private function cashupSemanticValue(string $field, mixed $value): string
    {
        if ($value === null) {
            return '__NULL__';
        }

        if (in_array($field, [
            'opening_float', 'expected_cash', 'counted_cash', 'cash_variance',
            'cashout_total', 'expected_cash_after_cashouts', 'expected_total',
            'counted_total', 'total_variance',
        ], true)) {
            return $this->cashupNumericValue($value);
        }
        if (in_array($field, ['cashout_count', 'transaction_count', 'void_count', 'refund_count'], true)) {
            return is_numeric($value) ? (string) ((int) $value) : trim((string) $value);
        }
        if (in_array($field, ['boh_enabled', 'fast_food_mode_enabled'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
        }
        if (in_array($field, [
            'payment_summary', 'currency_summary', 'sales_summary', 'cashout_summary',
            'shift_policy_snapshot', 'business_mode_snapshot',
        ], true)) {
            return $this->canonicalCashupJson($value);
        }
        if (in_array($field, ['cashup_opened_at', 'cashup_closed_at', 'opened_at', 'closed_at'], true)) {
            $timestamp = strtotime((string) $value);

            return $timestamp === false ? trim((string) $value) : gmdate('Y-m-d H:i:s', $timestamp);
        }
        if ($field === 'trading_date') {
            $timestamp = strtotime((string) $value);

            return $timestamp === false ? trim((string) $value) : gmdate('Y-m-d', $timestamp);
        }
        if (in_array($field, ['cashup_currency_code', 'base_currency_code'], true)) {
            return strtoupper(trim((string) $value));
        }
        if ($field === 'business_mode') {
            return strtolower(trim((string) $value));
        }

        return trim((string) $value);
    }

    private function cashupNumericValue(mixed $value): string
    {
        return is_numeric($value)
            ? number_format(round((float) $value, 4), 4, '.', '')
            : trim((string) $value);
    }

    private function canonicalCashupJson(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (! is_array($value)) {
            return trim((string) $value);
        }

        $normalise = function (array $items) use (&$normalise): array {
            if (array_is_list($items)) {
                $items = array_map(
                    static fn ($item) => is_array($item) ? $normalise($item) : $item,
                    $items
                );
                usort($items, static fn ($left, $right): int => strcmp(
                    json_encode($left, JSON_UNESCAPED_SLASHES),
                    json_encode($right, JSON_UNESCAPED_SLASHES)
                ));

                return $items;
            }

            ksort($items, SORT_STRING);
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $normalise($item);
                }
            }

            return $items;
        };

        return (string) json_encode($normalise($value), JSON_UNESCAPED_SLASHES);
    }

    private function importCashupDenominations(
        SyncNode $node,
        string $cashupId,
        mixed $cashupTillId,
        mixed $rows
    ): int {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('cashup_denominations must be a JSON array.');
        }
        if ($rows !== [] && ! Schema::hasTable('pos_cashup_denominations')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:pos_cashup_denominations');
        }

        foreach ($rows as $denomination) {
            if (! is_array($denomination)) {
                throw new InvalidArgumentException('cashup_denominations contains an invalid row.');
            }
            $this->assertUuid($denomination, 'cashup denomination');
            if (isset($denomination['cashup_id'])
                && (string) $denomination['cashup_id'] !== $cashupId) {
                throw new InvalidArgumentException('A cashup denomination references another cashup.');
            }
            $denominationId = (string) $denomination['id'];
            $denomination['cashup_id'] = $cashupId;
            $denomination['currency_code'] = strtoupper((string) ($denomination['currency_code'] ?? ''));
            if ($denomination['currency_code'] === ''
                || ! is_numeric($denomination['denomination_value'] ?? null)
                || (float) $denomination['denomination_value'] < 0
                || filter_var($denomination['quantity'] ?? null, FILTER_VALIDATE_INT) === false
                || (int) $denomination['quantity'] < 0) {
                throw new InvalidArgumentException('A cashup denomination contains invalid values.');
            }
            $denomination['till_id'] = $this->validatedTillId(
                $node,
                $denomination['till_id'] ?? $cashupTillId
            );
            if ($cashupTillId && $denomination['till_id'] !== (string) $cashupTillId) {
                throw new InvalidArgumentException('A cashup denomination belongs to another terminal.');
            }

            $existing = DB::table('pos_cashup_denominations')
                ->where('id', $denominationId)
                ->lockForUpdate()
                ->first();
            if ($existing
                && ((string) $existing->company_id !== (string) $node->company_id
                    || (string) $existing->branch_id !== (string) $node->branch_id
                    || (string) $existing->cashup_id !== $cashupId)) {
                throw new InvalidArgumentException('The cashup denomination UUID belongs to another tenant or cashup.');
            }

            $natural = DB::table('pos_cashup_denominations')
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('cashup_id', $cashupId)
                ->where('currency_code', $denomination['currency_code'])
                ->where('denomination_value', $denomination['denomination_value'] ?? null)
                ->lockForUpdate()
                ->first();
            if ($natural && (string) $natural->id !== $denominationId) {
                throw new InvalidArgumentException('This cashup denomination already exists under another UUID.');
            }

            $prepared = $this->prepareTenantRow('pos_cashup_denominations', $denomination, $node);
            if ($existing) {
                $updates = $prepared;
                unset($updates['id'], $updates['company_id'], $updates['branch_id'], $updates['cashup_id'], $updates['created_at']);
                DB::table('pos_cashup_denominations')
                    ->where('id', $denominationId)
                    ->where('company_id', $node->company_id)
                    ->where('branch_id', $node->branch_id)
                    ->where('cashup_id', $cashupId)
                    ->update($updates);
            } else {
                DB::table('pos_cashup_denominations')->insert($prepared);
            }
        }

        return count($rows);
    }

    private function importTradingDayHeader(
        SyncNode $node,
        mixed $header,
        mixed $expectedId
    ): ?string {
        if (! is_scalar($expectedId) || trim((string) $expectedId) === '') {
            return null;
        }
        $expectedId = trim((string) $expectedId);
        if (! is_array($header)) {
            return $expectedId;
        }
        if ((string) ($header['id'] ?? '') !== $expectedId || ! Str::isUuid($expectedId)) {
            throw new InvalidArgumentException('The trading-day header does not match the sale.');
        }
        if (! Schema::hasTable('trading_days')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:trading_days');
        }

        $existing = DB::table('trading_days')->where('id', $expectedId)->first();
        if ($existing) {
            if ((string) $existing->company_id !== (string) $node->company_id
                || (string) $existing->branch_id !== (string) $node->branch_id) {
                throw new InvalidArgumentException('The trading-day UUID belongs to another tenant.');
            }

            return $expectedId;
        }

        $tradingDate = $header['trading_date'] ?? null;
        if ($tradingDate) {
            $natural = DB::table('trading_days')
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->whereDate('trading_date', $tradingDate)
                ->first();
            if ($natural) {
                return (string) $natural->id;
            }
        }

        $sourceReferences = [];
        foreach ([
            'day_end_started_by' => 'pos_users',
            'closed_by' => 'pos_users',
        ] as $column => $table) {
            $this->normaliseOptionalReference($header, $column, $table, $node, $sourceReferences);
        }
        $header['metadata'] = $this->withSyncMetadata($header['metadata'] ?? null, $node, [
            'source_references' => $sourceReferences ?: null,
            'header_scope' => 'sale_reference',
        ]);
        $this->insertTenantRow('trading_days', $header, $node);

        return $expectedId;
    }

    private function importShiftSessionHeader(
        SyncNode $node,
        mixed $header,
        mixed $expectedId,
        ?string $tillId = null
    ): ?string {
        if (! is_scalar($expectedId) || trim((string) $expectedId) === '') {
            return null;
        }
        $expectedId = trim((string) $expectedId);
        if (! is_array($header)) {
            return $expectedId;
        }
        if ((string) ($header['id'] ?? '') !== $expectedId || ! Str::isUuid($expectedId)) {
            throw new InvalidArgumentException('The shift-session header does not match the sale.');
        }
        if (! Schema::hasTable('pos_user_shift_sessions')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:pos_user_shift_sessions');
        }

        $posUserId = $this->requireTenantReference(
            'pos_users',
            $header['pos_user_id'] ?? null,
            $node,
            'shift-session user'
        );
        $header['pos_user_id'] = $posUserId;
        $header['till_id'] = $this->validatedTillId($node, $header['till_id'] ?? $tillId);
        if ($tillId && $header['till_id'] !== $tillId) {
            throw new InvalidArgumentException('The shift session belongs to another till.');
        }
        $sourceReferences = [];
        $this->normaliseOptionalReference(
            $header,
            'shift_configuration_id',
            'shiftconfigurations',
            $node,
            $sourceReferences
        );

        $existing = DB::table('pos_user_shift_sessions')->where('id', $expectedId)->lockForUpdate()->first();
        if ($existing) {
            if ((string) $existing->company_id !== (string) $node->company_id
                || (string) $existing->branch_id !== (string) $node->branch_id) {
                throw new InvalidArgumentException('The shift-session UUID belongs to another tenant.');
            }
            if ((string) $existing->pos_user_id !== $posUserId) {
                throw new InvalidArgumentException('The shift-session UUID belongs to another cashier.');
            }
            if ($existing->till_id && (string) $existing->till_id !== $header['till_id']) {
                throw new InvalidArgumentException('The shift-session UUID belongs to another till.');
            }
            if (! $existing->till_id) {
                foreach (['sales_transactions', 'sales_transaction_payments', 'pos_cash_drawer_movements', 'pos_cashups'] as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'till_id')
                        || ! Schema::hasColumn($table, 'shift_session_id')) {
                        continue;
                    }
                    if (DB::table($table)->where('company_id', $node->company_id)
                        ->where('branch_id', $node->branch_id)
                        ->where('shift_session_id', $expectedId)
                        ->whereNotNull('till_id')
                        ->where('till_id', '!=', $header['till_id'])->exists()) {
                        throw new InvalidArgumentException('Legacy shift records conflict with the supplied till.');
                    }
                }
                DB::table('pos_user_shift_sessions')->where('id', $expectedId)
                    ->where('company_id', $node->company_id)
                    ->where('branch_id', $node->branch_id)
                    ->update(['till_id' => $header['till_id']]);
            }

            if (strtolower((string) ($header['status'] ?? '')) === 'closed') {
                if (empty($header['closed_at'])) {
                    throw new InvalidArgumentException('A closed shift session must include its closure time.');
                }

                // opened_at is immutable after the first import. Historical
                // edge schemas had ON UPDATE CURRENT_TIMESTAMP on that column,
                // so copying it during closure could erase the real opening.
                $updates = array_intersect_key($header, array_flip([
                    'closed_at', 'closing_cash', 'status', 'updated_at',
                ]));
                $updates['status'] = 'closed';
                $updates = array_intersect_key(
                    $updates,
                    array_flip(Schema::getColumnListing('pos_user_shift_sessions'))
                );
                DB::table('pos_user_shift_sessions')
                    ->where('id', $expectedId)
                    ->where('company_id', $node->company_id)
                    ->where('branch_id', $node->branch_id)
                    ->update($updates);
            }

            return $expectedId;
        }

        if (! $header['till_id']) {
            throw new InvalidArgumentException('A new shift session requires a registered till.');
        }
        $this->insertTenantRow('pos_user_shift_sessions', $header, $node);

        return $expectedId;
    }

    private function postCreditSaleProjection(SyncNode $node, string $saleId): void
    {
        if (! Schema::hasTable('credit_customers') || ! Schema::hasTable('credit_customer_ledger')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: credit customer accounting tables');
        }

        $sale = SalesTransaction::query()
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->find($saleId);
        if (! $sale || ! $sale->credit_customer_id) {
            throw new InvalidArgumentException('A credit sale must identify a credit customer.');
        }

        // The sale UUID is the idempotency boundary. This defensive lookup
        // also protects historical/recovery imports from ever double-posting.
        if (DB::table('credit_customer_ledger')
            ->where('company_id', $node->company_id)
            ->where('sales_transaction_id', $saleId)
            ->where('entry_type', 'sale')
            ->exists()) {
            return;
        }

        $customer = CreditCustomer::query()
            ->where('company_id', $node->company_id)
            ->where(fn ($scope) => $scope
                ->whereNull('branch_id')
                ->orWhere('branch_id', $node->branch_id))
            ->find($sale->credit_customer_id);
        if (! $customer) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: credit_customers:{$sale->credit_customer_id}");
        }

        $amount = round((float) ($sale->credit_amount ?: $sale->total_amount), 4);
        if ($amount <= 0) {
            throw new InvalidArgumentException('A credit sale amount must be greater than zero.');
        }

        $metadata = is_array($sale->metadata) ? $sale->metadata : [];
        app(CreditCustomerService::class)->postCreditSale(
            sale: $sale,
            customer: $customer,
            amount: $amount,
            currencyCode: (string) ($sale->currency_code ?: $customer->currency_code ?: 'USD'),
            baseCurrencyCode: $sale->base_currency_code,
            exchangeRate: (float) ($sale->exchange_rate ?: 1),
            shiftSessionId: $sale->shift_session_id,
            tillId: $sale->till_id,
            cashierId: $sale->cashier_id,
            poNumber: is_scalar($metadata['po_number'] ?? null) ? (string) $metadata['po_number'] : null,
            notes: 'Credit sale imported from paired edge node '.$node->node_code.'.'
        );
    }

    /**
     * Apply the movement to the cloud's materialised stock projections before
     * writing its immutable ledger row. This method runs inside the sale
     * import transaction, and the duplicate-sale guard ensures a retried event
     * can never apply the delta twice.
     */
    private function applyStockProjection(SyncNode $node, array &$movement): float
    {
        if (! Schema::hasTable('product_stocks')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:product_stocks');
        }

        $productId = (string) $movement['product_id'];
        $quantityIn = round((float) ($movement['quantity_in'] ?? 0), 4);
        $quantityOut = round((float) ($movement['quantity_out'] ?? 0), 4);
        $delta = round($quantityIn - $quantityOut, 4);
        $stockArea = strtolower(trim((string) ($movement['stock_area'] ?? 'front')));
        if (! in_array($stockArea, ['front', 'back'], true)) {
            throw new InvalidArgumentException('The stock movement contains an unsupported stock area.');
        }
        $movement['stock_area'] = $stockArea;

        $key = [
            'company_id' => (string) $node->company_id,
            'branch_id' => (string) $node->branch_id,
            'product_id' => $productId,
        ];
        if (Schema::hasColumn('product_stocks', 'stock_area')) {
            $key['stock_area'] = $stockArea;
        }

        $now = now();
        $initial = [
            'id' => (string) Str::uuid(),
            ...$key,
            'quantity_on_hand' => 0,
            'reserved_quantity' => 0,
            'available_quantity' => 0,
        ];
        if (Schema::hasColumn('product_stocks', 'created_at')) {
            $initial['created_at'] = $now;
        }
        if (Schema::hasColumn('product_stocks', 'updated_at')) {
            $initial['updated_at'] = $now;
        }
        DB::table('product_stocks')->insertOrIgnore($initial);

        $stockQuery = DB::table('product_stocks');
        foreach ($key as $column => $value) {
            $stockQuery->where($column, $value);
        }
        $stock = $stockQuery->lockForUpdate()->first();
        if (! $stock) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: product_stocks:{$productId}");
        }

        $quantityOnHand = round((float) $stock->quantity_on_hand + $delta, 4);
        $reserved = round((float) ($stock->reserved_quantity ?? 0), 4);
        $updates = [
            'quantity_on_hand' => $quantityOnHand,
            'available_quantity' => round($quantityOnHand - $reserved, 4),
        ];
        if (Schema::hasColumn('product_stocks', 'updated_at')) {
            $updates['updated_at'] = $now;
        }
        $stockQuery->update($updates);

        $this->applyCostProjection($node, $movement, $quantityIn, $quantityOut);
        $this->applyBatchProjection($node, $movement, $delta);
        $this->applyLocationProjection($node, $movement, $delta);

        return $quantityOnHand;
    }

    private function applyCostProjection(
        SyncNode $node,
        array $movement,
        float $quantityIn,
        float $quantityOut
    ): void {
        if (! Schema::hasTable('product_costs')) {
            throw new RuntimeException('SYNC_DEPENDENCY_MISSING: table:product_costs');
        }

        $key = [
            'company_id' => (string) $node->company_id,
            'branch_id' => (string) $node->branch_id,
            'product_id' => (string) $movement['product_id'],
        ];
        $initial = [
            'id' => (string) Str::uuid(),
            ...$key,
            'average_cost' => 0,
            'last_cost' => 0,
            'total_quantity' => 0,
            'total_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $initial = array_intersect_key(
            $initial,
            array_flip(Schema::getColumnListing('product_costs'))
        );
        DB::table('product_costs')->insertOrIgnore($initial);

        $query = DB::table('product_costs');
        foreach ($key as $column => $value) {
            $query->where($column, $value);
        }
        $cost = $query->lockForUpdate()->first();
        if (! $cost) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: product_costs:{$movement['product_id']}");
        }

        $unitCost = round((float) ($movement['unit_cost'] ?? $cost->average_cost ?? $cost->last_cost ?? 0), 4);
        $reportedTotal = abs(round((float) ($movement['total_cost'] ?? 0), 4));
        $inValue = round($unitCost * $quantityIn, 4);
        $outValue = round($unitCost * $quantityOut, 4);
        if ($unitCost == 0.0 && $reportedTotal > 0) {
            if ($quantityIn > 0 && $quantityOut <= 0) {
                $inValue = $reportedTotal;
            } elseif ($quantityOut > 0 && $quantityIn <= 0) {
                $outValue = $reportedTotal;
            }
        }

        $newQuantity = max(0, round(
            (float) ($cost->total_quantity ?? 0) + $quantityIn - $quantityOut,
            4
        ));
        $newValue = max(0, round(
            (float) ($cost->total_value ?? 0) + $inValue - $outValue,
            4
        ));
        $updates = [
            'total_quantity' => $newQuantity,
            'total_value' => $newValue,
            'average_cost' => $newQuantity > 0 ? round($newValue / $newQuantity, 4) : 0,
            'last_cost' => (float) ($cost->last_cost ?? 0) ?: $unitCost,
        ];
        $currency = $movement['currency_code'] ?? $movement['currency'] ?? null;
        foreach (['currency', 'currency_code'] as $column) {
            if ($currency !== null && Schema::hasColumn('product_costs', $column)) {
                $updates[$column] = $currency;
            }
        }
        if (Schema::hasColumn('product_costs', 'updated_at')) {
            $updates['updated_at'] = now();
        }
        $query->update($updates);
    }

    private function applyBatchProjection(SyncNode $node, array &$movement, float $delta): void
    {
        $batchId = isset($movement['batch_id']) && is_scalar($movement['batch_id'])
            ? trim((string) $movement['batch_id'])
            : '';
        if ($batchId === '' || ! Schema::hasTable('inventory_batches')) {
            return;
        }

        $batchQuery = DB::table('inventory_batches')
            ->where('id', $batchId)
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('product_id', $movement['product_id']);
        $batch = $batchQuery->lockForUpdate()->first();
        if (! $batch) {
            throw new InvalidArgumentException('The stock movement batch does not belong to its product and branch.');
        }
        foreach (['warehouse_id', 'location_id'] as $column) {
            if (! empty($movement[$column])
                && ! empty($batch->{$column})
                && (string) $movement[$column] !== (string) $batch->{$column}) {
                throw new InvalidArgumentException("The stock movement {$column} does not match its batch.");
            }
            $movement[$column] = $batch->{$column} ?: ($movement[$column] ?? null);
        }

        $batchQuantity = round((float) $batch->quantity_on_hand + $delta, 4);
        $batchUpdates = ['quantity_on_hand' => $batchQuantity];
        if (Schema::hasColumn('inventory_batches', 'updated_at')) {
            $batchUpdates['updated_at'] = now();
        }
        $batchQuery->update($batchUpdates);
    }

    private function applyLocationProjection(SyncNode $node, array $movement, float $delta): void
    {
        if (! Schema::hasTable('inventory_location_balances')) {
            return;
        }

        $locationId = isset($movement['location_id']) && is_scalar($movement['location_id'])
            ? trim((string) $movement['location_id'])
            : '';
        if ($locationId === '') {
            return;
        }
        $location = DB::table('inventory_locations')
            ->where('id', $locationId)
            ->where('company_id', $node->company_id)
            ->where(fn ($scope) => $scope
                ->whereNull('branch_id')
                ->orWhere('branch_id', $node->branch_id))
            ->lockForUpdate()
            ->first();
        if (! $location) {
            throw new InvalidArgumentException('The stock movement location does not belong to this branch.');
        }
        $warehouseId = isset($movement['warehouse_id']) && is_scalar($movement['warehouse_id'])
            ? trim((string) $movement['warehouse_id'])
            : '';
        $warehouseId = $warehouseId !== '' ? $warehouseId : (string) $location->warehouse_id;
        if ((string) $location->warehouse_id !== $warehouseId) {
            throw new InvalidArgumentException('The stock movement location does not belong to its warehouse.');
        }
        $batchId = isset($movement['batch_id']) && is_scalar($movement['batch_id'])
            ? trim((string) $movement['batch_id'])
            : '';
        $locationKey = [
            'company_id' => (string) $node->company_id,
            'branch_id' => (string) $node->branch_id,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'product_id' => (string) $movement['product_id'],
            'batch_id' => $batchId !== '' ? $batchId : null,
        ];
        $locationQuery = DB::table('inventory_location_balances');
        foreach ($locationKey as $column => $value) {
            if (Schema::hasColumn('inventory_location_balances', $column)) {
                $value === null
                    ? $locationQuery->whereNull($column)
                    : $locationQuery->where($column, $value);
            }
        }
        $balance = $locationQuery->lockForUpdate()->first();
        if (! $balance) {
            $locationInitial = [
                'id' => (string) Str::uuid(),
                ...$locationKey,
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $locationInitial = array_intersect_key(
                $locationInitial,
                array_flip(Schema::getColumnListing('inventory_location_balances'))
            );
            DB::table('inventory_location_balances')->insert($locationInitial);
            $locationQuery = DB::table('inventory_location_balances')
                ->where('id', $locationInitial['id']);
            $balance = $locationQuery->lockForUpdate()->first();
        }
        if (! $balance) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: inventory_location_balances:{$locationId}");
        }
        $onHand = round((float) $balance->quantity_on_hand + $delta, 4);
        $reserved = round((float) ($balance->reserved_quantity ?? 0), 4);
        $locationUpdates = [
            'quantity_on_hand' => $onHand,
            'available_quantity' => round($onHand - $reserved, 4),
        ];
        if (Schema::hasColumn('inventory_location_balances', 'updated_at')) {
            $locationUpdates['updated_at'] = now();
        }
        $locationQuery->update($locationUpdates);
    }

    private function insertTenantRow(string $table, array $row, SyncNode $node): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->insert($this->prepareTenantRow($table, $row, $node));
    }

    private function prepareTenantRow(string $table, array $row, SyncNode $node): array
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: table:{$table}");
        }

        $allowed = array_flip($this->availableAllowedColumns($table));
        $row = array_intersect_key($row, $allowed);
        $row['company_id'] = (string) $node->company_id;
        $row['branch_id'] = (string) $node->branch_id;

        foreach ([
            'metadata',
            'fiscal_response',
            'payment_summary',
            'currency_summary',
            'sales_summary',
            'cashout_summary',
            'shift_policy_snapshot',
            'business_mode_snapshot',
        ] as $jsonColumn) {
            if (array_key_exists($jsonColumn, $row) && is_array($row[$jsonColumn])) {
                $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_UNESCAPED_SLASHES);
            }
        }

        if (! isset($row['created_at']) && Schema::hasColumn($table, 'created_at')) {
            $row['created_at'] = now();
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $row['updated_at'] = $row['updated_at'] ?? now();
        }

        return $row;
    }

    private function availableAllowedColumns(string $table): array
    {
        $available = array_flip(Schema::getColumnListing($table));
        $columns = array_values(array_filter(
            self::ALLOWED[$table] ?? [],
            static fn (string $column) => isset($available[$column])
        ));

        foreach (['company_id', 'branch_id'] as $tenantColumn) {
            if (isset($available[$tenantColumn])) {
                $columns[] = $tenantColumn;
            }
        }

        return array_values(array_unique($columns));
    }

    private function requireTenantReference(
        string $table,
        mixed $id,
        SyncNode $node,
        string $label
    ): string {
        if (! is_scalar($id) || trim((string) $id) === '') {
            throw new InvalidArgumentException("The {$label} reference is required.");
        }
        $id = trim((string) $id);
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: {$table}:{$id}");
        }

        if ($this->referenceIsInTenantScope($table, $id, $node)) {
            return $id;
        }

        if (DB::table($table)->where('id', $id)->exists()) {
            throw new InvalidArgumentException("The {$label} belongs to another company or branch.");
        }

        throw new RuntimeException("SYNC_DEPENDENCY_MISSING: {$table}:{$id}");
    }

    /**
     * Optional operational references are retained when they are in tenant
     * scope. Missing local-only rows are nulled while their source UUID is
     * preserved in immutable sync metadata. Cross-tenant UUIDs are rejected.
     */
    private function normaliseOptionalReference(
        array &$row,
        string $column,
        string $table,
        SyncNode $node,
        array &$sourceReferences
    ): void {
        if (! array_key_exists($column, $row)
            || $row[$column] === null
            || $row[$column] === '') {
            return;
        }
        if (! is_scalar($row[$column])) {
            throw new InvalidArgumentException("The {$column} reference is invalid.");
        }

        $id = trim((string) $row[$column]);
        if (Schema::hasTable($table)) {
            if ($this->referenceIsInTenantScope($table, $id, $node)) {
                return;
            }
            if (DB::table($table)->where('id', $id)->exists()) {
                throw new InvalidArgumentException("The {$column} reference belongs to another tenant.");
            }
        }

        $sourceReferences[$column] = $id;
        $row[$column] = null;
    }

    /**
     * Apply one tenant/branch rule to required and optional references.
     *
     * A POS user's branch_id is only its primary/default branch. Additional
     * branch access is authoritative through user_branches, but both the user
     * and (when present) the pivot company must still match the node tenant so
     * a forged cross-company assignment cannot widen access.
     */
    private function referenceIsInTenantScope(string $table, string $id, SyncNode $node): bool
    {
        $query = DB::table($table)->where('id', $id);
        if (Schema::hasColumn($table, 'company_id')) {
            $query->where('company_id', $node->company_id);
        }
        if (Schema::hasColumn($table, 'branch_id')) {
            $query->where(fn ($scope) => $scope
                ->whereNull("{$table}.branch_id")
                ->orWhere("{$table}.branch_id", $node->branch_id));
        }
        if ($query->exists()) {
            return true;
        }

        if ($table !== 'pos_users'
            || ! Schema::hasTable('user_branches')
            || ! Schema::hasColumn('user_branches', 'pos_user_id')
            || ! Schema::hasColumn('user_branches', 'branch_id')) {
            return false;
        }

        $userQuery = DB::table('pos_users')->where('id', $id);
        if (Schema::hasColumn('pos_users', 'company_id')) {
            $userQuery->where('company_id', $node->company_id);
        }
        if (! $userQuery->exists()) {
            return false;
        }

        $assignment = DB::table('user_branches')
            ->where('pos_user_id', $id)
            ->where('branch_id', $node->branch_id);
        if (Schema::hasColumn('user_branches', 'company_id')) {
            $assignment->where('company_id', $node->company_id);
        }

        return $assignment->exists();
    }

    private function validatedTillId(SyncNode $node, mixed $payloadTillId): ?string
    {
        // The edge node is a branch/server identity and may serve several LAN
        // terminals. Preserve the terminal recorded by the sale; node.till_id
        // is only a backwards-compatible default for older payloads.
        $payloadId = is_scalar($payloadTillId) ? trim((string) $payloadTillId) : '';
        $tillId = $payloadId !== '' ? $payloadId : ($node->till_id ?: null);
        if (! $tillId) {
            return null;
        }

        $valid = DB::table('pos_tills')
            ->where('id', $tillId)
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->exists();

        if (! $valid) {
            throw new InvalidArgumentException('The sale terminal is not registered for this branch.');
        }

        return $tillId;
    }

    private function cloudClientSaleId(SyncNode $node, string $saleId, mixed $source): string
    {
        $identity = is_scalar($source) && trim((string) $source) !== '' ? trim((string) $source) : $saleId;

        return 'SYNC-'.strtoupper(substr(str_replace('-', '', (string) $node->id), 0, 10))
            .'-'.strtoupper(substr(hash('sha256', $identity), 0, 40));
    }

    private function availableInvoiceNumber(SyncNode $node, string $saleId, string $source): string
    {
        $source = trim($source) !== '' ? trim($source) : $saleId;
        $collision = DB::table('sales_transactions')
            ->where('invoice_number', $source)
            ->where('id', '!=', $saleId)
            ->exists();

        if (! $collision) {
            return $source;
        }

        $suffix = '-N'.strtoupper(substr(str_replace('-', '', (string) $node->id), 0, 8));

        return substr($source, 0, max(1, 255 - strlen($suffix))).$suffix;
    }

    private function withSyncMetadata(mixed $metadata, SyncNode $node, array $extra = []): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return [
            ...(is_array($metadata) ? $metadata : []),
            ...array_filter($extra, static fn ($value) => $value !== null),
            'sync' => [
                'source_node_id' => (string) $node->id,
                'source_node_code' => (string) $node->node_code,
                'received_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function cashMovementBelongsToSale(array $movement, string $saleId, string $invoice): bool
    {
        $metadata = $movement['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        return (string) ($metadata['source_sales_transaction_id'] ?? '') === $saleId
            || ($invoice !== '' && (string) ($movement['reference_no'] ?? '') === $invoice);
    }

    private function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException("{$key} must be a JSON array.");
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException("{$key} contains an invalid row.");
            }
        }

        return $rows;
    }

    private function assertUuid(array $row, string $label): void
    {
        if (! Str::isUuid((string) ($row['id'] ?? ''))) {
            throw new InvalidArgumentException("The {$label} id must be a UUID.");
        }
    }
}
