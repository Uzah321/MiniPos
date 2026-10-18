<?php
namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PosCommandReceipt;
use App\Services\Auth\SuperAdminService;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Cashups\CashupBranchAccessService;
use App\Services\Cashups\CashupFinancialVisibilityService;
use App\Services\CompanySettingService;
use App\Services\Pos\PosCommandIdempotencyService;
use App\Services\Reports\EnterpriseReportDownloadService;
use App\Services\Shifts\ShiftPolicyService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\PosUserShiftSession;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\GeneralSetting;
use Modules\Product\Support\HospitalityTradingDatePolicy;
use Modules\Sales\Models\PosCashup;
use Modules\Sales\Models\PosCashupDenomination;
use Modules\Sales\Models\SalesTransaction;
use function App\Helpers\hasPermission;

class CashupController extends Controller
{
    private const CASHUP_BUSINESS_MODE_VALIDATION_RULES = ['prohibited'];

    private EnterpriseReportDownloadService $reportDownloads;
    private CashupFinancialVisibilityService $cashupFinancialVisibility;
    private CashupBranchAccessService $cashupBranchAccess;
    private BusinessModeBranchScopeService $businessModeBranchScope;
    private CompanySettingService $companySettings;
    private SuperAdminService $superAdmins;
    private PosCommandIdempotencyService $posCommands;

    public function __construct(
        protected ShiftPolicyService $shiftPolicyService,
        ?EnterpriseReportDownloadService $reportDownloads = null,
        ?CashupFinancialVisibilityService $cashupFinancialVisibility = null,
        ?CashupBranchAccessService $cashupBranchAccess = null,
        ?BusinessModeBranchScopeService $businessModeBranchScope = null,
        ?CompanySettingService $companySettings = null,
        ?SuperAdminService $superAdmins = null,
        ?PosCommandIdempotencyService $posCommands = null
    ) {
        $this->reportDownloads = $reportDownloads ?? new EnterpriseReportDownloadService();
        $this->cashupFinancialVisibility = $cashupFinancialVisibility ?? new CashupFinancialVisibilityService();
        $this->cashupBranchAccess = $cashupBranchAccess ?? new CashupBranchAccessService();
        $this->businessModeBranchScope = $businessModeBranchScope ?? app(BusinessModeBranchScopeService::class);
        $this->companySettings = $companySettings ?? app(CompanySettingService::class);
        $this->superAdmins = $superAdmins ?? app(SuperAdminService::class);
        $this->posCommands = $posCommands ?? app(PosCommandIdempotencyService::class);
    }

    /** @return array<int, string> */
    private function resolveCashupReportBranchIds(Request $request, string $companyId): array
    {
        $requested = $request->input('branch_ids', []);
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }
        $requested = is_array($requested) ? $requested : [];
        if ($request->filled('branch_id')) {
            $requested[] = $request->input('branch_id');
        }

        $scope = $this->businessModeBranchScope->resolve($request, $companyId, $requested);
        $authoritativeMode = $this->cashupReportBusinessMode((string) $scope['business_mode']);
        $requestedMode = $this->cashupReportBusinessMode((string) $request->input('business_mode', ''));

        if ($requestedMode !== null && $requestedMode !== $authoritativeMode) {
            throw new HttpResponseException(response()->json([
                'message' => 'The requested cashup report mode does not match the active workspace branch.',
                'code' => 'BUSINESS_MODE_MISMATCH',
                'current_mode' => $authoritativeMode,
                'requested_mode' => $requestedMode,
                'branch_id' => $scope['context_branch_id'],
            ], 409));
        }

        // Report rows without a cashup snapshot (open shifts and live drawer
        // movements) must use the branch setting, never a caller-supplied mode.
        $request->merge(['business_mode' => $authoritativeMode]);
        $request->attributes->set('cashup_report_business_mode', $authoritativeMode);

