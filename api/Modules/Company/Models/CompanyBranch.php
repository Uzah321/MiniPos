<?php

namespace Modules\Company\Models;

use App\Models\SyncChange;
use App\Models\SyncNode;
use App\Models\SyncOutboxEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Company\Database\Factories\CompanyBranchFactory;

class CompanyBranch extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_name',
        'branch_code',
        'branch_address',
        'branch_city',
        'branch_country',
        'level',
        'is_main_branch',
        'status',
    ];

    protected static function booted(): void
    {
        static::created(function (CompanyBranch $branch): void {
            app(\App\Services\Enterprise\EnterpriseHierarchyService::class)
                ->ensureShopForBranch($branch);
        });

        static::deleted(function (CompanyBranch $branch): void {
            app(\App\Services\Enterprise\EnterpriseHierarchyService::class)
                ->retireShopForBranch($branch);
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function shop()
    {
        return $this->hasOne(Shop::class, 'branch_id');
    }

    public function contacts()
    {
        return $this->hasMany(CompanyBranchContact::class, 'branch_id');
    }

    public function syncNodes()
    {
        return $this->hasMany(SyncNode::class, 'branch_id');
    }

    public function syncOutboxEvents()
    {
        return $this->hasMany(SyncOutboxEvent::class, 'branch_id');
    }

    public function syncChanges()
    {
        return $this->hasMany(SyncChange::class, 'branch_id');
    }
}
