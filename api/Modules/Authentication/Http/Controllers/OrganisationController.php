<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\Brand;
use Modules\Authentication\Models\Complex;
use Modules\Authentication\Models\Country;
use Modules\Authentication\Services\AccessService;
use Modules\Terminals\Terminal;

class OrganisationController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function countries(Request $request): JsonResponse
    {
        $query = Country::query()->orderBy('name');
        if (! $request->user()->isSuperAdmin()) {
            $ids = $this->access->assignments($request->user(), 'countries.view')->pluck('country_id')->filter();
            $query->whereIn('id', $ids);
        }

        return response()->json($query->paginate());
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'countries.create', AccessContext::global());
        $data = $request->validate([
            'iso_code' => ['required', 'string', 'size:2', 'unique:countries,iso_code'],
            'iso3_code' => ['nullable', 'string', 'size:3', 'unique:countries,iso3_code'],
            'name' => ['required', 'string', 'max:120'],
            'currency_code' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'settings' => ['nullable', 'array'],
        ]);

        return response()->json(Country::create($data), 201);
    }

    public function updateCountry(Request $request, Country $country): JsonResponse
    {
        $this->requirePermission($request, 'countries.update', new AccessContext(countryId: $country->id));
        $country->update($request->validate([
            'iso_code' => ['sometimes', 'string', 'size:2', Rule::unique('countries')->ignore($country)],
            'iso3_code' => ['nullable', 'string', 'size:3', Rule::unique('countries')->ignore($country)],
            'name' => ['sometimes', 'string', 'max:120'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'settings' => ['nullable', 'array'],
        ]));

        return response()->json($country->fresh());
    }

    public function brands(Request $request): JsonResponse
    {
        $query = Brand::query()->with('country')->orderBy('name');
        if (! $request->user()->isSuperAdmin()) {
            $assignments = $this->access->assignments($request->user(), 'brands.view')->get();
            $query->where(fn (Builder $scope) => $scope
                ->whereIn('country_id', $assignments->pluck('country_id')->filter())
                ->orWhereIn('id', $assignments->pluck('brand_id')->filter()));
        }

        return response()->json($query->paginate());
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'uuid', 'exists:countries,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'settings' => ['nullable', 'array'],
        ]);
        $this->requirePermission($request, 'brands.create', new AccessContext(countryId: $data['country_id']));
        $data['code'] = strtoupper($data['code']);

        return response()->json(Brand::create($data), 201);
    }

    public function updateBrand(Request $request, Brand $brand): JsonResponse
    {
        $this->requirePermission($request, 'brands.update', new AccessContext($brand->country_id, $brand->id));
        $brand->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'settings' => ['nullable', 'array'],
        ]));

        return response()->json($brand->fresh());
    }

    public function complexes(Request $request): JsonResponse
    {
        $query = Complex::query()->with('brand.country')->orderBy('name');
        if (! $request->user()->isSuperAdmin()) {
            $assignments = $this->access->assignments($request->user(), 'complexes.view')->get();
            $query->where(fn (Builder $scope) => $scope
                ->whereIn('id', $assignments->pluck('complex_id')->filter())
                ->orWhereIn('brand_id', $assignments->pluck('brand_id')->filter())
                ->orWhereHas('brand', fn (Builder $brand) => $brand
                    ->whereIn('country_id', $assignments->pluck('country_id')->filter())));
        }

        return response()->json($query->paginate());
    }

    public function storeComplex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'uuid', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'settings' => ['nullable', 'array'],
        ]);
        $brand = Brand::findOrFail($data['brand_id']);
        $this->requirePermission($request, 'complexes.create', new AccessContext($brand->country_id, $brand->id));

        return response()->json(Complex::create($data), 201);
    }

    public function updateComplex(Request $request, Complex $complex): JsonResponse
    {
        $complex->loadMissing('brand');
        $this->requirePermission($request, 'complexes.update', new AccessContext(
            $complex->brand->country_id, $complex->brand_id, $complex->id
        ));
        $complex->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'settings' => ['nullable', 'array'],
        ]));

        return response()->json($complex->fresh());
    }

    public function stores(Request $request): JsonResponse
    {
        $query = $this->access->scopeStores(Store::query(), $request->user(), 'stores.view')
            ->with('complex.brand.country')->orderBy('name');

        return response()->json($query->paginate());
    }

    public function storeStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'complex_id' => ['required', 'uuid', 'exists:complexes,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'unique:stores,code'],
            'timezone' => ['required', 'timezone'],
            'business_hours' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $complex = Complex::with('brand')->findOrFail($data['complex_id']);
        $this->requirePermission($request, 'stores.create', new AccessContext(
            $complex->brand->country_id, $complex->brand_id, $complex->id
        ));

        return response()->json(Store::create($data), 201);
    }

    public function updateStore(Request $request, Store $store): JsonResponse
    {
        $this->requirePermission($request, 'stores.update', AccessContext::forStore($store));
        $store->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('stores')->ignore($store)],
            'timezone' => ['sometimes', 'timezone'],
            'business_hours' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'active' => ['sometimes', 'boolean'],
        ]));

        return response()->json($store->fresh());
    }

    public function terminals(Request $request): JsonResponse
    {
        $storeIds = $this->access->scopeStores(Store::query(), $request->user(), 'terminals.view')->pluck('id');

        return response()->json(Terminal::query()->whereIn('store_id', $storeIds)->paginate());
    }

    public function storeTerminal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'uuid', 'exists:stores,id'],
            'registration_code' => ['required', 'string', 'max:100', 'unique:terminals,registration_code'],
            'name' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended'])],
        ]);
        $store = Store::findOrFail($data['store_id']);
        $this->requirePermission($request, 'terminals.create', AccessContext::forStore($store));
        $plainDeviceKey = bin2hex(random_bytes(32));
        $data['device_key_hash'] = hash('sha256', $plainDeviceKey);
        $data['activated_at'] = ($data['status'] ?? 'pending') === 'active' ? now() : null;
        $terminal = Terminal::create($data);

        return response()->json(['terminal' => $terminal, 'device_key' => $plainDeviceKey], 201);
    }

    public function updateTerminal(Request $request, Terminal $terminal): JsonResponse
    {
        $this->requirePermission($request, 'terminals.update', AccessContext::forTerminal($terminal));
        $terminal->update($request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended'])],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]));

        return response()->json($terminal->fresh());
    }

    private function requirePermission(Request $request, string $permission, AccessContext $context): void
    {
        abort_unless($this->access->hasPermission($request->user(), $permission, $context), 403,
            'You do not have permission to perform this action.');
    }
}
