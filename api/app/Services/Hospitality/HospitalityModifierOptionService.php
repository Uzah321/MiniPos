<?php

namespace App\Services\Hospitality;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Company\Models\Shop;

/**
 * Brand catalogue modifier options (modifier_groups / modifier_group_options,
 * as written by the hospitality catalogue importer) presented in the same row
 * shape as legacy product_modifiers, so the POS can offer, price and post
 * them. The filtering rules mirror the terminal configuration bootstrap:
 * an active group assigned to the menu item, an active option whose modifier
 * product is active, scoped to the shop's brand, minus per-item exclusions.
 */
final class HospitalityModifierOptionService
{
    /** @return Collection<int, array<string, mixed>> */
    public function optionsForMenuItem(string $companyId, ?string $branchId, string $menuProductId): Collection
    {
        return $this->options($companyId, $branchId, $menuProductId);
    }

    /**
     * Only option ids the menu item may actually carry are returned, so a
     * client cannot attach an excluded or foreign-brand option to a sale.
     *
     * @param  array<int, string>  $optionIds
     * @return Collection<string, array<string, mixed>>
     */
    public function resolveForMenuItem(
        string $companyId,
        ?string $branchId,
        string $menuProductId,
        array $optionIds
    ): Collection {
        $optionIds = array_values(array_unique(array_filter(array_map('strval', $optionIds))));
        if ($optionIds === []) {
            return collect();
        }

        return $this->options($companyId, $branchId, $menuProductId, $optionIds)
            ->keyBy(fn (array $option): string => (string) $option['id']);
    }

    /**
     * @param  array<int, string>|null  $onlyOptionIds
     * @return Collection<int, array<string, mixed>>
     */
    private function options(
        string $companyId,
        ?string $branchId,
        string $menuProductId,
        ?array $onlyOptionIds = null
    ): Collection {
        if (! Schema::hasTable('menu_item_modifier_groups')
            || ! Schema::hasTable('modifier_groups')
            || ! Schema::hasTable('modifier_group_options')) {
            return collect();
        }

        $brandId = $this->brandIdForBranch($companyId, $branchId);
        if ($brandId === null) {
            return collect();
        }

        $query = DB::table('menu_item_modifier_groups as relation')
            ->join('modifier_groups as modifier_group', 'modifier_group.id', '=', 'relation.modifier_group_id')
            ->join('modifier_group_options as option', 'option.modifier_group_id', '=', 'modifier_group.id')
            ->join('products as product', 'product.id', '=', 'option.modifier_product_id')
            ->where('relation.company_id', $companyId)
            ->where('relation.brand_id', $brandId)
            ->where('relation.menu_product_id', $menuProductId)
            ->where('relation.is_active', true)
            ->where('modifier_group.is_active', true)
            ->where('option.company_id', $companyId)
            ->where('option.brand_id', $brandId)
            ->where('option.is_active', true)
            ->where('product.is_active', true);

        if (Schema::hasColumn('modifier_groups', 'deleted_at')) {
            $query->whereNull('modifier_group.deleted_at');
        }
        if ($onlyOptionIds !== null) {
            $query->whereIn('option.id', $onlyOptionIds);
        }
        if (Schema::hasTable('menu_item_modifier_exclusions')) {
            $query->whereNotExists(function ($exclusion) use ($companyId, $brandId): void {
                $exclusion->selectRaw('1')
                    ->from('menu_item_modifier_exclusions as exclusion')
                    ->whereColumn('exclusion.menu_product_id', 'relation.menu_product_id')
                    ->whereColumn('exclusion.modifier_option_id', 'option.id')
                    ->where('exclusion.company_id', $companyId)
                    ->where('exclusion.brand_id', $brandId);
            });
        }

        $columns = [
            'option.id', 'option.external_code', 'option.price_delta',
            'option.modifier_product_id', 'option.sort_order',
            'modifier_group.id as group_id', 'modifier_group.code as group_code',
            'modifier_group.name as group_name',
            'product.name as product_name', 'product.code as product_code',
        ];
        if (Schema::hasColumn('modifier_group_options', 'metadata')) {
            $columns[] = 'option.metadata';
        }

        return $query
            ->orderBy('relation.sort_order')
            ->orderBy('option.sort_order')
            ->get($columns)
            ->unique('id')
            ->map(function (object $row): array {
                $metadata = json_decode((string) ($row->metadata ?? ''), true);

                return [
                    'id' => (string) $row->id,
                    'modifier_id' => (string) $row->id,
                    'source' => 'modifier_group_option',
                    'code' => $row->external_code ?: $row->product_code,
                    'name' => $row->product_name,
                    'group_id' => (string) $row->group_id,
                    'group_code' => $row->group_code,
                    'group_name' => $row->group_name,
                    'sort_order' => (int) $row->sort_order,
                    'price' => round(max(0, (float) $row->price_delta), 4),
                    'currency_code' => is_array($metadata) ? ($metadata['currency'] ?? null) : null,
                    'modifier_product_id' => (string) $row->modifier_product_id,
                    'recipe_id' => null,
                    'deduct_recipe_stock' => true,
                    'print_on_kitchen' => true,
                    'print_on_customer_receipt' => true,
                    'is_active' => true,
                ];
            })
            ->values();
    }

    private function brandIdForBranch(string $companyId, ?string $branchId): ?string
    {
        if ($branchId === null || $branchId === '' || ! Schema::hasTable('shops')) {
            return null;
        }

        $brandId = Shop::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->value('brand_id');

        return is_scalar($brandId) && trim((string) $brandId) !== '' ? (string) $brandId : null;
    }
}
