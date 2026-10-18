<?php

namespace App\Services\Enterprise;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Company\Models\Shop;
use Modules\Product\Models\ProductPrice;

final class HospitalityMenuPriceService
{
    /** @var array<string, Shop|null> */
    private array $shops = [];

    public function __construct(private readonly EnterpriseHierarchyService $hierarchy) {}

    public function scopeSaleableProducts(
        EloquentBuilder|QueryBuilder $query,
        string $companyId,
        string $branchId,
        ?string $channel = null
    ): EloquentBuilder|QueryBuilder {
        if (! $this->catalogueReady()) {
            return $query->whereRaw('1 = 0');
        }

        $shop = $this->shopForBranch($companyId, $branchId);
        if (! $shop || $shop->status !== 'active') {
            return $query->whereRaw('1 = 0');
        }

        $visibleProductIds = $this->hierarchy
            ->visibleMenuQuery($shop, $this->channel($channel))
            ->reorder()
            ->select('products.id');

        return $query->whereIn('id', $visibleProductIds);
    }

    public function resolve(
        string $companyId,
        string $branchId,
        string $productId,
        ?string $selectedPriceId = null,
        ?string $channel = null,
        ?string $shopId = null
    ): ProductPrice {
        if (! $this->catalogueReady()) {
            throw new InvalidArgumentException('The Hospitality menu catalogue is not ready on this terminal.');
        }

        $shop = $this->shopForBranch($companyId, $branchId, $shopId);
        if (! $shop || $shop->status !== 'active') {
            throw new InvalidArgumentException('This till is not attached to an active Hospitality shop.');
        }

        $now = now();
        $query = DB::table('menu_item_channels')
            ->where('company_id', $companyId)
            ->where('brand_id', $shop->brand_id)
            ->where('product_id', $productId)
            ->where('channel_code', $this->channel($channel))
            ->where('is_available', true)
            ->where(function (QueryBuilder $price): void {
                $price->whereNotNull('price_inclusive')->orWhereNotNull('price_exclusive');
            })
            ->where(function (QueryBuilder $availability) use ($now): void {
                $availability->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (QueryBuilder $availability) use ($now): void {
                $availability->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });

        if ($selectedPriceId !== null && trim($selectedPriceId) !== '') {
            $query->where('id', trim($selectedPriceId));
        }

        $row = $query->first();
        if (! $row) {
            throw new InvalidArgumentException(
                $selectedPriceId
                    ? 'Selected Hospitality menu price is not valid for this product, brand, shop and channel.'
                    : 'No active Hospitality menu price was found for this product, brand, shop and channel.'
            );
        }

        return $this->fromChannelRow($row, $productId, $branchId);
    }

    public function fromChannelRow(object|array $row, string $productId, ?string $branchId = null): ProductPrice
    {
        $values = (array) $row;
        $channelId = trim((string) ($values['menu_item_channel_id'] ?? $values['id'] ?? ''));
        $inclusive = $values['price_inclusive'] ?? null;
        $exclusive = $values['price_exclusive'] ?? null;
        $amount = $inclusive !== null ? $inclusive : $exclusive;
        if ($channelId === '' || $amount === null || ! is_numeric($amount)) {
            throw new InvalidArgumentException('The Hospitality menu channel does not contain a valid selling price.');
        }

        $price = new ProductPrice;
        $price->forceFill([
            'id' => $channelId,
            'company_id' => $values['company_id'] ?? null,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'price_level_id' => null,
            'price' => $amount,
            'currency' => $values['currency_code'] ?? $values['currency'] ?? null,
            'is_default' => true,
            'is_active' => true,
        ]);
        $price->setAttribute('pricing_source', 'menu_item_channel');
        $price->setAttribute('menu_item_channel_id', $channelId);
        $price->setAttribute('channel_code', $values['channel_code'] ?? null);
        $price->setAttribute('price_inclusive', $inclusive);
        $price->setAttribute('price_exclusive', $exclusive);

        return $price;
    }

    /** @return array<string, mixed> */
    public function bootstrapPayload(ProductPrice $price): array
    {
        return [
            // The UI sends this identifier back as product_price_id. The sale
            // resolver recognizes it as a menu_item_channels identifier; the
            // receipt keeps product_price_id null and snapshots this source in
            // metadata so the legacy FK is never pointed at the wrong table.
            'id' => (string) $price->menu_item_channel_id,
            'product_id' => (string) $price->product_id,
            'branch_id' => $price->branch_id,
            'price_level_id' => null,
            'price' => (float) $price->price,
            'currency' => $price->currency,
            'is_default' => true,
            'is_active' => true,
            'pricing_source' => 'menu_item_channel',
            'menu_item_channel_id' => (string) $price->menu_item_channel_id,
            'channel_code' => $price->channel_code,
            'price_inclusive' => $price->price_inclusive,
            'price_exclusive' => $price->price_exclusive,
            'price_level_name' => 'Standard',
            'price_level_code' => 'STANDARD',
            'price_level' => [
                'id' => null,
                'name' => 'Standard',
                'code' => 'STANDARD',
            ],
        ];
    }

    private function catalogueReady(): bool
    {
        return Schema::hasTable('shops')
            && Schema::hasTable('brand_products')
            && Schema::hasTable('menu_item_channels');
    }

    private function shopForBranch(string $companyId, string $branchId, ?string $shopId = null): ?Shop
    {
        $key = $companyId.':'.$branchId.':'.($shopId ?: '*');
        if (! array_key_exists($key, $this->shops)) {
            $this->shops[$key] = Shop::query()
                ->with('country')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->when($shopId, fn ($query) => $query->whereKey($shopId))
                ->first();
        }

        return $this->shops[$key];
    }

    private function channel(?string $channel): string
    {
        $value = strtoupper(trim((string) ($channel
            ?: config('servnest.catalogue.default_channel', 'POS'))));

        return $value !== '' ? $value : 'POS';
    }
}
