<?php

namespace Modules\ZimraIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Zimra\ZimraReceiptService;
use Illuminate\Http\Request;
use Modules\ZimraIntegration\Models\ZimraReceipt;

class ZimraReceiptController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');
        $query = ZimraReceipt::where('company_id', $company->id)->latest();
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->date_to);
        return response()->json(['message' => 'ZIMRA receipts fetched successfully.', 'data' => $query->paginate($request->per_page ?? 50)]);
    }

    public function show(string $receipt)
    {
        $company = app('company');
        $row = ZimraReceipt::where('company_id', $company->id)->where('id', $receipt)->firstOrFail();
        return response()->json(['message' => 'ZIMRA receipt fetched successfully.', 'data' => $row]);
    }

    public function retrySale(string $saleId, ZimraReceiptService $service)
    {
        try {
            return response()->json(['message' => 'ZIMRA receipt submitted successfully.', 'data' => $service->submitSale($saleId)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'ZIMRA receipt retry failed.', 'error' => $e->getMessage()], 500);
        }
    }
}
