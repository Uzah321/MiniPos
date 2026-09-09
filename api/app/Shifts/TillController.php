<?php

namespace App\Shifts;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ManagerVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TillController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CashDrawerService $cashDrawer,
        private readonly ManagerVerifier $managerVerifier,
    ) {}

    public function open(Request $request)
    {
        $data = $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'opening_float' => ['required', 'numeric', 'min:0'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $shift = Shift::findOrFail($data['shift_id']);

        if ($shift->user_id !== $request->user()->id || $shift->status !== ShiftStatus::Open) {
            throw ValidationException::withMessages([
                'shift_id' => ['This shift is not open for this cashier.'],
            ]);
        }

        $manager = null;
        $threshold = config('pos.till_open_manager_threshold');

        if ($data['opening_float'] >= $threshold) {
            if (empty($data['manager_pin'])) {
                throw ValidationException::withMessages([
                    'manager_pin' => ["Manager verification is required for an opening float of {$threshold} or more."],
                ]);
            }

            $manager = $this->managerVerifier->verify($data['manager_pin'], $shift->terminal->store_id);
        }

        $till = DB::transaction(function () use ($shift, $data, $manager) {
            // Locking the shift row serialises concurrent opens for the
            // same shift, so two simultaneous requests can't both slip
            // past the "no active till" check before either commits.
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($shift->tills()->where('status', TillStatus::Open)->exists()) {
                throw ValidationException::withMessages([
                    'shift_id' => ['This shift already has an open till.'],
                ]);
            }

            $till = Till::create([
                'shift_id' => $shift->id,
                'opening_float' => $data['opening_float'],
                'status' => TillStatus::Open,
                'manager_verified_by' => $manager?->id,
            ]);

            $till->cashMovements()->create([
                'type' => 'open',
                'amount' => $data['opening_float'],
                'reason' => 'Opening float',
                'approved_by' => $manager?->id,
            ]);

            return $till;
        });

        $this->auditLogger->log($request->user(), 'till.open', $till, after: $till->toArray());

        return response()->json(['till' => $till], 201);
    }

    /**
     * No-sale drawer open: always requires manager approval, and never
     * changes expected cash.
     */
    public function noSale(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $data = $request->validate([
            'reason' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $movement = $this->cashDrawer->noSale($till, $data['reason'], $data['manager_pin'] ?? null, $request->user());

        return response()->json(['cash_movement' => $movement], 201);
    }

    public function paidIn(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $movement = $this->cashDrawer->paidIn($till, (string) $data['amount'], $data['reason'], $data['manager_pin'] ?? null, $request->user());

        return response()->json(['cash_movement' => $movement], 201);
    }

    public function paidOut(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $movement = $this->cashDrawer->paidOut($till, (string) $data['amount'], $data['reason'], $data['manager_pin'] ?? null, $request->user());

        return response()->json(['cash_movement' => $movement], 201);
    }

    public function cashDrop(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $movement = $this->cashDrawer->cashDrop($till, (string) $data['amount'], $data['manager_pin'] ?? null, $request->user());

        return response()->json(['cash_movement' => $movement], 201);
    }

    /**
     * Count the drawer. A variance at or beyond the configured threshold
     * puts the shift under manager review before the till can close.
     */
    public function count(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $till = $this->cashDrawer->count($till, (string) $data['counted_amount'], $request->user());

        return response()->json(['till' => $till]);
    }

    public function resolveVariance(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $data = $request->validate([
            'explanation' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $till = $this->cashDrawer->resolveVariance($till, $data['explanation'], $data['manager_pin'] ?? null, $request->user());

        return response()->json(['till' => $till]);
    }

    public function close(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        $till = $this->cashDrawer->closeTill($till, $request->user());

        return response()->json(['till' => $till]);
    }

    /**
     * X report: interim totals for this till's shift, without closing
     * anything or stopping new sales.
     */
    public function xReport(Request $request, Till $till)
    {
        $this->assertOwnedByCashier($request, $till);

        return response()->json(['report' => $this->cashDrawer->xReport($till)]);
    }

    private function assertOwnedByCashier(Request $request, Till $till): void
    {
        if ($till->shift->user_id !== $request->user()->id) {
            throw ValidationException::withMessages(['till' => ['This till does not belong to your shift.']]);
        }
    }
}
