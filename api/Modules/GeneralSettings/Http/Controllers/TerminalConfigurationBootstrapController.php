<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Enterprise\HospitalityMenuPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Services\PosAuthenticationContextService;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Sales\Services\HospitalityOutputConfigurationService;

/**
 * Public-to-the-activated-device bootstrap used before cashier sign-in.
 *
 * The route is not a public tenant catalogue: ResolveCompanyFromDomain and
 * ResolveTillFromToken require the random registered X-Till-Token first. No
 * user credential, policy settings, costs, suppliers or sync secrets are
 * projected here.
 */
class TerminalConfigurationBootstrapController extends Controller
{
    public function __construct(
        private readonly EnterpriseHierarchyService $hierarchy,
        private readonly PosAuthenticationContextService $posAuthenticationContext,
        private readonly HospitalityMenuPriceService $menuPrices,
        private readonly HospitalityOutputConfigurationService $outputConfiguration,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $deploymentMode = strtolower((string) config('servnest.deployment_mode', 'standalone'));
        if (! in_array($deploymentMode, ['edge', 'standalone'], true)) {
            return response()->json([
                'message' => 'Terminal configuration is available only from a Front Office deployment.',
                'code' => 'FRONT_OFFICE_DEPLOYMENT_REQUIRED',
            ], 403);
        }

        $till = app()->bound('till') ? app('till') : null;
        $company = app()->bound('company') ? app('company') : null;
        if (! $till instanceof PosTill
            || ! $company
            || (string) $till->company_id !== (string) $company->id
            || ! $till->is_registered
            || ! $till->is_active) {
            return response()->json([
                'message' => 'A valid terminal activation is required.',
                'code' => 'CURRENT_TERMINAL_REQUIRED',
            ], 403);
        }

        if (! Schema::hasTable('shops') || ! Schema::hasTable('brand_products')) {
            return response()->json([
                'message' => 'The Hospitality configuration schema has not been installed.',
                'code' => 'HOSPITALITY_CONFIGURATION_NOT_READY',
            ], 409);
        }

        if (! $till->shop_id
            || ! $till->country_id
            || ! $till->brand_id
            || ! $till->complex_id) {
            return $this->hierarchyNotReady();
        }

        $shop = Shop::query()
            ->with(['country', 'company', 'brand', 'complex', 'branch'])
            ->where('company_id', $till->company_id)
            ->where('branch_id', $till->branch_id)
            ->whereKey($till->shop_id)
            ->first();
        if (! $this->hasValidTerminalHierarchy($company, $till, $shop)) {
            return $this->hierarchyNotReady();
        }

        $defaultChannel = trim((string) config('servnest.catalogue.default_channel', 'POS')) ?: 'POS';
        $channel = strtoupper(trim((string) $request->query('channel', $defaultChannel)) ?: $defaultChannel);
        if ($channel !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]{0,79}$/D', $channel) !== 1) {
            return response()->json([
                'message' => 'The menu channel is invalid.',
                'errors' => ['channel' => ['Use letters, numbers, underscores or hyphens.']],
            ], 422);
        }

        $hierarchy = $this->hierarchy->hierarchyForShop($shop, $till);
        $manifest = $this->hierarchy->configurationManifest($till);
        $menu = $this->menuPayload($shop, $channel);
        $tableService = $this->tableServicePayload($shop);
        $kitchenOutput = $this->outputConfiguration->forTill($till);
        $authContext = $this->posAuthenticationContext->resolve($company, $till);
        $licenseStatus = app()->bound('license.status') ? app('license.status') : [];
        $license = app()->bound('license') ? app('license') : null;

