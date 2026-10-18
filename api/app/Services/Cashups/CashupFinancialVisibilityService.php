<?php

namespace App\Services\Cashups;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class CashupFinancialVisibilityService
{
    public const PERMISSION = 'view_cashup_financial_amounts';

    private const BLIND_CASHUP_ERROR_MESSAGE = 'Cashup values could not be validated. Review the counted payment entries and try again.';

    /**
     * Monetary and physical-count fields that must never leave the API when the
     * authenticated role only has operational cashup-report access.
     */
    private const SENSITIVE_KEYS = [
        'amount',
        'base_amount',
        'amount_tendered',
        'change_amount',
        'subtotal',
        'base_subtotal',
        'discount',
        'base_discount',
        'tax',
        'total',
        'total_amount',
        'base_total_amount',
        'gross_sales',
        'net_sales',
        'discounts',
        'average_basket',
        'base_gross_sales',
        'base_net_sales',
        'base_discounts',
        'base_tax',
        'sales_value',
        'sales',
        'base_sales_value',
        'invoiced_value',
        'base_invoiced_value',
        'cash_sale_value',
        'base_cash_sale_value',
        'credit_issued_value',
        'base_credit_issued_value',
        'credit_paid_value',
        'base_credit_paid_value',
        'credit_outstanding_value',
        'base_credit_outstanding_value',
        'money_received_value',
        'base_money_received_value',
        'credit_issued_reference_value',
        'cost',
        'cost_value',
        'base_cost_value',
        'transaction_cost_value',
        'profit',
        'profit_value',
        'base_profit_value',
        'gross_profit',
        'base_gross_profit',
        'estimated_profit',
        'base_estimated_profit',
        'transaction_sales_value',
        'transaction_estimated_profit',
        'net_profit_after_cashouts',
        'gross_margin_percent',
        'net_margin_after_cashouts_percent',
        'opening_float',
        'closing_cash',
        'expected_total',
        'counted_total',
        'total_variance',
        'base_total',
        'expected_amount',
        'expected_base_amount',
        'counted_amount',
        'counted_base_amount',
        'variance',
        'base_variance',
        'expected_cash',
        'expected_cash_before_cashouts',
        'expected_cash_after_cashouts',
        'effective_expected_cash_after_cashouts',
        'base_expected_cash',
        'counted_cash',
        'cash_variance',
        'cashout_total',
        'effective_cashout_total',
        'cashin_total',
        'net_cash_movement',
        'credit_amount',
        'credit_paid_amount',
        'credit_balance_amount',
        'credit_sales_total',
        'credit_paid_total',
        'credit_balance_total',
        'line_total',
        'denomination_value',
        'base_value',
        'value',
        'quantity',
        'cashup_expected_total',
        'cashup_counted_total',
        'cashup_total_variance',
        'cashup_expected_cash',
        'cashup_counted_cash',
        'cashup_cash_variance',
        'cashup_cashout_total',
        'cashup_variance',
        'base_expected_total',
        'base_counted_total',
        'base_total_variance',
        // Compatibility aliases used by older/custom POS clients.  Keep these
        // here so a differently-shaped review payload cannot bypass the blind
        // cashup rule.
        'expected',
        'expected_value',
        'variance_amount',
        'difference',
        'difference_amount',
        'over_short',
        'over_short_amount',
        'overage',
        'shortage',
    ];

    public function redact(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->redact($item));
        }

        if ($value instanceof Arrayable) {
            return $this->redact($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return $this->redact($value->jsonSerialize());
        }

        if (is_object($value)) {
            $copy = clone $value;
            foreach (get_object_vars($copy) as $key => $item) {
                $copy->{$key} = $this->isSensitiveKey((string) $key)
                    ? null
                    : $this->redact($item);
            }

            return $copy;
        }

        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? null
                : $this->redact($item);
        }

        return $redacted;
    }

    public function redactActiveSummary(array $summary): array
    {
        $redacted = $this->redact($summary);
        $redacted['permissions'] = array_merge($redacted['permissions'] ?? [], $this->permissionFlags(false));

        return $redacted;
    }

    public function permissionFlags(bool $allowed): array
    {
        return [
            'can_view_cashup_financial_amounts' => $allowed,
            // Kept for older POS clients while the canonical permission name is
            // view_cashup_financial_amounts.
            'can_view_expected_cashup_amounts' => $allowed,
        ];
    }

    /**
     * Validation/exception messages are a separate disclosure channel from
     * JSON amount fields.  A blind-count cashier still needs useful errors for
     * ordinary input fields, but any message or field that discusses expected
     * money, variance or over/short values must be replaced with a neutral
     * counted-entry instruction.
     */
    public function redactBlindCashupErrors(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->map(fn ($item, $key) => $this->redactBlindCashupErrorEntry($key, $item));
        }

        if ($value instanceof Arrayable) {
            return $this->redactBlindCashupErrors($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return $this->redactBlindCashupErrors($value->jsonSerialize());
        }

        if (is_object($value)) {
            $copy = clone $value;
            foreach (get_object_vars($copy) as $key => $item) {
                $copy->{$key} = $this->redactBlindCashupErrorEntry($key, $item);
            }

            return $copy;
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = $this->redactBlindCashupErrorEntry($key, $item);
            }

            return $redacted;
        }

        if (is_string($value) && $this->mentionsExpectedOrVariance($value)) {
            return self::BLIND_CASHUP_ERROR_MESSAGE;
        }

        return $value;
    }

    private function redactBlindCashupErrorEntry(string|int $key, mixed $value): mixed
    {
        if (is_string($key) && $this->isExpectedOrVarianceKey($key)) {
            return is_array($value) ? [self::BLIND_CASHUP_ERROR_MESSAGE] : self::BLIND_CASHUP_ERROR_MESSAGE;
        }

        return $this->redactBlindCashupErrors($value);
    }

    private function isExpectedOrVarianceKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'expected')
            || str_contains($normalized, 'variance')
            || str_contains($normalized, 'difference')
            || str_contains($normalized, 'over_short')
            || str_contains($normalized, 'overage')
            || str_contains($normalized, 'shortage');
    }

    private function mentionsExpectedOrVariance(string $message): bool
    {
        return preg_match('/\b(?:expected|variance|difference|over[ -]?short|overage|shortage)\b/i', $message) === 1;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
