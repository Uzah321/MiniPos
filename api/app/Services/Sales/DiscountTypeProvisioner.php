<?php

namespace App\Services\Sales;

use Modules\Sales\Models\DiscountType;

/**
 * Gives a company the system discount types the Discounts screen needs: a
 * discount cannot be saved without a type, and companies created after the
 * database seeder ran previously started with none.
 *
 * Only missing codes are created. A code that exists in any state, including
 * soft-deleted, is left alone so an administrator's removal sticks and the
 * (company_id, code) unique key is never hit.
 */
class DiscountTypeProvisioner
{
    public const SYSTEM_TYPES = [
        ['code' => 'PERCENTAGE', 'name' => 'Percentage Discount', 'scope' => 'line', 'calculation_type' => 'percentage'],
        ['code' => 'FIXED_AMOUNT', 'name' => 'Fixed Amount Discount', 'scope' => 'line', 'calculation_type' => 'fixed_amount'],
        ['code' => 'FIXED_PRICE', 'name' => 'Fixed Price Discount', 'scope' => 'line', 'calculation_type' => 'fixed_price'],
        ['code' => 'CART_PERCENTAGE', 'name' => 'Cart Percentage Discount', 'scope' => 'cart', 'calculation_type' => 'percentage'],
        ['code' => 'COUPON', 'name' => 'Coupon Discount', 'scope' => 'coupon', 'calculation_type' => 'percentage'],
        ['code' => 'STAFF', 'name' => 'Staff Discount', 'scope' => 'employee', 'calculation_type' => 'percentage'],
        ['code' => 'CUSTOMER_LOYALTY', 'name' => 'Customer Loyalty Discount', 'scope' => 'customer', 'calculation_type' => 'percentage'],
        ['code' => 'MANUAL_MANAGER', 'name' => 'Manual Manager Discount', 'scope' => 'manual', 'calculation_type' => 'percentage', 'requires_manager_approval' => true],
    ];

    /** @return int number of types created */
    public function ensureCompanyDefaults(string $companyId): int
    {
        $existingCodes = DiscountType::withTrashed()
            ->where('company_id', $companyId)
            ->pluck('code')
            ->map(fn ($code): string => strtoupper((string) $code))
            ->all();

        $created = 0;
        foreach (self::SYSTEM_TYPES as $type) {
            if (in_array($type['code'], $existingCodes, true)) {
                continue;
            }

            DiscountType::create([
                'company_id' => $companyId,
                'code' => $type['code'],
                'name' => $type['name'],
                'scope' => $type['scope'],
                'calculation_type' => $type['calculation_type'],
                'requires_manager_approval' => $type['requires_manager_approval'] ?? false,
                'requires_reason' => $type['requires_reason'] ?? false,
                'allow_stacking' => $type['allow_stacking'] ?? false,
                'is_system' => true,
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }
}
