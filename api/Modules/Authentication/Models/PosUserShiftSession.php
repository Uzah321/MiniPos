<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// use Modules\Authentication\Database\Factories\PosUserShiftSessionFactory;

class PosUserShiftSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'till_id',
        'pos_user_id',
        'shift_configuration_id',
        'shift_name',
        'opened_at',
        'closed_at',
        'opening_float',
        'closing_cash',
        'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'closing_cash' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class);
    }

    public function shiftConfiguration()
    {
        return $this->belongsTo(ShiftConfiguration::class, 'shift_configuration_id');
    }
}
