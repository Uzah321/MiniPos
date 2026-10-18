<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

class PosUserShiftAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'pos_user_id',
        'shift_configuration_id',
        'shift_name',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    public function shiftConfiguration()
    {
        return $this->belongsTo(ShiftConfiguration::class, 'shift_configuration_id');
    }
}