        return $scope['branch_ids'];
    }

    private function cashupReportBusinessMode(string $mode): ?string
    {
        return match (strtolower(trim($mode))) {
            'hospitality', 'fast_food_boh' => 'fast_food_boh',
            'retail' => 'retail',
            default => null,
        };
    }


    public function activeShiftSummary(Request $request)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();
        $policy = $this->shiftPolicyService->get($company->id, $till->branch_id);
        $permission = $this->cashupPermission($user, 'cashup_shift', (string) $company->id);
        if ($permission !== true) {
            return $permission;
        }

        $canCashupOnBehalf = $this->canCashupOnBehalf($user, (string) $company->id, $policy);

        $shift = $this->resolveCashupShift(
            (string) $company->id,
            (string) $till->branch_id,
            (string) $till->id,
            $user,
            $request->input('shift_session_id'),
            $policy
        );

        if (!$shift) {
            return response()->json(['message' => 'No open shift found.'], 404);
        }

        $summary = $this->buildShiftSummary($company->id, $till->branch_id, $till->id, $shift->id, $shift->pos_user_id);
        $businessModeContext = $this->resolveCashupBusinessMode($company->id, $till->branch_id);
        $tradingDayState = $businessModeContext['business_mode'] === 'fast_food_boh'
            ? $this->resolveActiveTradingDayState((string) $company->id, (string) $till->branch_id)
            : null;
        $tradingDateState = $tradingDayState
            ? HospitalityTradingDatePolicy::state($tradingDayState['trading_date'], $tradingDayState['status'])
            : HospitalityTradingDatePolicy::state(null);

        $canViewExpectedCashupAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);

        $cashupEndShiftRequiresManagerApproval = $this->generalSettingEnabled(
            $company->id,
            $till->branch_id,
            'cashup_end_shift_requires_manager_approval',
            false
        );

        $summary['policy'] = $policy;

        $summary['settings'] = [
            'cashout_requires_manager_approval' => (bool) ($policy['cashout_requires_manager_approval'] ?? false),
            'cashup_end_shift_requires_manager_approval' => $cashupEndShiftRequiresManagerApproval,
            'boh_enabled' => $businessModeContext['boh_enabled'],
            'fast_food_mode_enabled' => $businessModeContext['fast_food_mode_enabled'],
            'business_mode' => $businessModeContext['business_mode'],
            'requires_trading_date' => $businessModeContext['business_mode'] === 'fast_food_boh',
            'trading_date' => $tradingDayState['trading_date'] ?? null,
            'trading_day_status' => $tradingDayState['status'] ?? null,
            'trading_date_is_future' => (bool) ($tradingDateState['trading_date_is_future'] ?? false),
            'trading_date_valid_for_cashup' => $businessModeContext['business_mode'] !== 'fast_food_boh'
                || (($tradingDayState['status'] ?? null) === 'open'
                    && !($tradingDateState['trading_date_is_future'] ?? false)),
            'trading_date_error' => $businessModeContext['business_mode'] === 'fast_food_boh'
                ? ($tradingDateState['sale_block_reason'] ?? null)
                : null,
        ];
        $summary['trading_date'] = $tradingDayState['trading_date'] ?? null;
        $summary['current_trading_date'] = $tradingDayState['trading_date'] ?? null;

        $summary['permissions'] = array_merge(
            $summary['permissions'] ?? [],
            $this->cashupFinancialVisibility->permissionFlags($canViewExpectedCashupAmounts),
            [
            'can_cashup_on_behalf' => $canCashupOnBehalf,
            'cashout_requires_manager_approval' => (bool) ($policy['cashout_requires_manager_approval'] ?? false),
            'cashup_end_shift_requires_manager_approval' => $cashupEndShiftRequiresManagerApproval,
            ]
        );
        $summary['actor'] = [
            'id' => $user->id,
            'name' => $user->name ?? $user->username ?? 'Unknown',
        ];
        $summary['available_shifts'] = $this->availableCashupShifts(
            (string) $company->id,
            (string) $till->branch_id,
            (string) $till->id,
            $user,
            $canCashupOnBehalf
        );
        $summary['selected_shift'] = collect($summary['available_shifts'])
            ->firstWhere('id', (string) $shift->id);

        /*
         * Frontend can use this to render denomination inputs:
         * 100, 50, 20, 10, 5, 2, 1, etc.
         */
        $denominationTemplate = $this->buildCashupDenominationTemplate(
            companyId: $company->id,
            branchId: $till->branch_id,
            defaultCurrencyCode: $request->input('currency_code', 'USD')
        );
        $summary['denomination_template'] = $denominationTemplate;

        if (!$canViewExpectedCashupAmounts) {
            $summary = $this->cashupFinancialVisibility->redactActiveSummary($summary);
            // Denomination face values are an input template, not historical
            // cashup totals. Cashiers still need them to enter a blind count.
            $summary['denomination_template'] = $denominationTemplate;
        }

        return response()->json([
            'message' => 'Active shift summary fetched successfully.',
            'data' => $summary,
        ]);
    }
    public function shiftCompliance(Request $request)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();

        $branchId = $till->branch_id;

        $policy = $this->shiftPolicyService->get($company->id, $branchId);

        /*
         * Active shift must include shiftconfigurations so the frontend can count down.
         */
        $activeShift = PosUserShiftSession::query()
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->leftJoin('shiftconfigurations as shift_configurations', function ($join) use ($company, $branchId) {
                $join->on('shift_configurations.id', '=', 'pos_user_shift_sessions.shift_configuration_id')
                    ->where('shift_configurations.company_id', '=', $company->id)
                    ->whereNull('shift_configurations.deleted_at')
                    ->where(function ($query) use ($branchId) {
                        $query->whereNull('shift_configurations.branch_id')
                            ->orWhere('shift_configurations.branch_id', $branchId);
                    });
            })
            ->select($this->shiftComplianceSelectColumns())
            ->where('pos_user_shift_sessions.company_id', $company->id)
            ->where('pos_user_shift_sessions.branch_id', $branchId)
            ->where('pos_user_shift_sessions.till_id', $till->id)
            ->where('pos_user_shift_sessions.pos_user_id', $user->id)
            ->where('pos_user_shift_sessions.status', 'open')
            ->whereNull('pos_user_shift_sessions.closed_at')
            ->orderByDesc('pos_user_shift_sessions.opened_at')
            ->first();

        /*
         * All unclosed shifts must also include their expected end time for the admin banner.
         */
        $unclosed = PosUserShiftSession::query()
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->leftJoin('shiftconfigurations as shift_configurations', function ($join) use ($company, $branchId) {
                $join->on('shift_configurations.id', '=', 'pos_user_shift_sessions.shift_configuration_id')
                    ->where('shift_configurations.company_id', '=', $company->id)
                    ->whereNull('shift_configurations.deleted_at')
                    ->where(function ($query) use ($branchId) {
                        $query->whereNull('shift_configurations.branch_id')
                            ->orWhere('shift_configurations.branch_id', $branchId);
                    });
            })
            ->select($this->shiftComplianceSelectColumns())
            ->where('pos_user_shift_sessions.company_id', $company->id)
            ->where('pos_user_shift_sessions.branch_id', $branchId)
            ->where('pos_user_shift_sessions.till_id', $till->id)
            ->where('pos_user_shift_sessions.status', 'open')
            ->whereNull('pos_user_shift_sessions.closed_at')
            ->orderBy('pos_user_shift_sessions.opened_at')
            ->get()
            ->map(fn ($shift) => $this->formatShiftComplianceSession($shift, $policy))
            ->values();

        $activeShiftPayload = $activeShift
            ? $this->formatShiftComplianceSession($activeShift, $policy)
            : null;

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);
        if (!$canViewFinancialAmounts) {
            $activeShiftPayload = $activeShiftPayload
                ? $this->cashupFinancialVisibility->redact($activeShiftPayload)
                : null;
            $unclosed = $this->cashupFinancialVisibility->redact($unclosed);
        }

        return response()->json([
            'message' => 'Shift compliance fetched successfully.',
            'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewFinancialAmounts),
            'data' => [
                'policy' => $policy,
                'active_shift' => $activeShiftPayload,
                'unclosed_shifts' => $unclosed,
                'must_cashup_before_new_shift' => $policy['require_cashup_before_new_shift'] && $unclosed->count() > 0,
                'can_continue_current_shift' => $policy['allow_continue_unclosed_shift'],
                'can_open_new_shift' => $policy['allow_new_shift_when_previous_unclosed'] || $unclosed->count() === 0,
                'server_time' => now()->toDateTimeString(),
            ],
        ]);
    }

    private function shiftComplianceSelectColumns(): array
    {
        return [
            'pos_user_shift_sessions.*',
            DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),

            DB::raw('shift_configurations.id as configuration_id'),
            DB::raw('shift_configurations.enable_shifts as configuration_enable_shifts'),
            DB::raw('shift_configurations.shift_mode as configuration_shift_mode'),
            DB::raw('shift_configurations.morning_shift_start as configuration_morning_shift_start'),
            DB::raw('shift_configurations.morning_shift_end as configuration_morning_shift_end'),
            DB::raw('shift_configurations.afternoon_shift_start as configuration_afternoon_shift_start'),
            DB::raw('shift_configurations.afternoon_shift_end as configuration_afternoon_shift_end'),
            DB::raw('shift_configurations.night_shift_start as configuration_night_shift_start'),
            DB::raw('shift_configurations.night_shift_end as configuration_night_shift_end'),
            DB::raw('shift_configurations.auto_shift_logout as configuration_auto_shift_logout'),
            DB::raw('shift_configurations.auto_logout_after_minutes as configuration_auto_logout_after_minutes'),
            DB::raw('shift_configurations.require_opening_float as configuration_require_opening_float'),
            DB::raw('shift_configurations.require_closing_cashup as configuration_require_closing_cashup'),
            DB::raw('shift_configurations.allow_multiple_open_shifts as configuration_allow_multiple_open_shifts'),
            DB::raw('shift_configurations.allow_unscheduled_shifts as configuration_allow_unscheduled_shifts'),
            DB::raw('shift_configurations.early_clock_in_minutes as configuration_early_clock_in_minutes'),
            DB::raw('shift_configurations.shift_warning_before_end_minutes as configuration_shift_warning_before_end_minutes'),
            DB::raw('shift_configurations.shift_grace_minutes_after_end as configuration_shift_grace_minutes_after_end'),
            DB::raw('shift_configurations.allow_continue_unclosed_shift as configuration_allow_continue_unclosed_shift'),
            DB::raw('shift_configurations.require_cashup_before_new_shift as configuration_require_cashup_before_new_shift'),
            DB::raw('shift_configurations.block_sales_after_shift_end as configuration_block_sales_after_shift_end'),
            DB::raw('shift_configurations.allow_supervisor_cashup as configuration_allow_supervisor_cashup'),
            DB::raw('shift_configurations.require_on_behalf_reason as configuration_require_on_behalf_reason'),
            DB::raw('shift_configurations.status as configuration_status'),
        ];
    }

    private function formatShiftComplianceSession($shift, array $policy): array
    {
        $shiftName = strtolower((string) $shift->shift_name);

        $configuration = [
            'id' => $shift->configuration_id,
            'enable_shifts' => (bool) $shift->configuration_enable_shifts,
            'shift_mode' => $shift->configuration_shift_mode,
            'morning_shift_start' => $shift->configuration_morning_shift_start,
            'morning_shift_end' => $shift->configuration_morning_shift_end,
            'afternoon_shift_start' => $shift->configuration_afternoon_shift_start,
            'afternoon_shift_end' => $shift->configuration_afternoon_shift_end,
            'night_shift_start' => $shift->configuration_night_shift_start,
            'night_shift_end' => $shift->configuration_night_shift_end,
            'auto_shift_logout' => (bool) $shift->configuration_auto_shift_logout,
            'auto_logout_after_minutes' => $shift->configuration_auto_logout_after_minutes,
            'require_opening_float' => (bool) $shift->configuration_require_opening_float,
            'require_closing_cashup' => (bool) $shift->configuration_require_closing_cashup,
            'allow_multiple_open_shifts' => (bool) $shift->configuration_allow_multiple_open_shifts,
            'allow_unscheduled_shifts' => (bool) $shift->configuration_allow_unscheduled_shifts,
            'early_clock_in_minutes' => (int) $shift->configuration_early_clock_in_minutes,
            'shift_warning_before_end_minutes' => (int) $shift->configuration_shift_warning_before_end_minutes,
            'shift_grace_minutes_after_end' => (int) $shift->configuration_shift_grace_minutes_after_end,
            'allow_continue_unclosed_shift' => (bool) $shift->configuration_allow_continue_unclosed_shift,
            'require_cashup_before_new_shift' => (bool) $shift->configuration_require_cashup_before_new_shift,
            'block_sales_after_shift_end' => (bool) $shift->configuration_block_sales_after_shift_end,
            'allow_supervisor_cashup' => (bool) $shift->configuration_allow_supervisor_cashup,
            'require_on_behalf_reason' => (bool) $shift->configuration_require_on_behalf_reason,
            'status' => $shift->configuration_status,
        ];

        $expectedStartTime = $this->expectedShiftTime($shiftName, $configuration, 'start');
        $expectedEndTime = $this->expectedShiftTime($shiftName, $configuration, 'end');
        $expectedEndAt = $this->buildExpectedShiftEndAt($shift->opened_at, $expectedEndTime);

        $secondsToEnd = $expectedEndAt
            ? now()->diffInSeconds($expectedEndAt, false)
            : null;

        $minutesToEnd = $secondsToEnd !== null
            ? (int) ceil($secondsToEnd / 60)
            : null;

        return [
            'id' => $shift->id,
            'company_id' => $shift->company_id,
            'branch_id' => $shift->branch_id,
            'pos_user_id' => $shift->pos_user_id,
            'cashier_name' => $shift->cashier_name,
            'shift_name' => $shift->shift_name,
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
            'opening_float' => $shift->opening_float,
            'closing_cash' => $shift->closing_cash,
            'status' => $shift->status,
            'created_at' => $shift->created_at,
            'updated_at' => $shift->updated_at,
            'shift_configuration_id' => $shift->shift_configuration_id,

            /*
             * Frontend banner uses this object.
             */
            'shift_configuration' => $configuration,

            /*
             * Frontend can use these direct fields for accurate countdown.
             */
            'shift_expected_start_time' => $expectedStartTime,
            'shift_expected_end_time' => $expectedEndTime,
            'shift_end_at' => $expectedEndAt?->toIso8601String(),
            'expected_end_at' => $expectedEndAt?->toIso8601String(),
            'seconds_to_shift_end' => $secondsToEnd,
            'minutes_to_shift_end' => $minutesToEnd,
            'is_shift_ended' => $secondsToEnd !== null && $secondsToEnd <= 0,
            'is_shift_in_warning' => $minutesToEnd !== null
                && $minutesToEnd > 0
                && $minutesToEnd <= (int) ($policy['shift_warning_before_end_minutes'] ?? 15),
            'is_shift_grace_expired' => $secondsToEnd !== null
                && $secondsToEnd < -((int) ($policy['shift_grace_minutes_after_end'] ?? 0) * 60),
        ];
    }

    private function expectedShiftTime(string $shiftName, array $configuration, string $type): ?string
    {
        $field = match ($shiftName) {
            'morning' => "morning_shift_{$type}",
            'afternoon', 'evening' => "afternoon_shift_{$type}",
            'night' => "night_shift_{$type}",
            default => null,
        };

        if (!$field) {
            return null;
        }

        return $configuration[$field] ?? null;
    }

    private function buildExpectedShiftEndAt($openedAt, ?string $expectedEndTime): ?Carbon
    {
        if (!$expectedEndTime || !$openedAt) {
            return null;
        }

        $opened = Carbon::parse($openedAt);
        $endTime = Carbon::parse($expectedEndTime);

        $expectedEnd = $opened->copy()->setTime(
            (int) $endTime->format('H'),
            (int) $endTime->format('i'),
            (int) $endTime->format('s')
        );

        /*
         * If the configured end time is earlier than opened_at, the shift ends the next day.
         * Example: night shift opens at 22:00 and ends at 06:00.
         */
        if ($expectedEnd->lessThanOrEqualTo($opened)) {
            $expectedEnd->addDay();
        }

        return $expectedEnd;
    }


    public function recordCashMovement(Request $request)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();
        $policy = $this->shiftPolicyService->get($company->id, $till->branch_id);
        $permission = $this->cashupPermission($user, 'cashup_shift', (string) $company->id);
        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'client_action_id' => ['nullable', 'uuid'],
            'shift_session_id' => ['nullable', 'string', 'max:36'],
            'movement_type' => ['required', 'string', 'in:cash_in,cash_out,float_in,float_out,bank_drop,paid_out,petty_cash'],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'currency_code' => ['required', 'string', 'max:10'],
            'reason' => [$policy['require_cashout_reason'] ? 'required' : 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'approved_by' => ['nullable', 'string', 'max:36'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $commandType = 'cash_movement';
        $preflight = $this->posCommands->inspect(
            $request,
            $commandType,
            (string) $company->id,
            (string) $till->branch_id,
            (string) $till->id,
            (string) $user->id
        );
        if ($preflight['response']) {
            return $preflight['response'];
        }

        if (
            $policy['cashout_requires_manager_approval']
            && in_array($request->movement_type, ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'], true)
            && !$request->filled('approved_by')
        ) {
            return response()->json(['message' => 'Manager approval is required for this cash movement.'], 422);
        }

        $shift = $this->resolveCashupShift(
            (string) $company->id,
            (string) $till->branch_id,
            (string) $till->id,
            $user,
            $request->input('shift_session_id'),
            $policy
        );

        if (!$shift) {
            return response()->json(['message' => 'No open shift found.'], 404);
        }

        $movementTillId = (string) $till->id;
        $execute = function (?PosCommandReceipt $receipt) use (
            $company,
            $till,
            $user,
            $policy,
            $request,
            $shift,
            $movementTillId
        ) {
            $id = (string) Str::uuid();

            DB::table('pos_cash_drawer_movements')->insert([
                'id' => $id,
                'company_id' => $company->id,
                'branch_id' => $till->branch_id,
                'till_id' => $movementTillId,
                'shift_session_id' => $shift->id,
                'cashier_id' => $shift->pos_user_id,
                'movement_type' => $request->movement_type,
                'amount' => round((float) $request->amount, 4),
                'currency_code' => strtoupper($request->currency_code),
                'reason' => $request->reason,
                'notes' => $request->notes,
                'reference_no' => $request->reference_no,
                'approved_by' => $request->approved_by,
                'created_by' => $user->id,
                'occurred_at' => now(),
                'metadata' => json_encode(['policy' => $policy]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $summary = $this->buildShiftSummary(
                $company->id,
                $till->branch_id,
                $till->id,
                $shift->id,
                $shift->pos_user_id
            );
            $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts(
                $user,
                (string) $company->id
            );
            if (!$canViewFinancialAmounts) {
                $summary = $this->cashupFinancialVisibility->redactActiveSummary($summary);
            }

            $response = response()->json([
                'message' => 'Cash drawer movement recorded successfully.',
                'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewFinancialAmounts),
                'data' => [
                    'id' => $id,
                    'summary' => $summary,
                ],
            ]);
            if ($receipt) {
                $this->posCommands->complete($receipt, $response);
            }

            return $response;
        };

        if (!$request->filled('client_action_id')) {
            return $execute(null);
        }

        return DB::transaction(function () use (
            $request,
            $commandType,
            $company,
            $till,
            $user,
            $execute
        ) {
            $claim = $this->posCommands->claim(
                $request,
                $commandType,
                (string) $company->id,
                (string) $till->branch_id,
                (string) $till->id,
                (string) $user->id
            );
            if ($claim['response']) {
                return $claim['response'];
            }

            return $execute($claim['receipt']);
        }, 3);
    }



    public function cashup(Request $request)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();
        $policy = $this->shiftPolicyService->get($company->id, $till->branch_id);

        $permission = $this->cashupPermission($user, 'cashup_shift', (string) $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);

        $validator = Validator::make($request->all(), [
            'client_action_id' => ['nullable', 'uuid'],
            'shift_session_id' => ['nullable', 'string', 'max:36'],
            'counted_cash' => ['nullable', 'numeric', 'min:0'],

            'denominations' => ['nullable', 'array'],
            'denominations.*.currency_code' => ['required_with:denominations', 'string', 'max:10'],
            'denominations.*.value' => ['required_with:denominations', 'numeric', 'min:0'],
            'denominations.*.quantity' => ['required_with:denominations', 'integer', 'min:0'],
            'denominations.*.type' => ['nullable', 'string', 'in:note,coin,bond_note,voucher,other'],

            'counted_payments' => ['nullable', 'array'],
            'counted_payments.*.payment_name' => ['required_with:counted_payments', 'string', 'max:255'],
            'counted_payments.*.money_code' => ['nullable', 'string', 'max:255'],
            'counted_payments.*.currency_code' => ['required_with:counted_payments', 'string', 'max:10'],
            'counted_payments.*.amount' => ['required_with:counted_payments', 'numeric', 'min:0'],

            'trading_date' => ['nullable', 'date'],
            'cashup_currency_code' => ['nullable', 'string', 'max:10'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            // The branch/till configuration is authoritative. Accepting this
            // from a terminal would let a caller choose a different cashup
            // model than the workspace it is assigned to.
            'business_mode' => self::CASHUP_BUSINESS_MODE_VALIDATION_RULES,

            'notes' => ['nullable', 'string'],
            'force_close_reason' => ['nullable', 'string'],
            'close_shift' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            $validationErrors = $validator->errors()->toArray();
            if (!$canViewFinancialAmounts) {
                $validationErrors = $this->cashupFinancialVisibility->redactBlindCashupErrors($validationErrors);
            }

            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validationErrors,
            ], 422);
        }

        $commandType = (string) $request->attributes->get('pos_command_type', 'cashup');
        $preflight = $this->posCommands->inspect(
            $request,
            $commandType,
            (string) $company->id,
            (string) $till->branch_id,
            (string) $till->id,
            (string) $user->id
        );
        if ($preflight['response']) {
            return $preflight['response'];
        }

        $shift = $this->resolveCashupShift(
            (string) $company->id,
            (string) $till->branch_id,
            (string) $till->id,
            $user,
            $request->input('shift_session_id'),
            $policy
        );

        if (!$shift) {
            return response()->json(['message' => 'No open shift found.'], 404);
        }

        $isOnBehalf = (string) $shift->pos_user_id !== (string) $user->id;
        if ($isOnBehalf && !$this->canCashupOnBehalf($user, (string) $company->id, $policy)) {
            return response()->json([
                'message' => 'You do not have permission to cash up another cashier.',
            ], 403);
        }

        if ($isOnBehalf && ($policy['require_on_behalf_reason'] ?? true) && !$request->filled('force_close_reason')) {
            return response()->json([
                'message' => 'A supervisor reason is required when cashing up for another cashier.',
                'errors' => ['force_close_reason' => ['Enter the reason for cashing up on behalf of this cashier.']],
            ], 422);
        }

        $selectedShiftId = (string) $shift->id;

        DB::beginTransaction();

        try {
            $receipt = null;
            if ($request->filled('client_action_id')) {
                $claim = $this->posCommands->claim(
                    $request,
                    $commandType,
                    (string) $company->id,
                    (string) $till->branch_id,
                    (string) $till->id,
                    (string) $user->id
                );
                if ($claim['response']) {
                    DB::commit();

                    return $claim['response'];
                }
                $receipt = $claim['receipt'];
            }

            /*
             * Serialize cashup against the selected shift. A supervisor may have
             * opened the modal before the cashier (or another supervisor) closed
             * it. Re-reading the company/branch-scoped row with a write lock keeps
             * a delayed or double-clicked request from creating/replacing a second
             * close for the same shift.
             */
            $shift = PosUserShiftSession::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $till->branch_id)
                ->where('till_id', $till->id)
                ->where('id', $selectedShiftId)
                ->where('status', 'open')
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if (!$shift) {
                DB::rollBack();

                return response()->json([
                    'message' => 'This shift has already been closed or is no longer available for cashup.',
                    'code' => 'CASHUP_SHIFT_ALREADY_CLOSED',
                ], 409);
            }

            $summary = $this->buildShiftSummary(
                $company->id,
                $till->branch_id,
                $till->id,
                $shift->id,
                $shift->pos_user_id
            );
            $cashupTillId = (string) $till->id;
            $cashupTill = DB::table('pos_tills')->where('id', $cashupTillId)->first() ?: $till;
            if (!isset($cashupTill->branch_id)) {
                $cashupTill->branch_id = $till->branch_id;
            }

            $businessModeContext = $this->resolveCashupBusinessMode($company->id, $till->branch_id);
            if ($businessModeContext['mode_mismatch']) {
                throw ValidationException::withMessages([
                    'business_mode' => [
                        'Cashup mode does not match this branch. The authoritative mode is '
                        . $businessModeContext['detected_mode'] . '.',
                    ],
                ]);
            }
            $cashupCurrencyCode = $this->resolveCashupCurrencyCode($request, $summary);
            $cashupClosedAt = now();
            $tradingDate = $this->resolveCashupTradingDate(
                request: $request,
                businessMode: $businessModeContext['business_mode'],
                companyId: (string) $company->id,
                branchId: (string) $till->branch_id,
                openedAt: $shift->opened_at,
                closedAt: $cashupClosedAt
            );
            $cashupPeriod = $this->buildCashupPeriodBracket(
                businessMode: $businessModeContext['business_mode'],
                tradingDate: $tradingDate,
                currencyCode: $cashupCurrencyCode,
                companyId: (string) $company->id,
                branchId: (string) $till->branch_id,
                tillId: $cashupTillId,
                cashierId: (string) $shift->pos_user_id,
                shiftSessionId: (string) $shift->id,
                shiftName: (string) ($shift->shift_name ?? 'Shift'),
                openedAt: $shift->opened_at,
                closedAt: $cashupClosedAt
            );

            /*
             * Denomination rows:
             * [
             *   { currency_code: "USD", value: 100, quantity: 2, type: "note" }
             * ]
             */
            $denominationRows = $this->normalizeCashupDenominations(
                request: $request,
                companyId: $company->id,
                branchId: $till->branch_id,
                tillId: $till->id
            );

            /*
             * If denominations are supplied, they become the counted CASH amount.
             * Non-cash counted payments remain from counted_payments.
             */
            $countedPayments = $this->normalizeCountedPayments($request, $summary);

            /*
             | Never allow opening float / drawer count to enter payment variance.
             | It must not become "CASH DRAWER USD" in the report.
             */
            $countedPayments = $this->removeDrawerRowsFromCountedPayments($countedPayments);

            if (!empty($denominationRows)) {
                $countedPayments = $this->applyDenominationsToCountedPayments(
                    summary: $summary,
                    countedPayments: $countedPayments,
                    denominationRows: $denominationRows
                );
            }

            $varianceRows = $this->buildCashupVariance($summary['payments'], $countedPayments);
            $currencySummary = $this->buildCashupCurrencySummary($varianceRows);
            $totalsByCurrency = $this->buildTotalsByCurrency($varianceRows);
            $cashByCurrency = $this->buildCashByCurrency($varianceRows, $summary['cash_movements']);
            $totals = $this->buildCashupTotals($varianceRows);

            $isMultiCurrency = $this->hasMultipleCurrencies($varianceRows)
                || collect($cashByCurrency)
                    ->pluck('currency_code')
                    ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                    ->filter()
                    ->unique()
                    ->count() > 1;
            if ($isMultiCurrency) {
                $totals['currency_code'] = 'MULTI';
                $totals['is_multi_currency'] = true;
                $totals['expected_total'] = null;
                $totals['counted_total'] = null;
                $totals['total_variance'] = null;
            }
            $primaryCashRow = $this->primaryCashRow($cashByCurrency, $cashupCurrencyCode);
            $persistedExpectedTotal = $isMultiCurrency
                ? ($totals['base_expected_total'] ?? 0)
                : ($totals['expected_total'] ?? 0);
            $persistedCountedTotal = $isMultiCurrency
                ? ($totals['base_counted_total'] ?? 0)
                : ($totals['counted_total'] ?? 0);
            $persistedTotalVariance = $isMultiCurrency
                ? ($totals['base_total_variance'] ?? 0)
                : ($totals['total_variance'] ?? 0);

            $expectedCashBeforeCashouts = $primaryCashRow['expected_cash'];
            $cashoutTotal = $primaryCashRow['cashout_total'];
            $expectedCashAfterCashouts = $primaryCashRow['expected_cash_after_cashouts'];
            $countedCash = $primaryCashRow['counted_cash'];
            $cashVariance = $primaryCashRow['cash_variance'];

            /*
             * Do not rely on updateOrCreate for UUID primary key unless your model
             * auto-generates UUIDs. This is safer.
             */
            $cashup = PosCashup::firstOrNew($this->buildCashupIdentity(
                companyId: (string) $company->id,
                shiftSessionId: (string) $shift->id
            ));

            if (!$cashup->exists) {
                $cashup->id = (string) Str::uuid();
            }

            $cashup->fill($this->filterCashupPayloadByColumns([
                'branch_id' => $till->branch_id,
                'till_id' => $cashupTillId,
                'shift_configuration_id' => $shift->shift_configuration_id,
                'cashier_id' => $shift->pos_user_id,
                'closed_by' => $user->id,

                'business_mode' => $businessModeContext['business_mode'],
                'boh_enabled' => $businessModeContext['boh_enabled'],
                'fast_food_mode_enabled' => $businessModeContext['fast_food_mode_enabled'],
                'trading_date' => $tradingDate,
                'cashup_period_key' => $cashupPeriod['key'],
                'cashup_period_label' => $cashupPeriod['label'],
                'cashup_opened_at' => $shift->opened_at,
                'cashup_closed_at' => $cashupClosedAt,
                'cashup_currency_code' => $isMultiCurrency ? 'MULTI' : $cashupCurrencyCode,
                'base_currency_code' => $this->resolveBaseCurrencyCode($summary, $cashupCurrencyCode),
                'business_mode_snapshot' => $businessModeContext,

                'opening_float' => round((float) ($shift->opening_float ?? 0), 4),

                'expected_cash' => $expectedCashBeforeCashouts,
                'counted_cash' => $countedCash,
                'cash_variance' => $cashVariance,

                'cashout_total' => $cashoutTotal,
                'cashout_count' => count($summary['cash_movements']['cash_out_rows']),
                'expected_cash_after_cashouts' => $expectedCashAfterCashouts,

                // Legacy scalar columns cannot represent multiple currencies.
                // For MULTI cashups they store base-currency equivalents only;
                // API/report presentation uses the per-currency buckets below.
                'expected_total' => $persistedExpectedTotal,
                'counted_total' => $persistedCountedTotal,
                'total_variance' => $persistedTotalVariance,

                'transaction_count' => $summary['totals']['transaction_count'],
                'void_count' => $summary['totals']['void_count'],
                'refund_count' => $summary['totals']['refund_count'],

                'payment_summary' => $varianceRows,
                'currency_summary' => $currencySummary,
                'sales_summary' => [
                    'shift' => $summary['shift'],
                    'totals' => $summary['totals'],
                    'cash' => $summary['cash'],
                    'cash_by_currency' => $cashByCurrency,
                    'totals_by_currency' => $totalsByCurrency,
                    'reconciliation_totals' => $totals,
                    'is_multi_currency' => $isMultiCurrency,
                    'primary_cash_currency_code' => $primaryCashRow['currency_code'] ?? $cashupCurrencyCode,
                    'credit' => $summary['credit'] ?? [],
                    'payments' => $summary['payments'],
                    'currencies' => $summary['currencies'],
                    'denominations' => $this->summarizeDenominations($denominationRows),
                ],
                'cashout_summary' => $summary['cash_movements'],
                'shift_policy_snapshot' => $policy,
                'force_close_reason' => $request->force_close_reason,

                'status' => 'closed',
                'notes' => $request->notes,
                'opened_at' => $shift->opened_at,
                'closed_at' => $cashupClosedAt,
            ]));

            $cashup->save();

            /*
             * Save denomination lines against the cashup.
             */
            $this->saveCashupDenominations($cashup, $denominationRows);

            $shouldCloseShift = $request->boolean('close_shift', true);

            if ($shouldCloseShift) {
                $shift->update([
                    'closing_cash' => $countedCash,
                    'closed_at' => $cashupClosedAt,
                    'status' => 'closed',
                ]);
            }

            $freshCashup = $this->hydrateCashoutFields(
                $cashup->fresh()->load('denominations')
            );

            $cashupPrintPayload = $shouldCloseShift && $canViewFinancialAmounts
                ? $this->buildShiftEndCashupPrintPayload(
                    cashup: $freshCashup,
                    summary: $summary,
                    varianceRows: $varianceRows,
                    currencySummary: $currencySummary,
                    totals: $totals,
                    cashier: PosUser::find($shift->pos_user_id) ?? $user,
                    closedBy: $user,
                    till: $cashupTill,
                    policy: $policy
                )
                : null;

            $responseData = [
                'cashup' => $freshCashup,
                'business_mode' => $businessModeContext,
                'trading_date' => $tradingDate,
                'cashup_period' => $cashupPeriod,
                'cashup_currency_code' => $isMultiCurrency ? 'MULTI' : $cashupCurrencyCode,
                'denominations' => $freshCashup->denominations,

                'summary' => $summary,
                'counted_payments' => $countedPayments,
                'variance' => $varianceRows,
                'currency_summary' => $currencySummary,
                'totals' => $totals,
                'totals_by_currency' => $totalsByCurrency,
                'cash_by_currency' => $cashByCurrency,
                'is_multi_currency' => $isMultiCurrency,

                'cashout_total' => $cashoutTotal,
                'cashout_count' => count($summary['cash_movements']['cash_out_rows']),
                'expected_cash_after_cashouts' => $expectedCashAfterCashouts,

                'shift_closed' => $shouldCloseShift,

                'cashup_print' => $cashupPrintPayload,
                'print' => $cashupPrintPayload ? [
                    'auto_print' => (bool) ($cashupPrintPayload['auto_print'] ?? false),
                    'printer' => $cashupPrintPayload['printer'] ?? null,
                ] : [
                    'auto_print' => false,
                    'printer' => null,
                ],
            ];

            if (!$canViewFinancialAmounts) {
                $responseData = $this->cashupFinancialVisibility->redact($responseData);
                $responseData['cashup_print'] = null;
                $responseData['print'] = ['auto_print' => false, 'printer' => null];
            }

            $response = response()->json([
                'message' => $shouldCloseShift
                    ? 'Cashup completed and shift closed successfully.'
                    : 'Cashup completed successfully.',
                'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewFinancialAmounts),
                'data' => $responseData,
            ]);
            if ($receipt) {
                $this->posCommands->complete($receipt, $response);
            }
            DB::commit();

            return $response;
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $validationErrors = $e->errors();
            if (!$canViewFinancialAmounts) {
                $validationErrors = $this->cashupFinancialVisibility->redactBlindCashupErrors($validationErrors);
            }

            return response()->json([
                'message' => 'Cashup validation failed.',
                'errors' => $validationErrors,
            ], 422);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'message' => 'Cashup failed.',
                'error' => $canViewFinancialAmounts
                    ? $e->getMessage()
                    : 'An internal error prevented the cashup from completing.',
            ], 500);
        }
    }

    public function endShift(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = $this->cashupPermission($user, 'end_shift', (string) $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $request->merge(['close_shift' => true]);
        $request->attributes->set('pos_command_type', 'end_shift');

        return $this->cashup($request);
    }

    public function cashupHistory(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'cash_up_reports', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);

        $branchIds = $this->resolveCashupReportBranchIds($request, (string) $company->id);
        $closedAtColumn = Schema::hasColumn('pos_cashups', 'cashup_closed_at') ? 'cashup_closed_at' : 'closed_at';
        $requestedCurrencyCodes = $this->requestedCurrencyCodes($request);

        $query = PosCashup::query()
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_cashups.cashier_id')
            ->leftJoin('pos_users as closed_users', 'closed_users.id', '=', 'pos_cashups.closed_by')
            ->leftJoin('pos_tills', 'pos_tills.id', '=', 'pos_cashups.till_id')
            ->select([
                'pos_cashups.*',
                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
                DB::raw('COALESCE(closed_users.name, closed_users.username, "Unknown") as closed_by_name'),
                DB::raw('COALESCE(pos_tills.till_name, pos_tills.till_code, "Unknown Till") as till_name'),
                DB::raw('pos_tills.till_code as till_code'),
            ])
            ->where('pos_cashups.company_id', $company->id)
            ->when(!empty($branchIds), fn ($q) => $q->whereIn('pos_cashups.branch_id', $branchIds));

        foreach (['cashier_id', 'shift_session_id', 'till_id'] as $field) {
            if ($request->filled($field)) {
                $query->where("pos_cashups.{$field}", $request->input($field));
            }
        }

        if ($request->filled('business_mode') && Schema::hasColumn('pos_cashups', 'business_mode')) {
            $query->where('pos_cashups.business_mode', $request->business_mode);
        }

        if (!empty($requestedCurrencyCodes)) {
            $query->where(function ($q) use ($requestedCurrencyCodes) {
                if (Schema::hasColumn('pos_cashups', 'cashup_currency_code')) {
                    $q->whereIn('pos_cashups.cashup_currency_code', $requestedCurrencyCodes)
                        ->orWhere('pos_cashups.cashup_currency_code', 'MULTI')
                        ->orWhereNull('pos_cashups.cashup_currency_code');
                } else {
                    $q->whereRaw('1 = 1');
                }
            });
        }

        $this->applyCashupBracketDateFilters($query, $request);

        $query
            ->orderByRaw($this->cashupUnifiedBracketDateExpression() . ' DESC')
            ->orderByDesc("pos_cashups.{$closedAtColumn}");

        $perPage = max(1, min((int) $request->input('per_page', 25), 1000));

        if (!empty($requestedCurrencyCodes)) {
            // MULTI and legacy cashups cannot be filtered accurately from the
            // scalar cashup_currency_code. Resolve their JSON summaries first,
            // then paginate the matching collection so totals and page counts
            // describe the rows that the client can actually receive.
            $currentPage = max(1, (int) $request->input('page', 1));
            $page = $this->paginateCashupRowsForCurrencies(
                $query->get()
                    ->map(fn ($row) => $this->decorateCashupBracketFields($this->hydrateCashoutFields($row))),
                $requestedCurrencyCodes,
                $perPage,
                $currentPage,
                $request
            );
            $page->setCollection($page->getCollection()
                ->map(function ($row) use ($requestedCurrencyCodes) {
                    $row->denominations = $this->getCashupDenominations((string) $row->id);

                    return $this->filterCashupRowCurrencies($row, $requestedCurrencyCodes);
                }));
        } else {
            $page = $query->paginate($perPage);
            $page->setCollection($page->getCollection()->map(function ($row) {
                $row = $this->decorateCashupBracketFields($this->hydrateCashoutFields($row));
                $row->denominations = $this->getCashupDenominations((string) $row->id);

                return $row;
            }));
        }

        $groups = $this->groupCashupsByBracketAndCurrency($page->getCollection());
        $paymentMethodTables = $this->paymentMethodReportTables(
            $page->getCollection(),
            $canViewFinancialAmounts,
            'cashup'
        );
        $accountCreditTables = $this->accountCreditReportTables(
            $page->getCollection(),
            $canViewFinancialAmounts,
            'cashup'
        );
        if (!$canViewFinancialAmounts) {
            $page->setCollection($this->cashupFinancialVisibility->redact($page->getCollection()));
            $groups = $this->cashupFinancialVisibility->redact($groups);
        }

        return response()->json([
            'message' => 'Cashup history fetched successfully.',
            'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewFinancialAmounts),
            'data' => $page,
            'groups' => $groups,
            'payment_method_headers' => $paymentMethodTables['headers'],
            'payment_method_rows' => $paymentMethodTables['rows'],
            'payment_method_summary_headers' => $paymentMethodTables['summary_headers'],
            'payment_method_summary_rows' => $paymentMethodTables['summary_rows'],
            'account_credit_headers' => $accountCreditTables['headers'],
            'account_credit_rows' => $accountCreditTables['rows'],
            'account_credit_summary_headers' => $accountCreditTables['summary_headers'],
            'account_credit_summary_rows' => $accountCreditTables['summary_rows'],
        ]);
    }


    public function cashoutReport(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'cash_up_reports', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);

        $branchIds = $this->resolveCashupReportBranchIds($request, (string) $company->id);
        $requestedCurrencyCodes = $this->requestedCurrencyCodes($request);

        $baseQuery = DB::table('pos_cash_drawer_movements')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_cash_drawer_movements.cashier_id')
            ->leftJoin('pos_tills', 'pos_tills.id', '=', 'pos_cash_drawer_movements.till_id')
            ->leftJoin('pos_user_shift_sessions', 'pos_user_shift_sessions.id', '=', 'pos_cash_drawer_movements.shift_session_id')
            ->leftJoin('pos_cashups', function ($join) {
                $join->on('pos_cashups.company_id', '=', 'pos_cash_drawer_movements.company_id')
                    ->on('pos_cashups.branch_id', '=', 'pos_cash_drawer_movements.branch_id')
                    ->on('pos_cashups.shift_session_id', '=', 'pos_cash_drawer_movements.shift_session_id');
            })
            ->where('pos_cash_drawer_movements.company_id', $company->id)
            ->when(!empty($branchIds), fn ($q) => $q->whereIn('pos_cash_drawer_movements.branch_id', $branchIds))
            ->whereNull('pos_cash_drawer_movements.deleted_at')
            ->when($request->filled('cashier_id'), fn ($q) => $q->where('pos_cash_drawer_movements.cashier_id', $request->cashier_id))
            ->when($request->filled('till_id'), fn ($q) => $q->where('pos_cash_drawer_movements.till_id', $request->till_id))
            ->when($request->filled('shift_session_id'), fn ($q) => $q->where('pos_cash_drawer_movements.shift_session_id', $request->shift_session_id))
            ->when($request->filled('movement_type'), fn ($q) => $q->where('pos_cash_drawer_movements.movement_type', $request->movement_type))
            ->when(!empty($requestedCurrencyCodes), fn ($q) => $q->whereIn('pos_cash_drawer_movements.currency_code', $requestedCurrencyCodes));

        $this->applyCashMovementReportModeAndDateFilters($baseQuery, $request);

        $summaryRows = (clone $baseQuery)
            ->selectRaw('
                pos_cash_drawer_movements.movement_type,
                pos_cash_drawer_movements.currency_code,
                COUNT(*) as movement_count,
                SUM(pos_cash_drawer_movements.amount) as amount
            ')
            ->groupBy('pos_cash_drawer_movements.movement_type', 'pos_cash_drawer_movements.currency_code')
            ->orderBy('pos_cash_drawer_movements.movement_type')
            ->orderBy('pos_cash_drawer_movements.currency_code')
            ->get()
            ->map(fn ($row) => [
                'movement_type' => $row->movement_type,
                'currency_code' => $row->currency_code,
                'movement_count' => (int) $row->movement_count,
                'amount' => round((float) $row->amount, 4),
            ])
            ->values();

        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
        $cashInTypes = ['cash_in', 'float_in'];

        $rows = (clone $baseQuery)
            ->select([
                'pos_cash_drawer_movements.id',
                'pos_cash_drawer_movements.company_id',
                'pos_cash_drawer_movements.branch_id',
                'pos_cash_drawer_movements.till_id',
                'pos_cash_drawer_movements.shift_session_id',
                'pos_cash_drawer_movements.cashier_id',
                'pos_cash_drawer_movements.movement_type',
                'pos_cash_drawer_movements.amount',
                'pos_cash_drawer_movements.currency_code',
                'pos_cash_drawer_movements.reason',
                'pos_cash_drawer_movements.notes',
                'pos_cash_drawer_movements.reference_no',
                'pos_cash_drawer_movements.approved_by',
                'pos_cash_drawer_movements.created_by',
                'pos_cash_drawer_movements.occurred_at',
                'pos_cash_drawer_movements.created_at',
                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
                DB::raw('COALESCE(pos_tills.till_name, pos_tills.till_code, "Unknown Till") as till_name'),
                DB::raw('pos_tills.till_code as till_code'),
                DB::raw('pos_user_shift_sessions.shift_name as shift_name'),
                DB::raw('pos_user_shift_sessions.opened_at as shift_opened_at'),
                DB::raw('pos_user_shift_sessions.closed_at as shift_closed_at'),
                DB::raw('pos_cashups.id as cashup_id'),
                DB::raw('pos_cashups.closed_at as cashup_closed_at'),
                DB::raw('pos_cashups.status as cashup_status'),
                DB::raw((Schema::hasColumn('pos_cashups', 'business_mode') ? 'pos_cashups.business_mode' : 'NULL') . ' as cashup_business_mode'),
                DB::raw($this->cashMovementReportBusinessDateExpression($request) . ' as report_business_date'),
            ])
            ->orderByDesc('pos_cash_drawer_movements.occurred_at')
            ->paginate($request->per_page ?? 50);

        $requestedBusinessMode = strtolower(trim((string) $request->input('business_mode', '')));
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($requestedBusinessMode) {
            $row->report_business_mode = $row->cashup_business_mode ?: ($requestedBusinessMode ?: null);

            return $row;
        }));

        $summary = $this->cashMovementCurrencySummary($summaryRows, $cashOutTypes, $cashInTypes);

        if (!$canViewFinancialAmounts) {
            $rows->setCollection($this->cashupFinancialVisibility->redact($rows->getCollection()));
            $summary = $this->cashupFinancialVisibility->redact($summary);
        }

        return response()->json([
            'message' => 'Cashout report fetched successfully.',
            'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewFinancialAmounts),
            'summary' => $summary,
            'data' => $rows,
        ]);
    }



    private function buildShiftEndCashupPrintPayload($cashup, array $summary, array $varianceRows, array $currencySummary, array $totals, $cashier, $closedBy, $till, array $policy): ?array
    {
        $company = app('company');

        if (!$this->generalSettingEnabled($company->id, $till->branch_id, 'shift_end_cashup_report_print_enabled', false)) {
            return null;
        }

        $includeProductSales = $this->generalSettingEnabled($company->id, $till->branch_id, 'shift_end_cashup_report_include_product_sales', false);

        $categoryRows = $this->buildCashupCategorySalesSummary(
            companyId: $company->id,
            branchId: $till->branch_id,
            shiftSessionId: (string) $cashup->shift_session_id
        );

        $productRows = $includeProductSales
            ? $this->buildCashupProductSalesSummary(
                companyId: $company->id,
                branchId: $till->branch_id,
                shiftSessionId: (string) $cashup->shift_session_id
            )
            : [];

        $printer = $this->resolveCashupReportPrinter($company->id, $till->branch_id, $till->id);
        $branchName = DB::table('company_branches')
            ->where('id', $till->branch_id)
            ->value('branch_name');

        $payload = [
            'auto_print' => true,
            'printer' => $printer,
            'include_product_sales' => $includeProductSales,
            'cashup_id' => $cashup->id,
            'shift_session_id' => $cashup->shift_session_id,
            'supervisor_reason' => $cashup->force_close_reason,
            'report_title' => 'SHIFT END CASHUP REPORT',
            'generated_at' => now()->toDateTimeString(),
            'denominations' => $cashup->denominations
                ? $cashup->denominations->map(fn ($row) => [
                    'currency_code' => $row->currency_code,
                    'denomination_value' => round((float) $row->denomination_value, 4),
                    'quantity' => (int) $row->quantity,
                    'line_total' => round((float) $row->line_total, 4),
                    'denomination_type' => $row->denomination_type,
                ])->values()->all()
                : [],
            'company' => [
                'id' => $company->id,
                'name' => $company->company_name ?? $company->name ?? 'Company',
            ],
            'branch' => [
                'id' => $till->branch_id,
                'name' => $branchName ?? $till->branch_name ?? null,
            ],
            'till' => [
                'id' => $till->id,
                'code' => $till->till_code ?? null,
                'name' => $till->till_name ?? null,
            ],
            'cashier' => [
                'id' => $cashier->id,
                'name' => $cashier->name ?? $cashier->username ?? null,
            ],
            'closed_by' => [
                'id' => $closedBy->id,
                'name' => $closedBy->name ?? $closedBy->username ?? null,
            ],
            'shift' => [
                'name' => $cashup->sales_summary['shift']['shift_name'] ?? null,
                'opened_at' => $cashup->opened_at,
                'closed_at' => $cashup->closed_at,
            ],
            'cash_by_currency' => $cashup->cash_by_currency ?? data_get($cashup->sales_summary, 'cash_by_currency', []),
            'totals_by_currency' => $cashup->totals_by_currency ?? data_get($cashup->sales_summary, 'totals_by_currency', []),
            'totals' => [
                'expected_total' => round((float) ($totals['expected_total'] ?? $cashup->expected_total ?? 0), 4),
                'counted_total' => round((float) ($totals['counted_total'] ?? $cashup->counted_total ?? 0), 4),
                'total_variance' => round((float) ($totals['total_variance'] ?? $cashup->total_variance ?? 0), 4),
                'expected_cash' => round((float) ($cashup->expected_cash ?? 0), 4),
                'counted_cash' => round((float) ($cashup->counted_cash ?? 0), 4),
                'cash_variance' => round((float) ($cashup->cash_variance ?? 0), 4),
                'cashout_total' => round((float) ($cashup->cashout_total ?? 0), 4),
                'expected_cash_after_cashouts' => round((float) ($cashup->expected_cash_after_cashouts ?? 0), 4),
                'transaction_count' => (int) ($cashup->transaction_count ?? ($summary['totals']['transaction_count'] ?? 0)),
                'void_count' => (int) ($cashup->void_count ?? ($summary['totals']['void_count'] ?? 0)),
                'refund_count' => (int) ($cashup->refund_count ?? ($summary['totals']['refund_count'] ?? 0)),
            ],
            'payments' => $varianceRows,
            'currencies' => $currencySummary,
            'cash_movements' => $summary['cash_movements'] ?? [],
            'category_sales' => $categoryRows,
            'product_sales' => $productRows,
        ];

        $payload['html'] = $this->buildCashupReportDocketHtml($payload);

        return $payload;
    }


    private function resolveCashupBusinessMode(string $companyId, ?string $branchId, ?string $requestedMode = null): array
    {
        $bohEnabled = $this->companySettings->isBohEnabled($companyId, $branchId);
        $fastFoodModeEnabled = $this->companySettings->isFastFoodMode($companyId, $branchId);
        $detectedMode = $this->companySettings->isHospitalityMode($companyId, $branchId)
            ? 'fast_food_boh'
            : 'retail';
        $normalizedRequestedMode = in_array($requestedMode, ['fast_food_boh', 'retail'], true)
            ? $requestedMode
            : null;
        $businessMode = $detectedMode;

        return [
            'business_mode' => $businessMode,
            'boh_enabled' => $bohEnabled,
            'fast_food_mode_enabled' => $fastFoodModeEnabled,
            'is_fast_food_boh' => $businessMode === 'fast_food_boh',
            'is_retail' => $businessMode === 'retail',
            'detected_mode' => $detectedMode,
            'requested_mode' => $normalizedRequestedMode,
            'mode_mismatch' => $normalizedRequestedMode !== null && $normalizedRequestedMode !== $detectedMode,
        ];
    }

    private function generalSettingEnabledAny(string $companyId, ?string $branchId, array $settingKeys, bool $default = false): bool
    {
        foreach ($settingKeys as $settingKey) {
            if ($this->generalSettingEnabled($companyId, $branchId, $settingKey, false)) {
                return true;
            }
        }

        return $default;
    }

    private function resolveCashupTradingDate(
        Request $request,
        string $businessMode,
        string $companyId,
        string $branchId,
                $openedAt,
        Carbon $closedAt
    ): string {
        /*
         | Fast Food / BOH follows business trading date.
         | This matches Daily Stock Reconciliation, where trading_date is the business date
         | and closing_date is matched against that same trading date.
         */
        if ($businessMode === 'fast_food_boh') {
            $activeTradingDay = $this->resolveActiveTradingDayState($companyId, $branchId);
            $activeTradingDate = $activeTradingDay['trading_date'] ?? null;

            if (!$activeTradingDay || !$activeTradingDate) {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'No authoritative open Hospitality trading date is available for this branch.',
                    ],
                ]);
            }

            $dateState = HospitalityTradingDatePolicy::state(
                $activeTradingDate,
                $activeTradingDay['status'] ?? null
            );

            if ($dateState['trading_date_is_future'] ?? false) {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'Cashup rejected because trading date ' . $activeTradingDate
                        . ' is ahead of system date ' . $dateState['system_date'] . '.',
                    ],
                ]);
            }

            if (($activeTradingDay['status'] ?? null) !== 'open') {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'Cashup rejected because the authoritative trading date '
                        . $activeTradingDate . ' is not open.',
                    ],
                ]);
            }

            if ($request->filled('trading_date')) {
                $requestedTradingDate = $this->dateOnly($request->input('trading_date'));

                if ($requestedTradingDate !== $activeTradingDate) {
                    throw ValidationException::withMessages([
                        'trading_date' => [
                            'Cashup trading date must match the authoritative branch trading date '
                            . $activeTradingDate . '.',
                        ],
                    ]);
                }
            }

            return $activeTradingDate;
        }

        /*
         | Retail follows the shift bracket, not the clock close date.
         | If a retail shift opens on 2026-07-16 and is cashed up at 01:00
         | on 2026-07-17, the cashup belongs to the 2026-07-16 shift bracket.
         | Client trading_date input is deliberately ignored for Retail.
         */
        return Carbon::parse($openedAt ?: $closedAt)->toDateString();
    }

    /** @return array{trading_date: string, status: string, source: string}|null */
    private function resolveActiveTradingDayState(string $companyId, string $branchId): ?array
    {
        if (Schema::hasTable('trading_days')) {
            $rows = DB::table('trading_days')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereIn('status', ['open', 'day_end_running'])
                ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
                ->orderByDesc('day_end_started_at')
                ->orderByDesc('trading_date')
                ->get();

            if ($rows->count() > 1) {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'Cashup rejected because this branch has duplicate open Hospitality trading days. '
                        . 'Resolve Day End state before cashing up.',
                    ],
                ]);
            }

            $row = $rows->first();

            if ($row && !empty($row->trading_date)) {
                return [
                    'trading_date' => (string) $this->dateOnly($row->trading_date),
                    'status' => (string) $row->status,
                    'source' => 'trading_days',
                ];
            }

            // trading_days is the authoritative ledger whenever it exists.
            // Do not resurrect an obsolete date from the legacy day-end table.
            return null;
        }

        if (Schema::hasTable('trading_day_ends')) {
            $rows = DB::table('trading_day_ends')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereIn('status', ['open', 'running', 'day_end_running'])
                ->orderByDesc('trading_date')
                ->get();

            if ($rows->count() > 1) {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'Cashup rejected because this branch has duplicate open legacy trading days.',
                    ],
                ]);
            }

            $row = $rows->first();

            if ($row && !empty($row->trading_date)) {
                $status = (string) $row->status;

                return [
                    'trading_date' => (string) $this->dateOnly($row->trading_date),
                    'status' => in_array($status, ['running', 'day_end_running'], true)
                        ? 'day_end_running'
                        : $status,
                    'source' => 'trading_day_ends',
                ];
            }
        }

        return null;
    }

    private function dateOnly($value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $match)) {
            return $match[0];
        }

        return Carbon::parse($value, config('app.timezone'))->toDateString();
    }
    private function cashupRetailBracketDateSource(): string
    {
        $candidates = [
            'cashup_opened_at',
            'opened_at',
            'cashup_closed_at',
            'closed_at',
            'created_at',
        ];

        $columns = collect($candidates)
            ->filter(fn ($column) => Schema::hasColumn('pos_cashups', $column))
            ->map(fn ($column) => 'pos_cashups.' . $column)
            ->values()
            ->all();

        return count($columns) > 0
            ? 'COALESCE(' . implode(', ', $columns) . ')'
            : 'pos_cashups.created_at';
    }

    private function cashupRetailBracketDateExpression(): string
    {
        return 'DATE(' . $this->cashupRetailBracketDateSource() . ')';
    }

    private function cashupUnifiedBracketDateExpression(): string
    {
        $retailExpression = $this->cashupRetailBracketDateExpression();

        if (
            Schema::hasColumn('pos_cashups', 'business_mode')
            && Schema::hasColumn('pos_cashups', 'trading_date')
        ) {
            return "CASE WHEN pos_cashups.business_mode = 'fast_food_boh' THEN DATE(pos_cashups.trading_date) ELSE {$retailExpression} END";
        }

        return $retailExpression;
    }

    private function applyCashupBracketDateFilters($query, Request $request): void
    {
        $businessMode = $request->input('business_mode');

        if (
            $businessMode === 'fast_food_boh'
            && $request->filled('trading_date')
            && Schema::hasColumn('pos_cashups', 'trading_date')
        ) {
            $query->whereDate('pos_cashups.trading_date', $request->trading_date);
            return;
        }

        if ($businessMode === 'fast_food_boh' && Schema::hasColumn('pos_cashups', 'trading_date')) {
            if ($request->filled('date_from')) {
                $query->whereDate('pos_cashups.trading_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('pos_cashups.trading_date', '<=', $request->date_to);
            }

            return;
        }

        if ($businessMode === 'retail') {
            $retailExpression = $this->cashupRetailBracketDateExpression();

            if ($request->filled('date_from')) {
                $query->whereRaw("{$retailExpression} >= ?", [$request->date_from]);
            }

            if ($request->filled('date_to')) {
                $query->whereRaw("{$retailExpression} <= ?", [$request->date_to]);
            }

            return;
        }

        /*
         | When no mode is provided, use the correct bracket per row:
         | Fast Food / BOH -> trading_date.
         | Retail -> shift open date.
         */
        $bracketExpression = $this->cashupUnifiedBracketDateExpression();

        if ($request->filled('date_from')) {
            $query->whereRaw("{$bracketExpression} >= ?", [$request->date_from]);
        }

        if ($request->filled('date_to')) {
            $query->whereRaw("{$bracketExpression} <= ?", [$request->date_to]);
        }
    }

    /**
     * Cash drawer movements belong to the Hospitality trading day captured by
     * their cash-up, not necessarily the calendar day on which occurred_at was
     * written.  An open (not-yet-cashed-up) shift has no cash-up row, so its
     * branch's single active trading day is the authoritative bracket.
     */
    private function applyCashMovementReportModeAndDateFilters($query, Request $request): void
    {
        $businessMode = strtolower(trim((string) $request->input('business_mode', '')));
        $hasCashupBusinessMode = Schema::hasColumn('pos_cashups', 'business_mode');
        $hasCashupTradingDate = Schema::hasColumn('pos_cashups', 'trading_date');
        $openTradingDaySource = $this->hospitalityOpenTradingDaySource();

        if ($businessMode !== '' && $hasCashupBusinessMode) {
            $query->where(function ($modeQuery) use ($businessMode) {
                $modeQuery
                    ->where('pos_cashups.business_mode', $businessMode)
                    // An open shift has no cash-up snapshot yet. Branch access
                    // is already constrained to the active workspace mode.
                    ->orWhereNull('pos_cashups.id');
            });
        }

        if ($businessMode === 'fast_food_boh' && $hasCashupTradingDate) {
            $dateFilters = [];
            if ($request->filled('trading_date')) {
                $dateFilters[] = ['=', (string) $request->input('trading_date')];
            } else {
                if ($request->filled('date_from')) {
                    $dateFilters[] = ['>=', (string) $request->input('date_from')];
                }
                if ($request->filled('date_to')) {
                    $dateFilters[] = ['<=', (string) $request->input('date_to')];
                }
            }

            foreach ($dateFilters as [$operator, $date]) {
                $query->where(function ($dateQuery) use ($operator, $date, $openTradingDaySource) {
                    $dateQuery->whereDate('pos_cashups.trading_date', $operator, $date);

                    if ($openTradingDaySource !== null) {
                        $dateQuery->orWhere(function ($openMovementQuery) use ($operator, $date, $openTradingDaySource) {
                            $openMovementQuery
                                ->whereNull('pos_cashups.id')
                                ->whereExists(function ($tradingDayQuery) use ($operator, $date, $openTradingDaySource) {
                                    $alias = 'cashout_report_trading_days';
                                    $tradingDayQuery
                                        ->selectRaw('1')
                                        ->from($openTradingDaySource['table'] . ' as ' . $alias)
                                        ->whereColumn($alias . '.company_id', 'pos_cash_drawer_movements.company_id')
                                        ->whereColumn($alias . '.branch_id', 'pos_cash_drawer_movements.branch_id')
                                        ->whereIn($alias . '.status', $openTradingDaySource['statuses'])
                                        ->whereDate($alias . '.trading_date', $operator, $date);
                                });
                        });
                    }
                });
            }

            return;
        }

        // Retail follows the same shift-open bracket as its shift and cashup.
        // This keeps an overnight shift's movements on the date on which the
        // shift opened rather than splitting them at midnight.
        $retailBusinessDate = $this->cashMovementRetailBusinessDateExpression();
        if ($request->filled('date_from')) {
            $query->whereRaw("{$retailBusinessDate} >= ?", [$request->date_from]);
        }
        if ($request->filled('date_to')) {
            $query->whereRaw("{$retailBusinessDate} <= ?", [$request->date_to]);
        }
    }

    private function cashMovementReportBusinessDateExpression(Request $request): string
    {
        $businessMode = strtolower(trim((string) $request->input('business_mode', '')));
        if ($businessMode !== 'fast_food_boh' || !Schema::hasColumn('pos_cashups', 'trading_date')) {
            return $this->cashMovementRetailBusinessDateExpression();
        }

        $openTradingDateExpression = $this->hospitalityOpenTradingDateExpression(
            'cashout_report_open_days',
            'pos_cash_drawer_movements.company_id',
            'pos_cash_drawer_movements.branch_id'
        );

        return "COALESCE(
            DATE(pos_cashups.trading_date),
            CASE WHEN pos_cashups.id IS NULL THEN {$openTradingDateExpression} ELSE NULL END
        )";
    }

    private function cashMovementRetailBusinessDateExpression(): string
    {
        $sources = ['pos_user_shift_sessions.opened_at'];

        if (Schema::hasColumn('pos_cashups', 'cashup_opened_at')) {
            $sources[] = 'pos_cashups.cashup_opened_at';
        }
        if (Schema::hasColumn('pos_cashups', 'opened_at')) {
            $sources[] = 'pos_cashups.opened_at';
        }

        $sources[] = 'pos_cash_drawer_movements.occurred_at';

        return 'DATE(COALESCE(' . implode(', ', $sources) . '))';
    }

    /** @return array{table: string, statuses: array<int, string>}|null */
    private function hospitalityOpenTradingDaySource(): ?array
    {
        if (Schema::hasTable('trading_days')) {
            return [
                'table' => 'trading_days',
                'statuses' => ['open', 'day_end_running'],
            ];
        }

        if (Schema::hasTable('trading_day_ends')) {
            return [
                'table' => 'trading_day_ends',
                'statuses' => ['open', 'running', 'day_end_running'],
            ];
        }

        return null;
    }

    private function hospitalityOpenTradingDateExpression(
        string $alias,
        string $companyColumn,
        string $branchColumn
    ): string {
        $source = $this->hospitalityOpenTradingDaySource();
        if ($source === null) {
            return 'NULL';
        }

        $statuses = collect($source['statuses'])
            ->map(fn ($status) => "'" . str_replace("'", "''", $status) . "'")
            ->implode(', ');

        return "(SELECT MAX({$alias}.trading_date)
            FROM {$source['table']} {$alias}
            WHERE {$alias}.company_id = {$companyColumn}
              AND {$alias}.branch_id = {$branchColumn}
              AND {$alias}.status IN ({$statuses}))";
    }

    private function resolveCashupCurrencyCode(Request $request, array $summary): string
    {
        $requested = $request->input('cashup_currency_code') ?: $request->input('currency_code');

        if (!empty($requested)) {
            return strtoupper(trim((string) $requested));
        }

        $currencies = collect($summary['payments'] ?? [])
            ->pluck('currency_code')
            ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
            ->filter()
            ->unique()
            ->values();

        if ($currencies->count() > 1) {
            return 'MULTI';
        }

        return $currencies->first() ?: 'USD';
    }
    private function resolveBaseCurrencyCode(array $summary, string $fallbackCurrencyCode): string
    {
        $firstPayment = collect($summary['payments'] ?? [])->first();

        return strtoupper((string) ($firstPayment['base_currency_code'] ?? $fallbackCurrencyCode ?: 'USD'));
    }

    private function buildCashupPeriodBracket(
        string $businessMode,
        string $tradingDate,
        string $currencyCode,
        string $companyId,
        string $branchId,
        string $tillId,
        string $cashierId,
        string $shiftSessionId,
        string $shiftName,
               $openedAt,
        Carbon $closedAt
    ): array {
        $opened = $openedAt ? Carbon::parse($openedAt) : $closedAt->copy();
        $closed = $closedAt;
        $currencyCode = strtoupper($currencyCode ?: 'USD');

        if ($businessMode === 'fast_food_boh') {
            return [
                'key' => implode('|', ['FAST_FOOD_BOH', $companyId, $branchId, $tradingDate, $currencyCode, $shiftSessionId]),
                'label' => 'Trading Date ' . $tradingDate . ' / ' . $currencyCode . ' / ' . ($shiftName ?: 'Shift'),
            ];
        }

        return [
            'key' => implode('|', [
                'RETAIL',
                $companyId,
                $branchId,
                $tillId,
                $cashierId,
                $shiftSessionId,
                $currencyCode,
                $opened->format('YmdHi'),
                $closed->format('YmdHi'),
            ]),
            'label' => trim(($shiftName ?: 'Retail Shift') . ' / ' . $currencyCode . ' / ' . $opened->format('Y-m-d H:i') . ' - ' . $closed->format('Y-m-d H:i')),
        ];
    }

    private function isCashPaymentRow(array $row): bool
    {
        $paymentName = strtolower(trim((string) ($row['payment_name'] ?? '')));
        $moneyCode = strtolower(trim((string) ($row['money_code'] ?? '')));

        /*
         | These are drawer/float labels. They are not sale payment methods.
         */
        if (in_array($paymentName, [
            'cash drawer',
            'drawer',
            'drawer float',
            'opening float',
            'float',
            'manual drawer count',
        ], true)) {
            return false;
        }

        if (in_array($moneyCode, [
            'cash_drawer',
            'drawer',
            'drawer_float',
            'opening_float',
            'float',
            'manual_drawer_count',
        ], true)) {
            return false;
        }

        return str_contains($paymentName, 'cash')
            || str_contains($moneyCode, 'cash');
    }

    private function removeDrawerRowsFromCountedPayments(array $countedPayments): array
    {
        return collect($countedPayments)
            ->reject(function ($payment) {
                $paymentName = strtolower(trim((string) ($payment['payment_name'] ?? '')));
                $moneyCode = strtolower(trim((string) ($payment['money_code'] ?? '')));

                return in_array($paymentName, [
                        'cash drawer',
                        'drawer',
                        'drawer float',
                        'opening float',
                        'float',
                        'manual drawer count',
                    ], true)
                    || in_array($moneyCode, [
                        'cash_drawer',
                        'drawer',
                        'drawer_float',
                        'opening_float',
                        'float',
                        'manual_drawer_count',
                    ], true);
            })
            ->values()
            ->all();
    }

    private function buildTotalsByCurrency(array $varianceRows): array
    {
        return collect($varianceRows)
            ->groupBy(fn ($row) => strtoupper((string) ($row['currency_code'] ?? 'USD')))
            ->map(function ($rows, $currencyCode) {
                return [
                    'currency_code' => $currencyCode,
                    'expected_total' => round($rows->sum('expected_amount'), 4),
                    'counted_total' => round($rows->sum('counted_amount'), 4),
                    'total_variance' => round($rows->sum('variance'), 4),
                    'payment_count' => (int) $rows->sum('payment_count'),
                ];
            })
            ->values()
            ->all();
    }

    private function buildCashByCurrency(array $varianceRows, array $cashMovements): array
    {
        $cashRows = collect($varianceRows)->filter(fn ($row) =>
        $this->isCashPaymentRow($row)
        );

        $cashoutByCurrency = collect($cashMovements['cash_out_rows'] ?? [])
            ->groupBy(fn ($row) => strtoupper((string) ($row['currency_code'] ?? 'USD')))
            ->map(fn ($rows) => round($rows->sum('amount'), 4));

        $cashinByCurrency = collect($cashMovements['cash_in_rows'] ?? [])
            ->groupBy(fn ($row) => strtoupper((string) ($row['currency_code'] ?? 'USD')))
            ->map(fn ($rows) => round($rows->sum('amount'), 4));

        $currencyCodes = $cashRows
            ->pluck('currency_code')
            ->merge($cashoutByCurrency->keys())
            ->merge($cashinByCurrency->keys())
            ->map(fn ($currencyCode) => strtoupper((string) ($currencyCode ?: 'USD')))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $currencyCodes
            ->map(function ($currencyCode) use ($cashRows, $cashoutByCurrency, $cashinByCurrency) {
                $rows = $cashRows->filter(fn ($row) => strtoupper((string) ($row['currency_code'] ?? 'USD')) === $currencyCode);
                $expectedCash = round($rows->sum('expected_amount'), 4);
                $countedCash = round($rows->sum('counted_amount'), 4);
                $cashoutTotal = round((float) ($cashoutByCurrency[$currencyCode] ?? 0), 4);
                $cashinTotal = round((float) ($cashinByCurrency[$currencyCode] ?? 0), 4);
                $expectedAfterCashouts = round($expectedCash + $cashinTotal - $cashoutTotal, 4);

                return [
                    'currency_code' => $currencyCode,
                    'expected_cash' => $expectedCash,
                    'cashin_total' => $cashinTotal,
                    'cashout_total' => $cashoutTotal,
                    'expected_cash_after_cashouts' => $expectedAfterCashouts,
                    'counted_cash' => $countedCash,
                    'cash_variance' => round($countedCash - $expectedAfterCashouts, 4),
                ];
            })
            ->values()
            ->all();
    }

    private function primaryCashRow(array $cashByCurrency, string $fallbackCurrencyCode): array
    {
        $fallbackCurrencyCode = strtoupper($fallbackCurrencyCode ?: 'USD');

        $row = collect($cashByCurrency)->firstWhere('currency_code', $fallbackCurrencyCode);

        if (!$row) {
            $row = collect($cashByCurrency)->first();
        }

        return $row ?: [
            'currency_code' => $fallbackCurrencyCode,
            'expected_cash' => 0,
            'cashin_total' => 0,
            'cashout_total' => 0,
            'expected_cash_after_cashouts' => 0,
            'counted_cash' => 0,
            'cash_variance' => 0,
        ];
    }

    private function hasMultipleCurrencies(array $varianceRows): bool
    {
        return collect($varianceRows)
                ->pluck('currency_code')
                ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                ->filter()
                ->unique()
                ->count() > 1;
    }

    private function buildCashupIdentity(
        string $companyId,
        string $shiftSessionId
    ): array {
        // The table's canonical invariant is one cash-up for a company/shift.
        // Multi-currency methods and Hospitality date metadata are stored
        // inside that row.  Adding mutable currency/date fields here causes an
        // attempted second insert and conflicts with the original unique key.
        return [
            'company_id' => $companyId,
            'shift_session_id' => $shiftSessionId,
        ];
    }

    private function filterCashupPayloadByColumns(array $payload): array
    {
        if (!Schema::hasTable('pos_cashups')) {
            return $payload;
        }

        return collect($payload)
            ->filter(fn ($value, $key) => Schema::hasColumn('pos_cashups', (string) $key))
            ->all();
    }

    private function cashupBusinessModeLabel($row): string
    {
        $mode = (string) ($row->business_mode ?? $row->cashup_business_mode ?? '');

        if ($mode === 'fast_food_boh') {
            return 'Fast Food / BOH';
        }

        if ($mode === 'retail') {
            return 'Retail';
        }

        return '-';
    }

    private function generalSettingEnabled(string $companyId, ?string $branchId, string $settingKey, bool $default = false): bool
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

        if (!$setting) {
            return $default;
        }

        return filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveCashupReportPrinter(string $companyId, ?string $branchId, ?string $tillId): ?array
    {
        if (!DB::getSchemaBuilder()->hasTable('printers')) {
            return null;
        }

        $query = DB::table('printers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            });

        if (DB::getSchemaBuilder()->hasColumn('printers', 'is_active')) {
            $query->where('is_active', true);
        }

        if (DB::getSchemaBuilder()->hasColumn('printers', 'target_printing')) {
            $query->where(function ($query) {
                $query->where('target_printing', 'cashup')
                    ->orWhere('target_printing', 'receipt')
                    ->orWhere('target_printing', 'cashup_report')
                    ->orWhereNull('target_printing');
            });
        }

        if (DB::getSchemaBuilder()->hasColumn('printers', 'till_id') && $tillId) {
            $query->orderByRaw('CASE WHEN till_id = ? THEN 0 ELSE 1 END', [$tillId]);
        }

        if (DB::getSchemaBuilder()->hasColumn('printers', 'is_default')) {
            $query->orderByDesc('is_default');
        }

        $printer = $query->orderBy('created_at')->first();

        return $printer ? (array) $printer : null;
    }

    private function buildCashupCategorySalesSummary(string $companyId, string $branchId, string $shiftSessionId): array
    {
        $categoryTable = DB::getSchemaBuilder()->hasTable('product_categories')
            ? 'product_categories'
            : (DB::getSchemaBuilder()->hasTable('categories') ? 'categories' : null);

        $query = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.branch_id', $branchId)
            ->where('sales_transactions.shift_session_id', $shiftSessionId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale');

        if ($categoryTable) {
            $query->leftJoin("{$categoryTable} as product_categories_for_cashup", 'product_categories_for_cashup.id', '=', 'products.category_id')
                ->selectRaw('
                    COALESCE(product_categories_for_cashup.name, "Uncategorised") as category_name,
                    COUNT(DISTINCT sales_transactions.id) as receipt_count,
                    SUM(receipt_lines.line_quantity) as quantity,
                    SUM(receipt_lines.line_subtotal) as subtotal,
                    SUM(receipt_lines.line_tax) as tax,
                    SUM(receipt_lines.line_total) as total
                ')
                ->groupBy(DB::raw('COALESCE(product_categories_for_cashup.name, "Uncategorised")'));
        } else {
            $query->selectRaw('
                    "Uncategorised" as category_name,
                    COUNT(DISTINCT sales_transactions.id) as receipt_count,
                    SUM(receipt_lines.line_quantity) as quantity,
                    SUM(receipt_lines.line_subtotal) as subtotal,
                    SUM(receipt_lines.line_tax) as tax,
                    SUM(receipt_lines.line_total) as total
                ');
        }

        return $query->orderBy('category_name')
            ->get()
            ->map(fn ($row) => [
                'category_name' => $row->category_name,
                'receipt_count' => (int) $row->receipt_count,
                'quantity' => round((float) $row->quantity, 4),
                'subtotal' => round((float) $row->subtotal, 4),
                'tax' => round((float) $row->tax, 4),
                'total' => round((float) $row->total, 4),
            ])
            ->values()
            ->all();
    }

    private function buildCashupProductSalesSummary(string $companyId, string $branchId, string $shiftSessionId): array
    {
        return DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.branch_id', $branchId)
            ->where('sales_transactions.shift_session_id', $shiftSessionId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->selectRaw('
                receipt_lines.product_id,
                COALESCE(products.name, receipt_lines.line_name, "Unknown Product") as product_name,
                COALESCE(products.sku, receipt_lines.sku) as sku,
                COALESCE(products.code, receipt_lines.product_code) as code,
                SUM(receipt_lines.line_quantity) as quantity,
                SUM(receipt_lines.line_subtotal) as subtotal,
                SUM(receipt_lines.line_tax) as tax,
                SUM(receipt_lines.line_total) as total
            ')
            ->groupBy(
                'receipt_lines.product_id',
                DB::raw('COALESCE(products.name, receipt_lines.line_name, "Unknown Product")'),
                DB::raw('COALESCE(products.sku, receipt_lines.sku)'),
                DB::raw('COALESCE(products.code, receipt_lines.product_code)')
            )
            ->orderByDesc(DB::raw('SUM(receipt_lines.line_total)'))
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'code' => $row->code,
                'quantity' => round((float) $row->quantity, 4),
                'subtotal' => round((float) $row->subtotal, 4),
                'tax' => round((float) $row->tax, 4),
                'total' => round((float) $row->total, 4),
            ])
            ->values()
            ->all();
    }



    private function buildCashupReportDocketHtml(array $payload): string
    {
        $e = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
        $money = fn ($value) => number_format((float) ($value ?? 0), 2);
        $qty = fn ($value) => number_format((float) ($value ?? 0), 4);

        $totals = $payload['totals'] ?? [];

        $denominationRows = collect($payload['denominations'] ?? [])
            ->filter(function ($row) {
                return (int) ($row['quantity'] ?? 0) > 0
                    && (float) ($row['line_total'] ?? 0) > 0;
            })
            ->map(function ($row) use ($e, $money) {
                return '<tr>
            <td>' . $e($row['currency_code'] ?? '') . ' ' . $money($row['denomination_value'] ?? 0) . '</td>
            <td class="r">' . $e($row['quantity'] ?? 0) . '</td>
            <td class="r">' . $money($row['line_total'] ?? 0) . '</td>
        </tr>';
            })
            ->implode('');

        $paymentRows = collect($payload['payments'] ?? [])
            ->map(function ($row) use ($e, $money) {
                return '<tr>
                <td>' . $e($row['payment_name'] ?? '') . ' ' . $e($row['currency_code'] ?? '') . '</td>
                <td class="r">' . $money($row['expected_amount'] ?? 0) . '</td>
                <td class="r">' . $money($row['counted_amount'] ?? 0) . '</td>
                <td class="r">' . $money($row['variance'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');

        $currencyRows = collect($payload['currencies'] ?? [])
            ->map(function ($row) use ($e, $money) {
                return '<tr>
                <td>' . $e($row['currency_code'] ?? '') . '</td>
                <td class="r">' . $money($row['expected_amount'] ?? 0) . '</td>
                <td class="r">' . $money($row['counted_amount'] ?? 0) . '</td>
                <td class="r">' . $money($row['variance'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');
        $cashByCurrencyRows = collect($payload['cash_by_currency'] ?? [])
            ->map(function ($row) use ($e, $money) {
                return '<tr>
                <td>' . $e($row['currency_code'] ?? '') . '</td>
                <td class="r">' . $money($row['expected_cash'] ?? 0) . '</td>
                <td class="r">' . $money($row['cashout_total'] ?? 0) . '</td>
                <td class="r">' . $money($row['expected_cash_after_cashouts'] ?? 0) . '</td>
                <td class="r">' . $money($row['counted_cash'] ?? 0) . '</td>
                <td class="r">' . $money($row['cash_variance'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');


        $cashoutRows = collect(data_get($payload, 'cash_movements.cash_out_rows', []))
            ->map(function ($row) use ($e, $money) {
                return '<tr>
                <td>
                    ' . $e($row['movement_type'] ?? '') . '<br>
                    <span>' . $e($row['reason'] ?? '') . '</span>
                </td>
                <td class="r">' . $e($row['currency_code'] ?? '') . '</td>
                <td class="r">' . $money($row['amount'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');

        $cashinRows = collect(data_get($payload, 'cash_movements.cash_in_rows', []))
            ->map(function ($row) use ($e, $money) {
                return '<tr>
                <td>
                    ' . $e($row['movement_type'] ?? '') . '<br>
                    <span>' . $e($row['reason'] ?? '') . '</span>
                </td>
                <td class="r">' . $e($row['currency_code'] ?? '') . '</td>
                <td class="r">' . $money($row['amount'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');

        $categoryRows = collect($payload['category_sales'] ?? [])
            ->map(function ($row) use ($e, $money, $qty) {
                return '<tr>
                <td>' . $e($row['category_name'] ?? '') . '</td>
                <td class="r">' . $qty($row['quantity'] ?? 0) . '</td>
                <td class="r">' . $money($row['total'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');

        $productRows = collect($payload['product_sales'] ?? [])
            ->map(function ($row) use ($e, $money, $qty) {
                return '<tr>
                <td>
                    ' . $e($row['product_name'] ?? '') . '<br>
                    <span>' . $e(($row['sku'] ?? '') ?: ($row['code'] ?? '')) . '</span>
                </td>
                <td class="r">' . $qty($row['quantity'] ?? 0) . '</td>
                <td class="r">' . $money($row['total'] ?? 0) . '</td>
            </tr>';
            })
            ->implode('');

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Shift End Cashup Report</title>

<style>
@page {
    margin: 4mm;
    size: 80mm auto;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #ffffff;
    color: #000000;
}

body {
    width: 72mm;
    margin: 0 auto;
    font-family: "DejaVu Sans", Arial, sans-serif;
    font-size: 11px;
    line-height: 1.35;
}

h1 {
    font-size: 14px;
    text-align: center;
    margin: 0 0 4px 0;
    padding: 0;
    font-weight: 900;
    text-transform: uppercase;
}

h2 {
    font-size: 11px;
    border-top: 1px dashed #000000;
    border-bottom: 1px dashed #000000;
    padding: 3px 0;
    margin: 7px 0 4px 0;
    text-align: center;
    font-weight: 900;
    text-transform: uppercase;
}

.center {
    text-align: center;
}

.r {
    text-align: right;
}

.line {
    display: table;
    width: 100%;
    margin: 2px 0;
}

.line > div {
    display: table-cell;
    vertical-align: top;
}

.line > div:last-child {
    text-align: right;
}

.label {
    font-weight: 900;
}

.total {
    font-weight: 900;
    font-size: 12px;
    border-top: 1px solid #000000;
    border-bottom: 1px solid #000000;
    padding: 3px 0;
    margin-top: 3px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 3px;
    table-layout: fixed;
}

th,
td {
    padding: 2px 0;
    vertical-align: top;
    border-bottom: 1px dotted #bbbbbb;
    word-wrap: break-word;
    overflow-wrap: anywhere;
}

th {
    font-size: 10px;
    text-align: left;
    font-weight: 900;
}

td {
    font-size: 10.5px;
}

span {
    font-size: 9px;
    color: #333333;
}

.footer {
    text-align: center;
    margin-top: 8px;
    border-top: 1px dashed #000000;
    padding-top: 5px;
    font-weight: 900;
}

.small {
    font-size: 9px;
}

.no-border td {
    border-bottom: none;
}
</style>
</head>

<body>

<h1>' . $e($payload['report_title'] ?? 'SHIFT END CASHUP REPORT') . '</h1>

<div class="center">' . $e(data_get($payload, 'company.name', '')) . '</div>
<div class="center small">Cashup #: ' . $e($payload['cashup_id'] ?? '') . '</div>
<div class="center small">Generated: ' . $e($payload['generated_at'] ?? '') . '</div>

<h2>Shift Details</h2>

<div class="line">
    <div class="label">Cashier</div>
    <div>' . $e(data_get($payload, 'cashier.name', '')) . '</div>
</div>

<div class="line">
    <div class="label">Closed By</div>
    <div>' . $e(data_get($payload, 'closed_by.name', '')) . '</div>
</div>

' . (!empty($payload['supervisor_reason']) ? '<div class="line"><div class="label">Reason</div><div>' . $e($payload['supervisor_reason']) . '</div></div>' : '') . '

<div class="line">
    <div class="label">Branch</div>
    <div>' . $e(data_get($payload, 'branch.name', '')) . '</div>
</div>

<div class="line">
    <div class="label">Till</div>
    <div>' . $e(trim((string) data_get($payload, 'till.code', '') . ' ' . (string) data_get($payload, 'till.name', ''))) . '</div>
</div>

<div class="line">
    <div class="label">Opened</div>
    <div>' . $e(data_get($payload, 'shift.opened_at', '')) . '</div>
</div>

<div class="line">
    <div class="label">Closed</div>
    <div>' . $e(data_get($payload, 'shift.closed_at', '')) . '</div>
</div>

<h2>Activity Summary</h2>

<div class="line">
    <div>Transactions</div>
    <div>' . $e($totals['transaction_count'] ?? 0) . '</div>
</div>

<div class="line">
    <div>Voids</div>
    <div>' . $e($totals['void_count'] ?? 0) . '</div>
</div>

<div class="line">
    <div>Refunds</div>
    <div>' . $e($totals['refund_count'] ?? 0) . '</div>
</div>

<h2>Denominations</h2>

<table>
<thead>
<tr>
    <th>Denom</th>
    <th class="r">Qty</th>
    <th class="r">Total</th>
</tr>
</thead>
<tbody>
' . ($denominationRows ?: '<tr><td colspan="3" class="center">No denominations counted</td></tr>') . '
</tbody>
</table>

<h2>Payments</h2>

<table>
<thead>
<tr>
    <th>Payment</th>
    <th class="r">Exp</th>
    <th class="r">Cnt</th>
    <th class="r">Var</th>
</tr>
</thead>
<tbody>
' . ($paymentRows ?: '<tr><td colspan="4" class="center">No payments</td></tr>') . '
</tbody>
</table>

<h2>Currency Summary</h2>

<table>
<thead>
<tr>
    <th>Curr</th>
    <th class="r">Exp</th>
    <th class="r">Cnt</th>
    <th class="r">Var</th>
</tr>
</thead>
<tbody>
' . ($currencyRows ?: '<tr><td colspan="4" class="center">No currency summary</td></tr>') . '
</tbody>
</table>

<h2>Cash By Currency</h2>

<table>
<thead>
<tr>
    <th>Curr</th>
    <th class="r">Exp</th>
    <th class="r">Out</th>
    <th class="r">After</th>
    <th class="r">Cnt</th>
    <th class="r">Var</th>
</tr>
</thead>
<tbody>
' . ($cashByCurrencyRows ?: '<tr><td colspan="6" class="center">No cash summary</td></tr>') . '
</tbody>
</table>

<h2>Cash Outs</h2>

<table>
<thead>
<tr>
    <th>Type</th>
    <th class="r">Curr</th>
    <th class="r">Amount</th>
</tr>
</thead>
<tbody>
' . ($cashoutRows ?: '<tr><td colspan="3" class="center">No cash outs</td></tr>') . '
</tbody>
</table>

<h2>Cash Ins</h2>

<table>
<thead>
<tr>
    <th>Type</th>
    <th class="r">Curr</th>
    <th class="r">Amount</th>
</tr>
</thead>
<tbody>
' . ($cashinRows ?: '<tr><td colspan="3" class="center">No cash ins</td></tr>') . '
</tbody>
</table>

<h2>Category Sales</h2>

<table>
<thead>
<tr>
    <th>Category</th>
    <th class="r">Qty</th>
    <th class="r">Total</th>
</tr>
</thead>
<tbody>
' . ($categoryRows ?: '<tr><td colspan="3" class="center">No sales</td></tr>') . '
</tbody>
</table>' .

            ((bool) ($payload['include_product_sales'] ?? false)
                ? '<h2>Product Sales</h2>
<table>
<thead>
<tr>
    <th>Product</th>
    <th class="r">Qty</th>
    <th class="r">Total</th>
</tr>
</thead>
<tbody>
' . ($productRows ?: '<tr><td colspan="3" class="center">No product sales</td></tr>') . '
</tbody>
</table>'
                : '') .

            '<div class="footer">
    <div style="height:18px"></div>
    <div style="display:flex;justify-content:space-between;gap:8px">
        <span>Cashier: __________________</span>
        <span>Supervisor: ______________</span>
    </div>
    <div class="small" style="margin-top:8px">Expected, counted and variance values above remain separated by currency.</div>
    End of Cashup Report
</div>

</body>
</html>';
    }
    private function maskExpectedCashupSummary(array $summary): array
    {
        return $this->cashupFinancialVisibility->redactActiveSummary($summary);
    }

    private function canViewCashupFinancialAmounts($user, string $companyId): bool
    {
        return $this->cashupPermission(
            $user,
            CashupFinancialVisibilityService::PERMISSION,
            $companyId
        ) === true;
    }

    /**
     * Resolve operational cashup permissions through one gate. A genuine
     * Super Admin is identified server-side and bypasses company role pivots;
     * every ordinary user still goes through the persisted permission record.
     */
    private function cashupPermission($user, string $permission, string $companyId): mixed
    {
        if ($this->superAdmins->isSuperAdmin($user)) {
            return true;
        }

        return hasPermission($user->role_id, $permission, $companyId);
    }

    private function getActiveShift(string $companyId, string $branchId, string $tillId, string $cashierId): ?PosUserShiftSession
    {
        return PosUserShiftSession::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('pos_user_id', $cashierId)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();
    }

    private function canCashupOnBehalf($user, string $companyId, array $policy): bool
    {
        // Use the same authoritative identity check as licensing and company
        // management. This guarantees the system owner can cash up an eligible
        // cashier even when the company has disabled ordinary supervisor cashup.
        if ($this->superAdmins->isSuperAdmin($user)) {
            return true;
        }

        if (!($policy['allow_supervisor_cashup'] ?? true)) {
            return false;
        }

        return $this->cashupPermission($user, 'cashup_on_behalf', $companyId) === true;
    }

    private function resolveCashupShift(
        string $companyId,
        string $branchId,
        string $tillId,
        $actor,
        ?string $requestedShiftId,
        array $policy
    ): ?PosUserShiftSession {
        $query = PosUserShiftSession::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('status', 'open')
            ->whereNull('closed_at');

        if ($requestedShiftId) {
            $shift = (clone $query)->where('id', $requestedShiftId)->first();
            if (!$shift) {
                abort(404, 'The selected open shift was not found for this branch.');
            }

            if ((string) $shift->pos_user_id !== (string) $actor->id
                && !$this->canCashupOnBehalf($actor, $companyId, $policy)) {
                abort(403, 'You do not have permission to access another cashier shift.');
            }

            return $shift;
        }

        $ownShift = (clone $query)
            ->where('pos_user_id', $actor->id)
            ->latest('opened_at')
            ->first();

        if ($ownShift) {
            return $ownShift;
        }

        if ($this->canCashupOnBehalf($actor, $companyId, $policy)) {
            return (clone $query)->oldest('opened_at')->first();
        }

        return null;
    }

    private function availableCashupShifts(string $companyId, string $branchId, string $tillId, $actor, bool $canCashupOnBehalf): array
    {
        return PosUserShiftSession::query()
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->select([
                'pos_user_shift_sessions.id',
                'pos_user_shift_sessions.pos_user_id',
                'pos_user_shift_sessions.shift_name',
                'pos_user_shift_sessions.opened_at',
                'pos_user_shift_sessions.opening_float',
                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
            ])
            ->where('pos_user_shift_sessions.company_id', $companyId)
            ->where('pos_user_shift_sessions.branch_id', $branchId)
            ->where('pos_user_shift_sessions.till_id', $tillId)
            ->where('pos_user_shift_sessions.status', 'open')
            ->whereNull('pos_user_shift_sessions.closed_at')
            ->when(!$canCashupOnBehalf, fn ($query) => $query->where('pos_user_shift_sessions.pos_user_id', $actor->id))
            ->orderBy('pos_user_shift_sessions.opened_at')
            ->get()
            ->map(fn ($shift) => [
                'id' => (string) $shift->id,
                'cashier_id' => (string) $shift->pos_user_id,
                'cashier_name' => $shift->cashier_name,
                'shift_name' => $shift->shift_name,
                'opened_at' => $shift->opened_at,
                'opening_float' => round((float) ($shift->opening_float ?? 0), 4),
                'is_own_shift' => (string) $shift->pos_user_id === (string) $actor->id,
            ])
            ->values()
            ->all();
    }

    private function buildShiftSummary(string $companyId, string $branchId, string $tillId, string $shiftSessionId, string $cashierId): array
    {
        $salesQuery = SalesTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('cashier_id', $cashierId)
            ->where('till_id', $tillId)
            ->where('shift_session_id', $shiftSessionId);

        $completedSales = (clone $salesQuery)->where('status', 'completed')->get();

        $payments = DB::table('sales_transaction_payments')
            ->selectRaw('payment_name, money_code, currency_code, base_currency_code, COUNT(*) as payment_count, SUM(amount) as amount, SUM(base_amount) as base_amount, SUM(amount_tendered) as amount_tendered, SUM(change_amount) as change_amount')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('shift_session_id', $shiftSessionId)
            ->groupBy('payment_name', 'money_code', 'currency_code', 'base_currency_code')
            ->orderBy('payment_name')
            ->orderBy('currency_code')
            ->get();

        $allPaymentsArray = $payments->map(fn ($payment) => [
            'payment_name' => $payment->payment_name,
            'money_code' => $payment->money_code,
            'currency_code' => $payment->currency_code,
            'base_currency_code' => $payment->base_currency_code,
            'payment_count' => (int) $payment->payment_count,
            'amount' => round((float) $payment->amount, 4),
            'base_amount' => round((float) $payment->base_amount, 4),
            'amount_tendered' => round((float) $payment->amount_tendered, 4),
            'change_amount' => round((float) $payment->change_amount, 4),
        ])->values();

        $accountMoneyCodes = ['customer_credit', 'credit_customer', 'credit_account', 'account'];
        $creditSaleTenderRows = $allPaymentsArray->filter(fn ($payment) =>
            in_array(strtolower((string) ($payment['money_code'] ?? '')), $accountMoneyCodes, true)
        )->values();

        // A credit sale records debt, not money in the drawer. Real later collections are
        // separately inserted as "Credit Payment - <tender>" and remain reconcilable.
        $paymentsArray = $allPaymentsArray
            ->reject(fn ($payment) => in_array(strtolower((string) ($payment['money_code'] ?? '')), $accountMoneyCodes, true))
            ->values()
            ->all();

        $currencySummary = collect($paymentsArray)
            ->groupBy('currency_code')
            ->map(fn ($rows, $currencyCode) => [
                'currency_code' => $currencyCode,
                'payment_count' => $rows->sum('payment_count'),
                'amount' => round($rows->sum('amount'), 4),
                'base_amount' => round($rows->sum('base_amount'), 4),
                'amount_tendered' => round($rows->sum('amount_tendered'), 4),
                'change_amount' => round($rows->sum('change_amount'), 4),
            ])
            ->values()
            ->all();

        $creditPayments = collect($paymentsArray)->filter(function ($payment) {
            $paymentName = strtolower(trim((string) ($payment['payment_name'] ?? '')));

            return str_starts_with($paymentName, 'credit payment -');
        });

        $customerChangeCredits = collect($paymentsArray)->filter(fn ($payment) =>
            str_starts_with(strtolower(trim((string) ($payment['payment_name'] ?? ''))), 'customer change credit -')
        );
        $creditPaymentRefunds = collect($paymentsArray)->filter(fn ($payment) =>
            str_starts_with(strtolower(trim((string) ($payment['payment_name'] ?? ''))), 'credit payment refund -')
        );
        $customerChangeCreditRefunds = collect($paymentsArray)->filter(fn ($payment) =>
            str_starts_with(strtolower(trim((string) ($payment['payment_name'] ?? ''))), 'customer change credit refund -')
        );

        $creditSales = SalesTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('cashier_id', $cashierId)
            ->where('till_id', $tillId)
            ->where('shift_session_id', $shiftSessionId)
            ->where('status', 'completed')
            ->where('is_credit_sale', true)
            ->get();

        $cashPayments = collect($paymentsArray)->filter(fn ($payment) =>
        $this->isCashPaymentRow($payment)
        );

        $cashMovements = $this->buildCashMovementSummary($companyId, $branchId, $tillId, $shiftSessionId);

        $salesByCurrency = $completedSales
            ->groupBy(fn ($sale) => strtoupper((string) ($sale->currency_code ?: 'USD')))
            ->map(function ($rows, $currencyCode) {
                return [
                    'currency_code' => $currencyCode,
                    'base_currency_code' => strtoupper((string) ($rows->pluck('base_currency_code')->filter()->first() ?: $currencyCode)),
                    'transaction_count' => $rows->count(),
                    'subtotal' => round((float) $rows->sum('subtotal_amount'), 4),
                    'discount' => round((float) $rows->sum('discount_amount'), 4),
                    'tax' => round((float) $rows->sum('tax_amount'), 4),
                    'expected_total' => round((float) $rows->sum('total_amount'), 4),
                    'base_subtotal' => round((float) $rows->sum('base_subtotal_amount'), 4),
                    'base_discount' => round((float) $rows->sum('base_discount_amount'), 4),
                    'base_tax' => round((float) $rows->sum('base_tax_amount'), 4),
                    'base_total' => round((float) $rows->sum('base_total_amount'), 4),
                ];
            })
            ->sortKeys()
            ->values();

        $salesCurrencyCodes = $salesByCurrency->pluck('currency_code')->filter()->unique()->values();
        $salesAreMultiCurrency = $salesCurrencyCodes->count() > 1;
        $salesCurrencyCode = $salesAreMultiCurrency ? 'MULTI' : ($salesCurrencyCodes->first() ?: null);
        $baseCurrencyCode = strtoupper((string) (
            $salesByCurrency->pluck('base_currency_code')->filter()->first()
            ?: collect($paymentsArray)->pluck('base_currency_code')->filter()->first()
            ?: $salesCurrencyCode
            ?: 'USD'
        ));

        $cashPaymentRows = collect($cashPayments);
        $cashOutRows = collect($cashMovements['cash_out_rows'] ?? []);
        $cashInRows = collect($cashMovements['cash_in_rows'] ?? []);
        $cashCurrencyCodes = $cashPaymentRows->pluck('currency_code')
            ->merge($cashOutRows->pluck('currency_code'))
            ->merge($cashInRows->pluck('currency_code'))
            ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $cashByCurrency = $cashCurrencyCodes->map(function ($currencyCode) use ($cashPaymentRows, $cashOutRows, $cashInRows) {
            $paymentsForCurrency = $cashPaymentRows->filter(fn ($row) => strtoupper((string) ($row['currency_code'] ?? '')) === $currencyCode);
            $cashOutForCurrency = $cashOutRows->filter(fn ($row) => strtoupper((string) ($row['currency_code'] ?? '')) === $currencyCode);
            $cashInForCurrency = $cashInRows->filter(fn ($row) => strtoupper((string) ($row['currency_code'] ?? '')) === $currencyCode);
            $expectedCash = round((float) $paymentsForCurrency->sum('amount'), 4);
            $cashOut = round((float) $cashOutForCurrency->sum('amount'), 4);
            $cashIn = round((float) $cashInForCurrency->sum('amount'), 4);

            return [
                'currency_code' => $currencyCode,
                'expected_cash' => $expectedCash,
                'cashout_total' => $cashOut,
                'cashout_count' => $cashOutForCurrency->count(),
                'cashin_total' => $cashIn,
                'cashin_count' => $cashInForCurrency->count(),
                'expected_cash_after_cashouts' => round($expectedCash + $cashIn - $cashOut, 4),
                'base_expected_cash' => round((float) $paymentsForCurrency->sum('base_amount'), 4),
            ];
        })->values();
        $cashIsMultiCurrency = $cashByCurrency->count() > 1;
        $singleCashSummary = $cashByCurrency->first() ?? [
            'expected_cash' => 0,
            'cashout_total' => 0,
            'cashout_count' => 0,
            'cashin_total' => 0,
            'cashin_count' => 0,
            'expected_cash_after_cashouts' => 0,
            'base_expected_cash' => 0,
        ];

        $creditByCurrency = $creditSales
            ->groupBy(fn ($sale) => strtoupper((string) ($sale->currency_code ?: 'USD')))
            ->map(function ($rows, $currencyCode) {
                return [
                    'currency_code' => $currencyCode,
                    'credit_sale_count' => $rows->count(),
                    'credit_sales_total' => round((float) $rows->sum('credit_amount'), 4),
                    'credit_paid_total' => round((float) $rows->sum('credit_paid_amount'), 4),
                    'credit_balance_total' => round((float) $rows->sum('credit_balance_amount'), 4),
                ];
            })
            ->sortKeys()
            ->values();
        $creditIsMultiCurrency = $creditByCurrency->count() > 1;
        $shift = PosUserShiftSession::find($shiftSessionId);
        $cashier = PosUser::find($cashierId);
        $sourceTillId = $tillId;

        return [
            'shift' => [
                'shift_session_id' => $shiftSessionId,
                'cashier_id' => $cashierId,
                'cashier_name' => $cashier?->name ?? $cashier?->username ?? 'Unknown',
                'shift_name' => $shift?->shift_name,
                'opened_at' => $shift?->opened_at,
                'branch_id' => $branchId,
                'till_id' => $sourceTillId,
            ],
            'totals' => [
                'transaction_count' => $completedSales->count(),
                'void_count' => (clone $salesQuery)->where('status', 'voided')->count(),
                'refund_count' => (clone $salesQuery)->where('sale_type', 'refund')->count(),
                'currency_code' => $salesCurrencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'is_multi_currency' => $salesAreMultiCurrency,
                'subtotal' => $salesAreMultiCurrency ? null : round((float) $completedSales->sum('subtotal_amount'), 4),
                'discount' => $salesAreMultiCurrency ? null : round((float) $completedSales->sum('discount_amount'), 4),
                'tax' => $salesAreMultiCurrency ? null : round((float) $completedSales->sum('tax_amount'), 4),
                'expected_total' => $salesAreMultiCurrency ? null : round((float) $completedSales->sum('total_amount'), 4),
                'base_subtotal' => round((float) $completedSales->sum('base_subtotal_amount'), 4),
                'base_discount' => round((float) $completedSales->sum('base_discount_amount'), 4),
                'base_tax' => round((float) $completedSales->sum('base_tax_amount'), 4),
                'base_total' => round((float) $completedSales->sum('base_total_amount'), 4),
                'by_currency' => $salesByCurrency->all(),
            ],
            'cash' => [
                'currency_code' => $cashIsMultiCurrency ? 'MULTI' : data_get($cashByCurrency->first(), 'currency_code'),
                'is_multi_currency' => $cashIsMultiCurrency,
                'expected_cash' => $cashIsMultiCurrency ? null : $singleCashSummary['expected_cash'],
                'cashout_total' => $cashIsMultiCurrency ? null : $singleCashSummary['cashout_total'],
                'cashout_count' => (int) $cashOutRows->count(),
                'cashin_total' => $cashIsMultiCurrency ? null : $singleCashSummary['cashin_total'],
                'cashin_count' => (int) $cashInRows->count(),
                'expected_cash_after_cashouts' => $cashIsMultiCurrency ? null : $singleCashSummary['expected_cash_after_cashouts'],
                'base_expected_cash' => round((float) $cashPaymentRows->sum('base_amount'), 4),
                'by_currency' => $cashByCurrency->all(),
            ],
            'credit' => [
                'credit_sale_count' => $creditSales->count(),
                'is_multi_currency' => $creditIsMultiCurrency,
                'credit_sales_total' => $creditIsMultiCurrency ? null : round((float) $creditSales->sum('credit_amount'), 4),
                'credit_paid_total' => $creditIsMultiCurrency ? null : round((float) $creditSales->sum('credit_paid_amount'), 4),
                'credit_balance_total' => $creditIsMultiCurrency ? null : round((float) $creditSales->sum('credit_balance_amount'), 4),
                'sales_by_currency' => $creditByCurrency->all(),
                'collections_count' => (int) $creditPayments->sum('payment_count'),
                'collections_by_tender' => $creditPayments->values()->all(),
                'collections_by_currency' => $creditPayments->groupBy('currency_code')->map(fn ($rows, $currencyCode) => [
                    'currency_code' => $currencyCode,
                    'payment_count' => (int) $rows->sum('payment_count'),
                    'amount' => round((float) $rows->sum('amount'), 4),
                    'base_amount' => round((float) $rows->sum('base_amount'), 4),
                ])->values()->all(),
                'change_credit_count' => (int) $customerChangeCredits->sum('payment_count'),
                'change_credits_by_tender' => $customerChangeCredits->values()->all(),
                'change_credits_by_currency' => $customerChangeCredits->groupBy('currency_code')->map(fn ($rows, $currencyCode) => [
                    'currency_code' => $currencyCode,
                    'payment_count' => (int) $rows->sum('payment_count'),
                    'amount' => round((float) $rows->sum('amount'), 4),
                    'base_amount' => round((float) $rows->sum('base_amount'), 4),
                ])->values()->all(),
                'collection_refund_count' => (int) $creditPaymentRefunds->sum('payment_count'),
                'collection_refunds_by_tender' => $creditPaymentRefunds->values()->all(),
                'collection_refunds_by_currency' => $creditPaymentRefunds->groupBy('currency_code')->map(fn ($rows, $currencyCode) => [
                    'currency_code' => $currencyCode,
                    'payment_count' => (int) $rows->sum('payment_count'),
                    'amount' => round((float) $rows->sum('amount'), 4),
                    'base_amount' => round((float) $rows->sum('base_amount'), 4),
                ])->values()->all(),
                'change_credit_refund_count' => (int) $customerChangeCreditRefunds->sum('payment_count'),
                'change_credit_refunds_by_tender' => $customerChangeCreditRefunds->values()->all(),
                'change_credit_refunds_by_currency' => $customerChangeCreditRefunds->groupBy('currency_code')->map(fn ($rows, $currencyCode) => [
                    'currency_code' => $currencyCode,
                    'payment_count' => (int) $rows->sum('payment_count'),
                    'amount' => round((float) $rows->sum('amount'), 4),
                    'base_amount' => round((float) $rows->sum('base_amount'), 4),
                ])->values()->all(),
                'account_refund_count' => (int) ($creditPaymentRefunds->sum('payment_count') + $customerChangeCreditRefunds->sum('payment_count')),
                'account_activity_count' => (int) (
                    $creditPayments->sum('payment_count')
                    + $customerChangeCredits->sum('payment_count')
                    + $creditPaymentRefunds->sum('payment_count')
                    + $customerChangeCreditRefunds->sum('payment_count')
                ),
                'credit_sale_tender_rows' => $creditSaleTenderRows->all(),
            ],
            'payments' => $paymentsArray,
            'currencies' => $currencySummary,
            'cash_movements' => $cashMovements,
        ];
    }

    private function buildCashMovementSummary(string $companyId, string $branchId, ?string $tillId, string $shiftSessionId): array
    {
        $rows = DB::table('pos_cash_drawer_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('shift_session_id', $shiftSessionId)
            ->whereNull('deleted_at')
            ->orderBy('occurred_at')
            ->get();

        $cashOutRows = $rows->filter(fn ($row) => in_array($row->movement_type, ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'], true))
            ->map(fn ($row) => [
                'id' => $row->id,
                'movement_type' => $row->movement_type,
                'amount' => round((float) $row->amount, 4),
                'currency_code' => $row->currency_code,
                'reason' => $row->reason,
                'notes' => $row->notes,
                'reference_no' => $row->reference_no,
                'occurred_at' => $row->occurred_at,
            ])
            ->values()
            ->all();

        $cashInRows = $rows->filter(fn ($row) => in_array($row->movement_type, ['cash_in', 'float_in'], true))
            ->map(fn ($row) => [
                'id' => $row->id,
                'movement_type' => $row->movement_type,
                'amount' => round((float) $row->amount, 4),
                'currency_code' => $row->currency_code,
                'reason' => $row->reason,
                'notes' => $row->notes,
                'reference_no' => $row->reference_no,
                'occurred_at' => $row->occurred_at,
            ])
            ->values()
            ->all();

        $movementRows = collect($cashOutRows)->concat($cashInRows);
        $byCurrency = $movementRows
            ->groupBy(fn ($row) => strtoupper((string) ($row['currency_code'] ?? 'USD')))
            ->map(function ($currencyRows, $currencyCode) {
                $cashOut = $currencyRows->filter(fn ($row) => in_array($row['movement_type'], ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'], true));
                $cashIn = $currencyRows->filter(fn ($row) => in_array($row['movement_type'], ['cash_in', 'float_in'], true));

                return [
                    'currency_code' => $currencyCode,
                    'cashout_count' => $cashOut->count(),
                    'cashout_total' => round((float) $cashOut->sum('amount'), 4),
                    'cashin_count' => $cashIn->count(),
                    'cashin_total' => round((float) $cashIn->sum('amount'), 4),
                    'net_cash_movement' => round((float) $cashIn->sum('amount') - (float) $cashOut->sum('amount'), 4),
                ];
            })
            ->sortKeys()
            ->values();
        $isMultiCurrency = $byCurrency->count() > 1;
        $singleCurrency = $byCurrency->first() ?? [
            'cashout_total' => 0,
            'cashin_total' => 0,
            'net_cash_movement' => 0,
        ];

        return [
            'cash_out_rows' => $cashOutRows,
            'cash_in_rows' => $cashInRows,
            'cashout_count' => count($cashOutRows),
            'cashin_count' => count($cashInRows),
            'is_multi_currency' => $isMultiCurrency,
            'cashout_total' => $isMultiCurrency ? null : $singleCurrency['cashout_total'],
            'cashin_total' => $isMultiCurrency ? null : $singleCurrency['cashin_total'],
            'net_cash_movement' => $isMultiCurrency ? null : $singleCurrency['net_cash_movement'],
            'by_currency' => $byCurrency->all(),
        ];
    }

    private function normalizeCountedPayments(Request $request, array $summary): array
    {
        $countedPayments = collect($request->counted_payments ?? [])
            ->map(fn ($row) => [
                'payment_name' => trim((string) $row['payment_name']),
                'money_code' => $row['money_code'] ?? null,
                'currency_code' => strtoupper(trim((string) $row['currency_code'])),
                'amount' => round((float) $row['amount'], 4),
            ])
            ->filter(fn ($row) => $row['payment_name'] !== '' && $row['currency_code'] !== '')
            ->values();

        $hasExplicitCashCount = $countedPayments->contains(
            fn ($payment) => $this->isCashPaymentRow($payment)
        );

        if ($request->filled('counted_cash') && !$hasExplicitCashCount) {
            // counted_cash is a legacy drawer-count field.  Merge it when a
            // mixed counted_payments payload omitted cash, but never guess
            // between two native cash currencies.
            $cashTenderRows = collect($summary['payments'] ?? [])
                ->filter(fn ($payment) => $this->isCashPaymentRow($payment))
                ->values();
            $cashCurrencyCodes = $cashTenderRows
                ->pluck('currency_code')
                ->map(fn ($currencyCode) => strtoupper(trim((string) $currencyCode)))
                ->filter()
                ->unique()
                ->values();
            $requestedCashCurrency = strtoupper(trim((string) (
                $request->input('counted_cash_currency_code')
                ?: $request->input('cashup_currency_code')
                ?: $request->input('currency_code')
                ?: ''
            )));
            if ($requestedCashCurrency === 'MULTI') {
                $requestedCashCurrency = '';
            }

            if ($requestedCashCurrency === '' && $cashCurrencyCodes->count() > 1) {
                throw ValidationException::withMessages([
                    'counted_cash_currency_code' => [
                        'counted_cash_currency_code is required when counted_cash covers more than one cash currency.',
                    ],
                ]);
            }

            $targetCurrency = $requestedCashCurrency
                ?: (string) ($cashCurrencyCodes->first() ?: 'USD');
            $fallbackRow = $cashTenderRows->first(
                fn ($payment) => strtoupper((string) ($payment['currency_code'] ?? '')) === $targetCurrency
            ) ?: [
                'payment_name' => 'Cash',
                'money_code' => 'cash',
                'currency_code' => $targetCurrency,
            ];

            $countedPayments->push([
                'payment_name' => $fallbackRow['payment_name'],
                'money_code' => $fallbackRow['money_code'] ?? 'cash',
                'currency_code' => $targetCurrency,
                'amount' => round((float) $request->counted_cash, 4),
            ]);
        }

        if ($countedPayments->isEmpty()) {
            $countedPayments = collect($summary['payments'])->map(fn ($payment) => [
                'payment_name' => $payment['payment_name'],
                'money_code' => $payment['money_code'] ?? null,
                'currency_code' => strtoupper($payment['currency_code']),
                'amount' => 0,
            ]);
        }

        return $countedPayments->values()->all();
    }

    private function buildCashupVariance(array $expectedPayments, array $countedPayments): array
    {
        $rows = collect();

        foreach ($expectedPayments as $expected) {
            $key = $this->cashupKey($expected['payment_name'], $expected['currency_code'], $expected['money_code'] ?? null);

            $rows->put($key, [
                'payment_name' => $expected['payment_name'],
                'money_code' => $expected['money_code'] ?? null,
                'currency_code' => strtoupper($expected['currency_code']),
                'base_currency_code' => $expected['base_currency_code'] ?? null,
                'payment_count' => (int) ($expected['payment_count'] ?? 0),
                'expected_amount' => round((float) ($expected['amount'] ?? 0), 4),
                'counted_amount' => 0,
                'variance' => round(0 - (float) ($expected['amount'] ?? 0), 4),
                'expected_base_amount' => round((float) ($expected['base_amount'] ?? 0), 4),
                'counted_base_amount' => 0,
                'base_variance' => round(0 - (float) ($expected['base_amount'] ?? 0), 4),
            ]);
        }

        foreach ($countedPayments as $counted) {
            $key = $this->cashupKey($counted['payment_name'], $counted['currency_code'], $counted['money_code'] ?? null);

            if (!$rows->has($key)) {
                /*
                 * Mobile tills can know every active currency without knowing
                 * which tender/currency combinations were used by this shift.
                 * An unmatched zero is not a discrepancy and has no exchange
                 * rate from which a base amount can be derived. Keeping it
                 * would turn the entire base reconciliation into null. Ignore
                 * only that no-op row; a real counted-only amount still enters
                 * variance below and remains visible for investigation.
                 */
                if (round((float) ($counted['amount'] ?? 0), 4) === 0.0) {
                    continue;
                }

                $rows->put($key, [
                    'payment_name' => $counted['payment_name'],
                    'money_code' => $counted['money_code'] ?? null,
                    'currency_code' => strtoupper($counted['currency_code']),
                    'base_currency_code' => null,
                    'payment_count' => 0,
                    'expected_amount' => 0,
                    'counted_amount' => 0,
                    'variance' => 0,
                    'expected_base_amount' => 0,
                    'counted_base_amount' => null,
                    'base_variance' => null,
                ]);
            }

            $row = $rows->get($key);
            $row['counted_amount'] = round((float) $row['counted_amount'] + (float) $counted['amount'], 4);
            $row['variance'] = round((float) $row['counted_amount'] - (float) $row['expected_amount'], 4);
            $expectedAmount = (float) ($row['expected_amount'] ?? 0);
            $expectedBaseAmount = (float) ($row['expected_base_amount'] ?? 0);
            $isBaseCurrency = strtoupper((string) ($row['currency_code'] ?? '')) === strtoupper((string) ($row['base_currency_code'] ?? ''));
            $baseRate = abs($expectedAmount) > 0.000001
                ? $expectedBaseAmount / $expectedAmount
                : ($isBaseCurrency ? 1.0 : null);
            $row['counted_base_amount'] = $baseRate === null
                ? null
                : round((float) $row['counted_amount'] * $baseRate, 4);
            $row['base_variance'] = $row['counted_base_amount'] === null
                ? null
                : round((float) $row['counted_base_amount'] - $expectedBaseAmount, 4);
            $rows->put($key, $row);
        }

        return $rows->values()->sortBy([['payment_name', 'asc'], ['currency_code', 'asc']])->values()->all();
    }

    private function buildCashupCurrencySummary(array $varianceRows): array
    {
        return collect($varianceRows)
            ->groupBy('currency_code')
            ->map(fn ($rows, $currencyCode) => [
                'currency_code' => $currencyCode,
                'expected_amount' => round($rows->sum('expected_amount'), 4),
                'counted_amount' => round($rows->sum('counted_amount'), 4),
                'variance' => round($rows->sum('variance'), 4),
                'payment_count' => round($rows->sum('payment_count'), 4),
            ])
            ->values()
            ->all();
    }

    private function buildCashupTotals(array $varianceRows): array
    {
        $rows = collect($varianceRows);
        $currencyCodes = $rows
            ->pluck('currency_code')
            ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
            ->filter()
            ->unique()
            ->values();
        $isMultiCurrency = $currencyCodes->count() > 1;
        $baseCurrencyCode = strtoupper((string) ($rows->pluck('base_currency_code')->filter()->first() ?: $currencyCodes->first() ?: 'USD'));
        $hasUnknownBaseCount = $rows->contains(fn ($row) => ($row['counted_base_amount'] ?? null) === null);
        $baseExpectedTotal = round((float) $rows->sum('expected_base_amount'), 4);
        $baseCountedTotal = $hasUnknownBaseCount ? null : round((float) $rows->sum('counted_base_amount'), 4);

        return [
            'currency_code' => $isMultiCurrency ? 'MULTI' : ($currencyCodes->first() ?: null),
            'base_currency_code' => $baseCurrencyCode,
            'is_multi_currency' => $isMultiCurrency,
            'expected_total' => $isMultiCurrency ? null : round((float) $rows->sum('expected_amount'), 4),
            'counted_total' => $isMultiCurrency ? null : round((float) $rows->sum('counted_amount'), 4),
            'total_variance' => $isMultiCurrency ? null : round((float) $rows->sum('variance'), 4),
            'base_expected_total' => $baseExpectedTotal,
            'base_counted_total' => $baseCountedTotal,
            'base_total_variance' => $baseCountedTotal === null ? null : round($baseCountedTotal - $baseExpectedTotal, 4),
        ];
    }

    private function hydrateCashoutFields($cashup)
    {
        $salesSummary = $this->toArrayValue($cashup->sales_summary ?? null);
        $cashoutSummary = $this->toArrayValue($cashup->cashout_summary ?? null);
        $nestedCash = $salesSummary['cash'] ?? [];
        $paymentSummary = $this->toArrayValue($cashup->payment_summary ?? []);
        $isMultiCurrency = strtoupper((string) ($cashup->cashup_currency_code ?? '')) === 'MULTI'
            || (bool) ($salesSummary['is_multi_currency'] ?? false)
            || collect($paymentSummary)
                ->pluck('currency_code')
                ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                ->filter()
                ->unique()
                ->count() > 1;

        $cashoutRows = $cashoutSummary['cash_out_rows'] ?? [];
        $cashinRows = $cashoutSummary['cash_in_rows'] ?? [];

        /*
         | Details modal needs the actual drawer movement rows.
         | Older cashups may have cashout_summary = null, while the top-level
         | cashout_total or sales_summary.cash.cashout_total still has a value.
         | Rehydrate the movement rows from pos_cash_drawer_movements using shift_session_id.
         */
        if (empty($cashoutRows) && !empty($cashup->shift_session_id) && !empty($cashup->company_id) && !empty($cashup->branch_id)) {
            $movementSummary = $this->buildCashMovementSummary(
                (string) $cashup->company_id,
                (string) $cashup->branch_id,
                $cashup->till_id ? (string) $cashup->till_id : null,
                (string) $cashup->shift_session_id
            );

            $cashoutRows = $movementSummary['cash_out_rows'] ?? [];
            $cashinRows = $movementSummary['cash_in_rows'] ?? [];

            $cashoutSummary = array_merge($cashoutSummary, $movementSummary);
        }

        $nestedCashoutTotal = (float) ($nestedCash['cashout_total'] ?? 0);
        $summaryCashoutTotal = (float) ($cashoutSummary['cashout_total'] ?? collect($cashoutRows)->sum('amount'));
        $storedCashoutTotal = (float) ($cashup->cashout_total ?? 0);

        $effectiveCashoutTotal = $isMultiCurrency ? null : ($summaryCashoutTotal > 0
            ? $summaryCashoutTotal
            : ($storedCashoutTotal > 0 ? $storedCashoutTotal : $nestedCashoutTotal));

        $storedExpectedAfter = (float) ($cashup->expected_cash_after_cashouts ?? 0);
        $nestedExpectedAfter = (float) ($nestedCash['expected_cash_after_cashouts'] ?? 0);
        $expectedCash = (float) ($cashup->expected_cash ?? ($nestedCash['expected_cash'] ?? 0));

        $effectiveExpectedAfter = $isMultiCurrency ? null : ($storedExpectedAfter != 0.0
            ? $storedExpectedAfter
            : ($nestedExpectedAfter != 0.0 ? $nestedExpectedAfter : round($expectedCash - $effectiveCashoutTotal, 4)));

        $cashoutSummary['cash_out_rows'] = $cashoutRows;
        $cashoutSummary['cash_in_rows'] = $cashinRows;
        $cashoutSummary['cashout_count'] = (int) ($cashoutSummary['cashout_count'] ?? count($cashoutRows));
        $cashoutSummary['cashout_total'] = $isMultiCurrency ? null : round((float) ($cashoutSummary['cashout_total'] ?? collect($cashoutRows)->sum('amount')), 4);
        $cashoutSummary['cashin_total'] = $isMultiCurrency ? null : round((float) ($cashoutSummary['cashin_total'] ?? collect($cashinRows)->sum('amount')), 4);
        $cashoutSummary['net_cash_movement'] = $isMultiCurrency ? null : round((float) ($cashoutSummary['cashin_total'] - $cashoutSummary['cashout_total']), 4);

        $cashup->cashout_summary = $cashoutSummary;
        $cashup->cashout_total = $effectiveCashoutTotal === null ? null : round($effectiveCashoutTotal, 4);
        $cashup->cashout_count = (int) (($cashup->cashout_count ?? 0) ?: ($cashoutSummary['cashout_count'] ?? count($cashoutRows)));
        $cashup->expected_cash_after_cashouts = $effectiveExpectedAfter === null ? null : round($effectiveExpectedAfter, 4);
        $cashup->effective_cashout_total = $effectiveCashoutTotal === null ? null : round($effectiveCashoutTotal, 4);
        $cashup->effective_expected_cash_after_cashouts = $effectiveExpectedAfter === null ? null : round($effectiveExpectedAfter, 4);

        if (empty($salesSummary['cash_by_currency']) && !empty($paymentSummary)) {
            $salesSummary['cash_by_currency'] = $this->buildCashByCurrency($paymentSummary, $cashoutSummary);
        }

        if (empty($salesSummary['totals_by_currency']) && !empty($paymentSummary)) {
            $salesSummary['totals_by_currency'] = $this->buildTotalsByCurrency($paymentSummary);
        }

        $salesSummary['is_multi_currency'] = $isMultiCurrency || collect($paymentSummary)
                ->pluck('currency_code')
                ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                ->filter()
                ->unique()
                ->count() > 1;

        $cashup->sales_summary = $salesSummary;
        $cashup->cash_by_currency = $salesSummary['cash_by_currency'] ?? [];
        $cashup->totals_by_currency = $salesSummary['totals_by_currency'] ?? [];
        $cashup->is_multi_currency = (bool) ($salesSummary['is_multi_currency'] ?? false);

        if ($cashup->is_multi_currency) {
            $reconciliationTotals = $this->toArrayValue($salesSummary['reconciliation_totals'] ?? []);
            $cashup->base_expected_total = $reconciliationTotals['base_expected_total'] ?? null;
            $cashup->base_counted_total = $reconciliationTotals['base_counted_total'] ?? null;
            $cashup->base_total_variance = $reconciliationTotals['base_total_variance'] ?? null;
            $cashup->expected_total = null;
            $cashup->counted_total = null;
            $cashup->total_variance = null;
            $cashup->expected_cash = null;
            $cashup->counted_cash = null;
            $cashup->cash_variance = null;
        }

        return $cashup;
    }

    private function toArrayValue($value): array
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

    private function requestedCurrencyCodes(Request $request): array
    {
        $values = $request->input('currency_codes', []);

        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $values, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!is_array($values)) {
            $values = [];
        }

        if (empty($values) && $request->filled('currency_code')) {
            $values = [$request->input('currency_code')];
        }

        return collect($values)
            ->map(fn ($currencyCode) => strtoupper(trim((string) $currencyCode)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function paginateCashupRowsForCurrencies(
        iterable $rows,
        array $currencyCodes,
        int $perPage,
        int $currentPage,
        Request $request
    ): \Illuminate\Pagination\LengthAwarePaginator {
        $matchingRows = collect($rows)
            ->filter(function ($row) use ($currencyCodes) {
                return collect($this->cashupRowCurrencyCodes($row))
                    ->contains(fn ($currencyCode) => in_array(
                        strtoupper((string) $currencyCode),
                        $currencyCodes,
                        true
                    ));
            })
            ->map(fn ($row) => $this->filterCashupRowCurrencies($row, $currencyCodes))
            ->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $matchingRows->forPage($currentPage, $perPage)->values(),
            $matchingRows->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    private function filterCashupRowCurrencies($row, array $currencyCodes)
    {
        if (empty($currencyCodes)) {
            return $row;
        }

        $matches = fn ($item) => in_array(
            strtoupper((string) data_get($item, 'currency_code', '')),
            $currencyCodes,
            true
        );

        foreach (['payment_summary', 'currency_summary', 'cash_by_currency', 'totals_by_currency', 'denominations'] as $field) {
            $row->{$field} = collect($this->toArrayValue($row->{$field} ?? []))
                ->filter($matches)
                ->values()
                ->all();
        }

        $salesSummary = $this->toArrayValue($row->sales_summary ?? []);
        foreach (['cash_by_currency', 'totals_by_currency'] as $field) {
            if (array_key_exists($field, $salesSummary)) {
                $salesSummary[$field] = collect($this->toArrayValue($salesSummary[$field]))
                    ->filter($matches)
                    ->values()
                ->all();
            }
        }
        $salesSummary = $this->filterSalesSummaryCreditCurrencies($salesSummary, $currencyCodes);
        $actualCurrencyCodes = $this->cashupRowCurrencyCodes($row);
        $salesSummary['is_multi_currency'] = count($actualCurrencyCodes) > 1;
        $row->sales_summary = $salesSummary;
        $row->is_multi_currency = count($actualCurrencyCodes) > 1;

        return $row;
    }

    private function filterSalesSummaryCreditCurrencies(array $salesSummary, array $currencyCodes): array
    {
        if (empty($currencyCodes)) {
            return $salesSummary;
        }

        $credit = $this->toArrayValue($salesSummary['credit'] ?? []);
        foreach ([
            'credit_sale_tender_rows',
            'sales_by_currency',
            'collections_by_tender',
            'collections_by_currency',
            'change_credits_by_tender',
            'change_credits_by_currency',
            'collection_refunds_by_tender',
            'collection_refunds_by_currency',
            'change_credit_refunds_by_tender',
            'change_credit_refunds_by_currency',
        ] as $field) {
            if (!array_key_exists($field, $credit)) {
                continue;
            }

            $credit[$field] = collect($this->toArrayValue($credit[$field]))
                ->filter(fn ($row) => in_array(
                    strtoupper((string) ($row['currency_code'] ?? '')),
                    $currencyCodes,
                    true
                ))
                ->values()
                ->all();
        }

        if (!empty($credit)) {
            $salesSummary['credit'] = $credit;
        }

        return $salesSummary;
    }

    private function cashupKey(string $paymentName, string $currencyCode, ?string $moneyCode = null): string
    {
        return strtolower(trim($paymentName)) . '|' . strtolower(trim((string) $moneyCode)) . '|' . strtoupper(trim($currencyCode));
    }


    private function normalizeCashupDenominations(
        Request $request,
        string $companyId,
        string $branchId,
        ?string $tillId
    ): array {
        $rows = collect($request->input('denominations', []))
            ->map(function ($row, $index) use ($companyId, $branchId, $tillId) {
                $currencyCode = strtoupper(trim((string) ($row['currency_code'] ?? 'USD')));
                $value = round((float) ($row['value'] ?? $row['denomination_value'] ?? 0), 4);
                $quantity = (int) ($row['quantity'] ?? 0);
                $lineTotal = round($value * $quantity, 4);

                return [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'till_id' => $tillId,
                    'currency_code' => $currencyCode,
                    'denomination_value' => $value,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'denomination_type' => $row['type']
                        ?? $row['denomination_type']
                            ?? ($value >= 1 ? 'note' : 'coin'),
                    'sort_order' => (int) ($row['sort_order'] ?? ($index + 1)),
                    'metadata' => $row['metadata'] ?? null,
                ];
            })
            ->filter(fn ($row) =>
                $row['currency_code'] !== ''
                && $row['denomination_value'] > 0
                && $row['quantity'] > 0
            )
            ->groupBy(fn ($row) => $row['currency_code'] . '|' . number_format($row['denomination_value'], 4, '.', ''))
            ->map(function ($groupedRows) {
                $first = $groupedRows->first();

                $quantity = $groupedRows->sum('quantity');
                $lineTotal = round($groupedRows->sum('line_total'), 4);

                $first['quantity'] = (int) $quantity;
                $first['line_total'] = $lineTotal;

                return $first;
            })
            ->sortBy([
                ['currency_code', 'asc'],
                ['denomination_value', 'desc'],
            ])
            ->values()
            ->map(function ($row, $index) {
                $row['sort_order'] = $index + 1;
                return $row;
            })
            ->all();

        return $rows;
    }


    private function applyDenominationsToCountedPayments(
        array $summary,
        array $countedPayments,
        array $denominationRows
    ): array {
        $denominationTotalsByCurrency = collect($denominationRows)
            ->groupBy('currency_code')
            ->map(fn ($rows) => round($rows->sum('line_total'), 4));

        if ($denominationTotalsByCurrency->isEmpty()) {
            return $countedPayments;
        }

        /*
         | Denominations become the source of truth for physical cash.
         | Only cash payment rows for those currencies are replaced.
         | Drawer/float rows are ignored and must never become payment variance.
         */
        $countedPayments = collect($countedPayments)
            ->reject(function ($payment) use ($denominationTotalsByCurrency) {
                $currencyCode = strtoupper((string) ($payment['currency_code'] ?? ''));

                return $this->isCashPaymentRow($payment)
                    && $denominationTotalsByCurrency->has($currencyCode);
            })
            ->values();

        foreach ($denominationTotalsByCurrency as $currencyCode => $amount) {
            $expectedCashPayment = collect($summary['payments'] ?? [])->first(function ($payment) use ($currencyCode) {
                return $this->isCashPaymentRow($payment)
                    && strtoupper((string) ($payment['currency_code'] ?? '')) === strtoupper($currencyCode);
            });

            $countedPayments->push([
                'payment_name' => $expectedCashPayment['payment_name'] ?? 'CASH',
                'money_code' => $expectedCashPayment['money_code'] ?? 'cash',
                'currency_code' => strtoupper($currencyCode),
                'amount' => round((float) $amount, 4),
            ]);
        }

        return $countedPayments->values()->all();
    }


    private function saveCashupDenominations(PosCashup $cashup, array $denominationRows): void
    {
        if (!DB::getSchemaBuilder()->hasTable('pos_cashup_denominations')) {
            return;
        }

        PosCashupDenomination::where('cashup_id', $cashup->id)->delete();

        foreach ($denominationRows as $row) {
            PosCashupDenomination::create([
                'id' => (string) Str::uuid(),

                'company_id' => $cashup->company_id,
                'branch_id' => $cashup->branch_id,
                'till_id' => $cashup->till_id,

                'cashup_id' => $cashup->id,

                'currency_code' => strtoupper($row['currency_code']),
                'denomination_value' => round((float) $row['denomination_value'], 4),
                'quantity' => (int) $row['quantity'],
                'line_total' => round((float) $row['line_total'], 4),
                'denomination_type' => $row['denomination_type'] ?? 'note',
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'metadata' => $row['metadata'] ?? null,
            ]);
        }
    }


    private function getCashupDenominations(string $cashupId): array
    {
        if (!DB::getSchemaBuilder()->hasTable('pos_cashup_denominations')) {
            return [];
        }

        return PosCashupDenomination::query()
            ->where('cashup_id', $cashupId)
            ->orderBy('sort_order')
            ->orderByDesc('denomination_value')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'cashup_id' => $row->cashup_id,
                'currency_code' => $row->currency_code,
                'denomination_value' => round((float) $row->denomination_value, 4),
                'quantity' => (int) $row->quantity,
                'line_total' => round((float) $row->line_total, 4),
                'denomination_type' => $row->denomination_type,
                'sort_order' => (int) $row->sort_order,
            ])
            ->values()
            ->all();
    }


    private function summarizeDenominations(array $denominationRows): array
    {
        return collect($denominationRows)
            ->groupBy('currency_code')
            ->map(function ($rows, $currencyCode) {
                return [
                    'currency_code' => $currencyCode,
                    'quantity' => (int) $rows->sum('quantity'),
                    'total' => round($rows->sum('line_total'), 4),
                    'lines' => $rows->map(fn ($row) => [
                        'currency_code' => $row['currency_code'],
                        'denomination_value' => round((float) $row['denomination_value'], 4),
                        'quantity' => (int) $row['quantity'],
                        'line_total' => round((float) $row['line_total'], 4),
                        'denomination_type' => $row['denomination_type'],
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }


    private function buildCashupDenominationTemplate(
        string $companyId,
        ?string $branchId,
        string $defaultCurrencyCode = 'USD'
    ): array {
        /*
         * Optional GeneralSetting:
         * module: cashup
         * setting_key: cashup_denominations
         * setting_value:
         * [
         *   {"currency_code":"USD","value":100,"type":"note"},
         *   {"currency_code":"USD","value":50,"type":"note"},
         *   {"currency_code":"USD","value":2,"type":"note"}
         * ]
         */
        $setting = GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('setting_key', 'cashup_denominations')
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        $configured = [];

        if ($setting && !empty($setting->setting_value)) {
            $decoded = json_decode($setting->setting_value, true);

            if (is_array($decoded)) {
                $configured = $decoded;
            }
        }

        if (empty($configured)) {
            $currencyCode = strtoupper($defaultCurrencyCode ?: 'USD');

            $configured = [
                ['currency_code' => $currencyCode, 'value' => 100, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 50, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 20, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 10, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 5, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 2, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 1, 'type' => 'note'],
                ['currency_code' => $currencyCode, 'value' => 0.50, 'type' => 'coin'],
                ['currency_code' => $currencyCode, 'value' => 0.25, 'type' => 'coin'],
                ['currency_code' => $currencyCode, 'value' => 0.10, 'type' => 'coin'],
                ['currency_code' => $currencyCode, 'value' => 0.05, 'type' => 'coin'],
                ['currency_code' => $currencyCode, 'value' => 0.01, 'type' => 'coin'],
            ];
        }

        return collect($configured)
            ->map(function ($row, $index) {
                $value = round((float) ($row['value'] ?? $row['denomination_value'] ?? 0), 4);

                return [
                    'currency_code' => strtoupper((string) ($row['currency_code'] ?? 'USD')),
                    'value' => $value,
                    'denomination_value' => $value,
                    'quantity' => 0,
                    'line_total' => 0,
                    'type' => $row['type'] ?? $row['denomination_type'] ?? ($value >= 1 ? 'note' : 'coin'),
                    'sort_order' => (int) ($row['sort_order'] ?? ($index + 1)),
                ];
            })
            ->filter(fn ($row) => $row['denomination_value'] > 0)
            ->sortBy([
                ['currency_code', 'asc'],
                ['denomination_value', 'desc'],
            ])
            ->values()
            ->all();
    }

    public function cashupHistoryExcel(Request $request)
    {
        $data = $this->cashupHistoryReportData($request);
        return $this->reportDownloads->downloadSpreadsheet(
            title: 'Cashup History Report',
            filename: 'cashup-history-report-' . now()->format('Ymd-His') . '.xlsx',
            sheets: [
                ['title' => 'Executive Summary', 'headers' => ['Metric', 'Value'], 'rows' => $data['summary_rows']],
                ['title' => 'Cashups', 'headers' => $data['headers'], 'rows' => $data['rows']],
                ['title' => 'Payment Methods', 'headers' => $data['payment_method_headers'], 'rows' => $data['payment_method_rows']],
                ['title' => 'Payment Method Summary', 'headers' => $data['payment_method_summary_headers'], 'rows' => $data['payment_method_summary_rows']],
                ['title' => 'Account Credit Activity', 'headers' => $data['account_credit_headers'], 'rows' => $data['account_credit_rows']],
                ['title' => 'Account Credit Summary', 'headers' => $data['account_credit_summary_headers'], 'rows' => $data['account_credit_summary_rows']],
                ['title' => 'Cashup Currency Detail', 'headers' => $data['cashup_currency_headers'], 'rows' => $data['cashup_currency_detail_rows']],
                ['title' => 'Currency Summary', 'headers' => $data['currency_headers'], 'rows' => $data['currency_rows']],
                ['title' => 'Cashier Summary', 'headers' => $data['cashier_headers'], 'rows' => $data['cashier_rows']],
            ]
        );
    }

    public function cashupHistoryPdf(Request $request)
    {
        $data = $this->cashupHistoryReportData($request);
        return $this->reportDownloads->downloadPdf(
            title: 'Cashup History Report',
            filename: 'cashup-history-report-' . now()->format('Ymd-His') . '.pdf',
            sections: [
                ['title' => 'Executive Summary', 'headers' => ['Metric', 'Value'], 'rows' => $data['summary_rows']],
                ['title' => 'Cashups', 'headers' => $data['headers'], 'rows' => array_slice($data['rows'], 0, 300)],
                ['title' => 'Payment Methods', 'headers' => $data['payment_method_headers'], 'rows' => array_slice($data['payment_method_rows'], 0, 500)],
                ['title' => 'Payment Method Summary', 'headers' => $data['payment_method_summary_headers'], 'rows' => $data['payment_method_summary_rows']],
                ['title' => 'Account Credit Activity', 'headers' => $data['account_credit_headers'], 'rows' => array_slice($data['account_credit_rows'], 0, 500)],
                ['title' => 'Account Credit Summary', 'headers' => $data['account_credit_summary_headers'], 'rows' => $data['account_credit_summary_rows']],
                ['title' => 'Cashup Currency Detail', 'headers' => $data['cashup_currency_headers'], 'rows' => array_slice($data['cashup_currency_detail_rows'], 0, 500)],
                ['title' => 'Currency Summary', 'headers' => $data['currency_headers'], 'rows' => $data['currency_rows']],
                ['title' => 'Cashier Summary', 'headers' => $data['cashier_headers'], 'rows' => $data['cashier_rows']],
            ]
        );
    }

    public function cashoutReportExcel(Request $request)
    {
        $data = $this->cashoutReportData($request);
        return $this->reportDownloads->downloadSpreadsheet(
            title: 'Cash Movement Report',
            filename: 'cash-movement-report-' . now()->format('Ymd-His') . '.xlsx',
            sheets: [
                ['title' => 'Executive Summary', 'headers' => ['Metric', 'Value'], 'rows' => $data['summary_rows']],
                ['title' => 'Movements', 'headers' => $data['headers'], 'rows' => $data['rows']],
                ['title' => 'Type Currency Summary', 'headers' => $data['type_currency_headers'], 'rows' => $data['type_currency_rows']],
                ['title' => 'Cashier Currency Summary', 'headers' => $data['cashier_headers'], 'rows' => $data['cashier_rows']],
            ]
        );
    }

    public function cashoutReportPdf(Request $request)
    {
        $data = $this->cashoutReportData($request);
        return $this->reportDownloads->downloadPdf(
            title: 'Cash Movement Report',
            filename: 'cash-movement-report-' . now()->format('Ymd-His') . '.pdf',
            sections: [
                ['title' => 'Executive Summary', 'headers' => ['Metric', 'Value'], 'rows' => $data['summary_rows']],
                ['title' => 'Movements', 'headers' => $data['headers'], 'rows' => array_slice($data['rows'], 0, 400)],
                ['title' => 'Type / Currency Summary', 'headers' => $data['type_currency_headers'], 'rows' => $data['type_currency_rows']],
                ['title' => 'Cashier Currency Summary', 'headers' => $data['cashier_headers'], 'rows' => $data['cashier_rows']],
            ]
        );
    }

    public function shiftReport(Request $request)
    {
        $data = $this->shiftReportData($request);
        return response()->json([
            'message' => 'Shift report fetched successfully.',
            'permissions' => $data['permissions'],
            'summary' => $data['summary'],
            'data' => [
                'rows' => $data['records'],
                'summary_rows' => $data['summary_rows'],
                'cashier_rows' => $data['cashier_rows'],
                'currency_rows' => $data['currency_rows'],
                'shift_currency_detail_rows' => $data['shift_currency_detail_rows'],
                'payment_method_headers' => $data['payment_method_headers'],
                'payment_method_rows' => $data['payment_method_rows'],
                'payment_method_summary_headers' => $data['payment_method_summary_headers'],
                'payment_method_summary_rows' => $data['payment_method_summary_rows'],
                'account_credit_headers' => $data['account_credit_headers'],
                'account_credit_rows' => $data['account_credit_rows'],
                'account_credit_summary_headers' => $data['account_credit_summary_headers'],
                'account_credit_summary_rows' => $data['account_credit_summary_rows'],
            ],
            'payment_method_headers' => $data['payment_method_headers'],
            'payment_method_rows' => $data['payment_method_rows'],
            'payment_method_summary_headers' => $data['payment_method_summary_headers'],
            'payment_method_summary_rows' => $data['payment_method_summary_rows'],
            'account_credit_headers' => $data['account_credit_headers'],
            'account_credit_rows' => $data['account_credit_rows'],
            'account_credit_summary_headers' => $data['account_credit_summary_headers'],
            'account_credit_summary_rows' => $data['account_credit_summary_rows'],
        ]);
    }

    public function shiftReportExcel(Request $request)
    {
        $data = $this->shiftReportData($request);
        return $this->reportDownloads->downloadSpreadsheet(
            title: 'Shift Report',
            filename: 'shift-report-' . now()->format('Ymd-His') . '.xlsx',
            sheets: [
                ['title' => 'Executive Summary', 'headers' => ['Metric', 'Value'], 'rows' => $data['summary_rows']],
                ['title' => 'Shifts', 'headers' => $data['headers'], 'rows' => $data['rows']],
                ['title' => 'Payment Methods', 'headers' => $data['payment_method_headers'], 'rows' => $data['payment_method_rows']],
                ['title' => 'Payment Method Summary', 'headers' => $data['payment_method_summary_headers'], 'rows' => $data['payment_method_summary_rows']],
                ['title' => 'Account Credit Activity', 'headers' => $data['account_credit_headers'], 'rows' => $data['account_credit_rows']],
                ['title' => 'Account Credit Summary', 'headers' => $data['account_credit_summary_headers'], 'rows' => $data['account_credit_summary_rows']],
                ['title' => 'Shift Currency Detail', 'headers' => $data['shift_currency_headers'], 'rows' => $data['shift_currency_detail_rows']],
                ['title' => 'Currency Summary', 'headers' => $data['currency_headers'], 'rows' => $data['currency_rows']],
                ['title' => 'Cashier Currency Summary', 'headers' => $data['cashier_headers'], 'rows' => $data['cashier_rows']],
            ]
        );
    }

    public function shiftReportPdf(Request $request)
    {
        $data = $this->shiftReportData($request);
        return $this->reportDownloads->downloadPdf(
            title: 'Shift Report',
            filename: 'shift-report-' . now()->format('Ymd-His') . '.pdf',
            sections: [
                ['title' => 'Executive Summary', 'headers' => ['Metric', 'Value'], 'rows' => $data['summary_rows']],
                ['title' => 'Shifts', 'headers' => $data['headers'], 'rows' => array_slice($data['rows'], 0, 400)],
                ['title' => 'Payment Methods', 'headers' => $data['payment_method_headers'], 'rows' => array_slice($data['payment_method_rows'], 0, 500)],
                ['title' => 'Payment Method Summary', 'headers' => $data['payment_method_summary_headers'], 'rows' => $data['payment_method_summary_rows']],
                ['title' => 'Account Credit Activity', 'headers' => $data['account_credit_headers'], 'rows' => array_slice($data['account_credit_rows'], 0, 500)],
                ['title' => 'Account Credit Summary', 'headers' => $data['account_credit_summary_headers'], 'rows' => $data['account_credit_summary_rows']],
                ['title' => 'Shift Currency Detail', 'headers' => $data['shift_currency_headers'], 'rows' => array_slice($data['shift_currency_detail_rows'], 0, 500)],
                ['title' => 'Currency Summary', 'headers' => $data['currency_headers'], 'rows' => $data['currency_rows']],
                ['title' => 'Cashier Currency Summary', 'headers' => $data['cashier_headers'], 'rows' => $data['cashier_rows']],
            ]
        );
    }


    private function decorateCashupBracketFields($row)
    {
        $row->cashup_bracket_key = $this->cashupBracketKey($row);
        $row->cashup_bracket_date = $this->cashupBracketDate($row);
        $row->cashup_bracket_heading = $this->cashupBracketHeading($row);
        $row->cashup_bracket_subheading = $this->cashupBracketSubheading($row);

        return $row;
    }

    private function cashupBracketMode($row): string
    {
        $mode = strtolower((string) ($row->business_mode ?? $row->cashup_business_mode ?? ''));

        return $mode === 'fast_food_boh' ? 'fast_food_boh' : 'retail';
    }

    private function cashupBracketDate($row): string
    {
        if ($this->cashupBracketMode($row) === 'fast_food_boh') {
            return $this->dateText(
                $row->trading_date
                ?? $row->cashup_trading_date
                ?? null
            );
        }

        return $this->dateText(
            $row->cashup_opened_at
            ?? $row->opened_at
            ?? $row->cashup_closed_at
            ?? $row->closed_at
            ?? $row->created_at
            ?? null
        );
    }

    private function cashupBracketKey($row): string
    {
        return implode('|', [
            strtoupper($this->cashupBracketMode($row)),
            $this->cashupBracketDate($row) ?: 'NO_DATE',
            (string) ($row->branch_id ?? ''),
        ]);
    }

    private function cashupBracketHeading($row): string
    {
        $date = $this->cashupBracketDate($row) ?: '-';

        if ($this->cashupBracketMode($row) === 'fast_food_boh') {
            return 'TRADING DATE ' . $date . ' - FAST FOOD / BOH';
        }

        return 'SHIFT OPEN DATE ' . $date . ' - RETAIL';
    }

    private function cashupBracketSubheading($row): string
    {
        if ($this->cashupBracketMode($row) === 'fast_food_boh') {
            return 'Grouped by BOH trading date from Day End / Trading Day';
        }

        return 'Grouped by shift opening date';
    }

    /**
     * Summarize drawer movements without ever adding native values from unlike
     * currencies. Headline values are only meaningful when one currency exists;
     * callers can always use totals_by_currency for the complete breakdown.
     */
    private function cashMovementCurrencySummary($summaryRows, array $cashOutTypes, array $cashInTypes): array
    {
        $rows = collect($summaryRows)->map(fn ($row) => [
            'movement_type' => (string) data_get($row, 'movement_type', ''),
            'currency_code' => strtoupper((string) data_get($row, 'currency_code', '')) ?: 'N/A',
            'movement_count' => (int) data_get($row, 'movement_count', 0),
            'amount' => round((float) data_get($row, 'amount', 0), 4),
        ])->values();

        $totalsByCurrency = $rows
            ->groupBy('currency_code')
            ->map(function ($currencyRows, $currencyCode) use ($cashOutTypes, $cashInTypes) {
                $cashOutRows = $currencyRows->filter(
                    fn ($row) => in_array($row['movement_type'], $cashOutTypes, true)
                );
                $cashInRows = $currencyRows->filter(
                    fn ($row) => in_array($row['movement_type'], $cashInTypes, true)
                );
                $cashOutTotal = round((float) $cashOutRows->sum('amount'), 4);
                $cashInTotal = round((float) $cashInRows->sum('amount'), 4);

                return [
                    'currency_code' => (string) $currencyCode,
                    'movement_count' => (int) $currencyRows->sum('movement_count'),
                    'cashout_count' => (int) $cashOutRows->sum('movement_count'),
                    'cashin_count' => (int) $cashInRows->sum('movement_count'),
                    'cashout_total' => $cashOutTotal,
                    'cashin_total' => $cashInTotal,
                    'net_cash_movement' => round($cashInTotal - $cashOutTotal, 4),
                ];
            })
            ->sortBy('currency_code')
            ->values();

        $isMultiCurrency = $totalsByCurrency->count() > 1;
        $singleCurrency = $totalsByCurrency->first() ?? [
            'currency_code' => null,
            'cashout_total' => 0.0,
            'cashin_total' => 0.0,
            'net_cash_movement' => 0.0,
        ];

        return [
            'currency_code' => $isMultiCurrency ? 'MULTI' : $singleCurrency['currency_code'],
            'is_multi_currency' => $isMultiCurrency,
            'cashout_total' => $isMultiCurrency ? null : $singleCurrency['cashout_total'],
            'cashin_total' => $isMultiCurrency ? null : $singleCurrency['cashin_total'],
            'net_cash_movement' => $isMultiCurrency ? null : $singleCurrency['net_cash_movement'],
            'movement_count' => (int) $rows->sum('movement_count'),
            'cashout_count' => (int) $totalsByCurrency->sum('cashout_count'),
            'cashin_count' => (int) $totalsByCurrency->sum('cashin_count'),
            'totals_by_currency' => $totalsByCurrency->all(),
            'summary_by_type_currency' => $rows->all(),
        ];
    }

    private function groupCashupsByBracket($records): array
    {
        // Keep the legacy entry point currency-safe for any future callers. Native
        // amounts from different currencies must never be collapsed into one scalar.
        return $this->groupCashupsByBracketAndCurrency($records);
    }


    private function cashupRowCurrencyCodes($row): array
    {
        $codes = collect($this->toArrayValue($row->currency_summary ?? []))
            ->map(fn ($currency) => strtoupper((string) ($currency['currency_code'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($codes)) {
            $codes = collect($this->toArrayValue($row->payment_summary ?? []))
                ->map(fn ($payment) => strtoupper((string) ($payment['currency_code'] ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (empty($codes) && !empty($row->cashup_currency_code) && strtoupper((string) $row->cashup_currency_code) !== 'MULTI') {
            $codes = [strtoupper((string) $row->cashup_currency_code)];
        }

        return empty($codes) ? ['N/A'] : $codes;
    }

    private function currencyValuesForCashupRow($row, string $currencyCode): array
    {
        $currencyCode = strtoupper($currencyCode);

        $currencySummary = collect($this->toArrayValue($row->currency_summary ?? []))
            ->first(fn ($currency) => strtoupper((string) ($currency['currency_code'] ?? '')) === $currencyCode);

        $cashByCurrency = collect($row->cash_by_currency ?? data_get($this->toArrayValue($row->sales_summary ?? []), 'cash_by_currency', []))
            ->first(fn ($cash) => strtoupper((string) ($cash['currency_code'] ?? '')) === $currencyCode);

        return [
            'expected_total' => $this->amount($currencySummary['expected_amount'] ?? 0),
            'counted_total' => $this->amount($currencySummary['counted_amount'] ?? 0),
            'total_variance' => $this->amount($currencySummary['variance'] ?? 0),
            'expected_cash' => $this->amount($cashByCurrency['expected_cash'] ?? 0),
            'cashout_total' => $this->amount($cashByCurrency['cashout_total'] ?? 0),
            'expected_cash_after_cashouts' => $this->amount($cashByCurrency['expected_cash_after_cashouts'] ?? 0),
            'counted_cash' => $this->amount($cashByCurrency['counted_cash'] ?? 0),
            'cash_variance' => $this->amount($cashByCurrency['cash_variance'] ?? 0),
        ];
    }

    private function cashupCurrencyExportRow($row, string $currencyCode): array
    {
        $values = $this->currencyValuesForCashupRow($row, $currencyCode);

        return [
            $this->cashupBusinessModeLabel($row),
            $row->cashup_bracket_date ?? $this->cashupBracketDate($row),
            $row->cashup_period_label ?? $row->cashup_period_key ?? '-',
            $currencyCode,
            $this->dateTimeText($row->cashup_closed_at ?? $row->closed_at),
            $this->dateTimeText($row->cashup_opened_at ?? $row->opened_at),
            $row->cashier_name,
            $row->closed_by_name ?? 'Unknown',
            trim((string) ($row->till_code ? $row->till_code . ' - ' : '') . (string) $row->till_name),
            $currencyCode,
            $row->status,
            (int) ($row->transaction_count ?? 0),
            (int) ($row->void_count ?? 0),
            (int) ($row->refund_count ?? 0),
            $values['expected_total'],
            $values['counted_total'],
            $values['total_variance'],
            $values['expected_cash'],
            $values['cashout_total'],
            $values['expected_cash_after_cashouts'],
            $values['counted_cash'],
            $values['cash_variance'],
            $row->notes,
        ];
    }

    private function groupCashupsByBracketAndCurrency($records): array
    {
        return collect($records)
            ->map(fn ($row) => isset($row->cashup_bracket_key) ? $row : $this->decorateCashupBracketFields($row))
            ->groupBy(fn ($row) => $row->cashup_bracket_key)
            ->map(function ($rows) {
                $first = $rows->first();

                $currencyGroups = collect($rows)
                    ->flatMap(function ($row) {
                        return collect($this->cashupRowCurrencyCodes($row))
                            ->map(fn ($currencyCode) => [
                                'currency_code' => $currencyCode,
                                'row' => $row,
                            ]);
                    })
                    ->groupBy('currency_code')
                    ->map(function ($items, $currencyCode) {
                        $cashups = $items->pluck('row')->unique('id')->values();

                        $expectedTotal = 0;
                        $countedTotal = 0;
                        $totalVariance = 0;
                        $expectedCash = 0;
                        $cashoutTotal = 0;
                        $countedCash = 0;
                        $cashVariance = 0;

                        foreach ($cashups as $cashup) {
                            $values = $this->currencyValuesForCashupRow($cashup, (string) $currencyCode);
                            $expectedTotal += (float) $values['expected_total'];
                            $countedTotal += (float) $values['counted_total'];
                            $totalVariance += (float) $values['total_variance'];
                            $expectedCash += (float) $values['expected_cash'];
                            $cashoutTotal += (float) $values['cashout_total'];
                            $countedCash += (float) $values['counted_cash'];
                            $cashVariance += (float) $values['cash_variance'];
                        }

                        return [
                            'currency_code' => (string) $currencyCode,
                            'heading' => 'CURRENCY ' . (string) $currencyCode,
                            'cashup_count' => $cashups->count(),
                            'transaction_count' => (int) $cashups->sum('transaction_count'),
                            'expected_total' => $this->amount($expectedTotal),
                            'counted_total' => $this->amount($countedTotal),
                            'total_variance' => $this->amount($totalVariance),
                            'expected_cash' => $this->amount($expectedCash),
                            'cashout_total' => $this->amount($cashoutTotal),
                            'counted_cash' => $this->amount($countedCash),
                            'cash_variance' => $this->amount($cashVariance),
                            'cashups' => $cashups->values()->all(),
                        ];
                    })
                    ->values();

                $isMultiCurrency = $currencyGroups->count() > 1;
                $singleCurrency = $currencyGroups->first() ?? [
                    'currency_code' => null,
                    'expected_total' => $this->amount(0),
                    'counted_total' => $this->amount(0),
                    'total_variance' => $this->amount(0),
                    'cashout_total' => $this->amount(0),
                    'cash_variance' => $this->amount(0),
                ];

                return [
                    'key' => $first->cashup_bracket_key,
                    'heading' => $first->cashup_bracket_heading,
                    'subheading' => $first->cashup_bracket_subheading,
                    'bracket_date' => $first->cashup_bracket_date,
                    'business_mode' => $this->cashupBracketMode($first),
                    'currency_code' => $isMultiCurrency ? 'MULTI' : ($singleCurrency['currency_code'] ?? null),
                    'is_multi_currency' => $isMultiCurrency,
                    'cashup_count' => $rows->count(),
                    'transaction_count' => (int) $rows->sum('transaction_count'),
                    'expected_total' => $isMultiCurrency ? null : $singleCurrency['expected_total'],
                    'counted_total' => $isMultiCurrency ? null : $singleCurrency['counted_total'],
                    'total_variance' => $isMultiCurrency ? null : $singleCurrency['total_variance'],
                    'cashout_total' => $isMultiCurrency ? null : $singleCurrency['cashout_total'],
                    'cash_variance' => $isMultiCurrency ? null : $singleCurrency['cash_variance'],
                    'currency_groups' => $currencyGroups->all(),
                    'cashups' => $rows->values()->all(),
                ];
            })
            ->values()
            ->all();
    }


    private function cashupHistoryExportRow($row): array
    {
        return [
            $this->cashupBusinessModeLabel($row),
            $row->cashup_bracket_date ?? $this->cashupBracketDate($row),
            $row->cashup_period_label ?? $row->cashup_period_key ?? '-',
            strtoupper((string) ($row->cashup_currency_code ?? '')) ?: $this->currencyListText($row->currency_summary ?? []),
            $this->dateTimeText($row->cashup_closed_at ?? $row->closed_at),
            $this->dateTimeText($row->cashup_opened_at ?? $row->opened_at),
            $row->cashier_name,
            trim((string) ($row->till_code ? $row->till_code . ' - ' : '') . (string) $row->till_name),
            $this->currencyListText($row->currency_summary ?? []),
            $row->status,
            (int) ($row->transaction_count ?? 0),
            (int) ($row->void_count ?? 0),
            (int) ($row->refund_count ?? 0),
            $this->amount($row->expected_total),
            $this->amount($row->counted_total),
            $this->amount($row->total_variance),
            $this->amount($row->expected_cash),
            $this->amount($row->cashout_total),
            $this->amount($row->expected_cash_after_cashouts),
            $this->amount($row->counted_cash),
            $this->amount($row->cash_variance),
            $row->notes,
        ];
    }

    private function cashupRowsGroupedForExport($records, array $headers): array
    {
        $rows = [];
        $groups = $this->groupCashupsByBracketAndCurrency($records);

        foreach ($groups as $group) {
            $summaryLine = $group['heading']
                . '    |    ' . $group['subheading']
                . '    |    Cashups: ' . $group['cashup_count']
                . '    |    Transactions: ' . $group['transaction_count'];

            $rows[] = [
                '__style' => 'bracket',
                'values' => array_pad([$summaryLine], count($headers), ''),
            ];

            foreach ($group['currency_groups'] as $currencyGroup) {
                $currencyLine = $currencyGroup['heading']
                    . '    |    Cashups: ' . $currencyGroup['cashup_count']
                    . '    |    Expected: ' . $currencyGroup['expected_total']
                    . '    |    Counted: ' . $currencyGroup['counted_total']
                    . '    |    Variance: ' . $currencyGroup['total_variance']
                    . '    |    Cash Variance: ' . $currencyGroup['cash_variance'];

                $rows[] = [
                    '__style' => 'currency',
                    'values' => array_pad([$currencyLine], count($headers), ''),
                ];

                foreach ($currencyGroup['cashups'] as $row) {
                    $rows[] = [
                        '__style' => 'normal',
                        'values' => $this->cashupCurrencyExportRow($row, $currencyGroup['currency_code']),
                    ];
                }
            }

            $rows[] = [
                '__style' => 'spacer',
                'values' => array_fill(0, count($headers), ''),
            ];
        }

        return $rows;
    }

    private function cashupHistoryDateHeader(Request $request): string
    {
        return match ($request->input('business_mode')) {
            'fast_food_boh' => 'Trading Date',
            'retail' => 'Shift Open Date',
            default => 'Bracket Date',
        };
    }


    private function cashupHistoryReportData(Request $request): array
    {
        $company = app('company');
        $user = auth()->user();
        $requestedCurrencyCodes = $this->requestedCurrencyCodes($request);

        $permission = hasPermission($user->role_id, 'cash_up_reports', $company->id);
        if ($permission !== true) {
            abort(403, 'You do not have permission to view cashup reports.');
        }

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);
        $branchIds = $this->resolveCashupReportBranchIds($request, (string) $company->id);

        $query = PosCashup::query()
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_cashups.cashier_id')
            ->leftJoin('pos_users as closed_users', 'closed_users.id', '=', 'pos_cashups.closed_by')
            ->leftJoin('pos_tills', 'pos_tills.id', '=', 'pos_cashups.till_id')
            ->select([
                'pos_cashups.*',
                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
                DB::raw('COALESCE(closed_users.name, closed_users.username, "Unknown") as closed_by_name'),
                DB::raw('COALESCE(pos_tills.till_name, pos_tills.till_code, "Unknown Till") as till_name'),
                DB::raw('pos_tills.till_code as till_code'),
            ])
            ->where('pos_cashups.company_id', $company->id)
            ->when(!empty($branchIds), fn ($q) => $q->whereIn('pos_cashups.branch_id', $branchIds))
            ->when($request->filled('cashier_id'), fn ($q) => $q->where('pos_cashups.cashier_id', $request->cashier_id))
            ->when($request->filled('shift_session_id'), fn ($q) => $q->where('pos_cashups.shift_session_id', $request->shift_session_id))
            ->when($request->filled('till_id'), fn ($q) => $q->where('pos_cashups.till_id', $request->till_id))
            ->when($request->filled('business_mode') && Schema::hasColumn('pos_cashups', 'business_mode'), fn ($q) => $q->where('pos_cashups.business_mode', $request->business_mode));

        if (!empty($requestedCurrencyCodes) && Schema::hasColumn('pos_cashups', 'cashup_currency_code')) {
            $query->where(function ($q) use ($requestedCurrencyCodes) {
                $q->whereIn('pos_cashups.cashup_currency_code', $requestedCurrencyCodes)
                    ->orWhere('pos_cashups.cashup_currency_code', 'MULTI')
                    ->orWhereNull('pos_cashups.cashup_currency_code');
            });
        }

        $closedAtColumn = Schema::hasColumn('pos_cashups', 'cashup_closed_at') ? 'cashup_closed_at' : 'closed_at';

        $this->applyCashupBracketDateFilters($query, $request);

        $query
            ->orderByRaw($this->cashupUnifiedBracketDateExpression() . ' DESC')
            ->orderByDesc("pos_cashups.{$closedAtColumn}");

        $records = $query->limit((int) $request->input('export_limit', 5000))->get()
            ->map(fn ($row) => $this->decorateCashupBracketFields($this->hydrateCashoutFields($row)));

        if (!empty($requestedCurrencyCodes)) {
            $records = $records
                ->filter(function ($row) use ($requestedCurrencyCodes) {
                    return collect($this->cashupRowCurrencyCodes($row))
                        ->contains(fn ($currencyCode) => in_array(strtoupper((string) $currencyCode), $requestedCurrencyCodes, true));
                })
                ->map(fn ($row) => $this->filterCashupRowCurrencies($row, $requestedCurrencyCodes))
                ->values();
        }

        if (!$canViewFinancialAmounts) {
            return $this->operationalCashupHistoryReportData(
                $records,
                $this->cashupHistoryDateHeader($request)
            );
        }

        $cashupDateHeader = $this->cashupHistoryDateHeader($request);
        $headers = [
            'Business Mode', $cashupDateHeader, 'Period', 'Cashup Currency', 'Closed At', 'Opened At', 'Cashier', 'Closed By', 'Till', 'Currencies', 'Status', 'Transactions', 'Voids', 'Refunds',
            'Expected Total', 'Counted Total', 'Total Variance', 'Expected Cash', 'Cashouts',
            'Expected Cash After Cashouts', 'Counted Cash', 'Cash Variance', 'Notes'
        ];


        $rows = $this->cashupRowsGroupedForExport($records, $headers);


        $currencyCodes = !empty($requestedCurrencyCodes)
            ? $requestedCurrencyCodes
            : $records->flatMap(fn ($row) => $this->cashupRowCurrencyCodes($row))
                ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                ->filter(fn ($currencyCode) => $currencyCode !== '' && $currencyCode !== 'N/A')
                ->unique()->sort()->values()->all();

        $cashupCurrencyHeaders = array_merge(
            [$cashupDateHeader, 'Closed At', 'Cashier', 'Till', 'Status', 'Transactions'],
            collect($currencyCodes)->flatMap(fn ($currencyCode) => [
                $currencyCode . ' Payment Count',
                $currencyCode . ' Expected Total',
                $currencyCode . ' Counted Total',
                $currencyCode . ' Variance',
            ])->all()
        );
        $cashupCurrencyDetailRows = $records->map(function ($row) use ($currencyCodes) {
            $tillName = trim((string) ($row->till_code ? $row->till_code . ' - ' : '') . (string) $row->till_name);
            $byCurrency = collect($this->toArrayValue($row->currency_summary ?? []))
                ->keyBy(fn ($currency) => strtoupper((string) ($currency['currency_code'] ?? '')));

            return array_merge([
                $row->cashup_bracket_date ?? $this->cashupBracketDate($row),
                $this->dateTimeText($row->cashup_closed_at ?? $row->closed_at),
                $row->cashier_name ?: 'Unknown',
                $tillName ?: '-',
                $row->status,
                (int) ($row->transaction_count ?? 0),
            ], collect($currencyCodes)->flatMap(function ($currencyCode) use ($byCurrency) {
                $currency = $byCurrency->get($currencyCode, []);
                return [
                    (int) ($currency['payment_count'] ?? 0),
                    $this->amount($currency['expected_amount'] ?? 0),
                    $this->amount($currency['counted_amount'] ?? 0),
                    $this->amount($currency['variance'] ?? 0),
                ];
            })->all());
        })->values()->all();

        $currencyTotals = $records->flatMap(function ($row) {
            return collect($this->toArrayValue($row->currency_summary ?? []))->map(fn ($currency) => [
                'currency_code' => $currency['currency_code'] ?? 'N/A',
                'expected_amount' => (float) ($currency['expected_amount'] ?? 0),
                'counted_amount' => (float) ($currency['counted_amount'] ?? 0),
                'variance' => (float) ($currency['variance'] ?? 0),
            ]);
        })->groupBy(fn ($currency) => strtoupper((string) $currency['currency_code']));
        $currencyHeaders = array_merge(['Metric'], $currencyCodes);
        $currencyRows = [
            array_merge(['Expected Total'], collect($currencyCodes)->map(fn ($currencyCode) => $this->amount($currencyTotals->get($currencyCode, collect())->sum('expected_amount')))->all()),
            array_merge(['Counted Total'], collect($currencyCodes)->map(fn ($currencyCode) => $this->amount($currencyTotals->get($currencyCode, collect())->sum('counted_amount')))->all()),
            array_merge(['Variance'], collect($currencyCodes)->map(fn ($currencyCode) => $this->amount($currencyTotals->get($currencyCode, collect())->sum('variance')))->all()),
            array_merge(['Cashup Lines'], collect($currencyCodes)->map(fn ($currencyCode) => $currencyTotals->get($currencyCode, collect())->count())->all()),
        ];

        $cashierHeaders = array_merge(
            ['Cashier', 'Cashups'],
            collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Expected', $currencyCode . ' Counted', $currencyCode . ' Variance'])->all()
        );
        $cashierRows = $records->groupBy(fn ($row) => $row->cashier_name ?: 'Unknown')
            ->map(function ($rows, $cashier) use ($currencyCodes) {
                return array_merge([$cashier, $rows->count()], collect($currencyCodes)->flatMap(function ($currencyCode) use ($rows) {
                    $values = $rows->map(fn ($row) => $this->currencyValuesForCashupRow($row, $currencyCode));
                    return [
                        $this->amount($values->sum('expected_total')),
                        $this->amount($values->sum('counted_total')),
                        $this->amount($values->sum('total_variance')),
                    ];
                })->all());
            })->values()->all();

        $totalsByCurrency = collect($currencyCodes)->map(function ($currencyCode) use ($records) {
            $currencyValues = $records->map(fn ($row) => $this->currencyValuesForCashupRow($row, $currencyCode));

            return [
                'currency_code' => $currencyCode,
                'expected_total' => $this->amount($currencyValues->sum('expected_total')),
                'counted_total' => $this->amount($currencyValues->sum('counted_total')),
                'total_variance' => $this->amount($currencyValues->sum('total_variance')),
                'cashout_total' => $this->amount($currencyValues->sum('cashout_total')),
                'cash_variance' => $this->amount($currencyValues->sum('cash_variance')),
            ];
        })->values();
        $isMultiCurrency = $totalsByCurrency->count() > 1;
        $singleCurrency = $totalsByCurrency->first() ?? [
            'currency_code' => null,
            'expected_total' => $this->amount(0),
            'counted_total' => $this->amount(0),
            'total_variance' => $this->amount(0),
            'cashout_total' => $this->amount(0),
            'cash_variance' => $this->amount(0),
        ];
        $summary = [
            'currency_code' => $isMultiCurrency ? 'MULTI' : $singleCurrency['currency_code'],
            'is_multi_currency' => $isMultiCurrency,
            'cashup_count' => $records->count(),
            'transaction_count' => (int) $records->sum('transaction_count'),
            'expected_total' => $isMultiCurrency ? null : $singleCurrency['expected_total'],
            'counted_total' => $isMultiCurrency ? null : $singleCurrency['counted_total'],
            'total_variance' => $isMultiCurrency ? null : $singleCurrency['total_variance'],
            'cashout_total' => $isMultiCurrency ? null : $singleCurrency['cashout_total'],
            'cash_variance' => $isMultiCurrency ? null : $singleCurrency['cash_variance'],
            'totals_by_currency' => $totalsByCurrency->all(),
        ];
        $summaryRows = [
            ['Cashup Count', $summary['cashup_count']],
            ['Transaction Count', $summary['transaction_count']],
        ];
        foreach ($totalsByCurrency as $currencyTotalsRow) {
            $currencyCode = $currencyTotalsRow['currency_code'];
            $summaryRows[] = [$currencyCode . ' Expected Total', $currencyTotalsRow['expected_total']];
            $summaryRows[] = [$currencyCode . ' Counted Total', $currencyTotalsRow['counted_total']];
            $summaryRows[] = [$currencyCode . ' Total Variance', $currencyTotalsRow['total_variance']];
            $summaryRows[] = [$currencyCode . ' Cashouts', $currencyTotalsRow['cashout_total']];
            $summaryRows[] = [$currencyCode . ' Cash Variance', $currencyTotalsRow['cash_variance']];
        }

        $paymentMethodTables = $this->paymentMethodReportTables($records, true, 'cashup');
        $accountCreditTables = $this->accountCreditReportTables($records, true, 'cashup');

        return [
            'permissions' => $this->cashupFinancialVisibility->permissionFlags(true),
            'records' => $records->values()->all(),
            'groups' => $this->groupCashupsByBracketAndCurrency($records),
            'summary' => $summary,
            'summary_rows' => $summaryRows,
            'headers' => $headers,
            'rows' => $rows,
            'cashup_currency_headers' => $cashupCurrencyHeaders,
            'cashup_currency_detail_rows' => $cashupCurrencyDetailRows,
            'currency_headers' => $currencyHeaders,
            'currency_rows' => $currencyRows,
            'cashier_headers' => $cashierHeaders,
            'cashier_rows' => $cashierRows,
            'payment_method_headers' => $paymentMethodTables['headers'],
            'payment_method_rows' => $paymentMethodTables['rows'],
            'payment_method_summary_headers' => $paymentMethodTables['summary_headers'],
            'payment_method_summary_rows' => $paymentMethodTables['summary_rows'],
            'account_credit_headers' => $accountCreditTables['headers'],
            'account_credit_rows' => $accountCreditTables['rows'],
            'account_credit_summary_headers' => $accountCreditTables['summary_headers'],
            'account_credit_summary_rows' => $accountCreditTables['summary_rows'],
        ];
    }

    private function operationalCashupHistoryReportData($records, string $cashupDateHeader = 'Bracket Date'): array
    {
        $paymentMethodTables = $this->paymentMethodReportTables($records, false, 'cashup');
        $accountCreditTables = $this->accountCreditReportTables($records, false, 'cashup');
        $headers = [
            'Business Mode', $cashupDateHeader, 'Period', 'Cashup Currency', 'Closed At',
            'Opened At', 'Cashier', 'Closed By', 'Till', 'Currencies', 'Status',
            'Transactions', 'Voids', 'Refunds', 'Notes',
        ];
        $rows = [];
        $groups = $this->groupCashupsByBracketAndCurrency($records);

        foreach ($groups as $group) {
            $rows[] = [
                '__style' => 'bracket',
                'values' => array_pad([
                    $group['heading'] . '    |    ' . $group['subheading']
                    . '    |    Cashups: ' . $group['cashup_count']
                    . '    |    Transactions: ' . $group['transaction_count'],
                ], count($headers), ''),
            ];

            foreach ($group['currency_groups'] as $currencyGroup) {
                $rows[] = [
                    '__style' => 'currency',
                    'values' => array_pad([
                        $currencyGroup['heading'] . '    |    Cashups: ' . $currencyGroup['cashup_count'],
                    ], count($headers), ''),
                ];

                foreach ($currencyGroup['cashups'] as $row) {
                    $tillName = trim((string) (($row->till_code ?? '') ? $row->till_code . ' - ' : '') . (string) ($row->till_name ?? ''));
                    $rows[] = [
                        '__style' => 'normal',
                        'values' => [
                            $this->cashupBusinessModeLabel($row),
                            $row->cashup_bracket_date ?? $this->cashupBracketDate($row),
                            $row->cashup_period_label ?? $row->cashup_period_key ?? '-',
                            strtoupper((string) ($row->cashup_currency_code ?? '')) ?: '-',
                            $this->dateTimeText($row->cashup_closed_at ?? $row->closed_at),
                            $this->dateTimeText($row->cashup_opened_at ?? $row->opened_at),
                            $row->cashier_name ?? 'Unknown',
                            $row->closed_by_name ?? 'Unknown',
                            $tillName ?: '-',
                            $this->currencyListText($row->currency_summary ?? []),
                            $row->status ?? '-',
                            (int) ($row->transaction_count ?? 0),
                            (int) ($row->void_count ?? 0),
                            (int) ($row->refund_count ?? 0),
                            $row->notes ?? '-',
                        ],
                    ];
                }
            }

            $rows[] = [
                '__style' => 'spacer',
                'values' => array_fill(0, count($headers), ''),
            ];
        }

        $currencyActivity = $records->flatMap(function ($row) {
            return collect($this->toArrayValue($row->currency_summary ?? []))->map(fn ($currency) => [
                'currency_code' => strtoupper((string) ($currency['currency_code'] ?? 'N/A')),
                'payment_count' => (int) ($currency['payment_count'] ?? 0),
                'cashier_name' => $row->cashier_name ?? 'Unknown',
            ]);
        });

        $currencyRows = $currencyActivity
            ->groupBy('currency_code')
            ->map(fn ($items, $currencyCode) => [
                $currencyCode,
                $items->count(),
                (int) $items->sum('payment_count'),
            ])->values()->all();

        $cashierRows = $records
            ->groupBy(fn ($row) => $row->cashier_name ?? 'Unknown')
            ->map(fn ($items, $cashier) => [
                $cashier,
                $items->count(),
                (int) $items->sum('transaction_count'),
            ])->values()->all();

        $cashupCurrencyDetailRows = $records->map(function ($row) {
            $tillName = trim((string) (($row->till_code ?? '') ? $row->till_code . ' - ' : '') . (string) ($row->till_name ?? ''));

            return [
                $row->cashup_bracket_date ?? $this->cashupBracketDate($row),
                $this->dateTimeText($row->cashup_closed_at ?? $row->closed_at),
                $row->cashier_name ?? 'Unknown',
                $tillName ?: '-',
                $row->status ?? '-',
                (int) ($row->transaction_count ?? 0),
                $this->currencyListText($row->currency_summary ?? []),
            ];
        })->values()->all();

        $summary = [
            'cashup_count' => $records->count(),
            'transaction_count' => (int) $records->sum('transaction_count'),
        ];

        return [
            'permissions' => $this->cashupFinancialVisibility->permissionFlags(false),
            'records' => $this->cashupFinancialVisibility->redact($records)->values()->all(),
            'groups' => $this->cashupFinancialVisibility->redact($groups),
            'summary' => $summary,
            'summary_rows' => [
                ['Cashup Count', $summary['cashup_count']],
                ['Transaction Count', $summary['transaction_count']],
                ['Financial Amounts', 'Restricted by role permission'],
            ],
            'headers' => $headers,
            'rows' => $rows,
            'cashup_currency_headers' => [$cashupDateHeader, 'Closed At', 'Cashier', 'Till', 'Status', 'Transactions', 'Currencies'],
            'cashup_currency_detail_rows' => $cashupCurrencyDetailRows,
            'currency_headers' => ['Currency', 'Cashup Lines', 'Payment Count'],
            'currency_rows' => $currencyRows,
            'cashier_headers' => ['Cashier', 'Cashups', 'Transactions'],
            'cashier_rows' => $cashierRows,
            'payment_method_headers' => $paymentMethodTables['headers'],
            'payment_method_rows' => $paymentMethodTables['rows'],
            'payment_method_summary_headers' => $paymentMethodTables['summary_headers'],
            'payment_method_summary_rows' => $paymentMethodTables['summary_rows'],
            'account_credit_headers' => $accountCreditTables['headers'],
            'account_credit_rows' => $accountCreditTables['rows'],
            'account_credit_summary_headers' => $accountCreditTables['summary_headers'],
            'account_credit_summary_rows' => $accountCreditTables['summary_rows'],
        ];
    }

    private function cashoutReportData(Request $request): array
    {
        $company = app('company');
        $user = auth()->user();
        $requestedCurrencyCodes = $this->requestedCurrencyCodes($request);

        $permission = hasPermission($user->role_id, 'cash_up_reports', $company->id);
        if ($permission !== true) {
            abort(403, 'You do not have permission to view cashup reports.');
        }

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);

        $branchIds = $this->resolveCashupReportBranchIds($request, (string) $company->id);
        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
        $cashInTypes = ['cash_in', 'float_in'];

        $baseQuery = DB::table('pos_cash_drawer_movements')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_cash_drawer_movements.cashier_id')
            ->leftJoin('pos_tills', 'pos_tills.id', '=', 'pos_cash_drawer_movements.till_id')
            ->leftJoin('pos_user_shift_sessions', 'pos_user_shift_sessions.id', '=', 'pos_cash_drawer_movements.shift_session_id')
            ->leftJoin('pos_cashups', function ($join) {
                $join->on('pos_cashups.company_id', '=', 'pos_cash_drawer_movements.company_id')
                    ->on('pos_cashups.branch_id', '=', 'pos_cash_drawer_movements.branch_id')
                    ->on('pos_cashups.shift_session_id', '=', 'pos_cash_drawer_movements.shift_session_id');
            })
            ->where('pos_cash_drawer_movements.company_id', $company->id)
            ->when(!empty($branchIds), fn ($q) => $q->whereIn('pos_cash_drawer_movements.branch_id', $branchIds))
            ->when(DB::getSchemaBuilder()->hasColumn('pos_cash_drawer_movements', 'deleted_at'), fn ($q) => $q->whereNull('pos_cash_drawer_movements.deleted_at'))
            ->when($request->filled('cashier_id'), fn ($q) => $q->where('pos_cash_drawer_movements.cashier_id', $request->cashier_id))
            ->when($request->filled('till_id'), fn ($q) => $q->where('pos_cash_drawer_movements.till_id', $request->till_id))
            ->when($request->filled('shift_session_id'), fn ($q) => $q->where('pos_cash_drawer_movements.shift_session_id', $request->shift_session_id))
            ->when($request->filled('movement_type'), fn ($q) => $q->where('pos_cash_drawer_movements.movement_type', $request->movement_type))
            ->when(!empty($requestedCurrencyCodes), fn ($q) => $q->whereIn('pos_cash_drawer_movements.currency_code', $requestedCurrencyCodes));

        $this->applyCashMovementReportModeAndDateFilters($baseQuery, $request);

        $records = (clone $baseQuery)
            ->select([
                'pos_cash_drawer_movements.*',
                DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
                DB::raw('COALESCE(pos_tills.till_name, pos_tills.till_code, "Unknown Till") as till_name'),
                DB::raw('pos_tills.till_code as till_code'),
                DB::raw('pos_user_shift_sessions.shift_name as shift_name'),
                DB::raw('pos_user_shift_sessions.opened_at as shift_opened_at'),
                DB::raw('pos_user_shift_sessions.closed_at as shift_closed_at'),
                DB::raw('pos_cashups.id as cashup_id'),
                DB::raw('pos_cashups.closed_at as cashup_closed_at'),
                DB::raw('pos_cashups.status as cashup_status'),
                DB::raw((Schema::hasColumn('pos_cashups', 'business_mode') ? 'pos_cashups.business_mode' : 'NULL') . ' as cashup_business_mode'),
                DB::raw($this->cashMovementReportBusinessDateExpression($request) . ' as report_business_date'),
            ])
            ->orderByDesc('pos_cash_drawer_movements.occurred_at')
            ->limit((int) $request->input('export_limit', 10000))
            ->get();

        $requestedBusinessMode = strtolower(trim((string) $request->input('business_mode', '')));
        $records->transform(function ($row) use ($requestedBusinessMode) {
            $row->report_business_mode = $row->cashup_business_mode ?: ($requestedBusinessMode ?: null);

            return $row;
        });

        $currencyCodes = $records->pluck('currency_code')
            ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
            ->filter()->unique()->sort()->values()->all();

        if (!$canViewFinancialAmounts) {
            $headers = ['Business Mode', 'Business Date', 'Occurred At', 'Type', 'Currency', 'Cashier', 'Till', 'Shift', 'Reference', 'Reason', 'Notes', 'Cashup Status'];
            $rows = $records->map(fn ($row) => [
                $this->cashupBusinessModeLabel((object) ['business_mode' => $row->report_business_mode ?? null]),
                $this->dateText($row->report_business_date ?? null),
                $this->dateTimeText($row->occurred_at),
                $row->movement_type,
                strtoupper((string) $row->currency_code),
                $row->cashier_name,
                trim((string) ($row->till_code ? $row->till_code . ' - ' : '') . (string) $row->till_name),
                $row->shift_name ?: '-',
                $row->reference_no,
                $row->reason,
                $row->notes,
                $row->cashup_status ?: 'Not cashed up',
            ])->values()->all();
            $typeCurrencyRows = $records
                ->groupBy(fn ($row) => $row->movement_type . '|' . strtoupper((string) $row->currency_code))
                ->map(function ($items, $key) {
                    [$type, $currencyCode] = explode('|', $key . '|');
                    return [$type, $currencyCode, $items->count()];
                })->values()->all();
            $cashierRows = $records
                ->groupBy(fn ($row) => ($row->cashier_name ?: 'Unknown') . '|' . strtoupper((string) $row->currency_code))
                ->map(function ($items, $key) {
                    [$cashier, $currencyCode] = explode('|', $key . '|');
                    return [$cashier, $currencyCode, $items->count()];
                })->values()->all();
            $summary = [
                'movement_count' => $records->count(),
                'cashout_count' => $records->filter(fn ($row) => in_array($row->movement_type, $cashOutTypes, true))->count(),
                'cashin_count' => $records->filter(fn ($row) => in_array($row->movement_type, $cashInTypes, true))->count(),
            ];

            return [
                'permissions' => $this->cashupFinancialVisibility->permissionFlags(false),
                'records' => $this->cashupFinancialVisibility->redact($records)->values()->all(),
                'summary' => $summary,
                'summary_rows' => [
                    ['Movement Count', $summary['movement_count']],
                    ['Cash Out Count', $summary['cashout_count']],
                    ['Cash In Count', $summary['cashin_count']],
                    ['Financial Amounts', 'Restricted by role permission'],
                ],
                'headers' => $headers,
                'rows' => $rows,
                'type_currency_headers' => ['Movement Type', 'Currency', 'Movements'],
                'type_currency_rows' => $typeCurrencyRows,
                'cashier_headers' => ['Cashier', 'Currency', 'Movements'],
                'cashier_rows' => $cashierRows,
            ];
        }
        $headers = array_merge(
            ['Business Mode', 'Business Date', 'Occurred At', 'Type', 'Cashier', 'Till', 'Shift', 'Reference', 'Reason', 'Notes', 'Cashup Status'],
            collect($currencyCodes)->map(fn ($currencyCode) => $currencyCode . ' Amount')->all()
        );
        $rows = $records->map(fn ($row) => array_merge([
            $this->cashupBusinessModeLabel((object) ['business_mode' => $row->report_business_mode ?? null]),
            $this->dateText($row->report_business_date ?? null),
            $this->dateTimeText($row->occurred_at),
            $row->movement_type,
            $row->cashier_name,
            trim((string) ($row->till_code ? $row->till_code . ' - ' : '') . (string) $row->till_name),
            $row->shift_name ?: '-',
            $row->reference_no,
            $row->reason,
            $row->notes,
            $row->cashup_status ?: 'Not cashed up',
        ], collect($currencyCodes)->map(fn ($currencyCode) => strtoupper((string) $row->currency_code) === $currencyCode ? $this->amount($row->amount) : null)->all()))->values()->all();

        $typeCurrencyHeaders = array_merge(
            ['Movement Type', 'Movements'],
            collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Count', $currencyCode . ' Amount'])->all()
        );
        $typeCurrencyRows = $records->groupBy(fn ($row) => $row->movement_type)
            ->map(fn ($typeRows, $type) => array_merge([$type, $typeRows->count()], collect($currencyCodes)->flatMap(function ($currencyCode) use ($typeRows) {
                $currencyRows = $typeRows->filter(fn ($row) => strtoupper((string) $row->currency_code) === $currencyCode);
                return [$currencyRows->count(), $this->amount($currencyRows->sum('amount'))];
            })->all()))->values()->all();

        $cashierHeaders = array_merge(
            ['Cashier', 'Movements'],
            collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Cash Out', $currencyCode . ' Cash In', $currencyCode . ' Net'])->all()
        );
        $cashierRows = $records->groupBy(fn ($row) => $row->cashier_name ?: 'Unknown')
            ->map(function ($rows, $cashier) use ($currencyCodes, $cashOutTypes, $cashInTypes) {
                return array_merge([$cashier, $rows->count()], collect($currencyCodes)->flatMap(function ($currencyCode) use ($rows, $cashOutTypes, $cashInTypes) {
                    $currencyRows = $rows->filter(fn ($row) => strtoupper((string) $row->currency_code) === $currencyCode);
                    $cashOut = $currencyRows->filter(fn ($row) => in_array($row->movement_type, $cashOutTypes, true))->sum('amount');
                    $cashIn = $currencyRows->filter(fn ($row) => in_array($row->movement_type, $cashInTypes, true))->sum('amount');
                    return [$this->amount($cashOut), $this->amount($cashIn), $this->amount($cashIn - $cashOut)];
                })->all());
            })->values()->all();

        $movementSummaryRows = $records
            ->groupBy(fn ($row) => $row->movement_type . '|' . strtoupper((string) $row->currency_code))
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'movement_type' => (string) $first->movement_type,
                    'currency_code' => strtoupper((string) $first->currency_code),
                    'movement_count' => $items->count(),
                    'amount' => round((float) $items->sum('amount'), 4),
                ];
            })
            ->values();
        $summary = $this->cashMovementCurrencySummary($movementSummaryRows, $cashOutTypes, $cashInTypes);
        $summaryRows = [
            ['Movement Count', $summary['movement_count']],
            ['Cash Out Count', $summary['cashout_count']],
            ['Cash In Count', $summary['cashin_count']],
        ];
        foreach ($summary['totals_by_currency'] as $currencyTotals) {
            $currencyCode = $currencyTotals['currency_code'];
            $summaryRows[] = [$currencyCode . ' Cash Out', $this->amount($currencyTotals['cashout_total'])];
            $summaryRows[] = [$currencyCode . ' Cash In', $this->amount($currencyTotals['cashin_total'])];
            $summaryRows[] = [$currencyCode . ' Net', $this->amount($currencyTotals['net_cash_movement'])];
        }

        return [
            'permissions' => $this->cashupFinancialVisibility->permissionFlags(true),
            'records' => $records->values()->all(),
            'summary' => $summary,
            'summary_rows' => $summaryRows,
            'headers' => $headers,
            'rows' => $rows,
            'type_currency_headers' => $typeCurrencyHeaders,
            'type_currency_rows' => $typeCurrencyRows,
            'cashier_headers' => $cashierHeaders,
            'cashier_rows' => $cashierRows,
        ];
    }

    private function shiftReportData(Request $request): array
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'cash_up_reports', $company->id);
        if ($permission !== true) {
            abort(403, 'You do not have permission to view shift reports.');
        }

        $canViewFinancialAmounts = $this->canViewCashupFinancialAmounts($user, (string) $company->id);
        $branchIds = $this->resolveCashupReportBranchIds($request, (string) $company->id);
        $requestedCurrencyCodes = $this->requestedCurrencyCodes($request);
        $requestedBusinessMode = strtolower(trim((string) $request->input('business_mode', '')));

        $query = DB::table('pos_user_shift_sessions')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->leftJoin('pos_cashups', function ($join) {
                $join->on('pos_cashups.company_id', '=', 'pos_user_shift_sessions.company_id')
                    ->on('pos_cashups.shift_session_id', '=', 'pos_user_shift_sessions.id');
            })
            ->leftJoin('pos_users as shift_closed_users', 'shift_closed_users.id', '=', 'pos_cashups.closed_by')
            ->where('pos_user_shift_sessions.company_id', $company->id)
            ->when(!empty($branchIds), fn ($q) => $q->whereIn('pos_user_shift_sessions.branch_id', $branchIds))
            ->when($request->filled('cashier_id'), fn ($q) => $q->where('pos_user_shift_sessions.pos_user_id', $request->cashier_id))
            ->when($request->filled('till_id') && Schema::hasColumn('pos_user_shift_sessions', 'till_id'), fn ($q) => $q->where('pos_user_shift_sessions.till_id', $request->till_id))
            ->when(
                $request->filled('till_id') && !Schema::hasColumn('pos_user_shift_sessions', 'till_id'),
                function ($q) use ($request) {
                    $tillId = (string) $request->till_id;
                    $q->where(function ($tillQuery) use ($tillId) {
                        $tillQuery
                            ->where('pos_cashups.till_id', $tillId)
                            ->orWhereExists(function ($salesQuery) use ($tillId) {
                                $salesQuery
                                    ->selectRaw('1')
                                    ->from('sales_transactions as shift_report_till_sales')
                                    ->whereColumn('shift_report_till_sales.company_id', 'pos_user_shift_sessions.company_id')
                                    ->whereColumn('shift_report_till_sales.branch_id', 'pos_user_shift_sessions.branch_id')
                                    ->whereColumn('shift_report_till_sales.shift_session_id', 'pos_user_shift_sessions.id')
                                    ->where('shift_report_till_sales.till_id', $tillId);
                            })
                            ->orWhereExists(function ($paymentsQuery) use ($tillId) {
                                $paymentsQuery
                                    ->selectRaw('1')
                                    ->from('sales_transaction_payments as shift_report_till_payments')
                                    ->whereColumn('shift_report_till_payments.company_id', 'pos_user_shift_sessions.company_id')
                                    ->whereColumn('shift_report_till_payments.branch_id', 'pos_user_shift_sessions.branch_id')
                                    ->whereColumn('shift_report_till_payments.shift_session_id', 'pos_user_shift_sessions.id')
                                    ->where('shift_report_till_payments.till_id', $tillId);
                            });
                    });
                }
            )
            ->when($request->filled('status'), fn ($q) => $q->where('pos_user_shift_sessions.status', $request->status));

        if ($requestedBusinessMode !== '' && Schema::hasColumn('pos_cashups', 'business_mode')) {
            $query->where(function ($modeQuery) use ($requestedBusinessMode) {
                $modeQuery->where('pos_cashups.business_mode', $requestedBusinessMode);

                // An open shift has no cash-up row yet.  Branch access has
                // already been limited to the active workspace mode, so keep
                // the left-joined row instead of accidentally turning this
                // report into an inner join.
                $modeQuery->orWhereNull('pos_cashups.id');
            });
        }

        $hospitalityDateScope = $requestedBusinessMode === 'fast_food_boh';
        if ($hospitalityDateScope && Schema::hasColumn('pos_cashups', 'trading_date')) {
            $openTradingDaySource = $this->hospitalityOpenTradingDaySource();
            foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
                if (!$request->filled($field)) {
                    continue;
                }

                $date = $request->input($field);
                $query->where(function ($dateQuery) use ($operator, $date, $openTradingDaySource) {
                    $dateQuery->whereDate('pos_cashups.trading_date', $operator, $date);

                    if ($openTradingDaySource !== null) {
                        $dateQuery->orWhere(function ($openShiftQuery) use ($operator, $date, $openTradingDaySource) {
                            $openShiftQuery
                                ->whereNull('pos_cashups.id')
                                ->whereExists(function ($tradingDayQuery) use ($operator, $date, $openTradingDaySource) {
                                    $alias = 'shift_report_trading_days';
                                    $tradingDayQuery
                                        ->selectRaw('1')
                                        ->from($openTradingDaySource['table'] . ' as ' . $alias)
                                        ->whereColumn($alias . '.company_id', 'pos_user_shift_sessions.company_id')
                                        ->whereColumn($alias . '.branch_id', 'pos_user_shift_sessions.branch_id')
                                        ->whereIn($alias . '.status', $openTradingDaySource['statuses'])
                                        ->whereDate($alias . '.trading_date', $operator, $date);
                                });
                        });
                    }
                });
            }
        } else {
            $query
                ->when($request->filled('date_from'), fn ($q) => $q->whereDate('pos_user_shift_sessions.opened_at', '>=', $request->date_from))
                ->when($request->filled('date_to'), fn ($q) => $q->whereDate('pos_user_shift_sessions.opened_at', '<=', $request->date_to));
        }

        if (!empty($requestedCurrencyCodes) && Schema::hasColumn('pos_cashups', 'cashup_currency_code')) {
            $query->where(function ($currencyQuery) use ($requestedCurrencyCodes) {
                $currencyQuery
                    ->whereIn('pos_cashups.cashup_currency_code', $requestedCurrencyCodes)
                    ->orWhere('pos_cashups.cashup_currency_code', 'MULTI')
                    ->orWhereNull('pos_cashups.cashup_currency_code');
            });
        }

        $hasTillTable = DB::getSchemaBuilder()->hasTable('pos_tills');
        $shiftHasTillId = DB::getSchemaBuilder()->hasColumn('pos_user_shift_sessions', 'till_id');
        $resolvedTillExpression = $shiftHasTillId
            ? 'pos_user_shift_sessions.till_id'
            : "COALESCE(
                pos_cashups.till_id,
                (SELECT shift_report_sales_till.till_id
                 FROM sales_transactions shift_report_sales_till
                 WHERE shift_report_sales_till.company_id = pos_user_shift_sessions.company_id
                   AND shift_report_sales_till.branch_id = pos_user_shift_sessions.branch_id
                   AND shift_report_sales_till.shift_session_id = pos_user_shift_sessions.id
                   AND shift_report_sales_till.till_id IS NOT NULL
                 ORDER BY shift_report_sales_till.created_at DESC
                 LIMIT 1),
                (SELECT shift_report_payment_till.till_id
                 FROM sales_transaction_payments shift_report_payment_till
                 WHERE shift_report_payment_till.company_id = pos_user_shift_sessions.company_id
                   AND shift_report_payment_till.branch_id = pos_user_shift_sessions.branch_id
                   AND shift_report_payment_till.shift_session_id = pos_user_shift_sessions.id
                   AND shift_report_payment_till.till_id IS NOT NULL
                 ORDER BY shift_report_payment_till.created_at DESC
                 LIMIT 1)
            )";
        $hasTillJoin = $hasTillTable;
        if ($hasTillJoin) {
            $query->leftJoin('pos_tills', function ($join) use ($resolvedTillExpression) {
                $join->on('pos_tills.id', '=', DB::raw($resolvedTillExpression));
            });
        }

        $openTradingDateExpression = $this->hospitalityOpenTradingDateExpression(
            'shift_report_open_days',
            'pos_user_shift_sessions.company_id',
            'pos_user_shift_sessions.branch_id'
        );
        $cashupTradingDateExpression = Schema::hasColumn('pos_cashups', 'trading_date')
            ? 'pos_cashups.trading_date'
            : 'NULL';

        $records = $query->select([
            'pos_user_shift_sessions.*',
            DB::raw('COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name'),
            DB::raw('COALESCE(shift_closed_users.name, shift_closed_users.username, "-") as closed_by_name'),
            DB::raw($hasTillJoin ? 'COALESCE(pos_tills.till_name, pos_tills.till_code, "Unknown Till") as till_name' : '"" as till_name'),
            DB::raw($hasTillJoin ? 'pos_tills.till_code as till_code' : '"" as till_code'),
            DB::raw("{$resolvedTillExpression} as resolved_till_id"),
            DB::raw('pos_cashups.id as cashup_id'),
            DB::raw('pos_cashups.closed_at as cashup_closed_at'),
            DB::raw('pos_cashups.expected_total as cashup_expected_total'),
            DB::raw('pos_cashups.counted_total as cashup_counted_total'),
            DB::raw('pos_cashups.total_variance as cashup_total_variance'),
            DB::raw('pos_cashups.expected_cash as cashup_expected_cash'),
            DB::raw('pos_cashups.counted_cash as cashup_counted_cash'),
            DB::raw('pos_cashups.cash_variance as cashup_cash_variance'),
            DB::raw('pos_cashups.cashout_total as cashup_cashout_total'),
            DB::raw('pos_cashups.currency_summary as cashup_currency_summary'),
            DB::raw((Schema::hasColumn('pos_cashups', 'payment_summary') ? 'pos_cashups.payment_summary' : 'NULL') . ' as cashup_payment_summary'),
            DB::raw((Schema::hasColumn('pos_cashups', 'sales_summary') ? 'pos_cashups.sales_summary' : 'NULL') . ' as cashup_sales_summary'),
            DB::raw((Schema::hasColumn('pos_cashups', 'business_mode') ? 'pos_cashups.business_mode' : 'NULL') . ' as cashup_business_mode'),
            DB::raw("COALESCE({$cashupTradingDateExpression}, {$openTradingDateExpression}) as cashup_trading_date"),
            DB::raw((Schema::hasColumn('pos_cashups', 'cashup_currency_code') ? 'pos_cashups.cashup_currency_code' : 'NULL') . ' as cashup_currency_code'),
        ])
            ->orderByDesc('pos_user_shift_sessions.opened_at')
            ->limit((int) $request->input('export_limit', 10000))
            ->get();

        $openShiftIds = $records
            ->filter(fn ($row) => empty($row->cashup_id))
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();
        $liveOpenShiftPayments = collect();
        if ($openShiftIds->isNotEmpty() && Schema::hasTable('sales_transaction_payments')) {
            $accountMoneyCodes = ['customer_credit', 'credit_customer', 'credit_account', 'account'];
            $liveOpenShiftPayments = DB::table('sales_transaction_payments')
                ->selectRaw('shift_session_id, payment_name, money_code, currency_code, base_currency_code, COUNT(*) as payment_count, SUM(amount) as expected_amount, SUM(base_amount) as expected_base_amount')
                ->where('company_id', $company->id)
                ->whereIn('shift_session_id', $openShiftIds->all())
                ->when(!empty($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when(!empty($requestedCurrencyCodes), fn ($q) => $q->whereIn('currency_code', $requestedCurrencyCodes))
                ->whereNotIn(DB::raw("LOWER(COALESCE(money_code, ''))"), $accountMoneyCodes)
                ->groupBy('shift_session_id', 'payment_name', 'money_code', 'currency_code', 'base_currency_code')
                ->orderBy('payment_name')
                ->orderBy('currency_code')
                ->get()
                ->groupBy('shift_session_id')
                ->map(fn ($rows) => $rows->map(fn ($payment) => [
                    'payment_name' => $payment->payment_name,
                    'money_code' => $payment->money_code,
                    'currency_code' => strtoupper((string) $payment->currency_code),
                    'base_currency_code' => strtoupper((string) $payment->base_currency_code),
                    'payment_count' => (int) $payment->payment_count,
                    'expected_amount' => round((float) $payment->expected_amount, 4),
                    'counted_amount' => null,
                    'variance' => null,
                    'expected_base_amount' => round((float) $payment->expected_base_amount, 4),
                    'counted_base_amount' => null,
                    'base_variance' => null,
                ])->values()->all());
        }

        $liveOpenShiftCredit = collect();
        if (
            $openShiftIds->isNotEmpty()
            && Schema::hasTable('sales_transactions')
            && Schema::hasColumn('sales_transactions', 'is_credit_sale')
        ) {
            $creditAmountExpression = Schema::hasColumn('sales_transactions', 'credit_amount')
                ? 'COALESCE(credit_amount, total_amount, 0)'
                : 'COALESCE(total_amount, 0)';
            $creditPaidExpression = Schema::hasColumn('sales_transactions', 'credit_paid_amount')
                ? 'COALESCE(credit_paid_amount, 0)'
                : '0';
            $creditBalanceExpression = Schema::hasColumn('sales_transactions', 'credit_balance_amount')
                ? "COALESCE(credit_balance_amount, {$creditAmountExpression})"
                : $creditAmountExpression;

            $liveOpenShiftCredit = DB::table('sales_transactions')
                ->selectRaw("shift_session_id, currency_code,
                    COUNT(*) as credit_sale_count,
                    SUM({$creditAmountExpression}) as credit_sales_total,
                    SUM({$creditPaidExpression}) as credit_paid_total,
                    SUM({$creditBalanceExpression}) as credit_balance_total")
                ->where('company_id', $company->id)
                ->whereIn('shift_session_id', $openShiftIds->all())
                ->when(!empty($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when(!empty($requestedCurrencyCodes), fn ($q) => $q->whereIn('currency_code', $requestedCurrencyCodes))
                ->where('is_credit_sale', true)
                ->where('status', 'completed')
                ->whereNull('deleted_at')
                ->groupBy('shift_session_id', 'currency_code')
                ->get()
                ->groupBy('shift_session_id')
                ->map(function ($rows) {
                    $salesByCurrency = $rows->map(fn ($credit) => [
                        'currency_code' => strtoupper((string) ($credit->currency_code ?: 'USD')),
                        'credit_sale_count' => (int) $credit->credit_sale_count,
                        'credit_sales_total' => round((float) $credit->credit_sales_total, 4),
                        'credit_paid_total' => round((float) $credit->credit_paid_total, 4),
                        'credit_balance_total' => round((float) $credit->credit_balance_total, 4),
                    ])->sortBy('currency_code')->values();
                    $isMultiCurrency = $salesByCurrency->count() > 1;

                    return [
                        'credit_sale_count' => (int) $salesByCurrency->sum('credit_sale_count'),
                        'is_multi_currency' => $isMultiCurrency,
                        'credit_sales_total' => $isMultiCurrency ? null : round((float) $salesByCurrency->sum('credit_sales_total'), 4),
                        'credit_paid_total' => $isMultiCurrency ? null : round((float) $salesByCurrency->sum('credit_paid_total'), 4),
                        'credit_balance_total' => $isMultiCurrency ? null : round((float) $salesByCurrency->sum('credit_balance_total'), 4),
                        'sales_by_currency' => $salesByCurrency->all(),
                        'credit_sale_tender_rows' => [],
                    ];
                });
        }

        $records->transform(function ($row) use (
            $requestedCurrencyCodes,
            $requestedBusinessMode,
            $liveOpenShiftPayments,
            $liveOpenShiftCredit
        ) {
            if (($row->cashup_business_mode ?? null) === null && $requestedBusinessMode !== '') {
                $row->cashup_business_mode = $requestedBusinessMode;
            }
            $row->cashup_currency_summary = collect($this->toArrayValue($row->cashup_currency_summary ?? []))
                ->when(!empty($requestedCurrencyCodes), fn ($rows) => $rows->filter(fn ($currency) => in_array(
                    strtoupper((string) ($currency['currency_code'] ?? '')),
                    $requestedCurrencyCodes,
                    true
                )))
                ->values()
                ->all();
            $row->cashup_payment_summary = collect($this->toArrayValue($row->cashup_payment_summary ?? []))
                ->when(!empty($requestedCurrencyCodes), fn ($rows) => $rows->filter(fn ($payment) => in_array(
                    strtoupper((string) ($payment['currency_code'] ?? '')),
                    $requestedCurrencyCodes,
                    true
                )))
                ->values()
                ->all();
            if (empty($row->cashup_payment_summary) && empty($row->cashup_id)) {
                $row->cashup_payment_summary = $liveOpenShiftPayments->get((string) $row->id, []);
            }
            $salesSummary = $this->filterSalesSummaryCreditCurrencies(
                $this->toArrayValue($row->cashup_sales_summary ?? []),
                $requestedCurrencyCodes
            );
            if (empty($row->cashup_id) && $liveOpenShiftCredit->has((string) $row->id)) {
                $salesSummary['credit'] = $liveOpenShiftCredit->get((string) $row->id);
            }
            $row->cashup_sales_summary = $salesSummary;
            $isMultiCurrency = strtoupper((string) ($row->cashup_currency_code ?? '')) === 'MULTI'
                || collect($this->toArrayValue($row->cashup_currency_summary ?? []))
                    ->pluck('currency_code')
                    ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                    ->filter()
                    ->unique()
                    ->count() > 1
                || collect($row->cashup_payment_summary)
                    ->pluck('currency_code')
                    ->map(fn ($currencyCode) => strtoupper((string) $currencyCode))
                    ->filter()
                    ->unique()
                    ->count() > 1;

            if ($isMultiCurrency) {
                $row->cashup_expected_total = null;
                $row->cashup_counted_total = null;
                $row->cashup_total_variance = null;
                $row->cashup_expected_cash = null;
                $row->cashup_counted_cash = null;
                $row->cashup_cash_variance = null;
                $row->cashup_cashout_total = null;
            }

            $row->cashup_is_multi_currency = $isMultiCurrency;

            return $row;
        });

        if (!empty($requestedCurrencyCodes)) {
            $records = $records
                ->filter(fn ($row) => empty($row->cashup_id)
                    || !empty($row->cashup_payment_summary)
                    || !empty($row->cashup_currency_summary))
                ->values();
        }

        $paymentMethodTables = $this->paymentMethodReportTables(
            $records,
            $canViewFinancialAmounts,
            'shift'
        );
        $accountCreditTables = $this->accountCreditReportTables(
            $records,
            $canViewFinancialAmounts,
            'shift'
        );

        if (!$canViewFinancialAmounts) {
            $headers = ['Opened At', 'Closed At', 'Cashier', 'Closed By', 'Shift', 'Status', 'Currencies', 'Cashup Closed At'];
            $rows = $records->map(fn ($row) => [
                $this->dateTimeText($row->opened_at),
                $this->dateTimeText($row->closed_at),
                $row->cashier_name,
                $row->closed_by_name,
                $row->shift_name ?: '-',
                $row->status,
                $this->currencyListText($row->cashup_currency_summary ?? []),
                $this->dateTimeText($row->cashup_closed_at),
            ])->values()->all();
            $shiftCurrencyDetailRows = $records->flatMap(function ($row) {
                return collect($this->toArrayValue($row->cashup_currency_summary ?? []))->map(fn ($currency) => [
                    $this->dateTimeText($row->opened_at),
                    $this->dateTimeText($row->closed_at),
                    $row->cashier_name ?: 'Unknown',
                    $row->shift_name ?: '-',
                    $row->status,
                    $currency['currency_code'] ?? 'N/A',
                    (int) ($currency['payment_count'] ?? 0),
                ]);
            })->values()->all();
            $currencyRows = $records->flatMap(function ($row) {
                return collect($this->toArrayValue($row->cashup_currency_summary ?? []))->map(fn ($currency) => [
                    'currency_code' => $currency['currency_code'] ?? 'N/A',
                    'payment_count' => (int) ($currency['payment_count'] ?? 0),
                ]);
            })->groupBy('currency_code')->map(fn ($items, $currencyCode) => [
                $currencyCode,
                $items->count(),
                (int) $items->sum('payment_count'),
            ])->values()->all();
            $cashierRows = $records->flatMap(function ($row) {
                return collect($this->toArrayValue($row->cashup_currency_summary ?? []))->map(fn ($currency) => [
                    'cashier_name' => $row->cashier_name ?: 'Unknown',
                    'currency_code' => $currency['currency_code'] ?? 'N/A',
                ]);
            })->groupBy(fn ($row) => $row['cashier_name'] . '|' . $row['currency_code'])
                ->map(function ($items, $key) {
                    [$cashier, $currencyCode] = explode('|', $key . '|');
                    return [$cashier, $currencyCode, $items->count()];
                })->values()->all();
            $summary = [
                'shift_count' => $records->count(),
                'open_shift_count' => $records->where('status', 'open')->count(),
                'closed_shift_count' => $records->where('status', 'closed')->count(),
            ];

            return [
                'permissions' => $this->cashupFinancialVisibility->permissionFlags(false),
                'records' => $this->cashupFinancialVisibility->redact($records)->values()->all(),
                'summary' => $summary,
                'summary_rows' => array_merge($this->summaryRows($summary), [['Financial Amounts', 'Restricted by role permission']]),
                'headers' => $headers,
                'rows' => $rows,
                'shift_currency_headers' => ['Opened At', 'Closed At', 'Cashier', 'Shift', 'Status', 'Currency', 'Payment Count'],
                'shift_currency_detail_rows' => $shiftCurrencyDetailRows,
                'currency_headers' => ['Currency', 'Shifts', 'Payment Count'],
                'currency_rows' => $currencyRows,
                'cashier_headers' => ['Cashier', 'Currency', 'Shifts'],
                'cashier_rows' => $cashierRows,
                'payment_method_headers' => $paymentMethodTables['headers'],
                'payment_method_rows' => $paymentMethodTables['rows'],
                'payment_method_summary_headers' => $paymentMethodTables['summary_headers'],
                'payment_method_summary_rows' => $paymentMethodTables['summary_rows'],
                'account_credit_headers' => $accountCreditTables['headers'],
                'account_credit_rows' => $accountCreditTables['rows'],
                'account_credit_summary_headers' => $accountCreditTables['summary_headers'],
                'account_credit_summary_rows' => $accountCreditTables['summary_rows'],
            ];
        }

        $headers = ['Opened At', 'Closed At', 'Cashier', 'Closed By', 'Shift', 'Status', 'Currencies', 'Opening Float', 'Closing Cash', 'Cashup Closed At', 'Expected Total', 'Counted Total', 'Variance', 'Cashouts'];
        $rows = $records->map(fn ($row) => [
            $this->dateTimeText($row->opened_at),
            $this->dateTimeText($row->closed_at),
            $row->cashier_name,
            $row->closed_by_name,
            $row->shift_name ?: '-',
            $row->status,
            $this->currencyListText($row->cashup_currency_summary ?? []),
            $this->amount($row->opening_float),
            $this->nullableAmount($row->closing_cash),
            $this->dateTimeText($row->cashup_closed_at),
            $row->cashup_is_multi_currency ? 'By currency' : $this->nullableAmount($row->cashup_expected_total),
            $row->cashup_is_multi_currency ? 'By currency' : $this->nullableAmount($row->cashup_counted_total),
            $row->cashup_is_multi_currency ? 'By currency' : $this->nullableAmount($row->cashup_total_variance),
            $row->cashup_is_multi_currency ? 'By currency' : $this->amount($row->cashup_cashout_total),
        ])->values()->all();

        $shiftCurrencyDetailRows = $records->flatMap(function ($row) {
            return collect($this->toArrayValue($row->cashup_currency_summary ?? []))->map(fn ($currency) => [
                $this->dateTimeText($row->opened_at),
                $this->dateTimeText($row->closed_at),
                $row->cashier_name ?: 'Unknown',
                $row->shift_name ?: '-',
                $row->status,
                $currency['currency_code'] ?? 'N/A',
                (int) ($currency['payment_count'] ?? 0),
                $this->nullableAmount($currency['expected_amount'] ?? null),
                $this->nullableAmount($currency['counted_amount'] ?? null),
                $this->nullableAmount($currency['variance'] ?? null),
            ]);
        })->values()->all();

        $currencyRows = $records->flatMap(function ($row) {
            return collect($this->toArrayValue($row->cashup_currency_summary ?? []))->map(fn ($currency) => [
                'currency_code' => $currency['currency_code'] ?? 'N/A',
                'payment_count' => (int) ($currency['payment_count'] ?? 0),
                'expected_amount' => isset($currency['expected_amount']) ? (float) $currency['expected_amount'] : null,
                'counted_amount' => isset($currency['counted_amount']) ? (float) $currency['counted_amount'] : null,
                'variance' => isset($currency['variance']) ? (float) $currency['variance'] : null,
            ]);
        })->groupBy('currency_code')->map(function ($rows, $currencyCode) {
            return [
                $currencyCode,
                $rows->count(),
                (int) $rows->sum('payment_count'),
                $rows->every(fn ($row) => $row['expected_amount'] !== null) ? $this->amount($rows->sum('expected_amount')) : null,
                $rows->every(fn ($row) => $row['counted_amount'] !== null) ? $this->amount($rows->sum('counted_amount')) : null,
                $rows->every(fn ($row) => $row['variance'] !== null) ? $this->amount($rows->sum('variance')) : null,
            ];
        })->values()->all();

        $cashierRows = $records->flatMap(function ($row) {
            return collect($this->toArrayValue($row->cashup_currency_summary ?? []))->map(fn ($currency) => [
                'cashier_name' => $row->cashier_name ?: 'Unknown',
                'currency_code' => $currency['currency_code'] ?? 'N/A',
                'expected_amount' => isset($currency['expected_amount']) ? (float) $currency['expected_amount'] : null,
                'counted_amount' => isset($currency['counted_amount']) ? (float) $currency['counted_amount'] : null,
                'variance' => isset($currency['variance']) ? (float) $currency['variance'] : null,
            ]);
        })->groupBy(fn ($row) => $row['cashier_name'] . '|' . $row['currency_code'])
            ->map(function ($rows, $key) {
                [$cashier, $currencyCode] = explode('|', $key . '|');
                return [
                    $cashier,
                    $currencyCode,
                    $rows->count(),
                    $rows->every(fn ($row) => $row['expected_amount'] !== null) ? $this->amount($rows->sum('expected_amount')) : null,
                    $rows->every(fn ($row) => $row['counted_amount'] !== null) ? $this->amount($rows->sum('counted_amount')) : null,
                    $rows->every(fn ($row) => $row['variance'] !== null) ? $this->amount($rows->sum('variance')) : null,
                ];
            })->values()->all();

        $summary = [
            'shift_count' => $records->count(),
            'open_shift_count' => $records->where('status', 'open')->count(),
            'closed_shift_count' => $records->where('status', 'closed')->count(),
        ];

        return [
            'permissions' => $this->cashupFinancialVisibility->permissionFlags(true),
            'records' => $records->values()->all(),
            'summary' => $summary,
            'summary_rows' => $this->summaryRows($summary),
            'headers' => $headers,
            'rows' => $rows,
            'shift_currency_headers' => ['Opened At', 'Closed At', 'Cashier', 'Shift', 'Status', 'Currency', 'Payment Count', 'Expected Total', 'Counted Total', 'Variance'],
            'shift_currency_detail_rows' => $shiftCurrencyDetailRows,
            'currency_headers' => ['Currency', 'Shifts', 'Payment Count', 'Expected Total', 'Counted Total', 'Variance'],
            'currency_rows' => $currencyRows,
            'cashier_headers' => ['Cashier', 'Currency', 'Shifts', 'Expected Total', 'Counted Total', 'Variance'],
            'cashier_rows' => $cashierRows,
            'payment_method_headers' => $paymentMethodTables['headers'],
            'payment_method_rows' => $paymentMethodTables['rows'],
            'payment_method_summary_headers' => $paymentMethodTables['summary_headers'],
            'payment_method_summary_rows' => $paymentMethodTables['summary_rows'],
            'account_credit_headers' => $accountCreditTables['headers'],
            'account_credit_rows' => $accountCreditTables['rows'],
            'account_credit_summary_headers' => $accountCreditTables['summary_headers'],
            'account_credit_summary_rows' => $accountCreditTables['summary_rows'],
        ];
    }

    private function summaryRows(array $summary): array
    {
        return collect($summary)->map(fn ($value, $key) => [str_replace('_', ' ', ucwords((string) $key, '_')), $value])->values()->all();
    }

    /**
     * Build the stable payment-method tables shared by the JSON reports and by
     * the backend PDF/XLSX generators.  Each row stays at its native currency;
     * unlike-currency tender values are never added into a headline scalar.
     *
     * @param  iterable<int, mixed>  $records
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, summary_headers: array<int, string>, summary_rows: array<int, array<int, mixed>>}
     */
    private function paymentMethodReportTables(iterable $records, bool $canViewFinancialAmounts, string $context): array
    {
        $isShiftContext = $context === 'shift';
        $headers = [
            'Business Mode',
            'Business / Shift Date',
            'Cashup ID',
            'Shift ID',
            'Opened At',
            'Closed At',
            'Cashier',
            'Till',
            'Status',
            'Payment Method',
            'Money Code',
            'Currency',
            'Payment Count',
            'Expected Amount',
            'Counted Amount',
            'Variance',
        ];

        $entries = collect($records)->flatMap(function ($record) use ($isShiftContext) {
            $businessMode = strtolower((string) data_get(
                $record,
                $isShiftContext ? 'cashup_business_mode' : 'business_mode',
                data_get($record, 'cashup_business_mode', '')
            ));
            $businessDate = $businessMode === 'fast_food_boh'
                ? $this->dateText(data_get($record, 'cashup_trading_date', data_get($record, 'trading_date')))
                : $this->dateText(data_get(
                    $record,
                    $isShiftContext ? 'opened_at' : 'cashup_bracket_date',
                    data_get($record, 'cashup_opened_at', data_get($record, 'opened_at'))
                ));
            $cashupId = (string) data_get($record, $isShiftContext ? 'cashup_id' : 'id', '');
            $shiftId = (string) data_get($record, $isShiftContext ? 'id' : 'shift_session_id', '');
            $sourceKey = $isShiftContext ? ($shiftId ?: $cashupId) : ($cashupId ?: $shiftId);
            $tillName = trim((string) data_get($record, 'till_code', ''));
            $tillLabel = trim(($tillName !== '' ? $tillName . ' - ' : '') . (string) data_get($record, 'till_name', ''));

            return $this->normalizedPaymentMethodRows($record)->map(function (array $payment) use (
                $record,
                $businessDate,
                $cashupId,
                $shiftId,
                $sourceKey,
                $tillLabel,
                $isShiftContext
            ) {
                return array_merge($payment, [
                    'source_key' => $sourceKey,
                    'business_mode_label' => $this->cashupBusinessModeLabel($record),
                    'business_date' => $businessDate,
                    'cashup_id' => $cashupId,
                    'shift_id' => $shiftId,
                    'opened_at' => $this->dateTimeText(data_get(
                        $record,
                        $isShiftContext ? 'opened_at' : 'cashup_opened_at',
                        data_get($record, 'opened_at')
                    )),
                    'closed_at' => $this->dateTimeText(data_get(
                        $record,
                        $isShiftContext ? 'closed_at' : 'cashup_closed_at',
                        data_get($record, 'closed_at')
                    )),
                    'cashier' => (string) data_get($record, 'cashier_name', 'Unknown'),
                    'till' => $tillLabel !== '' ? $tillLabel : '-',
                    'status' => (string) data_get($record, 'status', '-'),
                ]);
            });
        })->sortBy([
            ['business_date', 'desc'],
            ['cashier', 'asc'],
            ['payment_name', 'asc'],
            ['currency_code', 'asc'],
        ])->values();

        $rows = $entries->map(function (array $entry) use ($canViewFinancialAmounts) {
            return [
                $entry['business_mode_label'],
                $entry['business_date'],
                $entry['cashup_id'] ?: '-',
                $entry['shift_id'] ?: '-',
                $entry['opened_at'],
                $entry['closed_at'],
                $entry['cashier'] ?: 'Unknown',
                $entry['till'],
                $entry['status'] ?: '-',
                $entry['payment_name'],
                $entry['money_code'] ?: '-',
                $entry['currency_code'],
                $entry['payment_count'],
                $canViewFinancialAmounts && $entry['expected_amount'] !== null ? $this->amount($entry['expected_amount']) : null,
                $canViewFinancialAmounts && $entry['counted_amount'] !== null ? $this->amount($entry['counted_amount']) : null,
                $canViewFinancialAmounts && $entry['variance'] !== null ? $this->amount($entry['variance']) : null,
            ];
        })->all();

        $summaryHeaders = [
            'Payment Method',
            'Money Code',
            'Currency',
            $isShiftContext ? 'Shifts' : 'Cashups',
            'Payment Count',
            'Expected Amount',
            'Counted Amount',
            'Variance',
        ];
        $summaryRows = $entries
            ->groupBy(fn (array $entry) => implode('|', [
                strtolower($entry['payment_name']),
                strtolower($entry['money_code']),
                strtoupper($entry['currency_code']),
            ]))
            ->map(function ($methodRows) use ($canViewFinancialAmounts) {
                $first = $methodRows->first();
                $expectedAvailable = $methodRows->every(fn (array $row) => $row['expected_amount'] !== null);
                $countedAvailable = $methodRows->every(fn (array $row) => $row['counted_amount'] !== null);
                $varianceAvailable = $methodRows->every(fn (array $row) => $row['variance'] !== null);

                return [
                    $first['payment_name'],
                    $first['money_code'] ?: '-',
                    $first['currency_code'],
                    $methodRows->pluck('source_key')->filter()->unique()->count(),
                    (int) $methodRows->sum('payment_count'),
                    $canViewFinancialAmounts && $expectedAvailable
                        ? $this->amount($methodRows->sum(fn (array $row) => (float) ($row['expected_amount'] ?? 0)))
                        : null,
                    $canViewFinancialAmounts && $countedAvailable
                        ? $this->amount($methodRows->sum(fn (array $row) => (float) ($row['counted_amount'] ?? 0)))
                        : null,
                    $canViewFinancialAmounts && $varianceAvailable
                        ? $this->amount($methodRows->sum(fn (array $row) => (float) ($row['variance'] ?? 0)))
                        : null,
                ];
            })
            ->sortBy(fn (array $row) => strtolower((string) $row[0]) . '|' . (string) $row[2])
            ->values()
            ->all();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'summary_headers' => $summaryHeaders,
            'summary_rows' => $summaryRows,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function normalizedPaymentMethodRows($record)
    {
        $paymentSummary = $this->toArrayValue(data_get($record, 'payment_summary'));
        if (empty($paymentSummary)) {
            $paymentSummary = $this->toArrayValue(data_get($record, 'cashup_payment_summary'));
        }
        if (empty($paymentSummary)) {
            $salesSummary = $this->toArrayValue(data_get($record, 'sales_summary'));
            if (empty($salesSummary)) {
                $salesSummary = $this->toArrayValue(data_get($record, 'cashup_sales_summary'));
            }
            $paymentSummary = $this->toArrayValue($salesSummary['payments'] ?? []);
        }

        return collect($paymentSummary)
            ->map(function ($row) {
                $row = $this->toArrayValue($row);
                $moneyCode = trim((string) ($row['money_code'] ?? ''));
                $paymentName = trim((string) ($row['payment_name'] ?? ''));
                if ($paymentName === '') {
                    $paymentName = $moneyCode !== '' ? Str::headline($moneyCode) : 'Unknown Tender';
                }

                $hasExpected = (array_key_exists('expected_amount', $row) && $row['expected_amount'] !== null)
                    || (array_key_exists('amount', $row) && $row['amount'] !== null);
                $hasCounted = array_key_exists('counted_amount', $row) && $row['counted_amount'] !== null;
                $hasVariance = array_key_exists('variance', $row) && $row['variance'] !== null;

                return [
                    'payment_name' => $paymentName,
                    'money_code' => $moneyCode,
                    'currency_code' => strtoupper(trim((string) ($row['currency_code'] ?? ''))) ?: 'N/A',
                    'payment_count' => (int) ($row['payment_count'] ?? 0),
                    'expected_amount' => $hasExpected
                        ? round((float) ($row['expected_amount'] ?? $row['amount'] ?? 0), 4)
                        : null,
                    'counted_amount' => $hasCounted ? round((float) $row['counted_amount'], 4) : null,
                    'variance' => $hasVariance ? round((float) $row['variance'], 4) : null,
                ];
            })
            ->filter(fn (array $row) => $row['payment_name'] !== '' && $row['currency_code'] !== '')
            ->sortBy([
                ['payment_name', 'asc'],
                ['currency_code', 'asc'],
            ])
            ->values();
    }

    /**
     * Customer-account credit issued by a sale is debt, not received tender.
     * Keep it visible in reports without contaminating payment variance.
     *
     * @param  iterable<int, mixed>  $records
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, summary_headers: array<int, string>, summary_rows: array<int, array<int, mixed>>}
     */
    private function accountCreditReportTables(iterable $records, bool $canViewFinancialAmounts, string $context): array
    {
        $isShiftContext = $context === 'shift';
        $headers = [
            'Business Mode',
            'Business / Shift Date',
            'Cashup ID',
            'Shift ID',
            'Cashier',
            'Till',
            'Account Credit Method',
            'Money Code',
            'Currency',
            'Credit Sale Count',
            'Credit Issued',
        ];

        $entries = collect($records)->flatMap(function ($record) use ($isShiftContext) {
            $salesSummary = $this->toArrayValue(data_get($record, 'sales_summary'));
            if (empty($salesSummary)) {
                $salesSummary = $this->toArrayValue(data_get($record, 'cashup_sales_summary'));
            }
            $credit = $this->toArrayValue($salesSummary['credit'] ?? []);
            $creditRows = collect($this->toArrayValue($credit['credit_sale_tender_rows'] ?? []));

            if ($creditRows->isEmpty()) {
                $creditRows = collect($this->toArrayValue($credit['sales_by_currency'] ?? []))
                    ->map(fn ($row) => [
                        'payment_name' => 'Customer Account',
                        'money_code' => 'account',
                        'currency_code' => $row['currency_code'] ?? 'N/A',
                        'payment_count' => (int) ($row['credit_sale_count'] ?? 0),
                        'amount' => (float) ($row['credit_sales_total'] ?? 0),
                    ]);
            }

            $businessMode = strtolower((string) data_get(
                $record,
                $isShiftContext ? 'cashup_business_mode' : 'business_mode',
                data_get($record, 'cashup_business_mode', '')
            ));
            $tradingDate = data_get($record, 'cashup_trading_date') ?: data_get($record, 'trading_date');
            $businessDate = $businessMode === 'fast_food_boh'
                ? $this->dateText($tradingDate)
                : $this->dateText(
                    data_get($record, $isShiftContext ? 'opened_at' : 'cashup_bracket_date')
                    ?: data_get($record, 'cashup_opened_at')
                    ?: data_get($record, 'opened_at')
                );
            $cashupId = (string) data_get($record, $isShiftContext ? 'cashup_id' : 'id', '');
            $shiftId = (string) data_get($record, $isShiftContext ? 'id' : 'shift_session_id', '');
            $sourceKey = $isShiftContext ? ($shiftId ?: $cashupId) : ($cashupId ?: $shiftId);
            $tillCode = trim((string) data_get($record, 'till_code', ''));
            $till = trim(($tillCode !== '' ? $tillCode . ' - ' : '') . (string) data_get($record, 'till_name', ''));

            return $creditRows->map(function ($row) use (
                $record,
                $businessDate,
                $cashupId,
                $shiftId,
                $sourceKey,
                $till
            ) {
                $row = $this->toArrayValue($row);

                return [
                    'source_key' => $sourceKey,
                    'business_mode_label' => $this->cashupBusinessModeLabel($record),
                    'business_date' => $businessDate,
                    'cashup_id' => $cashupId,
                    'shift_id' => $shiftId,
                    'cashier' => (string) data_get($record, 'cashier_name', 'Unknown'),
                    'till' => $till !== '' ? $till : '-',
                    'payment_name' => trim((string) ($row['payment_name'] ?? '')) ?: 'Customer Account',
                    'money_code' => trim((string) ($row['money_code'] ?? '')) ?: 'account',
                    'currency_code' => strtoupper(trim((string) ($row['currency_code'] ?? ''))) ?: 'N/A',
                    'payment_count' => (int) ($row['payment_count'] ?? $row['credit_sale_count'] ?? 0),
                    'amount' => round((float) ($row['amount'] ?? $row['credit_sales_total'] ?? 0), 4),
                ];
            });
        })->filter(fn (array $row) => $row['payment_count'] > 0 || abs($row['amount']) > 0.000001)
            ->sortBy([
                ['business_date', 'desc'],
                ['payment_name', 'asc'],
                ['currency_code', 'asc'],
            ])
            ->values();

        $rows = $entries->map(fn (array $row) => [
            $row['business_mode_label'],
            $row['business_date'],
            $row['cashup_id'] ?: '-',
            $row['shift_id'] ?: '-',
            $row['cashier'] ?: 'Unknown',
            $row['till'],
            $row['payment_name'],
            $row['money_code'],
            $row['currency_code'],
            $row['payment_count'],
            $canViewFinancialAmounts ? $this->amount($row['amount']) : null,
        ])->all();

        $summaryHeaders = [
            'Account Credit Method',
            'Money Code',
            'Currency',
            $isShiftContext ? 'Shifts' : 'Cashups',
            'Credit Sale Count',
            'Credit Issued',
        ];
        $summaryRows = $entries
            ->groupBy(fn (array $row) => implode('|', [
                strtolower($row['payment_name']),
                strtolower($row['money_code']),
                $row['currency_code'],
            ]))
            ->map(function ($creditRows) use ($canViewFinancialAmounts) {
                $first = $creditRows->first();

                return [
                    $first['payment_name'],
                    $first['money_code'],
                    $first['currency_code'],
                    $creditRows->pluck('source_key')->filter()->unique()->count(),
                    (int) $creditRows->sum('payment_count'),
                    $canViewFinancialAmounts ? $this->amount($creditRows->sum('amount')) : null,
                ];
            })
            ->sortBy(fn (array $row) => strtolower((string) $row[0]) . '|' . (string) $row[2])
            ->values()
            ->all();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'summary_headers' => $summaryHeaders,
            'summary_rows' => $summaryRows,
        ];
    }


    private function currencyListText($value): string
    {
        $codes = collect($this->toArrayValue($value))
            ->map(fn ($row) => strtoupper((string) ($row['currency_code'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return empty($codes) ? '-' : implode(', ', $codes);
    }

    private function amount($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function nullableAmount($value): ?string
    {
        return $value === null ? null : $this->amount($value);
    }

    private function dateText($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private function dateTimeText($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

}
