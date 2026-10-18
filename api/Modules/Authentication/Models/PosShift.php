<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// use Modules\Authentication\Database\Factories\PosShiftFactory;

class PosShift extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'pos_user_id',
        'shift_name',
        'opened_at',
        'closed_at',
        'opening_float',
        'expected_cash',
        'actual_cash',
        'variance',
        'status',
        'opening_note',
        'closing_note',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class);
    }

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    /**
     * Scope: open shifts
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope: closed shifts
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }
}
