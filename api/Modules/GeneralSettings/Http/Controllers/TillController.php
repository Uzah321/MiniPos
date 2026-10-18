<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\License\LicenseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Company\Models\License;
use Modules\GeneralSettings\Models\PosTill;
use function App\Helpers\hasPermission;

class TillController extends Controller
{
    protected LicenseService $licenseService;

    public function __construct(LicenseService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * List tills for resolved company.
     */
    public function index(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $query = PosTill::where('company_id', $company->id)
            ->with(['branch', 'registeredBy'])
            ->orderBy('till_code');

        if ($request->filled('q')) {
            $q = trim((string) $request->q);

            $query->where(function ($sub) use ($q) {
                $sub->where('till_code', 'like', "%{$q}%")
                    ->orWhere('till_name', 'like', "%{$q}%")
                    ->orWhere('device_label', 'like', "%{$q}%");
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        if ($request->filled('is_registered')) {
            $query->where(
                'is_registered',
                filter_var($request->is_registered, FILTER_VALIDATE_BOOLEAN)
            );
        }

        $perPage = (int) $request->get('per_page', 50);

        return response()->json([
            'message' => 'Tills fetched successfully.',
            'license_usage' => $this->getDeviceLicenseUsage($company->id),
            'data' => $query->paginate($perPage),
        ], 200);
    }

    /**
     * Create till from admin side.
     * This checks license max_devices before saving.
     */
    public function store(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $licenseCheck = $this->checkDeviceLicenseLimit($company->id);

        if ($licenseCheck !== true) {
            return $licenseCheck;
        }

        $request->merge([
            'till_name' => trim((string) $request->input('till_name')),
            'till_code' => strtoupper(trim((string) $request->input('till_code'))),
        ]);

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')
                    ->where('company_id', $company->id),
            ],

            'till_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pos_tills', 'till_code')
                    ->where(function ($query) use ($company, $request) {
                        $query->where('company_id', $company->id)
                            ->where('branch_id', $request->branch_id)
                            ->whereNull('deleted_at');
                    }),
            ],

            'till_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pos_tills', 'till_name')
                    ->where(function ($query) use ($company, $request) {
                        $query->where('company_id', $company->id)
                            ->where('branch_id', $request->branch_id)
                            ->whereNull('deleted_at');
                    }),
            ],
            'device_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $till = PosTill::create([
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'till_code' => strtoupper(trim((string) $request->till_code)),
                'till_name' => trim((string) $request->till_name),
                'device_label' => $request->filled('device_label')
                    ? trim((string) $request->device_label)
                    : null,
                'registration_token' => null,
                'activation_code' => null,
                'activation_code_expires_at' => null,
                'is_registered' => false,
                'is_active' => $request->boolean('is_active', true),
                'registered_by' => null,
                'registered_at' => null,
                'last_seen_at' => null,
                'last_ip_address' => null,
                'last_user_agent' => null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Till created successfully.',
                'license_usage' => $this->getDeviceLicenseUsage($company->id),
                'data' => $till->load('branch'),
            ], 201);
        } catch (QueryException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Database error while creating till.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating till.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show single till.
     */
    public function show(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $till = PosTill::where('company_id', $company->id)
            ->where('id', $id)
            ->with(['branch', 'registeredBy'])
            ->first();

        if (!$till) {
            return response()->json([
                'message' => 'Till not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Till fetched successfully.',
            'license_usage' => $this->getDeviceLicenseUsage($company->id),
            'data' => $till,
        ], 200);
    }

    /**
     * Update till details.
     * Updating does not consume a new device because it is the same till.
     */
    public function update(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $till = PosTill::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$till) {
            return response()->json([
                'message' => 'Till not found.',
            ], 404);
        }

        $licenseStatus = $this->checkValidLicenseOnly($company->id);

        if ($licenseStatus !== true) {
            return $licenseStatus;
        }

        $request->merge([
            'till_name' => trim((string) $request->input('till_name')),
            'till_code' => strtoupper(trim((string) $request->input('till_code'))),
        ]);

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')
                    ->where('company_id', $company->id),
            ],

            'till_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pos_tills', 'till_code')
                    ->ignore($till->id)
                    ->where(function ($query) use ($company, $request) {
                        $query->where('company_id', $company->id)
                            ->where('branch_id', $request->branch_id)
                            ->whereNull('deleted_at');
                    }),
            ],

            'till_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pos_tills', 'till_name')
                    ->ignore($till->id)
                    ->where(function ($query) use ($company, $request) {
                        $query->where('company_id', $company->id)
                            ->where('branch_id', $request->branch_id)
                            ->whereNull('deleted_at');
                    }),
            ],
            'device_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $till->update([
                'branch_id' => $request->branch_id,
                'till_code' => strtoupper(trim((string) $request->till_code)),
                'till_name' => trim((string) $request->till_name),
                'device_label' => $request->filled('device_label')
                    ? trim((string) $request->device_label)
                    : null,
                'is_active' => $request->boolean('is_active', true),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Till updated successfully.',
                'license_usage' => $this->getDeviceLicenseUsage($company->id),
                'data' => $till->fresh(['branch', 'registeredBy']),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while updating till.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete till.
     */
    public function destroy(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $till = PosTill::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$till) {
            return response()->json([
                'message' => 'Till not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $till->delete();

            DB::commit();

            return response()->json([
                'message' => 'Till deleted successfully.',
                'license_usage' => $this->getDeviceLicenseUsage($company->id),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while deleting till.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue a short-lived, one-time code that a separate Front Office browser
     * can redeem directly against this standalone server. Only the digest is
     * persisted; issuing a replacement code revokes the previous one without
     * disconnecting the currently registered browser until redemption.
     */
    public function issueStandaloneActivationCode(Request $request, string $id)
    {
        if ((string) config('salexa_sync.deployment_mode', 'standalone') !== 'standalone') {
            return response()->json([
                'message' => 'Local browser activation codes are available only on a standalone installation.',
                'code' => 'STANDALONE_TERMINAL_ACTIVATION_UNAVAILABLE',
            ], 409);
        }

        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $licenseStatus = $this->checkValidLicenseOnly($company->id);
        if ($licenseStatus !== true) {
            return $licenseStatus;
        }

        $validator = Validator::make($request->all(), [
            'device_label' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $issued = DB::transaction(function () use ($company, $request, $id): ?array {
            $till = PosTill::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereHas('branch', fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->where('status', 'active'))
                ->lockForUpdate()
                ->find($id);
            if (! $till) {
                return null;
            }

            do {
                $rawCode = $this->newStandaloneActivationCode();
                $codeDigest = $this->standaloneActivationCodeDigest($rawCode);
            } while (PosTill::query()->where('activation_code', $codeDigest)->exists());
            $expiresAt = now()->addMinutes(max(
                1,
                min(30, (int) config('salexa_sync.security.terminal_activation_ttl_minutes', 10))
            ));
            $updates = [
                'activation_code' => $codeDigest,
                'activation_code_expires_at' => $expiresAt,
            ];
            if ($request->filled('device_label')) {
                $updates['device_label'] = trim((string) $request->input('device_label'));
            }
            $till->forceFill($updates)->save();

            return compact('till', 'rawCode', 'expiresAt');
        }, 3);

        if (! $issued) {
            return response()->json([
                'message' => 'Active till not found for this company.',
                'code' => 'TERMINAL_NOT_FOUND',
            ], 404);
        }

        /** @var PosTill $till */
        $till = $issued['till'];

        return response()->json([
            'message' => 'Local terminal activation code created. It is shown only once.',
            'data' => [
                'terminal_activation_code' => $issued['rawCode'],
                'expires_at' => $issued['expiresAt']->toIso8601String(),
                'terminal' => $till->only([
                    'id', 'company_id', 'branch_id', 'till_code', 'till_name',
                    'device_label', 'is_active',
                ]),
            ],
        ], 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Activate a terminal directly on a standalone installation.
     * Distributed deployments use the node-bound activation exchange so the
     * raw terminal credential is created and stored only on the edge.
     */
    public function activate(Request $request, string $id)
    {
        $deploymentMode = (string) config('salexa_sync.deployment_mode', 'standalone');
        if ($deploymentMode === 'cloud') {
            return response()->json([
                'message' => 'Direct terminal activation is unavailable on the cloud. Use Deployment & Sync to create a terminal activation code for the selected sync node.',
                'code' => 'TERMINAL_ACTIVATION_CODE_REQUIRED',
            ], 409);
        }
        if ($deploymentMode === 'edge') {
            return response()->json([
                'message' => 'Direct terminal activation is unavailable on an edge. Redeem a terminal activation code through the local edge activation endpoint.',
                'code' => 'TERMINAL_ACTIVATION_REDEMPTION_REQUIRED',
            ], 409);
        }

        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $licenseStatus = $this->checkValidLicenseOnly($company->id);

        if ($licenseStatus !== true) {
            return $licenseStatus;
        }

        $validator = Validator::make($request->all(), [
            'device_label' => ['nullable', 'string', 'max:255'],
            'force_reactivate' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $till = PosTill::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$till) {
            return response()->json([
                'message' => 'Till not found.',
            ], 404);
        }

        if (!$till->is_active) {
            return response()->json([
                'message' => 'Till is inactive.',
            ], 422);
        }

        $usage = $this->getDeviceLicenseUsage($company->id);

        if ((int) $usage['used_devices'] > (int) $usage['max_devices']) {
            return response()->json([
                'message' => 'Device limit exceeded for this license. Please remove extra tills or renew license.',
                'details' => $usage,
            ], 403);
        }

        if ($till->is_registered && !$request->boolean('force_reactivate', false)) {
            return response()->json([
                'message' => 'Till is already registered. Use force_reactivate to move it to this device.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $rawToken = bin2hex(random_bytes(48));
            $hashedToken = hash('sha256', $rawToken);

            $till->update([
                'device_label' => $request->filled('device_label')
                    ? trim((string) $request->device_label)
                    : $till->device_label,
                'registration_token' => $hashedToken,
                'is_registered' => true,
                'registered_by' => $user->id,
                'registered_at' => now(),
                'last_seen_at' => now(),
                'last_ip_address' => $request->ip(),
                'last_user_agent' => $request->userAgent(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Till activated successfully.',
                'license_usage' => $this->getDeviceLicenseUsage($company->id),
                'data' => [
                    'id' => $till->id,
                    'company_id' => $till->company_id,
                    'branch_id' => $till->branch_id,
                    'till_code' => $till->till_code,
                    'till_name' => $till->till_name,
                    'device_label' => $till->device_label,
                    'till_token' => $rawToken,
                    'registered_at' => $till->registered_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while activating till.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove till registration token but keep till record.
     */
    public function unregister(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $till = PosTill::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$till) {
            return response()->json([
                'message' => 'Till not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $till->update([
                'registration_token' => null,
                'is_registered' => false,
                'registered_by' => null,
                'registered_at' => null,
                'last_seen_at' => null,
                'last_ip_address' => null,
                'last_user_agent' => null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Till unregistered successfully.',
                'license_usage' => $this->getDeviceLicenseUsage($company->id),
                'data' => $till->fresh('branch'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while unregistering till.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Called by frontend to check current local till token.
     */
    public function resolveCurrentTill(Request $request)
    {
        $company = app('company');

        $rawToken = $request->header('X-Till-Token');

        if (!$rawToken) {
            return response()->json([
                'registered' => false,
                'message' => 'This browser has not been activated as a till.',
            ], 200);
        }

        $licenseStatus = $this->checkValidLicenseOnly($company->id);

        if ($licenseStatus !== true) {
            return $licenseStatus;
        }

        $hashedToken = hash('sha256', $rawToken);

        $till = PosTill::where('company_id', $company->id)
            ->where('registration_token', $hashedToken)
            ->where('is_registered', true)
            ->where('is_active', true)
            ->with('branch')
            ->first();

        if (!$till) {
            return response()->json([
                'registered' => false,
                'message' => 'This terminal activation is invalid or inactive.',
            ], 200);
        }

        $seenAt = now();
        $presenceCutoff = $seenAt->copy()->subSeconds(30);
        $till->recordPresence([
            'last_seen_at' => $seenAt,
            'last_ip_address' => $request->ip(),
            'last_user_agent' => $request->userAgent(),
        ], $presenceCutoff);

        return response()->json([
            'registered' => true,
            'message' => 'Till resolved successfully.',
            'license_usage' => $this->getDeviceLicenseUsage($company->id),
            'data' => [
                'id' => $till->id,
                'company_id' => $till->company_id,
                'branch_id' => $till->branch_id,
                'branch' => $till->branch,
                'till_code' => $till->till_code,
                'till_name' => $till->till_name,
                'device_label' => $till->device_label,
                'last_seen_at' => $till->last_seen_at,
            ],
        ], 200);
    }

    /**
     * Generate next till code for branch.
     */
    public function generateTillCode(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'till_management', $company->id);
        if ($result !== true) return $result;

        $licenseStatus = $this->checkValidLicenseOnly($company->id);

        if ($licenseStatus !== true) {
            return $licenseStatus;
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')
                    ->where('company_id', $company->id),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $count = PosTill::where('company_id', $company->id)
            ->where('branch_id', $request->branch_id)
            ->withTrashed()
            ->count();

        $next = $count + 1;

        do {
            $code = 'TILL-' . str_pad($next, 3, '0', STR_PAD_LEFT);

            $exists = PosTill::where('company_id', $company->id)
                ->where('branch_id', $request->branch_id)
                ->where('till_code', $code)
                ->withTrashed()
                ->exists();

            if (!$exists) {
                break;
            }

            $next++;
        } while (true);

        return response()->json([
            'message' => 'Till code generated successfully.',
            'data' => [
                'till_code' => $code,
            ],
        ], 200);
    }

    /**
     * Only check that company has a valid active license.
     */
    private function checkValidLicenseOnly(string $companyId)
    {
        $license = $this->getActiveLicense($companyId);

        if (!$license || !$this->licenseService->isValid($license)) {
            return response()->json([
                'message' => 'Company has no valid active license.',
            ], 403);
        }

        return true;
    }

    /**
     * Check max_devices before creating a new till.
     */
    private function checkDeviceLicenseLimit(string $companyId, ?string $ignoreTillId = null)
    {
        $license = $this->getActiveLicense($companyId);

        if (!$license || !$this->licenseService->isValid($license)) {
            return response()->json([
                'message' => 'Company has no valid active license.',
            ], 403);
        }

        $query = PosTill::where('company_id', $companyId)
            ->whereNull('deleted_at');

        if ($ignoreTillId) {
            $query->where('id', '!=', $ignoreTillId);
        }

        $usedDevices = $query->count();

        if ($usedDevices >= (int) $license->max_devices) {
            return response()->json([
                'message' => 'Device limit reached for this license.',
                'details' => [
                    'max_devices' => (int) $license->max_devices,
                    'used_devices' => $usedDevices,
                    'available_devices' => 0,
                ],
            ], 403);
        }

        return true;
    }

    /**
     * License usage for frontend display.
     */
    private function getDeviceLicenseUsage(string $companyId): array
    {
        $license = $this->getActiveLicense($companyId);

        $usedDevices = PosTill::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->count();

        return [
            'max_devices' => $license ? (int) $license->max_devices : 0,
            'used_devices' => $usedDevices,
            'available_devices' => $license
                ? max(((int) $license->max_devices) - $usedDevices, 0)
                : 0,
        ];
    }

    /**
     * Get active company license.
     */
    private function getActiveLicense(string $companyId): ?License
    {
        return License::where('company_id', $companyId)
            ->where('status', 'active')
            ->latest()
            ->first();
    }

    private function newStandaloneActivationCode(): string
    {
        return 'TERM-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
    }

    private function standaloneActivationCodeDigest(string $code): string
    {
        // The existing column is 30 characters. A truncated SHA-256 digest
        // retains 120 bits and keeps the 128-bit raw capability out of storage.
        return substr(hash('sha256', strtoupper(trim($code))), 0, 30);
    }
}
