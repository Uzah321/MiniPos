<?php

namespace Modules\Company\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Company\CompanyDeletionService;
use App\Services\CompanySettingService;
use App\Services\Inventory\InventoryWarehouseProvisioner;
use App\Services\License\LicenseAccessService;
use App\Services\License\LicenseService;
use App\Services\PublicAssetStorage;
use App\Services\Sales\DiscountTypeProvisioner;
use App\Services\Settings\GeneralSettingsProvisioner;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\CompanyBranchContact;
use Modules\Company\Models\Country;
use Modules\Company\Models\License;

use function App\Helpers\hasPermission;

class CompanyController extends Controller
{
    protected LicenseService $licenseService;

    public function __construct(
        LicenseService $licenseService,
        private readonly LicenseAccessService $licenseAccess,
    ) {
        $this->licenseService = $licenseService;
    }

    public function index(Request $request)
    {
        $companies = Company::with(['country', 'brands', 'branches.contacts', 'activeLicense'])
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Companies fetched successfully',
            'data' => $companies,
        ]);
    }

    public function store(Request $request)
    {
        //        $company = app('company');
        //        $result = hasPermission(auth()->user()->role_id, 'create_company',$company->id);
        //        if ($result !== true) return $result;

        if (! $request->has('branches') && is_array($request->input('initial_branch'))) {
            $request->merge(['branches' => [$request->input('initial_branch')]]);
        }
        if (! $request->has('license') && is_array($request->input('initial_license'))) {
            $request->merge(['license' => $request->input('initial_license')]);
        }

        if ($request->filled('license.license_key')) {
            try {
                $request->merge([
                    'license' => [
                        ...$request->input('license', []),
                        'license_key' => $this->licenseService->normalizeLicenseKey(
                            (string) $request->input('license.license_key')
                        ),
                    ],
                ]);
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => ['license.license_key' => [$e->getMessage()]],
                ], 422);
            }
        }

        $logoRules = ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'];

        $validator = Validator::make($request->all(), [
            'company_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', Rule::unique('companies', 'company_code')->withoutTrashed()],
            'company_name' => 'required|string|max:255',
            'country_id' => ['required', 'uuid', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'location_address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'country' => 'nullable|string|max:255',
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'domain')->withoutTrashed()],
            'host_ip' => 'nullable|string|max:255',
            'license_support_phone' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,inactive,suspended',
            'logo' => $logoRules,

            'license.license_key' => ['nullable', 'string', 'regex:'.LicenseService::KEY_PATTERN, 'unique:licenses,license_key'],
            'license.license_type' => 'required|string|in:trial,standard,enterprise',
            'license.max_branches' => 'required|integer|min:1',
            'license.max_users' => 'required|integer|min:1',
            'license.max_devices' => 'required|integer|min:1',
            'license.starts_at' => 'nullable|date',
            'license.expires_at' => 'nullable|date|after_or_equal:today|after_or_equal:license.starts_at',

            'branches' => 'required|array|min:1',
            'branches.*.branch_name' => 'required|string|max:255',
            'branches.*.branch_code' => 'nullable|string|max:100',
            'branches.*.branch_address' => 'nullable|string|max:255',
            'branches.*.branch_city' => 'nullable|string|max:255',
            'branches.*.branch_country' => 'nullable|string|max:255',
            'branches.*.level' => 'nullable|string|max:255',
            'branches.*.is_main_branch' => 'nullable|boolean',

            'branches.*.contacts' => 'nullable|array',
            'branches.*.contacts.*.contact_name' => 'nullable|string|max:255',
            'branches.*.contacts.*.email' => 'nullable|email|max:255',
            'branches.*.contacts.*.phone' => 'nullable|string|max:50',
            'branches.*.contacts.*.position' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }
        if ($request->filled('country_id')) {
            $request->merge([
                'country' => Country::query()->findOrFail($request->input('country_id'))->name,
            ]);
        }

        if (count($request->input('branches', [])) > (int) $request->input('license.max_branches')) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => ['branches' => ['Initial branches cannot exceed the license branch limit.']],
            ], 422);
        }

        DB::beginTransaction();
        $uploadedLogoPath = null;

        try {
            $logoPath = null;

            if ($request->hasFile('logo')) {
                $companyNameSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $request->company_name);
                $countrySafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $request->country);

                // Logos share the same public asset root used by product and
                // category images. On Linux this is /var/www/salexapos/assets;
                // keeping it outside the Laravel app also matches the Nginx
                // /assets/ alias and the XAMPP project layout.
                $assets = app(PublicAssetStorage::class);
                $basePath = $assets->ensureDirectory(
                    "logos/images/{$companyNameSafe}_{$countrySafe}"
                );

                $logo = $request->file('logo');
                $logoFileName = 'logo_'.Str::uuid().'.'.strtolower($logo->guessExtension() ?: $logo->getClientOriginalExtension());
                $logo->move($basePath, $logoFileName);

                $assets->hardenFile($basePath.DIRECTORY_SEPARATOR.$logoFileName);

                $logoPath = "assets/logos/images/{$companyNameSafe}_{$countrySafe}/{$logoFileName}";
                $uploadedLogoPath = $logoPath;
            }

            $company = Company::create([
                'company_code' => $this->availableCompanyCode(
                    $request->input('company_code'),
                    (string) $request->company_name
                ),
                'company_name' => $request->company_name,
                'country_id' => $request->input('country_id'),
                'location_address' => $request->location_address,
                'city' => $request->city,
                'country' => $request->country,
                'domain' => $request->filled('domain') ? strtolower(trim((string) $request->domain)) : null,
                'host_ip' => $request->host_ip,
                'license_support_phone' => $request->license_support_phone,
                'logo' => $logoPath,
                'status' => $request->status ?? 'active',
            ]);

            $this->licenseService->createLicense($company, [
                'license_key' => $request->license['license_key'] ?? null,
                'license_type' => $request->license['license_type'],
                'max_branches' => $request->license['max_branches'],
                'max_users' => $request->license['max_users'],
                'max_devices' => $request->license['max_devices'],
                'starts_at' => $request->license['starts_at'] ?? now(),
                'expires_at' => $request->license['expires_at'] ?? now()->addYear(),
                'status' => 'active',
                'renewal_source' => 'initial',
            ]);

            app(DiscountTypeProvisioner::class)->ensureCompanyDefaults((string) $company->id);
            app(GeneralSettingsProvisioner::class)->ensureCompanyDefaults((string) $company->id);

            foreach ($request->branches as $index => $branchData) {
                $branch = CompanyBranch::create([
                    'company_id' => $company->id,
                    'branch_name' => $branchData['branch_name'],
                    'branch_code' => $branchData['branch_code'] ?? null,
                    'branch_address' => $branchData['branch_address'] ?? null,
                    'branch_city' => $branchData['branch_city'] ?? null,
                    'branch_country' => $branchData['branch_country'] ?? null,
                    'level' => $branchData['level'] ?? null,
                    'is_main_branch' => $branchData['is_main_branch'] ?? $index === 0,
                    'status' => 'active',
                ]);

                app(InventoryWarehouseProvisioner::class)->ensureBranchDefaults($branch);

                foreach ($branchData['contacts'] ?? [] as $contactData) {
                    CompanyBranchContact::create([
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'contact_name' => $contactData['contact_name'] ?? null,
                        'email' => $contactData['email'] ?? null,
                        'phone' => $contactData['phone'] ?? null,
                        'position' => $contactData['position'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Company created successfully',
                'company' => $company->load(['country', 'brands', 'shops', 'branches.contacts', 'activeLicense']),
                'license' => $company->activeLicense,
                'server_date' => now()->toDateString(),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            app(PublicAssetStorage::class)->delete($uploadedLogoPath);

            return response()->json([
                'message' => 'Something went wrong while creating the company.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Company $company)
    {
        return response()->json([
            'message' => 'Company fetched successfully',
            'company' => $company->load(['country', 'brands', 'shops', 'branches.contacts', 'licenses', 'activeLicense']),
            'server_date' => now()->toDateString(),
            'license' => $this->licenseAccess->status($company, request()->user()),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $logoRules = ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'];

        $validator = Validator::make($request->all(), [
            'company_code' => [
                'sometimes', 'required', 'string', 'max:40',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('companies', 'company_code')->withoutTrashed()->ignore($company->id),
            ],
            'company_name' => 'sometimes|required|string|max:255',
            'country_id' => ['sometimes', 'required', 'uuid', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'location_address' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
            'domain' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('companies', 'domain')->withoutTrashed()->ignore($company->id)],
            'host_ip' => 'nullable|string|max:255',
            'license_support_phone' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,inactive,suspended',
            'logo' => $logoRules,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        if ($request->exists('country_id')
            && (string) $request->input('country_id') !== (string) $company->country_id
            && ($company->brands()->withTrashed()->exists()
                || $company->complexes()->withTrashed()->exists()
                || $company->shops()->withTrashed()->exists())) {
            return response()->json([
                'message' => 'A company with brands, complexes or shops cannot be moved to another country.',
                'code' => 'ENTERPRISE_HIERARCHY_CONFLICT',
            ], 409);
        }

        $data = $request->only([
            'company_code',
            'company_name',
            'country_id',
            'location_address',
            'city',
            'country',
            'domain',
            'host_ip',
            'license_support_phone',
            'status',
            'logo',
        ]);

        if (array_key_exists('company_code', $data)) {
            $data['company_code'] = strtoupper(trim((string) $data['company_code']));
        }
        if (array_key_exists('domain', $data)) {
            $data['domain'] = $request->filled('domain') ? strtolower(trim((string) $data['domain'])) : null;
        }
        if (! empty($data['country_id'])) {
            $data['country'] = Country::query()->findOrFail($data['country_id'])->name;
        }

        $oldLogoPath = $company->logo;
        if ($request->hasFile('logo')) {
            $companyNameSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $request->company_name ?? $company->company_name);
            $countrySafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $request->country ?? $company->country);

            $assets = app(PublicAssetStorage::class);
            $basePath = $assets->ensureDirectory(
                "logos/images/{$companyNameSafe}_{$countrySafe}"
            );

            $logo = $request->file('logo');
            $logoFileName = 'logo_'.Str::uuid().'.'.strtolower($logo->guessExtension() ?: $logo->getClientOriginalExtension());
            $logo->move($basePath, $logoFileName);

            $assets->hardenFile($basePath.DIRECTORY_SEPARATOR.$logoFileName);

            $data['logo'] = "assets/logos/images/{$companyNameSafe}_{$countrySafe}/{$logoFileName}";
        }

        $company->update($data);
        if ($request->hasFile('logo') && $oldLogoPath !== $company->logo) {
            app(PublicAssetStorage::class)->delete($oldLogoPath);
        }
        if (strtolower((string) $company->status) !== 'active') {
            $this->licenseAccess->revokeNonPlatformAdministratorTokens($company);
        }

        return response()->json([
            'message' => 'Company updated successfully',
            'company' => $company->fresh()->load(['branches.contacts', 'activeLicense']),
            'license' => $this->licenseAccess->status($company->fresh(), request()->user()),
            'server_date' => now()->toDateString(),
        ]);
    }

    public function destroy(Company $company)
    {
        $logoPath = $company->logo;
        app(CompanyDeletionService::class)->delete($company);
        app(PublicAssetStorage::class)->delete($logoPath);

        return response()->json([
            'message' => 'Company deleted successfully',
        ]);
    }

    public function currentCompany(
        Request $request,
        BusinessModeBranchScopeService $branchScope,
        CompanySettingService $settings
    ) {
        $company = app('company');
        $user = $request->user('sanctum') ?: $request->user();
        $workspaceBranchId = is_scalar($request->header('X-Branch-ID'))
            ? trim((string) $request->header('X-Branch-ID'))
            : '';

        $modeScoped = $user && $workspaceBranchId !== '';
        if ($modeScoped) {
            // This route also serves unauthenticated login/bootstrap discovery.
            // Once a valid Sanctum identity and workspace branch are present,
            // operational consumers must only receive accessible branches in
            // that workspace's business mode.
            $request->setUserResolver(fn () => $user);
            $scope = $branchScope->resolve($request, (string) $company->id);
            $branches = $company->branches()
                ->with('contacts')
                ->whereIn('id', $scope['branch_ids'])
                ->orderByDesc('is_main_branch')
                ->orderBy('branch_name')
                ->get()
                ->each(fn ($branch) => $branch->setAttribute(
                    'business_mode',
                    $settings->businessMode((string) $company->id, (string) $branch->id)
                ));

            $company->setRelation('branches', $branches);
        }

        $modeScoped
            ? $company->loadMissing('activeLicense')
            : $company->load(['branches.contacts', 'activeLicense']);
        $licenseStatus = $this->licenseAccess->status($company, $user);
        $companyPayload = $company->toArray();
        // This endpoint is used before login. Return the calculated lifecycle
        // status below, but never disclose the activation key or signed raw
        // entitlement through the embedded active-license relationship.
        unset($companyPayload['active_license']);

        return response()->json([
            'message' => 'Company fetched successfully',
            'company' => $companyPayload,
            'server_date' => now()->toDateString(),
            'license' => $licenseStatus,
        ]);
    }

    public function getResolvedCompanyBranches(
        Request $request,
        BusinessModeBranchScopeService $branchScope,
        CompanySettingService $settings
    ) {
        $company = app('company');
        $user = $request->user();
        $companyBranchIds = $company->branches()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $accessibleBranchIds = $branchScope->accessibleBranchIds($user, (string) $company->id, $companyBranchIds);

        if ($accessibleBranchIds === []) {
            return response()->json([
                'message' => 'No branches are allocated to this user.',
                'code' => 'BRANCH_ACCESS_DENIED',
            ], 403);
        }

        $validatedBranchId = is_scalar($request->attributes->get('company_branch_id'))
            ? trim((string) $request->attributes->get('company_branch_id'))
            : '';
        $userBranchId = is_scalar($user?->branch_id)
            ? trim((string) $user->branch_id)
            : '';
        $currentCompanyUserBranchId = in_array($userBranchId, $companyBranchIds, true)
            ? $userBranchId
            : null;

        // A platform owner can switch company context without rewriting their
        // own home branch. Never let that stale branch turn this company's
        // branch-list request into an invalid workspace anchor.
        $hasWorkspaceAnchor = collect([
            $validatedBranchId,
            $request->header('X-Branch-ID'),
            $request->input('branch_id'),
            app()->bound('till') ? app('till')?->branch_id : null,
            $currentCompanyUserBranchId,
        ])->contains(fn ($value) => is_scalar($value) && trim((string) $value) !== '');

        $businessMode = null;
        if ($hasWorkspaceAnchor) {
            if ($validatedBranchId !== '') {
                $request->attributes->set('business_mode_branch_id', $validatedBranchId);
            }
            $scope = $branchScope->resolve(
                $request,
                (string) $company->id,
                []
            );
            $accessibleBranchIds = $scope['branch_ids'];
            $businessMode = $scope['business_mode'];
        }

        $branches = $company->branches()
            ->with('contacts')
            ->whereIn('id', $accessibleBranchIds)
            ->orderByDesc('is_main_branch')
            ->orderBy('branch_name')
            ->get()
            ->each(fn ($branch) => $branch->setAttribute(
                'business_mode',
                $settings->businessMode((string) $company->id, (string) $branch->id)
            ));

        return response()->json([
            'message' => 'Branches fetched successfully',
            'business_mode' => $businessMode,
            'branches' => $branches,
        ]);
    }

    public function storeBranch(Request $request, Company $company)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }
        if ($blocked = $this->localHubBranchIdentityUnavailable()) {
            return $blocked;
        }

        $license = $company->activeLicense;

        if (! $license || ! $this->licenseService->isValid($license)) {
            return response()->json([
                'message' => 'Company has no valid active license',
            ], 403);
        }

        if ($company->branches()->count() >= $license->max_branches) {
            return response()->json([
                'message' => 'Branch limit reached for this license',
            ], 403);
        }

        return $this->createBranch($request, $company);
    }

    public function getCompanyBranches(Company $company)
    {
        return response()->json([
            'message' => 'Company branches fetched successfully',
            'data' => $company->branches()->with('contacts')->orderByDesc('is_main_branch')->orderBy('branch_name')->get(),
        ]);
    }

    public function storePlatformBranch(Request $request, Company $company)
    {
        if ($blocked = $this->localHubBranchIdentityUnavailable()) {
            return $blocked;
        }
        $license = $company->licenses()->latest('created_at')->first();
        if (! $license) {
            return response()->json([
                'message' => 'Assign a license before creating branches.',
                'code' => 'LICENSE_MISSING',
            ], 422);
        }

        if ($company->branches()->count() >= (int) $license->max_branches) {
            return response()->json([
                'message' => 'Branch limit reached for this license.',
                'code' => 'LICENSE_BRANCH_LIMIT_REACHED',
            ], 422);
        }

        return $this->createBranch($request, $company);
    }

    public function updatePlatformBranch(Request $request, Company $company, CompanyBranch $branch)
    {
        if ((string) $branch->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        return $this->updateBranch($request, $branch);
    }

    public function destroyPlatformBranch(Company $company, CompanyBranch $branch)
    {
        if ((string) $branch->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        return $this->destroyBranch($branch);
    }

    public function storeBranchForResolvedCompany(Request $request)
    {
        if ($blocked = $this->localHubBranchIdentityUnavailable()) {
            return $blocked;
        }
        $company = app('company');
        $license = app('license');

        if ($company->branches()->count() >= $license->max_branches) {
            return response()->json([
                'message' => 'Branch limit reached for this license',
            ], 403);
        }

        return $this->createBranch($request, $company);
    }

    private function createBranch(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'branch_name' => 'required|string|max:255',
            'branch_code' => 'nullable|string|max:100',
            'branch_address' => 'nullable|string|max:255',
            'branch_city' => 'nullable|string|max:255',
            'branch_country' => 'nullable|string|max:255',
            'level' => 'nullable|string|max:255',
            'is_main_branch' => 'nullable|boolean',
            'contacts' => 'nullable|array',
            'contacts.*.contact_name' => 'nullable|string|max:255',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.phone' => 'nullable|string|max:50',
            'contacts.*.position' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $branch = CompanyBranch::create([
                'company_id' => $company->id,
                'branch_name' => $request->branch_name,
                'branch_code' => $request->branch_code,
                'branch_address' => $request->branch_address,
                'branch_city' => $request->branch_city,
                'branch_country' => $request->branch_country,
                'level' => $request->level,
                'is_main_branch' => $request->is_main_branch ?? false,
                'status' => 'active',
            ]);

            app(InventoryWarehouseProvisioner::class)->ensureBranchDefaults($branch);

            foreach ($request->contacts ?? [] as $contactData) {
                CompanyBranchContact::create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'contact_name' => $contactData['contact_name'] ?? null,
                    'email' => $contactData['email'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'position' => $contactData['position'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Branch created successfully',
                'branch' => $branch->load('contacts'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating branch.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateBranch(Request $request, CompanyBranch $branch)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $branch->company_id) {
            return $this->tenantResourceNotFound();
        }
        if ($blocked = $this->localHubBranchIdentityUnavailable()) {
            return $blocked;
        }

        $validator = Validator::make($request->all(), [
            'branch_name' => 'sometimes|required|string|max:255',
            'branch_code' => 'nullable|string|max:100',
            'branch_address' => 'nullable|string|max:255',
            'branch_city' => 'nullable|string|max:255',
            'branch_country' => 'nullable|string|max:255',
            'level' => 'nullable|string|max:255',
            'is_main_branch' => 'nullable|boolean',
            'status' => 'nullable|string|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        $branch->update($request->only([
            'branch_name',
            'branch_code',
            'branch_address',
            'branch_city',
            'branch_country',
            'level',
            'is_main_branch',
            'status',
        ]));

        return response()->json([
            'message' => 'Branch updated successfully',
            'branch' => $branch->fresh()->load('contacts'),
        ]);
    }

    public function destroyBranch(CompanyBranch $branch)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $branch->company_id) {
            return $this->tenantResourceNotFound();
        }
        if ($blocked = $this->localHubBranchIdentityUnavailable()) {
            return $blocked;
        }

        $branch->delete();

        return response()->json([
            'message' => 'Branch deleted successfully',
        ]);
    }

    private function localHubBranchIdentityUnavailable(): ?JsonResponse
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud'
            || ! (bool) config('salexa_sync.local_hub', false)) {
            return null;
        }

        return response()->json([
            'message' => 'Branch identities are managed in Central. Import a fresh Central-signed provisioning package to update this local Back Office.',
            'code' => 'LOCAL_HUB_BRANCH_CENTRAL_MANAGED',
        ], 409);
    }

    public function storeBranchContact(Request $request, CompanyBranch $branch)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $branch->company_id) {
            return $this->tenantResourceNotFound();
        }

        $validator = Validator::make($request->all(), [
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        $contact = CompanyBranchContact::create([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'contact_name' => $request->contact_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'position' => $request->position,
        ]);

        return response()->json([
            'message' => 'Branch contact created successfully',
            'contact' => $contact,
        ], 201);
    }

    public function updateBranchContact(Request $request, CompanyBranchContact $contact)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $contact->company_id) {
            return $this->tenantResourceNotFound();
        }

        $validator = Validator::make($request->all(), [
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        $contact->update($request->only([
            'contact_name',
            'email',
            'phone',
            'position',
        ]));

        return response()->json([
            'message' => 'Branch contact updated successfully',
            'contact' => $contact,
        ]);
    }

    public function destroyBranchContact(CompanyBranchContact $contact)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $contact->company_id) {
            return $this->tenantResourceNotFound();
        }

        $contact->delete();

        return response()->json([
            'message' => 'Branch contact deleted successfully',
        ]);
    }

    public function storePlatformBranchContact(Request $request, Company $company, CompanyBranch $branch)
    {
        if ((string) $branch->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        return $this->storeBranchContact($request, $branch);
    }

    public function updatePlatformBranchContact(
        Request $request,
        Company $company,
        CompanyBranch $branch,
        CompanyBranchContact $contact
    ) {
        if ((string) $branch->company_id !== (string) $company->id
            || (string) $contact->branch_id !== (string) $branch->id
            || (string) $contact->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        return $this->updateBranchContact($request, $contact);
    }

    public function destroyPlatformBranchContact(
        Company $company,
        CompanyBranch $branch,
        CompanyBranchContact $contact
    ) {
        if ((string) $branch->company_id !== (string) $company->id
            || (string) $contact->branch_id !== (string) $branch->id
            || (string) $contact->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        return $this->destroyBranchContact($contact);
    }

    public function getLicenses(Request $request)
    {
        $licenses = License::with('company')
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Licenses fetched successfully',
            'data' => $licenses,
        ]);
    }

    public function getCompanyLicenses(Company $company)
    {
        if (app()->bound('company') && (string) app('company')->id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        return response()->json([
            'message' => 'Company licenses fetched successfully',
            'company' => $company->load('licenses'),
            'data' => $company->licenses()->latest('created_at')->get(),
        ]);
    }

    public function platformLicenseStatus(Company $company)
    {
        $status = $this->licenseAccess->status($company, request()->user());

        return response()->json([
            ...$this->licenseAccess->responsePayload($status),
            'company' => $company,
        ]);
    }

    public function runtimeLicenseStatus()
    {
        $status = app('license.status');

        return response()->json($this->licenseAccess->responsePayload($status));
    }

    public function assignLicense(Request $request, Company $company)
    {
        return $this->renewLicense($request, $company);
    }

    public function renewLicense(Request $request, Company $company)
    {
        if ($request->filled('license_key')) {
            try {
                $request->merge([
                    'license_key' => $this->licenseService->normalizeLicenseKey((string) $request->license_key),
                ]);
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => ['license_key' => [$e->getMessage()]],
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'license_key' => ['nullable', 'string', 'regex:'.LicenseService::KEY_PATTERN, 'unique:licenses,license_key'],
            'license_type' => 'required|string|in:trial,standard,enterprise',
            'max_branches' => 'required|integer|min:1',
            'max_users' => 'required|integer|min:1',
            'max_devices' => 'required|integer|min:1',
            'starts_at' => 'nullable|date|before_or_equal:today',
            'expires_at' => 'required|date|after_or_equal:today|after_or_equal:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        if ($company->branches()->count() > (int) $request->max_branches) {
            return response()->json([
                'message' => 'Validation error',
                'data' => ['max_branches' => ['The branch limit cannot be lower than the current branch count.']],
            ], 422);
        }

        $activeUsers = PosUser::where('company_id', $company->id)
            ->where('is_active', true)
            ->count();
        if ($activeUsers > (int) $request->max_users) {
            return response()->json([
                'message' => 'Validation error',
                'data' => ['max_users' => ['The user limit cannot be lower than the current active user count.']],
            ], 422);
        }

        DB::beginTransaction();

        try {
            $license = $this->licenseService->renewLicense($company, [
                'license_key' => $request->license_key,
                'license_type' => $request->license_type,
                'max_branches' => $request->max_branches,
                'max_users' => $request->max_users,
                'max_devices' => $request->max_devices,
                'starts_at' => $request->starts_at ?? now(),
                'expires_at' => $request->expires_at,
                'renewal_source' => 'manual',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'License renewed successfully',
                'license' => $license,
                'license_status' => $this->licenseAccess->status($company->fresh(), $request->user()),
                'server_date' => now()->toDateString(),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while renewing the license.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePlatformLicense(Request $request, Company $company, License $license)
    {
        if ((string) $license->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        if ($request->filled('license_key')) {
            try {
                $request->merge([
                    'license_key' => $this->licenseService->normalizeLicenseKey((string) $request->license_key),
                ]);
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => ['license_key' => [$e->getMessage()]],
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'license_key' => ['sometimes', 'string', 'regex:'.LicenseService::KEY_PATTERN, Rule::unique('licenses', 'license_key')->ignore($license->id)],
            'license_type' => 'sometimes|required|string|in:trial,standard,enterprise',
            'max_branches' => 'sometimes|required|integer|min:1',
            'max_users' => 'sometimes|required|integer|min:1',
            'max_devices' => 'sometimes|required|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'sometimes|required|date|after_or_equal:starts_at',
            'status' => 'sometimes|required|string|in:active,inactive,expired,revoked',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $effectiveStatus = $data['status'] ?? $license->status;
        $effectiveStartsAt = $data['starts_at'] ?? $license->starts_at;
        $effectiveExpiresAt = $data['expires_at'] ?? $license->expires_at;
        if ($effectiveStatus === 'active'
            && $effectiveStartsAt
            && now()->startOfDay()->lt(Carbon::parse($effectiveStartsAt)->startOfDay())) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => ['starts_at' => ['An active license cannot start in the future.']],
            ], 422);
        }
        if ($effectiveStartsAt
            && $effectiveExpiresAt
            && Carbon::parse($effectiveExpiresAt)->startOfDay()
                ->lt(Carbon::parse($effectiveStartsAt)->startOfDay())) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => ['expires_at' => ['The expiry date cannot be before the license start date.']],
            ], 422);
        }

        if (($data['status'] ?? null) === 'revoked') {
            $data['revoked_at'] = now();
        } elseif (($data['status'] ?? null) === 'active') {
            $data['revoked_at'] = null;
            $data['activated_at'] = $license->activated_at ?: now();
        }

        $updated = $this->licenseService->updateLicense($license, $data);
        if (($data['status'] ?? null) === 'active') {
            $this->licenseService->expireActiveLicenses($company, (string) $updated->id);
        }

        $status = $this->licenseAccess->status($company->fresh(), $request->user());
        if (! $status['valid'] && ! $status['bypass']) {
            $this->licenseAccess->revokeNonPlatformAdministratorTokens($company);
        }

        return response()->json([
            'message' => 'License updated successfully',
            'license' => $updated,
            'license_status' => $status,
            'server_date' => now()->toDateString(),
        ]);
    }

    public function revokePlatformLicense(Request $request, Company $company, License $license)
    {
        if ((string) $license->company_id !== (string) $company->id) {
            return $this->tenantResourceNotFound();
        }

        $license = $this->licenseService->updateLicense($license, [
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
        $this->licenseAccess->revokeNonPlatformAdministratorTokens($company);

        return response()->json([
            'message' => 'License revoked successfully',
            'license' => $license,
            'license_status' => $this->licenseAccess->status($company->fresh(), $request->user()),
            'server_date' => now()->toDateString(),
        ]);
    }

    public function generateOfflineLicense(Request $request, Company $company)
    {
        if ($request->filled('license_key')) {
            try {
                $request->merge([
                    'license_key' => $this->licenseService->normalizeLicenseKey((string) $request->license_key),
                ]);
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => ['license_key' => [$e->getMessage()]],
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'license_key' => ['nullable', 'string', 'regex:'.LicenseService::KEY_PATTERN, 'unique:licenses,license_key'],
            'license_type' => 'required|string|in:trial,standard,enterprise',
            'max_branches' => 'required|integer|min:1',
            'max_users' => 'required|integer|min:1',
            'max_devices' => 'required|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'required|date|after_or_equal:today|after_or_equal:starts_at',
            'include_legacy_token' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $license = $this->licenseService->createLicense($company, [
                ...$validator->validated(),
                'status' => 'inactive',
                'renewal_source' => 'offline_key',
                'activated_at' => null,
            ]);
            $legacyToken = $request->boolean('include_legacy_token')
                ? $this->licenseService->generateOfflineLicenseToken($company, $request->all(), $license)
                : null;

            return response()->json([
                'message' => 'Offline activation key generated successfully',
                'license_key' => $license->license_key,
                'license' => $license,
                'offline_license_token' => $legacyToken,
                'legacy_license_token' => $legacyToken,
                'server_date' => now()->toDateString(),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong while generating offline license.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function renewLicenseOffline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_key' => 'nullable|required_without:license_token|string',
            'license_token' => 'nullable|required_without:license_key|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $company = app('company');
            $license = $request->filled('license_key')
                ? $this->licenseService->activateByKey($company, (string) $request->license_key)
                : $this->licenseService->renewUsingOfflineToken((string) $request->license_token, $company);

            DB::commit();

            return response()->json([
                'message' => 'License activated successfully',
                'license' => $license,
                'license_status' => $this->licenseAccess->status($company->fresh(), $request->user()),
                'server_date' => now()->toDateString(),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Offline license renewal failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function availableCompanyCode(mixed $requested, string $companyName): string
    {
        if (is_scalar($requested) && trim((string) $requested) !== '') {
            return strtoupper(trim((string) $requested));
        }

        $stem = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($companyName)));
        $stem = substr($stem ?: 'COMPANY', 0, 18);
        do {
            $code = $stem.'-'.Str::upper(Str::random(6));
        } while (Company::withTrashed()->where('company_code', $code)->exists());

        return $code;
    }

    private function tenantResourceNotFound()
    {
        return response()->json([
            'message' => 'Resource not found for this company.',
            'code' => 'TENANT_RESOURCE_NOT_FOUND',
        ], 404);
    }
}
