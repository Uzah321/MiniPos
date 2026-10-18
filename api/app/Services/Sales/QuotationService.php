<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationItem;

class QuotationService
{
    public function quoteCode(string $companyId, ?string $branchCode = null): string
    {
        $prefix = 'QT';
        if ($branchCode) {
            $prefix .= '-' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $branchCode));
        }

        $date = now()->format('ymd');
        $count = Quotation::query()
                ->where('company_id', $companyId)
                ->whereDate('created_at', now()->toDateString())
                ->count() + 1;

        return $prefix . '-' . $date . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(array $items): array
    {
        $subTotal = 0;
        $discountTotal = 0;
        $taxTotal = 0;
        $inclusiveTaxTotal = 0;
        $exclusiveTaxTotal = 0;
        $subtotalExclTax = 0;
        $grandTotal = 0;
        $calculatedItems = [];

        foreach ($items as $item) {
            $quantity = max((float) ($item['quantity'] ?? 1), 0);
            $unitPrice = round((float) ($item['unit_price'] ?? $item['price'] ?? 0), 4);
            $gross = round($quantity * $unitPrice, 4);
            $discount = round((float) ($item['discount_amount'] ?? data_get($item, 'discount_payload.discount_amount', 0)), 4);
            $discount = min(max($discount, 0), $gross);

            $taxCalc = $this->calculateTaxForQuotationItem($item, $gross, $discount, $quantity);
            $net = round($taxCalc['line_total'], 4);

            $calculatedItems[] = [
                ...$item,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'taxable' => $taxCalc['taxable'],
                'tax_rate' => $taxCalc['tax_rate'],
                'tax_amount' => $taxCalc['tax_amount'],
                'tax_total' => $taxCalc['tax_amount'],
                'inclusive_tax_total' => $taxCalc['inclusive_tax_total'],
                'exclusive_tax_total' => $taxCalc['exclusive_tax_total'],
                'subtotal_excl_tax' => $taxCalc['subtotal_excl_tax'],
                'tax_details' => $taxCalc['tax_details'],
                'net_amount' => $net,
                'line_total' => $net,
            ];

            $subTotal += $gross;
            $discountTotal += $discount;
            $taxTotal += $taxCalc['tax_amount'];
            $inclusiveTaxTotal += $taxCalc['inclusive_tax_total'];
            $exclusiveTaxTotal += $taxCalc['exclusive_tax_total'];
            $subtotalExclTax += $taxCalc['subtotal_excl_tax'];
            $grandTotal += $net;
        }

        return [
            'items' => $calculatedItems,
            'sub_total' => round($subTotal, 4),
            'discount_total' => round($discountTotal, 4),
            'tax_total' => round($taxTotal, 4),
            'inclusive_tax_total' => round($inclusiveTaxTotal, 4),
            'exclusive_tax_total' => round($exclusiveTaxTotal, 4),
            'subtotal_excl_tax' => round($subtotalExclTax, 4),
            'grand_total' => round($grandTotal, 4),
        ];
    }

    private function calculateTaxForQuotationItem(array $item, float $gross, float $discount, float $quantity): array
    {
        $amountAfterDiscount = max($gross - $discount, 0);
        $incomingTaxDetails = $item['tax_details'] ?? data_get($item, 'metadata.tax_details', []);

        if (!is_array($incomingTaxDetails)) {
            $incomingTaxDetails = [];
        }

        if (!empty($incomingTaxDetails)) {
            return $this->normaliseIncomingTaxDetails($incomingTaxDetails, $amountAfterDiscount, $quantity, (float) ($item['tax_rate'] ?? 0));
        }

        $taxable = filter_var($item['taxable'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $rate = (float) ($item['tax_rate'] ?? 0);
        $ratePercent = $rate > 0 && $rate <= 1 ? $rate * 100 : $rate;
        $providedTaxAmount = array_key_exists('tax_amount', $item)
            ? (float) $item['tax_amount']
            : (array_key_exists('tax_total', $item) ? (float) $item['tax_total'] : null);

        if ($taxable && $providedTaxAmount !== null) {
            $taxAmount = round(max($providedTaxAmount, 0), 4);
        } elseif ($taxable && $ratePercent > 0) {
            $taxAmount = round($amountAfterDiscount * ($ratePercent / 100), 4);
        } else {
            $taxAmount = 0;
        }

        $details = [];
        if ($taxable && ($ratePercent > 0 || $taxAmount > 0)) {
            $details[] = [
                'tax_id' => $item['tax_id'] ?? null,
                'tax_name' => $item['tax_name'] ?? 'Tax',
                'tax_code' => $item['tax_code'] ?? 'TAX',
                'fdms_tax_id' => $item['fdms_tax_id'] ?? $item['fiscal_tax_id'] ?? null,
                'fiscal_tax_id' => $item['fiscal_tax_id'] ?? $item['fdms_tax_id'] ?? null,
                'tax_type' => 'percentage',
                'tax_percent' => round($ratePercent, 4),
                'tax_rate' => round($ratePercent, 4),
                'taxable_amount' => round($amountAfterDiscount, 4),
                'tax_amount' => $taxAmount,
                'sales_amount_with_tax' => round($amountAfterDiscount + $taxAmount, 4),
                'is_inclusive' => false,
                'obliged_to_zimra' => filter_var($item['obliged_to_zimra'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return [
            'taxable' => $taxable,
            'tax_rate' => round($ratePercent, 6),
            'tax_amount' => $taxAmount,
            'inclusive_tax_total' => 0,
            'exclusive_tax_total' => $taxAmount,
            'subtotal_excl_tax' => round($amountAfterDiscount, 4),
            'line_total' => round($amountAfterDiscount + $taxAmount, 4),
            'tax_details' => $details,
        ];
    }

    private function normaliseIncomingTaxDetails(array $incomingTaxDetails, float $amountAfterDiscount, float $quantity, float $fallbackTaxRate = 0): array
    {
        $taxDetails = [];
        $inclusiveTaxTotal = 0;
        $exclusiveTaxTotal = 0;
        $taxAmountTotal = 0;
        $subtotalExclTax = null;
        $weightedRate = 0;
        $hasTax = false;

        foreach ($incomingTaxDetails as $row) {
            if (!is_array($row)) {
                continue;
            }

            $isInclusive = filter_var($row['is_inclusive'] ?? $row['inclusive'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $taxType = $row['tax_type'] ?? $row['type'] ?? 'percentage';
            $taxPercent = (float) ($row['tax_percent'] ?? $row['tax_rate'] ?? $row['rate'] ?? $fallbackTaxRate);
            $taxPercent = $taxPercent > 0 && $taxPercent <= 1 ? $taxPercent * 100 : $taxPercent;
            $taxableAmount = (float) ($row['taxable_amount'] ?? $row['subtotal_excl_tax'] ?? $amountAfterDiscount);
            $taxAmount = (float) ($row['tax_amount'] ?? 0);

            if ($taxAmount <= 0 && $taxPercent > 0) {
                if ($isInclusive) {
                    $taxAmount = $amountAfterDiscount - ($amountAfterDiscount / (1 + ($taxPercent / 100)));
                    $taxableAmount = $amountAfterDiscount - $taxAmount;
                } else {
                    $taxAmount = $taxableAmount * ($taxPercent / 100);
                }
            }

            if ($taxType === 'fixed' && $taxAmount <= 0) {
                $fixedRate = (float) ($row['rate'] ?? $row['tax_rate'] ?? 0);
                $taxAmount = $fixedRate * $quantity;
            }

            $taxAmount = round($taxAmount, 4);
            $taxableAmount = round($taxableAmount, 4);

            if ($taxAmount <= 0 && $taxPercent <= 0) {
                continue;
            }

            $salesAmountWithTax = (float) ($row['sales_amount_with_tax'] ?? 0);
            if ($salesAmountWithTax <= 0) {
                $salesAmountWithTax = $isInclusive ? $amountAfterDiscount : $taxableAmount + $taxAmount;
            }

            $taxDetails[] = [
                'tax_id' => $row['tax_id'] ?? $row['id'] ?? null,
                'tax_name' => $row['tax_name'] ?? $row['name'] ?? null,
                'tax_code' => $row['tax_code'] ?? $row['code'] ?? null,
                'fdms_tax_id' => $row['fdms_tax_id'] ?? $row['fiscal_tax_id'] ?? $row['taxID'] ?? null,
                'fiscal_tax_id' => $row['fiscal_tax_id'] ?? $row['fdms_tax_id'] ?? $row['taxID'] ?? null,
                'tax_type' => $taxType,
                'tax_percent' => round($taxPercent, 4),
                'tax_rate' => round($taxPercent, 4),
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'sales_amount_with_tax' => round($salesAmountWithTax, 4),
                'is_inclusive' => $isInclusive,
                'obliged_to_zimra' => filter_var($row['obliged_to_zimra'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'metadata' => $row['metadata'] ?? null,
            ];

            $taxAmountTotal += $taxAmount;
            $weightedRate += $taxPercent;
            $hasTax = true;
            $isInclusive ? $inclusiveTaxTotal += $taxAmount : $exclusiveTaxTotal += $taxAmount;
            $subtotalExclTax = $subtotalExclTax === null ? $taxableAmount : min($subtotalExclTax, $taxableAmount);
        }

        $subtotalExclTax = $subtotalExclTax === null ? $amountAfterDiscount : $subtotalExclTax;

        return [
            'taxable' => $hasTax,
            'tax_rate' => round($weightedRate, 6),
            'tax_amount' => round($taxAmountTotal, 4),
            'inclusive_tax_total' => round($inclusiveTaxTotal, 4),
            'exclusive_tax_total' => round($exclusiveTaxTotal, 4),
            'subtotal_excl_tax' => round($subtotalExclTax, 4),
            'line_total' => round($subtotalExclTax + $inclusiveTaxTotal + $exclusiveTaxTotal, 4),
            'tax_details' => $taxDetails,
        ];
    }

    public function createOrUpdate(array $data, ?Quotation $quotation = null): Quotation
    {
        $companyId = $data['company_id'];
        $branchId = $data['branch_id'] ?? null;
        $totals = $this->calculateTotals($data['items'] ?? []);

        return DB::transaction(function () use ($data, $quotation, $companyId, $branchId, $totals) {
            if (!$quotation) {
                $quotation = new Quotation();
                $quotation->id = (string) Str::uuid();
                $quotation->company_id = $companyId;
                $quotation->branch_id = $branchId;
                $quotation->quote_code = $data['quote_code'];
                $quotation->status = $data['status'] ?? 'draft';
            }

            $quotation->fill([
                'customer_id' => $data['customer_id'] ?? null,
                'pos_user_id' => $data['pos_user_id'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'sub_total' => $totals['sub_total'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'tax_summary' => [
                        'subtotal_excl_tax' => $totals['subtotal_excl_tax'],
                        'inclusive_tax_total' => $totals['inclusive_tax_total'],
                        'exclusive_tax_total' => $totals['exclusive_tax_total'],
                        'tax_total' => $totals['tax_total'],
                    ],
                ]),
            ]);

            if (isset($data['status'])) {
                $quotation->status = $data['status'];
            }

            $quotation->save();
            QuotationItem::where('quotation_id', $quotation->id)->delete();

            foreach ($totals['items'] as $item) {
                $payload = [
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'],
                    'product_price_id' => $item['product_price_id'] ?? null,
                    'price_level_id' => $item['price_level_id'] ?? null,
                    'category_id' => $item['category_id'] ?? null,
                    'product_name' => $item['product_name'] ?? $item['name'] ?? 'Product',
                    'product_code' => $item['product_code'] ?? $item['code'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'currency' => $data['currency'] ?? $item['currency'] ?? 'USD',
                    'quantity' => $item['quantity'],
                    'base_unit_price' => $item['base_unit_price'] ?? $item['unit_price'] ?? 0,
                    'unit_price' => $item['unit_price'],
                    'has_price_override' => filter_var($item['has_price_override'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'price_override_level_id' => $item['price_override_level_id'] ?? data_get($item, 'price_level_override.price_level_id'),
                    'price_override_approved_by' => $item['price_override_approved_by'] ?? data_get($item, 'price_level_override.approved_by'),
                    'price_override_approval_id' => $item['price_override_approval_id'] ?? data_get($item, 'price_level_override.manager_approval_id'),
                    'price_override_payload' => $item['price_override_payload'] ?? $item['price_level_override'] ?? null,
                    'gross_amount' => $item['gross_amount'],
                    'discount_amount' => $item['discount_amount'],
                    'tax_amount' => $item['tax_amount'],
                    'net_amount' => $item['net_amount'],
                    'discount_id' => $item['discount_id'] ?? data_get($item, 'discount_payload.discount_id'),
                    'discount_type_id' => $item['discount_type_id'] ?? data_get($item, 'discount_payload.discount_type_id'),
                    'discount_code' => $item['discount_code'] ?? data_get($item, 'discount_payload.discount_code'),
                    'discount_name' => $item['discount_name'] ?? data_get($item, 'discount_payload.discount_name'),
                    'discount_basis' => $item['discount_basis'] ?? data_get($item, 'discount_payload.discount_basis'),
                    'discount_value' => $item['discount_value'] ?? data_get($item, 'discount_payload.discount_value', 0),
                    'discount_approved_by' => $item['discount_approved_by'] ?? data_get($item, 'discount_payload.approved_by'),
                    'discount_approval_id' => $item['discount_approval_id'] ?? data_get($item, 'discount_payload.manager_approval_id'),
                    'discount_payload' => $item['discount_payload'] ?? null,
                    'taxable' => filter_var($item['taxable'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'metadata' => array_merge($item['metadata'] ?? [], [
                        'tax_details' => $item['tax_details'] ?? [],
                        'tax_summary' => [
                            'subtotal_excl_tax' => $item['subtotal_excl_tax'] ?? 0,
                            'inclusive_tax_total' => $item['inclusive_tax_total'] ?? 0,
                            'exclusive_tax_total' => $item['exclusive_tax_total'] ?? 0,
                            'tax_total' => $item['tax_total'] ?? $item['tax_amount'] ?? 0,
                        ],
                    ]),
                ];

                foreach (['subtotal_excl_tax', 'inclusive_tax_total', 'exclusive_tax_total', 'tax_total', 'tax_details'] as $column) {
                    if (Schema::hasColumn('quotation_items', $column)) {
                        $payload[$column] = $item[$column] ?? ($column === 'tax_total' ? ($item['tax_amount'] ?? 0) : ($column === 'tax_details' ? [] : 0));
                    }
                }

                QuotationItem::create($payload);
            }

            return $quotation->fresh(['items']);
        });
    }

    public function formatForSale(Quotation $quotation): array
    {
        $quotation->loadMissing('items');

        return [
            'quotation_id' => $quotation->id,
            'quote_code' => $quotation->quote_code,
            'customer_id' => $quotation->customer_id,
            'customer_name' => $quotation->customer_name,
            'customer_phone' => $quotation->customer_phone,
            'customer_email' => $quotation->customer_email,
            'currency_code' => $quotation->currency,
            'currency' => $quotation->currency,
            'sub_total' => (float) $quotation->sub_total,
            'discount_total' => (float) $quotation->discount_total,
            'tax_total' => (float) $quotation->tax_total,
            'grand_total' => (float) $quotation->grand_total,
            'notes' => $quotation->notes,
            'status' => $quotation->status,
            'sale_products' => $quotation->items->map(fn (QuotationItem $item) => $item->sale_product_payload)->values()->all(),
        ];
    }

    public function audit(string $action, ?Quotation $quotation, array $payload = []): void
    {
        DB::table('quotation_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $payload['company_id'] ?? $quotation?->company_id,
            'branch_id' => $payload['branch_id'] ?? $quotation?->branch_id,
            'quotation_id' => $quotation?->id,
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name ?? auth()->user()?->username,
            'action' => $action,
            'status' => $payload['status'] ?? 'success',
            'description' => $payload['description'] ?? null,
            'old_values' => isset($payload['old_values']) ? json_encode($payload['old_values']) : null,
            'new_values' => isset($payload['new_values']) ? json_encode($payload['new_values']) : null,
            'metadata' => isset($payload['metadata']) ? json_encode($payload['metadata']) : null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function companyBranchCode(?string $branchId): ?string
    {
        if (!$branchId || !Schema::hasTable('company_branches')) {
            return null;
        }

        return DB::table('company_branches')->where('id', $branchId)->value('branch_code');
    }
}
