<?php

namespace Modules\FiscalDevice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Zimra\ZimraFiscalDayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\FiscalDevice\Models\FiscalDevice;
use Modules\Sales\Models\FiscalDay;

class FiscalDayController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');
        $query = FiscalDay::where('company_id', $company->id)->with('device')->latest();
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return response()->json(['message' => 'Fiscal days fetched successfully.', 'data' => $query->paginate($request->per_page ?? 50)]);
    }

    public function open(Request $request, ZimraFiscalDayService $service)
    {
        $company = app('company');
        $validator = Validator::make($request->all(), ['fiscal_device_id' => 'required|exists:fiscal_devices,id']);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        try {
            $device = FiscalDevice::where('company_id', $company->id)->where('id', $request->fiscal_device_id)->firstOrFail();
            return response()->json(['message' => 'Fiscal day opened successfully.', 'data' => $service->open($device, auth()->id())]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Fiscal day opening failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function close(string $fiscalDay, ZimraFiscalDayService $service)
    {
        try {
            $day = $this->findFiscalDay($fiscalDay);
            return response()->json(['message' => 'Fiscal day close initiated successfully.', 'data' => $service->close($day, auth()->id())]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Fiscal day close failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function poll(string $fiscalDay, ZimraFiscalDayService $service)
    {
        try {
            $day = $this->findFiscalDay($fiscalDay);
            return response()->json(['message' => 'Fiscal day status updated successfully.', 'data' => $service->poll($day)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Fiscal day status polling failed.', 'error' => $e->getMessage()], 500);
        }
    }

    private function findFiscalDay(string $id): FiscalDay
    {
        $company = app('company');
        return FiscalDay::where('company_id', $company->id)->where('id', $id)->firstOrFail();
    }
}
