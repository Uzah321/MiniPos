<?php

//
// namespace Modules\Authentication\Http\Controllers;
//
// use App\Http\Controllers\Controller;
// use App\Services\License\LicenseService;
// use App\Services\Shifts\ShiftPolicyService;
// use App\Services\Shifts\ShiftResolverService;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Hash;
// use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Str;
// use Modules\Authentication\Models\PosUser;
// use Modules\Authentication\Models\PosUserShiftSession;
// use Modules\Company\Models\Company;
// use Modules\Company\Models\CompanyBranch;
// use function App\Helpers\hasPermission;
//
// class PosUserController extends Controller
// {
//    protected LicenseService $licenseService;
//    protected ShiftResolverService $shiftResolverService;
//
//    protected ShiftPolicyService $shiftPolicyService;
//
//    public function __construct(
//            LicenseService $licenseService,
//            ShiftResolverService $shiftResolverService,
//            ShiftPolicyService $shiftPolicyService
//  ) {
//      $this->licenseService = $licenseService;
//      $this->shiftResolverService = $shiftResolverService;
//      $this->shiftPolicyService = $shiftPolicyService;
//  }
//
//
// public function login(Request $request)
// {
//    $validator = Validator::make($request->all(), [
//        'username' => 'required|string',
//        'password' => 'required|string',
//        'branch_id' => 'required|exists:company_branches,id',
//        'device_name' => 'nullable|string|max:255',
//        'time' => 'nullable|date_format:H:i',
//        'opening_float' => 'nullable|numeric|min:0',
//        'continue_shift_session_id' => 'nullable|string',
//        'force_new_shift' => 'nullable|boolean',
//    ]);
//
//    if ($validator->fails()) {
//        return response()->json([
//            'message' => 'Validation error',
//            'errors' => $validator->errors(),
//        ], 422);
//    }
//
//    $user = PosUser::with(['role.permissions', 'branches'])
//        ->where('username', $request->username)
//        ->first();
//
//    if (!$user || !Hash::check($request->password, $user->password)) {
//        return response()->json(['message' => 'Invalid username or password'], 401);
//    }
//
//    if (!$user->is_active || !$user->can_login_pos) {
//        return response()->json(['message' => 'User is not allowed to login'], 403);
//    }
//
//    $branch = CompanyBranch::with('company')->find($request->branch_id);
//
//    if (!$branch) {
//        return response()->json(['message' => 'Branch not found'], 404);
//    }
//
//    $isSuperAdmin = $user->role && $user->role->name === 'Super Admin';
//
//    if (!$isSuperAdmin) {
//        if ($user->company_id !== $branch->company_id) {
//            return response()->json(['message' => 'User does not belong to this company'], 403);
//        }
//
//        $hasBranchAccess = $user->branches()
//            ->where('company_branches.id', $branch->id)
//            ->exists();
//
//        if (!$hasBranchAccess) {
//            return response()->json(['message' => 'User does not have access to this branch'], 403);
//        }
//    }
//
//    $shiftConfig = $this->shiftResolverService->getConfig($branch->company_id, $branch->id);
//    $shiftPolicy = $this->shiftPolicyService->get($branch->company_id, $branch->id);
//
//    $shiftName = null;
//    $activeShiftSession = null;
//    $shiftWarning = null;
//    $shiftActionRequired = null;
//
//    if ($shiftConfig && $shiftConfig->enable_shifts) {
//        $shiftName = $this->shiftResolverService->detect(
//            $branch->company_id,
//            $branch->id,
//            $request->time
//        );
//
//        if (!$shiftName) {
//            return response()->json([
//                'message' => 'No active shift found for this branch at the current time',
//            ], 403);
//        }
//
//        $existingOpenShiftForUser = PosUserShiftSession::where('company_id', $branch->company_id)
//            ->where('branch_id', $branch->id)
//            ->where('pos_user_id', $user->id)
//            ->where('status', 'open')
//            ->whereNull('closed_at')
//            ->latest('opened_at')
//            ->first();
//
//        $openBranchShifts = PosUserShiftSession::query()
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
//            ->select([
//                'pos_user_shift_sessions.*',
//                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
//            ])
//            ->where('pos_user_shift_sessions.company_id', $branch->company_id)
//            ->where('pos_user_shift_sessions.branch_id', $branch->id)
//            ->where('pos_user_shift_sessions.status', 'open')
//            ->whereNull('pos_user_shift_sessions.closed_at')
//            ->orderBy('pos_user_shift_sessions.opened_at')
//            ->get();
//
//        $otherOpenShift = $openBranchShifts
//            ->where('pos_user_id', '!=', $user->id)
//            ->first();
//
//        /*
//         |--------------------------------------------------------------------------
//         | Enterprise behavior:
//         | Do not block login just because someone did not close shift.
//         | Login succeeds and frontend receives warning + required action.
//         |--------------------------------------------------------------------------
//         */
//        if ($otherOpenShift && !$shiftPolicy['allow_new_shift_when_previous_unclosed']) {
//            $shiftWarning = "An earlier shift is still open and must be cashed up.";
//            $shiftActionRequired = $shiftPolicy['require_cashup_before_new_shift']
//                ? 'cashup_required_before_new_shift'
//                : 'warning_only';
//        }
//
//        if ($existingOpenShiftForUser && $shiftPolicy['allow_continue_unclosed_shift']) {
//            $activeShiftSession = $existingOpenShiftForUser;
//            $shiftWarning = $shiftWarning ?: 'You are continuing your existing open shift.';
//            $shiftActionRequired = $shiftActionRequired ?: 'continue_existing_shift';
//        } elseif (!$otherOpenShift || $shiftPolicy['allow_new_shift_when_previous_unclosed'] || $request->boolean('force_new_shift')) {
//            if ($shiftConfig->require_opening_float && !$request->filled('opening_float')) {
//                return response()->json([
//                    'message' => 'Opening float is required to open this shift',
//                    'shift_warning' => $shiftWarning,
//                    'shift_action_required' => $shiftActionRequired,
//                    'unclosed_shifts' => $openBranchShifts,
//                ], 422);
//            }
//        } else {
//            /*
//             | User can login, but no new shift is opened until previous shift is handled.
//             */
//            $activeShiftSession = null;
//        }
//    }
//
//    DB::beginTransaction();
//
//    try {
//        $user->update([
//            'company_id' => $branch->company_id,
//            'branch_id' => $branch->id,
//            'last_login_at' => now(),
//        ]);
//
//        if ($shiftConfig && $shiftConfig->enable_shifts) {
//            if (!$activeShiftSession) {
//                $canOpenNewShift = !$shiftActionRequired
//                    || $shiftPolicy['allow_new_shift_when_previous_unclosed']
//                    || $request->boolean('force_new_shift');
//
//                if ($canOpenNewShift) {
//                    $activeShiftSession = PosUserShiftSession::create([
//                        'company_id' => $branch->company_id,
//                        'branch_id' => $branch->id,
//                        'shift_configuration_id' => $shiftConfig->id,
//                        'pos_user_id' => $user->id,
//                        'shift_name' => $shiftName,
//                        'opened_at' => now(),
//                        'opening_float' => $request->opening_float,
//                        'status' => 'open',
//                    ]);
//                }
//            } elseif (!$activeShiftSession->shift_configuration_id) {
//                $activeShiftSession->update(['shift_configuration_id' => $shiftConfig->id]);
//            }
//        }
//
//        $openBranchShifts = PosUserShiftSession::query()
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
//            ->select([
//                'pos_user_shift_sessions.*',
//                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
//            ])
//            ->where('pos_user_shift_sessions.company_id', $branch->company_id)
//            ->where('pos_user_shift_sessions.branch_id', $branch->id)
//            ->where('pos_user_shift_sessions.status', 'open')
//            ->whereNull('pos_user_shift_sessions.closed_at')
//            ->orderBy('pos_user_shift_sessions.opened_at')
//            ->get();
//
//        $token = $user->createToken($request->device_name ?? 'pos-device')->plainTextToken;
//
//        DB::commit();
//
//        return response()->json([
//            'message' => 'Login successful',
//            'token_type' => 'Bearer',
//            'token' => $token,
//            'active_shift' => $activeShiftSession
//                ? $activeShiftSession->fresh()->load('shiftConfiguration')
//                : null,
//            'shift_warning' => $shiftWarning,
//            'shift_action_required' => $shiftActionRequired,
//            'shift_policy' => $shiftPolicy,
//            'unclosed_shifts' => $openBranchShifts,
//            'user' => $user->fresh()->load([
//                'company',
//                'branch',
//                'role.permissions',
//                'branches',
//                'activeShiftSession.shiftConfiguration',
//            ]),
//        ]);
//    } catch (\Exception $e) {
//        DB::rollBack();
//
//        return response()->json([
//            'message' => 'Something went wrong while logging in',
//            'error' => $e->getMessage(),
//        ], 500);
//    }
// }
//
//    public function me(Request $request)
//    {
//        return response()->json([
//            'user' => $request->user()->load([
//                'company',
//                'branch',
//                'role',
//                'branches',
//                'activeShiftSession.shiftConfiguration',
//            ]),
//        ]);
//    }
//
//    public function logout(Request $request)
//    {
//        $request->user()->currentAccessToken()->delete();
//
//        return response()->json([
//            'message' => 'Logout successful',
//        ]);
//    }
//
//    public function logoutAll(Request $request)
//    {
//        $request->user()->tokens()->delete();
//
//        return response()->json([
//            'message' => 'Logged out from all devices',
//        ]);
//    }
//
//    public function index(Request $request)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'manage_user', $company->id);
//
//        if ($result !== true) {
//            return $result;
//        }
//
//        $users = PosUser::with([
//            'company',
//            'branch',
//            'role',
//            'branches',
//            'activeShiftSession.shiftConfiguration',
//        ])
//            ->where('company_id', $company->id)
//            ->latest()
//            ->paginate($request->get('per_page', 20));
//
//        return response()->json([
//            'message' => 'POS users fetched successfully',
//            'data' => $users,
//        ]);
//    }
//
//    public function store(Request $request)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'create_user', $company->id);
//
//        if ($result !== true) {
//            return $result;
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'role_id' => 'nullable|exists:roles,id',
//
//            'name' => 'required|string|max:255',
//            'username' => 'required|string|max:255|unique:pos_users,username',
//            'phone' => 'nullable|string|max:50',
//            'password' => 'required|string|min:6|confirmed',
//
//            'employee_code' => 'nullable|string|max:100',
//            'user_type' => 'nullable|string|in:super_admin,admin,manager,cashier,supervisor',
//            'can_login_pos' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//
//            'branch_ids' => 'nullable|array',
//            'branch_ids.*' => 'exists:company_branches,id',
//            'default_branch_id' => 'nullable|exists:company_branches,id',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $company = Company::with('activeLicense')->find($company->id);
//
//        if (!$company) {
//            return response()->json([
//                'message' => 'Company not found',
//            ], 404);
//        }
//
//        $license = $company->activeLicense;
//
//        if (!$license || !$this->licenseService->isValid($license)) {
//            return response()->json([
//                'message' => 'Company license is invalid or expired',
//            ], 403);
//        }
//
//        $currentUsersCount = PosUser::where('company_id', $company->id)
//            ->where('is_active', true)
//            ->count();
//
//        if ($currentUsersCount >= $license->max_users) {
//            return response()->json([
//                'message' => 'User limit reached for this license',
//                'limit' => $license->max_users,
//                'current_users' => $currentUsersCount,
//            ], 403);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            $user = PosUser::create([
//                'company_id' => $company->id,
//                'branch_id' => $request->branch_id,
//                'role_id' => $request->role_id,
//                'name' => $request->name,
//                'username' => $request->username,
//                'phone' => $request->phone,
//                'password' => $request->password,
//                'employee_code' => $request->employee_code,
//                'user_type' => $request->user_type ?? 'cashier',
//                'can_login_pos' => $request->can_login_pos ?? true,
//                'is_active' => $request->is_active ?? true,
//            ]);
//
//            if ($request->filled('branch_ids')) {
//                $syncData = [];
//
//                foreach ($request->branch_ids as $branchId) {
//                    $syncData[$branchId] = [
//                        'id' => (string) Str::uuid(),
//                        'company_id' => $company->id,
//                        'is_default' => $branchId === $request->default_branch_id,
//                    ];
//                }
//
//                $user->branches()->sync($syncData);
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'POS user created successfully',
//                'user' => $user->load([
//                    'company',
//                    'branch',
//                    'role',
//                    'branches',
//                    'activeShiftSession.shiftConfiguration',
//                ]),
//            ], 201);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while creating POS user',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function show(PosUser $posUser)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'manage_user', $company->id);
//
//        if ($result !== true) {
//            return $result;
//        }
//
//        return response()->json([
//            'message' => 'POS user fetched successfully',
//            'user' => $posUser->load([
//                'company',
//                'branch',
//                'role',
//                'branches',
//                'activeShiftSession.shiftConfiguration',
//            ]),
//        ]);
//    }
//
// //    public function update(Request $request, PosUser $posUser)
// //    {
// //        $company = app('company');
// //
// //        $result = hasPermission(auth()->user()->role_id, 'manage_user', $company->id);
// //
// //        if ($result !== true) {
// //            return $result;
// //        }
// //
// //        $validator = Validator::make($request->all(), [
// //            'company_id' => 'nullable|exists:companies,id',
// //            'branch_id' => 'nullable|exists:company_branches,id',
// //            'role_id' => 'nullable|exists:roles,id',
// //
// //            'name' => 'sometimes|required|string|max:255',
// //            'username' => 'sometimes|required|string|max:255|unique:pos_users,username,' . $posUser->id,
// //            'phone' => 'nullable|string|max:50',
// //            'password' => 'nullable|string|min:6|confirmed',
// //
// //            'employee_code' => 'nullable|string|max:100',
// //            'user_type' => 'nullable|string|in:super_admin,admin,manager,cashier,supervisor',
// //            'can_login_pos' => 'nullable|boolean',
// //            'is_active' => 'nullable|boolean',
// //
// //            'branch_ids' => 'nullable|array',
// //            'branch_ids.*' => 'exists:company_branches,id',
// //            'default_branch_id' => 'nullable|exists:company_branches,id',
// //        ]);
// //
// //        if ($validator->fails()) {
// //            return response()->json([
// //                'message' => 'Validation error',
// //                'errors' => $validator->errors(),
// //            ], 422);
// //        }
// //
// //        $data = $request->only([
// //            'company_id',
// //            'branch_id',
// //            'role_id',
// //            'name',
// //            'username',
// //            'phone',
// //            'employee_code',
// //            'user_type',
// //            'can_login_pos',
// //            'is_active',
// //        ]);
// //
// //        if ($request->filled('password')) {
// //            $data['password'] = $request->password;
// //        }
// //
// //        $posUser->update($data);
// //
// //        if ($request->has('branch_ids')) {
// //            $syncData = [];
// //
// //            foreach ($request->branch_ids ?? [] as $branchId) {
// //                $syncData[$branchId] = [
// //                    'company_id' => $request->company_id ?? $posUser->company_id,
// //                    'is_default' => $branchId === $request->default_branch_id,
// //                ];
// //            }
// //
// //            $posUser->branches()->sync($syncData);
// //        }
// //
// //        return response()->json([
// //            'message' => 'POS user updated successfully',
// //            'user' => $posUser->fresh()->load([
// //                'company',
// //                'branch',
// //                'role',
// //                'branches',
// //                'activeShiftSession.shiftConfiguration',
// //            ]),
// //        ]);
// //    }
//    public function update(Request $request, PosUser $posUser)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'manage_user', $company->id);
//
//        if ($result !== true) {
//            return $result;
//        }
//
//        $validator = Validator::make($request->all(), [
//            'company_id' => 'nullable|exists:companies,id',
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'role_id' => 'nullable|exists:roles,id',
//
//            'name' => 'sometimes|required|string|max:255',
//            'username' => 'sometimes|required|string|max:255|unique:pos_users,username,' . $posUser->id,
//            'phone' => 'nullable|string|max:50',
//            'password' => 'nullable|string|min:6|confirmed',
//
//            'employee_code' => 'nullable|string|max:100',
//            'user_type' => 'nullable|string|in:super_admin,admin,manager,cashier,supervisor',
//            'can_login_pos' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//
//            'branch_ids' => 'nullable|array',
//            'branch_ids.*' => 'exists:company_branches,id',
//            'default_branch_id' => 'nullable|exists:company_branches,id',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $data = $request->only([
//            'company_id',
//            'branch_id',
//            'role_id',
//            'name',
//            'username',
//            'phone',
//            'employee_code',
//            'user_type',
//            'can_login_pos',
//            'is_active',
//        ]);
//
//        if ($request->filled('password')) {
//            $data['password'] = $request->password;
//        }
//
//        $posUser->update($data);
//
//        if ($request->has('branch_ids')) {
//            $companyId = $request->company_id ?? $posUser->company_id;
//
//            $branchIds = collect($request->branch_ids ?? [])
//                ->filter()
//                ->unique()
//                ->values();
//
//            $defaultBranchId = $request->default_branch_id;
//
//            if (!$defaultBranchId && $branchIds->count() > 0) {
//                $defaultBranchId = $branchIds->first();
//            }
//
//            $syncData = [];
//
//            foreach ($branchIds as $branchId) {
//                $existingPivotId = DB::table('user_branches')
//                    ->where('pos_user_id', $posUser->id)
//                    ->where('branch_id', $branchId)
//                    ->value('id');
//
//                $syncData[$branchId] = [
//                    'id' => $existingPivotId ?: (string) Str::uuid(),
//                    'company_id' => $companyId,
//                    'is_default' => $branchId === $defaultBranchId,
//                    'created_at' => now(),
//                    'updated_at' => now(),
//                ];
//            }
//
//            $posUser->branches()->sync($syncData);
//
//            if ($defaultBranchId) {
//                $posUser->update([
//                    'branch_id' => $defaultBranchId,
//                ]);
//            }
//        }
//
//        return response()->json([
//            'message' => 'POS user updated successfully',
//            'user' => $posUser->fresh()->load([
//                'company',
//                'branch',
//                'role',
//                'branches',
//                'activeShiftSession.shiftConfiguration',
//            ]),
//        ]);
//    }
//    public function destroy(PosUser $posUser)
//    {
//        $posUser->delete();
//
//        return response()->json([
//            'message' => 'POS user deleted successfully',
//        ]);
//    }
//
//    public function closeActiveShift(Request $request)
//    {
//        $validator = Validator::make($request->all(), [
//            'closing_cash' => 'nullable|numeric|min:0',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $user = $request->user();
//
//        $activeShiftSession = PosUserShiftSession::where('pos_user_id', $user->id)
//            ->where('status', 'open')
//            ->latest()
//            ->first();
//
//        if (!$activeShiftSession) {
//            return response()->json([
//                'message' => 'No open shift found for this user',
//            ], 404);
//        }
//
//        $activeShiftSession->update([
//            'closing_cash' => $request->closing_cash,
//            'closed_at' => now(),
//            'status' => 'closed',
//        ]);
//
//        return response()->json([
//            'message' => 'Shift closed successfully',
//            'data' => $activeShiftSession->fresh()->load('shiftConfiguration'),
//        ]);
//    }
//
//    public function create()
//    {
//        return view('authentication::create');
//    }
// }

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SyncNode;
use App\Services\Auth\SuperAdminService;
use App\Services\CompanySettingService;
use App\Services\License\LicenseAccessService;
use App\Services\License\LicenseService;
use App\Services\Shifts\ShiftPolicyService;
use App\Services\Shifts\ShiftResolverService;
use App\Services\Shifts\ShiftSessionOpener;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\PosUserShiftAssignment;
use Modules\Authentication\Models\PosUserShiftSession;
use Modules\Authentication\Models\ShiftConfiguration;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Services\AuthenticationEventRecorder;
use Modules\Authentication\Services\AuthenticationPolicyService;
use Modules\Authentication\Services\AuthSessionService;
use Modules\Authentication\Services\CredentialPolicyService;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Authentication\Services\LoginCodeBlindIndex;
use Modules\Authentication\Services\PosAuthenticationContextService;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

