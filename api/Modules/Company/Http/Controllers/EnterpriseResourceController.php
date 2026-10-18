<?php

namespace Modules\Company\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Company\CompanyDeletionService;
use App\Services\Inventory\InventoryWarehouseProvisioner;
use App\Services\PublicAssetStorage;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Company\Models\Brand;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\CompanyCountry;
use Modules\Company\Models\ComplexLocation;
use Modules\Company\Models\Country;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

/**
 * Platform-owner CRUD for the canonical hospitality organisation model.
 *
 * Each resource is managed independently. Parent identifiers are always
 * resolved on the server; denormalised country/company/brand/complex values
 * are never trusted from the browser.
 */
class EnterpriseResourceController extends Controller
{
    public function __construct(
        private readonly SyncConfigurationService $sync,
        private readonly InventoryWarehouseProvisioner $warehouses,
        private readonly PublicAssetStorage $assets,
    ) {}

    public function countries(Request $request): JsonResponse
    {
        $query = Country::query()->withCount('companies');
        $this->search($query, $request, ['name', 'code', 'iso_alpha_2', 'iso_alpha_3']);

        return $this->page($query->orderBy('name'), $request);
    }

    public function showCountry(Country $country): JsonResponse
    {
        return $this->one($country->loadCount('companies'));
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $data = $request->validate($this->countryRules());
        $image = $request->file('image');
        unset($data['image']);
        $country = Country::query()->create([
            ...$this->normaliseCountry($data),
            'is_active' => $data['is_active'] ?? true,
        ]);
        if ($image) {
            $country->update(['image_path' => $this->storeImage($image, 'countries', (string) $country->id)]);
        }

        return response()->json(['message' => 'Country created.', 'data' => $country->fresh()], 201);
    }

    public function updateCountry(Request $request, Country $country): JsonResponse
    {
        $data = $request->validate($this->countryRules($country));
        $image = $request->file('image');
        $oldImagePath = $country->image_path;
        unset($data['image']);
        if ($image) {
            $data['image_path'] = $this->storeImage($image, 'countries', (string) $country->id);
            $data['image_url'] = null;
        }
        $country->update($this->normaliseCountry($data));
        if ($image && $oldImagePath !== $country->image_path) {
            $this->assets->delete($oldImagePath);
        }

        return response()->json(['message' => 'Country updated.', 'data' => $country->fresh()]);
    }

    public function destroyCountry(Country $country): JsonResponse
    {
        if ($country->companies()->exists()) {
            return $this->conflict('Country has companies and cannot be deleted.');
        }
        $imagePath = $country->image_path;
        $country->delete();
        $this->assets->delete($imagePath);

        return response()->json(['message' => 'Country deleted.']);
    }

    public function companies(Request $request): JsonResponse
    {
        $query = Company::query()
            ->with('country')
            ->withCount(['brands', 'complexes', 'shops'])
            ->when($request->filled('country_id'), fn (Builder $query) => $query->where('country_id', $request->input('country_id')));
        $this->search($query, $request, ['company_name', 'company_code', 'city']);

        return $this->page($query->orderBy('company_name'), $request);
    }

    public function showCompany(Company $company): JsonResponse
    {
        return $this->one($company->load('country')->loadCount(['brands', 'complexes', 'shops']));
    }

    public function storeCompany(Request $request): JsonResponse
    {
        $data = $request->validate($this->companyRules());
        $country = Country::query()->findOrFail($data['country_id']);
        $logo = $request->file('logo');
        unset($data['logo']);
        $company = Company::query()->create([
            ...$data,
            'company_code' => $this->availableCompanyCode($data['company_code'] ?? null, $data['company_name']),
            'company_name' => trim($data['company_name']),
            'country_id' => $country->id,
            'country' => $country->name,
            'domain' => isset($data['domain']) && trim((string) $data['domain']) !== ''
                ? strtolower(trim((string) $data['domain']))
                : null,
            'status' => $data['status'] ?? 'active',
        ]);
        if ($logo) {
            $company->update(['logo' => $this->storeImage($logo, 'companies', (string) $company->id)]);
        }

        return response()->json(['message' => 'Company created.', 'data' => $company->fresh('country')], 201);
    }

