<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// use Modules\Authentication\Database\Factories\UserBranchFactory;

class UserBranch extends Model
{
    use HasUuids;

    protected $table = 'user_branches';

    protected $fillable = [
        'company_id',
        'pos_user_id',
        'branch_id',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class);
    }

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class);
    }
}
