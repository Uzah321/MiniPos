<?php

namespace App\Terminals;

use App\Terminals\TerminalStatus;
use App\Http\Controllers\Controller;
use App\Terminals\Terminal;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    private const PERIPHERALS = [
        'scanner', 'receipt_printer', 'cash_drawer', 'payment_terminal', 'customer_display', 'kitchen_printer',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'registration_code' => ['required', 'string'],
            'store_id' => ['required', 'exists:stores,id'],
            'name' => ['nullable', 'string'],
            'app_version' => ['nullable', 'string'],
        ]);

        $terminal = Terminal::firstOrNew([
            'registration_code' => $data['registration_code'],
            'store_id' => $data['store_id'],
        ]);

        $terminal->fill([
            'name' => $data['name'] ?? $terminal->name,
            'app_version' => $data['app_version'] ?? null,
            'status' => TerminalStatus::Active,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['terminal' => $terminal]);
    }

    public function status(Terminal $terminal)
    {
        $terminal->update(['last_seen_at' => now()]);

        return response()->json([
            'terminal' => $terminal,
            'ready' => $terminal->status === TerminalStatus::Active,
        ]);
    }

    /**
     * Peripheral readiness: record the outcome of testing each connected
     * peripheral and report overall readiness, or log the exception.
     */
    public function peripheralCheck(Request $request, Terminal $terminal)
    {
        $data = $request->validate([
            'checks' => ['required', 'array', 'min:1'],
            'checks.*.peripheral' => ['required', 'string', 'in:'.implode(',', self::PERIPHERALS)],
            'checks.*.status' => ['required', 'in:ok,fail'],
            'checks.*.detail' => ['nullable', 'string'],
        ]);

        $overall = collect($data['checks'])->every(fn (array $check) => $check['status'] === 'ok') ? 'ready' : 'exception';

        $terminal->update([
            'peripheral_status' => $data['checks'],
            'peripheral_checked_at' => now(),
        ]);

        $this->auditLogger->log(
            $request->user(),
            'terminal.peripheral_check',
            $terminal,
            after: ['checks' => $data['checks'], 'overall' => $overall],
        );

        return response()->json([
            'terminal' => $terminal,
            'overall' => $overall,
        ]);
    }
}
