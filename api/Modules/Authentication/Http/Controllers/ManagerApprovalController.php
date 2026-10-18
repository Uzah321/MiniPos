<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Company;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

class ManagerApprovalController extends Controller
{
    /**
     * The permission for an approval is always selected by the server. Keep
     * aliases here because older POS clients use more than one name for the
     * same operation.
     *
     * @var array<string, string>
     */
    private const ACTION_PERMISSION_MAP = [
        'cashout' => 'approve_cash_drawer_movement',
        'cash_out' => 'approve_cash_drawer_movement',
        'bank_drop' => 'approve_cash_drawer_movement',
        'paid_out' => 'approve_cash_drawer_movement',
        'petty_cash' => 'approve_cash_drawer_movement',
        'float_out' => 'approve_cash_drawer_movement',
        'cash_drawer_movement' => 'approve_cash_drawer_movement',

        'cashup' => 'approve_cashup_end_shift',
        'end_shift' => 'approve_cashup_end_shift',

        'price_override' => 'approve_price_override',
        'price_level_override' => 'approve_price_override',
        'price_change' => 'approve_price_override',
        'manual_price' => 'approve_price_override',
        'manual_price_change' => 'approve_price_override',
        'sale_price_override' => 'approve_price_override',
        'product_price_override' => 'approve_price_override',

        'discount_override' => 'approve_discount_override',
        'manual_discount' => 'approve_discount_override',
        'apply_discount' => 'approve_discount_override',
        'cart_discount' => 'approve_discount_override',
        'line_discount' => 'approve_discount_override',

        'void_sale' => 'approve_void_sale',
        'void_receipt' => 'approve_void_sale',
        'cancel_receipt' => 'approve_void_sale',

        'clear_open_table' => 'clear_open_tables',
        'clear_table' => 'clear_open_tables',
        'clear_hanging_table' => 'clear_open_tables',
    ];

    public function __construct(
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly EnterpriseScopeResolver $scopes,
    ) {}

