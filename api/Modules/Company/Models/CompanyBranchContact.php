<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Company\Database\Factories\CompanyBranchContactFactory;

class CompanyBranchContact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'contact_name',
        'email',
        'phone',
        'position',
    ];

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
