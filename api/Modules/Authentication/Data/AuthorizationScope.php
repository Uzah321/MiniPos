<?php

namespace Modules\Authentication\Data;

use InvalidArgumentException;
use Modules\Authentication\Enums\EnterpriseScope;

final readonly class AuthorizationScope
{
    public function __construct(
        public ?string $countryId = null,
        public ?string $companyId = null,
        public ?string $brandId = null,
        public ?string $complexId = null,
        public ?string $shopId = null,
        public ?string $tillId = null,
        public ?string $branchId = null,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $shopId = self::value($values, 'shop_id', 'shopId');
        $branchId = self::value($values, 'branch_id', 'branchId');

        return new self(
            countryId: self::value($values, 'country_id', 'countryId'),
            companyId: self::value($values, 'company_id', 'companyId'),
            brandId: self::value($values, 'brand_id', 'brandId'),
            complexId: self::value($values, 'complex_id', 'complexId'),
            // Existing branches are backfilled one-to-one as shops. This
            // fallback lets pre-migration callers build the canonical scope.
            shopId: $shopId ?? $branchId,
            tillId: self::value($values, 'till_id', 'tillId'),
            branchId: $branchId,
        );
    }

    public static function global(): self
    {
        return new self;
    }

    public function identifier(EnterpriseScope $type): ?string
    {
        return match ($type) {
            EnterpriseScope::Global => null,
            EnterpriseScope::Country => $this->countryId,
            EnterpriseScope::Company => $this->companyId,
            EnterpriseScope::Brand => $this->brandId,
            EnterpriseScope::Complex => $this->complexId,
            EnterpriseScope::Shop => $this->shopId,
            EnterpriseScope::Till => $this->tillId,
        };
    }

    public function deepestType(): EnterpriseScope
    {
        foreach (array_reverse(EnterpriseScope::ordered()) as $type) {
            if ($type === EnterpriseScope::Global || $this->identifier($type) !== null) {
                return $type;
            }
        }

        return EnterpriseScope::Global;
    }

    public function scopeKey(EnterpriseScope $type): string
    {
        if ($type === EnterpriseScope::Global) {
            return EnterpriseScope::Global->value;
        }

        $identifier = $this->identifier($type);
        if ($identifier === null || trim($identifier) === '') {
            throw new InvalidArgumentException("A {$type->value} identifier is required for this scope.");
        }

        return $type->value.':'.trim($identifier);
    }

    /** @return list<string> */
    public function lineageKeys(): array
    {
        $keys = [EnterpriseScope::Global->value];

        foreach (EnterpriseScope::ordered() as $type) {
            if ($type === EnterpriseScope::Global || $this->identifier($type) === null) {
                continue;
            }

            $keys[] = $this->scopeKey($type);
        }

        return $keys;
    }

    /**
     * Return only the hierarchy columns at or above the selected scope. This
     * prevents a company policy, for example, from accidentally carrying a
     * stale shop identifier.
     *
     * @return array{scope_type: string, scope_key: string, country_id: ?string, company_id: ?string, brand_id: ?string, complex_id: ?string, shop_id: ?string, till_id: ?string}
     */
    public function databaseColumns(EnterpriseScope $type): array
    {
        $columns = [
            'scope_type' => $type->value,
            'scope_key' => $this->scopeKey($type),
            'country_id' => null,
            'company_id' => null,
            'brand_id' => null,
            'complex_id' => null,
            'shop_id' => null,
            'till_id' => null,
        ];

        foreach (EnterpriseScope::ordered() as $candidate) {
            if ($candidate === EnterpriseScope::Global) {
                continue;
            }

            if ($candidate->specificity() > $type->specificity()) {
                break;
            }

            $columns[$candidate->column()] = $this->identifier($candidate);
        }

        return $columns;
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'country_id' => $this->countryId,
            'company_id' => $this->companyId,
            'brand_id' => $this->brandId,
            'complex_id' => $this->complexId,
            'shop_id' => $this->shopId,
            'till_id' => $this->tillId,
            'branch_id' => $this->branchId,
        ];
    }

    /** @param array<string, mixed> $values */
    private static function value(array $values, string $snake, string $camel): ?string
    {
        $value = $values[$snake] ?? $values[$camel] ?? null;
        if ($value === null) {
            return null;
        }

        $normalised = trim((string) $value);

        return $normalised === '' ? null : $normalised;
    }
}