        $brandLogo = $this->hierarchy->publicAssetReference(
            $shop->brand?->logo_url,
            $shop->brand?->logo_path
        );
        $payload = [
            'deployment_mode' => $deploymentMode,
            'hierarchy' => $hierarchy,
            // Explicit alias for clients that persist their pairing identity
            // separately from visual hierarchy navigation.
            'identity' => $hierarchy,
            'branding' => [
                'name' => $shop->brand?->name ?: $company->company_name,
                'logo' => $brandLogo ?: $company->logo,
                'logo_url' => $shop->brand?->logo_url,
                'logo_path' => $shop->brand?->logo_path,
                'tenant_logo' => $brandLogo ?: $company->logo,
                'company_logo' => $company->logo,
            ],
            'configuration' => [
                ...$manifest,
                'schema_version' => (int) config('servnest.configuration_schema_version', 2),
            ],
            'authentication' => [
                'back_office_login_mode' => 'username_password',
                'pos_login_mode' => $authContext['mode'],
                'allowed_pos_login_methods' => $authContext['methods'],
                'allowed_authentication_methods' => $authContext['methods'],
                'login_code' => $authContext['login_code'],
                'credential_policy' => [
                    'login_code' => $authContext['login_code'],
                ],
                'policy_fingerprint' => $authContext['fingerprint'],
            ],
            'license' => [
                'valid' => (bool) ($licenseStatus['valid'] ?? true),
                'status' => $licenseStatus['status'] ?? $license?->status,
                'expires_at' => $licenseStatus['expires_at']
                    ?? ($license?->expires_at?->toIso8601String()),
                'days_remaining' => $licenseStatus['days_remaining'] ?? null,
            ],
            'terminal_status' => [
                'registered' => (bool) $till->is_registered,
                'active' => (bool) $till->is_active,
            ],
            'operation' => [
                'business_mode' => 'hospitality',
                'hospitality_only' => true,
            ],
            'menu' => $menu,
            'table_service' => $tableService,
            'kitchen_output' => [
                'authoritative' => true,
                ...$kitchenOutput,
            ],
        ];

