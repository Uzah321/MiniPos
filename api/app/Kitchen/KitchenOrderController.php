<?php

namespace App\Kitchen;

use App\Http\Controllers\Controller;
use App\Sales\Sale;
use App\Services\ManagerVerifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KitchenOrderController extends Controller
{
    public function __construct(
        private readonly KitchenOrderService $kitchenOrders,
        private readonly ManagerVerifier $managerVerifier,
    ) {}

    /**
     * Send: routes any sale lines not yet represented in this order's
     * active kitchen lines. Safe to call again after adding items.
     */
    public function send(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $order = $this->kitchenOrders->send($sale, $request->user());

        return response()->json(['kitchen_order' => $order], 201);
    }

    public function show(Request $request, KitchenOrder $kitchenOrder)
    {
        $this->assertOwnedByCashier($request, $kitchenOrder->sale);

        return response()->json(['kitchen_order' => $kitchenOrder->load('lines.item', 'table')]);
    }

    /**
     * Advance a single line to the next status in sequence, e.g. a station
     * marking its item accepted, then preparing, then ready.
     */
    public function advanceLine(Request $request, KitchenOrder $kitchenOrder, KitchenOrderLine $line)
    {
        $this->assertOwnedByCashier($request, $kitchenOrder->sale);
        $this->assertLineBelongsToOrder($kitchenOrder, $line);

        $line = $this->kitchenOrders->advanceLine($line, $request->user());

        return response()->json(['line' => $line, 'kitchen_order' => $kitchenOrder->fresh()]);
    }

    /**
     * Void a sent line: always requires manager approval, regardless of value.
     */
    public function voidLine(Request $request, KitchenOrder $kitchenOrder, KitchenOrderLine $line)
    {
        $this->assertOwnedByCashier($request, $kitchenOrder->sale);
        $this->assertLineBelongsToOrder($kitchenOrder, $line);

        $data = $request->validate([
            'reason' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $manager = $this->managerVerifier->verify($data['manager_pin'] ?? null, $kitchenOrder->sale->store_id);

        $line = $this->kitchenOrders->voidLine($line, $data['reason'], $manager);

        return response()->json(['line' => $line, 'kitchen_order' => $kitchenOrder->fresh()]);
    }

    /**
     * Resend or reprint: creates a clearly marked duplicate line rather
     * than repeating the original ticket silently.
     */
    public function resendLine(Request $request, KitchenOrder $kitchenOrder, KitchenOrderLine $line)
    {
        $this->assertOwnedByCashier($request, $kitchenOrder->sale);
        $this->assertLineBelongsToOrder($kitchenOrder, $line);

        $duplicate = $this->kitchenOrders->resendLine($line, $request->user());

        return response()->json(['line' => $duplicate, 'kitchen_order' => $kitchenOrder->fresh()], 201);
    }

    /**
     * Handover: every active line must be ready; marks them all served or
     * collected together.
     */
    public function handover(Request $request, KitchenOrder $kitchenOrder)
    {
        $this->assertOwnedByCashier($request, $kitchenOrder->sale);

        $order = $this->kitchenOrders->handover($kitchenOrder, $request->user());

        return response()->json(['kitchen_order' => $order]);
    }

    /**
     * Close: the deliberate final step once every line is served,
     * collected, cancelled or voided.
     */
    public function close(Request $request, KitchenOrder $kitchenOrder)
    {
        $this->assertOwnedByCashier($request, $kitchenOrder->sale);

        $order = $this->kitchenOrders->close($kitchenOrder, $request->user());

        return response()->json(['kitchen_order' => $order]);
    }

    private function assertLineBelongsToOrder(KitchenOrder $kitchenOrder, KitchenOrderLine $line): void
    {
        if ($line->kitchen_order_id !== $kitchenOrder->id) {
            throw ValidationException::withMessages(['line' => ['This line does not belong to the given kitchen order.']]);
        }
    }

    private function assertOwnedByCashier(Request $request, ?Sale $sale): void
    {
        if (! $sale || $sale->cashier_id !== $request->user()->id) {
            throw ValidationException::withMessages(['sale' => ['This kitchen order does not belong to your sale.']]);
        }
    }
}
