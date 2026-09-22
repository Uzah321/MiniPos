<?php

namespace Modules\Authentication\Services;

use App\Models\Store;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\Brand;
use Modules\Authentication\Models\Complex;
use Modules\Authentication\Models\Country;
use Modules\Terminals\Terminal;

class ScopeValidationService
{
    public function context(array $data): AccessContext
    {
        $scope = $data['scope_type'] ?? 'global';
        $required = [
            'country' => 'country_id', 'brand' => 'brand_id', 'complex' => 'complex_id',
            'store' => 'store_id', 'terminal' => 'terminal_id',
        ][$scope] ?? null;

        if ($required !== null && empty($data[$required])) {
            throw ValidationException::withMessages([$required => ["The {$required} field is required for this scope."]]);
        }

        if ($scope === 'global') return AccessContext::global();
        if ($scope === 'country') return new AccessContext(countryId: Country::findOrFail($data['country_id'])->id);
        if ($scope === 'brand') {
            $brand = Brand::findOrFail($data['brand_id']);
            return new AccessContext($brand->country_id, $brand->id);
        }
        if ($scope === 'complex') {
            $complex = Complex::with('brand')->findOrFail($data['complex_id']);
            return new AccessContext($complex->brand->country_id, $complex->brand_id, $complex->id);
        }
        if ($scope === 'store') return AccessContext::forStore(Store::findOrFail($data['store_id']));
        if ($scope === 'terminal') return AccessContext::forTerminal(Terminal::findOrFail($data['terminal_id']));

        throw ValidationException::withMessages(['scope_type' => ['The selected scope type is invalid.']]);
    }

    public function columns(AccessContext $context, string $scopeType): array
    {
        return [
            'scope_type' => $scopeType,
            'country_id' => $context->countryId,
            'brand_id' => $context->brandId,
            'complex_id' => $context->complexId,
            'store_id' => $context->storeId,
            'terminal_id' => $context->terminalId,
        ];
    }
}
