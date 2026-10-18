<?php

namespace Modules\Sales\Http\Controllers\Concerns;

use Modules\Product\Models\Product;
use Modules\Sales\Models\ReceiptLine;
use Modules\Sales\Models\SalesTransaction;

trait FastFoodSalesIntegration
{
    protected function fastFoodSaleValidationRules(): array
    {
        return [
            'products.*.prep_message_ids' => ['nullable', 'array'],
            'products.*.prep_message_ids.*' => ['string', 'max:36'],

            'products.*.prep_messages' => ['nullable', 'array'],
            'products.*.prep_messages.*' => ['nullable'],

            'products.*.prep_note' => ['nullable', 'string', 'max:1000'],
            'products.*.prep_message' => ['nullable', 'string', 'max:1000'],
            'products.*.special_instructions' => ['nullable', 'string', 'max:1000'],

            'products.*.modifier_ids' => ['nullable', 'array'],
            'products.*.modifier_ids.*' => ['string', 'max:36'],

            'products.*.modifiers' => ['nullable', 'array'],
            'products.*.modifiers.*.id' => ['nullable', 'string', 'max:36'],
            'products.*.modifiers.*.modifier_id' => ['nullable', 'string', 'max:36'],
            'products.*.modifiers.*.name' => ['nullable', 'string', 'max:255'],
            'products.*.modifiers.*.quantity' => ['nullable', 'numeric', 'min:1'],
            'products.*.modifiers.*.price' => ['nullable', 'numeric', 'min:0'],
            'products.*.modifiers.*.currency_code' => ['nullable', 'string', 'max:10'],
            'products.*.modifiers.*.currency' => ['nullable', 'string', 'max:10'],
            'products.*.modifiers.*.recipe_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    protected function assignFastFoodOrderAfterSaleCreate(
        SalesTransaction $sale,
        string $companyId,
        string $branchId,
        ?string $tillId = null,
        ?string $tillCode = null
    ): SalesTransaction {
        if (!method_exists($this, 'assignFastFoodOrderNumberToSale')) {
            return $sale;
        }

        return $this->assignFastFoodOrderNumberToSale(
            sale: $sale,
            companyId: $companyId,
            branchId: $branchId,
            tillId: $tillId,
            tillCode: $tillCode
        );
    }

    protected function resolveFastFoodSaleLineCustomisationOrEmpty(
        array $item,
        Product $product,
        string $companyId,
        string $branchId
    ): array {
        if (!method_exists($this, 'resolveFastFoodLineCustomisation')) {
            return [
                'enabled' => false,
                'prep_messages' => [],
                'prep_note' => null,
                'modifiers' => [],
                'missing_support_trait' => 'FastFoodOrderSupport is not loaded on SalesController.',
            ];
        }

        $customisation = $this->resolveFastFoodLineCustomisation(
            item: $item,
            product: $product,
            companyId: $companyId,
            branchId: $branchId
        );

        $customisation['modifiers'] = $this->normaliseFastFoodModifierPrices(
            resolvedModifiers: $customisation['modifiers'] ?? [],
            item: $item
        );

        return $customisation;
    }

    protected function applyFastFoodLineCustomisationToReceiptLine(
        ReceiptLine $receiptLine,
        SalesTransaction $sale,
        array $customisation
    ): void {
        if (!method_exists($this, 'applyFastFoodLineFields')) {
            return;
        }

        $this->applyFastFoodLineFields(
            receiptLine: $receiptLine,
            sale: $sale,
            customisation: $customisation
        );
    }

    protected function deductFastFoodModifierRecipesForLine(
        Product $menuProduct,
        string $branchId,
        float $soldQuantity,
        string $saleId,
        string $receiptLineId,
        ?string $shiftSessionId,
        array $customisation
    ): void {
        if (!method_exists($this, 'deductFastFoodModifierRecipesFromFrontStock')) {
            return;
        }

        $this->deductFastFoodModifierRecipesFromFrontStock(
            menuProduct: $menuProduct,
            branchId: $branchId,
            soldQuantity: $soldQuantity,
            saleId: $saleId,
            receiptLineId: $receiptLineId,
            shiftSessionId: $shiftSessionId,
            modifiers: $customisation['modifiers'] ?? []
        );
    }

    /** @return array<string, float> ingredient product id => negative quantity */
    protected function fastFoodModifierRecipeAdjustmentsForLine(
        Product $menuProduct,
        float $soldQuantity,
        array $customisation
    ): array {
        if (!method_exists($this, 'fastFoodModifierRecipeAdjustments')) {
            return [];
        }

        return $this->fastFoodModifierRecipeAdjustments(
            menuProduct: $menuProduct,
            soldQuantity: $soldQuantity,
            modifiers: $customisation['modifiers'] ?? []
        );
    }

    protected function fastFoodModifierUnitPriceForSale(
        array $customisation,
        string $saleCurrencyCode,
        string $companyId,
        ?string $branchId = null
    ): float {
        $total = 0.0;

        foreach (($customisation['modifiers'] ?? []) as $modifier) {
            if (!is_array($modifier)) {
                continue;
            }

            $price = (float) ($modifier['price'] ?? 0);
            $quantity = max(1, (float) ($modifier['quantity'] ?? 1));
            $currencyCode = $modifier['currency_code']
                ?? $modifier['currency']
                ?? $saleCurrencyCode;

            if ($price <= 0) {
                continue;
            }

            if (
                $currencyCode
                && strtoupper((string) $currencyCode) !== strtoupper($saleCurrencyCode)
                && function_exists('\App\Helpers\currencyConverter')
            ) {
                try {
                    $price = (float) \App\Helpers\currencyConverter(
                        $currencyCode,
                        $saleCurrencyCode,
                        $price,
                        $companyId,
                        $branchId
                    );
                } catch (\Throwable $e) {
                    $price = (float) ($modifier['price'] ?? 0);
                }
            }

            $total += ($price * $quantity);
        }

        return round($total, 4);
    }

    protected function fastFoodModifierBaseUnitPriceForSale(
        array $customisation,
        string $saleCurrencyCode,
        string $baseCurrencyCode,
        string $companyId,
        ?string $branchId = null
    ): float {
        $saleUnitTotal = $this->fastFoodModifierUnitPriceForSale(
            customisation: $customisation,
            saleCurrencyCode: $saleCurrencyCode,
            companyId: $companyId,
            branchId: $branchId
        );

        if (strtoupper($saleCurrencyCode) === strtoupper($baseCurrencyCode)) {
            return round($saleUnitTotal, 4);
        }

        if (!function_exists('\App\Helpers\currencyConverter')) {
            return round($saleUnitTotal, 4);
        }

        try {
            return round((float) \App\Helpers\currencyConverter(
                $saleCurrencyCode,
                $baseCurrencyCode,
                $saleUnitTotal,
                $companyId,
                $branchId
            ), 4);
        } catch (\Throwable $e) {
            return round($saleUnitTotal, 4);
        }
    }

    protected function normaliseFastFoodModifierPrices(array $resolvedModifiers, array $item): array
    {
        $submittedById = [];

        foreach (($item['modifiers'] ?? []) as $submitted) {
            if (!is_array($submitted)) {
                continue;
            }

            $id = $submitted['modifier_id'] ?? $submitted['id'] ?? null;

            if (!$id) {
                continue;
            }

            $submittedById[(string) $id] = $submitted;
        }

        $rows = [];

        foreach ($resolvedModifiers as $modifier) {
            if (!is_array($modifier)) {
                continue;
            }

            $id = (string) ($modifier['id'] ?? $modifier['modifier_id'] ?? '');

            if ($id === '') {
                continue;
            }

            $submitted = $submittedById[$id] ?? [];

            $rows[] = array_merge($modifier, [
                'id' => $modifier['id'] ?? $modifier['modifier_id'] ?? $id,
                'modifier_id' => $modifier['modifier_id'] ?? $modifier['id'] ?? $id,

                'quantity' => max(1, (float) (
                    $modifier['quantity']
                    ?? $submitted['quantity']
                    ?? 1
                )),

                'price' => (float) (
                    $modifier['price']
                    ?? $submitted['price']
                    ?? 0
                ),

                'currency_code' => $modifier['currency_code']
                    ?? $submitted['currency_code']
                        ?? $submitted['currency']
                        ?? null,

                'print_on_kitchen' => filter_var(
                        $modifier['print_on_kitchen']
                        ?? $submitted['print_on_kitchen']
                        ?? true,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ) ?? true,

                'print_on_customer_receipt' => filter_var(
                        $modifier['print_on_customer_receipt']
                        ?? $submitted['print_on_customer_receipt']
                        ?? true,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ) ?? true,

                'deduct_recipe_stock' => filter_var(
                        $modifier['deduct_recipe_stock']
                        ?? $submitted['deduct_recipe_stock']
                        ?? true,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ) ?? true,

                'recipe_id' => $modifier['recipe_id']
                    ?? $submitted['recipe_id']
                        ?? null,
            ]);
        }

        return array_values($rows);
    }

    protected function fastFoodSaleResponseExtras(SalesTransaction $sale): array
    {
        $kitchenPrint = null;

        // Retail sales must never create a kitchen print job or return a
        // kitchen-order payload. The response is consumed by several clients,
        // so enforce the operating mode here as the server-side authority.
        if (
            !method_exists($this, 'fastFoodOrderModeEnabled')
            || !$this->fastFoodOrderModeEnabled(
                (string) $sale->company_id,
                $sale->branch_id ? (string) $sale->branch_id : null
            )
        ) {
            return [
                'order_number' => null,
                'order_sequence' => null,
                'order_business_date' => null,
                'kitchen_print' => null,
            ];
        }

        if (method_exists($this, 'prepareFastFoodPrintPayloads')) {
            $prints = $this->prepareFastFoodPrintPayloads($sale);
            $kitchenPrint = $prints['kitchen'] ?? null;
        }

        return [
            'order_number' => $sale->order_number
                ?? \data_get($sale->metadata, 'fast_food_order.order_number'),

            'order_sequence' => $sale->order_sequence
                ?? \data_get($sale->metadata, 'fast_food_order.order_sequence'),

            'order_business_date' => $sale->order_business_date
                ?? \data_get($sale->metadata, 'fast_food_order.business_date')
                    ?? $sale->business_date
                    ?? null,

            'kitchen_print' => $kitchenPrint,
        ];
    }
}
