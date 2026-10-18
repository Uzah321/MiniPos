<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\GeneralSetting;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductNumberSequence;

class ProductIdentityGenerator
{
    public function generateProductCode(string $companyId, ?string $branchId = null): string
    {
        return $this->generate($companyId, $branchId, 'product_code');
    }

    public function generateSku(string $companyId, ?string $branchId = null): string
    {
        return $this->generate($companyId, $branchId, 'sku');
    }

    public function shouldGenerateProductCode(string $companyId, ?string $branchId = null): bool
    {
        return $this->settingIsEnabled(
            $companyId,
            $branchId,
            'generate_product_code'
        );
    }

    public function shouldGenerateSku(string $companyId, ?string $branchId = null): bool
    {
        return $this->settingIsEnabled(
            $companyId,
            $branchId,
            'generate_product_sku'
        );
    }

    public function generateAllForProductCreate(
        string $companyId,
        ?string $branchId,
        array $data
    ): array {
        if (
            empty($data['code']) &&
            $this->shouldGenerateProductCode($companyId, $branchId)
        ) {
            $data['code'] = $this->generateProductCode($companyId, $branchId);
        }

        if (
            empty($data['sku']) &&
            $this->shouldGenerateSku($companyId, $branchId)
        ) {
            $data['sku'] = $this->generateSku($companyId, $branchId);
        }

        return $data;
    }

    /**
     * A missing code or SKU is a form error the user can fix, so it is
     * reported as a 422 against the field rather than a server failure.
     */
    public function assertProductIdentityIsComplete(array $data): void
    {
        $errors = [];

        if (empty($data['code'])) {
            $errors['code'] = ['Enter a product code, or turn on automatic product code generation in General Settings.'];
        }

        if (empty($data['sku'])) {
            $errors['sku'] = ['Enter a SKU, or turn on automatic SKU generation in General Settings.'];
        }

        if ($errors !== []) {
            throw new HttpResponseException(response()->json([
                'message' => reset($errors)[0],
                'errors' => $errors,
            ], 422));
        }
    }

    private function generate(string $companyId, ?string $branchId, string $type): string
    {
        return DB::transaction(function () use ($companyId, $branchId, $type) {
            $prefix = $this->getPrefix($companyId, $branchId, $type);
            $padding = 5;

            $sequence = ProductNumberSequence::where('company_id', $companyId)
//                ->where(function ($query) use ($branchId) {
//                    if ($branchId) {
//                        $query->where('branch_id', $branchId);
//                    } else {
//                        $query->whereNull('branch_id');
//                    }
//                })
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = ProductNumberSequence::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'type' => $type,
                    'prefix' => $prefix,
                    'current_number' => 0,
                    'padding' => $padding,
                    'is_active' => true,
                ]);
            }

            $sequence->prefix = $prefix;
            $sequence->padding = $padding;
            $sequence->current_number++;
            $sequence->save();

            $value = $this->formatNumber(
                $sequence->prefix,
                $sequence->current_number,
                $sequence->padding
            );

            while ($this->alreadyExists($companyId, $type, $value)) {
                $sequence->current_number++;
                $sequence->save();

                $value = $this->formatNumber(
                    $sequence->prefix,
                    $sequence->current_number,
                    $sequence->padding
                );
            }

            return $value;
        });
    }

    private function getPrefix(string $companyId, ?string $branchId, string $type): string
    {
        $settingKey = match ($type) {
            'product_code' => 'product_code_prefix',
            'sku' => 'sku_code_prefix',
            default => null,
        };

        $default = match ($type) {
            'product_code' => 'PRD',
            'sku' => 'SKU',
            default => '',
        };

        if (!$settingKey) {
            return $default;
        }

        return $this->getStringSetting($companyId, $branchId, $settingKey, $default);
    }

    private function settingIsEnabled(
        string $companyId,
        ?string $branchId,
        string $settingKey
    ): bool {
        $setting = $this->getSetting($companyId, $branchId, $settingKey);

        if (!$setting) {
            return false;
        }

        return (int) $setting->is_active === 1;
    }

    private function getStringSetting(
        string $companyId,
        ?string $branchId,
        string $settingKey,
        string $default = ''
    ): string {
        $setting = $this->getSetting($companyId, $branchId, $settingKey);

        if (!$setting) {
            return $default;
        }

        if ((int) $setting->is_active !== 1) {
            return $default;
        }

        if ($setting->setting_value === null || $setting->setting_value === '') {
            return $default;
        }

        return $setting->setting_value;
    }

    private function getSetting(
        string $companyId,
        ?string $branchId,
        string $settingKey
    ): ?GeneralSetting {
        if ($branchId) {
            $branchSetting = GeneralSetting::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('setting_key', $settingKey)
                ->where('module', 'product')
                ->first();

            if ($branchSetting) {
                return $branchSetting;
            }
        }

        return GeneralSetting::where('company_id', $companyId)
            ->whereNull('branch_id')
            ->where('setting_key', $settingKey)
            ->where('module', 'product')
            ->first();
    }

    private function formatNumber(?string $prefix, int $number, int $padding): string
    {
        return ($prefix ?? '') . str_pad(
                (string) $number,
                $padding,
                '0',
                STR_PAD_LEFT
            );
    }

    private function alreadyExists(string $companyId, string $type, string $value): bool
    {
        return match ($type) {
            'product_code' => Product::where('company_id', $companyId)
                ->where('code', $value)
                ->exists(),

            'sku' => Product::where('company_id', $companyId)
                ->where('sku', $value)
                ->exists(),

            default => false,
        };
    }
}