    public function verify(Request $request)
    {
        $company = app('company');
        $requestingUser = auth()->user();

        /*
         * Do NOT call app('till') here.
         * This endpoint is also called from admin/cashup/sale flows where till
         * middleware may not have bound `till`.
         */
        $branchId = $this->resolveBranchId($request, $requestingUser);
        $terminalName = $this->resolveTerminalName($request);

        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'action' => ['required', 'string', 'max:100'],
            // Accepted for compatibility with older clients, but never used.
            'permission' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reference_type' => ['nullable', 'string', 'max:150'],
            'reference_id' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'string', 'max:36'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $action = Str::snake(trim((string) $request->action));
        $requiredPermission = $this->permissionForAction($action);

        if ($requiredPermission === null) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => [
                    'action' => ['Unsupported manager approval action.'],
                ],
            ], 422);
        }

        if ($requiredPermission === 'clear_open_tables'
            && ($request->reference_type !== 'hospitality_table_order'
                || ! $request->filled('reference_id')
                || ! Str::isUuid((string) $request->reference_id))) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => [
                    'reference_id' => ['A hospitality table order reference is required for this approval.'],
                ],
            ], 422);
        }

        $branchId ??= $this->referenceBranchId($request, (string) $company->id);

        $manager = PosUser::query()
            ->with('role')
            ->where('company_id', $company->id)
            ->where('username', $request->username)
            ->where('is_active', true)
            ->where('can_login_pos', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $manager || ! Hash::check($request->password, $manager->password)) {
            $this->writeApprovalAudit(
                request: $request,
                companyId: $company->id,
                branchId: $branchId,
                requestingUser: $requestingUser,
                manager: $manager,
                action: $action,
                requiredPermission: $requiredPermission,
                status: 'failed',
                description: 'Manager approval failed: invalid credentials.',
                errorMessage: 'Invalid manager username or password.',
                terminalName: $terminalName
            );

            return response()->json([
                'message' => 'Invalid manager username or password.',
                'code' => 'MANAGER_APPROVAL_INVALID_CREDENTIALS',
            ], 422);
        }

        if ($manager->company_id !== $company->id) {
            $this->writeApprovalAudit(
                request: $request,
                companyId: $company->id,
                branchId: $branchId,
                requestingUser: $requestingUser,
                manager: $manager,
                action: $action,
                requiredPermission: $requiredPermission,
                status: 'failed',
                description: 'Manager approval failed: manager belongs to another company.',
                errorMessage: 'Manager is not valid for this company.',
                terminalName: $terminalName
            );

            return response()->json([
                'message' => 'Manager is not valid for this company.',
            ], 422);
        }

        /*
         * Branch rule:
         * - manager.branch_id null = company-level manager
         * - if branch is known and manager.branch_id is set, it must match
         * - if branch is unknown, do not crash; permission check still applies
         */
        if ($branchId && $manager->branch_id && (string) $manager->branch_id !== (string) $branchId) {
            $this->writeApprovalAudit(
                request: $request,
                companyId: $company->id,
                branchId: $branchId,
                requestingUser: $requestingUser,
                manager: $manager,
                action: $action,
                requiredPermission: $requiredPermission,
                status: 'failed',
                description: 'Manager approval failed: manager belongs to another branch.',
                errorMessage: 'Manager is not assigned to this branch.',
                terminalName: $terminalName
            );

            return response()->json([
                'message' => 'Manager is not assigned to this branch.',
            ], 422);
        }

        $permissionResult = $this->managerHasPermission(
            $request,
            $manager,
            $requiredPermission,
            $company,
            $branchId
        );

        if ($permissionResult !== true) {
            $this->writeApprovalAudit(
                request: $request,
                companyId: $company->id,
                branchId: $branchId,
                requestingUser: $requestingUser,
                manager: $manager,
                action: $action,
                requiredPermission: $requiredPermission,
                status: 'failed',
                description: "Manager approval failed: missing {$requiredPermission}.",
                errorMessage: 'Manager role does not have the required approval permission.',
                terminalName: $terminalName
            );

            return response()->json([
                'message' => 'Manager role does not have the required approval permission.',
                'required_permission' => $requiredPermission,
            ], 403);
        }

        $auditId = $this->writeApprovalAudit(
            request: $request,
            companyId: $company->id,
            branchId: $branchId,
            requestingUser: $requestingUser,
            manager: $manager,
            action: $action,
            requiredPermission: $requiredPermission,
            status: 'success',
            description: "Manager approval granted for {$action}.",
            errorMessage: null,
            terminalName: $terminalName
        );

        return response()->json([
            'message' => 'Manager approval verified successfully.',
            'data' => [
                'approval_id' => $auditId,
                'approved' => true,
                'action' => $action,
                'required_permission' => $requiredPermission,
                'manager' => [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'username' => $manager->username,
                    'role_id' => $manager->role_id,
                    'role_name' => $manager->role?->name,
                    'user_type' => $manager->user_type,
                ],
                'approved_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    private function resolveBranchId(Request $request, $requestingUser): ?string
    {
        if ($request->filled('branch_id')) {
            return (string) $request->branch_id;
        }

        if ($request->header('X-Branch-Id')) {
            return (string) $request->header('X-Branch-Id');
        }

        if ($request->input('metadata.branch_id')) {
            return (string) $request->input('metadata.branch_id');
        }

        if ($requestingUser?->selectedBranchId) {
            return (string) $requestingUser->selectedBranchId;
        }

        if ($requestingUser?->branch_id) {
            return (string) $requestingUser->branch_id;
        }

        return null;
    }

    private function resolveTerminalName(Request $request): ?string
    {
        return $request->header('X-Terminal-Name')
            ?: $request->header('X-Till-Name')
                ?: $request->header('X-Device-Name')
                    ?: null;
    }

    private function referenceBranchId(Request $request, string $companyId): ?string
    {
        if ($request->reference_type !== 'hospitality_table_order'
            || ! $request->filled('reference_id')
            || ! Schema::hasTable('hospitality_table_orders')) {
            return null;
        }

        $branchId = DB::table('hospitality_table_orders')
            ->where('company_id', $companyId)
            ->where('id', $request->reference_id)
            ->value('branch_id');

        return $branchId ? (string) $branchId : null;
    }

    private function managerHasPermission(
        Request $request,
        PosUser $manager,
        string $permission,
        mixed $company,
        ?string $branchId
    ): bool {
        if (! $this->authorization->usesScopedAssignments($manager)) {
            $manager->loadMissing('role.permissions');
            $normalise = static fn ($value): string => strtolower(str_replace(
                [' ', '-'],
                '_',
                trim((string) $value)
            ));

            return in_array($normalise($manager->user_type), ['super_admin', 'super_administrator'], true)
                || in_array($normalise($manager->role?->name), ['super_admin', 'super_administrator'], true)
                || ($manager->role?->permissions?->contains('name', $permission) ?? false);
        }

        try {
            $scope = $this->approvalScope($request, $company, $branchId);
        } catch (\Throwable) {
            return false;
        }

        return $this->authorization->can($manager, $permission, $scope);
    }

    private function approvalScope(Request $request, mixed $company, ?string $branchId): AuthorizationScope
    {
        $companyModel = $company instanceof Company
            ? $company
            : Company::query()->findOrFail((string) $company->id);

        if ($request->reference_type === 'hospitality_table_order'
            && $request->filled('reference_id')
            && Schema::hasTable('hospitality_table_orders')) {
            $order = DB::table('hospitality_table_orders')
                ->where('company_id', $companyModel->id)
                ->where('id', $request->reference_id)
                ->first(['branch_id', 'shop_id', 'last_till_id', 'till_id']);
            if (! $order) {
                throw new \DomainException('The table order approval scope could not be resolved.');
            }

            $till = PosTill::query()
                ->where('company_id', $companyModel->id)
                ->where('branch_id', $order->branch_id)
                ->whereKey($order->last_till_id ?: $order->till_id)
                ->first();
            if ($till) {
                return $this->scopes->fromTill($till);
            }

            $shop = Shop::query()
                ->where('company_id', $companyModel->id)
                ->where('branch_id', $order->branch_id)
                ->whereKey($order->shop_id)
                ->firstOrFail();

            return $this->scopes->fromShop($shop);
        }

        $tillId = $request->input('metadata.till_id');
        if (is_scalar($tillId) && trim((string) $tillId) !== '') {
            $till = PosTill::query()
                ->where('company_id', $companyModel->id)
                ->whereKey(trim((string) $tillId))
                ->firstOrFail();

            return $this->scopes->fromTill($till);
        }

        $shopId = $request->input('metadata.shop_id');
        $shop = Shop::query()
            ->where('company_id', $companyModel->id)
            ->when(
                is_scalar($shopId) && trim((string) $shopId) !== '',
                fn ($query) => $query->whereKey(trim((string) $shopId)),
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->first();
        if ($shop) {
            return $this->scopes->fromShop($shop);
        }

        return $this->scopes->fromCompany($companyModel);
    }

    private function permissionForAction(string $action): ?string
    {
        return self::ACTION_PERMISSION_MAP[$action] ?? null;
    }

    private function writeApprovalAudit(
        Request $request,
        ?string $companyId,
        ?string $branchId,
        $requestingUser,
        $manager,
        string $action,
        string $requiredPermission,
        string $status,
        string $description,
        ?string $errorMessage = null,
        ?string $terminalName = null
    ): string {
        $auditId = (string) Str::uuid();

        DB::table('audit_trails')->insert([
            'id' => $auditId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => $manager?->id,
            'user_name' => $manager?->name ?? $manager?->username ?? $request->username,
            'user_email' => null,
            'user_role' => $manager?->role?->name ?? $manager?->user_type,
            'action' => 'manager_approval_'.$action,
            'module' => 'manager-approval',
            'description' => $description,
            'auditable_type' => $request->reference_type ?: 'manager_approval',
            'auditable_id' => $request->reference_id,
            'old_values' => null,
            'new_values' => json_encode([
                'approved_by' => $manager?->id,
                'approved_by_name' => $manager?->name ?? $manager?->username,
                'requested_by' => $requestingUser?->id,
                'requested_by_name' => $requestingUser?->name ?? $requestingUser?->username,
                'action' => $action,
                'required_permission' => $requiredPermission,
                'reason' => $request->reason,
            ]),
            'changed_fields' => json_encode(['manager_approval']),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'device_name' => $request->header('X-Device-Name'),
            'terminal_name' => $terminalName,
            'session_id' => session()->getId(),
            'status' => $status,
            'error_message' => $errorMessage,
            'metadata' => json_encode([
                'manager_username' => $request->username,
                'requesting_user_id' => $requestingUser?->id,
                'requesting_user_name' => $requestingUser?->name ?? $requestingUser?->username,
                'required_permission' => $requiredPermission,
                'reason' => $request->reason,
                'branch_id' => $branchId,
                'request_metadata' => $request->metadata ?? [],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $auditId;
    }
}
