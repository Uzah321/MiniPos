<?php

namespace App\Services\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Currency\Models\Currency;
use Modules\Currency\Models\PaymentType;
use Modules\Product\Models\ProductPrice;
use Modules\Product\Models\ProductTaxe;
use Modules\Product\Models\Taxe;

/**
 * Immutable, server-held pricing contract acknowledged by one Android node.
 *
 * The mobile client sends only the content hash and row identifiers. Prices,
 * taxes, payment policy and exchange rates are always reconstructed from this
 * cloud snapshot; no monetary value supplied by the device is trusted.
 */
final class MobileSaleSnapshot
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $datasets;

    private CarbonImmutable $generatedAt;

    /** @var array<string, mixed> */
    private array $mobileContext;

    public function __construct(
        private readonly array $payload,
        private readonly string $branchId,
        private readonly string $tillId
    ) {
        if ((int) ($payload['schema_version'] ?? 0) !== 3) {
            throw new MobileSaleSnapshotException(
                'This acknowledged mobile configuration predates the required sale context. Synchronize configuration before selling.',
                'MOBILE_CONFIGURATION_RESYNC_REQUIRED',
                409
            );
        }
        $this->datasets = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $this->generatedAt = CarbonImmutable::parse((string) ($payload['generated_at'] ?? 'now'));
        $this->mobileContext = $this->validatedMobileContext();
    }

    public function contentHash(): string
    {
        return strtolower((string) ($this->payload['content_hash'] ?? ''));
    }

    public function generatedAt(): string
    {
        return $this->generatedAt->toIso8601String();
    }

    public function businessDate(): ?string
    {
        return $this->mobileContext['business_date'];
    }

    public function salesAllowed(): bool
    {
        return $this->mobileContext['sales_allowed'];
    }

    public function businessMode(): string
    {
        return $this->mobileContext['business_mode'];
    }

    public function assertSaleContext(mixed $claimedBusinessDate): void
    {
        if (! $this->salesAllowed()) {
            throw new MobileSaleSnapshotException(
                (string) ($this->mobileContext['sale_block_reason']
                    ?? 'The acknowledged mobile configuration did not permit sales.'),
                'MOBILE_SNAPSHOT_SALES_BLOCKED',
                409
            );
        }

        $claimed = is_scalar($claimedBusinessDate) ? trim((string) $claimedBusinessDate) : '';
        if ($claimed === '' || ! hash_equals((string) $this->businessDate(), $claimed)) {
            throw new MobileSaleSnapshotException(
                'The queued sale business date does not match its acknowledged mobile configuration. Synchronize or reconcile this receipt.',
                'MOBILE_SNAPSHOT_BUSINESS_DATE_MISMATCH',
                409
            );
        }
    }

    public function currency(string $code): Currency
    {
        $row = $this->currencyRow($code);
        if (! $row) {
            throw new InvalidArgumentException("Currency {$code} was not available in the acknowledged terminal configuration.");
        }

        return $this->hydrate(Currency::class, $row);
    }

    public function baseCurrency(): Currency
    {
        $rows = collect($this->rows('currencies'))
            ->filter(fn (array $row): bool => $this->activeRow($row))
            ->groupBy(fn (array $row): string => strtoupper(trim((string) ($row['code'] ?? ''))))
            ->map(fn (Collection $rows): ?array => $this->sortEffective($rows->all())[0] ?? null)
            ->filter()
            ->values()
            ->all();
        $baseRows = array_values(array_filter($rows, fn (array $row): bool => $this->bool($row['is_base_currency'] ?? false)));
        $row = ($this->sortEffective($baseRows)[0] ?? null) ?: ($this->sortEffective($rows)[0] ?? null);
        if (! $row) {
            throw new InvalidArgumentException('No base currency was available in the acknowledged terminal configuration.');
        }

        return $this->hydrate(Currency::class, $row);
    }

    public function paymentType(string $id): PaymentType
    {
        $row = collect($this->rows('payment_types'))->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === $id
                && $this->activeRow($row)
                && $this->branchMatches($row)
        );
        if (! $row) {
            throw new InvalidArgumentException('A payment method was not available in the acknowledged terminal configuration.');
        }
        if (! $this->supportedMobilePayment($row)) {
            throw new MobileSaleSnapshotException(
                'The selected payment method is not authorized for queued mobile sales.',
                'MOBILE_PAYMENT_SELECTION_UNAUTHORIZED',
                422
            );
        }

        return $this->hydrate(PaymentType::class, $row);
    }

    public function productPrice(string $productId, ?string $priceId): ProductPrice
    {
        if (! $priceId) {
            throw new InvalidArgumentException('The queued Android sale does not identify its acknowledged product price.');
        }
        if (! $this->mobileProductAvailable($productId)) {
            throw new MobileSaleSnapshotException(
                'The selected product was not available in the acknowledged mobile catalogue.',
                'MOBILE_PRODUCT_SELECTION_UNAUTHORIZED',
                422
            );
        }

        $eligibleRows = array_values(array_filter(
            $this->rows('product_prices'),
            fn (array $row): bool => (string) ($row['product_id'] ?? '') === $productId
                && $this->activeRow($row)
                && $this->branchMatches($row)
                && $this->activeDuring($row, 'starts_at', 'ends_at')
        ));
        $row = collect($eligibleRows)->first(
            fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $priceId
        );
        if (! $row) {
            throw new InvalidArgumentException('The selected price was not valid in the acknowledged terminal configuration.');
        }

        $ordinary = $this->ordinaryProductPriceRow($eligibleRows);
        if (! $ordinary || (string) ($ordinary['id'] ?? '') !== (string) ($row['id'] ?? '')) {
            throw new MobileSaleSnapshotException(
                'The selected price is not the ordinary price authorized by the acknowledged mobile catalogue.',
                'MOBILE_PRICE_SELECTION_UNAUTHORIZED',
                422
            );
        }

        return $this->hydrate(ProductPrice::class, $row);
    }

    /**
     * Mirror MobileConfigurationParser's deterministic catalogue choice. An
     * offline sale may use exactly the price the untampered catalogue exposed;
     * selecting any other snapshot row is a price-level override and needs an
     * online authorization workflow.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function ordinaryProductPriceRow(array $rows): ?array
    {
        $standardPriceLevelIds = collect($this->rows('price_levels'))
            ->filter(fn (array $row): bool => $this->activeRow($row)
                && (strcasecmp(trim((string) ($row['name'] ?? '')), 'standard') === 0
                    || strcasecmp(trim((string) ($row['code'] ?? '')), 'standard') === 0))
            ->map(fn (array $row): string => (string) ($row['id'] ?? ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->all();

        usort($rows, function (array $left, array $right) use ($standardPriceLevelIds): int {
            foreach ([
                in_array((string) ($right['price_level_id'] ?? ''), $standardPriceLevelIds, true)
                    <=> in_array((string) ($left['price_level_id'] ?? ''), $standardPriceLevelIds, true),
                ((string) ($right['branch_id'] ?? '') === $this->branchId)
                    <=> ((string) ($left['branch_id'] ?? '') === $this->branchId),
                $this->bool($right['is_default'] ?? false)
                    <=> $this->bool($left['is_default'] ?? false),
            ] as $comparison) {
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $leftPrice = is_numeric($left['price'] ?? null) ? (float) $left['price'] : INF;
            $rightPrice = is_numeric($right['price'] ?? null) ? (float) $right['price'] : INF;
            $priceComparison = $leftPrice <=> $rightPrice;
            if ($priceComparison !== 0) {
                return $priceComparison;
            }

            foreach (['updated_at', 'created_at', 'id'] as $column) {
                $comparison = strcmp((string) ($right[$column] ?? ''), (string) ($left[$column] ?? ''));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $rows[0] ?? null;
    }

    /** @return Collection<int, ProductTaxe> */
    public function productTaxes(string $productId): Collection
    {
        $taxes = collect($this->rows('taxes'))
            ->filter(fn (array $row): bool => $this->activeRow($row))
            ->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));

        return collect($this->rows('product_taxes'))
            ->filter(fn (array $row): bool => (string) ($row['product_id'] ?? '') === $productId
                && $this->activeRow($row)
                && $this->branchMatches($row)
                && $this->activeDuring($row, 'starts_at', 'ends_at'))
            ->map(function (array $row) use ($taxes): ?ProductTaxe {
                $taxRow = $taxes->get((string) ($row['tax_id'] ?? ''));
                if (! is_array($taxRow)) {
                    return null;
                }
                $assignment = $this->hydrate(ProductTaxe::class, $row);
                $assignment->setRelation('tax', $this->hydrate(Taxe::class, $taxRow));

                return $assignment;
            })
            ->filter()
            ->values();
    }

    /** Product fiscal metadata must come from the acknowledged offline catalogue. */
    public function productFiscalFields(string $productId): array
    {
        $row = collect($this->rows('products'))->first(
            fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $productId
        );
        if (! $row || ! $this->mobileProductAvailable($productId)) {
            throw new InvalidArgumentException('This product was not saleable in the acknowledged mobile catalogue.');
        }
        $hsCode = trim((string) ($row['hs_code'] ?? ''));

        return [
            'name' => (string) ($row['name'] ?? ''),
            'hs_code' => $hsCode !== '' ? $hsCode : null,
            'product_type' => (string) ($row['product_type'] ?? ''),
            'stock_tracking_enabled' => $this->bool($row['stock_tracking_enabled'] ?? false),
            'allow_negative_stock' => $this->bool($row['allow_negative_stock'] ?? false),
        ];
    }

    /** @return array<int, string> */
    public function activeMenuRecipeIds(string $productId): array
    {
        $ids = collect($this->rows('food_recipes'))
            ->filter(fn (array $row): bool => (string) ($row['menu_product_id'] ?? '') === $productId
                && (string) ($row['recipe_type'] ?? '') === 'menu'
                && $this->activeRow($row))
            ->map(fn (array $row): string => (string) ($row['id'] ?? ''))
            ->filter()->values()->all();
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Mirrors Android CurrencyMath and the two-stage cloud conversion: source
     * to base rounded to four decimals, then base to target rounded again.
     */
    public function convert(float $amount, string $fromCode, string $toCode): float
    {
        $from = strtoupper(trim($fromCode));
        $to = strtoupper(trim($toCode));
        if ($from === $to) {
            return round($amount, 4);
        }

        $base = $this->baseCurrency();
        $baseCode = strtoupper((string) $base->code);
        $baseRate = $this->rateFor((string) $base->code);
        $fromRate = $this->rateFor($from);
        $toRate = $this->rateFor($to);
        $baseAmount = $from === $baseCode
            ? round($amount, 4)
            : round(($amount / $fromRate) * $baseRate, 4);
        if ($to === $baseCode) {
            return $baseAmount;
        }

        $baseToTarget = round((1 / $baseRate) * $toRate, 4);

        return round($baseAmount * $baseToTarget, 4);
    }

    public function exchangeRate(string $fromCode, string $toCode): float
    {
        return $this->convert(1, $fromCode, $toCode);
    }

    private function rateFor(string $code): float
    {
        $currency = $this->currencyRow($code);
        if (! $currency) {
            throw new InvalidArgumentException("No acknowledged currency configuration exists for {$code}.");
        }
        $rows = array_values(array_filter(
            $this->rows('currency_rates'),
            fn (array $row): bool => (string) ($row['currency_id'] ?? '') === (string) ($currency['id'] ?? '')
                && $this->activeRow($row)
                && $this->branchMatches($row)
                && is_numeric($row['rate'] ?? null)
                && (float) $row['rate'] > 0
        ));
        $rate = $this->sortEffective($rows)[0]['rate'] ?? null;
        if ($rate === null && $this->bool($currency['is_base_currency'] ?? false)) {
            return 1.0;
        }
        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new InvalidArgumentException("No valid acknowledged exchange rate exists for {$code}.");
        }

        return (float) $rate;
    }

    private function currencyRow(string $code): ?array
    {
        $code = strtoupper(trim($code));
        $rows = array_values(array_filter(
            $this->rows('currencies'),
            fn (array $row): bool => strtoupper(trim((string) ($row['code'] ?? ''))) === $code
                && $this->activeRow($row)
                && $this->branchMatches($row)
        ));

        return $this->sortEffective($rows)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $dataset): array
    {
        $rows = $this->datasets[$dataset] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    private function validatedMobileContext(): array
    {
        $rows = $this->rows('mobile_context');
        if (count($rows) !== 1) {
            throw new MobileSaleSnapshotException(
                'This acknowledged mobile configuration predates the required sale context. Synchronize configuration before selling.',
                'MOBILE_CONFIGURATION_RESYNC_REQUIRED',
                409
            );
        }

        $row = $rows[0];
        $companyId = (string) ($this->payload['company_id'] ?? '');
        if ($companyId === ''
            || (string) ($row['company_id'] ?? '') !== $companyId
            || (string) ($row['branch_id'] ?? '') !== $this->branchId
            || (string) ($row['till_id'] ?? '') !== $this->tillId) {
            throw new MobileSaleSnapshotException(
                'The acknowledged mobile sale context does not match this company, branch and till.',
                'MOBILE_CONFIGURATION_CONTEXT_SCOPE_INVALID',
                409
            );
        }

        $businessMode = strtolower(trim((string) ($row['business_mode'] ?? '')));
        $systemTimezone = trim((string) ($row['system_timezone'] ?? ''));
        $timezoneValid = $systemTimezone !== '' && in_array($systemTimezone, timezone_identifiers_list(), true);
        $salesAllowed = $this->strictBoolean($row['sales_allowed'] ?? null);
        $businessDate = is_scalar($row['business_date'] ?? null)
            ? trim((string) $row['business_date'])
            : '';
        $dateValid = $businessDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate);
        if ($dateValid) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $businessDate, 'Africa/Harare');
                $dateValid = $parsed !== false && $parsed->format('Y-m-d') === $businessDate;
            } catch (\Throwable) {
                $dateValid = false;
            }
        }

        if (! in_array($businessMode, ['retail', 'hospitality'], true)
            || ! $timezoneValid
            || $salesAllowed === null
            || ($salesAllowed && ! $dateValid)
            || (! $salesAllowed && $businessDate !== '' && ! $dateValid)) {
            throw new MobileSaleSnapshotException(
                'The acknowledged mobile sale context is incomplete or invalid. Synchronize configuration before selling.',
                'MOBILE_CONFIGURATION_RESYNC_REQUIRED',
                409
            );
        }

        $row['business_mode'] = $businessMode;
        $row['business_date'] = $businessDate !== '' ? $businessDate : null;
        $row['sales_allowed'] = $salesAllowed;
        $row['system_timezone'] = $systemTimezone;

        return $row;
    }

    private function strictBoolean(mixed $value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', 'yes', 'on' => true,
                'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }

    private function mobileProductAvailable(string $productId): bool
    {
        $row = collect($this->rows('products'))->first(
            fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $productId
                && $this->activeRow($candidate)
                && $this->branchMatches($candidate)
        );
        if (! $row) {
            return false;
        }

        $productType = $this->normalizeMobileLabel((string) ($row['product_type'] ?? ''));

        return $this->businessMode() === 'hospitality'
            ? $productType === 'finished_good'
            : in_array($productType, ['simple', 'parent', 'child', 'finished_good'], true);
    }

    /** @param array<string, mixed> $row */
    private function supportedMobilePayment(array $row): bool
    {
        $code = $this->normalizeMobileLabel((string) ($row['money_code'] ?? ''));
        $type = $this->normalizeMobileLabel((string) ($row['type'] ?? ''));
        $name = $this->normalizeMobileLabel((string) ($row['name'] ?? ''));
        $accountNames = [
            'customer_credit', 'customer_account', 'credit_account', 'on_account', 'account_sale',
        ];

        return $type !== 'credit'
            && ! in_array($code, [
                'credit_customer', 'customer_credit', 'credit_account', 'customer_account', 'account',
            ], true)
            && ! in_array($name, $accountNames, true)
            && ! (str_contains($name, 'customer')
                && (str_contains($name, 'credit') || str_contains($name, 'account')));
    }

    private function normalizeMobileLabel(string $value): string
    {
        return str_replace([' ', '-', '/'], '_', strtolower(trim($value)));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sortEffective(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            $branch = ((string) ($right['branch_id'] ?? '') === $this->branchId)
                <=> ((string) ($left['branch_id'] ?? '') === $this->branchId);
            if ($branch !== 0) {
                return $branch;
            }
            foreach (['updated_at', 'created_at', 'id'] as $column) {
                $comparison = strcmp((string) ($right[$column] ?? ''), (string) ($left[$column] ?? ''));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    private function activeDuring(array $row, string $startColumn, string $endColumn): bool
    {
        $startsAt = $this->date($row[$startColumn] ?? null, false);
        $endsAt = $this->date($row[$endColumn] ?? null, true);

        return (! $startsAt || ! $startsAt->isAfter($this->generatedAt))
            && (! $endsAt || ! $endsAt->isBefore($this->generatedAt));
    }

    private function date(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::parse(
                (string) $value,
                (string) ($this->mobileContext['system_timezone'] ?? 'Africa/Harare')
            );
            if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value))) {
                return $date->endOfDay();
            }

            return $date;
        } catch (\Throwable) {
            return null;
        }
    }

    private function branchMatches(array $row): bool
    {
        $branchId = trim((string) ($row['branch_id'] ?? ''));

        return $branchId === '' || $branchId === $this->branchId;
    }

    private function activeRow(array $row): bool
    {
        return empty($row['deleted_at'])
            && (! array_key_exists('is_active', $row) || $this->bool($row['is_active']));
    }

    private function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @template T of \Illuminate\Database\Eloquent\Model @param class-string<T> $class @return T */
    private function hydrate(string $class, array $row)
    {
        $model = new $class;
        $model->setRawAttributes($row, true);
        $model->exists = true;

        return $model;
    }
}
