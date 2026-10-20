<?php

namespace App\Services\Enterprise;

use App\Services\ConfigurationBranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Back Office data belongs to brands. The brand comes from the shop the user
 * signed in to (the request's branch), the same rule for every role: working
 * in a Chicken Inn shop shows Chicken Inn's recipes, products, menus and
 * categories only. Rows with no brand (legacy or company-shared) stay visible
 * to every brand. An empty brand list means "no brand context" and leaves a
 * query unchanged.
 */
class BrandScopeService
{
    /** Catalogue kind recorded when a Back Office product joins a brand. */
    private const ITEM_KINDS = [
        'finished_good' => 'menu_item',
        'service' => 'menu_item',
        'semi_finished' => 'production_item',
    ];

    public function __construct(private ConfigurationBranchScopeService $branchScope)
    {
    }

    /** @return list<string> the brands of the shop the request is working in */
    public function forRequest(Request $request, string $companyId): array
    {
        $branchId = $this->branchScope->resolve($request, $companyId);

        return $branchId ? $this->brandIdsForBranches([$branchId]) : [];
    }

    /**
     * @param  array<int, mixed>  $branchIds
     * @return list<string>
     */
    public function brandIdsForBranches(array $branchIds): array
    {
        $branchIds = array_values(array_unique(array_filter(array_map('strval', $branchIds))));
        if ($branchIds === [] || ! Schema::hasTable('shops') || ! Schema::hasColumn('shops', 'brand_id')) {
            return [];
        }

        return DB::table('shops')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('brand_id')
            ->when(Schema::hasColumn('shops', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->distinct()
            ->pluck('brand_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Products a brand may see: those in its catalogue, plus products in no
     * brand's catalogue (company-shared). Another brand's products are hidden.
     *
     * @param  list<string>  $brandIds
     */
    public function constrainProducts($query, array $brandIds, string $productsTable = 'products')
    {
        if ($brandIds === [] || ! Schema::hasTable('brand_products')) {
            return $query;
        }

        $live = fn ($links) => $links->when(
            Schema::hasColumn('brand_products', 'deleted_at'),
            fn ($scope) => $scope->whereNull('brand_products.deleted_at')
        );

        return $query->where(function ($scope) use ($brandIds, $productsTable, $live): void {
            $scope->whereExists(fn ($links) => $live($links->selectRaw('1')
                ->from('brand_products')
                ->whereColumn('brand_products.product_id', "{$productsTable}.id")
                ->whereIn('brand_products.brand_id', $brandIds)))
                ->orWhereNotExists(fn ($links) => $live($links->selectRaw('1')
                    ->from('brand_products')
                    ->whereColumn('brand_products.product_id', "{$productsTable}.id")));
        });
    }

    /**
     * Rows that carry their own brand column (recipes, categories, modifiers,
     * prep messages): the request's brands plus brand-less shared rows.
     *
     * @param  list<string>  $brandIds
     */
    public function constrainBrandColumn($query, array $brandIds, string $column = 'brand_id')
    {
        if ($brandIds === []) {
            return $query;
        }

        return $query->where(fn ($scope) => $scope->whereIn($column, $brandIds)->orWhereNull($column));
    }

    /** The single brand new Back Office records are filed under, if any. */
    public function primaryBrandId(array $brandIds): ?string
    {
        return count($brandIds) === 1 ? (string) $brandIds[0] : null;
    }

    /**
     * Files a product created in the Back Office under the working brand, so
     * other brands do not see it. A product already in a catalogue is left as is.
     */
    public function linkProductToBrand(object $product, ?string $brandId): void
    {
        if (! $brandId || ! Schema::hasTable('brand_products') || empty($product->id)) {
            return;
        }

        $linked = DB::table('brand_products')
            ->where('product_id', $product->id)
            ->when(Schema::hasColumn('brand_products', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->exists();
        if ($linked) {
            return;
        }

        $kind = self::ITEM_KINDS[strtolower((string) ($product->product_type ?? ''))] ?? 'raw_material';
        DB::table('brand_products')->insert(array_intersect_key([
            'id' => (string) Str::uuid(),
            'company_id' => $product->company_id,
            'brand_id' => $brandId,
            'product_id' => $product->id,
            'item_kind' => $kind,
            'source_metadata' => json_encode(['source' => 'back_office']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_flip(Schema::getColumnListing('brand_products'))));
    }
}
