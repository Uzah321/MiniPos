<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Authentication\Models\GeneralSetting;
use Modules\Authentication\Models\PosUser;
use function App\Helpers\hasPermission;

class PriceLevelOverrideController extends Controller
{
    public function __construct(
        private readonly CompanyBranchContextService $branchContext
    ) {}

    public function config(Request $request)
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        return response()->json([
            'message' => 'Price override config fetched successfully.',
            'data' => [
                'enabled' => $this->settingEnabled($company->id, $branchId, 'price_level_override_enabled', false),
                'requires_manager_approval' => $this->settingEnabled($company->id, $branchId, 'price_level_override_requires_manager_approval', true),
                'cashier_can_override_without_manager' => $this->settingEnabled($company->id, $branchId, 'cashier_can_price_level_override_without_manager', false),
            ],
        ]);
    }

    public function productPriceLevels(Request $request, string $productId)
    {
        $company = app('company');
        $user = auth()->user();
        $branchId = $this->resolveBranchId($request);

        if (!$this->settingEnabled($company->id, $branchId, 'price_level_override_enabled', false)) {
            return response()->json([
                'message' => 'Price level override is disabled in General Settings.',
            ], 403);
        }

        $validator = Validator::make(array_merge($request->all(), ['product_id' => $productId]), [
            'product_id' => ['required', 'string', 'max:36'],
            'manager_approval_id' => ['nullable', 'string', 'max:36'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contextRoleId = $user->role_id;
        $contextUserId = $user->id;
        $contextUserName = $user->name ?? $user->username ?? 'Cashier';
        $managerApproval = null;

        $requiresManager = $this->settingEnabled($company->id, $branchId, 'price_level_override_requires_manager_approval', true);
        $cashierAllowed = $this->settingEnabled($company->id, $branchId, 'cashier_can_price_level_override_without_manager', false);

        if ($requiresManager && !$cashierAllowed) {
            if (!$request->filled('manager_approval_id')) {
                return response()->json([
                    'message' => 'Manager approval is required before viewing override price levels.',
                    'required_permission' => 'approve_price_override',
                ], 422);
            }

            $managerApproval = $this->getValidManagerApproval(
                approvalId: $request->manager_approval_id,
                companyId: $company->id,
                branchId: $branchId,
                expectedPermission: 'approve_price_override'
            );

            if (!$managerApproval) {
                return response()->json([
                    'message' => 'Invalid manager approval for price override.',
                ], 422);
            }

            /*
             * Manager mode:
             * The approving manager role controls which price levels appear.
             */
            $contextRoleId = $managerApproval['manager_role_id'];
            $contextUserId = $managerApproval['manager_id'];
            $contextUserName = $managerApproval['manager_name'];
        } else {
            /*
             * Cashier/no-manager mode:
             * view_product_price_levels controls whether the cashier may view/select
             * product price levels in the modal.
             */
            $viewPermission = hasPermission($user->role_id, 'view_product_price_levels', $company->id);

            if ($viewPermission !== true) {
                return $viewPermission;
            }
        }

        $eligiblePriceLevelIds = DB::table('role_price_levels')
            ->where('company_id', $company->id)
            ->where('role_id', $contextRoleId)
            ->pluck('price_level_id')
            ->filter()
            ->values()
            ->all();

        if (empty($eligiblePriceLevelIds)) {
            return response()->json([
                'message' => 'This role is not eligible for any override price levels.',
                'data' => [
                    'rows' => [],
                    'context' => [
                        'user_id' => $contextUserId,
                        'user_name' => $contextUserName,
                        'role_id' => $contextRoleId,
                        'manager_approval_id' => $request->manager_approval_id,
                    ],
                ],
            ]);
        }

        $now = now();

        $rows = DB::table('product_prices')
            ->leftJoin('price_levels', 'price_levels.id', '=', 'product_prices.price_level_id')
            ->where('product_prices.company_id', $company->id)
            ->where('product_prices.product_id', $productId)
            ->whereIn('product_prices.price_level_id', $eligiblePriceLevelIds)
            ->where('product_prices.is_active', true)
            ->whereNull('product_prices.deleted_at')
            ->where(function ($query) use ($branchId) {
                $query->whereNull('product_prices.branch_id');

                if ($branchId) {
                    $query->orWhere('product_prices.branch_id', $branchId);
                }
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('product_prices.starts_at')
                    ->orWhere('product_prices.starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('product_prices.ends_at')
                    ->orWhere('product_prices.ends_at', '>=', $now);
            })
            ->select([
                'product_prices.id',
                'product_prices.product_id',
                'product_prices.branch_id',
                'product_prices.price_level_id',
                'product_prices.price',
                'product_prices.currency',
                'product_prices.is_default',
                DB::raw('COALESCE(price_levels.name, "Price Level") as price_level_name'),
                DB::raw('COALESCE(price_levels.code, "") as price_level_code'),
                DB::raw('price_levels.is_default as price_level_is_default'),
            ])
            ->orderByRaw('CASE WHEN product_prices.branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('price_levels.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'branch_id' => $row->branch_id,
                'price_level_id' => $row->price_level_id,
                'price_level_name' => $row->price_level_name,
                'price_level_code' => $row->price_level_code,
                'price' => round((float) $row->price, 4),
                'currency' => $row->currency,
                'is_default' => (bool) $row->is_default,
                'price_level_is_default' => (bool) $row->price_level_is_default,
            ])
            ->values()
            ->all();

        return response()->json([
            'message' => 'Eligible price levels fetched successfully.',
            'data' => [
                'rows' => $rows,
                'context' => [
                    'user_id' => $contextUserId,
                    'user_name' => $contextUserName,
                    'role_id' => $contextRoleId,
                    'manager_approval_id' => $request->manager_approval_id,
                    'manager_approval' => $managerApproval,
                ],
            ],
        ]);
    }

    public function apply(Request $request)
    {
        $company = app('company');
        $user = auth()->user();
        $branchId = $this->resolveBranchId($request);

        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'string', 'max:36'],
            'product_price_id' => ['required', 'string', 'max:36'],
            'line_id' => ['nullable', 'string', 'max:80'],
            'old_price' => ['required', 'numeric'],
            'old_price_level_id' => ['nullable', 'string', 'max:36'],
            'manager_approval_id' => ['nullable', 'string', 'max:36'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$this->settingEnabled($company->id, $branchId, 'price_level_override_enabled', false)) {
            return response()->json([
                'message' => 'Price level override is disabled in General Settings.',
            ], 403);
        }

        $contextRoleId = $user->role_id;
        $contextUserId = $user->id;
        $contextUserName = $user->name ?? $user->username ?? 'Cashier';
        $managerApproval = null;

        $requiresManager = $this->settingEnabled($company->id, $branchId, 'price_level_override_requires_manager_approval', true);
        $cashierAllowed = $this->settingEnabled($company->id, $branchId, 'cashier_can_price_level_override_without_manager', false);

        if ($requiresManager && !$cashierAllowed) {
            $managerApproval = $this->getValidManagerApproval(
                approvalId: $request->manager_approval_id,
                companyId: $company->id,
                branchId: $branchId,
                expectedPermission: 'approve_price_override'
            );

            if (!$managerApproval) {
                return response()->json([
                    'message' => 'Manager approval is required for price level override.',
                    'required_permission' => 'approve_price_override',
                ], 422);
            }

            /*
             * Manager mode:
             * The approving manager role controls eligibility in role_price_levels.
             */
            $contextRoleId = $managerApproval['manager_role_id'];
            $contextUserId = $managerApproval['manager_id'];
            $contextUserName = $managerApproval['manager_name'];
        } else {
            /*
             * Cashier/no-manager mode:
             * price_level_override controls the actual ability to apply the selected level.
             */
            $overridePermission = hasPermission($user->role_id, 'price_level_override', $company->id);

            if ($overridePermission !== true) {
                return $overridePermission;
            }
        }

        $price = DB::table('product_prices')
            ->leftJoin('price_levels', 'price_levels.id', '=', 'product_prices.price_level_id')
            ->where('product_prices.company_id', $company->id)
            ->where('product_prices.product_id', $request->product_id)
            ->where('product_prices.id', $request->product_price_id)
            ->where('product_prices.is_active', true)
            ->whereNull('product_prices.deleted_at')
            ->where(function ($query) use ($branchId) {
                $query->whereNull('product_prices.branch_id');

                if ($branchId) {
                    $query->orWhere('product_prices.branch_id', $branchId);
                }
            })
            ->select([
                'product_prices.*',
                DB::raw('COALESCE(price_levels.name, "Price Level") as price_level_name'),
                DB::raw('COALESCE(price_levels.code, "") as price_level_code'),
            ])
            ->first();

        if (!$price) {
            return response()->json([
                'message' => 'Selected price level price was not found for this product.',
            ], 404);
        }

        $eligible = DB::table('role_price_levels')
            ->where('company_id', $company->id)
            ->where('role_id', $contextRoleId)
            ->where('price_level_id', $price->price_level_id)
            ->exists();

        if (!$eligible) {
            return response()->json([
                'message' => 'Approving role is not eligible for the selected price level.',
            ], 403);
        }

        $auditId = (string) Str::uuid();

        DB::table('audit_trails')->insert([
            'id' => $auditId,
            'company_id' => $company->id,
            'branch_id' => $branchId,
            'user_id' => $contextUserId,
            'user_name' => $contextUserName,
            'user_email' => null,
            'user_role' => $contextRoleId,
            'action' => 'price_level_override_applied',
            'module' => 'sales',
            'description' => 'Product price level override applied during sale.',
            'auditable_type' => 'product_price',
            'auditable_id' => $price->id,
            'old_values' => json_encode([
                'price' => round((float) $request->old_price, 4),
                'price_level_id' => $request->old_price_level_id,
            ]),
            'new_values' => json_encode([
                'price' => round((float) $price->price, 4),
                'price_level_id' => $price->price_level_id,
                'price_level_name' => $price->price_level_name,
                'price_level_code' => $price->price_level_code,
            ]),
            'changed_fields' => json_encode(['price', 'price_level_id', 'product_price_id']),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'device_name' => $request->header('X-Device-Name'),
            'terminal_name' => $request->header('X-Terminal-Name') ?: $request->header('X-Till-Name'),
            'session_id' => session()->getId(),
            'status' => 'success',
            'error_message' => null,
            'metadata' => json_encode([
                'cashier_id' => $user->id,
                'cashier_name' => $user->name ?? $user->username,
                'manager_approval_id' => $request->manager_approval_id,
                'manager_approval' => $managerApproval,
                'line_id' => $request->line_id,
                'product_id' => $request->product_id,
                'reason' => $request->reason,
                'request_metadata' => $request->metadata ?? [],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Price level override applied successfully.',
            'data' => [
                'audit_id' => $auditId,
                'product_price' => [
                    'id' => $price->id,
                    'product_id' => $price->product_id,
                    'price_level_id' => $price->price_level_id,
                    'price_level_name' => $price->price_level_name,
                    'price_level_code' => $price->price_level_code,
                    'price' => round((float) $price->price, 4),
                    'currency' => $price->currency,
                ],
                'approved_by' => [
                    'id' => $contextUserId,
                    'name' => $contextUserName,
                    'role_id' => $contextRoleId,
                ],
            ],
        ]);
    }

    private function getValidManagerApproval(string $approvalId, string $companyId, ?string $branchId, string $expectedPermission): ?array
    {
        $audit = DB::table('audit_trails')
            ->where('id', $approvalId)
            ->where('company_id', $companyId)
            ->where('module', 'manager-approval')
            ->where('status', 'success')
            ->whereIn('action', [
                'manager_approval_price_level_override',
                'manager_approval_price_override',
            ])
            ->first();

        if (!$audit) {
            return null;
        }

        if ($branchId && $audit->branch_id && (string) $audit->branch_id !== (string) $branchId) {
            return null;
        }

        $metadata = $this->jsonToArray($audit->metadata ?? null);
        $newValues = $this->jsonToArray($audit->new_values ?? null);

        $permission = $metadata['required_permission'] ?? $newValues['required_permission'] ?? null;

        if ($permission !== $expectedPermission) {
            return null;
        }

        $manager = PosUser::query()
            ->with('role')
            ->where('company_id', $companyId)
            ->where('id', $audit->user_id)
            ->where('is_active', true)
            ->where('can_login_pos', true)
            ->whereNull('deleted_at')
            ->first();

        if (!$manager) {
            return null;
        }

        return [
            'approval_id' => $approvalId,
            'manager_id' => $manager->id,
            'manager_name' => $manager->name ?? $manager->username,
            'manager_role_id' => $manager->role_id,
            'manager_role_name' => $manager->role?->name,
        ];
    }

    private function jsonToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: [];
        }

        return [];
    }

    private function resolveBranchId(Request $request): ?string
    {
        $metadataBranchId = $request->input('metadata.branch_id');
        if (is_scalar($metadataBranchId) && trim((string) $metadataBranchId) !== '') {
            $metadataBranchId = trim((string) $metadataBranchId);
            $bodyBranchId = $request->input('branch_id');
            if (is_scalar($bodyBranchId)
                && trim((string) $bodyBranchId) !== ''
                && trim((string) $bodyBranchId) !== $metadataBranchId) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => 'The supplied branch contexts do not match.',
                        'code' => 'BRANCH_CONTEXT_MISMATCH',
                    ], 409)
                );
            }

            if (! $request->filled('branch_id')) {
                $request->merge(['branch_id' => $metadataBranchId]);
            }
        }

        return $this->branchContext->resolve(
            $request,
            (string) app('company')->id
        );
    }

    private function settingEnabled(string $companyId, ?string $branchId, string $settingKey, bool $default = false): bool
    {
        $setting = GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('setting_key', $settingKey)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        return $setting
            ? filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN)
            : $default;
    }
}
