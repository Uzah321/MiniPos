<?php

namespace Modules\ZimraIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\ZimraIntegration\Models\ZimraCompanySetting;

class ZimraCompanySettingController extends Controller
{
    public function show()
    {
        $company = app('company');
        $setting = ZimraCompanySetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'enabled' => false,
                'environment' => config('zimra.default_environment', 'testing'),
                'block_sales_without_open_day' => true,
                'block_sale_if_fiscalisation_fails' => false,
            ]
        );
        return response()->json(['message' => 'ZIMRA settings fetched successfully.', 'data' => $setting]);
    }

    public function update(Request $request)
    {
        $company = app('company');
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'environment' => 'required|string|in:testing,production',
            'base_url' => 'nullable|url',
            'auto_open_day' => 'nullable|boolean',
            'block_sales_without_open_day' => 'nullable|boolean',
            'block_sale_if_fiscalisation_fails' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }
        $setting = ZimraCompanySetting::updateOrCreate(
            ['company_id' => $company->id],
            [
                'enabled' => $request->boolean('enabled'),
                'environment' => $request->environment,
                'base_url' => $request->base_url,
                'auto_open_day' => $request->boolean('auto_open_day'),
                'block_sales_without_open_day' => $request->boolean('block_sales_without_open_day', true),
                'block_sale_if_fiscalisation_fails' => $request->boolean('block_sale_if_fiscalisation_fails', false),
                'metadata' => $request->metadata,
            ]
        );
        return response()->json(['message' => 'ZIMRA settings updated successfully.', 'data' => $setting]);
    }
}