    public function updateCompany(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate($this->companyRules($company));
        if (isset($data['country_id']) && (string) $data['country_id'] !== (string) $company->country_id) {
            if ($company->brands()->exists() || $company->complexes()->exists() || $company->shops()->exists()) {
                return $this->conflict('A company with brands, complexes or shops cannot be moved to another country.');
            }
            $country = Country::query()->findOrFail($data['country_id']);
            $data['country'] = $country->name;
        }
        $logo = $request->file('logo');
        $oldLogoPath = $company->logo;
        unset($data['logo']);
        if ($logo) {
            $data['logo'] = $this->storeImage($logo, 'companies', (string) $company->id);
        }
        if (isset($data['company_code'])) {
            $data['company_code'] = strtoupper(trim($data['company_code']));
        }
        if (isset($data['company_name'])) {
            $data['company_name'] = trim($data['company_name']);
        }
        if (array_key_exists('domain', $data)) {
            $data['domain'] = trim((string) $data['domain']) !== '' ? strtolower(trim((string) $data['domain'])) : null;
        }
        $company->update($data);
        if ($logo && $oldLogoPath !== $company->logo) {
            $this->assets->delete($oldLogoPath);
        }
        $this->sync->publishCompanySnapshots((string) $company->id);

        return response()->json(['message' => 'Company updated.', 'data' => $company->fresh('country')]);
    }

    public function destroyCompany(Company $company): JsonResponse
    {
        // Deleting a company deletes everything under it.
        $logoPath = $company->logo;
        app(CompanyDeletionService::class)->delete($company);
        $this->assets->delete($logoPath);

        return response()->json(['message' => 'Company deleted.']);
    }

    public function brands(Request $request): JsonResponse
    {
        $query = Brand::query()
            ->with(['company:id,company_name,country_id', 'country'])
            ->withCount('shops')
            ->when($request->filled('company_id'), fn (Builder $query) => $query->where('company_id', $request->input('company_id')));
        $this->search($query, $request, ['name', 'code']);

        return $this->page($query->orderBy('name'), $request);
    }

    public function showBrand(Brand $brand): JsonResponse
    {
        return $this->one($brand->load(['company:id,company_name,country_id', 'country'])->loadCount('shops'));
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate($this->brandRules());
        $company = Company::query()->findOrFail($data['company_id']);
        if (! $company->country_id) {
            return $this->unprocessable('Assign the company to a country before creating a brand.', 'company_id');
        }
        $logo = $request->file('logo');
        unset($data['logo']);
        $brand = Brand::query()->create([
            ...$data,
            'company_id' => $company->id,
            'country_id' => $company->country_id,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'status' => $data['status'] ?? 'active',
        ]);
        if ($logo) {
            $brand->update(['logo_path' => $this->storeImage($logo, 'brands', (string) $brand->id)]);
        }
        $this->sync->publishCompanySnapshots((string) $company->id);

        return response()->json(['message' => 'Brand created.', 'data' => $brand->fresh(['company', 'country'])], 201);
    }

    public function storeBrandForCompany(Request $request, Company $company): JsonResponse
    {
        $request->merge(['company_id' => (string) $company->id]);

        return $this->storeBrand($request);
    }

    public function updateBrand(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate($this->brandRules($brand));
        if (isset($data['company_id']) && (string) $data['company_id'] !== (string) $brand->company_id) {
            return $this->unprocessable('A brand cannot be moved to another company.', 'company_id');
        }
        $logo = $request->file('logo');
        $oldLogoPath = $brand->logo_path;
        unset($data['logo'], $data['company_id']);
        if ($logo) {
            $data['logo_path'] = $this->storeImage($logo, 'brands', (string) $brand->id);
            $data['logo_url'] = null;
        }
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $brand->update($data);
        if ($logo && $oldLogoPath !== $brand->logo_path) {
            $this->assets->delete($oldLogoPath);
        }
        $this->sync->publishCompanySnapshots((string) $brand->company_id);

        return response()->json(['message' => 'Brand updated.', 'data' => $brand->fresh(['company', 'country'])]);
    }

    public function destroyBrand(Brand $brand): JsonResponse
    {
        if ($brand->shops()->exists()) {
            return $this->conflict('Brand has shops and cannot be deleted.');
        }
        $companyId = (string) $brand->company_id;
        $logoPath = $brand->logo_path;
        $brand->delete();
        $this->assets->delete($logoPath);
        $this->sync->publishCompanySnapshots($companyId);

        return response()->json(['message' => 'Brand deleted.']);
    }