use function App\Helpers\hasPermission;

class PosUserController extends Controller
{
    protected LicenseService $licenseService;

    protected ShiftResolverService $shiftResolverService;

    protected ShiftPolicyService $shiftPolicyService;

    public function __construct(
        LicenseService $licenseService,
        ShiftResolverService $shiftResolverService,
        ShiftPolicyService $shiftPolicyService,
        private readonly LicenseAccessService $licenseAccess,
        private readonly SuperAdminService $superAdmins,
    ) {
        $this->licenseService = $licenseService;
        $this->shiftResolverService = $shiftResolverService;
        $this->shiftPolicyService = $shiftPolicyService;
    }

    private function normalizeLoginCode(Request $request): void
    {
        $code = $request->input(
            'login_code',
            $request->input('pin_code', $request->input('code'))
        );

        if ($code === null || $code === '') {
            return;
        }

        try {
            $normalised = LoginCodeBlindIndex::canonicalizeCode((string) $code);
        } catch (\InvalidArgumentException) {
            // Preserve invalid input for the request/policy validator instead
            // of converting a client error into an authentication exception.
            $normalised = trim((string) $code);
        }

        $request->merge([
            // Do not repair an invalid credential by deleting characters.
            // Leading zeroes are significant and non-digits must be rejected
            // by validation rather than silently changing the supplied code.
            'login_code' => $normalised,
        ]);
    }

    private function supportsLoginCodeBlindIndex(): bool
    {
        return Schema::hasTable('pos_users')
            && Schema::hasColumn('pos_users', 'login_code_lookup')
            && Schema::hasColumn('pos_users', 'login_code_lookup_key_id');
    }

    /** @return array<string, string> */
    private function loginCodeDigests(string $companyId, string $loginCode): array
    {
        return app(LoginCodeBlindIndex::class)
            ->candidateDigestsForCompany($companyId, $loginCode);
    }

    private function activeLoginCodeDigest(string $companyId, string $loginCode): array
    {
        $index = app(LoginCodeBlindIndex::class);

        return [
            'login_code_lookup' => $index->digestForCompany($companyId, $loginCode),
            'login_code_lookup_key_id' => $index->activeKeyId(),
        ];
    }

    private function loginCodeCandidates(Company $company, string $loginCode)
    {
        $query = $this->loginCandidateQuery($company)
            ->whereNotNull('login_code')
            ->where('is_active', true)
            ->where('can_login_pos', true);

        if (! $this->supportsLoginCodeBlindIndex()) {
            return $query->get();
        }

        $digests = array_values($this->loginCodeDigests((string) $company->id, $loginCode));
        $indexed = (clone $query)
            ->whereIn('login_code_lookup', $digests)
            ->get();
        $legacy = (clone $query)
            ->whereNull('login_code_lookup')
            ->get();

        // The HMAC is only a lookup accelerator. The slow hash remains the
        // final verifier below, including during key rotation/backfill.
        return $indexed->concat($legacy)->unique('id')->values();
    }

    private function loginCandidateQuery(
        Company $company,
        bool $includePlatformAdministrators = false
    ): Builder {
        $platformAdministratorIds = $includePlatformAdministrators
            ? $this->superAdmins->platformAdministratorIds()
            : [];

        return PosUser::with(['role.permissions', 'branches'])
            ->where(function (Builder $query) use ($company, $platformAdministratorIds): void {
                $query->where('company_id', $company->id);

                if ($platformAdministratorIds !== []) {
                    $query->orWhereIn('id', $platformAdministratorIds);
                }
            });
    }

    private function salesScreenLayout(string $companyId, string $branchId): string
    {
        $layout = strtolower(trim((string) app(CompanySettingService::class)->get(
            $companyId,
            'sales_screen_layout',
            'standard',
            $branchId
        )));

        return in_array($layout, ['standard', 'visual'], true) ? $layout : 'standard';
    }

    private function loginCodeAlreadyUsed(string $companyId, string $loginCode, ?string $ignoreUserId = null): bool
    {
        $query = PosUser::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('login_code');

        if ($ignoreUserId) {
            $query->where('id', '!=', $ignoreUserId);
        }

        if ($this->supportsLoginCodeBlindIndex()) {
            $indexedUsers = (clone $query)
                ->whereIn('login_code_lookup', array_values($this->loginCodeDigests($companyId, $loginCode)))
                ->get(['id', 'login_code']);
            $legacyUsers = (clone $query)
                ->whereNull('login_code_lookup')
                ->get(['id', 'login_code']);
            $existingUsers = $indexedUsers->concat($legacyUsers)->unique('id');
        } else {
            $existingUsers = $query->get(['id', 'login_code']);
        }

        foreach ($existingUsers as $existingUser) {
            if ($existingUser->login_code && Hash::check($loginCode, $existingUser->login_code)) {
                return true;
            }
        }

        return false;
    }

