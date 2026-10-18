<?php

namespace Modules\Sales\Support;

use App\Services\Hospitality\HospitalityModifierOptionService;
use App\Services\Inventory\InventoryAllocationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\FoodManufacturing\Models\FoodRecipe;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;
use Modules\Sales\Models\HospitalityTableOrder;
use Modules\Sales\Models\KitchenPrintJob;
use Modules\Sales\Models\ProductModifier;
use Modules\Sales\Models\ProductModifierAssignment;
use Modules\Sales\Models\ProductPrepMessage;
use Modules\Sales\Models\ProductPrepMessageAssignment;
use Modules\Sales\Models\ReceiptLine;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Services\HospitalityOrderNumberService;
use Modules\Sales\Services\HospitalityOutputConfigurationService;
use Modules\Sales\Services\KitchenOrderPrintBuilder;

trait FastFoodOrderSupport
{
    private function fastFoodOrderModeEnabled(string $companyId, ?string $branchId): bool
    {
        if (! property_exists($this, 'companySettingService') || ! $this->companySettingService) {
            return false;
        }

        return $this->companySettingService->isHospitalityMode($companyId, $branchId);
    }

    private function fastFoodOpenTradingDay(?string $companyId, ?string $branchId): ?object
    {
        if (! $companyId || ! $branchId || ! Schema::hasTable('trading_days')) {
            return null;
        }

        return DB::table('trading_days')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['open', 'day_end_running'])
            ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
            ->orderByDesc('trading_date')
            ->first();
    }

    private function fastFoodBusinessDate(?string $companyId, ?string $branchId): string
    {
        $tradingDay = $this->fastFoodOpenTradingDay($companyId, $branchId);

        if ($tradingDay) {
            $raw = $tradingDay->business_date ?? $tradingDay->trading_date ?? null;

            if ($raw) {
                return Carbon::parse($raw)->toDateString();
            }
        }

        return now()->toDateString();
    }

    private function fastFoodTradingDayId(?string $companyId, ?string $branchId): ?string
    {
        return $this->fastFoodOpenTradingDay($companyId, $branchId)?->id;
    }

    private function generateFastFoodOrderNumber(
        string $companyId,
        string $branchId,
        ?string $tillId,
        ?string $tillCode = null
    ): array {
        $businessDate = $this->fastFoodBusinessDate($companyId, $branchId);
        $tradingDayId = $this->fastFoodTradingDayId($companyId, $branchId);

        /*
         * The service owns the branch-wide, trading-day sequence. It persists
         * a random daily starting point and serialises every allocation on the
         * same MySQL row, so multiple tills cannot issue duplicate tickets.
         */
        return app(HospitalityOrderNumberService::class)->allocate(
            companyId: $companyId,
            branchId: $branchId,
            businessDate: $businessDate,
            tradingDayId: $tradingDayId
        );
    }

    private function assignFastFoodOrderNumberToSale(SalesTransaction $sale, string $companyId, string $branchId, ?string $tillId, ?string $tillCode = null): SalesTransaction
    {
        if (! $this->fastFoodOrderModeEnabled($companyId, $branchId)) {
            return $sale;
        }

        $order = $this->hospitalityTableOrderNumberForSale($sale, $companyId, $branchId)
            ?? $this->generateFastFoodOrderNumber($companyId, $branchId, $tillId, $tillCode);
        $updates = [];

        foreach ([
            'order_number' => $order['order_number'],
            'order_sequence' => $order['order_sequence'],
            'order_business_date' => $order['business_date'],
            'business_date' => $order['business_date'],
            'trading_day_id' => $order['trading_day_id'],
            'kitchen_print_status' => 'pending',
        ] as $column => $value) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $updates[$column] = $value;
            }
        }

        if (! empty($updates)) {
            $sale->update($updates);
        }

        $this->mergeFastFoodSaleMetadata($sale->fresh() ?: $sale, [
            'fast_food_order' => $order,
            'order_number' => $order['order_number'],
            'order_sequence' => $order['order_sequence'],
            'order_business_date' => $order['business_date'],
            'kitchen_print_status' => 'pending',
        ]);

        return $sale->fresh() ?: $sale;
    }

    /**
     * Reuse the public number already shown on a pre-payment kitchen ticket.
     * If a table order reaches checkout without having been sent to kitchen,
     * allocate its number here once and persist it for the completion event.
     */
    private function hospitalityTableOrderNumberForSale(
        SalesTransaction $sale,
        string $companyId,
        string $branchId
    ): ?array {
        $tableOrderId = data_get($sale->metadata, 'hospitality_table_order_id');
        if (! $tableOrderId || ! Schema::hasTable('hospitality_table_orders')) {
            return null;
        }

        /** @var HospitalityTableOrder|null $tableOrder */
        $tableOrder = HospitalityTableOrder::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereKey($tableOrderId)
            ->lockForUpdate()
            ->first();
        if (! $tableOrder) {
            return null;
        }

        if (! $tableOrder->order_number) {
            $allocation = $this->generateFastFoodOrderNumber(
                $companyId,
                $branchId,
                $sale->till_id,
                data_get($sale->metadata, 'till_code')
            );
            $tableOrder->forceFill([
                'order_number' => $allocation['order_number'],
                'order_sequence' => $allocation['order_sequence'],
                'order_business_date' => $allocation['business_date'],
                'trading_day_id' => $allocation['trading_day_id'],
            ])->save();

            return $allocation;
        }

        $businessDate = $tableOrder->order_business_date;
        if ($businessDate instanceof \DateTimeInterface) {
            $businessDate = $businessDate->format('Y-m-d');
        }

        return [
            'enabled' => true,
            'order_number' => (string) $tableOrder->order_number,
            'order_sequence' => (int) $tableOrder->order_sequence,
            'business_date' => (string) ($businessDate ?: $this->fastFoodBusinessDate($companyId, $branchId)),
            'trading_day_id' => $tableOrder->trading_day_id,
            'prefix' => strtok((string) $tableOrder->order_number, '-') ?: 'ORD',
        ];
    }

    private function mergeFastFoodSaleMetadata(SalesTransaction $sale, array $metadata): void
    {
        if (! Schema::hasColumn('sales_transactions', 'metadata')) {
            return;
        }

        $current = $sale->metadata;

        if (! is_array($current)) {
            $current = json_decode($current ?? '[]', true) ?: [];
        }

        $sale->metadata = array_replace_recursive($current, $metadata);
        $sale->save();
    }

    private function resolveFastFoodLineCustomisation(array $item, Product $product, string $companyId, ?string $branchId): array
    {
        $prepMessageIds = collect($item['prep_message_ids'] ?? $item['prep_messages'] ?? [])
            ->map(fn ($value) => is_array($value) ? ($value['id'] ?? $value['prep_message_id'] ?? null) : $value)
            ->filter()
            ->unique()
            ->values();

        $prepMessages = [];

        if ($prepMessageIds->isNotEmpty()) {
            $allowedPrepIds = ProductPrepMessageAssignment::query()
                ->where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id');
                    if ($branchId) {
                        $query->orWhere('branch_id', $branchId);
                    }
                })
                ->pluck('prep_message_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $prepMessages = ProductPrepMessage::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $prepMessageIds)
                ->where('is_active', true)
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id');
                    if ($branchId) {
                        $query->orWhere('branch_id', $branchId);
                    }
                })
                ->get()
                ->filter(fn ($row) => in_array((string) $row->id, $allowedPrepIds, true))
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'message' => $row->message,
                    'code' => $row->code,
                ])
                ->values()
                ->all();
        }

        $freeText = trim((string) ($item['prep_note'] ?? $item['prep_message'] ?? $item['special_instructions'] ?? ''));

        $modifierRows = collect($item['modifier_ids'] ?? $item['modifiers'] ?? [])
            ->map(function ($value) {
                if (is_array($value)) {
                    return [
                        'id' => $value['id'] ?? $value['modifier_id'] ?? null,
                        'quantity' => max((float) ($value['quantity'] ?? 1), 1),
                    ];
                }

                return ['id' => $value, 'quantity' => 1];
            })
            ->filter(fn ($row) => ! empty($row['id']))
            ->values();

        $modifiers = [];

        if ($modifierRows->isNotEmpty()) {
            $modifierIds = $modifierRows->pluck('id')->map(fn ($id) => (string) $id)->unique()->values();

            $allowedModifierIds = ProductModifierAssignment::query()
                ->where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id');
                    if ($branchId) {
                        $query->orWhere('branch_id', $branchId);
                    }
                })
                ->pluck('modifier_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $modifierModels = ProductModifier::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $modifierIds)
                ->where('is_active', true)
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id');
                    if ($branchId) {
                        $query->orWhere('branch_id', $branchId);
                    }
                })
                ->get()
                ->filter(fn ($modifier) => in_array((string) $modifier->id, $allowedModifierIds, true))
                ->keyBy(fn ($modifier) => (string) $modifier->id);

            // Brand catalogue (imported BOM) options are validated against the
            // menu item's assigned groups and exclusions; price and recipe come
            // from the server, never from the submitted payload.
            $catalogueOptions = app(HospitalityModifierOptionService::class)->resolveForMenuItem(
                $companyId,
                $branchId,
                (string) $product->id,
                $modifierIds->reject(fn ($id) => $modifierModels->has((string) $id))->values()->all()
            );

            foreach ($modifierRows as $row) {
                $modifier = $modifierModels->get((string) $row['id']);
                if (! $modifier) {
                    $option = $catalogueOptions->get((string) $row['id']);
                    if ($option) {
                        $modifiers[] = [
                            'id' => $option['id'],
                            'name' => $option['name'],
                            'code' => $option['code'],
                            'quantity' => (float) $row['quantity'],
                            'price' => (float) $option['price'],
                            'currency_code' => $option['currency_code'],
                            'modifier_product_id' => $option['modifier_product_id'],
                            'recipe_id' => null,
                            'deduct_recipe_stock' => true,
                            'print_on_kitchen' => true,
                            'print_on_customer_receipt' => true,
                            'source' => 'modifier_group_option',
                            'group_name' => $option['group_name'],
                        ];
                    }

                    continue;
                }

                $modifiers[] = [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'code' => $modifier->code,
                    'quantity' => (float) $row['quantity'],
                    'price' => (float) $modifier->price,
                    'currency_code' => $modifier->currency_code,
                    'modifier_product_id' => $modifier->modifier_product_id,
                    'recipe_id' => $modifier->recipe_id,
                    'deduct_recipe_stock' => (bool) $modifier->deduct_recipe_stock,
                    'print_on_kitchen' => (bool) $modifier->print_on_kitchen,
                    'print_on_customer_receipt' => (bool) $modifier->print_on_customer_receipt,
                ];
            }
        }

        return [
            'prep_messages' => $prepMessages,
            'prep_note' => $freeText,
            'modifiers' => $modifiers,
        ];
    }

    private function applyFastFoodLineFields(ReceiptLine $receiptLine, SalesTransaction $sale, array $customisation): void
    {
        $metadata = $receiptLine->metadata;

        if (! is_array($metadata)) {
            $metadata = json_decode($metadata ?? '[]', true) ?: [];
        }

        $orderNumber = $sale->order_number ?: data_get($sale->metadata, 'fast_food_order.order_number');

        $metadata['fast_food_order'] = [
            'order_number' => $orderNumber,
            'order_sequence' => $sale->order_sequence ?: data_get($sale->metadata, 'fast_food_order.order_sequence'),
            'business_date' => $sale->order_business_date ?: data_get($sale->metadata, 'fast_food_order.business_date'),
        ];
        $metadata['kitchen'] = [
            'print_status' => 'pending',
            'prep_messages' => $customisation['prep_messages'] ?? [],
            'prep_note' => $customisation['prep_note'] ?? null,
            'modifiers' => $customisation['modifiers'] ?? [],
        ];
        $metadata['prep_messages'] = $customisation['prep_messages'] ?? [];
        $metadata['prep_note'] = $customisation['prep_note'] ?? null;
        $metadata['modifiers'] = $customisation['modifiers'] ?? [];

        $updates = ['metadata' => $metadata];

        if (Schema::hasColumn('receipt_lines', 'order_number')) {
            $updates['order_number'] = $orderNumber;
        }
        if (Schema::hasColumn('receipt_lines', 'kitchen_print_status')) {
            $updates['kitchen_print_status'] = 'pending';
        }

        $receiptLine->update($updates);
    }

    private function deductFastFoodModifierRecipesFromFrontStock(
        Product $menuProduct,
        string $branchId,
        float $soldQuantity,
        string $saleId,
        ?string $receiptLineId,
        ?string $shiftSessionId,
        array $modifiers
    ): void {
        foreach ($modifiers as $modifier) {
            if (($modifier['deduct_recipe_stock'] ?? true) !== true) {
                continue;
            }

            $modifierQty = max((float) ($modifier['quantity'] ?? 1), 1);
            $recipe = $this->resolveFastFoodModifierRecipe($menuProduct, $modifier);

            if (! $recipe || ($recipe->ingredients ?? collect())->isEmpty()) {
                continue;
            }

            $this->deductRecipeObjectFromFrontStock(
                recipe: $recipe,
                branchId: $branchId,
                multiplier: $soldQuantity * $modifierQty,
                saleId: $saleId,
                receiptLineId: $receiptLineId,
                shiftSessionId: $shiftSessionId,
                movementReason: 'sale_modifier_recipe_front_stock_consumption',
                notes: 'Modifier recipe consumed from FRONT stock: '.($modifier['name'] ?? 'Modifier'),
                allowNegativeFrontStock: (bool) $menuProduct->allow_negative_stock,
                sourceMetadata: [
                    'menu_product_id' => $menuProduct->id,
                    'menu_product_name' => $menuProduct->name,
                    'modifier' => $modifier,
                ]
            );
        }
    }

    private function resolveFastFoodModifierRecipe(Product $menuProduct, array $modifier): ?FoodRecipe
    {
        $recipe = null;

        if (! empty($modifier['recipe_id'])) {
            $recipe = FoodRecipe::query()
                ->where('company_id', $menuProduct->company_id)
                ->where('id', $modifier['recipe_id'])
                ->where('is_active', true)
                ->with(['ingredients.ingredientProduct'])
                ->first();
        }

        if (! $recipe && ! empty($modifier['modifier_product_id'])) {
            $recipe = FoodRecipe::query()
                ->where('company_id', $menuProduct->company_id)
                ->where('menu_product_id', $modifier['modifier_product_id'])
                ->where('is_active', true)
                ->with(['ingredients.ingredientProduct'])
                ->orderByDesc('version_no')
                ->orderByDesc('created_at')
                ->first();
        }

        return $recipe;
    }

    /**
     * Ingredient reductions requested by the line's modifiers. Exclusion and
     * substitution options ("No Mushrooms", "Deep Pan Dough") carry negative
     * recipe lines; they reduce the menu item's own recipe consumption rather
     * than posting a separate stock return.
     *
     * @return array<string, float> ingredient product id => negative quantity
     */
    private function fastFoodModifierRecipeAdjustments(
        Product $menuProduct,
        float $soldQuantity,
        array $modifiers
    ): array {
        $adjustments = [];

        foreach ($modifiers as $modifier) {
            if (($modifier['deduct_recipe_stock'] ?? true) !== true) {
                continue;
            }

            $recipe = $this->resolveFastFoodModifierRecipe($menuProduct, $modifier);
            if (! $recipe) {
                continue;
            }

            $modifierQty = max((float) ($modifier['quantity'] ?? 1), 1);
            $yieldQuantity = max((float) ($recipe->yield_quantity ?? 1), 0.0001);

            foreach (($recipe->ingredients ?? collect()) as $line) {
                $quantity = (float) ($line->quantity ?? 0);
                if ($quantity >= 0 || empty($line->ingredient_product_id)) {
                    continue;
                }

                $wastagePercent = (float) ($line->wastage_percent ?? 0);
                $gross = $quantity + ($quantity * $wastagePercent / 100);
                $productId = (string) $line->ingredient_product_id;
                $adjustments[$productId] = ($adjustments[$productId] ?? 0.0)
                    + ($gross / $yieldQuantity) * $soldQuantity * $modifierQty;
            }
        }

        return $adjustments;
    }

    private function deductRecipeObjectFromFrontStock(
        FoodRecipe $recipe,
        string $branchId,
        float $multiplier,
        string $saleId,
        ?string $receiptLineId,
        ?string $shiftSessionId,
        string $movementReason,
        string $notes,
        bool $allowNegativeFrontStock = false,
        array $sourceMetadata = []
    ): void {
        $yieldQuantity = max((float) ($recipe->yield_quantity ?? 1), 0.0001);
        $till = null;

        try {
            $till = app('till');
        } catch (\Throwable $e) {
        }

        foreach (($recipe->ingredients ?? collect()) as $line) {
            $ingredient = $line->ingredientProduct ?? null;

            if (! $ingredient) {
                throw new \Exception('Recipe ingredient product not found for recipe '.($recipe->name ?? $recipe->id));
            }

            $recipeNetQty = (float) ($line->quantity ?? 0);
            $wastagePercent = (float) ($line->wastage_percent ?? 0);
            $grossQtyPerYield = $recipeNetQty + ($recipeNetQty * $wastagePercent / 100);
            $requiredQty = ($grossQtyPerYield / $yieldQuantity) * $multiplier;

            if ($requiredQty <= 0) {
                continue;
            }

            $stock = ProductStock::query()
                ->where('company_id', $recipe->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredient->id)
                ->where('stock_area', 'front')
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = ProductStock::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $recipe->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $ingredient->id,
                    'stock_area' => 'front',
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]);

                $stock = ProductStock::query()->where('id', $stock->id)->lockForUpdate()->first();
            }

            if (! $stock) {
                throw new \Exception('FRONT stock record could not be created for ingredient '.$ingredient->name.'.');
            }

            if (! $allowNegativeFrontStock && (float) $stock->available_quantity < $requiredQty) {
                throw new \Exception('Insufficient FRONT stock for ingredient '.$ingredient->name.'. Required: '.$requiredQty.', Available: '.$stock->available_quantity.'. Enable negative FRONT-stock recipe consumption on the menu item only when deferred BOH issue is authorised.');
            }

            $cost = ProductCost::query()
                ->where('company_id', $recipe->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            $unitCost = (float) ($cost?->average_cost ?: $cost?->last_cost ?: 0);
            $currency = $cost?->currency_code ?? $cost?->currency ?? null;
            $totalCost = round($unitCost * $requiredQty, 4);

            if ($cost) {
                $oldQty = (float) $cost->total_quantity;
                $oldValue = (float) $cost->total_value;
                $newQty = max(0, $oldQty - $requiredQty);
                $newValue = max(0, $oldValue - $totalCost);
                $cost->total_quantity = $newQty;
                $cost->total_value = $newValue;
                $cost->average_cost = $newQty > 0 ? $newValue / $newQty : 0;
                $cost->save();
            }

            $beforeQty = (float) $stock->quantity_on_hand;
            $beforeAvailable = (float) $stock->available_quantity;
            $beforeReserved = (float) $stock->reserved_quantity;
            $deferredFrontStockIssue = $allowNegativeFrontStock
                && $beforeAvailable < $requiredQty;
            $negativeQuantityCreated = $deferredFrontStockIssue
                ? max(0, $requiredQty - max(0, $beforeAvailable))
                : 0;

            $stock->quantity_on_hand = $beforeQty - $requiredQty;
            $stock->available_quantity = $beforeAvailable - $requiredQty;
            $stock->save();

            $allocatedByBatch = $deferredFrontStockIssue ? false : app(InventoryAllocationService::class)->allocateSale([
                'company_id' => $recipe->company_id, 'branch_id' => $branchId, 'product_id' => $ingredient->id,
                'quantity' => $requiredQty, 'sale_id' => $saleId, 'receipt_line_id' => $receiptLineId,
                'till_id' => $till?->id, 'shift_session_id' => $shiftSessionId, 'balance_after' => (float) $stock->quantity_on_hand,
                'unit_cost' => $unitCost, 'currency' => $currency, 'user_id' => auth()->id(),
                'stock_area' => 'front', 'reference_type' => 'sale_modifier_recipe',
                'movement_notes' => $notes,
                'movement_metadata' => array_merge($sourceMetadata, [
                    'source' => 'sales',
                    'movement_reason' => $movementReason,
                    'sale_id' => $saleId,
                    'receipt_line_id' => $receiptLineId,
                    'recipe_id' => $recipe->id,
                    'recipe_version_no' => $recipe->version_no,
                    'recipe_name' => $recipe->name ?? null,
                    'menu_allows_negative_front_stock' => $allowNegativeFrontStock,
                    'deferred_front_stock_issue' => $deferredFrontStockIssue,
                    'negative_quantity_created' => round($negativeQuantityCreated, 4),
                    'ingredient_product_id' => $ingredient->id,
                    'ingredient_product_name' => $ingredient->name,
                    'recipe_line_id' => $line->id ?? null,
                    'required_quantity' => round($requiredQty, 4),
                ]),
            ]);

            if (! $allocatedByBatch) {
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $recipe->company_id,
                    'branch_id' => $branchId,
                    'till_id' => $till?->id,
                    'shift_session_id' => $shiftSessionId,
                    'product_id' => $ingredient->id,
                    'stock_area' => 'front',
                    'movement_type' => 'sale',
                    'quantity_in' => 0,
                    'quantity_out' => round($requiredQty, 4),
                    'balance_after' => round((float) $stock->quantity_on_hand, 4),
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($totalCost, 4),
                    'currency' => $currency,
                    'currency_code' => $currency,
                    'reference_type' => 'sale_modifier_recipe',
                    'reference_id' => $saleId,
                    'notes' => $notes,
                    'metadata' => json_encode(array_merge($sourceMetadata, [
                        'source' => 'sales',
                        'movement_reason' => $movementReason,
                        'sale_id' => $saleId,
                        'receipt_line_id' => $receiptLineId,
                        'recipe_id' => $recipe->id,
                        'recipe_version_no' => $recipe->version_no,
                        'recipe_name' => $recipe->name ?? null,
                        'menu_allows_negative_front_stock' => $allowNegativeFrontStock,
                        'deferred_front_stock_issue' => $deferredFrontStockIssue,
                        'negative_quantity_created' => round($negativeQuantityCreated, 4),
                        'ingredient_product_id' => $ingredient->id,
                        'ingredient_product_name' => $ingredient->name,
                        'required_quantity' => round($requiredQty, 4),
                        'before' => [
                            'quantity_on_hand' => round($beforeQty, 4),
                            'available_quantity' => round($beforeAvailable, 4),
                            'reserved_quantity' => round($beforeReserved, 4),
                        ],
                        'after' => [
                            'quantity_on_hand' => round((float) $stock->quantity_on_hand, 4),
                            'available_quantity' => round((float) $stock->available_quantity, 4),
                            'reserved_quantity' => round((float) $stock->reserved_quantity, 4),
                        ],
                    ])),
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function prepareFastFoodPrintPayloads(SalesTransaction $sale): array
    {
        $builder = app(KitchenOrderPrintBuilder::class);
        $outputConfiguration = app(HospitalityOutputConfigurationService::class);

        $saleForPrint = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
            'receiptLines.product',
        ])->where('id', $sale->id)->first() ?: $sale;

        if ($prePayment = $this->prePaymentKitchenDispatch(
            $saleForPrint,
            $builder,
            $outputConfiguration
        )) {
            return ['kitchen' => $prePayment];
        }

        $kitchenPayload = $builder->buildKitchenPayload($saleForPrint);
        $runtime = $saleForPrint->till
            ? $outputConfiguration->forTill($saleForPrint->till)
            : [
                'mode' => HospitalityOutputConfigurationService::MODE_KITCHEN_PRINTER,
                'auto_print' => false,
                'copies' => 1,
                'dispatch_on' => 'order_submit',
                'capabilities' => [
                    'kitchen_printer' => ['available' => true, 'transport' => 'qz'],
                    'kds' => ['available' => false, 'status' => 'planned'],
                ],
                'printer' => $builder->resolvePrinter($saleForPrint, 'kitchen'),
                'qz' => ['certificate_configured' => false, 'transport' => 'qz'],
                'ready' => false,
            ];
        $kitchenPrinter = $runtime['printer'] ?? null;
        $kitchenHtml = method_exists($builder, 'renderKitchenHtml')
            ? $builder->renderKitchenHtml($kitchenPayload, [
                'paper_size' => $kitchenPrinter['paper_size'] ?? '80mm',
            ])
            : null;
        $kitchenRawText = method_exists($builder, 'renderKitchenText')
            ? $builder->renderKitchenText($kitchenPayload)
            : null;

        $job = DB::transaction(function () use (
            $saleForPrint,
            $kitchenPrinter,
            $kitchenPayload,
            $runtime
        ): KitchenPrintJob {
            // Lock the immutable sale row as the stable serialization point.
            // This makes enqueue idempotent even on an upgraded database which
            // did not receive the historical sale/target unique index.
            SalesTransaction::query()
                ->where('company_id', $saleForPrint->company_id)
                ->where('branch_id', $saleForPrint->branch_id)
                ->whereKey($saleForPrint->id)
                ->lockForUpdate()
                ->firstOrFail();

            $job = KitchenPrintJob::query()
                ->where('company_id', $saleForPrint->company_id)
                ->where('branch_id', $saleForPrint->branch_id)
                ->where('till_id', $saleForPrint->till_id)
                ->where('sales_transaction_id', $saleForPrint->id)
                ->where('target_printing', 'kitchen')
                ->lockForUpdate()
                ->first();

            $created = ! $job;
            if (! $job) {
                $job = new KitchenPrintJob;
                $job->id = (string) Str::uuid();
                $job->company_id = $saleForPrint->company_id;
                $job->branch_id = $saleForPrint->branch_id;
                $job->till_id = $saleForPrint->till_id;
                $job->sales_transaction_id = $saleForPrint->id;
                $job->target_printing = 'kitchen';
                $job->attempt_count = 0;
                $job->metadata = ['created_by' => auth()->id()];
            }

            $nextStatus = (bool) ($runtime['ready'] ?? false)
                ? 'pending'
                : 'pending_configuration';
            if (! $created && in_array($job->status, ['printed', 'failed'], true)) {
                // Re-reading or retrying a sale response must not silently
                // print an acknowledged ticket again or erase a failure.
                $nextStatus = $job->status;
            }

            $job->order_number = $saleForPrint->order_number
                ?: data_get($saleForPrint->metadata, 'fast_food_order.order_number');
            $job->printer_id = $kitchenPrinter['id'] ?? null;
            $job->printer_name = $kitchenPrinter['printer_name'] ?? null;
            $job->status = $nextStatus;
            $job->payload = $kitchenPayload;
            $dispatchKey = hash(
                'sha256',
                implode('|', [
                    'sales_transaction',
                    $saleForPrint->company_id,
                    $saleForPrint->branch_id,
                    $saleForPrint->till_id,
                    $saleForPrint->id,
                    'kitchen',
                ])
            );
            if (Schema::hasColumn('kitchen_print_jobs', 'dispatch_key')) {
                $job->dispatch_key = $dispatchKey;
                $job->dispatch_revision = 1;
                $job->ticket_kind = 'new_order';
            }
            if (Schema::hasColumn('kitchen_print_jobs', 'idempotency_key')) {
                $job->idempotency_key = $dispatchKey;
            }
            $job->save();

            if (Schema::hasColumn('sales_transactions', 'kitchen_print_status')) {
                SalesTransaction::query()
                    ->whereKey($saleForPrint->id)
                    ->update(['kitchen_print_status' => $job->status]);
            }

            return $job->fresh() ?: $job;
        }, 3);

        return [
            'kitchen' => [
                'enabled' => true,
                'mode' => $runtime['mode'],
                'auto_print' => (bool) ($runtime['ready'] ?? false)
                    && $job->status === 'pending',
                'ready' => (bool) ($runtime['ready'] ?? false),
                'copies' => (int) ($runtime['copies'] ?? 1),
                'dispatch_on' => 'order_submit',
                'target_printing' => 'kitchen',
                'capabilities' => $runtime['capabilities'] ?? [],
                'printer' => $kitchenPrinter,
                'qz' => $runtime['qz'] ?? [
                    'certificate_configured' => false,
                    'transport' => 'qz',
                ],
                'job' => [
                    'id' => $job->id,
                    'status' => $job->status,
                    'attempt_count' => (int) ($job->attempt_count ?? 0),
                ],
                'payload' => $kitchenPayload,
                'html' => $kitchenHtml,
                'raw_text' => $kitchenRawText,
            ],
        ];
    }

    private function prePaymentKitchenDispatch(
        SalesTransaction $sale,
        KitchenOrderPrintBuilder $builder,
        HospitalityOutputConfigurationService $outputConfiguration
    ): ?array {
        $tableOrderId = data_get($sale->metadata, 'hospitality_table_order_id');
        if (! $tableOrderId
            || ! $sale->till
            || ! Schema::hasTable('hospitality_table_orders')
            || ! Schema::hasColumn('kitchen_print_jobs', 'hospitality_table_order_id')) {
            return null;
        }

        $tableOrder = HospitalityTableOrder::query()
            ->where('company_id', $sale->company_id)
            ->where('branch_id', $sale->branch_id)
            ->whereKey($tableOrderId)
            ->first();
        if (! $tableOrder || ! $tableOrder->last_kitchen_content_revision) {
            return null;
        }

        $job = KitchenPrintJob::query()
            ->where('company_id', $sale->company_id)
            ->where('branch_id', $sale->branch_id)
            ->where('hospitality_table_order_id', $tableOrder->id)
            ->where('dispatch_revision', $tableOrder->last_kitchen_content_revision)
            ->where('target_printing', 'kitchen')
            ->first();
        if (! $job) {
            return null;
        }

        if (! $job->sales_transaction_id) {
            $job->sales_transaction_id = $sale->id;
            $job->save();
        }

        if (Schema::hasColumn('sales_transactions', 'kitchen_print_status')) {
            $sale->update(['kitchen_print_status' => $job->status]);
        }

        $runtime = $outputConfiguration->forTill($sale->till);
        $printer = $runtime['printer'] ?? null;
        $payload = is_array($job->payload) ? $job->payload : [];

        return [
            'enabled' => true,
            'mode' => $runtime['mode'],
            'auto_print' => (bool) ($runtime['ready'] ?? false) && $job->status === 'pending',
            'ready' => (bool) ($runtime['ready'] ?? false),
            'copies' => (int) ($runtime['copies'] ?? 1),
            'dispatch_on' => 'order_submit',
            'target_printing' => 'kitchen',
            'capabilities' => $runtime['capabilities'] ?? [],
            'printer' => $printer,
            'qz' => $runtime['qz'] ?? [
                'certificate_configured' => false,
                'transport' => 'qz',
            ],
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
                'attempt_count' => (int) ($job->attempt_count ?? 0),
                'source' => 'hospitality_table_order',
                'source_id' => $tableOrder->id,
                'dispatch_revision' => (int) $job->dispatch_revision,
                'ticket_kind' => $job->ticket_kind,
                'idempotent_replay' => true,
            ],
            'already_dispatched_before_payment' => true,
            'payload' => $payload,
            'html' => $builder->renderKitchenHtml($payload, [
                'paper_size' => $printer['paper_size'] ?? '80mm',
            ]),
            'raw_text' => $builder->renderKitchenText($payload),
        ];
    }
}
