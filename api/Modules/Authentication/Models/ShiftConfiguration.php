<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

// use Modules\Authentication\Database\Factories\ShiftconfigurationFactory;

class ShiftConfiguration extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
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
    ];

    protected $casts = [
        'enable_shifts' => 'boolean',
        'auto_shift_logout' => 'boolean',
        'auto_logout_after_minutes' => 'integer',
        'require_opening_float' => 'boolean',
        'require_closing_cashup' => 'boolean',
        'allow_multiple_open_shifts' => 'boolean',
        'allow_unscheduled_shifts' => 'boolean',
        'early_clock_in_minutes' => 'integer',
        'shift_warning_before_end_minutes' => 'integer',
        'shift_grace_minutes_after_end' => 'integer',
        'allow_continue_unclosed_shift' => 'boolean',
        'require_cashup_before_new_shift' => 'boolean',
        'block_sales_after_shift_end' => 'boolean',
        'allow_supervisor_cashup' => 'boolean',
        'require_on_behalf_reason' => 'boolean',
    ];

    protected $table = 'shiftconfigurations';

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    public function userAssignments()
    {
        return $this->hasMany(PosUserShiftAssignment::class, 'shift_configuration_id');
    }
}
