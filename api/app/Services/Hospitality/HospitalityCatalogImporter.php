<?php

namespace App\Services\Hospitality;

use App\Services\Sync\SyncConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class HospitalityCatalogImporter
{
    /**
     * POS is the stable terminal channel. The supplied GAAP workbooks use
     * different names for their counter price, so imports project exactly one
     * deterministic POS alias while preserving every source channel.
     *
     * @var array<int, string>
     */
    private const POS_CHANNEL_PRIORITY = ['INSTORE', 'TAKEAWAY', 'DINE_IN', 'DELIVERY'];

    /** @var array<string, array<int, string>> */
    private array $columns = [];

    /** @var array<string, string> */
    private array $productIds = [];

    /** @var array<string, string> */
    private array $productKinds = [];

    /** @var array<string, string> */
    private array $productUoms = [];

    /** @var array<string, string> */
    private array $uomIds = [];

    /** @var array<string, string> */
    private array $categoryIds = [];

    /** @var array<string, string> */
    private array $modifierGroupIds = [];

    /** @var array<string, string> */
    private array $modifierOptionIds = [];

    /** @var array<string, string> */
    private array $recipeIds = [];

    public function __construct(private readonly SyncConfigurationService $sync) {}

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    public function import(
        array $catalog,
        string $companyId,
        string $brandId,
        string $currency = 'USD',
        string $sourceSystem = 'gaap',
        ?string $createdBy = null,
        bool $force = false,
        ?string $branchId = null
    ): array {
        $this->resetImportState();
        $this->assertSchema();
        $this->assertTenant($companyId, $brandId, $branchId);
        $this->assertCatalogue($catalog);

        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Currency must be a three-letter ISO code.');
        }
        $sourceSystem = strtolower(trim($sourceSystem)) ?: 'gaap';
        $source = $catalog['source'];
        $branchId = trim((string) $branchId) ?: null;
        $scopeKey = $this->catalogImportScopeKey($branchId);

        $existing = DB::table('hospitality_catalog_imports')
            ->where('company_id', $companyId)
            ->where('brand_id', $brandId)
            ->where('source_sha256', $source['sha256'])
            ->where('dry_run', false)
            ->where('scope_key', $scopeKey)
            ->first();
        if ($existing && $existing->status === 'completed' && ! $force) {
            return [
                'id' => (string) $existing->id,
                'status' => 'already_imported',
                'statistics' => $this->decodeJson($existing->statistics),
            ];
        }

        $importId = $existing?->id ?: (string) Str::uuid();
        $now = now();
        $importPayload = [
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'branch_id' => $branchId,
            'scope_key' => $scopeKey,
            'source_system' => $sourceSystem,
            'source_name' => $source['name'],
            'source_sha256' => $source['sha256'],
            'source_version' => $source['version'] ?? null,
            'status' => 'processing',
            'dry_run' => false,
            'sheet_manifest' => $this->json($catalog['sheet_manifest'] ?? []),
            'statistics' => null,
            'errors' => null,
            'created_by' => $createdBy,
            'started_at' => $now,
            'completed_at' => null,
            'updated_at' => $now,
        ];
        if ($existing) {
            DB::table('hospitality_catalog_imports')->where('id', $importId)->update($importPayload);
        } else {
            DB::table('hospitality_catalog_imports')->insert([
                'id' => $importId,
                ...$importPayload,
                'created_at' => $now,
            ]);
        }

        try {
            $statistics = DB::transaction(function () use (
                $catalog,
                $companyId,
                $brandId,
                $currency,
                $sourceSystem,
                $createdBy,
                $importId,
                $branchId,
                $scopeKey
            ): array {
                DB::table('brands')
                    ->where('id', $brandId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->value('id');

                $costBranchIds = $this->costBranchIds($companyId, $brandId, $branchId);
                foreach ($catalog['products'] as $product) {
                    $this->importProduct(
                        $product,
                        $companyId,
                        $brandId,
                        $sourceSystem,
                        $currency,
                        $costBranchIds
                    );
                }
                $posChannelAliases = $this->importMenus($catalog, $companyId, $brandId, $currency);
                $this->importModifiers($catalog, $companyId, $brandId, $currency, $sourceSystem);
                $this->importRecipes(
                    $catalog,
                    $companyId,
                    $brandId,
                    $currency,
                    $sourceSystem,
                    $createdBy
                );
                $this->bumpConfigurationVersions($companyId, $brandId);

                $statistics = [
                    ...($catalog['statistics'] ?? []),
                    'company_id' => $companyId,
                    'brand_id' => $brandId,
                    'branch_id' => $branchId,
                    'scope_key' => $scopeKey,
                    'source_system' => $sourceSystem,
                    'cost_branches' => count($costBranchIds),
                    'pos_channel_aliases' => $posChannelAliases,
                    'completed_at' => now()->toIso8601String(),
                ];
                DB::table('hospitality_catalog_imports')->where('id', $importId)->update([
                    'status' => 'completed',
                    'statistics' => $this->json($statistics),
                    'errors' => $this->json($catalog['issues'] ?? $catalog['warnings'] ?? []),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

                return $statistics;
            }, 3);
        } catch (Throwable $exception) {
            DB::table('hospitality_catalog_imports')->where('id', $importId)->update([
                'status' => 'failed',
                'errors' => $this->json([[
                    'type' => $exception::class,
                    'message' => Str::limit($exception->getMessage(), 1500, ''),
                ]]),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            throw $exception;
        }

        $this->signalAffectedShops($companyId, $brandId);

        return ['id' => $importId, 'status' => 'completed', 'statistics' => $statistics];
    }

    private function assertSchema(): void
    {
        $required = [
            'companies', 'brands', 'products', 'product_categories', 'uoms',
            'brand_products', 'product_uom_conversions', 'menu_item_channels',
            'menu_item_trading_schedules', 'modifier_groups', 'modifier_group_options',
            'menu_item_modifier_groups', 'menu_item_modifier_exclusions',
            'food_recipes', 'food_recipe_ingredients',
            'hospitality_catalog_imports', 'hospitality_external_keys',
        ];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Run the enterprise hospitality migrations before importing: '.implode(', ', $missing)
            );
        }
        foreach (['branch_id', 'scope_key'] as $column) {
            if (! Schema::hasColumn('hospitality_catalog_imports', $column)) {
                throw new RuntimeException(
                    'Run the scoped hospitality catalogue import migration before importing.'
                );
            }
        }
    }

    private function assertTenant(string $companyId, string $brandId, ?string $branchId = null): void
    {
        if (! DB::table('companies')->where('id', $companyId)->exists()) {
            throw new RuntimeException('The selected company does not exist.');
        }
        if (! DB::table('brands')->where('id', $brandId)->where('company_id', $companyId)->exists()) {
            throw new RuntimeException('The selected brand does not belong to the selected company.');
        }
        $branchId = trim((string) $branchId);
        if ($branchId !== '' && ! in_array($branchId, $this->costBranchIds($companyId, $brandId, $branchId), true)) {
            throw new RuntimeException('The selected branch is not assigned to the selected brand.');
        }
    }

    private function catalogImportScopeKey(?string $branchId): string
    {
        $branchId = trim((string) $branchId);

        return $branchId === '' ? 'brand' : 'branch:'.strtolower($branchId);
    }

    /** @param array<string, mixed> $catalog */
    private function assertCatalogue(array $catalog): void
    {
        if (! isset($catalog['source']['sha256'], $catalog['source']['name'])
            || ! is_array($catalog['products'] ?? null)
            || ! is_array($catalog['recipes'] ?? null)) {
            throw new RuntimeException('The parsed hospitality catalogue is incomplete.');
        }
        if (($catalog['products'] ?? []) === []) {
            throw new RuntimeException('The workbook did not contain any importable products.');
        }
    }

    /** @param array<string, mixed> $record */
    private function importProduct(
        array $record,
        string $companyId,
        string $brandId,
        string $sourceSystem,
        string $currency,
        array $costBranchIds
    ): void
    {
        $externalCode = strtoupper((string) $record['external_code']);
        $categoryId = $this->categoryId($companyId, $brandId, (string) ($record['category'] ?: 'Imported Hospitality'));
        $uomId = $this->uomId($companyId, (string) ($record['uom'] ?: 'EACH'));
        $supplierId = $this->supplierId($companyId, $record);
        $productId = $this->mappedEntityId($companyId, $brandId, $sourceSystem, 'product', $externalCode);

        if (! $productId) {
            $productId = DB::table('brand_products')
                ->where('brand_id', $brandId)
                ->where('external_code', $externalCode)
                ->value('product_id');
        }
        if (! $productId) {
            $candidateIds = DB::table('products')
                ->where('company_id', $companyId)
                ->where('code', $externalCode)
                ->when(Schema::hasColumn('products', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->limit(2)
                ->pluck('id');
            if ($candidateIds->isEmpty() && Schema::hasColumn('products', 'deleted_at')) {
                $candidateIds = DB::table('products')
                    ->where('company_id', $companyId)
                    ->where('code', $externalCode)
                    ->orderByDesc('deleted_at')
                    ->limit(1)
                    ->pluck('id');
            }
            if ($candidateIds->count() > 1) {
                throw new RuntimeException(
                    "Product code {$externalCode} is ambiguous inside the selected company."
                );
            }
            $productId = $candidateIds->first();
            if ($productId) {
                $this->assertReusableProductOwnership((string) $productId, $brandId, $externalCode);
            }
        }

        $name = $this->uniqueProductName(
            $companyId,
            (string) ($record['name'] ?: $externalCode),
            $productId ? (string) $productId : null,
            $externalCode
        );
        $kind = (string) ($record['item_kind'] ?? 'raw_material');
        $tracked = in_array($kind, ['raw_material', 'production_item', 'packaging'], true);
        $productPayload = $this->onlyColumns('products', [
            'company_id' => $companyId,
            'branch_id' => null,
            'is_global' => true,
            'category_id' => $categoryId,
            'supplier_id' => $supplierId,
            'base_uom_id' => $uomId,
            'name' => $name,
            'code' => $externalCode,
            'sku' => $externalCode,
            'description' => (string) ($record['name'] ?: $externalCode),
            'product_type' => match ($kind) {
                'production_item' => 'semi_finished',
                'menu_item' => 'finished_good',
                'modifier' => 'service',
                'packaging' => 'packaging',
                default => 'raw_material',
            },
            'stock_tracking_enabled' => $tracked,
            'batch_tracking_enabled' => false,
            'expiry_tracking_enabled' => false,
            'allow_negative_stock' => false,
            'allow_discount' => $kind === 'menu_item',
            'taxable' => (float) ($record['tax_rate'] ?? 0) > 0,
            'reorder_level' => 0,
            'minimum_stock' => 0,
            'maximum_stock' => 0,
            'is_active' => true,
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        if ($productId && DB::table('products')->where('id', $productId)->exists()) {
            DB::table('products')->where('id', $productId)->update($productPayload);
            $productId = (string) $productId;
        } else {
            $productId = (string) Str::uuid();
            DB::table('products')->insert([
                'id' => $productId,
                ...$productPayload,
                'created_at' => now(),
            ]);
        }

        $this->upsertUuid(
            'brand_products',
            ['brand_id' => $brandId, 'product_id' => $productId],
            [
                'company_id' => $companyId,
                'item_kind' => $kind,
                'external_code' => $externalCode,
                'legacy_code' => $record['legacy_code'] ?? null,
                'market' => $record['market'] ?? null,
                'department' => $record['department'] ?? null,
                'flavour' => $record['flavour'] ?? null,
                'size_label' => $record['size_label'] ?? null,
                'stock_group' => $record['stock_group'] ?? null,
                'source_metadata' => $this->json($record),
                'is_active' => true,
                'deleted_at' => null,
            ]
        );
        $this->upsertExternalKey(
            $companyId,
            $brandId,
            $sourceSystem,
            'product',
            $productId,
            $externalCode,
            $record['legacy_code'] ?? null,
            ['item_kind' => $kind]
        );

        $caseSize = (float) ($record['case_size'] ?? 0);
        if ($caseSize > 0 && strtoupper((string) $record['uom']) !== 'CASE') {
            $this->upsertUuid(
                'product_uom_conversions',
                [
                    'product_id' => $productId,
                    'from_uom_id' => $this->uomId($companyId, 'CASE'),
                    'to_uom_id' => $uomId,
                ],
                [
                    'company_id' => $companyId,
                    'conversion_factor' => $caseSize,
                    'source_key' => $sourceSystem.':'.$externalCode.':case',
                    'is_active' => true,
                ]
            );
        }
        $this->linkSupplier($companyId, $productId, $supplierId, $record);
        $this->upsertProductCosts($companyId, $productId, $record, $currency, $costBranchIds);

        $this->productIds[$externalCode] = $productId;
        $this->productKinds[$externalCode] = $kind;
        $this->productUoms[$externalCode] = strtoupper((string) (($record['uom'] ?? null) ?: 'EACH'));
    }

    /** @return array<int, string> */
    private function costBranchIds(
        string $companyId,
        string $brandId,
        ?string $branchId = null
    ): array {
        $branchId = trim((string) $branchId);
        if ($branchId !== '') {
            if (! DB::table('company_branches')
                ->where('company_id', $companyId)
                ->where('id', $branchId)
                ->exists()) {
                return [];
            }
            if (Schema::hasTable('shops')) {
                $shop = DB::table('shops')
                    ->where('company_id', $companyId)
                    ->where('brand_id', $brandId)
                    ->where('branch_id', $branchId);
                if (Schema::hasColumn('shops', 'deleted_at')) {
                    $shop->whereNull('deleted_at');
                }
                if (! $shop->exists()) {
                    return [];
                }
            }

            return [$branchId];
        }

        if (! Schema::hasTable('shops')) {
            return [];
        }
        $shops = DB::table('shops')
            ->where('company_id', $companyId)
            ->where('brand_id', $brandId)
            ->whereNotNull('branch_id');
        if (Schema::hasColumn('shops', 'deleted_at')) {
            $shops->whereNull('deleted_at');
        }

        return $shops->pluck('branch_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, string> $branchIds
     */
    private function upsertProductCosts(
        string $companyId,
        string $productId,
        array $record,
        string $currency,
        array $branchIds
    ): void {
        if (! Schema::hasTable('product_costs') || $branchIds === []) {
            return;
        }
        $unitCost = $record['unit_cost'] ?? null;
        if ($unitCost === null
            && (float) ($record['case_size'] ?? 0) > 0
            && ($record['case_cost'] ?? null) !== null) {
            $unitCost = (float) ($record['case_cost'] ?? 0) / (float) $record['case_size'];
        }
        if (! is_numeric($unitCost) || (float) $unitCost < 0) {
            return;
        }

        foreach ($branchIds as $branchId) {
            $where = [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
            ];
            $existing = DB::table('product_costs')->where($where)->first();
            $values = $this->onlyColumns('product_costs', [
                'average_cost' => (float) $unitCost,
                'last_cost' => (float) $unitCost,
                'currency_code' => $currency,
                'currency' => $currency,
                'updated_at' => now(),
            ]);
            if ($existing) {
                DB::table('product_costs')->where('id', $existing->id)->update($values);
                continue;
            }
            DB::table('product_costs')->insert($this->onlyColumns('product_costs', [
                'id' => (string) Str::uuid(),
                ...$where,
                ...$values,
                'total_quantity' => 0,
                'total_value' => 0,
                'created_at' => now(),
            ]));
        }
    }

    private function assertReusableProductOwnership(
        string $productId,
        string $brandId,
        string $externalCode
    ): void {
        if (! Schema::hasTable('brand_products')) {
            return;
        }

        $query = DB::table('brand_products')
            ->where('product_id', $productId)
            ->where('brand_id', '!=', $brandId);
        if (Schema::hasColumn('brand_products', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $foreignBrandIds = $query
            ->limit(2)
            ->pluck('brand_id');

        if ($foreignBrandIds->isNotEmpty()) {
            throw new RuntimeException(
                "Product code {$externalCode} already belongs to another brand; use a unique brand product code."
            );
        }
    }

    /** @param array<string, mixed> $catalog */
    private function importMenus(array $catalog, string $companyId, string $brandId, string $currency): int
    {
        $posChannelAliases = 0;

        foreach ($catalog['menu_items'] ?? [] as $menu) {
            $code = strtoupper((string) $menu['external_code']);
            $productId = $this->productIds[$code] ?? null;
            if (! $productId) {
                continue;
            }
            foreach ($menu['channels'] ?? [] as $channel) {
                $channelCode = strtoupper(trim((string) ($channel['code'] ?? '')));
                if ($channelCode === '') {
                    continue;
                }
                $this->upsertUuid(
                    'menu_item_channels',
                    ['brand_id' => $brandId, 'product_id' => $productId, 'channel_code' => $channelCode],
                    [
                        'company_id' => $companyId,
                        'price_exclusive' => $channel['price_exclusive'] ?? null,
                        'price_inclusive' => $channel['price_inclusive'] ?? null,
                        'currency_code' => $currency,
                        'is_available' => $channel['is_available'] ?? true,
                        'starts_at' => $channel['starts_at'] ?? null,
                        'ends_at' => $channel['ends_at'] ?? null,
                        'metadata' => $this->json(['source' => $menu['source'] ?? null]),
                    ]
                );
            }

            $posSource = $this->canonicalPosChannel($menu['channels'] ?? []);
            if ($posSource !== null && $posSource['code'] !== 'POS') {
                $this->upsertUuid(
                    'menu_item_channels',
                    ['brand_id' => $brandId, 'product_id' => $productId, 'channel_code' => 'POS'],
                    [
                        'company_id' => $companyId,
                        'price_exclusive' => $posSource['price_exclusive'] ?? null,
                        'price_inclusive' => $posSource['price_inclusive'] ?? null,
                        'currency_code' => $currency,
                        'is_available' => true,
                        'starts_at' => $posSource['starts_at'] ?? null,
                        'ends_at' => $posSource['ends_at'] ?? null,
                        'metadata' => $this->json([
                            'source' => $menu['source'] ?? null,
                            'canonical_alias' => true,
                            'source_channel_code' => $posSource['code'],
                            'selection_priority' => self::POS_CHANNEL_PRIORITY,
                        ]),
                    ]
                );
                $posChannelAliases++;
            }

            $trading = trim((string) ($menu['trading_days'] ?? '')) ?: 'Daily';
            $this->upsertUuid(
                'menu_item_trading_schedules',
                [
                    'brand_id' => $brandId,
                    'product_id' => $productId,
                    'schedule_code' => $this->scheduleCode($trading),
                ],
                [
                    'company_id' => $companyId,
                    'weekdays' => $this->json($this->weekdays($trading)),
                    'is_active' => true,
                    'source_metadata' => $this->json([
                        'trading_days' => $trading,
                        'source' => $menu['source'] ?? null,
                    ]),
                ]
            );
        }

        return $posChannelAliases;
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     * @return array<string, mixed>|null
     */
    private function canonicalPosChannel(array $channels): ?array
    {
        $available = [];
        foreach ($channels as $channel) {
            $code = strtoupper(trim((string) ($channel['code'] ?? '')));
            if ($code === ''
                || ($channel['is_available'] ?? true) === false
                || (($channel['price_inclusive'] ?? null) === null
                    && ($channel['price_exclusive'] ?? null) === null)) {
                continue;
            }
            $available[$code] = [...$channel, 'code' => $code];
        }

        // A source-native POS row is authoritative and must never be replaced
        // by an alias synthesized from another channel.
        if (isset($available['POS'])) {
            return $available['POS'];
        }

        foreach (self::POS_CHANNEL_PRIORITY as $code) {
            if (isset($available[$code])) {
                return $available[$code];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $catalog */
    private function importModifiers(
        array $catalog,
        string $companyId,
        string $brandId,
        string $currency,
        string $sourceSystem
    ): void {
        foreach ($catalog['modifier_groups'] ?? [] as $group) {
            $code = strtoupper((string) $group['code']);
            $id = $this->upsertUuid(
                'modifier_groups',
                ['brand_id' => $brandId, 'code' => $code],
                [
                    'company_id' => $companyId,
                    'name' => $group['name'],
                    'minimum_selections' => 0,
                    'maximum_selections' => null,
                    'is_required' => false,
                    'is_active' => true,
                    'metadata' => $this->json(['source' => $group['source'] ?? null]),
                    'deleted_at' => null,
                ]
            );
            $this->modifierGroupIds[$code] = $id;
            $this->upsertExternalKey(
                $companyId,
                $brandId,
                $sourceSystem,
                'modifier_group',
                $id,
                $code,
                null,
                ['name' => $group['name']]
            );
        }

        foreach ($catalog['modifier_options'] ?? [] as $option) {
            $groupCode = strtoupper((string) $option['group_code']);
            $optionCode = strtoupper((string) $option['external_code']);
            $groupId = $this->modifierGroupIds[$groupCode] ?? null;
            $productId = $this->productIds[$optionCode] ?? null;
            if (! $groupId || ! $productId) {
                continue;
            }
            $id = $this->upsertUuid(
                'modifier_group_options',
                ['modifier_group_id' => $groupId, 'modifier_product_id' => $productId],
                [
                    'company_id' => $companyId,
                    'brand_id' => $brandId,
                    'external_code' => $optionCode,
                    'sort_order' => (int) ($option['sort_order'] ?? 0),
                    'price_delta' => (float) ($option['price_delta'] ?? 0),
                    'is_default' => false,
                    'is_active' => true,
                    'metadata' => $this->json(['source' => $option['source'] ?? null, 'currency' => $currency]),
                ]
            );
            $this->modifierOptionIds[$optionCode] = $id;
        }

        foreach ($catalog['menu_modifier_groups'] ?? [] as $assignment) {
            $menuId = $this->productIds[strtoupper((string) $assignment['menu_external_code'])] ?? null;
            $groupId = $this->modifierGroupIds[strtoupper((string) $assignment['group_code'])] ?? null;
            if (! $menuId || ! $groupId) {
                continue;
            }
            $this->upsertUuid(
                'menu_item_modifier_groups',
                ['menu_product_id' => $menuId, 'modifier_group_id' => $groupId],
                [
                    'company_id' => $companyId,
                    'brand_id' => $brandId,
                    'sort_order' => (int) ($assignment['sort_order'] ?? 0),
                    'minimum_selections' => null,
                    'maximum_selections' => null,
                    'is_active' => true,
                ]
            );
        }

        foreach ($catalog['menu_modifier_exclusions'] ?? [] as $exclusion) {
            $menuId = $this->productIds[strtoupper((string) $exclusion['menu_external_code'])] ?? null;
            $optionId = $this->modifierOptionIds[
                strtoupper((string) $exclusion['modifier_option_external_code'])
            ] ?? null;
            if (! $menuId || ! $optionId) {
                continue;
            }
            $this->upsertUuid(
                'menu_item_modifier_exclusions',
                ['menu_product_id' => $menuId, 'modifier_option_id' => $optionId],
                [
                    'company_id' => $companyId,
                    'brand_id' => $brandId,
                    'reason' => $exclusion['reason'] ?? null,
                ]
            );
        }

        $this->importPrepMessages($catalog, $companyId, $brandId);
    }

    /** @param array<string, mixed> $catalog */
    private function importPrepMessages(array $catalog, string $companyId, string $brandId): void
    {
        if (! Schema::hasTable('product_prep_messages') || ! Schema::hasTable('product_prep_message_assignments')) {
            return;
        }

        $messagesHaveBrand = Schema::hasColumn('product_prep_messages', 'brand_id');
        $assignmentsHaveBrand = Schema::hasColumn('product_prep_message_assignments', 'brand_id');
        $ids = [];
        foreach ($catalog['prep_messages'] ?? [] as $message) {
            $code = substr(strtoupper((string) $message['code']), 0, 80);
            $lookup = DB::table('product_prep_messages')
                ->where('company_id', $companyId)
                ->whereNull('branch_id')
                ->where('code', $code)
                ->when(Schema::hasColumn('product_prep_messages', 'deleted_at'), fn ($query) => $query->orderByRaw('deleted_at IS NULL DESC'));
            if ($messagesHaveBrand) {
                $lookup->where('brand_id', $brandId);
            }
            $id = $lookup->value('id');
            $values = $this->onlyColumns('product_prep_messages', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
                'branch_id' => null,
                'message' => $message['message'],
                'code' => $code,
                'allow_free_text' => false,
                'is_active' => true,
                'metadata' => $this->json([
                    'source' => $message['source'] ?? null,
                    'modifier_group_code' => $message['group_code'] ?? null,
                ]),
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
            if ($id) {
                DB::table('product_prep_messages')->where('id', $id)->update($values);
            } else {
                $id = (string) Str::uuid();
                DB::table('product_prep_messages')->insert(['id' => $id, ...$values, 'created_at' => now()]);
            }
            $ids[(string) $message['code']] = (string) $id;
        }

        foreach ($catalog['prep_message_assignments'] ?? [] as $assignment) {
            $productId = $this->productIds[strtoupper((string) $assignment['menu_external_code'])] ?? null;
            $messageId = $ids[(string) $assignment['prep_message_code']] ?? null;
            if (! $productId || ! $messageId) {
                continue;
            }
            $assignmentKey = [
                'company_id' => $companyId,
                'branch_id' => null,
                'product_id' => $productId,
                'prep_message_id' => $messageId,
            ];
            if ($assignmentsHaveBrand) {
                $assignmentKey['brand_id'] = $brandId;
            }
            $this->upsertUuid(
                'product_prep_message_assignments',
                $assignmentKey,
                [
                    'is_default' => false,
                    'sort_order' => (int) ($assignment['sort_order'] ?? 0),
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );
        }
    }

    /** @param array<string, mixed> $catalog */
    private function importRecipes(
        array $catalog,
        string $companyId,
        string $brandId,
        string $currency,
        string $sourceSystem,
        ?string $createdBy
    ): void {
        foreach ($catalog['recipes'] ?? [] as $recipe) {
            $code = strtoupper((string) $recipe['external_code']);
            $productId = $this->productIds[$code] ?? null;
            if (! $productId) {
                continue;
            }
            $sourceKey = $sourceSystem.':'.$brandId.':'.$recipe['recipe_type'].':'.$code;
            $recipeId = DB::table('food_recipes')
                ->where('company_id', $companyId)
                ->where('brand_id', $brandId)
                ->where('source_key', $sourceKey)
                ->value('id');
            $isProduction = $recipe['recipe_type'] === 'manufacturing';
            $values = $this->onlyColumns('food_recipes', [
                'company_id' => $companyId,
                'brand_id' => $brandId,
                'menu_product_id' => $isProduction ? null : $productId,
                'produced_product_id' => $isProduction ? $productId : null,
                'name' => $recipe['name'] ?: $code,
                'external_code' => $code,
                'source_key' => $sourceKey,
                'recipe_type' => $recipe['recipe_type'],
                'production_output_type' => $isProduction ? 'semi_finished' : null,
                'version_no' => 1,
                'yield_quantity' => (float) ($recipe['yield_quantity'] ?: 1),
                'yield_uom_id' => $this->uomId($companyId, (string) ($recipe['yield_uom'] ?: 'EACH')),
                'currency' => $currency,
                'source_stock_area' => $isProduction ? 'back' : 'front',
                'destination_stock_area' => $isProduction ? 'back' : null,
                'ingredient_cost' => (float) ($recipe['total_cost'] ?? 0),
                'wastage_cost' => 0,
                'packaging_cost' => 0,
                'labour_cost' => 0,
                'overhead_cost' => 0,
                'notes' => 'Imported from '.$sourceSystem.' hospitality BOM.',
                'total_cost' => (float) ($recipe['total_cost'] ?? 0),
                'cost_per_unit' => (float) ($recipe['cost_per_unit'] ?? 0),
                'last_costed_at' => now(),
                'is_active' => true,
                'updated_by' => $createdBy,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_current' => true,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
            if ($recipeId) {
                DB::table('food_recipes')->where('id', $recipeId)->update($values);
            } else {
                $recipeId = (string) Str::uuid();
                DB::table('food_recipes')->insert([
                    'id' => $recipeId,
                    ...$values,
                    ...$this->onlyColumns('food_recipes', ['created_by' => $createdBy]),
                    'created_at' => now(),
                ]);
            }
            $this->recipeIds[$code] = (string) $recipeId;
            $this->upsertExternalKey(
                $companyId,
                $brandId,
                $sourceSystem,
                'recipe',
                (string) $recipeId,
                $code,
                null,
                ['recipe_type' => $recipe['recipe_type']]
            );
        }

        foreach ($catalog['recipes'] ?? [] as $recipe) {
            $code = strtoupper((string) $recipe['external_code']);
            $recipeId = $this->recipeIds[$code] ?? null;
            if (! $recipeId) {
                continue;
            }
            $safeLines = [];
            foreach ($recipe['ingredients'] ?? [] as $line) {
                $ingredientCode = strtoupper((string) ($line['external_code'] ?? ''));
                $line['uom'] = $this->canonicalRecipeLineUom($code, $ingredientCode, $line);
                $safeLines[] = $line;
            }
            $lines = $this->aggregateRecipeLines($safeLines);
            DB::table('food_recipe_ingredients')->where('food_recipe_id', $recipeId)->delete();
            $sequence = 0;
            foreach ($lines as $line) {
                $ingredientCode = strtoupper((string) $line['external_code']);
                $ingredientId = $this->productIds[$ingredientCode] ?? null;
                if (! $ingredientId) {
                    throw new RuntimeException("Recipe {$code} references missing product {$ingredientCode}.");
                }
                $sequence++;
                DB::table('food_recipe_ingredients')->insert($this->onlyColumns('food_recipe_ingredients', [
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'food_recipe_id' => $recipeId,
                    'child_recipe_id' => $this->recipeIds[$ingredientCode] ?? null,
                    'ingredient_product_id' => $ingredientId,
                    'uom_id' => $this->uomId($companyId, (string) ($line['uom'] ?: 'EACH')),
                    'line_type' => ($this->productKinds[$ingredientCode] ?? null) === 'production_item'
                        ? 'sub_recipe'
                        : 'ingredient',
                    'quantity' => (float) $line['quantity'],
                    'wastage_percent' => 0,
                    'notes' => null,
                    'source_line_key' => $line['source_line_key'] ?? null,
                    'sequence_no' => $sequence,
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'line_total' => $line['line_total'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /** @param array<int, array<string, mixed>> $lines @return array<int, array<string, mixed>> */
    private function aggregateRecipeLines(array $lines): array
    {
        $aggregated = [];
        foreach ($lines as $line) {
            $code = strtoupper((string) ($line['external_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (! isset($aggregated[$code])) {
                $aggregated[$code] = $line;
                continue;
            }
            $aggregated[$code]['quantity'] = (float) $aggregated[$code]['quantity'] + (float) $line['quantity'];
            $aggregated[$code]['line_total'] = (float) ($aggregated[$code]['line_total'] ?? 0)
                + (float) ($line['line_total'] ?? 0);
            $aggregated[$code]['source_line_key'] = implode(',', array_filter([
                $aggregated[$code]['source_line_key'] ?? null,
                $line['source_line_key'] ?? null,
            ]));
        }

        return array_values($aggregated);
    }

    /**
     * Categories belong to one brand: importing a second brand's menu must
     * never reuse (and so share) the first brand's category of the same name.
     */
    private function categoryId(string $companyId, string $brandId, string $name): string
    {
        $brandScoped = Schema::hasColumn('product_categories', 'brand_id');
        $key = $companyId.':'.($brandScoped ? $brandId.':' : '').mb_strtolower(trim($name));
        if (isset($this->categoryIds[$key])) {
            return $this->categoryIds[$key];
        }
        $id = DB::table('product_categories')
            ->where('company_id', $companyId)
            ->when($brandScoped, fn ($query) => $query->where('brand_id', $brandId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when(Schema::hasColumn('product_categories', 'deleted_at'), fn ($query) => $query->orderByRaw('deleted_at IS NULL DESC'))
            ->value('id');
        $values = $this->onlyColumns('product_categories', [
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'parent_id' => null,
            'name' => trim($name),
            'description' => 'Hospitality catalogue category',
            'is_active' => true,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);
        if ($id) {
            DB::table('product_categories')->where('id', $id)->update($values);
        } else {
            $id = (string) Str::uuid();
            $code = 'HOSP_'.strtoupper(substr(hash('sha256', $key), 0, 12));
            DB::table('product_categories')->insert([
                'id' => $id,
                ...$values,
                ...$this->onlyColumns('product_categories', ['code' => $code]),
                'created_at' => now(),
            ]);
        }

        return $this->categoryIds[$key] = (string) $id;
    }

    private function uomId(string $companyId, string $code): string
    {
        $code = strtoupper(trim($code)) ?: 'EACH';
        $cacheKey = $companyId.':'.$code;
        if (isset($this->uomIds[$cacheKey])) {
            return $this->uomIds[$cacheKey];
        }
        $row = DB::table('uoms')
            ->whereRaw('UPPER(code) = ?', [$code])
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->first();
        if ($row) {
            return $this->uomIds[$cacheKey] = (string) $row->id;
        }

        $id = (string) Str::uuid();
        DB::table('uoms')->insert($this->onlyColumns('uoms', [
            'id' => $id,
            'company_id' => $companyId,
            'name' => Str::headline(strtolower($code)),
            'code' => $code,
            'quantity_type' => in_array($code, ['EACH', 'PCS', 'CASE', 'PACK', 'BOX'], true)
                ? 'integer'
                : 'decimal',
            'decimal_places' => in_array($code, ['EACH', 'PCS', 'CASE', 'PACK', 'BOX'], true) ? 0 : 4,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]));

        return $this->uomIds[$cacheKey] = $id;
    }

    private function resetImportState(): void
    {
        $this->productIds = [];
        $this->productKinds = [];
        $this->productUoms = [];
        $this->uomIds = [];
        $this->categoryIds = [];
        $this->modifierGroupIds = [];
        $this->modifierOptionIds = [];
        $this->recipeIds = [];
    }

    /** @param array<string, mixed> $line */
    private function canonicalRecipeLineUom(string $recipeCode, string $ingredientCode, array $line): string
    {
        $sourceUom = strtoupper(trim((string) ($line['uom'] ?? 'EACH')));
        $baseUom = strtoupper(trim((string) ($this->productUoms[$ingredientCode] ?? $sourceUom)));
        $equivalentEach = in_array($sourceUom, ['EACH', 'EA', 'PCS', 'PC'], true)
            && in_array($baseUom, ['EACH', 'EA', 'PCS', 'PC'], true);
        if ($sourceUom === $baseUom || $equivalentEach) {
            return in_array($baseUom, ['EA', 'PC'], true)
                ? ($baseUom === 'EA' ? 'EACH' : 'PCS')
                : $baseUom;
        }

        $source = trim((string) ($line['source_line_key'] ?? 'unknown row'));
        throw new RuntimeException(
            "Recipe {$recipeCode} ingredient {$ingredientCode} uses {$sourceUom}, but its base UOM is {$baseUom} "
            ."at {$source}; configure an explicit conversion before importing this row."
        );
    }

    /** @param array<string, mixed> $record */
    private function supplierId(string $companyId, array $record): ?string
    {
        if (! Schema::hasTable('suppliers')) {
            return null;
        }
        $name = preg_replace('/\s+/u', ' ', trim((string) ($record['supplier'] ?? ''))) ?: '';
        $code = trim((string) ($record['supplier_code'] ?? ''));
        if ($name === '' && $code === '') {
            return null;
        }
        $query = DB::table('suppliers')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('suppliers', 'deleted_at'), fn ($query) => $query->orderByRaw('deleted_at IS NULL DESC'));
        $codeMatch = $code !== ''
            ? (clone $query)->where('code', $code)->first(['id', 'name', 'code'])
            : null;
        $nameMatch = $name !== ''
            ? (clone $query)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first(['id', 'name', 'code'])
            : null;
        $identity = $this->resolveSupplierIdentity($codeMatch, $nameMatch, $name, $code);
        $existing = $identity['existing'];
        $values = $this->onlyColumns('suppliers', [
            'company_id' => $companyId,
            'name' => $identity['name'],
            'code' => $identity['code'],
            'is_active' => true,
            'notes' => 'Imported from hospitality BOM.',
            'deleted_at' => null,
            'updated_at' => now(),
        ]);
        if ($existing) {
            $id = (string) $existing->id;
            DB::table('suppliers')->where('id', $id)->update($values);
        } else {
            $id = (string) Str::uuid();
            DB::table('suppliers')->insert(['id' => $id, ...$values, 'created_at' => now()]);
        }

        return (string) $id;
    }

    /**
     * @return array{existing: object|null, name: string, code: string|null}
     */
    private function resolveSupplierIdentity(
        ?object $codeMatch,
        ?object $nameMatch,
        string $incomingName,
        string $incomingCode
    ): array {
        $existing = $codeMatch ?: $nameMatch;
        $matchedByName = $codeMatch === null && $nameMatch !== null;

        $name = $incomingName !== '' ? $incomingName : ($existing->name ?? $incomingCode);
        $code = $incomingCode !== '' ? $incomingCode : null;

        if ($matchedByName && trim((string) ($existing->code ?? '')) !== '') {
            $code = (string) $existing->code;
        }
        if ($codeMatch && $nameMatch && (string) $codeMatch->id !== (string) $nameMatch->id) {
            $name = (string) $codeMatch->name;
        }

        return [
            'existing' => $existing,
            'name' => $name,
            'code' => $code,
        ];
    }

    /** @param array<string, mixed> $record */
    private function linkSupplier(string $companyId, string $productId, ?string $supplierId, array $record): void
    {
        if (! $supplierId || ! Schema::hasTable('product_suppliers')) {
            return;
        }
        $this->upsertUuid(
            'product_suppliers',
            ['company_id' => $companyId, 'product_id' => $productId, 'supplier_id' => $supplierId],
            [
                'supplier_code' => $record['supplier_code'] ?? null,
                'last_purchase_price' => (float) ($record['unit_cost'] ?? 0),
                'is_default' => true,
            ]
        );
    }

    private function uniqueProductName(
        string $companyId,
        string $name,
        ?string $productId,
        string $externalCode
    ): string {
        $name = Str::limit(trim($name), 255, '');
        $collision = DB::table('products')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('products', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->where('name', $name)
            ->when($productId, fn ($query) => $query->where('id', '!=', $productId))
            ->exists();

        return $collision
            ? Str::limit($name, 225, '').' ['.Str::limit($externalCode, 25, '').']'
            : $name;
    }

    private function mappedEntityId(
        string $companyId,
        string $brandId,
        string $sourceSystem,
        string $entityType,
        string $externalKey
    ): ?string {
        $id = DB::table('hospitality_external_keys')
            ->where('company_id', $companyId)
            ->where('brand_id', $brandId)
            ->where('source_system', $sourceSystem)
            ->where('entity_type', $entityType)
            ->where('external_key', $externalKey)
            ->value('entity_id');

        return $id ? (string) $id : null;
    }

    /** @param array<string, mixed> $metadata */
    private function upsertExternalKey(
        string $companyId,
        string $brandId,
        string $sourceSystem,
        string $entityType,
        string $entityId,
        string $externalKey,
        mixed $legacyCode,
        array $metadata
    ): void {
        $this->upsertUuid(
            'hospitality_external_keys',
            [
                'company_id' => $companyId,
                'brand_id' => $brandId,
                'source_system' => $sourceSystem,
                'entity_type' => $entityType,
                'external_key' => $externalKey,
            ],
            [
                'entity_id' => $entityId,
                'legacy_code' => $legacyCode,
                'metadata' => $this->json($metadata),
            ]
        );
    }

    /** @param array<string, mixed> $where @param array<string, mixed> $values */
    private function upsertUuid(string $table, array $where, array $values): string
    {
        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->orderByRaw('deleted_at IS NULL DESC');
        }
        $id = $query->value('id');
        $values = $this->onlyColumns($table, [...$values, 'updated_at' => now()]);
        if ($id) {
            DB::table($table)->where('id', $id)->update($values);

            return (string) $id;
        }

        $id = (string) Str::uuid();
        DB::table($table)->insert($this->onlyColumns($table, [
            'id' => $id,
            ...$where,
            ...$values,
            'created_at' => now(),
        ]));

        return $id;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function onlyColumns(string $table, array $values): array
    {
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);

        return array_intersect_key($values, array_flip($columns));
    }

    private function bumpConfigurationVersions(string $companyId, string $brandId): void
    {
        if (! Schema::hasTable('shops')) {
            return;
        }
        $shopIds = DB::table('shops')
            ->where('company_id', $companyId)
            ->where('brand_id', $brandId)
            ->pluck('id');
        if ($shopIds->isEmpty()) {
            return;
        }
        if (Schema::hasColumn('shops', 'configuration_version')) {
            DB::table('shops')->whereIn('id', $shopIds)->increment('configuration_version');
        }
        if (Schema::hasTable('pos_tills') && Schema::hasColumn('pos_tills', 'shop_id')
            && Schema::hasColumn('pos_tills', 'configuration_version')) {
            DB::table('pos_tills')->whereIn('shop_id', $shopIds)->increment('configuration_version');
        }
    }

    private function signalAffectedShops(string $companyId, string $brandId): void
    {
        if (! Schema::hasTable('shops')) {
            return;
        }
        DB::table('shops')
            ->where('company_id', $companyId)
            ->where('brand_id', $brandId)
            ->whereNotNull('branch_id')
            ->pluck('branch_id')
            ->each(function ($branchId) use ($companyId): void {
                try {
                    $this->sync->markBranchDirty($companyId, (string) $branchId, 'hospitality_catalog_import');
                } catch (Throwable $exception) {
                    Log::warning('Hospitality import could not signal a branch sync.', [
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    /** @return array<int, int>|null */
    private function weekdays(string $trading): ?array
    {
        $normal = strtolower($trading);
        if (str_contains($normal, 'daily') || str_contains($normal, 'every day')) {
            return [1, 2, 3, 4, 5, 6, 7];
        }
        if (str_contains($normal, 'weekdays') || str_contains($normal, 'monday to friday')) {
            return [1, 2, 3, 4, 5];
        }
        if (str_contains($normal, 'weekend')) {
            return [6, 7];
        }

        return null;
    }

    private function scheduleCode(string $trading): string
    {
        $code = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $trading), '_'));

        return Str::limit($code ?: 'DEFAULT', 80, '');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
