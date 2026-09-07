<?php

namespace App\Shifts;

use App\Shifts\ShiftStatus;
use App\Shifts\TillStatus;
use App\Http\Controllers\Controller;
use App\Shifts\Shift;
use App\Shifts\Till;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TillController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

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

            $manager = User::role('manager')
                ->get()
                ->first(fn (User $candidate) => $candidate->pin_hash && Hash::check($data['manager_pin'], $candidate->pin_hash));

            if (! $manager) {
                throw ValidationException::withMessages([
                    'manager_pin' => ['Manager PIN could not be verified.'],
                ]);
            }
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

        $this->auditLogger->log($request->user(), 'till.open', $till, after: $till->toArray());

        return response()->json(['till' => $till], 201);
    }
}
