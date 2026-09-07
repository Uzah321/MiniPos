<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShiftStatus;
use App\Enums\TillStatus;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Shift;
use App\Services\AuditLogger;
use App\Services\BasketService;
use App\Services\ManagerVerifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function __construct(
        private readonly BasketService $basketService,
        private readonly AuditLogger $auditLogger,
        private readonly ManagerVerifier $managerVerifier,
    ) {}

    /**
     * Start sale: open a new draft basket for the authenticated cashier's
     * open shift and till on the given terminal.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'terminal_id' => ['required', 'exists:terminals,id'],
        ]);

        $shift = Shift::query()
            ->where('user_id', $request->user()->id)
            ->where('terminal_id', $data['terminal_id'])
            ->where('status', ShiftStatus::Open)
            ->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'terminal_id' => ['You do not have an open shift on this terminal.'],
            ]);
        }

        if (! $shift->tills()->where('status', TillStatus::Open)->exists()) {
            throw ValidationException::withMessages([
                'terminal_id' => ['Open a till before starting a sale.'],
            ]);
        }

        $sale = Sale::create([
            'store_id' => $shift->terminal->store_id,
            'terminal_id' => $shift->terminal_id,
            'shift_id' => $shift->id,
            'cashier_id' => $request->user()->id,
        ]);

        $this->auditLogger->log($request->user(), 'sale.started', $sale, after: $sale->toArray());

        return response()->json(['sale' => $sale->load('lines')], 201);
    }

    public function show(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        return response()->json(['sale' => $sale->load('lines.item', 'payments')]);
    }

    /**
     * Scan or search + select item: add it to the basket, merging quantity
     * into an existing line for the same item.
     */
    public function addLine(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate([
            'item_id' => ['required_without_all:sku,barcode', 'nullable', 'integer', 'exists:items,id'],
            'sku' => ['required_without_all:item_id,barcode', 'nullable', 'string'],
            'barcode' => ['required_without_all:item_id,sku', 'nullable', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $item = $this->resolveItem($data);

        $line = $this->basketService->addLine($sale, $item, (string) ($data['quantity'] ?? 1));

        return response()->json(['sale' => $sale->fresh()->load('lines.item'), 'line' => $line], 201);
    }

    /**
     * Change quantity on an existing basket line.
     */
    public function updateLine(Request $request, Sale $sale, SaleLine $line)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $this->basketService->updateLineQuantity($sale, $line, (string) $data['quantity']);

        return response()->json(['sale' => $sale->fresh()->load('lines.item')]);
    }

    /**
     * Remove item before payment: requires a reason, and a manager PIN once
     * the line's value reaches the configured override threshold.
     */
    public function destroyLine(Request $request, Sale $sale, SaleLine $line)
    {
        $this->assertOwnedByCashier($request, $sale);

        if ($line->sale_id !== $sale->id) {
            throw ValidationException::withMessages(['line' => ['This line does not belong to the given sale.']]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $manager = null;
        $threshold = config('pos.line_removal_manager_threshold');

        if (bccomp((string) $line->line_total, (string) $threshold, 4) >= 0) {
            $manager = $this->managerVerifier->verify($data['manager_pin'] ?? null);
        }

        $before = $line->toArray();
        $this->basketService->removeLine($sale, $line);

        $this->auditLogger->log(
            $manager ?? $request->user(),
            'sale.line.removed',
            $sale,
            before: $before,
            reason: $data['reason'],
        );

        return response()->json(['sale' => $sale->fresh()->load('lines.item')]);
    }

    private function resolveItem(array $data): Item
    {
        $item = isset($data['item_id'])
            ? Item::find($data['item_id'])
            : Item::query()
                ->where(function ($query) use ($data) {
                    if (! empty($data['sku'])) {
                        $query->orWhere('sku', $data['sku']);
                    }
                    if (! empty($data['barcode'])) {
                        $query->orWhere('barcode', $data['barcode']);
                    }
                })
                ->first();

        if (! $item || ! $item->active) {
            throw ValidationException::withMessages(['item' => ['Item not found or not available for sale.']]);
        }

        return $item;
    }

    private function assertOwnedByCashier(Request $request, Sale $sale): void
    {
        if ($sale->cashier_id !== $request->user()->id) {
            throw ValidationException::withMessages(['sale' => ['This sale does not belong to you.']]);
        }
    }
}
