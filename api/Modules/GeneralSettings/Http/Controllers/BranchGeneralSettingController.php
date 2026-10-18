<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Authentication\Models\GeneralSetting;
use Modules\Company\Models\CompanyBranch;
use function App\Helpers\hasPermission;

class BranchGeneralSettingController extends Controller
{
    public function index(Request $request, CompanyBranch $branch)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'manage_general_settings', $company->id);
        if ($result !== true) return $result;

        if ($branch->company_id !== $company->id) {
            return response()->json([
                'message' => 'Branch does not belong to this company',
            ], 403);
        }

        $companySettings = GeneralSetting::where('company_id', $company->id)
            ->whereNull('branch_id')
            ->orderBy('module')
            ->orderBy('setting_key')
            ->get();

        $branchSettings = GeneralSetting::where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->get()
            ->keyBy('setting_key');

        $settings = $companySettings->map(function ($companySetting) use ($branchSettings, $branch) {
            $branchSetting = $branchSettings->get($companySetting->setting_key);

            return [
                'setting_key' => $companySetting->setting_key,
                'module' => $companySetting->module,
                'description' => $companySetting->description,
                'setting_type' => $companySetting->setting_type,

                'company_setting_id' => $companySetting->id,
                'branch_setting_id' => $branchSetting?->id,

                'company_default_value' => $companySetting->setting_value,
                'branch_value' => $branchSetting?->setting_value,

                'setting_value' => $branchSetting?->setting_value ?? $companySetting->setting_value,

                'company_is_active' => $companySetting->is_active,
                'branch_is_active' => $branchSetting?->is_active,

                'is_active' => $branchSetting?->is_active ?? $companySetting->is_active,

                'has_branch_override' => (bool) $branchSetting,
                'branch_id' => $branch->id,
            ];
        });

        return response()->json([
            'message' => 'Branch settings fetched successfully',
            'branch' => $branch,
            'data' => $settings->values(),
        ]);
    }

    public function update(Request $request, CompanyBranch $branch, string $settingKey)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'manage_general_settings', $company->id);
        if ($result !== true) return $result;

        if ($branch->company_id !== $company->id) {
            return response()->json([
                'message' => 'Branch does not belong to this company',
            ], 403);
        }

        $companySetting = GeneralSetting::where('company_id', $company->id)
            ->whereNull('branch_id')
            ->where('setting_key', $settingKey)
            ->first();

        if (!$companySetting) {
            return response()->json([
                'message' => 'General setting does not exist. Please seed it first.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'setting_value' => 'nullable',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchSetting = GeneralSetting::updateOrCreate(
            [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'setting_key' => $companySetting->setting_key,
            ],
            [
                'setting_value' => $this->formatSettingValue(
                    $request->has('setting_value')
                        ? $request->setting_value
                        : $companySetting->setting_value,
                    $companySetting->setting_type
                ),
                'setting_type' => $companySetting->setting_type,
                'module' => $companySetting->module,
                'description' => $companySetting->description,
                'is_active' => $request->boolean('is_active'),
            ]
        );

        return response()->json([
            'message' => 'Branch setting updated successfully',
            'setting' => $branchSetting,
        ]);
    }

    public function reset(CompanyBranch $branch, string $settingKey)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'manage_general_settings', $company->id);
        if ($result !== true) return $result;

        if ($branch->company_id !== $company->id) {
            return response()->json([
                'message' => 'Branch does not belong to this company',
            ], 403);
        }

        $deleted = GeneralSetting::where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('setting_key', $settingKey)
            ->delete();

        return response()->json([
            'message' => $deleted
                ? 'Branch setting reset to company default successfully'
                : 'Branch setting was already using company default',
        ]);
    }

    public function sync(Request $request, CompanyBranch $branch)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'manage_general_settings', $company->id);
        if ($result !== true) return $result;

        if ($branch->company_id !== $company->id) {
            return response()->json([
                'message' => 'Branch does not belong to this company',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.setting_key' => 'required|string',
            'settings.*.setting_value' => 'nullable',
            'settings.*.is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->settings as $item) {
            $companySetting = GeneralSetting::where('company_id', $company->id)
                ->whereNull('branch_id')
                ->where('setting_key', $item['setting_key'])
                ->first();

            if (!$companySetting) {
                continue;
            }

            GeneralSetting::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'setting_key' => $companySetting->setting_key,
                ],
                [
                    'setting_value' => $this->formatSettingValue(
                        array_key_exists('setting_value', $item)
                            ? $item['setting_value']
                            : $companySetting->setting_value,
                        $companySetting->setting_type
                    ),
                    'setting_type' => $companySetting->setting_type,
                    'module' => $companySetting->module,
                    'description' => $companySetting->description,
                    'is_active' => (bool) $item['is_active'],
                ]
            );
        }

        return response()->json([
            'message' => 'Branch settings synced successfully',
        ]);
    }

    private function formatSettingValue($value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'integer' => (string) intval($value),
            'decimal' => (string) floatval($value),
            'float' => (string) floatval($value),
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };
    }
}
