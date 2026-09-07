<?php

namespace App\Shifts;

use App\Shifts\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Shifts\Shift;
use App\Terminals\Terminal;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

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
}
