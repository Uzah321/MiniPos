<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use App\Services\CompanySettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesIndustryModeController extends Controller
{
    public function __construct(
        private readonly CompanySettingService $companySettingService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = app('company');
        $branchId = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );

        $bohEnabled = true;
        $fastFoodModeEnabled = true;
        $receiveStockToBohFirst = true;
        $recipeConsumesFrontStock = true;
        $fastFoodIndustryMode = true;

        return response()->json([
            'message' => 'Sales industry mode detected successfully.',
            'data' => [
                'boh_enabled' => $bohEnabled,
                'fast_food_mode_enabled' => $fastFoodModeEnabled,
                'receive_stock_to_boh_first' => $receiveStockToBohFirst,
                'recipe_consumes_front_stock' => $recipeConsumesFrontStock,
                'is_fast_food_industry_mode' => $fastFoodIndustryMode,
                'business_mode' => 'hospitality',
                'hospitality_only' => true,
                'cost_source' => 'recipe_ingredient_consumption',
                'visual_product_filter' => ['product_type' => 'finished_good'],
                'visual_cart_controls' => [
                    'increment_selected_item' => true,
                    'decrement_selected_item' => true,
                    'editable_quantity' => true,
                    'minimum_quantity' => 0.0001,
                    'merge_repeated_product_selection' => true,
                ],
            ],
        ]);
    }
}
