<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\ShiftConfiguration;
use function App\Helpers\hasPermission;

class ShiftConfigurationController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');

        $request->validate([
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
        ]);

        $result = hasPermission(auth()->user()->role_id, 'manage_shift_settings', $company->id);
        $canManageUsers = hasPermission(auth()->user()->role_id, 'manage_user', $company->id);
        if ($result !== true && $canManageUsers !== true) return $result;

        $configs = ShiftConfiguration::with(['company', 'branch'])
            ->where('company_id', $company->id)
            ->when($request->branch_id, function ($query) use ($request) {
                $query->where('branch_id', $request->branch_id);
            })
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Shift configurations fetched successfully',
            'data' => $configs,
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeTimeFields($request);

        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'manage_shift_settings', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],

            'enable_shifts' => 'nullable|boolean',
            'shift_mode' => 'required|string|in:manual,automatic,fixed_time',

            'morning_shift_start' => 'nullable|date_format:H:i',
            'morning_shift_end' => 'nullable|date_format:H:i',

            'afternoon_shift_start' => 'nullable|date_format:H:i',
            'afternoon_shift_end' => 'nullable|date_format:H:i',

            'night_shift_start' => 'nullable|date_format:H:i',
            'night_shift_end' => 'nullable|date_format:H:i',

            'auto_shift_logout' => 'nullable|boolean',
            'auto_logout_after_minutes' => 'nullable|integer|min:1',

            'require_opening_float' => 'nullable|boolean',
            'require_closing_cashup' => 'nullable|boolean',
            'allow_multiple_open_shifts' => 'nullable|boolean',
            'allow_unscheduled_shifts' => 'nullable|boolean',
            'early_clock_in_minutes' => 'nullable|integer|min:0|max:240',
            'shift_warning_before_end_minutes' => 'nullable|integer|min:0|max:240',
            'shift_grace_minutes_after_end' => 'nullable|integer|min:0|max:1440',
            'allow_continue_unclosed_shift' => 'nullable|boolean',
            'require_cashup_before_new_shift' => 'nullable|boolean',
            'block_sales_after_shift_end' => 'nullable|boolean',
            'allow_supervisor_cashup' => 'nullable|boolean',
            'require_on_behalf_reason' => 'nullable|boolean',

            'status' => 'nullable|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $exists = ShiftConfiguration::where('company_id', $company->id)
            ->where('branch_id', $request->branch_id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Shift configuration already exists for this company/branch',
            ], 409);
        }

        $config = ShiftConfiguration::create([
            'company_id' => $company->id,
            'branch_id' => $request->branch_id,

            'enable_shifts' => $request->enable_shifts ?? true,
            'shift_mode' => $request->shift_mode,

            'morning_shift_start' => $request->morning_shift_start,
            'morning_shift_end' => $request->morning_shift_end,

            'afternoon_shift_start' => $request->afternoon_shift_start,
            'afternoon_shift_end' => $request->afternoon_shift_end,

            'night_shift_start' => $request->night_shift_start,
            'night_shift_end' => $request->night_shift_end,

            'auto_shift_logout' => $request->auto_shift_logout ?? false,
            'auto_logout_after_minutes' => $request->auto_logout_after_minutes,

            'require_opening_float' => $request->require_opening_float ?? true,
            'require_closing_cashup' => $request->require_closing_cashup ?? true,
            'allow_multiple_open_shifts' => $request->allow_multiple_open_shifts ?? false,
            'allow_unscheduled_shifts' => $request->allow_unscheduled_shifts ?? true,
            'early_clock_in_minutes' => $request->early_clock_in_minutes ?? 15,
            'shift_warning_before_end_minutes' => $request->shift_warning_before_end_minutes ?? 15,
            'shift_grace_minutes_after_end' => $request->shift_grace_minutes_after_end ?? 10,
            'allow_continue_unclosed_shift' => $request->allow_continue_unclosed_shift ?? true,
            'require_cashup_before_new_shift' => $request->require_cashup_before_new_shift ?? true,
            'block_sales_after_shift_end' => $request->block_sales_after_shift_end ?? false,
            'allow_supervisor_cashup' => $request->allow_supervisor_cashup ?? true,
            'require_on_behalf_reason' => $request->require_on_behalf_reason ?? true,

            'status' => $request->status ?? 'active',
        ]);

        return response()->json([
            'message' => 'Shift configuration created successfully',
            'data' => $config,
        ], 201);
    }

    public function show(ShiftConfiguration $shiftConfiguration)
    {
        return response()->json([
            'message' => 'Shift configuration fetched successfully',
            'data' => $shiftConfiguration->load(['company', 'branch']),
        ]);
    }

    public function update(Request $request, ShiftConfiguration $shiftConfiguration)
    {
        $this->normalizeTimeFields($request);

        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'manage_shift_settings', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'enable_shifts' => 'nullable|boolean',
            'shift_mode' => 'nullable|string|in:manual,automatic,fixed_time',

            'morning_shift_start' => 'nullable|date_format:H:i',
            'morning_shift_end' => 'nullable|date_format:H:i',

            'afternoon_shift_start' => 'nullable|date_format:H:i',
            'afternoon_shift_end' => 'nullable|date_format:H:i',

            'night_shift_start' => 'nullable|date_format:H:i',
            'night_shift_end' => 'nullable|date_format:H:i',

            'auto_shift_logout' => 'nullable|boolean',
            'auto_logout_after_minutes' => 'nullable|integer|min:1',

            'require_opening_float' => 'nullable|boolean',
            'require_closing_cashup' => 'nullable|boolean',
            'allow_multiple_open_shifts' => 'nullable|boolean',
            'allow_unscheduled_shifts' => 'nullable|boolean',
            'early_clock_in_minutes' => 'nullable|integer|min:0|max:240',
            'shift_warning_before_end_minutes' => 'nullable|integer|min:0|max:240',
            'shift_grace_minutes_after_end' => 'nullable|integer|min:0|max:1440',
            'allow_continue_unclosed_shift' => 'nullable|boolean',
            'require_cashup_before_new_shift' => 'nullable|boolean',
            'block_sales_after_shift_end' => 'nullable|boolean',
            'allow_supervisor_cashup' => 'nullable|boolean',
            'require_on_behalf_reason' => 'nullable|boolean',

            'status' => 'nullable|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shiftConfiguration->update($request->only([
            'enable_shifts',
            'shift_mode',
            'morning_shift_start',
            'morning_shift_end',
            'afternoon_shift_start',
            'afternoon_shift_end',
            'night_shift_start',
            'night_shift_end',
            'auto_shift_logout',
            'auto_logout_after_minutes',
            'require_opening_float',
            'require_closing_cashup',
            'allow_multiple_open_shifts',
            'allow_unscheduled_shifts',
            'early_clock_in_minutes',
            'shift_warning_before_end_minutes',
            'shift_grace_minutes_after_end',
            'allow_continue_unclosed_shift',
            'require_cashup_before_new_shift',
            'block_sales_after_shift_end',
            'allow_supervisor_cashup',
            'require_on_behalf_reason',
            'status',
        ]));

        return response()->json([
            'message' => 'Shift configuration updated successfully',
            'data' => $shiftConfiguration->fresh(),
        ]);
    }

    public function destroy(ShiftConfiguration $shiftConfiguration)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'manage_shift_settings', $company->id);
        if ($result !== true) return $result;

        $shiftConfiguration->delete();

        return response()->json([
            'message' => 'Shift configuration deleted successfully',
        ]);
    }

    public function current(Request $request)
    {
        $company = app('company');

        $request->validate([
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
        ]);

        $config = ShiftConfiguration::where('company_id', $company->id)
            ->where('branch_id', $request->branch_id)
            ->where('status', 'active')
            ->first();

        if (!$config) {
            $config = ShiftConfiguration::where('company_id', $company->id)
                ->whereNull('branch_id')
                ->where('status', 'active')
                ->first();
        }

        if (!$config) {
            return response()->json([
                'message' => 'No shift configuration found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'message' => 'Current shift configuration fetched successfully',
            'data' => $config,
        ]);
    }

    public function detectCurrentShift(Request $request)
    {
        $company = app('company');

        $this->normalizeSingleTimeField($request, 'time');

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'time' => 'nullable|date_format:H:i',
            'shift_name' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $time = $request->time ?? now()->format('H:i');

        $config = ShiftConfiguration::where('company_id', $company->id)
            ->where('branch_id', $request->branch_id)
            ->where('status', 'active')
            ->first();

        if (!$config) {
            $config = ShiftConfiguration::where('company_id', $company->id)
                ->whereNull('branch_id')
                ->where('status', 'active')
                ->first();
        }

        if (!$config || !$config->enable_shifts) {
            return response()->json([
                'message' => 'Shifts are disabled',
                'shift' => null,
            ]);
        }

        $shift = $config->shift_mode === 'manual'
            ? (strtolower(trim((string) $request->shift_name)) ?: 'manual')
            : $this->resolveShiftName($config, $time);

        if (!$shift && $config->allow_unscheduled_shifts) {
            $shift = 'unscheduled';
        }

        return response()->json([
            'message' => 'Current shift detected successfully',
            'time' => $time,
            'shift' => $shift,
            'config' => $config,
        ]);
    }

    private function resolveShiftName(ShiftConfiguration $config, string $time): ?string
    {
        if ($this->isBetween($time, $config->morning_shift_start, $config->morning_shift_end)) {
            return 'morning';
        }

        if ($this->isBetween($time, $config->afternoon_shift_start, $config->afternoon_shift_end)) {
            return 'afternoon';
        }

        if ($this->isBetween($time, $config->night_shift_start, $config->night_shift_end)) {
            return 'night';
        }

        return null;
    }

    private function isBetween(?string $time, ?string $start, ?string $end): bool
    {
        if (!$time || !$start || !$end) {
            return false;
        }

        $time = substr($time, 0, 5);
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        if ($start <= $end) {
            return $time >= $start && $time <= $end;
        }

        return $time >= $start || $time <= $end;
    }

    private function normalizeTimeFields(Request $request): void
    {
        $fields = [
            'morning_shift_start',
            'morning_shift_end',
            'afternoon_shift_start',
            'afternoon_shift_end',
            'night_shift_start',
            'night_shift_end',
        ];

        foreach ($fields as $field) {
            $this->normalizeSingleTimeField($request, $field);
        }
    }

    private function normalizeSingleTimeField(Request $request, string $field): void
    {
        if (!$request->filled($field)) {
            return;
        }

        $value = $request->input($field);

        if (!is_string($value)) {
            return;
        }

        $value = trim($value);

        if ($value === '') {
            return;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            $request->merge([
                $field => $value,
            ]);

            return;
        }

        if (preg_match('/^\d{1}:\d{2}$/', $value)) {
            $request->merge([
                $field => '0' . $value,
            ]);

            return;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            $request->merge([
                $field => substr($value, 0, 5),
            ]);

            return;
        }

        if (preg_match('/^\d{1}:\d{2}:\d{2}$/', $value)) {
            $request->merge([
                $field => '0' . substr($value, 0, 4),
            ]);

            return;
        }

        try {
            $request->merge([
                $field => Carbon::parse($value)->format('H:i'),
            ]);
        } catch (\Exception $e) {
            // Leave original value so Laravel validator returns the correct error.
        }
    }
}
