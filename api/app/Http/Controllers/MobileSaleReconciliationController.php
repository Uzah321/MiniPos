<?php

namespace App\Http\Controllers;

use App\Models\MobileSaleReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSaleReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MobileSaleReconciliation::query()
            ->where('company_id', app('company')->id);
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (string) $request->query('branch_id'));
        }

        return response()->json([
            'message' => 'These are unposted, unverified offline commands requiring manual accounting and fiscal review.',
            'sale_posted' => false,
            'fiscal_proof_verified' => false,
            'data' => $query->orderByDesc('created_at')
            ->select(['id', 'company_id', 'branch_id', 'till_id', 'sync_node_id', 'cashier_id',
                'client_sale_id', 'business_date', 'occurred_at', 'configuration_content_hash',
                'command_sha256', 'reason_code', 'status', 'context', 'created_at'])
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100)),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $record = MobileSaleReconciliation::query()
            ->where('company_id', app('company')->id)
            ->whereKey($id)->firstOrFail();

        return response()->json([
            'message' => 'Evidence only. No sale, stock movement, cashup, day-end or FDMS confirmation has been posted from this record.',
            'sale_posted' => false,
            'fiscal_proof_verified' => false,
            'data' => $record,
        ]);
    }
}
