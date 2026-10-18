<?php

namespace Modules\Authentication\Enums;

enum EnterpriseScope: string
{
    case Global = 'global';
    case Country = 'country';
    case Company = 'company';
    case Brand = 'brand';
    case Complex = 'complex';
    case Shop = 'shop';
    case Till = 'till';

    public function column(): ?string
    {
        return match ($this) {
            self::Global => null,
            self::Country => 'country_id',
            self::Company => 'company_id',
            self::Brand => 'brand_id',
            self::Complex => 'complex_id',
            self::Shop => 'shop_id',
            self::Till => 'till_id',
        };
    }

    public function specificity(): int
    {
        return match ($this) {
            self::Global => 0,
            self::Country => 10,
            self::Company => 20,
            self::Brand => 30,
            self::Complex => 40,
            self::Shop => 50,
            self::Till => 60,
        };
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Global,
            self::Country,
            self::Company,
            self::Brand,
            self::Complex,
            self::Shop,
            self::Till,
        ];
    }
}
