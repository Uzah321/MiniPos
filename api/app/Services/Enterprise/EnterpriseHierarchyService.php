<?php

namespace App\Services\Enterprise;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Company\Models\Brand;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\ComplexLocation;
use Modules\Company\Models\Country;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

class EnterpriseHierarchyService
{
    public function ensureShopForBranch(CompanyBranch $branch): ?Shop
    {
        if (! Schema::hasTable('countries')
            || ! Schema::hasTable('brands')
            || ! Schema::hasTable('complexes')
            || ! Schema::hasTable('shops')) {
            return null;
        }

        $company = $branch->company()->first();
        if (! $company) {
            return null;
        }

        return DB::transaction(function () use ($branch, $company): Shop {
            $countryName = trim((string) ($branch->branch_country ?: $company->country)) ?: 'Unassigned';
            $countryId = $company->country_id ?: $this->deterministicUuid('country:'.mb_strtolower($countryName));
            $country = Country::query()->firstOrCreate(
                ['id' => $countryId],
                [
                    'code' => $this->availableCountryCode($countryName, $countryId),
                    'name' => $countryName,
                    'is_active' => true,
                ]
            );
            if ((string) $company->country_id !== (string) $country->id) {
                $company->forceFill(['country_id' => $country->id])->saveQuietly();
            }

            $brand = Brand::query()
                ->where('company_id', $company->id)
                ->where('country_id', $country->id)
                ->where('status', 'active')
                ->orderBy('created_at')
                ->first();
            if (! $brand) {
                $brand = Brand::query()->create([
                    'id' => $this->deterministicUuid('brand:'.$company->id.':default'),
                    'company_id' => $company->id,
                    'country_id' => $country->id,
                    'code' => 'DEFAULT',
                    'name' => $company->company_name,
                    'status' => 'active',
                    'metadata' => ['legacy_backfill' => true],
                ]);
            }

            $code = trim((string) $branch->branch_code)
                ?: 'SHOP-'.substr(str_replace('-', '', (string) $branch->id), 0, 12);
            $complex = ComplexLocation::query()->firstOrCreate(
                ['id' => $this->deterministicUuid('complex:'.$branch->id)],
                [
                    'company_id' => $company->id,
                    'country_id' => $country->id,
                    // Complexes are physical company locations. The brand is
                    // assigned to the Shop below, never to the complex.
                    'brand_id' => null,
                    'code' => $code,
                    'name' => $branch->branch_name,
                    'address' => $branch->branch_address,
                    'city' => $branch->branch_city,
                    'status' => $branch->status,
                    'metadata' => ['legacy_backfill' => true],
                ]
            );

            return Shop::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'id' => $branch->id,
                    'company_id' => $company->id,
                    'country_id' => $country->id,
                    'brand_id' => $brand->id,
                    'complex_id' => $complex->id,
                    'code' => $code,
                    'name' => $branch->branch_name,
                    'address' => $branch->branch_address,
                    'city' => $branch->branch_city,
                    'pos_login_mode' => 'code',
                    'status' => $branch->status,
                    'metadata' => ['legacy_backfill' => true],
                ]
            );
        }, 3);
    }

    /**
     * Counterpart of ensureShopForBranch for a deleted branch: soft-delete its
     * shop, and the complex ensureShopForBranch created for it (marked
     * legacy_backfill) once no live shop uses that complex. Otherwise both stay
     * live, appear in the hospitality structure, and collide with the shop and
     * complex of a branch re-added with the same code. Complexes set up by an
     * administrator are never removed here.
     */
    public function retireShopForBranch(CompanyBranch $branch): void
    {
        if (! Schema::hasTable('shops') || ! Schema::hasTable('complexes')) {
            return;
        }

        DB::transaction(function () use ($branch): void {
            Shop::query()->where('branch_id', $branch->id)->get()
                ->each(function (Shop $shop): void {
                    $complexId = $shop->complex_id;
                    $shop->delete();

                    $complex = $complexId ? ComplexLocation::query()->find($complexId) : null;
                    if ($complex
                        && ($complex->metadata['legacy_backfill'] ?? false) === true
                        && ! Shop::query()->where('complex_id', $complexId)->exists()) {
                        $complex->delete();
                    }
                });
        }, 3);
    }

    /** @return array<string, mixed> */
    public function hierarchyForTill(PosTill $till): array
    {
        $shop = null;
        if (Schema::hasTable('shops')) {
            $shop = Shop::query()
                ->with(['country', 'company', 'brand', 'complex', 'branch'])
                ->where('company_id', $till->company_id)
                ->where(fn ($query) => $query
                    ->whereKey($till->shop_id ?: $till->branch_id)
                    ->orWhere('branch_id', $till->branch_id))
                ->first();
        }

        if ($shop) {
            return $this->hierarchyForShop($shop, $till);
        }

        $branch = $till->branch()->first();
        $company = $till->company()->first();

        return [
            'country' => null,
            'company' => $company ? [
                'id' => (string) $company->id,
                'code' => $company->company_code,
                'name' => $company->company_name,
                'logo' => $company->logo,
                'status' => $company->status,
            ] : null,
            'brand' => null,
            'complex' => null,
            'shop' => $branch ? [
                'id' => (string) $branch->id,
                'code' => $branch->branch_code,
                'name' => $branch->branch_name,
                'branch_id' => (string) $branch->id,
                'pos_login_mode' => 'code',
                'configuration_version' => 1,
                'status' => $branch->status,
            ] : null,
            'till' => $this->tillPayload($till),
        ];
    }

    /** @return array<string, mixed> */
    public function hierarchyForShop(Shop $shop, ?PosTill $till = null): array
    {
        $shop->loadMissing(['country', 'company', 'brand', 'complex', 'branch']);

        return [
            'country' => $shop->country ? $this->countryPayload($shop->country) : null,
            'company' => $shop->company ? [
                'id' => (string) $shop->company->id,
                'code' => $shop->company->company_code,
                'name' => $shop->company->company_name,
                'logo' => $shop->company->logo,
                'status' => $shop->company->status,
            ] : null,
            'brand' => $shop->brand ? [
                'id' => (string) $shop->brand->id,
                'code' => $shop->brand->code,
                'name' => $shop->brand->name,
                'logo' => $this->publicAssetReference(
                    $shop->brand->logo_url,
                    $shop->brand->logo_path
                ),
                'logo_url' => $shop->brand->logo_url,
                'logo_path' => $shop->brand->logo_path,
                'status' => $shop->brand->status,
            ] : null,
            'complex' => $shop->complex ? [
                'id' => (string) $shop->complex->id,
                'code' => $shop->complex->code,
                'name' => $shop->complex->name,
                'status' => $shop->complex->status,
            ] : null,
            'shop' => [
                'id' => (string) $shop->id,
                'code' => $shop->code,
                'name' => $shop->name,
                'branch_id' => (string) $shop->branch_id,
                'pos_login_mode' => $shop->pos_login_mode,
                'configuration_version' => (int) $shop->configuration_version,
                'status' => $shop->status,
            ],
            'till' => $till ? $this->tillPayload($till) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function configurationManifest(PosTill $till): array
    {
        $hierarchy = $this->hierarchyForTill($till);
        $countryId = data_get($hierarchy, 'country.id');
        $companyId = (string) $till->company_id;
        $shopId = data_get($hierarchy, 'shop.id');
        $brandId = data_get($hierarchy, 'brand.id');
        $complexId = data_get($hierarchy, 'complex.id');
        $branchId = (string) $till->branch_id;
        $facts = [
            'deployment_mode' => (string) config('servnest.deployment_mode'),
            'hospitality_only' => true,
            'company_id' => $companyId,
            'shop_id' => $shopId,
            'till_id' => (string) $till->id,
            'shop_version' => (int) data_get($hierarchy, 'shop.configuration_version', 1),
            'till_version' => (int) ($till->configuration_version ?: 1),
            'country_updated_at' => $this->maxUpdatedAt('countries', ['id' => $countryId]),
            'company_updated_at' => $this->maxUpdatedAt('companies', ['id' => $companyId]),
            'brand_updated_at' => $this->maxUpdatedAt('brands', [
                'id' => $brandId,
                'company_id' => $companyId,
            ]),
            'complex_updated_at' => $this->maxUpdatedAt('complexes', [
                'id' => $complexId,
                'company_id' => $companyId,
            ]),
            'shop_updated_at' => $this->maxUpdatedAt('shops', [
                'id' => $shopId,
                'company_id' => $companyId,
            ]),
            'till_updated_at' => $this->maxUpdatedAt('pos_tills', [
                'id' => $till->id,
                'company_id' => $companyId,
            ]),
            'products_updated_at' => $this->maxUpdatedAt('products', [
                'company_id' => $companyId,
            ]),
            'categories_updated_at' => $this->maxUpdatedAt('product_categories', [
                'company_id' => $companyId,
            ]),
            'sub_categories_updated_at' => $this->maxUpdatedAt('product_sub_categories', [
                'company_id' => $companyId,
            ]),
            'catalogue_updated_at' => $this->maxUpdatedAt('brand_products', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'visibility_updated_at' => $this->maxUpdatedAt('shop_menu_item_overrides', [
                'company_id' => $companyId,
                'shop_id' => $shopId,
            ]),
            'recipes_updated_at' => $this->maxUpdatedAt('food_recipes', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'recipe_ingredients_updated_at' => $this->maxUpdatedAt('food_recipe_ingredients', [
                'company_id' => $companyId,
            ]),
            'uom_conversions_updated_at' => $this->maxUpdatedAt('product_uom_conversions', [
                'company_id' => $companyId,
            ]),
            'channels_updated_at' => $this->maxUpdatedAt('menu_item_channels', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'schedules_updated_at' => $this->maxUpdatedAt('menu_item_trading_schedules', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'modifiers_updated_at' => $this->maxUpdatedAt('modifier_groups', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'modifier_options_updated_at' => $this->maxUpdatedAt('modifier_group_options', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'modifier_relations_updated_at' => $this->maxUpdatedAt('menu_item_modifier_groups', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'modifier_exclusions_updated_at' => $this->maxUpdatedAt('menu_item_modifier_exclusions', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'prep_messages_updated_at' => $this->maxUpdatedAt('product_prep_messages', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'prep_assignments_updated_at' => $this->maxUpdatedAt('product_prep_message_assignments', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'legacy_modifiers_updated_at' => $this->maxUpdatedAt('product_modifiers', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'legacy_modifier_assignments_updated_at' => $this->maxUpdatedAt('product_modifier_assignments', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]),
            'prices_updated_at' => $this->maxUpdatedAt('product_prices', [
                'company_id' => $companyId,
            ]),
            'taxes_updated_at' => $this->maxUpdatedAt('taxes', [
                'company_id' => $companyId,
            ]),
            'product_taxes_updated_at' => $this->maxUpdatedAt('product_taxes', [
                'company_id' => $companyId,
            ]),
            'settings_updated_at' => $this->maxUpdatedAt('general_settings', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
            ]),
            'auth_policies_updated_at' => $this->maxUpdatedAt('auth_policies', []),
            'printer_routes_updated_at' => $this->maxUpdatedAt('printers', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
            ]),
            'kitchen_output_updated_at' => $this->maxUpdatedAt('hospitality_output_configurations', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'till_id' => $till->id,
            ]),
            'table_settings_updated_at' => $this->maxUpdatedAt('hospitality_table_settings', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'shop_id' => $shopId,
            ]),
            'tables_updated_at' => $this->maxUpdatedAt('hospitality_tables', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'shop_id' => $shopId,
            ]),
        ];

        return [
            'configuration_version' => max($facts['shop_version'], $facts['till_version']),
            'configuration_etag' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
            'generated_at' => now()->toIso8601String(),
            'sync_required' => true,
        ];
    }

    public function visibleMenuQuery(Shop $shop, ?string $channel = null): Builder
    {
        $now = now();
        $shopNow = $this->shopLocalTime($shop);
        $channel = $channel !== null && trim($channel) !== '' ? strtoupper(trim($channel)) : null;
        $query = DB::table('brand_products as brand_catalogue')
            ->join('products', 'products.id', '=', 'brand_catalogue.product_id')
            ->leftJoin('shop_menu_item_overrides as global_visibility', function ($join) use ($shop, $now): void {
                $join->on('global_visibility.product_id', '=', 'products.id')
                    ->where('global_visibility.shop_id', '=', $shop->id)
                    ->where('global_visibility.channel_key', '=', '*')
                    ->where(function ($scope) use ($now): void {
                        $scope->whereNull('global_visibility.starts_at')
                            ->orWhere('global_visibility.starts_at', '<=', $now);
                    })
                    ->where(function ($scope) use ($now): void {
                        $scope->whereNull('global_visibility.ends_at')
                            ->orWhere('global_visibility.ends_at', '>=', $now);
                    });
            })
            ->where('brand_catalogue.company_id', $shop->company_id)
            ->where('brand_catalogue.brand_id', $shop->brand_id)
            ->where('brand_catalogue.item_kind', 'menu_item')
            ->where('brand_catalogue.is_active', true)
            ->whereNull('brand_catalogue.deleted_at')
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            // The brand catalogue is shared by every shop of the brand; a
            // branch's own menu item is sold only at that branch's shop.
            ->when(Schema::hasColumn('products', 'branch_id'), fn ($scope) => $scope->where(
                fn ($owner) => $owner->whereNull('products.branch_id')
                    ->orWhere('products.branch_id', $shop->branch_id)
                    ->when(Schema::hasColumn('products', 'is_global'), fn ($shared) => $shared
                        ->orWhere('products.is_global', true))
            ))
            ->select([
                'products.*',
                'brand_catalogue.external_code',
                'brand_catalogue.legacy_code',
                'brand_catalogue.market',
                'brand_catalogue.department',
                'brand_catalogue.flavour',
                'brand_catalogue.size_label',
            ]);

        if ($channel !== null) {
            $query->leftJoin('shop_menu_item_overrides as channel_visibility', function ($join) use ($shop, $channel, $now): void {
                $join->on('channel_visibility.product_id', '=', 'products.id')
                    ->where('channel_visibility.shop_id', '=', $shop->id)
                    ->where('channel_visibility.channel_key', '=', $channel)
                    ->where(function ($scope) use ($now): void {
                        $scope->whereNull('channel_visibility.starts_at')
                            ->orWhere('channel_visibility.starts_at', '<=', $now);
                    })
                    ->where(function ($scope) use ($now): void {
                        $scope->whereNull('channel_visibility.ends_at')
                            ->orWhere('channel_visibility.ends_at', '>=', $now);
                    });
            })
                ->whereRaw('COALESCE(channel_visibility.is_available, global_visibility.is_available, 1) = 1')
                ->addSelect(DB::raw('COALESCE(channel_visibility.is_available, global_visibility.is_available, 1) as is_available'));
        } else {
            $query->whereRaw('COALESCE(global_visibility.is_available, 1) = 1')
                ->addSelect(DB::raw('COALESCE(global_visibility.is_available, 1) as is_available'));
        }

        if ($channel !== null && Schema::hasTable('menu_item_channels')) {
            $query->join('menu_item_channels as menu_channel', function ($join) use ($shop, $channel, $now): void {
                $join->on('menu_channel.product_id', '=', 'products.id')
                    ->where('menu_channel.brand_id', '=', $shop->brand_id)
                    ->where('menu_channel.channel_code', '=', $channel)
                    ->where('menu_channel.is_available', '=', true)
                    ->where(function ($price): void {
                        $price->whereNotNull('menu_channel.price_inclusive')
                            ->orWhereNotNull('menu_channel.price_exclusive');
                    })
                    ->where(function ($scope) use ($now): void {
                        $scope->whereNull('menu_channel.starts_at')->orWhere('menu_channel.starts_at', '<=', $now);
                    })
                    ->where(function ($scope) use ($now): void {
                        $scope->whereNull('menu_channel.ends_at')->orWhere('menu_channel.ends_at', '>=', $now);
                    });
            })->addSelect([
                'menu_channel.id as menu_item_channel_id',
                'menu_channel.channel_code',
                'menu_channel.price_exclusive',
                'menu_channel.price_inclusive',
                'menu_channel.currency_code',
            ]);
        }

        if (Schema::hasTable('menu_item_trading_schedules')) {
            $weekday = $shopNow->isoWeekday();
            $localDate = $shopNow->toDateString();
            $localTime = $shopNow->format('H:i:s');
            $previousShopDay = $shopNow->copy()->subDay();
            $previousWeekday = $previousShopDay->isoWeekday();
            $previousLocalDate = $previousShopDay->toDateString();

            // No active schedule means the item is unrestricted. Once at
            // least one active schedule exists, one of those schedules must
            // match the shop-local date, weekday and clock window.
            $query->where(function (Builder $availability) use (
                $shop,
                $weekday,
                $localDate,
                $localTime,
                $previousWeekday,
                $previousLocalDate
            ): void {
                $availability->whereNotExists(function (Builder $schedule) use ($shop): void {
                    $this->scopeActiveTradingSchedules($schedule, $shop);
                })->orWhereExists(function (Builder $schedule) use (
                    $shop,
                    $weekday,
                    $localDate,
                    $localTime,
                    $previousWeekday,
                    $previousLocalDate
                ): void {
                    $this->scopeActiveTradingSchedules($schedule, $shop)
                        ->where(function (Builder $window) use (
                            $weekday,
                            $localDate,
                            $localTime,
                            $previousWeekday,
                            $previousLocalDate
                        ): void {
                            $window->where(function (Builder $currentDay) use (
                                $weekday,
                                $localDate,
                                $localTime
                            ): void {
                                $this->scopeTradingScheduleCalendar($currentDay, $localDate, $weekday)
                                    ->where(function (Builder $clock) use ($localTime): void {
                                        $clock->where(function (Builder $unrestricted): void {
                                            $unrestricted->whereNull('active_menu_schedule.starts_at')
                                                ->whereNull('active_menu_schedule.ends_at');
                                        })->orWhere(function (Builder $afterStart) use ($localTime): void {
                                            $afterStart->whereNotNull('active_menu_schedule.starts_at')
                                                ->whereNull('active_menu_schedule.ends_at')
                                                ->where('active_menu_schedule.starts_at', '<=', $localTime);
                                        })->orWhere(function (Builder $beforeEnd) use ($localTime): void {
                                            $beforeEnd->whereNull('active_menu_schedule.starts_at')
                                                ->whereNotNull('active_menu_schedule.ends_at')
                                                ->where('active_menu_schedule.ends_at', '>=', $localTime);
                                        })->orWhere(function (Builder $sameDay) use ($localTime): void {
                                            $sameDay->whereNotNull('active_menu_schedule.starts_at')
                                                ->whereNotNull('active_menu_schedule.ends_at')
                                                ->whereColumn(
                                                    'active_menu_schedule.starts_at',
                                                    '<=',
                                                    'active_menu_schedule.ends_at'
                                                )
                                                ->where('active_menu_schedule.starts_at', '<=', $localTime)
                                                ->where('active_menu_schedule.ends_at', '>=', $localTime);
                                        })->orWhere(function (Builder $overnightBeforeMidnight) use ($localTime): void {
                                            $overnightBeforeMidnight
                                                ->whereNotNull('active_menu_schedule.starts_at')
                                                ->whereNotNull('active_menu_schedule.ends_at')
                                                ->whereColumn(
                                                    'active_menu_schedule.starts_at',
                                                    '>',
                                                    'active_menu_schedule.ends_at'
                                                )
                                                ->where('active_menu_schedule.starts_at', '<=', $localTime);
                                        });
                                    });
                            })->orWhere(function (Builder $previousDay) use (
                                $previousWeekday,
                                $previousLocalDate,
                                $localTime
                            ): void {
                                // After midnight, an overnight window belongs
                                // to the day on which it started.
                                $this->scopeTradingScheduleCalendar(
                                    $previousDay,
                                    $previousLocalDate,
                                    $previousWeekday
                                )
                                    ->whereNotNull('active_menu_schedule.starts_at')
                                    ->whereNotNull('active_menu_schedule.ends_at')
                                    ->whereColumn(
                                        'active_menu_schedule.starts_at',
                                        '>',
                                        'active_menu_schedule.ends_at'
                                    )
                                    ->where('active_menu_schedule.ends_at', '>=', $localTime);
                            });
                        });
                });
            });
        }

        return $query->orderBy('products.name');
    }

    private function scopeActiveTradingSchedules(Builder $query, Shop $shop): Builder
    {
        return $query->selectRaw('1')
            ->from('menu_item_trading_schedules as active_menu_schedule')
            ->whereColumn('active_menu_schedule.product_id', 'products.id')
            ->where('active_menu_schedule.company_id', $shop->company_id)
            ->where('active_menu_schedule.brand_id', $shop->brand_id)
            ->where('active_menu_schedule.is_active', true);
    }

    private function scopeTradingScheduleCalendar(
        Builder $query,
        string $localDate,
        int $weekday
    ): Builder {
        return $query
            ->where(function (Builder $date) use ($localDate): void {
                $date->whereNull('active_menu_schedule.valid_from')
                    ->orWhere('active_menu_schedule.valid_from', '<=', $localDate);
            })
            ->where(function (Builder $date) use ($localDate): void {
                $date->whereNull('active_menu_schedule.valid_to')
                    ->orWhere('active_menu_schedule.valid_to', '>=', $localDate);
            })
            ->where(function (Builder $days) use ($weekday): void {
                $days->whereNull('active_menu_schedule.weekdays')
                    ->orWhereJsonContains('active_menu_schedule.weekdays', $weekday);
            });
    }

    private function shopLocalTime(Shop $shop): Carbon
    {
        $timezone = (string) ($shop->timezone
            ?: $shop->country?->timezone
            ?: config('app.timezone', 'UTC'));
        if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        return now($timezone);
    }

    private function maxUpdatedAt(string $table, array $scope): ?string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'updated_at')) {
            return null;
        }
        $query = DB::table($table);
        foreach ($scope as $column => $value) {
            if ($value !== null && Schema::hasColumn($table, $column)) {
                $query->where($column, $value);
            }
        }

        return $query->max('updated_at');
    }

    private function countryPayload(Country $country): array
    {
        return [
            'id' => (string) $country->id,
            'code' => $country->code,
            'iso_alpha_2' => $country->iso_alpha_2,
            'iso_alpha_3' => $country->iso_alpha_3,
            'name' => $country->name,
            'currency_code' => $country->currency_code,
            'timezone' => $country->timezone,
            'image' => $this->publicAssetReference($country->image_url, $country->image_path),
            'image_url' => $country->image_url,
            'image_path' => $country->image_path,
        ];
    }

    /**
     * Turn an uploaded public-disk path into a reference a terminal can fetch.
     * Explicit remote URLs are kept unchanged. The raw path is still included
     * beside this value so an edge can rebase locally cached assets later.
     */
    public function publicAssetReference(?string $explicitUrl, ?string $storedPath): ?string
    {
        $explicitUrl = trim((string) $explicitUrl);
        if ($explicitUrl !== '') {
            return $explicitUrl;
        }

        $storedPath = trim((string) $storedPath);
        if ($storedPath === '') {
            return null;
        }
        if (Str::startsWith(strtolower($storedPath), ['http://', 'https://', 'data:'])) {
            return $storedPath;
        }

        $path = '/'.ltrim(str_replace('\\', '/', $storedPath), '/');
        if (! Str::startsWith($path, ['/storage/', '/api/assets/', '/assets/'])) {
            $path = '/storage'.$path;
        }

        return url($path);
    }

    private function tillPayload(PosTill $till): array
    {
        return [
            'id' => (string) $till->id,
            'code' => $till->till_code,
            'name' => $till->till_name,
            'device_label' => $till->device_label,
            'configuration_version' => (int) ($till->configuration_version ?: 1),
            'is_active' => (bool) $till->is_active,
        ];
    }

    private function availableCountryCode(string $name, string $countryId): string
    {
        $base = substr(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?: 'COUNTRY'), 0, 16);
        $candidate = $base;
        for ($suffix = 1; Country::query()->where('code', $candidate)->whereKeyNot($countryId)->exists(); $suffix++) {
            $tail = (string) $suffix;
            $candidate = substr($base, 0, 16 - strlen($tail)).$tail;
        }

        return $candidate;
    }

    private function deterministicUuid(string $seed): string
    {
        $hex = substr(hash('sha256', 'servnest:'.$seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }
}