        return response()->json([
            'message' => 'Terminal Hospitality configuration loaded successfully.',
            'data' => $payload,
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-ServeNest-Configuration-ETag' => (string) $manifest['configuration_etag'],
        ]);
    }

    private function hasValidTerminalHierarchy(object $company, PosTill $till, ?Shop $shop): bool
    {
        if (! $shop
            || strtolower((string) $company->status) !== 'active'
            || strtolower((string) $shop->status) !== 'active'
            || ! $shop->country
            || ! $shop->country->is_active
            || ! $shop->brand
            || strtolower((string) $shop->brand->status) !== 'active'
            || ! $shop->complex
            || strtolower((string) $shop->complex->status) !== 'active'
            || ! $shop->branch
            || strtolower((string) $shop->branch->status) !== 'active') {
            return false;
        }

        $expected = [
            'company_id' => (string) $company->id,
            'country_id' => (string) $company->country_id,
            'brand_id' => (string) $shop->brand_id,
            'complex_id' => (string) $shop->complex_id,
            'branch_id' => (string) $shop->branch_id,
            'shop_id' => (string) $shop->id,
        ];

        return $expected['country_id'] !== ''
            && (string) $shop->company_id === $expected['company_id']
            && (string) $shop->country_id === $expected['country_id']
            && (string) $shop->brand->company_id === $expected['company_id']
            && (string) $shop->brand->country_id === $expected['country_id']
            && (string) $shop->complex->company_id === $expected['company_id']
            && (string) $shop->complex->country_id === $expected['country_id']
            && (string) $shop->branch->company_id === $expected['company_id']
            && (string) $till->company_id === $expected['company_id']
            && (string) $till->country_id === $expected['country_id']
            && (string) $till->brand_id === $expected['brand_id']
            && (string) $till->complex_id === $expected['complex_id']
            && (string) $till->branch_id === $expected['branch_id']
            && (string) $till->shop_id === $expected['shop_id'];
    }

    private function hierarchyNotReady(): JsonResponse
    {
        return response()->json([
            'message' => 'This terminal is not attached to a complete active Hospitality hierarchy.',
            'code' => 'TERMINAL_HIERARCHY_NOT_READY',
        ], 409);
    }

    /** @return array<string, mixed> */
    private function tableServicePayload(Shop $shop): array
    {
        $settings = [
            'id' => null,
            'company_id' => (string) $shop->company_id,
            'branch_id' => (string) $shop->branch_id,
            'shop_id' => (string) $shop->id,
            'assignment_mode' => 'auto',
            'require_table_for_dine_in' => true,
            'takeaway_uses_tables' => false,
            'allow_cross_till_recall' => true,
            'is_active' => true,
            'metadata' => null,
        ];

        if (Schema::hasTable('hospitality_table_settings')) {
            $row = DB::table('hospitality_table_settings')
                ->where('company_id', $shop->company_id)
                ->where('branch_id', $shop->branch_id)
                ->where('shop_id', $shop->id)
                ->first();
            if ($row) {
                $settings = array_merge($settings, [
                    'id' => $row->id,
                    'assignment_mode' => (string) $row->assignment_mode,
                    'require_table_for_dine_in' => (bool) $row->require_table_for_dine_in,
                    'allow_cross_till_recall' => (bool) $row->allow_cross_till_recall,
                    'is_active' => (bool) $row->is_active,
                    'metadata' => $this->decodeJson($row->metadata ?? null),
                ]);
            }
        }

        $tables = collect();
        if (Schema::hasTable('hospitality_tables')) {
            $tables = DB::table('hospitality_tables')
                ->where('company_id', $shop->company_id)
                ->where('branch_id', $shop->branch_id)
                ->where('shop_id', $shop->id)
                ->where('table_type', 'dining')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('section')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get([
                    'id', 'company_id', 'branch_id', 'shop_id', 'code', 'name',
                    'section', 'table_type', 'capacity', 'sort_order', 'is_active',
                    'metadata', 'updated_at',
                ])
                ->map(function (object $table): array {
                    $values = (array) $table;
                    $values['capacity'] = (int) $values['capacity'];
                    $values['sort_order'] = (int) $values['sort_order'];
                    $values['is_active'] = (bool) $values['is_active'];
                    $values['metadata'] = $this->decodeJson($values['metadata'] ?? null);

                    return $values;
                });
        }

        return [
            'authoritative' => true,
            'settings' => $settings,
            'tables' => $tables->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function menuPayload(Shop $shop, ?string $channel): array
    {
        $rawItems = $this->hierarchy->visibleMenuQuery($shop, $channel)->get();
        $productIds = $rawItems->pluck('id')->map(fn ($id): string => (string) $id)->values();
        $channelsByProduct = $this->channelsByProduct($shop, $productIds);
        $schedulesByProduct = $this->schedulesByProduct($shop, $productIds);
        $prepMessagesByProduct = $this->prepMessagesByProduct($shop, $productIds);
        $modifierGroupsByProduct = $this->modifierGroupsByProduct($shop, $productIds);
        $legacyModifiersByProduct = $this->legacyModifiersByProduct($shop, $productIds);
        $modifierProductIds = $modifierGroupsByProduct
            ->flatMap(fn (Collection $groups): Collection => $groups
                ->flatMap(fn (array $group): array => $group['options'] ?? []))
            ->pluck('modifier_product_id')
            ->filter()
            ->map(fn ($id): string => (string) $id);
        $legacyRecipeIds = $legacyModifiersByProduct
            ->flatten(1)
            ->pluck('recipe_id')
            ->filter()
            ->map(fn ($id): string => (string) $id);
        $recipes = $this->recipesForProducts(
            $shop,
            $productIds->merge($modifierProductIds)->unique()->values(),
            $legacyRecipeIds->unique()->values()
        );
        $recipesByProduct = $recipes->groupBy(
            fn (array $recipe): string => (string) ($recipe['menu_product_id']
                ?: $recipe['produced_product_id']
                ?: '')
        );
        $visibility = $this->visibilityPayload($shop, $channel, $productIds);

        $items = $rawItems->map(function (object $item) use (
            $shop,
            $channelsByProduct,
            $schedulesByProduct,
            $prepMessagesByProduct,
            $modifierGroupsByProduct,
            $legacyModifiersByProduct,
            $recipesByProduct
        ): array {
            $id = (string) $item->id;
            $compatiblePrice = isset($item->menu_item_channel_id)
                ? $this->menuPrices->bootstrapPayload(
                    $this->menuPrices->fromChannelRow($item, $id, (string) $shop->branch_id)
                )
                : null;

            return [
                'id' => $id,
                'company_id' => (string) $shop->company_id,
                'brand_id' => (string) $shop->brand_id,
                'shop_id' => (string) $shop->id,
                'category_id' => $item->category_id ?? null,
                'sub_category_id' => $item->sub_category_id ?? null,
                'code' => $item->code ?? null,
                'external_code' => $item->external_code ?? null,
                'legacy_code' => $item->legacy_code ?? null,
                'name' => $item->name ?? null,
                'description' => $item->description ?? null,
                'image' => ($item->image_url ?? null) ?: ($item->image_path ?? null),
                'product_type' => $item->product_type ?? 'finished_good',
                'allow_discount' => (bool) ($item->allow_discount ?? false),
                'taxable' => (bool) ($item->taxable ?? false),
                'is_active' => (bool) ($item->is_active ?? true),
                'is_available' => (bool) ($item->is_available ?? true),
                'channel_code' => $item->channel_code ?? null,
                'price_exclusive' => $item->price_exclusive ?? null,
                'price_inclusive' => $item->price_inclusive ?? null,
                'currency_code' => $item->currency_code ?? null,
                'product_price_id' => $compatiblePrice['id'] ?? null,
                'prices' => $compatiblePrice ? [$compatiblePrice] : [],
                'market' => $item->market ?? null,
                'department' => $item->department ?? null,
                'flavour' => $item->flavour ?? null,
                'size_label' => $item->size_label ?? null,
                'channels' => $channelsByProduct->get($id, collect())->values()->all(),
                'trading_schedules' => $schedulesByProduct->get($id, collect())->values()->all(),
                'prep_messages' => $prepMessagesByProduct->get($id, collect())->values()->all(),
                'modifier_groups' => $modifierGroupsByProduct->get($id, collect())->values()->all(),
                'legacy_modifiers' => $legacyModifiersByProduct->get($id, collect())->values()->all(),
                'recipes' => $recipesByProduct->get($id, collect())->values()->all(),
            ];
        })->values();

        return [
            'authoritative' => true,
            'shop_id' => (string) $shop->id,
            'brand_id' => (string) $shop->brand_id,
            'channel' => $channel,
            'items' => $items->all(),
            'categories' => $this->categories($shop, $items)->all(),
            'sub_categories' => $this->subCategories($shop, $items)->all(),
            'recipes' => $recipes->values()->all(),
            'visibility' => $visibility,
            'counts' => [
                'menu_items' => $items->count(),
                'recipes' => $recipes->count(),
                'prep_message_assignments' => $prepMessagesByProduct->flatten(1)->count(),
                'modifier_group_assignments' => $modifierGroupsByProduct->flatten(1)->count(),
                'legacy_modifier_assignments' => $legacyModifiersByProduct->flatten(1)->count(),
                'visibility_overrides' => count($visibility['overrides']),
            ],
        ];
    }

    private function legacyModifiersByProduct(Shop $shop, Collection $productIds): Collection
    {
        if ($productIds->isEmpty()
            || ! Schema::hasTable('product_modifiers')
            || ! Schema::hasTable('product_modifier_assignments')) {
            return collect();
        }

        $query = DB::table('product_modifier_assignments as assignment')
            ->join('product_modifiers as modifier', 'modifier.id', '=', 'assignment.modifier_id')
            ->where('assignment.company_id', $shop->company_id)
            ->whereIn('assignment.product_id', $productIds->all())
            ->where('assignment.is_active', true)
            ->where('modifier.is_active', true)
            ->where(function ($branch) use ($shop): void {
                $branch->whereNull('assignment.branch_id')
                    ->orWhere('assignment.branch_id', $shop->branch_id);
            })
            ->where(function ($branch) use ($shop): void {
                $branch->whereNull('modifier.branch_id')
                    ->orWhere('modifier.branch_id', $shop->branch_id);
            });
        if (Schema::hasColumn('product_modifier_assignments', 'brand_id')) {
            $query->where('assignment.brand_id', $shop->brand_id);
        }
        if (Schema::hasColumn('product_modifiers', 'brand_id')) {
            $query->where('modifier.brand_id', $shop->brand_id);
        }

        return $query->orderBy('assignment.sort_order')
            ->get([
                'assignment.id as assignment_id', 'assignment.product_id',
                'assignment.is_required', 'assignment.min_select', 'assignment.max_select',
                'assignment.sort_order', 'modifier.id', 'modifier.code', 'modifier.name',
                'modifier.modifier_product_id', 'modifier.recipe_id', 'modifier.price',
                'modifier.currency_code', 'modifier.deduct_recipe_stock',
                'modifier.print_on_kitchen', 'modifier.print_on_customer_receipt',
            ])
            ->map(function (object $row): array {
                $values = (array) $row;
                foreach ([
                    'is_required', 'deduct_recipe_stock', 'print_on_kitchen',
                    'print_on_customer_receipt',
                ] as $boolean) {
                    $values[$boolean] = (bool) ($values[$boolean] ?? false);
                }

                return $values;
            })
            ->groupBy(fn (array $row): string => (string) $row['product_id']);
    }

    /**
     * Current brand recipes and their BOM lines for visible menu/modifier
     * products. Cost fields are intentionally omitted from this pre-login
     * terminal contract.
     */
    private function recipesForProducts(
        Shop $shop,
        Collection $productIds,
        Collection $explicitRecipeIds
    ): Collection {
        if (($productIds->isEmpty() && $explicitRecipeIds->isEmpty())
            || ! Schema::hasTable('food_recipes')) {
            return collect();
        }

        $query = DB::table('food_recipes')
            ->where('company_id', $shop->company_id);
        if (Schema::hasColumn('food_recipes', 'brand_id')) {
            $query->where('brand_id', $shop->brand_id);
        }
        $query->where(function ($targets) use ($productIds, $explicitRecipeIds): void {
            if ($productIds->isNotEmpty()) {
                $targets->whereIn('menu_product_id', $productIds->all());
                if (Schema::hasColumn('food_recipes', 'produced_product_id')) {
                    $targets->orWhereIn('produced_product_id', $productIds->all());
                }
            }
            if ($explicitRecipeIds->isNotEmpty()) {
                $method = $productIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                $targets->{$method}('id', $explicitRecipeIds->all());
            }
        });
        if (Schema::hasColumn('food_recipes', 'is_current')) {
            $query->where('is_current', true);
        }
        if (Schema::hasColumn('food_recipes', 'is_active')) {
            $query->where('is_active', true);
        }
        if (Schema::hasColumn('food_recipes', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $recipes = $query->orderBy('id')->get($this->availableColumns('food_recipes', [
            'id', 'brand_id', 'menu_product_id', 'produced_product_id', 'name',
            'external_code', 'recipe_type', 'version_no', 'yield_quantity', 'yield_uom_id',
            'source_stock_area', 'destination_stock_area', 'effective_from', 'effective_to',
            'is_current', 'is_active', 'updated_at',
        ]));
        if ($recipes->isEmpty()) {
            return collect();
        }

        $ingredients = collect();
        if (Schema::hasTable('food_recipe_ingredients')) {
            $ingredients = DB::table('food_recipe_ingredients as ingredient')
                ->leftJoin('products as product', 'product.id', '=', 'ingredient.ingredient_product_id')
                ->where('ingredient.company_id', $shop->company_id)
                ->whereIn('ingredient.food_recipe_id', $recipes->pluck('id')->all())
                ->orderBy('ingredient.sequence_no')
                ->get([
                    'ingredient.id', 'ingredient.food_recipe_id', 'ingredient.child_recipe_id',
                    'ingredient.ingredient_product_id', 'ingredient.uom_id', 'ingredient.line_type',
                    'ingredient.sequence_no', 'ingredient.quantity', 'ingredient.wastage_percent',
                    'ingredient.notes', 'product.code as ingredient_code',
                    'product.name as ingredient_name',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->groupBy(fn (array $row): string => (string) $row['food_recipe_id']);
        }

        return $recipes->map(function (object $recipe) use ($ingredients): array {
            $values = (array) $recipe;
            $values['ingredients'] = $ingredients
                ->get((string) $recipe->id, collect())
                ->values()
                ->all();

            return $values;
        });
    }

    /** @return array<string, mixed> */
    private function visibilityPayload(Shop $shop, ?string $channel, Collection $visibleProductIds): array
    {
        if (! Schema::hasTable('shop_menu_item_overrides')) {
            return [
                'authoritative' => true,
                'visible_product_ids' => $visibleProductIds->all(),
                'excluded_product_ids' => [],
                'overrides' => [],
            ];
        }

        $channelKey = strtoupper(trim((string) $channel));
        $overrides = DB::table('shop_menu_item_overrides')
            ->where('company_id', $shop->company_id)
            ->where('brand_id', $shop->brand_id)
            ->where('shop_id', $shop->id)
            ->whereIn('channel_key', array_values(array_unique(array_filter(['*', $channelKey]))))
            ->orderBy('product_id')
            ->orderBy('channel_key')
            ->get($this->availableColumns('shop_menu_item_overrides', [
                'id', 'product_id', 'is_available', 'channel_code', 'channel_key',
                'starts_at', 'ends_at', 'reason', 'configuration_version', 'updated_at',
            ]))
            ->map(function (object $override): array {
                $values = (array) $override;
                $values['is_available'] = (bool) $values['is_available'];

                return $values;
            });

        $now = now();
        $excluded = $overrides
            ->groupBy('product_id')
            ->map(function (Collection $rows) use ($channelKey, $now): ?array {
                $current = $rows->filter(function (array $row) use ($now): bool {
                    return (empty($row['starts_at']) || $now->greaterThanOrEqualTo($row['starts_at']))
                        && (empty($row['ends_at']) || $now->lessThanOrEqualTo($row['ends_at']));
                });

                return $current->firstWhere('channel_key', $channelKey)
                    ?? $current->firstWhere('channel_key', '*');
            })
            ->filter(fn (?array $row): bool => $row !== null && ! $row['is_available'])
            ->keys()
            ->map(fn ($id): string => (string) $id)
            ->values();

        return [
            'authoritative' => true,
            'visible_product_ids' => $visibleProductIds->all(),
            'excluded_product_ids' => $excluded->all(),
            'overrides' => $overrides->values()->all(),
        ];
    }

    private function channelsByProduct(Shop $shop, Collection $productIds): Collection
    {
        if ($productIds->isEmpty() || ! Schema::hasTable('menu_item_channels')) {
            return collect();
        }

        return DB::table('menu_item_channels')
            ->where('company_id', $shop->company_id)
            ->where('brand_id', $shop->brand_id)
            ->whereIn('product_id', $productIds->all())
            ->where('is_available', true)
            ->where(function ($price): void {
                $price->whereNotNull('price_inclusive')->orWhereNotNull('price_exclusive');
            })
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('channel_code')
            ->get(['id', 'product_id', 'channel_code', 'price_exclusive', 'price_inclusive', 'currency_code'])
            ->map(fn (object $row): array => (array) $row)
            ->groupBy(fn (array $row): string => (string) $row['product_id']);
    }

    private function schedulesByProduct(Shop $shop, Collection $productIds): Collection
    {
        if ($productIds->isEmpty() || ! Schema::hasTable('menu_item_trading_schedules')) {
            return collect();
        }

        return DB::table('menu_item_trading_schedules')
            ->where('company_id', $shop->company_id)
            ->where('brand_id', $shop->brand_id)
            ->whereIn('product_id', $productIds->all())
            ->where('is_active', true)
            ->orderBy('schedule_code')
            ->get(['id', 'product_id', 'schedule_code', 'weekdays', 'starts_at', 'ends_at', 'valid_from', 'valid_to'])
            ->map(function (object $row): array {
                $values = (array) $row;
                $values['weekdays'] = $this->decodeJson($values['weekdays'] ?? null);

                return $values;
            })
            ->groupBy(fn (array $row): string => (string) $row['product_id']);
    }

    private function prepMessagesByProduct(Shop $shop, Collection $productIds): Collection
    {
        if ($productIds->isEmpty()
            || ! Schema::hasTable('product_prep_messages')
            || ! Schema::hasTable('product_prep_message_assignments')) {
            return collect();
        }

        $query = DB::table('product_prep_message_assignments as assignment')
            ->join('product_prep_messages as message', 'message.id', '=', 'assignment.prep_message_id')
            ->where('assignment.company_id', $shop->company_id)
            ->whereIn('assignment.product_id', $productIds->all())
            ->where('assignment.is_active', true)
            ->where('message.is_active', true)
            ->where(function ($branch) use ($shop): void {
                $branch->whereNull('assignment.branch_id')->orWhere('assignment.branch_id', $shop->branch_id);
            });
        if (Schema::hasColumn('product_prep_message_assignments', 'brand_id')) {
            $query->where('assignment.brand_id', $shop->brand_id);
        }
        if (Schema::hasColumn('product_prep_messages', 'brand_id')) {
            $query->where('message.brand_id', $shop->brand_id);
        }

        return $query->orderBy('assignment.sort_order')
            ->get([
                'assignment.product_id', 'assignment.sort_order', 'assignment.is_default',
                'message.id', 'message.code', 'message.message', 'message.allow_free_text',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->groupBy(fn (array $row): string => (string) $row['product_id']);
    }

    private function modifierGroupsByProduct(Shop $shop, Collection $productIds): Collection
    {
        if ($productIds->isEmpty()
            || ! Schema::hasTable('menu_item_modifier_groups')
            || ! Schema::hasTable('modifier_groups')
            || ! Schema::hasTable('modifier_group_options')) {
            return collect();
        }

        $relations = DB::table('menu_item_modifier_groups as relation')
            ->join('modifier_groups as modifier_group', 'modifier_group.id', '=', 'relation.modifier_group_id')
            ->where('relation.company_id', $shop->company_id)
            ->where('relation.brand_id', $shop->brand_id)
            ->whereIn('relation.menu_product_id', $productIds->all())
            ->where('relation.is_active', true)
            ->where('modifier_group.is_active', true)
            ->orderBy('relation.sort_order')
            ->get([
                'relation.menu_product_id', 'relation.modifier_group_id', 'relation.sort_order',
                'relation.minimum_selections', 'relation.maximum_selections',
                'modifier_group.code', 'modifier_group.name', 'modifier_group.is_required',
                'modifier_group.minimum_selections as group_minimum_selections',
                'modifier_group.maximum_selections as group_maximum_selections',
            ]);
        $groupIds = $relations->pluck('modifier_group_id')->map(fn ($id): string => (string) $id)->unique();
        $options = $groupIds->isEmpty()
            ? collect()
            : DB::table('modifier_group_options as option')
                ->join('products as product', 'product.id', '=', 'option.modifier_product_id')
                ->where('option.company_id', $shop->company_id)
                ->where('option.brand_id', $shop->brand_id)
                ->whereIn('option.modifier_group_id', $groupIds->all())
                ->where('option.is_active', true)
                ->where('product.is_active', true)
                ->orderBy('option.sort_order')
                ->get([
                    'option.id', 'option.modifier_group_id', 'option.modifier_product_id',
                    'option.external_code', 'option.sort_order', 'option.price_delta',
                    'option.is_default', 'product.name', 'product.code',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->groupBy(fn (array $row): string => (string) $row['modifier_group_id']);
        $excluded = Schema::hasTable('menu_item_modifier_exclusions')
            ? DB::table('menu_item_modifier_exclusions')
                ->where('company_id', $shop->company_id)
                ->where('brand_id', $shop->brand_id)
                ->whereIn('menu_product_id', $productIds->all())
                ->get(['menu_product_id', 'modifier_option_id'])
                ->groupBy(fn (object $row): string => (string) $row->menu_product_id)
                ->map(fn (Collection $rows) => $rows->pluck('modifier_option_id')->map(fn ($id): string => (string) $id)->flip())
            : collect();

        return $relations->map(function (object $relation) use ($options, $excluded): array {
            $menuId = (string) $relation->menu_product_id;
            $excludedForMenu = $excluded->get($menuId, collect());

            return [
                'id' => (string) $relation->modifier_group_id,
                'code' => $relation->code,
                'name' => $relation->name,
                'sort_order' => (int) $relation->sort_order,
                'is_required' => (bool) $relation->is_required,
                'minimum_selections' => $relation->minimum_selections ?? $relation->group_minimum_selections,
                'maximum_selections' => $relation->maximum_selections ?? $relation->group_maximum_selections,
                'options' => $options->get((string) $relation->modifier_group_id, collect())
                    ->reject(fn (array $option): bool => $excludedForMenu->has((string) $option['id']))
                    ->values()
                    ->all(),
                '_menu_product_id' => $menuId,
            ];
        })->groupBy('_menu_product_id')->map(fn (Collection $groups) => $groups
            ->map(function (array $group): array {
                unset($group['_menu_product_id']);

                return $group;
            }));
    }

    private function categories(Shop $shop, Collection $items): Collection
    {
        if (! Schema::hasTable('product_categories')) {
            return collect();
        }
        $ids = $items->pluck('category_id')->filter()->map(fn ($id): string => (string) $id)->unique();
        $frontier = $ids;
        for ($depth = 0; $depth < 20 && $frontier->isNotEmpty(); $depth++) {
            $parents = DB::table('product_categories')
                ->where('company_id', $shop->company_id)
                ->whereIn('id', $frontier->all())
                ->whereNotNull('parent_id')
                ->pluck('parent_id')->map(fn ($id): string => (string) $id)->diff($ids)->unique();
            $ids = $ids->merge($parents)->unique();
            $frontier = $parents;
        }
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('product_categories')
            ->where('company_id', $shop->company_id)
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get($this->availableColumns('product_categories', [
                'id', 'parent_id', 'name', 'code', 'description', 'image_path', 'image_url',
                'banner_image_path', 'banner_image_url', 'display_area', 'sort_order', 'is_active',
            ]))->map(fn (object $row): array => (array) $row);
    }

    private function subCategories(Shop $shop, Collection $items): Collection
    {
        if (! Schema::hasTable('product_sub_categories')) {
            return collect();
        }
        $ids = $items->pluck('sub_category_id')->filter()->map(fn ($id): string => (string) $id)->unique();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('product_sub_categories')
            ->where('company_id', $shop->company_id)
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get($this->availableColumns('product_sub_categories', [
                'id', 'category_id', 'name', 'code', 'description', 'image_path', 'image_url',
                'banner_image_path', 'banner_image_url', 'display_area', 'sort_order', 'is_active',
            ]))->map(fn (object $row): array => (array) $row);
    }

    /** @return list<string> */
    private function availableColumns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
    }

    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
