<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\GeneralSetting;

class PosGeneralSettingController extends Controller
{
    public function __construct(
        private readonly CompanyBranchContextService $branchContext
    ) {}

    public function barcodeScan(Request $request)
    {
        return $this->booleanSettingResponse($request, 'barcode_scan_enabled', 'sales');
    }

    public function touchKeyboard(Request $request)
    {
        return $this->booleanSettingResponse($request, 'touch_keyboard_enabled', 'sales');
    }

    public function salesScreenLayout(Request $request)
    {
        $company = app('company');
        $branchId = $this->branchContext->resolve($request, (string) $company->id);

        $setting = $this->findSetting(
            companyId: $company->id,
            settingKey: 'sales_screen_layout',
            module: 'sales',
            branchId: $branchId
        );

        $value = $setting?->setting_value ?: 'standard';

        if (!in_array($value, ['standard', 'visual'], true)) {
            $value = 'standard';
        }

        return response()->json([
            'message' => 'Setting fetched successfully.',
            'data' => [
                'setting_key' => 'sales_screen_layout',
                'setting_value' => $value,
                'setting_type' => 'string',
                'module' => 'sales',
                'allowed_values' => ['standard', 'visual'],
                'is_active' => (bool) ($setting?->is_active ?? true),
                'company_id' => $company->id,
                'branch_id' => $setting?->branch_id,
            ],
        ]);
    }

    public function updateSalesScreenLayout(Request $request)
    {
        $company = app('company');
        $validated = $request->validate([
            'setting_value' => ['required', 'string', Rule::in(['standard', 'visual'])],
            'branch_id' => [
                'nullable',
                'uuid',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
        ]);

        $branchId = $this->branchContext->resolve($request, (string) $company->id);

        $setting = GeneralSetting::updateOrCreate(
            [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'setting_key' => 'sales_screen_layout',
            ],
            [
                'setting_value' => $validated['setting_value'],
                'setting_type' => 'string',
                'module' => 'sales',
                'is_active' => true,
            ]
        );

        return response()->json([
            'message' => 'Sales screen layout updated successfully.',
            'data' => [
                'setting_key' => $setting->setting_key,
                'setting_value' => $setting->setting_value,
                'setting_type' => $setting->setting_type,
                'module' => $setting->module,
                'allowed_values' => ['standard', 'visual'],
                'is_active' => (bool) $setting->is_active,
                'company_id' => $setting->company_id,
                'branch_id' => $setting->branch_id,
            ],
        ]);
    }

    public function show(Request $request)
    {
        $company = app('company');

        $settingKey = $request->input('setting_key') ?: $request->input('key');
        $module = $request->input('module');
        $branchId = $this->branchContext->resolve($request, (string) $company->id);

        if (!$settingKey) {
            return response()->json([
                'message' => 'setting_key is required.',
            ], 422);
        }

        $setting = $this->findSetting(
            companyId: $company->id,
            settingKey: $settingKey,
            module: $module,
            branchId: $branchId
        );

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'message' => 'Setting fetched successfully.',
            'data' => $setting,
        ]);
    }

    private function booleanSettingResponse(Request $request, string $settingKey, string $module)
    {
        $company = app('company');
        $branchId = $this->branchContext->resolve($request, (string) $company->id);

        $setting = $this->findSetting(
            companyId: $company->id,
            settingKey: $settingKey,
            module: $module,
            branchId: $branchId
        );

        $enabled = $setting
            ? filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN)
            : false;

        return response()->json([
            'message' => 'Setting fetched successfully.',
            'data' => [
                'setting_key' => $settingKey,
                'setting_value' => $enabled ? 'true' : 'false',
                'setting_type' => 'boolean',
                'module' => $module,
                'is_active' => (bool) ($setting?->is_active ?? false),
                'is_effectively_enabled' => (bool) ($setting && $enabled),
                'company_id' => $company->id,
                'branch_id' => $setting?->branch_id,
            ],
        ]);
    }

    private function findSetting(string $companyId, string $settingKey, ?string $module = null, ?string $branchId = null)
    {
        return GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('setting_key', $settingKey)
            ->when($module, fn ($query) => $query->where('module', $module))
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }
}