    public function complexes(Request $request): JsonResponse
    {
        $query = ComplexLocation::query()
            ->with(['company:id,company_name,country_id', 'country'])
            ->withCount('shops')
            ->when($request->filled('company_id'), fn (Builder $query) => $query->where('company_id', $request->input('company_id')))
            ->when($request->filled('country_id'), fn (Builder $query) => $query->where('country_id', $request->input('country_id')));
        $this->search($query, $request, ['name', 'code', 'city', 'address']);

        return $this->page($query->orderBy('name'), $request);
    }

    public function showComplex(ComplexLocation $complex): JsonResponse
    {
        return $this->one($complex->load(['company:id,company_name,country_id', 'country'])->loadCount('shops'));
    }

    public function storeComplex(Request $request): JsonResponse
    {
        $data = $request->validate($this->complexRules());
        $company = Company::query()->findOrFail($data['company_id']);
        if (! $company->country_id) {
            return $this->unprocessable('Assign the company to a country before creating a complex.', 'company_id');
        }
        $complex = ComplexLocation::query()->create([
            ...$data,
            'company_id' => $company->id,
            'country_id' => $company->country_id,
            'brand_id' => null,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'status' => $data['status'] ?? 'active',
        ]);
        $this->sync->publishCompanySnapshots((string) $company->id);

        return response()->json(['message' => 'Complex created.', 'data' => $complex->fresh(['company', 'country'])], 201);
    }

    public function updateComplex(Request $request, ComplexLocation $complex): JsonResponse
    {
        $data = $request->validate($this->complexRules($complex));
        if (isset($data['company_id']) && (string) $data['company_id'] !== (string) $complex->company_id) {
            return $this->unprocessable('A complex cannot be moved to another company.', 'company_id');
        }
        unset($data['company_id']);
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $complex->update($data);
        $this->sync->publishCompanySnapshots((string) $complex->company_id);

        return response()->json(['message' => 'Complex updated.', 'data' => $complex->fresh(['company', 'country'])]);
    }

    public function destroyComplex(ComplexLocation $complex): JsonResponse
    {
        if ($complex->shops()->exists()) {
            return $this->conflict('Complex has shops and cannot be deleted.');
        }
        $companyId = (string) $complex->company_id;
        $complex->delete();
        $this->sync->publishCompanySnapshots($companyId);

        return response()->json(['message' => 'Complex deleted.']);
    }

    public function shops(Request $request): JsonResponse
    {
        $query = Shop::query()
            ->with(['company:id,company_name,country_id', 'country', 'brand', 'complex'])
            ->withCount('tills')
            ->when($request->filled('company_id'), fn (Builder $query) => $query->where('company_id', $request->input('company_id')))
            ->when($request->filled('brand_id'), fn (Builder $query) => $query->where('brand_id', $request->input('brand_id')))
            ->when($request->filled('complex_id'), fn (Builder $query) => $query->where('complex_id', $request->input('complex_id')));
        $this->search($query, $request, ['name', 'code', 'city', 'address']);

        return $this->page($query->orderBy('name'), $request);
    }

    public function showShop(Shop $shop): JsonResponse
    {
        return $this->one($shop->load(['company', 'country', 'brand', 'complex', 'branch'])->loadCount('tills'));
    }

