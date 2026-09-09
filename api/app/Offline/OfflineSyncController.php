<?php

namespace App\Offline;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OfflineSyncController extends Controller
{
    public function __construct(private readonly OfflineSyncService $offlineSync) {}

    /**
     * Offline synchronisation: uploads queued offline sales in the order
     * given. Each is deduplicated by client_reference, so a retried or
     * overlapping upload never creates a second sale for the same offline
     * transaction.
     */
    public function sync(Request $request)
    {
        $data = $request->validate([
            'transactions' => ['required', 'array', 'min:1'],
            'transactions.*.client_reference' => ['required', 'string'],
            'transactions.*.shift_id' => ['required'],
            'transactions.*.table_id' => ['nullable', 'integer'],
            'transactions.*.lines' => ['required', 'array', 'min:1'],
            'transactions.*.lines.*.item_id' => ['required', 'integer'],
            'transactions.*.lines.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'transactions.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.occurred_at' => ['nullable', 'date'],
        ]);

        $results = $this->offlineSync->syncBatch($data['transactions'], $request->user());

        return response()->json(['results' => $results]);
    }
}
