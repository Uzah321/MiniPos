<?php

namespace App\Tables;

use App\Http\Controllers\Controller;
use App\Sales\BasketService;
use App\Sales\BillSplitService;
use App\Sales\Sale;
use App\Sales\SaleLine;
use App\Sales\SaleStatus;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TableController extends Controller
{
    private const OPEN_SALE_STATUSES = [
        SaleStatus::Draft, SaleStatus::Priced, SaleStatus::PaymentPending,
        SaleStatus::Paid, SaleStatus::Committed, SaleStatus::PaymentUnknown,
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly BillSplitService $billSplitService,
        private readonly BasketService $basketService,
    ) {}

    /**
     * Floor view: list this store's tables with their current status.
     */
    public function index(Request $request)
    {
        $tables = RestaurantTable::query()
            ->where('store_id', $request->user()->store_id)
            ->with('server')
            ->orderBy('label')
            ->get();

        return response()->json(['tables' => $tables]);
    }

    /**
     * Seat guests: assign a server and guest count to an available table.
     */
    public function occupy(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'server_id' => ['nullable', 'exists:users,id'],
        ]);

        if ($table->status !== TableStatus::Available) {
            throw ValidationException::withMessages([
                'table' => ['This table is not available.'],
            ]);
        }

        $before = $table->toArray();

        $table->update([
            'status' => TableStatus::Occupied,
            'server_id' => $data['server_id'] ?? $request->user()->id,
            'guest_count' => $data['guest_count'] ?? null,
        ]);

        $this->auditLogger->log($request->user(), 'table.occupy', $table, before: $before, after: $table->toArray());

        return response()->json(['table' => $table]);
    }

    /**
     * Move the active tab from this table to an available one, freeing this table.
     */
    public function transfer(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'to_table_id' => ['required', 'exists:tables,id'],
        ]);

        if ((int) $data['to_table_id'] === $table->id) {
            throw ValidationException::withMessages([
                'to_table_id' => ['Choose a different table to transfer to.'],
            ]);
        }

        if (! in_array($table->status, [TableStatus::Occupied, TableStatus::BillRequested], true)) {
            throw ValidationException::withMessages([
                'table' => ['This table has no active tab to transfer.'],
            ]);
        }

        $toTable = RestaurantTable::findOrFail($data['to_table_id']);
        $this->assertOwnedByStore($request, $toTable);

        if ($toTable->status !== TableStatus::Available) {
            throw ValidationException::withMessages([
                'to_table_id' => ['The destination table is not available.'],
            ]);
        }

        $table->sales()->whereIn('status', self::OPEN_SALE_STATUSES)->update(['table_id' => $toTable->id]);
        $table->kitchenOrders()->update(['table_id' => $toTable->id]);

        $toTable->update([
            'status' => $table->status,
            'server_id' => $table->server_id,
            'guest_count' => $table->guest_count,
        ]);

        $before = $table->toArray();
        $table->update(['status' => TableStatus::Available, 'server_id' => null, 'guest_count' => null]);

        $this->auditLogger->log($request->user(), 'table.transfer', $table, before: $before, after: ['moved_to' => $toTable->id]);

        return response()->json(['table' => $table->fresh(), 'to_table' => $toTable->fresh()]);
    }

    /**
     * Merge one or more occupied tables into this one: their open bills and
     * kitchen orders move here, their guest counts are added, and they free up.
     */
    public function merge(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'from_table_ids' => ['required', 'array', 'min:1'],
            'from_table_ids.*' => ['distinct', 'exists:tables,id'],
        ]);

        if (in_array($table->id, $data['from_table_ids'])) {
            throw ValidationException::withMessages([
                'from_table_ids' => ['The destination table cannot merge into itself.'],
            ]);
        }

        if (! in_array($table->status, [TableStatus::Occupied, TableStatus::BillRequested], true)) {
            throw ValidationException::withMessages([
                'table' => ['The destination table must already be occupied.'],
            ]);
        }

        $sourceTables = RestaurantTable::whereIn('id', $data['from_table_ids'])->get();

        foreach ($sourceTables as $sourceTable) {
            $this->assertOwnedByStore($request, $sourceTable);

            if (! in_array($sourceTable->status, [TableStatus::Occupied, TableStatus::BillRequested], true)) {
                throw ValidationException::withMessages([
                    'from_table_ids' => ["Table {$sourceTable->label} has no active tab to merge."],
                ]);
            }
        }

        $incompatiblePayment = Sale::query()
            ->whereIn('table_id', [$table->id, ...$data['from_table_ids']])
            ->whereIn('status', [SaleStatus::PaymentPending, SaleStatus::PaymentUnknown])
            ->exists();

        if ($incompatiblePayment) {
            throw ValidationException::withMessages([
                'table' => ['A bill involved in this merge has a payment in progress.'],
            ]);
        }

        $mergedGuestCount = (int) $table->guest_count;

        foreach ($sourceTables as $sourceTable) {
            $sourceTable->sales()->whereIn('status', self::OPEN_SALE_STATUSES)->update(['table_id' => $table->id]);
            $sourceTable->kitchenOrders()->update(['table_id' => $table->id]);

            $mergedGuestCount += (int) $sourceTable->guest_count;

            $before = $sourceTable->toArray();
            $sourceTable->update(['status' => TableStatus::Available, 'server_id' => null, 'guest_count' => null]);

            $this->auditLogger->log($request->user(), 'table.merge', $sourceTable, before: $before, after: ['merged_into' => $table->id]);
        }

        $table->update(['guest_count' => $mergedGuestCount ?: null]);

        return response()->json(['table' => $table->fresh(), 'merged_tables' => $sourceTables->fresh()]);
    }

    /**
     * Split this bill: moves the given quantity of each selected line into
     * a brand-new bill on the same table, e.g. splitting by item or by guest.
     */
    public function split(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $sale = $this->resolveSaleOnTable($table, $data['sale_id']);

        $result = $this->billSplitService->split($sale, $data['lines'], $request->user());

        return response()->json([
            'sale' => $result['source']->load('lines.item'),
            'new_sale' => $result['new']->load('lines.item'),
        ], 201);
    }

    /**
     * Move a single item from one guest's bill to another, both on this table.
     */
    public function moveItem(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'from_sale_id' => ['required', 'exists:sales,id'],
            'to_sale_id' => ['required', 'exists:sales,id'],
            'sale_line_id' => ['required', 'exists:sale_lines,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $from = $this->resolveSaleOnTable($table, $data['from_sale_id']);
        $to = $this->resolveSaleOnTable($table, $data['to_sale_id']);
        $line = SaleLine::findOrFail($data['sale_line_id']);

        $result = $this->billSplitService->moveLine($from, $to, $line, $data['quantity'] ?? null, $request->user());

        return response()->json([
            'from_sale' => $result['from']->load('lines.item'),
            'to_sale' => $result['to']->load('lines.item'),
        ]);
    }

    /**
     * Apply the store's configured service charge (or an override rate) to a bill.
     */
    public function serviceCharge(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $sale = $this->resolveSaleOnTable($table, $data['sale_id']);

        $sale = $this->basketService->applyServiceCharge($sale, isset($data['rate']) ? (string) $data['rate'] : null);

        return response()->json(['sale' => $sale]);
    }

    /**
     * Set the tip collected against a bill.
     */
    public function tip(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        $data = $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $sale = $this->resolveSaleOnTable($table, $data['sale_id']);

        $sale = $this->basketService->setTip($sale, (string) $data['amount']);

        return response()->json(['sale' => $sale]);
    }

    /**
     * Waiter requests the bill for the table: signals the till to print it.
     */
    public function requestBill(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        if ($table->status !== TableStatus::Occupied) {
            throw ValidationException::withMessages([
                'table' => ['This table is not currently occupied.'],
            ]);
        }

        $table->update(['status' => TableStatus::BillRequested]);

        $this->auditLogger->log($request->user(), 'table.bill_requested', $table);

        return response()->json(['table' => $table]);
    }

    /**
     * Free the table once its sale is settled (paid, voided, or otherwise closed out).
     */
    public function close(Request $request, RestaurantTable $table)
    {
        $this->assertOwnedByStore($request, $table);

        if ($table->sales()->whereIn('status', self::OPEN_SALE_STATUSES)->exists()) {
            throw ValidationException::withMessages([
                'table' => ['This table still has an unsettled sale.'],
            ]);
        }

        $before = $table->toArray();
        $table->update(['status' => TableStatus::Available, 'server_id' => null, 'guest_count' => null]);

        $this->auditLogger->log($request->user(), 'table.close', $table, before: $before, after: $table->toArray());

        return response()->json(['table' => $table]);
    }

    private function resolveSaleOnTable(RestaurantTable $table, string $saleId): Sale
    {
        $sale = Sale::findOrFail($saleId);

        if ($sale->table_id !== $table->id) {
            throw ValidationException::withMessages(['sale_id' => ['This bill does not belong to the given table.']]);
        }

        return $sale;
    }

    private function assertOwnedByStore(Request $request, RestaurantTable $table): void
    {
        if ($table->store_id !== $request->user()->store_id) {
            throw ValidationException::withMessages(['table' => ['This table does not belong to your store.']]);
        }
    }
}