    public function storeShop(Request $request): JsonResponse
    {
        $data = $request->validate($this->shopRules());
        [$brand, $complex] = $this->shopParents($data['brand_id'], $data['complex_id']);
        if (! $brand || ! $complex) {
            return $this->unprocessable('Brand and complex must belong to the same company and country.', 'complex_id');
        }

        $shop = DB::transaction(function () use ($data, $brand, $complex): Shop {
            $id = (string) Str::uuid();
            $branch = new CompanyBranch;
            $branch->setAttribute('id', $id);
            $branch->forceFill([
                'company_id' => $brand->company_id,
                'branch_name' => trim($data['name']),
                'branch_code' => strtoupper(trim($data['code'])),
                'branch_address' => $data['address'] ?? null,
                'branch_city' => $data['city'] ?? $complex->city,
                'branch_country' => $complex->country?->name,
                'level' => 'shop',
                'is_main_branch' => false,
                'status' => $data['status'] ?? 'active',
            ])->saveQuietly();

            $shop = new Shop;
            $shop->setAttribute('id', $id);
            $shop->forceFill([
                'company_id' => $brand->company_id,
                'country_id' => $brand->country_id,
                'brand_id' => $brand->id,
                'complex_id' => $complex->id,
                'branch_id' => $branch->id,
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? $complex->city,
                'timezone' => $data['timezone'] ?? $complex->timezone,
                'pos_login_mode' => $data['pos_login_mode'] ?? 'code',
                'configuration_version' => 1,
                'status' => $data['status'] ?? 'active',
                'metadata' => $data['metadata'] ?? null,
            ])->save();
            $this->warehouses->ensureBranchDefaults($branch);

            return $shop;
        }, 3);
        $this->sync->publishCompanySnapshots((string) $shop->company_id);

        return response()->json(['message' => 'Shop created.', 'data' => $shop->load(['brand', 'complex', 'branch'])], 201);
    }

    public function storeShopForComplex(Request $request, ComplexLocation $complex): JsonResponse
    {
        $request->merge(['complex_id' => (string) $complex->id]);

        return $this->storeShop($request);
    }

    public function updateShop(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate($this->shopRules($shop));
        $brandId = (string) ($data['brand_id'] ?? $shop->brand_id);
        $complexId = (string) ($data['complex_id'] ?? $shop->complex_id);
        [$brand, $complex] = $this->shopParents($brandId, $complexId);
        if (! $brand || ! $complex || (string) $brand->company_id !== (string) $shop->company_id) {
            return $this->unprocessable('Brand and complex must belong to the shop company and country.', 'complex_id');
        }

        DB::transaction(function () use ($data, $shop, $brand, $complex): void {
            $shop->fill([
                ...$data,
                'brand_id' => $brand->id,
                'complex_id' => $complex->id,
                'country_id' => $brand->country_id,
            ]);
            if (isset($data['code'])) {
                $shop->code = strtoupper(trim($data['code']));
            }
            if (isset($data['name'])) {
                $shop->name = trim($data['name']);
            }
            $shop->configuration_version = ((int) $shop->configuration_version) + 1;
            $shop->save();
            $shop->branch()->update([
                'branch_name' => $shop->name,
                'branch_code' => $shop->code,
                'branch_address' => $shop->address,
                'branch_city' => $shop->city,
                'branch_country' => $complex->country?->name,
                'status' => $shop->status,
            ]);
            PosTill::query()->where('shop_id', $shop->id)->update([
                'country_id' => $shop->country_id,
                'brand_id' => $shop->brand_id,
                'complex_id' => $shop->complex_id,
                'configuration_version' => $shop->configuration_version,
            ]);
        }, 3);
        $this->sync->markBranchDirty((string) $shop->company_id, (string) $shop->branch_id, 'shops');

        return response()->json(['message' => 'Shop updated.', 'data' => $shop->fresh(['brand', 'complex', 'branch'])]);
    }

    public function destroyShop(Shop $shop): JsonResponse
    {
        if ($shop->tills()->exists()) {
            return $this->conflict('Shop has tills and cannot be deleted.');
        }
        DB::transaction(function () use ($shop): void {
            $shop->delete();
            $shop->branch()->delete();
        }, 3);
        $this->sync->markBranchDirty((string) $shop->company_id, (string) $shop->branch_id, 'shops');

        return response()->json(['message' => 'Shop deleted.']);
    }

    public function tills(Request $request): JsonResponse
    {
        $query = PosTill::query()
            ->with(['company:id,company_name,country_id', 'country', 'brand', 'complex', 'shop', 'branch'])
            ->when($request->filled('company_id'), fn (Builder $query) => $query->where('company_id', $request->input('company_id')))
            ->when($request->filled('brand_id'), fn (Builder $query) => $query->where('brand_id', $request->input('brand_id')))
            ->when($request->filled('complex_id'), fn (Builder $query) => $query->where('complex_id', $request->input('complex_id')))
            ->when($request->filled('shop_id'), fn (Builder $query) => $query->where('shop_id', $request->input('shop_id')));
        $this->search($query, $request, ['till_name', 'till_code', 'device_label']);

        return $this->page($query->orderBy('till_name'), $request);
    }

