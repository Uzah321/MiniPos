<?php

namespace Modules\Authentication\Data;

use App\Models\Store;
use Modules\Terminals\Terminal;

final readonly class AccessContext
{
    public function __construct(
        public ?string $countryId = null,
        public ?string $brandId = null,
        public ?string $complexId = null,
        public ?string $storeId = null,
        public ?string $terminalId = null,
    ) {}

    public static function global(): self
    {
        return new self;
    }

    public static function forStore(Store $store): self
    {
        $store->loadMissing('complex.brand');

        return new self(
            countryId: $store->complex?->brand?->country_id,
            brandId: $store->complex?->brand_id,
            complexId: $store->complex_id,
            storeId: $store->id,
        );
    }

    public static function forTerminal(Terminal $terminal): self
    {
        $context = self::forStore($terminal->store);

        return new self(
            countryId: $context->countryId,
            brandId: $context->brandId,
            complexId: $context->complexId,
            storeId: $context->storeId,
            terminalId: $terminal->id,
        );
    }
}
