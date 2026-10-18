<?php

namespace App\Services\Enterprise;

use App\Services\Sync\SyncConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The till builds its menu from the brand catalogue (brand_products) and each
 * item's POS channel price (menu_item_channels). Imported menus fill both. A
 * menu item created in Back Office belongs to one branch and is priced with
 * product prices, so it is catalogued here for the brand of that branch's
 * shop, and its POS price follows the branch's current selling price.
 *
 * Central and standalone installations only: an edge receives the catalogue
 * from Central through sync.
 */
final class BranchMenuCatalogueService
{
    public const MENU_PRODUCT_TYPES = ['finished_good', 'service'];

    private const SOURCE = 'back_office';

    public function __construct(private readonly SyncConfigurationService $sync) {}

    public function syncProduct(?string $productId): void
    {
        if (blank($productId) || config('servnest.deployment_mode') === 'edge') {
            return;
        }

        $product = DB::table('products')->where('id', $productId)->first();
        if (! $product || ! $this->isBranchMenuItem($product) || ! $this->catalogueReady()) {
            return;
        }

        $shop = DB::table('shops')
            ->where('company_id', $product->company_id)
            ->where('branch_id', $product->branch_id)
            ->whereNotNull('brand_id')
            ->when(Schema::hasColumn('shops', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('id')
            ->first(['id', 'brand_id']);
        if (! $shop) {
            return;
        }

        $catalogued = $this->syncCatalogueEntry($product, (string) $shop->brand_id);
        $priced = $this->syncPosPrice($product, (string) $shop->brand_id);

        if ($catalogued || $priced) {
            $this->sync->markBranchDirty((string) $product->company_id, (string) $product->branch_id, 'menu_catalogue');
        }
    }

    private function isBranchMenuItem(object $product): bool
    {
        return ! (bool) ($product->is_global ?? true)
            && filled($product->branch_id ?? null)
            && in_array((string) ($product->product_type ?? ''), self::MENU_PRODUCT_TYPES, true);
    }

    private function syncCatalogueEntry(object $product, string $brandId): bool
    {
        $existing = DB::table('brand_products')
            ->where('brand_id', $brandId)
            ->where('product_id', $product->id)
            ->first();

        $deletedAt = $product->deleted_at ?? null;
        if ($existing) {
            if ((bool) $existing->is_active && ($existing->deleted_at === null) === ($deletedAt === null)) {
                return false;
            }

            DB::table('brand_products')->where('id', $existing->id)->update([
                'is_active' => true,
                'deleted_at' => $deletedAt,
                'updated_at' => now(),
            ]);

            return true;
        }

        if ($deletedAt !== null) {
            return false;
        }

        DB::table('brand_products')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $product->company_id,
            'brand_id' => $brandId,
            'product_id' => $product->id,
            'item_kind' => 'menu_item',
            'external_code' => $this->freeExternalCode($brandId, $product->code ?? null),
            'source_metadata' => json_encode(['source' => self::SOURCE]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function freeExternalCode(string $brandId, ?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        $taken = DB::table('brand_products')
            ->where('brand_id', $brandId)
            ->where('external_code', $code)
            ->exists();

        return $taken ? null : $code;
    }

    private function syncPosPrice(object $product, string $brandId): bool
    {
        $channel = strtoupper(trim((string) config('servnest.catalogue.default_channel', 'POS')) ?: 'POS');
        $existing = DB::table('menu_item_channels')
            ->where('brand_id', $brandId)
            ->where('product_id', $product->id)
            ->where('channel_code', $channel)
            ->first();

        $price = $this->currentSellingPrice($product);
        $currency = $price ? $this->currencyFor($product, $price->currency ?? null) : null;

        if (! $price || $currency === null) {
            if (! $existing || ! (bool) $existing->is_available) {
                return false;
            }

            DB::table('menu_item_channels')->where('id', $existing->id)->update([
                'is_available' => false,
                'updated_at' => now(),
            ]);

            return true;
        }

        $values = [
            'price_inclusive' => round((float) $price->price, 4),
            'price_exclusive' => null,
            'currency_code' => $currency,
            'is_available' => true,
        ];

        if ($existing) {
            $unchanged = (bool) $existing->is_available
                && $existing->price_exclusive === null
                && round((float) $existing->price_inclusive, 4) === $values['price_inclusive']
                && (string) $existing->currency_code === $currency;
            if ($unchanged) {
                return false;
            }

            DB::table('menu_item_channels')->where('id', $existing->id)->update([
                ...$values,
                'updated_at' => now(),
            ]);

            return true;
        }

        DB::table('menu_item_channels')->insert([
            ...$values,
            'id' => (string) Str::uuid(),
            'company_id' => $product->company_id,
            'brand_id' => $brandId,
            'product_id' => $product->id,
            'channel_code' => $channel,
            'metadata' => json_encode(['source' => self::SOURCE]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * The branch's live selling price: the default price first, then the
     * most recently changed one. Hospitality tills sell one price per item.
     */
    private function currentSellingPrice(object $product): ?object
    {
        if (! Schema::hasTable('product_prices')) {
            return null;
        }

        $now = now();

        return DB::table('product_prices')
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->where(fn ($branch) => $branch->where('branch_id', $product->branch_id)->orWhereNull('branch_id'))
            ->when(Schema::hasColumn('product_prices', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->when(Schema::hasColumn('product_prices', 'starts_at'), fn ($query) => $query
                ->where(fn ($window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', $now)))
            ->when(Schema::hasColumn('product_prices', 'ends_at'), fn ($query) => $query
                ->where(fn ($window) => $window->whereNull('ends_at')->orWhere('ends_at', '>=', $now)))
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->first(['price', 'currency']);
    }

    private function currencyFor(object $product, ?string $currency): ?string
    {
        $currency = strtoupper(trim((string) $currency));
        if (strlen($currency) === 3) {
            return $currency;
        }

        if (! Schema::hasTable('currencies')) {
            return null;
        }

        $base = DB::table('currencies')
            ->where('company_id', $product->company_id)
            ->where('branch_id', $product->branch_id)
            ->where('is_base_currency', true)
            ->when(Schema::hasColumn('currencies', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->value('code');

        return $base ? strtoupper((string) $base) : null;
    }

    private function catalogueReady(): bool
    {
        return Schema::hasTable('shops')
            && Schema::hasTable('brand_products')
            && Schema::hasTable('menu_item_channels')
            && Schema::hasColumn('shops', 'brand_id');
    }
}
