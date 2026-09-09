<?php

namespace App\Shifts;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Terminals\Terminal;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CashDrawerService $cashDrawer,
    ) {}

    public function open(Request $request)
    {
        $data = $request->validate([
            'terminal_id' => ['required', 'exists:terminals,id'],
        ]);

        $terminal = Terminal::findOrFail($data['terminal_id']);

        $activeShift = Shift::query()
            ->where('terminal_id', $terminal->id)
            ->where('status', ShiftStatus::Open)
            ->first();

        if ($activeShift) {
            throw ValidationException::withMessages([
                'terminal_id' => ['This terminal already has an active cashier shift.'],
            ]);
        }

        $shift = Shift::create([
            'user_id' => $request->user()->id,
            'terminal_id' => $terminal->id,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $this->auditLogger->log($request->user(), 'shift.open', $shift, after: $shift->toArray());

        return response()->json(['shift' => $shift], 201);
    }

    /**
     * Close shift: every till must already be counted, reconciled and
     * closed, and no basket or payment on this shift may still be active.
     */
    public function close(Request $request, Shift $shift)
    {
        if ($shift->user_id !== $request->user()->id) {
            throw ValidationException::withMessages(['shift' => ['This shift does not belong to you.']]);
        }

        $shift = $this->cashDrawer->closeShift($shift, $request->user());

        return response()->json(['shift' => $shift]);
    }

    /**
     * Terminal unavailable: move the cashier's open shift to a replacement
     * terminal in the same store, so they can continue on new hardware
     * without opening a second shift or duplicating a sale.
     */
    public function reassignTerminal(Request $request, Shift $shift)
    {
        if ($shift->user_id !== $request->user()->id) {
            throw ValidationException::withMessages(['shift' => ['This shift does not belong to you.']]);
        }

        if ($shift->status !== ShiftStatus::Open) {
            throw ValidationException::withMessages(['shift' => ['Only an open shift can be moved to another terminal.']]);
        }

        $data = $request->validate(['terminal_id' => ['required', 'exists:terminals,id']]);

        $terminal = Terminal::findOrFail($data['terminal_id']);

        if ($terminal->store_id !== $shift->terminal->store_id) {
            throw ValidationException::withMessages(['terminal_id' => ['The replacement terminal must belong to the same store.']]);
        }

        if ($terminal->id === $shift->terminal_id) {
            throw ValidationException::withMessages(['terminal_id' => ['Choose a different terminal to move to.']]);
        }

        $activeShift = Shift::query()
            ->where('terminal_id', $terminal->id)
            ->where('status', ShiftStatus::Open)
            ->exists();

        if ($activeShift) {
            throw ValidationException::withMessages(['terminal_id' => ['This terminal already has an active cashier shift.']]);
        }

        $before = $shift->toArray();
        $shift->update(['terminal_id' => $terminal->id]);

        $this->auditLogger->log($request->user(), 'shift.terminal_reassigned', $shift, before: $before, after: ['terminal_id' => $terminal->id]);

        return response()->json(['shift' => $shift->fresh()]);
    }
}