    public function showTill(PosTill $till): JsonResponse
    {
        return $this->one($till->load(['company', 'country', 'brand', 'complex', 'shop', 'branch']));
    }

    public function storeTill(Request $request): JsonResponse
    {
        $data = $request->validate($this->tillRules());
        $shop = Shop::query()->with('company.activeLicense')->findOrFail($data['shop_id']);
        if ($error = $this->validateTillParents($data, $shop)) {
            return $error;
        }
        $license = $shop->company->activeLicense;
        if ($license && PosTill::query()->where('company_id', $shop->company_id)->count() >= (int) $license->max_devices) {
            return $this->conflict('The company device licence limit has been reached.');
        }
        $till = PosTill::query()->create($this->tillValues($data, $shop));
        $this->sync->markBranchDirty((string) $shop->company_id, (string) $shop->branch_id, 'pos_tills');

        return response()->json(['message' => 'Till created.', 'data' => $till->load(['brand', 'complex', 'shop', 'branch'])], 201);
    }

    public function storeTillForShop(Request $request, Shop $shop): JsonResponse
    {
        $request->merge(['shop_id' => (string) $shop->id]);

        return $this->storeTill($request);
    }

    public function updateTill(Request $request, PosTill $till): JsonResponse
    {
        $data = $request->validate($this->tillRules($till));
        $shop = Shop::query()->findOrFail($data['shop_id'] ?? $till->shop_id);
        if ($error = $this->validateTillParents($data, $shop)) {
            return $error;
        }
        $oldCompanyId = (string) $till->company_id;
        $oldBranchId = (string) $till->branch_id;
        $till->update([
            ...$this->tillValues($data, $shop, $till),
            'configuration_version' => max((int) $till->configuration_version, (int) $shop->configuration_version) + 1,
        ]);
        $this->sync->markBranchDirty($oldCompanyId, $oldBranchId, 'pos_tills');
        $this->sync->markBranchDirty((string) $shop->company_id, (string) $shop->branch_id, 'pos_tills');

        return response()->json(['message' => 'Till updated.', 'data' => $till->fresh(['brand', 'complex', 'shop', 'branch'])]);
    }

    public function updateTillForShop(Request $request, Shop $shop, PosTill $till): JsonResponse
    {
        if ((string) $till->shop_id !== (string) $shop->id) {
            abort(404);
        }
        $request->merge(['shop_id' => (string) $shop->id]);

        return $this->updateTill($request, $till);
    }

    public function destroyTill(PosTill $till): JsonResponse
    {
        $companyId = (string) $till->company_id;
        $branchId = (string) $till->branch_id;
        $till->delete();
        $this->sync->markBranchDirty($companyId, $branchId, 'pos_tills');

        return response()->json(['message' => 'Till deleted.']);
    }

    public function destroyTillForShop(Shop $shop, PosTill $till): JsonResponse
    {
        if ((string) $till->shop_id !== (string) $shop->id) {
            abort(404);
        }

        return $this->destroyTill($till);
    }

