<?php

namespace App\Services;

use Modules\Authentication\Models\GeneralSetting;
use Modules\Company\Models\CompanyBranch;

class CompanySettingService
{
    public function get(string $companyId, string $key, mixed $default = null, ?string $branchId = null): mixed
    {
        $setting = GeneralSetting::where('company_id', $companyId)
            ->where('setting_key', $key)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if (! $setting) {
            return $default;
        }

        return match ($setting->setting_type) {
            'boolean' => filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->setting_value,
            'decimal' => (float) $setting->setting_value,
            default => $setting->setting_value,
        };
    }

    public function enabled(string $companyId, string $key, ?string $branchId = null): bool
    {
        return (bool) $this->get($companyId, $key, false, $branchId);
    }

    public function isFastFoodMode(string $companyId, ?string $branchId = null): bool
    {
        if ((bool) config('servnest.hospitality_only', true)) {
            return true;
        }

        return $this->enabled($companyId, 'fast_food_mode_enabled', $branchId);
    }

    public function isBohEnabled(string $companyId, ?string $branchId = null): bool
    {
        if ((bool) config('servnest.hospitality_only', true)) {
            return true;
        }

        return $this->enabled($companyId, 'boh_enabled', $branchId);
    }

    public function isHospitalityMode(string $companyId, ?string $branchId = null): bool
    {
        if ((bool) config('servnest.hospitality_only', true)) {
            return true;
        }

        return $this->isBohEnabled($companyId, $branchId)
            && $this->isFastFoodMode($companyId, $branchId)
            && $this->enabled($companyId, 'receive_stock_to_boh_first', $branchId)
            && $this->enabled($companyId, 'recipe_consumes_front_stock', $branchId);
    }

    public function businessMode(string $companyId, ?string $branchId = null): string
    {
        return 'hospitality';
    }

    /**
     * @param  array<int, string>|null  $candidateBranchIds
     * @return array<int, string>
     */
    public function branchIdsForMode(string $companyId, string $mode, ?array $candidateBranchIds = null): array
    {
        $mode = strtolower(trim($mode));
        if ($mode !== 'hospitality') {
            return [];
        }

        $branchIds = CompanyBranch::query()
            ->where('company_id', $companyId)
            ->when($candidateBranchIds !== null, fn ($query) => $query->whereIn('id', $candidateBranchIds))
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        return $branchIds
            ->filter(fn ($branchId) => $this->businessMode($companyId, $branchId) === $mode)
            ->values()
            ->all();
    }
}
