<?php

namespace Modules\FiscalDevice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Zimra\ZimraDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\FiscalDevice\Models\FiscalDevice;

class FiscalDeviceController extends Controller
{
    public function index(Request $request, ZimraDeviceService $service)
    {
        $company = app('company');
        $request->validate([
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
        ]);

        return response()->json(['message' => 'Fiscal devices fetched successfully.', 'data' => $service->listDevices($company->id, $request->branch_id)]);
    }

    public function store(Request $request, ZimraDeviceService $service)
    {
        $company = app('company');
        $certificateExt = implode(',', config('zimra.allowed_certificate_extensions', ['pem', 'crt', 'cer', 'txt']));
        $keyExt = implode(',', config('zimra.allowed_private_key_extensions', ['pem', 'key', 'txt']));
        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'device_id' => ['required', 'integer', Rule::unique('fiscal_devices', 'device_id')->where('company_id', $company->id)->ignore($request->id)],
            'device_serial_no' => 'nullable|string|max:50',
            'model_name' => 'required|string|max:255',
            'model_version' => 'required|string|max:255',
            'environment' => 'nullable|string|in:testing,production',
            'base_url' => 'nullable|url',
            'certificate_file' => 'required|file|mimes:' . $certificateExt,
            'private_key_file' => 'required|file|mimes:' . $keyExt,
            'certificate_password' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }
        try {
            $device = $service->saveDevice(
                data: [...$request->except(['certificate_file', 'private_key_file']), 'company_id' => $company->id],
                certificate: $request->file('certificate_file'),
                privateKey: $request->file('private_key_file')
            );
            return response()->json(['message' => 'Fiscal device saved successfully.', 'data' => $device], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Fiscal device save failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function syncConfig(string $device, ZimraDeviceService $service)
    {
        try {
            $model = $this->findDevice($device);
            return response()->json(['message' => 'Fiscal device config synced successfully.', 'data' => $service->syncConfig($model)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Config sync failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function status(string $device, ZimraDeviceService $service)
    {
        try {
            $model = $this->findDevice($device);
            return response()->json(['message' => 'Fiscal device status fetched successfully.', 'data' => $service->status($model)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Status fetch failed.', 'error' => $e->getMessage()], 500);
        }
    }

    private function findDevice(string $id): FiscalDevice
    {
        $company = app('company');
        return FiscalDevice::where('company_id', $company->id)->where('id', $id)->firstOrFail();
    }
}