    private function countryRules(?Country $country = null): array
    {
        return [
            'code' => [$country ? 'sometimes' : 'required', 'string', 'max:16', Rule::unique('countries', 'code')->withoutTrashed()->ignore($country?->id)],
            'iso_alpha_2' => ['nullable', 'string', 'size:2', Rule::unique('countries', 'iso_alpha_2')->withoutTrashed()->ignore($country?->id)],
            'iso_alpha_3' => ['nullable', 'string', 'size:3', Rule::unique('countries', 'iso_alpha_3')->withoutTrashed()->ignore($country?->id)],
            'name' => [$country ? 'sometimes' : 'required', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:20'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
            'image_path' => ['prohibited'],
            'image_url' => ['prohibited'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function companyRules(?Company $company = null): array
    {
        return [
            'country_id' => [$company ? 'sometimes' : 'required', 'uuid', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'company_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', Rule::unique('companies', 'company_code')->withoutTrashed()->ignore($company?->id)],
            'company_name' => [$company ? 'sometimes' : 'required', 'string', 'max:255'],
            'location_address' => [$company ? 'sometimes' : 'required', 'string', 'max:255'],
            'city' => [$company ? 'sometimes' : 'required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'domain')->withoutTrashed()->ignore($company?->id)],
            'host_ip' => ['nullable', 'string', 'max:255'],
            'license_support_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    private function brandRules(?Brand $brand = null): array
    {
        $companyId = request()->input('company_id', $brand?->company_id);

        return [
            'company_id' => [$brand ? 'sometimes' : 'required', 'uuid', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'code' => [
                $brand ? 'sometimes' : 'required', 'string', 'max:80',
                Rule::unique('brands', 'code')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'))->ignore($brand?->id),
            ],
            'name' => [$brand ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
            'logo_path' => ['prohibited'],
            'logo_url' => ['prohibited'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function complexRules(?ComplexLocation $complex = null): array
    {
        $companyId = request()->input('company_id', $complex?->company_id);

        return [
            'company_id' => [$complex ? 'sometimes' : 'required', 'uuid', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'code' => [
                $complex ? 'sometimes' : 'required', 'string', 'max:80',
                Rule::unique('complexes', 'code')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'))->ignore($complex?->id),
            ],
            'name' => [$complex ? 'sometimes' : 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function shopRules(?Shop $shop = null): array
    {
        $companyId = $shop?->company_id;
        if (! $companyId && request()->filled('brand_id')) {
            $companyId = Brand::query()->whereKey(request()->input('brand_id'))->value('company_id');
        }

        return [
            'brand_id' => [$shop ? 'sometimes' : 'required', 'uuid', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'complex_id' => [$shop ? 'sometimes' : 'required', 'uuid', Rule::exists('complexes', 'id')->whereNull('deleted_at')],
            'code' => [
                $shop ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('shops', 'code')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'))->ignore($shop?->id),
            ],
            'name' => [$shop ? 'sometimes' : 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'pos_login_mode' => ['nullable', Rule::in(['code', 'username_password', 'both'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function tillRules(?PosTill $till = null): array
    {
        $shopId = request()->input('shop_id', $till?->shop_id);
        $shop = $shopId ? Shop::query()->find($shopId) : null;

        return [
            'shop_id' => [$till ? 'sometimes' : 'required', 'uuid', Rule::exists('shops', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', 'uuid', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'complex_id' => ['nullable', 'uuid', Rule::exists('complexes', 'id')->whereNull('deleted_at')],
            'till_code' => [
                $till ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('pos_tills', 'till_code')->where(fn ($query) => $query
                    ->where('company_id', $shop?->company_id)
                    ->where('branch_id', $shop?->branch_id)
                    ->whereNull('deleted_at'))->ignore($till?->id),
            ],
            'till_name' => [$till ? 'sometimes' : 'required', 'string', 'max:255'],
            'device_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Brands and complexes are created in one of the company's operating
     * countries; without a requested country the head-office country is used.
     * Returns null when the company does not operate in the requested country.
     */
    private function operatingCountryId(Company $company, ?string $requestedCountryId): ?string
    {
        $homeCountryId = trim((string) $company->country_id);
        $countryId = trim((string) ($requestedCountryId ?: $homeCountryId));
        if ($countryId === '') {
            return null;
        }
        if ($countryId === $homeCountryId) {
            $this->registerOperatingCountry($company, $countryId, true);

            return $countryId;
        }
        if (! Schema::hasTable('company_countries')) {
            return null;
        }

        return CompanyCountry::query()
            ->where('company_id', $company->id)
            ->where('country_id', $countryId)
            ->where('status', 'active')
            ->exists() ? $countryId : null;
    }

    private function registerOperatingCountry(Company $company, string $countryId, bool $home = false): ?CompanyCountry
    {
        if (! Schema::hasTable('company_countries')) {
            return null;
        }

        $row = CompanyCountry::query()->firstOrCreate(
            ['company_id' => (string) $company->id, 'country_id' => $countryId],
            ['is_home' => $home, 'status' => 'active']
        );
        if ($home && ! $row->is_home) {
            CompanyCountry::query()->where('company_id', $company->id)->update(['is_home' => false]);
            $row->update(['is_home' => true, 'status' => 'active']);
        }

        return $row;
    }

    /** @return array{brands: int, complexes: int, shops: int} */
    private function countryFootprint(Company $company, string $countryId): array
    {
        return [
            'brands' => $company->brands()->where('country_id', $countryId)->count(),
            'complexes' => $company->complexes()->where('country_id', $countryId)->count(),
            'shops' => $company->shops()->where('country_id', $countryId)->count(),
        ];
    }

    /** @return array{0: ?Brand, 1: ?ComplexLocation} */
    private function shopParents(string $brandId, string $complexId): array
    {
        $brand = Brand::query()->find($brandId);
        $complex = ComplexLocation::query()->with('country')->find($complexId);
        if (! $brand || ! $complex
            || (string) $brand->company_id !== (string) $complex->company_id
            || (string) $brand->country_id !== (string) $complex->country_id) {
            return [null, null];
        }

        return [$brand, $complex];
    }

    private function validateTillParents(array $data, Shop $shop): ?JsonResponse
    {
        if ((isset($data['brand_id']) && (string) $data['brand_id'] !== (string) $shop->brand_id)
            || (isset($data['complex_id']) && (string) $data['complex_id'] !== (string) $shop->complex_id)) {
            return $this->unprocessable('Till brand and complex must exactly match its selected shop.', 'shop_id');
        }

        return null;
    }

    private function tillValues(array $data, Shop $shop, ?PosTill $till = null): array
    {
        return [
            'company_id' => $shop->company_id,
            'country_id' => $shop->country_id,
            'brand_id' => $shop->brand_id,
            'complex_id' => $shop->complex_id,
            'branch_id' => $shop->branch_id,
            'shop_id' => $shop->id,
            'till_code' => isset($data['till_code']) ? strtoupper(trim($data['till_code'])) : $till?->till_code,
            'till_name' => isset($data['till_name']) ? trim($data['till_name']) : $till?->till_name,
            'device_label' => array_key_exists('device_label', $data) ? $data['device_label'] : $till?->device_label,
            'is_registered' => $till?->is_registered ?? false,
            'is_active' => $data['is_active'] ?? $till?->is_active ?? true,
            'configuration_version' => $till?->configuration_version ?? $shop->configuration_version,
        ];
    }

    private function normaliseCountry(array $data): array
    {
        foreach (['code', 'iso_alpha_2', 'iso_alpha_3', 'currency_code'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strtoupper(trim($data[$field]));
            }
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    private function storeImage($image, string $resource, string $id): string
    {
        $relativeDirectory = "hierarchy/{$resource}/{$id}";
        $directory = $this->assets->ensureDirectory($relativeDirectory);
        $extension = strtolower((string) ($image->guessExtension() ?: $image->getClientOriginalExtension()));
        $fileName = 'image-'.Str::uuid().'.'.$extension;
        $image->move($directory, $fileName);
        $this->assets->hardenFile($directory.DIRECTORY_SEPARATOR.$fileName);

        return 'assets/'.$relativeDirectory.'/'.$fileName;
    }

    private function availableCompanyCode(?string $requested, string $name): string
    {
        $base = strtoupper(trim((string) $requested));
        if ($base === '') {
            $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
        }
        $base = substr($base !== '' ? $base : 'COMPANY', 0, 32);
        $candidate = $base;
        $suffix = 1;
        while (Company::withTrashed()->where('company_code', $candidate)->exists()) {
            $tail = '-'.$suffix++;
            $candidate = substr($base, 0, 40 - strlen($tail)).$tail;
        }

        return $candidate;
    }

    private function search(Builder $query, Request $request, array $columns): void
    {
        if (! $request->filled('q')) {
            return;
        }
        $term = trim((string) $request->input('q'));
        $query->where(function (Builder $scope) use ($columns, $term): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $scope->where($column, 'like', "%{$term}%")
                    : $scope->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    private function page(Builder $query, Request $request): JsonResponse
    {
        return response()->json(['data' => $query->paginate($this->perPage($request))]);
    }

    private function one($resource): JsonResponse
    {
        return response()->json(['data' => $resource]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 50), 1), 100);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'ENTERPRISE_HIERARCHY_CONFLICT'], 409);
    }

    private function unprocessable(string $message, string $field): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
            'code' => 'ENTERPRISE_HIERARCHY_INVALID',
        ], 422);
    }
}
