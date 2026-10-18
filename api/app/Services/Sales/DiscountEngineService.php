<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\GeneralSetting;
use Modules\Authentication\Models\PosUser;
use Modules\Sales\Models\Discount;
use function App\Helpers\hasPermission;

class DiscountEngineService
{
    public function discountModuleEnabled(string $companyId, ?string $branchId): bool
    {
        return $this->settingEnabled($companyId, $branchId, 'discount_module_enabled', true);
    }

    public function manualDiscountRequiresManager(string $companyId, ?string $branchId): bool
    {
        return $this->settingEnabled($companyId, $branchId, 'manual_discount_requires_manager_approval', true);
    }

    public function maxCashierDiscountPercent(string $companyId, ?string $branchId): float
    {
        return (float) $this->settingValue($companyId, $branchId, 'max_cashier_discount_percent_without_manager', 0);
    }

    private function resolveCategoryIds(string $companyId, ?string $productId, ?string $categoryId): array
    {
        $ids = [];

        if ($categoryId) $ids[] = (string) $categoryId;
        if (!$productId) return array_values(array_unique(array_filter($ids)));

        if (Schema::hasTable('products')) {
            $product = DB::table('products')
                ->where('company_id', $companyId)
                ->where('id', $productId)
                ->first(['id', 'category_id']);

            if ($product && !empty($product->category_id)) {
                $ids[] = (string) $product->category_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function discountMatchesProductTarget(Discount $discount, string $companyId, ?string $productId): bool
    {
        $hasProductTargets = DB::table('discount_products')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->exists();

        if (!$hasProductTargets) return true;
        if (!$productId) return false;

        return DB::table('discount_products')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->where('product_id', $productId)
            ->exists();
    }

    private function discountMatchesCategoryTarget(Discount $discount, string $companyId, array $categoryIds): bool
    {
        $hasCategoryTargets = DB::table('discount_categories')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->exists();

        if (!$hasCategoryTargets) return true;
        if (empty($categoryIds)) return false;

        return DB::table('discount_categories')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->whereIn('category_id', $categoryIds)
            ->exists();
    }

    private function discountMatchesRole(Discount $discount, string $companyId, ?string $cashierRoleId): bool
    {
        $hasRoleRestrictions = DB::table('discount_role_permissions')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->exists();

        if (!$hasRoleRestrictions) return true;
        if (!$cashierRoleId) return false;

        return DB::table('discount_role_permissions')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->where('role_id', $cashierRoleId)
            ->exists();
    }

    private function productCanReceiveDiscount(string $companyId, ?string $productId): bool
    {
        if (!$productId || !Schema::hasTable('products')) return true;

        $product = DB::table('products')
            ->where('company_id', $companyId)
            ->where('id', $productId)
            ->first(['id', 'allow_discount', 'is_active', 'deleted_at']);

        if (!$product) return false;
        if ($product->deleted_at !== null) return false;
        if ((int) $product->is_active !== 1) return false;
        if ((int) $product->allow_discount !== 1) return false;

        return true;
    }

    public function listEligibleDiscounts(array $context): array
    {
        $companyId = $context['company_id'];
        $branchId = $context['branch_id'] ?? null;
        $productId = $context['product_id'] ?? null;
        $categoryIds = $this->resolveCategoryIds($companyId, $productId, $context['category_id'] ?? null);

        $saleAmount = (float) ($context['sale_amount'] ?? $context['gross_amount'] ?? $context['line_amount'] ?? 0);
        $quantity = (float) ($context['quantity'] ?? 1);
        $customerId = $context['customer_id'] ?? null;
        $coupon = $context['coupon_code'] ?? null;
        $cashierRoleId = $context['cashier_role_id'] ?? null;
        $now = now();

        if (!$this->productCanReceiveDiscount($companyId, $productId)) return [];

        $discounts = Discount::query()
            ->with(['discountType', 'rolePermissions'])
            ->where('company_id', $companyId)
            ->whereNotNull('discount_type_id')
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) $query->orWhere('branch_id', $branchId);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return $discounts
            ->filter(function (Discount $discount) use ($companyId, $productId, $categoryIds, $saleAmount, $quantity, $customerId, $coupon, $cashierRoleId) {
                if ($discount->usage_limit !== null && (int) $discount->usage_count >= (int) $discount->usage_limit) return false;
                if ($discount->min_sale_amount !== null && $saleAmount < (float) $discount->min_sale_amount) return false;
                if ($discount->min_quantity !== null && $quantity < (float) $discount->min_quantity) return false;
                if ($discount->requires_customer && !$customerId) return false;
                if ($discount->requires_coupon_code && strcasecmp((string) $discount->coupon_code, (string) $coupon) !== 0) return false;
                if (!$this->discountMatchesProductTarget($discount, $companyId, $productId)) return false;
                if (!$this->discountMatchesCategoryTarget($discount, $companyId, $categoryIds)) return false;
                if (!$this->discountMatchesRole($discount, $companyId, $cashierRoleId)) return false;
                return true;
            })
            ->map(fn (Discount $discount) => $this->serializeDiscount($discount, $cashierRoleId, $productId, $categoryIds))
            ->values()
            ->all();
    }

    public function calculateDiscount(array $payload): array
    {
        $companyId = $payload['company_id'] ?? app('company')->id;
        $branchId = $payload['branch_id'] ?? null;
        $productId = $payload['product_id'] ?? null;
        $categoryIds = $this->resolveCategoryIds($companyId, $productId, $payload['category_id'] ?? null);

        $discount = Discount::with(['discountType', 'rolePermissions'])
            ->where('company_id', $companyId)
            ->whereNotNull('discount_type_id')
            ->findOrFail($payload['discount_id']);

        $eligible = collect($this->listEligibleDiscounts([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'category_id' => $categoryIds[0] ?? null,
            'sale_amount' => $payload['gross_amount'] ?? 0,
            'quantity' => $payload['quantity'] ?? 1,
            'customer_id' => $payload['customer_id'] ?? null,
            'coupon_code' => $payload['coupon_code'] ?? null,
            'cashier_role_id' => auth()->user()?->role_id,
        ]))->firstWhere('id', $discount->id);

        if (!$eligible) throw new \Exception('This discount is not configured for the selected product/category/role.');

        $gross = round((float) ($payload['gross_amount'] ?? 0), 4);
        $qty = max((float) ($payload['quantity'] ?? 1), 0.0001);
        $value = (float) $discount->value;

        $amount = match ($discount->discount_basis) {
            'percentage' => $gross * ($value / 100),
            'fixed_amount' => $value,
            'fixed_price' => max(0, $gross - ($value * $qty)),
            default => 0,
        };

        if ($discount->max_discount_amount !== null) $amount = min($amount, (float) $discount->max_discount_amount);

        $amount = min(max($amount, 0), $gross);
        $net = max(0, $gross - $amount);
        $enterpriseScope = $discount->discountType?->scope ?? 'line';

        return [
            'discount_id' => $discount->id,
            'discount_code' => $discount->code,
            'discount_name' => $discount->name,
            'discount_type_id' => $discount->discount_type_id,
            'scope' => $enterpriseScope,
            'sale_scope' => $this->normalizeSaleDiscountScope($enterpriseScope),
            'discount_basis' => $discount->discount_basis,
            'discount_value' => round($value, 4),
            'gross_amount' => round($gross, 4),
            'discount_amount' => round($amount, 4),
            'net_amount' => round($net, 4),
            'product_id' => $productId,
            'category_id' => $categoryIds[0] ?? null,
            'category_ids' => $categoryIds,
            'requires_manager_approval' => (bool) $discount->requires_manager_approval || (bool) ($discount->discountType?->requires_manager_approval),
            'requires_reason' => (bool) $discount->requires_reason || (bool) ($discount->discountType?->requires_reason),
            'allow_stacking' => (bool) $discount->allow_stacking || (bool) ($discount->discountType?->allow_stacking),
        ];
    }

    public function validateDiscountApproval(array $line, string $companyId, ?string $branchId, string $cashierId): ?array
    {
        $discountPayload = $line['discount_payload'] ?? null;
        if (!$discountPayload) return null;

        $discountId = $discountPayload['discount_id'] ?? null;
        $discountAmount = (float) ($discountPayload['discount_amount'] ?? 0);
        $gross = (float) ($discountPayload['gross_amount'] ?? 0);
        $managerApprovalId = $discountPayload['manager_approval_id'] ?? null;
        $productId = $line['product_id'] ?? $discountPayload['product_id'] ?? null;
        $categoryIds = $this->resolveCategoryIds($companyId, $productId, $line['category_id'] ?? $discountPayload['category_id'] ?? null);

        if (!$discountId || $discountAmount <= 0) return null;

        $discount = Discount::with(['discountType', 'rolePermissions'])
            ->where('company_id', $companyId)
            ->whereNotNull('discount_type_id')->findOrFail($discountId);
        $cashier = PosUser::query()->where('company_id', $companyId)->where('id', $cashierId)->first();
        if (!$cashier) throw new \Exception('Cashier not found for discount validation.');

        $eligible = collect($this->listEligibleDiscounts([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'category_id' => $categoryIds[0] ?? null,
            'sale_amount' => $gross,
            'quantity' => $line['quantity'] ?? 1,
            'customer_id' => $line['customer_id'] ?? null,
            'coupon_code' => $discountPayload['coupon_code'] ?? null,
            'cashier_role_id' => $cashier->role_id,
        ]))->firstWhere('id', $discount->id);

        if (!$eligible) throw new \Exception('This discount is not configured for the selected product/category/role.');

        $roleRow = DB::table('discount_role_permissions')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->where('role_id', $cashier->role_id)
            ->first();

        $hasRoleRestrictions = DB::table('discount_role_permissions')
            ->where('company_id', $companyId)
            ->where('discount_id', $discount->id)
            ->exists();

        $requiresManager = (bool) $discount->requires_manager_approval
            || (bool) ($discount->discountType?->requires_manager_approval)
            || $this->manualDiscountRequiresManager($companyId, $branchId);

        if ($hasRoleRestrictions && !$roleRow) $requiresManager = true;

        $cashierPercent = $gross > 0 ? (($discountAmount / $gross) * 100) : 0;
        $cashierMax = $this->maxCashierDiscountPercent($companyId, $branchId);

        if ($roleRow) {
            if ($roleRow->max_percentage !== null && $cashierPercent > (float) $roleRow->max_percentage) $requiresManager = true;
            if ($roleRow->max_amount !== null && $discountAmount > (float) $roleRow->max_amount) $requiresManager = true;
            if ((bool) $roleRow->can_apply_without_manager) $requiresManager = false;
        }

        if (!$requiresManager && $cashierMax > 0 && $cashierPercent <= $cashierMax) {
            $permission = hasPermission($cashier->role_id, 'apply_discount', $companyId);
            if ($permission !== true) throw new \Exception('Cashier role is not allowed to apply discount.');

            return [
                'discount_id' => $discount->id,
                'approved_by' => $cashier->id,
                'manager_approval_id' => null,
                'approval_mode' => 'cashier_permission',
                'role_permission_id' => $roleRow->id ?? null,
            ];
        }

        if (!$managerApprovalId) throw new \Exception('Manager approval is required for this discount.');

        $approval = $this->getValidDiscountManagerApproval(
            (string) $managerApprovalId,
            $companyId,
            $branchId
        );

        if (!$approval) throw new \Exception('Invalid manager approval for discount.');

        return [
            'discount_id' => $discount->id,
            'approved_by' => $approval->user_id,
            'manager_approval_id' => $managerApprovalId,
            'approval_mode' => 'manager',
            'role_permission_id' => $roleRow->id ?? null,
        ];
    }

    private function getValidDiscountManagerApproval(string $approvalId, string $companyId, ?string $branchId): ?object
    {
        if (trim($companyId) === '' || ($branchId !== null && trim($branchId) === '')) {
            return null;
        }

        $query = DB::table('audit_trails')
            ->where('id', $approvalId)
            ->whereNotNull('company_id')
            ->where('company_id', $companyId)
            ->where('module', 'manager-approval')
            ->where('status', 'success')
            ->whereIn('action', [
                'manager_approval_discount_override',
                'manager_approval_manual_discount',
                'manager_approval_apply_discount',
            ]);

        if ($branchId !== null) {
            $query->whereNotNull('branch_id')->where('branch_id', $branchId);
        }

        $approval = $query->first();
        if (!$approval) {
            return null;
        }

        $metadata = $this->jsonToArray($approval->metadata ?? null);
        $newValues = $this->jsonToArray($approval->new_values ?? null);
        $metadataPermission = $metadata['required_permission'] ?? null;
        $newValuesPermission = $newValues['required_permission'] ?? null;

        if ($metadataPermission !== null
            && $newValuesPermission !== null
            && $metadataPermission !== $newValuesPermission) {
            return null;
        }

        $requiredPermission = $metadataPermission ?? $newValuesPermission;

        return $requiredPermission === 'approve_discount_override' ? $approval : null;
    }

    public function recordSaleDiscount(array $data): void
    {
        $scope = $this->normalizeSaleDiscountScope($data['scope'] ?? 'line');

        DB::table('sale_discounts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'sales_transaction_id' => $data['sales_transaction_id'] ?? null,
            'receipt_line_id' => $data['receipt_line_id'] ?? null,
            'discount_id' => $data['discount_id'] ?? null,
            'discount_type_id' => $data['discount_type_id'] ?? null,
            'discount_code' => $data['discount_code'] ?? null,
            'discount_name' => $data['discount_name'] ?? null,
            'scope' => $scope,
            'discount_basis' => $data['discount_basis'] ?? 'percentage',
            'discount_value' => $data['discount_value'] ?? 0,
            'gross_amount' => $data['gross_amount'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'net_amount' => $data['net_amount'] ?? 0,
            'currency' => $data['currency'] ?? 'USD',
            'cashier_id' => $data['cashier_id'] ?? null,
            'approved_by' => $data['approved_by'] ?? null,
            'manager_approval_id' => $data['manager_approval_id'] ?? null,
            'reason' => $data['reason'] ?? null,
            'metadata' => json_encode([...(is_array($data['metadata'] ?? null) ? $data['metadata'] : []), 'original_scope' => $data['original_scope'] ?? $data['scope'] ?? null]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serializeDiscount(Discount $discount, ?string $roleId = null, ?string $productId = null, array $categoryIds = []): array
    {
        $enterpriseScope = $discount->discountType?->scope ?? 'line';

        $rolePermission = null;
        if ($roleId) {
            $rolePermission = DB::table('discount_role_permissions')
                ->where('company_id', $discount->company_id)
                ->where('discount_id', $discount->id)
                ->where('role_id', $roleId)
                ->first();
        }

        return [
            'id' => $discount->id,
            'code' => $discount->code,
            'name' => $discount->name,
            'description' => $discount->description,
            'discount_type_id' => $discount->discount_type_id,
            'discount_type_name' => $discount->discountType?->name,
            'scope' => $enterpriseScope,
            'sale_scope' => $this->normalizeSaleDiscountScope($enterpriseScope),
            'calculation_type' => $discount->discountType?->calculation_type ?? $discount->discount_basis,
            'discount_basis' => $discount->discount_basis,
            'value' => (float) $discount->value,
            'max_discount_amount' => $discount->max_discount_amount !== null ? (float) $discount->max_discount_amount : null,
            'min_sale_amount' => $discount->min_sale_amount !== null ? (float) $discount->min_sale_amount : null,
            'min_quantity' => $discount->min_quantity !== null ? (float) $discount->min_quantity : null,
            'applies_to_all_products' => (bool) $discount->applies_to_all_products,
            'applies_to_all_categories' => (bool) $discount->applies_to_all_categories,
            'matched_product_id' => $productId,
            'matched_category_id' => $categoryIds[0] ?? null,
            'matched_category_ids' => $categoryIds,
            'requires_manager_approval' => (bool) $discount->requires_manager_approval || (bool) ($discount->discountType?->requires_manager_approval),
            'requires_reason' => (bool) $discount->requires_reason || (bool) ($discount->discountType?->requires_reason),
            'allow_stacking' => (bool) $discount->allow_stacking || (bool) ($discount->discountType?->allow_stacking),
            'priority' => (int) $discount->priority,
            'role_permission' => $rolePermission ? [
                'id' => $rolePermission->id,
                'role_id' => $rolePermission->role_id,
                'max_percentage' => $rolePermission->max_percentage !== null ? (float) $rolePermission->max_percentage : null,
                'max_amount' => $rolePermission->max_amount !== null ? (float) $rolePermission->max_amount : null,
                'can_apply_without_manager' => (bool) $rolePermission->can_apply_without_manager,
            ] : null,
        ];
    }

    private function normalizeSaleDiscountScope(?string $scope): string
    {
        return $scope === 'cart' ? 'cart' : 'line';
    }

    private function jsonToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: [];
        }

        return [];
    }

    private function settingEnabled(string $companyId, ?string $branchId, string $settingKey, bool $default): bool
    {
        return filter_var($this->settingValue($companyId, $branchId, $settingKey, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    private function settingValue(string $companyId, ?string $branchId, string $settingKey, mixed $default = null): mixed
    {
        $setting = GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('setting_key', $settingKey)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) $query->orWhere('branch_id', $branchId);
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        return $setting?->setting_value ?? $default;
    }
}