    private function isLoginCodeLookupConflict(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'pos_users_company_code_lookup_uq');
    }

    private function isSuperAdminIdentity($user): bool
    {
        return $this->superAdmins->isSuperAdmin($user);
    }

    private function requestAssignsSuperAdmin(
        Request $request,
        string $companyId,
        ?PosUser $user = null
    ): bool {
        $roleId = $request->input('role_id', $user?->role_id);
        $roleName = $roleId
            ? DB::table('roles')
                ->where('company_id', $companyId)
                ->where('id', $roleId)
                ->value('name')
            : null;
        $normalise = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));

        return in_array($normalise($roleName), ['super_admin', 'super_administrator'], true)
            || in_array(
                $normalise($request->input('user_type', $user?->user_type)),
                ['super_admin', 'super_administrator'],
                true
            );
    }

    /**
     * Return the branches that a canonical scoped administrator may manage.
     * Null means the installation is still using the legacy company-wide
     * compatibility path (or the authenticated platform owner).
     *
     * @return list<string>|null
     */
    private function userManagementBranchIds(PosUser $actor, Company $company): ?array
    {
        if ($this->superAdmins->hasPlatformSessionAccess($actor)
            || ! app(EnterpriseAuthorizationService::class)->usesScopedAssignments($actor)) {
            return null;
        }

        return app(EnterpriseAuthorizationService::class)->accessibleBranchIds(
            $actor,
            (string) $company->id
        );
    }

    private function hasCompanyWideUserPermission(
        PosUser $actor,
        Company $company,
        string $permission
    ): bool {
        if ($this->superAdmins->hasPlatformSessionAccess($actor)) {
            return true;
        }

        if (! app(EnterpriseAuthorizationService::class)->usesScopedAssignments($actor)) {
            // Rolling-deployment compatibility ends permanently as soon as
            // the actor receives any canonical assignment.
            return true;
        }

        return app(EnterpriseAuthorizationService::class)->can(
            $actor,
            $permission,
            app(EnterpriseScopeResolver::class)->fromCompany($company)
        );
    }

    /** @return list<string> */
    private function posUserBranchIds(PosUser $user): array
    {
        return collect([$user->branch_id])
            ->merge($user->branches()->pluck('company_branches.id'))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function requestedUserBranchIds(Request $request, ?PosUser $user = null): array
    {
        $branchIds = $request->has('branch_ids')
            ? collect($request->input('branch_ids', []))
            : collect($user ? $this->posUserBranchIds($user) : []);

        if ($request->filled('branch_id')) {
            $branchIds->push($request->input('branch_id'));
        } elseif (! $request->has('branch_ids') && $user?->branch_id) {
            $branchIds->push($user->branch_id);
        }

        if ($request->filled('default_branch_id')) {
            $branchIds->push($request->input('default_branch_id'));
        }

        return $branchIds
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A shop administrator may manage users only inside that shop. Managing
     * a multi-shop identity is deliberately company-scoped because changing
     * any part of it affects another shop's security boundary.
     *
     * @return array<string, list<string>>
     */
    private function userManagementBranchErrors(
        Request $request,
        PosUser $actor,
        Company $company,
        string $permission,
        ?PosUser $user = null
    ): array {
        $allowedBranchIds = $this->userManagementBranchIds($actor, $company);
        if ($allowedBranchIds === null) {
            return [];
        }

        $requestedBranchIds = $this->requestedUserBranchIds($request, $user);
        $errors = [];

        if ($requestedBranchIds === []) {
            $errors['branch_id'][] = 'A user managed at shop scope must be assigned to an accessible shop.';

            return $errors;
        }

        $outsideScope = array_values(array_diff($requestedBranchIds, $allowedBranchIds));
        if ($outsideScope !== []) {
            $errors['branch_ids'][] = 'One or more selected shops are outside your access scope.';
        }

        if (count($requestedBranchIds) > 1
            && ! $this->hasCompanyWideUserPermission($actor, $company, $permission)) {
            $errors['branch_ids'][] = 'A company-scope permission is required to manage a user across shops.';
        }

        if ($request->has('branch_ids') && $request->filled('default_branch_id')
            && ! in_array((string) $request->input('default_branch_id'), array_map(
                'strval',
                (array) $request->input('branch_ids', [])
            ), true)) {
            $errors['default_branch_id'][] = 'The default shop must be included in the selected shops.';
        }

        return $errors;
    }

    private function canManagePosUserTarget(
        PosUser $actor,
        PosUser $target,
        Company $company,
        string $permission = 'manage_user'
    ): bool {
        if ($this->superAdmins->hasPlatformSessionAccess($actor)) {
            return true;
        }

        // A role label alone must never let a tenant administrator mutate the
        // separately allowlisted platform identity.
        if ($this->superAdmins->isSuperAdmin($target)) {
            return false;
        }

        if (! app(EnterpriseAuthorizationService::class)->usesScopedAssignments($actor)) {
            return true;
        }

        $allowedBranchIds = $this->userManagementBranchIds($actor, $company) ?? [];
        $targetBranchIds = $this->posUserBranchIds($target);
        if ($targetBranchIds === []
            || array_diff($targetBranchIds, $allowedBranchIds) !== []) {
            return false;
        }
        if (count($targetBranchIds) > 1
            && ! $this->hasCompanyWideUserPermission($actor, $company, $permission)) {
            return false;
        }

        if (! Schema::hasTable('user_access_assignments')) {
            return true;
        }

        return $target->accessAssignments()
            ->effectiveAt(now())
            ->get()
            ->every(fn (UserAccessAssignment $assignment): bool => app(EnterpriseAuthorizationService::class)->can(
                $actor,
                $permission,
                $assignment->enterpriseScope()
            ));
    }

    private function availableShiftNames(ShiftConfiguration $config): array
    {
        $names = [];

        if ($config->shift_mode === 'manual') {
            $names[] = 'manual';
        } else {
            foreach (['morning', 'afternoon', 'night'] as $name) {
                if ($config->{$name.'_shift_start'} && $config->{$name.'_shift_end'}) {
                    $names[] = $name;
                }
            }
        }

        if ($config->allow_unscheduled_shifts) {
            $names[] = 'unscheduled';
        }

        return array_values(array_unique($names));
    }

    private function shiftAssignmentErrors(Request $request, string $companyId, ?PosUser $user = null): array
    {
        $assignments = collect($request->has('shift_assignments')
            ? $request->input('shift_assignments', [])
            : ($user?->shiftAssignments()
                ->get(['shift_configuration_id', 'shift_name'])
                ->map(fn ($assignment) => [
                    'shift_configuration_id' => $assignment->shift_configuration_id,
                    'shift_name' => $assignment->shift_name,
                ])
                ->all() ?? []));
        $branchIds = collect($request->has('branch_ids')
            ? $request->input('branch_ids', [])
            : ($user?->branches()->pluck('company_branches.id')->all() ?? []))
            ->push($request->input('default_branch_id', $user?->branch_id))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $roleId = $request->input('role_id', $user?->role_id);
        $roleName = $roleId
            ? DB::table('roles')
                ->where('company_id', $companyId)
                ->where('id', $roleId)
                ->value('name')
            : null;
        $normalise = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $identities = [
            $normalise($request->input('user_type', $user?->user_type)),
            $normalise($roleName),
        ];
        $isSuperAdmin = ($user && $this->isSuperAdminIdentity($user))
            || count(array_intersect($identities, ['super_admin', 'super_administrator'])) > 0;
        $frontOfficeAccess = $request->has('can_access_front_office')
            ? $request->boolean('can_access_front_office')
            : (bool) $user?->can_access_front_office;

        $availableConfigs = ShiftConfiguration::query()
            ->where('company_id', $companyId)
            ->where('enable_shifts', true)
            ->where('status', 'active')
            ->where(function ($query) use ($branchIds) {
                $query->whereNull('branch_id');
                if ($branchIds->isNotEmpty()) {
                    $query->orWhereIn('branch_id', $branchIds);
                }
            })
            ->get()
            ->keyBy(fn ($config) => (string) $config->id);

        $errors = [];
        if ($frontOfficeAccess && ! $isSuperAdmin && $availableConfigs->isNotEmpty() && $assignments->isEmpty()) {
            $errors['shift_assignments'][] = 'Assign at least one shift before granting Front Office access.';
        }

        $seen = [];
        foreach ($assignments as $index => $assignment) {
            $configId = (string) ($assignment['shift_configuration_id'] ?? '');
            $shiftName = strtolower(trim((string) ($assignment['shift_name'] ?? '')));
            $config = $availableConfigs->get($configId);

            if (! $config) {
                $errors["shift_assignments.{$index}.shift_configuration_id"][] = 'The selected shift configuration is not active for this company and branch.';

                continue;
            }

            if ($config->branch_id && ! $branchIds->contains((string) $config->branch_id)) {
                $errors["shift_assignments.{$index}.shift_configuration_id"][] = 'Assign the user to the shift branch first.';
            }

            if (! in_array($shiftName, $this->availableShiftNames($config), true)) {
                $errors["shift_assignments.{$index}.shift_name"][] = 'The selected shift is not available in this configuration.';
            }

            $key = $configId.':'.$shiftName;
            if (isset($seen[$key])) {
                $errors["shift_assignments.{$index}.shift_name"][] = 'The same shift cannot be assigned twice.';
            }
            $seen[$key] = true;
        }

        return $errors;
    }

    private function syncShiftAssignments(PosUser $user, string $companyId, array $assignments): void
    {
        $configs = ShiftConfiguration::query()
            ->where('company_id', $companyId)
            ->whereIn('id', collect($assignments)->pluck('shift_configuration_id')->filter()->unique())
            ->get()
            ->keyBy(fn ($config) => (string) $config->id);

        $user->shiftAssignments()->delete();

        foreach ($assignments as $assignment) {
            $config = $configs->get((string) ($assignment['shift_configuration_id'] ?? ''));
            if (! $config) {
                continue;
            }

            PosUserShiftAssignment::create([
                'company_id' => $companyId,
                'branch_id' => $config->branch_id,
                'pos_user_id' => $user->id,
                'shift_configuration_id' => $config->id,
                'shift_name' => strtolower(trim((string) $assignment['shift_name'])),
            ]);
        }
    }

    private function posUserForCompany(string $posUserId, string $companyId): PosUser
    {
        return PosUser::query()
            ->where('company_id', $companyId)
            ->whereKey($posUserId)
            ->firstOrFail();
    }

    public function loginBackOffice(Request $request)
    {
        return $this->loginForWorkspace($request, 'back-office');
    }

    public function loginFrontOffice(Request $request)
    {
        return $this->loginForWorkspace($request, 'front-office');
    }

    private function loginForWorkspace(Request $request, string $workspace)
    {
        if ($request->filled('workspace') && (string) $request->input('workspace') !== $workspace) {
            return response()->json([
                'message' => 'The requested workspace does not match this login endpoint.',
                'code' => 'WORKSPACE_ENDPOINT_MISMATCH',
            ], 422);
        }

        $request->merge(['workspace' => $workspace]);

        return $this->login($request);
    }

    /**
     * Resolve the canonical Till -> Shop scope and its effective dynamic
     * authentication policy. The Shop column is the compatibility baseline;
     * matching database policies override it broad-to-specific through Till.
     *
     * @return array{scope: AuthorizationScope, methods: list<string>, mode: string, fingerprint: string, login_code: array<string, mixed>}
     */
    private function posAuthenticationContext(
        Company $company,
        PosTill $till,
        bool $forUpdate = false,
    ): array {
        return app(PosAuthenticationContextService::class)->resolve(
            $company,
            $till,
            $forUpdate
        );
    }

    private function credentialScopeForBranch(Company $company, ?string $branchId): AuthorizationScope
    {
        if ($branchId && Schema::hasTable('shops')) {
            $shop = Shop::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->first();
            if ($shop) {
                return app(EnterpriseScopeResolver::class)->fromShop($shop);
            }
        }

        return new AuthorizationScope(
            companyId: (string) $company->id,
            shopId: $branchId ?: null,
            branchId: $branchId ?: null,
        );
    }

    private function withEffectiveAuthorizationPresentation(
        PosUser $user,
        AuthorizationScope $scope,
        bool $platformSession = false
    ): PosUser {
        $authorization = app(EnterpriseAuthorizationService::class);
        $permissions = $authorization->effectivePermissionNames($user, $scope);
        $roleNames = $authorization->effectiveRoleNames($user, $scope);
        $effectiveSuperAdmin = $platformSession
            || ($authorization->usesScopedAssignments($user)
                ? $authorization->isEffectiveSuperAdmin($user, $scope)
                : $this->superAdmins->isSuperAdmin($user));

        $user->setAttribute('permissions', $permissions);
        $user->setAttribute('permission_names', $permissions);
        $user->setAttribute('role_permissions', $permissions);
        $user->setAttribute('effective_role_names', $roleNames);
        $user->setAttribute('is_platform_admin', $platformSession);
        $user->setAttribute('is_company_super_admin', $effectiveSuperAdmin);

        if ($authorization->usesScopedAssignments($user)) {
            // Do not serialize a stale legacy Super Admin label or permission
            // relationship after canonical assignments become authoritative.
            $user->setAttribute('user_type', $effectiveSuperAdmin ? 'super_admin' : 'scoped_user');
            if ($user->relationLoaded('role') && $user->role) {
                $effectiveRole = clone $user->role;
                $effectiveRole->setAttribute(
                    'name',
                    $effectiveSuperAdmin
                        ? 'Super Admin'
                        : (count($roleNames) === 1 ? $roleNames[0] : 'Scoped Access')
                );
                $effectiveRole->setRelation('permissions', collect($permissions));
                $user->setRelation('role', $effectiveRole);
            }
        }

        return $user;
    }

    public function frontOfficeLoginMode(Request $request)
    {
        return $this->loginMode($request);
    }

    public function loginMode(Request $request)
    {
        /** @var Company $company */
        $company = app('company');
        /** @var PosTill|null $till */
        $till = app()->bound('till') ? app('till') : null;
        if (! $till) {
            return response()->json([
                'message' => 'A registered till is required to resolve the POS login policy.',
                'code' => 'TILL_TOKEN_REQUIRED',
            ], 403);
        }

        try {
            $context = $this->posAuthenticationContext($company, $till);
        } catch (\DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'TILL_SCOPE_INVALID',
            ], 422);
        }

        return response()->json([
            'login_mode' => $context['mode'],
            'allowed_authentication_methods' => $context['methods'],
            'credential_policy' => ['login_code' => $context['login_code']],
            'policy_fingerprint' => $context['fingerprint'],
            'server_date' => now()->toDateString(),
            'license' => $this->licenseAccess->status($company),
        ]);
    }

    public function login(Request $request)
    {
        $this->normalizeLoginCode($request);
        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:1024',
            // The effective minimum/maximum and numeric-only flag are checked
            // after the Till -> Shop credential policy has been resolved.
            'login_code' => 'nullable|string|max:12',
            'branch_id' => 'nullable|exists:company_branches,id',
            'device_name' => 'nullable|string|max:255',
            'time' => 'nullable|date_format:H:i',
            'opening_float' => 'nullable|numeric|min:0',
            'continue_shift_session_id' => 'nullable|string',
            'force_new_shift' => 'nullable|boolean',
            'shift_name' => 'nullable|string|max:80',
            'workspace' => 'required|string|in:front-office,back-office',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hasLoginCode = $request->filled('login_code');
        $hasUsername = $request->filled('username');
        $hasPassword = $request->filled('password');
        $isFrontOffice = $request->workspace === 'front-office';

        if (! $isFrontOffice && $request->has('company_id')) {
            return response()->json([
                'message' => 'Company selection is only accepted through the authenticated company-selection endpoint.',
                'code' => 'COMPANY_SELECTION_TOKEN_REQUIRED',
            ], 422);
        }

        if (! $isFrontOffice && ($hasLoginCode || ! $hasUsername || ! $hasPassword)) {
            return response()->json([
                'message' => 'Back Office login requires a username and password.',
                'code' => 'BACK_OFFICE_PASSWORD_REQUIRED',
                'errors' => [
                    'credentials' => ['Use username and password for Back Office login.'],
                ],
            ], 422);
        }
        if ($isFrontOffice && ($hasLoginCode === ($hasUsername || $hasPassword))) {
            return response()->json([
                'message' => 'Provide either a login code or a username and password.',
                'code' => 'POS_CREDENTIAL_METHOD_REQUIRED',
                'errors' => [
                    'credentials' => ['Use exactly one configured POS login method.'],
                ],
            ], 422);
        }
        if ($isFrontOffice && ! $hasLoginCode && (! $hasUsername || ! $hasPassword)) {
            return response()->json([
                'message' => 'A username and password must be supplied together.',
                'code' => 'POS_PASSWORD_CREDENTIALS_INCOMPLETE',
            ], 422);
        }
        $authenticationMethod = $hasLoginCode
            ? AuthenticationPolicyService::METHOD_LOGIN_CODE
            : AuthenticationPolicyService::METHOD_USERNAME_PASSWORD;

        /** @var Company|null $company */
        $company = app()->bound('company') ? app('company') : null;
        $resolvedTill = app()->bound('till') ? app('till') : null;
        if ($isFrontOffice && ! $resolvedTill && $request->header('X-Till-Token') && $company) {
            $resolvedTill = PosTill::query()
                ->where('company_id', $company->id)
                ->where('registration_token', hash('sha256', (string) $request->header('X-Till-Token')))
                ->where('is_registered', true)
                ->where('is_active', true)
                ->first();
        }
        $deploymentMode = (string) config('salexa_sync.deployment_mode', 'standalone');
        $isCloudAndroidTerminal = $deploymentMode === 'cloud'
            && $isFrontOffice
            && $resolvedTill
            && SyncNode::query()
                ->where('company_id', $resolvedTill->company_id)
                ->where('branch_id', $resolvedTill->branch_id)
                ->where('till_id', $resolvedTill->id)
                ->where('deployment_mode', 'android')
                ->where('status', 'active')
                ->whereNotNull('activated_at')
                ->whereNull('revoked_at')
                ->exists();
        if (($deploymentMode === 'cloud' && $isFrontOffice && ! $isCloudAndroidTerminal)
            || ($deploymentMode === 'edge' && ! $isFrontOffice)) {
            return response()->json([
                'message' => $deploymentMode === 'cloud'
                    ? 'Use the local POS installation for Front Office login.'
                    : 'Use the central Back Office installation for Back Office login.',
                'code' => 'DEPLOYMENT_WORKSPACE_DENIED',
            ], 403);
        }

        if (! $company && ! $isFrontOffice) {
            $companyResolution = $this->resolveBackOfficeLoginCompany($request);
            if ($companyResolution instanceof JsonResponse) {
                return $companyResolution;
            }

            $company = $companyResolution;
            app()->instance('company', $company);
        }

        if (! $company) {
            return response()->json([
                'message' => 'The POS company context could not be resolved.',
                'code' => 'COMPANY_CONTEXT_REQUIRED',
            ], 422);
        }

        if ($isFrontOffice && ! $resolvedTill) {
            return response()->json([
                'message' => 'Front Office login requires a paired and registered till.',
                'code' => 'TILL_TOKEN_REQUIRED',
            ], 401);
        }

        if ($isFrontOffice && $resolvedTill) {
            if ($request->filled('branch_id')
                && (string) $request->branch_id !== (string) $resolvedTill->branch_id) {
                return response()->json([
                    'message' => 'This till is registered to a different branch.',
                    'code' => 'TILL_BRANCH_MISMATCH',
                ], 403);
            }
            $request->merge(['branch_id' => (string) $resolvedTill->branch_id]);
        }

        $posAuthContext = null;
        if ($isFrontOffice) {
            try {
                $posAuthContext = $this->posAuthenticationContext($company, $resolvedTill);
            } catch (\DomainException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'TILL_SCOPE_INVALID',
                ], 422);
            }

            if (! in_array($authenticationMethod, $posAuthContext['methods'], true)) {
                return response()->json([
                    'message' => 'This authentication method is not enabled for the till.',
                    'code' => 'AUTHENTICATION_METHOD_NOT_ALLOWED',
                    'login_mode' => $posAuthContext['mode'],
                    'allowed_authentication_methods' => $posAuthContext['methods'],
                ], 403);
            }

            if ($authenticationMethod === AuthenticationPolicyService::METHOD_LOGIN_CODE) {
                $codeErrors = app(CredentialPolicyService::class)->loginCodeFormatViolations(
                    (string) $request->login_code,
                    $posAuthContext['scope']
                );
                if ($codeErrors !== []) {
                    return response()->json([
                        'message' => 'Validation error',
                        'errors' => ['login_code' => $codeErrors],
                        'credential_policy' => ['login_code' => $posAuthContext['login_code']],
                    ], 422);
                }
            }
        }

        $branch = $request->filled('branch_id')
            ? CompanyBranch::with('company')
                ->where('company_id', $company->id)
                ->find($request->branch_id)
            : null;

        if ($request->filled('branch_id') && (! $branch || (string) $branch->company_id !== (string) $company->id)) {
            return response()->json(['message' => 'Branch not found for this company'], 404);
        }

        $user = null;
        $passwordLoginValid = false;
        $codeLoginValid = false;
        $loginCodeLookupNeedsRefresh = false;
        $activeLoginCodeLookup = null;

        if ($request->filled('login_code')) {
            try {
                $candidateUsers = $this->loginCodeCandidates(
                    $company,
                    (string) $request->login_code
                );
            } catch (LogicException) {
                return response()->json([
                    'message' => 'POS code login is not configured on this installation.',
                    'code' => 'LOGIN_CODE_LOOKUP_KEY_REQUIRED',
                ], 503);
            }

            $matchedUsers = collect();

            foreach ($candidateUsers as $candidateUser) {
                if (Hash::check((string) $request->login_code, $candidateUser->login_code)) {
                    $matchedUsers->push($candidateUser);
                }
            }

            if ($matchedUsers->count() > 1) {
                return response()->json([
                    'message' => 'Duplicate login code configuration found. Please assign a unique code to each POS user.',
                ], 409);
            }

            if ($matchedUsers->count() === 1) {
                $user = $matchedUsers->first();
                $codeLoginValid = true;
                if ($this->supportsLoginCodeBlindIndex()) {
                    $activeLoginCodeLookup = $this->activeLoginCodeDigest(
                        (string) $company->id,
                        (string) $request->login_code
                    );
                    $loginCodeLookupNeedsRefresh = ! hash_equals(
                        (string) ($user->login_code_lookup ?? ''),
                        $activeLoginCodeLookup['login_code_lookup']
                    ) || (string) ($user->login_code_lookup_key_id ?? '')
                        !== $activeLoginCodeLookup['login_code_lookup_key_id'];
                }
            }
        } else {
            $candidateQuery = $this->loginCandidateQuery(
                $company,
                $request->workspace === 'back-office'
            )
                ->where('username', $request->username);

            $resolvedBackOfficeUserId = $request->attributes->get('resolved_back_office_user_id');
            if (! $isFrontOffice && is_scalar($resolvedBackOfficeUserId)
                && trim((string) $resolvedBackOfficeUserId) !== '') {
                $candidateQuery->whereKey((string) $resolvedBackOfficeUserId);
            }

            $candidateUsers = $candidateQuery->get();

            $matchedUsers = $candidateUsers->filter(
                fn (PosUser $candidateUser): bool => $request->filled('password')
                    && Hash::check((string) $request->password, $candidateUser->password)
            )->values();

            if ($matchedUsers->count() > 1) {
                return response()->json([
                    'message' => 'The username is ambiguous for this company. Contact the system administrator.',
                    'code' => 'AMBIGUOUS_LOGIN_IDENTITY',
                ], 409);
            }

            $user = $matchedUsers->first();

            $passwordLoginValid = $user !== null;
        }

        if (! $user || (! $passwordLoginValid && ! $codeLoginValid)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        if (! $user->is_active || ! $user->can_login_pos) {
            return response()->json(['message' => 'User is not allowed to login'], 403);
        }

        $isSuperAdmin = $this->superAdmins->isSuperAdmin($user);
        $isPlatformAdministrator = $this->superAdmins->isPlatformAdministrator($user);
        $enterpriseAuthorization = app(EnterpriseAuthorizationService::class);
        $usesScopedAssignments = ! $isPlatformAdministrator
            && $enterpriseAuthorization->usesScopedAssignments($user);
        $isCrossCompanyLogin = (string) $user->company_id !== (string) $company->id;

        // A platform owner may select another tenant only in Back Office.
        // Front Office is always bound to the paired company's till and must
        // never authenticate a user record from another company.
        if ($isCrossCompanyLogin && ($isFrontOffice || ! $isPlatformAdministrator)) {
            return response()->json([
                'message' => 'User does not belong to this company',
                'code' => 'COMPANY_ACCESS_DENIED',
            ], 403);
        }

        $licenseStatus = $this->licenseAccess->enforce($company, $user);

        if (! $licenseStatus['valid']) {
            return response()->json(
                $this->licenseAccess->responsePayload($licenseStatus),
                423
            );
        }

        // Android FOH needs only expiry/warning facts. Even when the platform
        // owner signs in at a till, never serialize the human-readable license
        // key (or any entitlement/signature material) to the mobile client.
        if (strtolower(trim((string) $request->header('X-Client-Platform'))) === 'android') {
            $licenseStatus['license_key'] = null;
        }

        $hasWorkspaceAccess = $isFrontOffice
            ? (bool) $user->can_access_front_office
            : (bool) $user->can_access_back_office;

        if (! $isPlatformAdministrator
            && ! ($isSuperAdmin && ! $usesScopedAssignments)
            && ! $hasWorkspaceAccess) {
            $workspaceName = $isFrontOffice ? 'Front Office' : 'Back Office';

            return response()->json([
                'message' => "This user is not assigned to the {$workspaceName} workspace.",
                'code' => 'WORKSPACE_ACCESS_DENIED',
            ], 403);
        }

        if (! $isPlatformAdministrator && (string) $user->company_id !== (string) $company->id) {
            return response()->json(['message' => 'User does not belong to this company'], 403);
        }

        if (! $branch) {
            if ($isPlatformAdministrator || ($isSuperAdmin && ! $usesScopedAssignments)) {
                $allowedBranches = $company->branches()->orderBy('branch_name')->get();
            } elseif ($usesScopedAssignments) {
                $companyBranchIds = $company->branches()
                    ->orderBy('branch_name')
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->all();
                $allowedBranchIds = $enterpriseAuthorization->accessibleBranchIds(
                    $user,
                    (string) $company->id,
                    $companyBranchIds
                );
                $allowedBranches = CompanyBranch::query()
                    ->where('company_id', $company->id)
                    ->whereIn('id', $allowedBranchIds)
                    ->orderBy('branch_name')
                    ->get();
            } else {
                $allowedBranches = $user->branches()
                    ->where('company_branches.company_id', $company->id)
                    ->orderBy('company_branches.branch_name')
                    ->get();
            }

            if (! $isSuperAdmin
                && ! $usesScopedAssignments
                && $allowedBranches->isEmpty()
                && $user->branch_id) {
                $primaryBranch = CompanyBranch::query()
                    ->where('company_id', $company->id)
                    ->find($user->branch_id);

                if ($primaryBranch) {
                    $allowedBranches = collect([$primaryBranch]);
                }
            }

            if ($allowedBranches->isEmpty()) {
                return response()->json([
                    'message' => $isPlatformAdministrator
                        ? 'Create at least one branch for this company before opening its Back Office.'
                        : 'No branches are assigned to this user',
                    'code' => $isPlatformAdministrator
                        ? 'COMPANY_BRANCH_REQUIRED'
                        : 'BRANCH_ACCESS_DENIED',
                ], $isPlatformAdministrator ? 409 : 403);
            }

            if ($allowedBranches->count() > 1) {
                return response()->json([
                    'message' => 'Select a branch to continue',
                    'branch_selection_required' => true,
                    'branches' => $allowedBranches->values(),
                    'server_date' => now()->toDateString(),
                    'license' => $licenseStatus,
                ]);
            }

            $branch = $allowedBranches->first();
            $request->merge(['branch_id' => $branch->id]);
        }

        $loginAuthorizationScope = $isFrontOffice
            ? $posAuthContext['scope']
            : $this->credentialScopeForBranch($company, (string) $branch->id);
        if ($usesScopedAssignments) {
            if (! $enterpriseAuthorization->hasScopeAccess($user, $loginAuthorizationScope)) {
                return response()->json([
                    'message' => 'User does not have an active role assignment for this enterprise scope.',
                    'code' => 'SCOPED_ACCESS_DENIED',
                ], 403);
            }
        } elseif (! $isSuperAdmin) {
            $hasBranchAccess = $user->branches()
                ->where('company_branches.id', $branch->id)
                ->exists();

            $hasPrimaryBranchAccess = (string) $user->branch_id === (string) $branch->id;

            if (! $hasBranchAccess && ! $hasPrimaryBranchAccess) {
                return response()->json(['message' => 'User does not have access to this branch'], 403);
            }
        }

        $shiftConfig = $isFrontOffice
            ? $this->shiftResolverService->getConfig($branch->company_id, $branch->id)
            : null;
        if ($shiftConfig?->enable_shifts && ! $resolvedTill) {
            return response()->json([
                'message' => 'A registered till is required to open or resume a shift.',
                'code' => 'TILL_TOKEN_REQUIRED',
            ], 403);
        }
        $shiftPolicy = $this->shiftPolicyService->get($branch->company_id, $branch->id);

        $shiftName = null;
        $activeShiftSession = null;
        $shiftWarning = null;
        $shiftActionRequired = null;
        // Code login never collects or accepts a caller-supplied float. Keep it
        // deterministic at zero; password login retains the configured prompt.
        $openingFloat = $codeLoginValid
            ? 0
            : ($request->filled('opening_float') ? $request->input('opening_float') : null);

        if ($shiftConfig && $shiftConfig->enable_shifts) {
            $shiftName = $this->shiftResolverService->detect(
                $branch->company_id,
                $branch->id,
                $request->time,
                $request->shift_name
            );

            if (! $shiftName) {
                return response()->json([
                    'message' => 'No active shift found for this branch at the current time',
                ], 403);
            }

            if (! $isSuperAdmin) {
                $isAssignedToShift = $user->shiftAssignments()
                    ->where('shift_configuration_id', $shiftConfig->id)
                    ->where('shift_name', strtolower($shiftName))
                    ->exists();

                if (! $isAssignedToShift) {
                    return response()->json([
                        'message' => 'This user is not assigned to the current shift.',
                        'code' => 'SHIFT_ACCESS_DENIED',
                        'shift' => $shiftName,
                    ], 403);
                }
            }

            $existingOpenShiftForUser = PosUserShiftSession::where('company_id', $branch->company_id)
                ->where('branch_id', $branch->id)
                ->where('till_id', $resolvedTill->id)
                ->where('pos_user_id', $user->id)
                ->where('status', 'open')
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first();

            $openBranchShifts = PosUserShiftSession::query()
                ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
                ->select([
                    'pos_user_shift_sessions.*',
                    DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
                ])
                ->where('pos_user_shift_sessions.company_id', $branch->company_id)
                ->where('pos_user_shift_sessions.branch_id', $branch->id)
                ->where('pos_user_shift_sessions.till_id', $resolvedTill->id)
                ->where('pos_user_shift_sessions.status', 'open')
                ->whereNull('pos_user_shift_sessions.closed_at')
                ->orderBy('pos_user_shift_sessions.opened_at')
                ->get();

            if ($existingOpenShiftForUser && $shiftPolicy['allow_continue_unclosed_shift']) {
                $activeShiftSession = $existingOpenShiftForUser;
                $shiftWarning = 'You are continuing your existing open shift.';
                $shiftActionRequired = 'continue_existing_shift';
            } else {
                if ($shiftConfig->require_opening_float && $openingFloat === null) {
                    return response()->json([
                        'message' => 'Opening float is required to open this shift',
                        'code' => 'OPENING_FLOAT_REQUIRED',
                        'shift' => $shiftName,
                        'shift_warning' => $shiftWarning,
                        'shift_action_required' => $shiftActionRequired,
                        'unclosed_shifts' => $openBranchShifts,
                    ], 422);
                }
            }
        }

        DB::beginTransaction();

        try {
            /** @var PosUser|null $lockedUser */
            $lockedUser = PosUser::query()->whereKey($user->id)->lockForUpdate()->first();
            $credentialStillValid = $lockedUser && (
                $codeLoginValid
                    ? Hash::check((string) $request->login_code, (string) $lockedUser->login_code)
                    : Hash::check((string) $request->password, (string) $lockedUser->password)
            );
            if (! $credentialStillValid || ! $lockedUser->is_active || ! $lockedUser->can_login_pos) {
                DB::rollBack();

                return response()->json(['message' => 'Invalid login credentials'], 401);
            }
            $user = $lockedUser->load(['role.permissions', 'branches']);

            if ($isFrontOffice) {
                $transactionTill = $resolvedTill;
                if (Schema::hasTable('pos_tills') && $resolvedTill->exists) {
                    $transactionTill = PosTill::query()
                        ->whereKey($resolvedTill->getKey())
                        ->where('company_id', $company->id)
                        ->where('branch_id', $branch->id)
                        ->where('is_registered', true)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();
                }
                if (! $transactionTill) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'The registered till became unavailable during login.',
                        'code' => 'TILL_NOT_ACTIVE',
                    ], 403);
                }

                try {
                    $lockedPolicy = $this->posAuthenticationContext(
                        $company,
                        $transactionTill,
                        true
                    );
                } catch (\DomainException $exception) {
                    DB::rollBack();

                    return response()->json([
                        'message' => $exception->getMessage(),
                        'code' => 'TILL_SCOPE_INVALID',
                    ], 422);
                }
                if (! in_array($authenticationMethod, $lockedPolicy['methods'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'The authentication policy changed during login. Try again.',
                        'code' => 'AUTHENTICATION_POLICY_CHANGED',
                        'login_mode' => $lockedPolicy['mode'],
                        'allowed_authentication_methods' => $lockedPolicy['methods'],
                    ], 409);
                }
                $resolvedTill = $transactionTill;
                $posAuthContext = $lockedPolicy;
                $loginAuthorizationScope = $lockedPolicy['scope'];
            }

            if ($usesScopedAssignments
                && ! $enterpriseAuthorization->hasScopeAccess($user, $loginAuthorizationScope)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'The enterprise access assignment changed during login. Try again.',
                    'code' => 'SCOPED_ACCESS_CHANGED',
                ], 409);
            }

            if ($codeLoginValid && $loginCodeLookupNeedsRefresh && $activeLoginCodeLookup !== null) {
                $user->forceFill($activeLoginCodeLookup)->save();
            }

            $loginUpdates = ['last_login_at' => now()];
            if (! $isPlatformAdministrator) {
                $loginUpdates['company_id'] = $branch->company_id;
                $loginUpdates['branch_id'] = $branch->id;
            }
            $user->update($loginUpdates);

            if ($shiftConfig && $shiftConfig->enable_shifts) {
                if (! $activeShiftSession) {
                    /*
                     | Every successful Front Office login must own an open shift
                     | session. Other cashiers may legitimately work the same branch
                     | at the same time and must never prevent this user's session.
                     */
                    $activeShiftSession = PosUserShiftSession::create([
                        'company_id' => $branch->company_id,
                        'branch_id' => $branch->id,
                        'till_id' => $resolvedTill->id,
                        'shift_configuration_id' => $shiftConfig->id,
                        'pos_user_id' => $user->id,
                        'shift_name' => $shiftName,
                        'opened_at' => now(),
                        'opening_float' => $openingFloat,
                        'status' => 'open',
                    ]);
                } elseif (! $activeShiftSession->shift_configuration_id) {
                    $activeShiftSession->update(['shift_configuration_id' => $shiftConfig->id]);
                }
            } elseif ($isFrontOffice && $resolvedTill?->id) {
                // Shops without shift rules still sell inside a session: open
                // or resume the cashier's all-day shift on this till.
                $activeShiftSession = app(ShiftSessionOpener::class)->openFor(
                    (string) $branch->company_id,
                    (string) $branch->id,
                    (string) $resolvedTill->id,
                    (string) $user->id,
                    openingFloat: $openingFloat ?? 0,
                );
            }

            $openBranchShifts = $isFrontOffice
                ? PosUserShiftSession::query()
                    ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
                    ->select([
                        'pos_user_shift_sessions.*',
                        DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
                    ])
                    ->where('pos_user_shift_sessions.company_id', $branch->company_id)
                    ->where('pos_user_shift_sessions.branch_id', $branch->id)
                    ->when($resolvedTill, fn ($query) => $query->where('pos_user_shift_sessions.till_id', $resolvedTill->id))
                    ->where('pos_user_shift_sessions.status', 'open')
                    ->whereNull('pos_user_shift_sessions.closed_at')
                    ->orderBy('pos_user_shift_sessions.opened_at')
                    ->get()
                : collect();

            $abilities = $this->superAdmins->tokenAbilitiesForWorkspace(
                $user,
                (string) $request->workspace
            );

            $issuedToken = $user->createToken(
                $request->device_name ?? 'pos-device',
                $abilities
            );
            $authSession = app(AuthSessionService::class)->issue(
                $user,
                $issuedToken,
                (string) $request->workspace,
                $authenticationMethod,
                $loginAuthorizationScope,
                $request,
            );
            $this->recordAuthenticationEvent(
                'login_success',
                'success',
                $loginAuthorizationScope,
                $request,
                $user,
                $authSession?->id,
                null,
                $authenticationMethod,
            );
            $token = $issuedToken->plainTextToken;

            $hasPlatformSessionAccess = $this->superAdmins->hasPlatformSessionAccess($user, $abilities);
            // Company Super Admin is an operational tenant role. Keep it
            // separate from the allow-listed platform owner flag so clients
            // can mirror company permission bypasses without granting license
            // or cross-company platform authority.
            $hasCompanySuperAdminAccess = $usesScopedAssignments
                ? $enterpriseAuthorization->isEffectiveSuperAdmin($user, $loginAuthorizationScope)
                : $this->superAdmins->isSuperAdmin($user);
            $responseUser = $user->fresh()->load([
                'company',
                'branch',
                'role.permissions',
                'branches',
                'shiftAssignments.shiftConfiguration.branch',
                'activeShiftSession.shiftConfiguration',
            ]);
            $responseUser->setAttribute('is_platform_admin', $hasPlatformSessionAccess);
            $responseUser->setAttribute('is_company_super_admin', $hasCompanySuperAdminAccess);
            $responseUser = $this->withEffectiveAuthorizationPresentation(
                $responseUser,
                $loginAuthorizationScope,
                $hasPlatformSessionAccess
            );
            if ($isFrontOffice && $resolvedTill) {
                $responseUser->setRelation('activeShiftSession', $activeShiftSession);
            }

            $responseCompany = $this->companyForAuthenticationResponse($company);
            if ($responseUser->relationLoaded('company') && $responseUser->company) {
                $responseUser->company->makeHidden('company_code');
            }

            DB::commit();

            $activeShiftPayload = $activeShiftSession
                ? $activeShiftSession->fresh()->load('shiftConfiguration')
                : null;
            $shiftSalesCutoff = $activeShiftPayload
                ? $this->shiftPolicyService->resolveSalesCutoff(
                    $shiftPolicy,
                    $activeShiftPayload,
                    $activeShiftPayload->shiftConfiguration
                )
                : null;
            $shiftSalesBlockReason = $activeShiftPayload
                ? $this->shiftPolicyService->salesBlockReason(
                    (string) $branch->company_id,
                    (string) $branch->id,
                    $activeShiftPayload
                )
                : null;

            return response()->json([
                'message' => 'Login successful',
                'token_type' => 'Bearer',
                'token' => $token,
                'workspace' => $request->workspace,
                'authentication_method' => $authenticationMethod,
                'authentication_policy_fingerprint' => $posAuthContext['fingerprint'] ?? null,
                'is_platform_admin' => $hasPlatformSessionAccess,
                'is_company_super_admin' => $hasCompanySuperAdminAccess,
                'company_id' => (string) $company->id,
                'branch_id' => (string) $branch->id,
                'company' => $responseCompany,
                'branch' => $branch,
                'workspace_context' => [
                    'company_id' => (string) $company->id,
                    'branch_id' => (string) $branch->id,
                    'company' => $responseCompany,
                    'branch' => $branch,
                ],
                'server_date' => now()->toDateString(),
                'server_time' => now()->toIso8601String(),
                'license' => $licenseStatus,
                'sales_screen_layout' => $this->salesScreenLayout(
                    (string) $branch->company_id,
                    (string) $branch->id
                ),
                'active_shift' => $activeShiftPayload,
                // Android must enforce the same cashier-shift cutoff while it
                // is offline. Without this signed login fact it can accept a
                // sale which Central is required to reject a few milliseconds
                // later, leaving the durable outbox unable to advance.
                'shift_sales_cutoff_at' => isset($shiftSalesCutoff['at'])
                    ? $shiftSalesCutoff['at']->toIso8601String()
                    : null,
                'shift_sales_block_reason' => $shiftSalesBlockReason,
                'shift_status' => $activeShiftPayload ? 'open' : 'not_open',
                'shift_warning' => $shiftWarning,
                'shift_action_required' => $shiftActionRequired,
                'shift_policy' => $shiftPolicy,
                'unclosed_shifts' => $openBranchShifts,
                'user' => $responseUser,
            ]);
        } catch (QueryException $e) {
            DB::rollBack();

            if ($this->isLoginCodeLookupConflict($e)) {
                return response()->json([
                    'message' => 'Duplicate login code configuration found. Please assign a unique code to each POS user.',
                ], 409);
            }

            report($e);

            return response()->json([
                'message' => 'Login could not be completed. Please try again or contact support.',
                'code' => 'LOGIN_FAILED',
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();

            report($e);

            return response()->json([
                'message' => 'Login could not be completed. Please try again or contact support.',
                'code' => 'LOGIN_FAILED',
            ], 500);
        }
    }

    /**
     * Resolve an ordinary Back Office account from its persisted company
     * assignment. The username remains tenant-scoped for POS-only users, so
     * every Back Office-capable candidate is slow-hash verified and ambiguous
     * matches fail closed instead of selecting the first tenant row.
     */
    private function resolveBackOfficeLoginCompany(Request $request)
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud'
            || $request->workspace !== 'back-office') {
            return response()->json([
                'message' => 'Back Office login is unavailable on this installation.',
                'code' => 'DEPLOYMENT_WORKSPACE_DENIED',
            ], 403);
        }

        $candidateUsers = $this->backOfficeIdentityQuery((string) $request->username)->get();
        $matchedUsers = $candidateUsers
            ->filter(fn (PosUser $candidateUser): bool => Hash::check(
                (string) $request->password,
                (string) $candidateUser->password
            ))
            ->values();

        if ($matchedUsers->count() > 1) {
            $this->recordAuthenticationEvent(
                'login_failure',
                'failure',
                AuthorizationScope::global(),
                $request,
                null,
                null,
                'AMBIGUOUS_LOGIN_IDENTITY',
            );

            return response()->json([
                'message' => 'These credentials do not identify a unique Back Office account. Contact the system administrator.',
                'code' => 'AMBIGUOUS_LOGIN_IDENTITY',
            ], 409);
        }

        /** @var PosUser|null $user */
        $user = $matchedUsers->first();
        if (! $user) {
            $this->recordAuthenticationEvent(
                'login_failure',
                'failure',
                AuthorizationScope::global(),
                $request,
                null,
                null,
                'INVALID_LOGIN_CREDENTIALS',
            );

            return response()->json(['message' => 'Invalid login credentials'], 401);
        }
        if (! $user->is_active || ! $user->can_login_pos) {
            return response()->json(['message' => 'User is not allowed to login'], 403);
        }

        if ($this->superAdmins->isPlatformAdministrator($user)) {
            return $this->platformCompanySelectionChallenge($request, $user);
        }

        if (! $user->can_access_back_office) {
            return response()->json([
                'message' => 'This user is not assigned to the Back Office workspace.',
                'code' => 'WORKSPACE_ACCESS_DENIED',
            ], 403);
        }

        $companyId = trim((string) $user->company_id);
        if ($companyId === '') {
            return response()->json([
                'message' => 'This Back Office account is not assigned to a company. Contact the system administrator.',
                'code' => 'COMPANY_ASSIGNMENT_REQUIRED',
            ], 403);
        }

        $company = Company::query()
            ->with(['branches', 'activeLicense'])
            ->find($companyId);
        if (! $company) {
            return response()->json([
                'message' => 'This Back Office account cannot be opened. Contact the system administrator.',
                'code' => 'COMPANY_ASSIGNMENT_INVALID',
            ], 403);
        }

        $request->attributes->set('resolved_back_office_user_id', (string) $user->id);
        $request->merge(['username' => (string) $user->username]);

        return $company;
    }

    private function backOfficeIdentityQuery(string $username): Builder
    {
        $platformAdministratorIds = $this->superAdmins->platformAdministratorIds();
        $superAdminNames = [
            'super_admin',
            'super_administrator',
            'super-admin',
            'super-administrator',
            'Super Admin',
            'Super Administrator',
        ];

        return PosUser::query()
            ->whereRaw('LOWER(username) = ?', [Str::lower(trim($username))])
            ->where(function (Builder $query) use ($platformAdministratorIds, $superAdminNames): void {
                $query->where('can_access_back_office', true)
                    ->orWhereIn('user_type', $superAdminNames)
                    ->orWhereHas('role', fn (Builder $roleQuery) => $roleQuery
                        ->whereIn('name', $superAdminNames));

                if ($platformAdministratorIds !== []) {
                    $query->orWhereIn('id', $platformAdministratorIds);
                }
            });
    }

    private function backOfficeUsernameAlreadyUsed(string $username, ?string $ignoreUserId = null): bool
    {
        return $this->backOfficeIdentityQuery($username)
            ->when($ignoreUserId, fn (Builder $query) => $query->whereKeyNot($ignoreUserId))
            ->exists();
    }

    private function acquireBackOfficeUsernameLock(string $username): ?string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        $lockName = 'servenest:bo-user:'.substr(hash('sha256', Str::lower(trim($username))), 0, 40);
        $result = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new \RuntimeException('The Back Office username lock could not be acquired.');
        }

        return $lockName;
    }

    private function releaseBackOfficeUsernameLock(?string $lockName): void
    {
        if ($lockName === null || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    }

    private function platformCompanySelectionChallenge(Request $request, PosUser $user)
    {
        $expiresAt = now()->addMinutes(5);
        $abilities = [
            'workspace:back-office',
            'platform:admin',
            'platform:select-company',
        ];
        $issuedToken = $user->createToken(
            $request->device_name ?? 'back-office-company-selection',
            $abilities,
            $expiresAt,
        );
        $authSession = app(AuthSessionService::class)->issue(
            $user,
            $issuedToken,
            'back-office',
            AuthenticationPolicyService::METHOD_USERNAME_PASSWORD,
            AuthorizationScope::global(),
            $request,
        );
        if ($authSession) {
            $authSession->forceFill(['expires_at' => $expiresAt])->saveQuietly();
        }
        $this->recordAuthenticationEvent(
            'login_success',
            'success',
            AuthorizationScope::global(),
            $request,
            $user,
            $authSession?->id,
            null,
            AuthenticationPolicyService::METHOD_USERNAME_PASSWORD,
        );
        $user->forceFill(['last_login_at' => now()])->save();

        $companies = Company::query()
            ->select(['id', 'company_name', 'logo', 'city', 'country', 'status'])
            ->orderBy('company_name')
            ->get();

        return response()->json([
            'message' => 'Select a company to open its Back Office.',
            'token_type' => 'Bearer',
            'selection_token' => $issuedToken->plainTextToken,
            'selection_token_expires_at' => $expiresAt->toIso8601String(),
            'workspace' => 'back-office',
            'is_platform_admin' => true,
            'company_selection_required' => true,
            'platform_context_required' => true,
            'companies' => $companies,
            'server_date' => now()->toDateString(),
        ]);
    }

    /**
     * Rotate a short-lived platform selection token into a durable tenant
     * session. The selected company lives in auth_sessions.company_id, so no
     * company code or mutable browser header participates in later requests.
     */
    public function selectBackOfficeCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => ['required', 'string', 'max:64'],
            'branch_id' => ['nullable', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var PosUser|null $user */
        $user = $request->user();
        $bootstrapToken = $user?->currentAccessToken();
        if (! $user
            || ! $this->superAdmins->hasPlatformSessionAccess($user)
            || ! $bootstrapToken
            || ! method_exists($bootstrapToken, 'can')
            || ! $bootstrapToken->can('platform:select-company')) {
            return response()->json([
                'message' => 'A valid company-selection session is required.',
                'code' => 'COMPANY_SELECTION_TOKEN_REQUIRED',
            ], 403);
        }

        $company = Company::query()
            ->with(['branches', 'activeLicense'])
            ->find((string) $request->company_id);
        if (! $company) {
            return response()->json([
                'message' => 'The selected company is unavailable.',
                'code' => 'COMPANY_SELECTION_INVALID',
            ], 422);
        }

        $licenseStatus = $this->licenseAccess->enforce($company, $user);
        if (! $licenseStatus['valid']) {
            return response()->json(
                $this->licenseAccess->responsePayload($licenseStatus),
                423
            );
        }

        $branches = $company->branches()->orderBy('branch_name')->get();
        if ($branches->isEmpty()) {
            return response()->json([
                'message' => 'Create at least one branch for this company before opening its Back Office.',
                'code' => 'COMPANY_BRANCH_REQUIRED',
            ], 409);
        }

        $branch = null;
        if ($request->filled('branch_id')) {
            $branch = $branches->first(
                fn (CompanyBranch $candidate): bool => (string) $candidate->id === (string) $request->branch_id
            );
            if (! $branch) {
                return response()->json([
                    'message' => 'The selected branch is unavailable for this company.',
                    'code' => 'BRANCH_SELECTION_INVALID',
                ], 422);
            }
        } elseif ($branches->count() > 1) {
            return response()->json([
                'message' => 'Select a branch to continue.',
                'company_selection_required' => false,
                'branch_selection_required' => true,
                'company_id' => (string) $company->id,
                'company' => $this->companyForAuthenticationResponse($company),
                'branches' => $branches->values(),
                'server_date' => now()->toDateString(),
            ]);
        } else {
            $branch = $branches->first();
        }

        $authorizationScope = $this->credentialScopeForBranch(
            $company,
            (string) $branch->id
        );
        $salesScreenLayout = $this->salesScreenLayout(
            (string) $company->id,
            (string) $branch->id
        );

        DB::beginTransaction();
        try {
            $user->forceFill(['last_login_at' => now()])->save();
            $abilities = $this->superAdmins->tokenAbilitiesForWorkspace($user, 'back-office');
            $issuedToken = $user->createToken(
                $request->device_name ?? 'central-back-office',
                $abilities,
            );
            $tenantAuthSession = app(AuthSessionService::class)->issue(
                $user,
                $issuedToken,
                'back-office',
                AuthenticationPolicyService::METHOD_USERNAME_PASSWORD,
                $authorizationScope,
                $request,
            );
            if (Schema::hasTable('authentication_events')) {
                app(AuthenticationEventRecorder::class)->record(
                    'platform_company_selected',
                    'success',
                    $authorizationScope,
                    $user,
                    $user,
                    [
                        'auth_session_id' => $tenantAuthSession?->id,
                        'channel' => 'back_office',
                        'workspace' => 'back-office',
                        'authentication_method' => AuthenticationPolicyService::METHOD_USERNAME_PASSWORD,
                        'identifier' => (string) $user->username,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'metadata' => [
                            'selected_company_id' => (string) $company->id,
                            'selected_branch_id' => (string) $branch->id,
                        ],
                    ]
                );
            }
            app(AuthSessionService::class)->revokeCurrent(
                $user,
                $bootstrapToken,
                'company_selected'
            );

            $responseCompany = $this->companyForAuthenticationResponse($company);
            $responseUser = $user->fresh()->load('role.permissions');
            $responseUser->setAttribute('company_id', (string) $company->id);
            $responseUser->setAttribute('branch_id', (string) $branch->id);
            $responseUser->setRelation('company', $responseCompany);
            $responseUser->setRelation('branch', $branch);
            $responseUser->setRelation('branches', $branches);
            $responseUser->setAttribute('is_platform_admin', true);
            $responseUser->setAttribute('is_company_super_admin', true);
            $responseUser = $this->withEffectiveAuthorizationPresentation(
                $responseUser,
                $authorizationScope,
                true
            );

            DB::commit();

            return response()->json([
                'message' => 'Login successful',
                'token_type' => 'Bearer',
                'token' => $issuedToken->plainTextToken,
                'workspace' => 'back-office',
                'authentication_method' => AuthenticationPolicyService::METHOD_USERNAME_PASSWORD,
                'is_platform_admin' => true,
                'is_company_super_admin' => true,
                'company_selection_required' => false,
                'platform_context_required' => false,
                'company_id' => (string) $company->id,
                'branch_id' => (string) $branch->id,
                'company' => $responseCompany,
                'branch' => $branch,
                'workspace_context' => [
                    'company_id' => (string) $company->id,
                    'branch_id' => (string) $branch->id,
                    'company' => $responseCompany,
                    'branch' => $branch,
                ],
                'server_date' => now()->toDateString(),
                'server_time' => now()->toIso8601String(),
                'license' => $licenseStatus,
                'sales_screen_layout' => $salesScreenLayout,
                'user' => $responseUser,
            ]);
        } catch (\Throwable $exception) {
            DB::rollBack();
            report($exception);

            return response()->json([
                'message' => 'Company selection could not be completed. Sign in and try again.',
                'code' => 'COMPANY_SELECTION_FAILED',
            ], 500);
        }
    }

    private function companyForAuthenticationResponse(Company $company): Company
    {
        $responseCompany = clone $company;
        $responseCompany->makeHidden('company_code');

        return $responseCompany;
    }

    private function recordAuthenticationEvent(
        string $eventType,
        string $outcome,
        AuthorizationScope $scope,
        Request $request,
        ?PosUser $user = null,
        ?string $authSessionId = null,
        ?string $reasonCode = null,
        string $authenticationMethod = AuthenticationPolicyService::METHOD_USERNAME_PASSWORD,
    ): void {
        if (! Schema::hasTable('authentication_events')) {
            return;
        }

        try {
            $identifier = $request->filled('username')
                ? (string) $request->username
                : (string) $request->login_code;
            app(AuthenticationEventRecorder::class)->record(
                $eventType,
                $outcome,
                $scope,
                $user,
                $user,
                [
                    'auth_session_id' => $authSessionId,
                    'channel' => $request->workspace === 'front-office' ? 'pos' : 'back_office',
                    'workspace' => (string) $request->workspace,
                    'authentication_method' => $authenticationMethod,
                    'identifier' => $identifier,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'reason_code' => $reasonCode,
                ]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $isPlatformAdmin = $this->superAdmins->hasPlatformSessionAccess($user);
        $company = app()->bound('company') ? app('company') : null;
        $resolvedBranchId = (string) ($request->attributes->get('company_branch_id')
            ?: $request->header('X-Branch-ID')
            ?: $request->input('branch_id')
            ?: $user->branch_id);
        $presentationScope = $company
            ? $this->credentialScopeForBranch(
                $company,
                $resolvedBranchId
            )
            : AuthorizationScope::global();
        $isCompanySuperAdmin = $company
            ? app(EnterpriseAuthorizationService::class)->isEffectiveSuperAdmin(
                $user,
                $presentationScope
            )
            : $this->superAdmins->isSuperAdmin($user);
        $responseUser = clone $user;
        $responseUser->load([
            'company',
            'branch',
            'role.permissions',
            'branches',
            'shiftAssignments.shiftConfiguration.branch',
            'activeShiftSession.shiftConfiguration',
        ]);
        $responseUser->setAttribute('is_platform_admin', $isPlatformAdmin);
        $responseUser->setAttribute('is_company_super_admin', $isCompanySuperAdmin);
        if ($company) {
            $responseUser = $this->withEffectiveAuthorizationPresentation(
                $responseUser,
                $presentationScope,
                $isPlatformAdmin
            );
        }
        $responseCompany = $company
            ? $this->companyForAuthenticationResponse($company)
            : null;
        $responseBranch = null;
        if ($company && $resolvedBranchId !== '') {
            $responseBranch = CompanyBranch::query()
                ->where('company_id', $company->id)
                ->find($resolvedBranchId);
        }
        if ($company && $isPlatformAdmin) {
            $responseUser->setAttribute('company_id', (string) $company->id);
            $responseUser->setAttribute('branch_id', $responseBranch?->id);
            $responseUser->setRelation('company', $responseCompany);
            $responseUser->setRelation('branch', $responseBranch);
            $responseUser->setRelation(
                'branches',
                Schema::hasTable('company_branches')
                    ? $company->branches()->orderBy('branch_name')->get()
                    : collect()
            );
        } elseif ($responseUser->relationLoaded('company') && $responseUser->company) {
            $responseUser->company->makeHidden('company_code');
        }
        if ($isPlatformAdmin && ! $company) {
            $responseUser = $this->withoutTenantPresentationContext($responseUser);
        }

        return response()->json([
            'user' => $responseUser,
            'is_platform_admin' => $isPlatformAdmin,
            'is_company_super_admin' => $isCompanySuperAdmin,
            'company_id' => $company ? (string) $company->id : null,
            'branch_id' => $responseBranch?->id,
            'company' => $responseCompany,
            'branch' => $responseBranch,
            'workspace_context' => $company ? [
                'company_id' => (string) $company->id,
                'branch_id' => $responseBranch?->id,
                'company' => $responseCompany,
                'branch' => $responseBranch,
            ] : null,
            'platform_context_required' => $isPlatformAdmin && ! $company,
            'server_date' => now()->toDateString(),
            'license' => ! $company
                ? null
                : (app()->bound('license.status')
                ? app('license.status')
                : $this->licenseAccess->status($company, $user)),
        ]);
    }

    /**
     * Remove tenant presentation state from an unscoped platform response.
     * This changes only the response model in memory; the owner's canonical
     * database identity remains untouched for allow-list verification.
     */
    private function withoutTenantPresentationContext(PosUser $user): PosUser
    {
        $user->setAttribute('company_id', null);
        $user->setAttribute('branch_id', null);
        $user->setRelation('company', null);
        $user->setRelation('branch', null);
        $user->setRelation('branches', collect());

        return $user;
    }

    public function logout(Request $request)
    {
        app(AuthSessionService::class)->revokeCurrent(
            $request->user(),
            $request->user()->currentAccessToken(),
            'logout'
        );

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }

    public function logoutAll(Request $request)
    {
        app(AuthSessionService::class)->revokeAllForUser(
            $request->user(),
            'logout_all'
        );

        return response()->json([
            'message' => 'Logged out from all devices',
        ]);
    }

    public function index(Request $request)
    {
        $company = app('company');
        /** @var PosUser $actor */
        $actor = $request->user() ?? auth()->user();

        $result = hasPermission($actor->role_id, 'manage_user', $company->id);

        if ($result !== true) {
            return $result;
        }

        $allowedBranchIds = $this->userManagementBranchIds($actor, $company);
        $users = PosUser::with([
            'company',
            'branch',
            'role',
            'branches',
            'shiftAssignments.shiftConfiguration.branch',
            'activeShiftSession.shiftConfiguration',
        ])
            ->where('company_id', $company->id)
            ->when($allowedBranchIds !== null, function (Builder $query) use ($allowedBranchIds): void {
                $query->where(function (Builder $branchScope) use ($allowedBranchIds): void {
                    $branchScope->whereIn('branch_id', $allowedBranchIds)
                        ->orWhereHas('branches', fn (Builder $branches) => $branches
                            ->whereIn('company_branches.id', $allowedBranchIds));
                });
            })
            ->when(! $this->superAdmins->hasPlatformSessionAccess($actor), function ($query) {
                $query->whereNotIn('user_type', ['super_admin', 'super_administrator'])
                    ->whereDoesntHave('role', function ($roleQuery) {
                        $roleQuery->whereIn('name', [
                            'Super Admin',
                            'Super Administrator',
                            'super_admin',
                            'super_administrator',
                            'super-admin',
                            'super-administrator',
                        ]);
                    });
            })
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'POS users fetched successfully',
            'data' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeLoginCode($request);

        $company = app('company');
        /** @var PosUser $actor */
        $actor = $request->user() ?? auth()->user();

        $result = hasPermission($actor->role_id, 'create_user', $company->id);

        if ($result !== true) {
            return $result;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => ['prohibited'],
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where('company_id', $company->id),
            ],

            'name' => 'required|string|max:255',
            'username' => [
                'nullable', 'required_without:login_code', 'string', 'max:255',
                Rule::unique('pos_users', 'username')->where('company_id', $company->id)->withoutTrashed(),
            ],
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|required_without:login_code|string|max:1024|confirmed',
            'login_code' => 'nullable|required_without:password|string|max:12',

            'employee_code' => 'nullable|string|max:100',
            'user_type' => 'nullable|string|in:super_admin,admin,manager,cashier,supervisor',
            'can_login_pos' => 'nullable|boolean',
            'can_access_front_office' => 'nullable|boolean',
            'can_access_back_office' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',

            'branch_ids' => 'nullable|array',
            'branch_ids.*' => [
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'default_branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'shift_assignments' => 'nullable|array',
            'shift_assignments.*.shift_configuration_id' => [
                'required',
                'string',
                Rule::exists('shiftconfigurations', 'id')->where('company_id', $company->id),
            ],
            'shift_assignments.*.shift_name' => 'required|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $createsBackOfficeIdentity = $request->boolean('can_access_back_office')
            || $this->requestAssignsSuperAdmin($request, (string) $company->id);
        if ($createsBackOfficeIdentity) {
            $backOfficeIdentityErrors = [];
            if (! $request->filled('username')) {
                $backOfficeIdentityErrors['username'][] = 'A username is required for Back Office access.';
            } elseif ($this->backOfficeUsernameAlreadyUsed((string) $request->username)) {
                $backOfficeIdentityErrors['username'][] = 'This username is already assigned to another Back Office account.';
            }
            if (! $request->filled('password')) {
                $backOfficeIdentityErrors['password'][] = 'A password is required for Back Office access.';
            }
            if ($backOfficeIdentityErrors !== []) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $backOfficeIdentityErrors,
                ], 422);
            }
        }

        $branchErrors = $this->userManagementBranchErrors(
            $request,
            $actor,
            $company,
            'create_user'
        );
        if ($branchErrors !== []) {
            return response()->json([
                'message' => 'The selected shops are outside your user-management scope.',
                'errors' => $branchErrors,
            ], 403);
        }

        $credentialScope = $this->credentialScopeForBranch(
            $company,
            $request->input('branch_id', $request->input('default_branch_id'))
        );
        $credentialPolicy = app(CredentialPolicyService::class);
        $credentialErrors = [];
        if ($request->filled('password')) {
            $credentialErrors['password'] = $credentialPolicy->passwordViolations(
                (string) $request->password,
                $credentialScope
            );
        }
        if ($request->filled('login_code')) {
            $credentialErrors['login_code'] = $credentialPolicy->loginCodeViolations(
                (string) $request->login_code,
                $credentialScope
            );
        }
        $credentialErrors = array_filter($credentialErrors);
        if ($credentialErrors !== []) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $credentialErrors,
            ], 422);
        }

        if ($this->requestAssignsSuperAdmin($request, (string) $company->id)
            && ! $this->superAdmins->hasPlatformSessionAccess($actor)) {
            return response()->json([
                'message' => 'Only a Super Admin can create another Super Admin user.',
            ], 403);
        }

        $shiftAssignmentErrors = $this->shiftAssignmentErrors($request, (string) $company->id);
        if ($shiftAssignmentErrors) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $shiftAssignmentErrors,
            ], 422);
        }

        $company = Company::with('activeLicense')->find($company->id);

        if (! $company) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $license = $company->activeLicense;

        if (! $license || ! $this->licenseService->isValid($license)) {
            return response()->json([
                'message' => 'Company license is invalid or expired',
            ], 403);
        }

        $currentUsersCount = PosUser::where('company_id', $company->id)
            ->where('is_active', true)
            ->count();

        if ($currentUsersCount >= $license->max_users) {
            return response()->json([
                'message' => 'User limit reached for this license',
                'limit' => $license->max_users,
                'current_users' => $currentUsersCount,
            ], 403);
        }

        if ($request->filled('login_code')) {
            try {
                $loginCodeAlreadyUsed = $this->loginCodeAlreadyUsed(
                    (string) $company->id,
                    (string) $request->login_code
                );
            } catch (LogicException) {
                return response()->json([
                    'message' => 'POS code login is not configured on this installation.',
                    'code' => 'LOGIN_CODE_LOOKUP_KEY_REQUIRED',
                ], 503);
            }
            if ($loginCodeAlreadyUsed) {
                return response()->json([
                    'message' => 'This login code is already assigned to another POS user.',
                    'errors' => [
                        'login_code' => ['This login code is already assigned to another POS user.'],
                    ],
                ], 422);
            }
        }

        $backOfficeUsernameLock = null;
        DB::beginTransaction();

        try {
            if ($createsBackOfficeIdentity) {
                $backOfficeUsernameLock = $this->acquireBackOfficeUsernameLock(
                    (string) $request->username
                );
                if ($this->backOfficeUsernameAlreadyUsed((string) $request->username)) {
                    DB::rollBack();
                    $this->releaseBackOfficeUsernameLock($backOfficeUsernameLock);

                    return response()->json([
                        'message' => 'Validation error',
                        'errors' => [
                            'username' => ['This username is already assigned to another Back Office account.'],
                        ],
                    ], 422);
                }
            }

            $requestedBranchIds = $this->requestedUserBranchIds($request);
            $primaryBranchId = $request->input(
                'branch_id',
                $request->input('default_branch_id', $requestedBranchIds[0] ?? null)
            );
            $user = PosUser::create([
                'company_id' => $company->id,
                'branch_id' => $primaryBranchId,
                'role_id' => $request->role_id,
                'name' => $request->name,
                'username' => $request->filled('username') ? trim((string) $request->username) : 'code_user_'.Str::lower((string) Str::uuid()),
                'phone' => $request->phone,
                'password' => $request->filled('password') ? $request->password : Str::random(64),
                'login_code' => $request->filled('login_code')
                    ? Hash::make((string) $request->login_code)
                    : null,
                'employee_code' => $request->employee_code,
                'user_type' => $request->user_type ?? 'cashier',
                'can_login_pos' => $request->can_login_pos ?? true,
                'can_access_front_office' => $request->boolean('can_access_front_office'),
                'can_access_back_office' => $request->boolean('can_access_back_office'),
                'is_active' => $request->is_active ?? true,
            ]);

            if ($request->filled('login_code') && $this->supportsLoginCodeBlindIndex()) {
                $user->forceFill($this->activeLoginCodeDigest(
                    (string) $company->id,
                    (string) $request->login_code
                ));
            }
            if (Schema::hasColumn('pos_users', 'credentials_changed_at')) {
                $user->credentials_changed_at = now();
            }
            if ($user->isDirty()) {
                $user->save();
            }

            if ($request->filled('branch_ids')) {
                $syncData = [];

                foreach ($request->branch_ids as $branchId) {
                    $syncData[$branchId] = [
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'is_default' => $branchId === $request->default_branch_id,
                    ];
                }

                $user->branches()->sync($syncData);
            }

            $this->syncShiftAssignments(
                $user,
                (string) $company->id,
                $request->input('shift_assignments', [])
            );

            DB::commit();
            $this->releaseBackOfficeUsernameLock($backOfficeUsernameLock);

            return response()->json([
                'message' => 'POS user created successfully',
                'user' => $user->load([
                    'company',
                    'branch',
                    'role',
                    'branches',
                    'shiftAssignments.shiftConfiguration.branch',
                    'activeShiftSession.shiftConfiguration',
                ]),
            ], 201);
        } catch (QueryException $e) {
            DB::rollBack();
            $this->releaseBackOfficeUsernameLock($backOfficeUsernameLock);

            if ($this->isLoginCodeLookupConflict($e)) {
                return response()->json([
                    'message' => 'This login code is already assigned to another POS user.',
                    'errors' => [
                        'login_code' => ['This login code is already assigned to another POS user.'],
                    ],
                ], 422);
            }

            return response()->json([
                'message' => 'Something went wrong while creating POS user',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->releaseBackOfficeUsernameLock($backOfficeUsernameLock);

            return response()->json([
                'message' => 'Something went wrong while creating POS user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $posUserId)
    {
        $company = app('company');
        /** @var PosUser $actor */
        $actor = request()->user() ?? auth()->user();

        $result = hasPermission($actor->role_id, 'manage_user', $company->id);

        if ($result !== true) {
            return $result;
        }

        $posUser = $this->posUserForCompany($posUserId, (string) $company->id);
        if (! $this->canManagePosUserTarget($actor, $posUser, $company)) {
            return response()->json([
                'message' => 'This user is outside your user-management scope.',
                'code' => 'USER_SCOPE_DENIED',
            ], 403);
        }

        return response()->json([
            'message' => 'POS user fetched successfully',
            'user' => $posUser->load([
                'company',
                'branch',
                'role',
                'branches',
                'shiftAssignments.shiftConfiguration.branch',
                'activeShiftSession.shiftConfiguration',
            ]),
        ]);
    }

    //    public function update(Request $request, PosUser $posUser)
    //    {
    //        $company = app('company');
    //
    //        $result = hasPermission(auth()->user()->role_id, 'manage_user', $company->id);
    //
    //        if ($result !== true) {
    //            return $result;
    //        }
    //
    //        $validator = Validator::make($request->all(), [
    //            'company_id' => 'nullable|exists:companies,id',
    //            'branch_id' => 'nullable|exists:company_branches,id',
    //            'role_id' => 'nullable|exists:roles,id',
    //
    //            'name' => 'sometimes|required|string|max:255',
    //            'username' => 'nullable|string|max:255|unique:pos_users,username,' . $posUser->id,
    //            'phone' => 'nullable|string|max:50',
    //            'password' => 'nullable|string|min:6|confirmed',
    //
    //            'employee_code' => 'nullable|string|max:100',
    //            'user_type' => 'nullable|string|in:super_admin,admin,manager,cashier,supervisor',
    //            'can_login_pos' => 'nullable|boolean',
    //            'is_active' => 'nullable|boolean',
    //
    //            'branch_ids' => 'nullable|array',
    //            'branch_ids.*' => 'exists:company_branches,id',
    //            'default_branch_id' => 'nullable|exists:company_branches,id',
    //        ]);
    //
    //        if ($validator->fails()) {
    //            return response()->json([
    //                'message' => 'Validation error',
    //                'errors' => $validator->errors(),
    //            ], 422);
    //        }
    //
    //        $data = $request->only([
    //            'company_id',
    //            'branch_id',
    //            'role_id',
    //            'name',
    //            'username',
    //            'phone',
    //            'employee_code',
    //            'user_type',
    //            'can_login_pos',
    //            'is_active',
    //        ]);
    //
    //        if ($request->filled('password')) {
    //            $data['password'] = $request->password;
    //        }
    //
    //        $posUser->update($data);
    //
    //        if ($request->has('branch_ids')) {
    //            $syncData = [];
    //
    //            foreach ($request->branch_ids ?? [] as $branchId) {
    //                $syncData[$branchId] = [
    //                    'company_id' => $request->company_id ?? $posUser->company_id,
    //                    'is_default' => $branchId === $request->default_branch_id,
    //                ];
    //            }
    //
    //            $posUser->branches()->sync($syncData);
    //        }
    //
    //        return response()->json([
    //            'message' => 'POS user updated successfully',
    //            'user' => $posUser->fresh()->load([
    //                'company',
    //                'branch',
    //                'role',
    //                'branches',
    //                'activeShiftSession.shiftConfiguration',
    //            ]),
    //        ]);
    //    }
    public function update(Request $request, string $posUserId)
    {
        $this->normalizeLoginCode($request);

        $company = app('company');
        /** @var PosUser $actor */
        $actor = $request->user() ?? auth()->user();

        $result = hasPermission($actor->role_id, 'manage_user', $company->id);

        if ($result !== true) {
            return $result;
        }

        $posUser = $this->posUserForCompany($posUserId, (string) $company->id);
        if (! $this->canManagePosUserTarget($actor, $posUser, $company)) {
            return response()->json([
                'message' => 'This user is outside your user-management scope.',
                'code' => 'USER_SCOPE_DENIED',
            ], 403);
        }

        if (! $this->superAdmins->hasPlatformSessionAccess($actor)
            && ($this->isSuperAdminIdentity($posUser)
                || $this->requestAssignsSuperAdmin($request, (string) $company->id, $posUser))) {
            return response()->json([
                'message' => 'Only a Super Admin can manage or assign Super Admin access.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => ['prohibited'],
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'role_id' => [
                'sometimes',
                'required',
                Rule::exists('roles', 'id')->where('company_id', $company->id),
            ],

            'name' => 'sometimes|required|string|max:255',
            'username' => [
                'nullable', 'string', 'max:255',
                Rule::unique('pos_users', 'username')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->ignore($posUser->id),
            ],
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|string|max:1024|confirmed',
            'login_code' => 'nullable|string|max:12',
            'clear_login_code' => 'nullable|boolean',

            'employee_code' => 'nullable|string|max:100',
            'user_type' => 'nullable|string|in:super_admin,admin,manager,cashier,supervisor',
            'can_login_pos' => 'nullable|boolean',
            'can_access_front_office' => 'nullable|boolean',
            'can_access_back_office' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',

            'branch_ids' => 'nullable|array',
            'branch_ids.*' => [
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'default_branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'shift_assignments' => 'nullable|array',
            'shift_assignments.*.shift_configuration_id' => [
                'required',
                'string',
                Rule::exists('shiftconfigurations', 'id')->where('company_id', $company->id),
            ],
            'shift_assignments.*.shift_name' => 'required|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updatesBackOfficeIdentity = $request->has('can_access_back_office')
            ? $request->boolean('can_access_back_office')
            : (bool) $posUser->can_access_back_office;
        $updatesBackOfficeIdentity = $updatesBackOfficeIdentity
            || $this->requestAssignsSuperAdmin($request, (string) $company->id, $posUser);
        $backOfficeUsername = null;
        if ($updatesBackOfficeIdentity) {
            $username = $request->has('username')
                ? trim((string) $request->username)
                : trim((string) $posUser->username);
            $backOfficeUsername = $username;
            $backOfficeIdentityErrors = [];
            if ($username === '') {
                $backOfficeIdentityErrors['username'][] = 'A username is required for Back Office access.';
            } elseif ($this->backOfficeUsernameAlreadyUsed($username, (string) $posUser->id)) {
                $backOfficeIdentityErrors['username'][] = 'This username is already assigned to another Back Office account.';
            }
            if (! $posUser->can_access_back_office
                && $request->boolean('can_access_back_office')
                && ! $request->filled('password')) {
                $backOfficeIdentityErrors['password'][] = 'Set a password when enabling Back Office access.';
            }
            if ($backOfficeIdentityErrors !== []) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $backOfficeIdentityErrors,
                ], 422);
            }
        }

        $branchErrors = $this->userManagementBranchErrors(
            $request,
            $actor,
            $company,
            'manage_user',
            $posUser
        );
        if ($branchErrors !== []) {
            return response()->json([
                'message' => 'The selected shops are outside your user-management scope.',
                'errors' => $branchErrors,
            ], 403);
        }

        $credentialScope = $this->credentialScopeForBranch(
            $company,
            $request->input(
                'branch_id',
                $request->input('default_branch_id', (string) $posUser->branch_id)
            )
        );
        $credentialPolicy = app(CredentialPolicyService::class);
        $credentialErrors = [];
        if ($request->filled('password')) {
            $credentialErrors['password'] = $credentialPolicy->passwordViolations(
                (string) $request->password,
                $credentialScope
            );
            if ($credentialPolicy->passwordWasRecentlyUsed(
                $posUser,
                (string) $request->password,
                $credentialScope
            )) {
                $credentialErrors['password'][] = 'The password was used recently and cannot be reused.';
            }
        }
        if ($request->filled('login_code')) {
            $credentialErrors['login_code'] = $credentialPolicy->loginCodeViolations(
                (string) $request->login_code,
                $credentialScope
            );
        }
        $credentialErrors = array_filter($credentialErrors);
        if ($credentialErrors !== []) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $credentialErrors,
            ], 422);
        }

        $shiftAssignmentErrors = $this->shiftAssignmentErrors(
            $request,
            (string) $company->id,
            $posUser
        );
        if ($shiftAssignmentErrors) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $shiftAssignmentErrors,
            ], 422);
        }

        if ($request->filled('login_code')) {
            try {
                $loginCodeAlreadyUsed = $this->loginCodeAlreadyUsed(
                    (string) $company->id,
                    (string) $request->login_code,
                    (string) $posUser->id
                );
            } catch (LogicException) {
                return response()->json([
                    'message' => 'POS code login is not configured on this installation.',
                    'code' => 'LOGIN_CODE_LOOKUP_KEY_REQUIRED',
                ], 503);
            }
            if ($loginCodeAlreadyUsed) {
                return response()->json([
                    'message' => 'This login code is already assigned to another POS user.',
                    'errors' => [
                        'login_code' => ['This login code is already assigned to another POS user.'],
                    ],
                ], 422);
            }
        }

        $data = $request->only([
            'branch_id',
            'role_id',
            'name',
            'phone',
            'employee_code',
            'user_type',
            'can_login_pos',
            'can_access_front_office',
            'can_access_back_office',
            'is_active',
        ]);

        if ($request->has('username')) {
            $data['username'] = $request->filled('username')
                ? trim((string) $request->username)
                : 'code_user_'.Str::lower((string) Str::uuid());
        }

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        if ($request->filled('login_code')) {
            $data['login_code'] = Hash::make((string) $request->login_code);
        }

        if ($request->boolean('clear_login_code')) {
            $data['login_code'] = null;
        }

        $credentialChanged = $request->filled('password')
            || $request->filled('login_code')
            || $request->boolean('clear_login_code');
        $posUser->fill($data);
        if ($request->filled('login_code') && $this->supportsLoginCodeBlindIndex()) {
            $posUser->forceFill($this->activeLoginCodeDigest(
                (string) $company->id,
                (string) $request->login_code
            ));
        } elseif ($request->boolean('clear_login_code') && $this->supportsLoginCodeBlindIndex()) {
            $posUser->forceFill([
                'login_code_lookup' => null,
                'login_code_lookup_key_id' => null,
            ]);
        }
        if ($credentialChanged && Schema::hasColumn('pos_users', 'credential_version')) {
            $posUser->credential_version = max(1, (int) $posUser->credential_version) + 1;
            $posUser->credentials_changed_at = now();
        }

        $backOfficeUsernameLock = null;
        try {
            if ($updatesBackOfficeIdentity && $backOfficeUsername !== null) {
                $backOfficeUsernameLock = $this->acquireBackOfficeUsernameLock($backOfficeUsername);
                if ($this->backOfficeUsernameAlreadyUsed($backOfficeUsername, (string) $posUser->id)) {
                    return response()->json([
                        'message' => 'Validation error',
                        'errors' => [
                            'username' => ['This username is already assigned to another Back Office account.'],
                        ],
                    ], 422);
                }
            }
            $posUser->save();
        } catch (QueryException $exception) {
            if (! $this->isLoginCodeLookupConflict($exception)) {
                throw $exception;
            }

            return response()->json([
                'message' => 'This login code is already assigned to another POS user.',
                'errors' => [
                    'login_code' => ['This login code is already assigned to another POS user.'],
                ],
            ], 422);
        } finally {
            $this->releaseBackOfficeUsernameLock($backOfficeUsernameLock);
        }

        if ($request->has('branch_ids')) {
            $branchIds = collect($request->branch_ids ?? [])
                ->filter()
                ->unique()
                ->values();

            $defaultBranchId = $request->default_branch_id;

            if (! $defaultBranchId && $branchIds->count() > 0) {
                $defaultBranchId = $branchIds->first();
            }

            $syncData = [];

            foreach ($branchIds as $branchId) {
                $existingPivotId = DB::table('user_branches')
                    ->where('pos_user_id', $posUser->id)
                    ->where('branch_id', $branchId)
                    ->value('id');

                $syncData[$branchId] = [
                    'id' => $existingPivotId ?: (string) Str::uuid(),
                    'company_id' => $company->id,
                    'is_default' => $branchId === $defaultBranchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $posUser->branches()->sync($syncData);

            if ($defaultBranchId) {
                $posUser->update([
                    'branch_id' => $defaultBranchId,
                ]);
            }
        }

        if ($request->has('shift_assignments')) {
            $this->syncShiftAssignments(
                $posUser,
                (string) $company->id,
                $request->input('shift_assignments', [])
            );
        }

        if ($request->hasAny([
            'can_login_pos',
            'can_access_front_office',
            'can_access_back_office',
            'shift_assignments',
            'password',
            'login_code',
            'clear_login_code',
        ])) {
            app(AuthSessionService::class)->revokeAllForUser(
                $posUser,
                $credentialChanged ? 'credentials_changed' : 'user_access_changed'
            );
        }

        return response()->json([
            'message' => 'POS user updated successfully',
            'user' => $posUser->fresh()->load([
                'company',
                'branch',
                'role',
                'branches',
                'shiftAssignments.shiftConfiguration.branch',
                'activeShiftSession.shiftConfiguration',
            ]),
        ]);
    }

    public function destroy(string $posUserId)
    {
        $company = app('company');
        /** @var PosUser $actor */
        $actor = request()->user() ?? auth()->user();

        $result = hasPermission($actor->role_id, 'manage_user', $company->id);

        if ($result !== true) {
            return $result;
        }

        $posUser = $this->posUserForCompany($posUserId, (string) $company->id);
        if (! $this->canManagePosUserTarget($actor, $posUser, $company)) {
            return response()->json([
                'message' => 'This user is outside your user-management scope.',
                'code' => 'USER_SCOPE_DENIED',
            ], 403);
        }

        DB::transaction(function () use ($posUser): void {
            // A deleted user can never sign in again, so release their PIN
            // for reuse; live PINs stay unique per company.
            $posUser->forceFill(['login_code' => null]);
            if ($this->supportsLoginCodeBlindIndex()) {
                $posUser->forceFill([
                    'login_code_lookup' => null,
                    'login_code_lookup_key_id' => null,
                ]);
            }
            $posUser->save();
            $posUser->delete();
        });

        return response()->json([
            'message' => 'POS user deleted successfully',
        ]);
    }

    public function closeActiveShift(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'closing_cash' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $activeShiftSession = PosUserShiftSession::where('pos_user_id', $user->id)
            ->where('company_id', app('company')->id)
            ->where('till_id', app('till')->id)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->latest()
            ->first();

        if (! $activeShiftSession) {
            return response()->json([
                'message' => 'No open shift found for this user',
            ], 404);
        }

        $activeShiftSession->update([
            'closing_cash' => $request->closing_cash,
            'closed_at' => now(),
            'status' => 'closed',
        ]);

        return response()->json([
            'message' => 'Shift closed successfully',
            'data' => $activeShiftSession->fresh()->load('shiftConfiguration'),
        ]);
    }

    public function create()
    {
        return view('authentication::create');
    }
}
