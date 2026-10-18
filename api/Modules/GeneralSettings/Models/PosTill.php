<?php

namespace Modules\GeneralSettings\Models;

use App\Models\SyncNode;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Brand;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\ComplexLocation;
use Modules\Company\Models\Country;
use Modules\Company\Models\Shop;

// use Modules\GeneralSettings\Database\Factories\PosTillFactory;

class PosTill extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'pos_tills';

    protected $fillable = [
        'company_id',
        'country_id',
        'brand_id',
        'complex_id',
        'branch_id',
        'shop_id',
        'till_code',
        'till_name',
        'device_label',
        'registration_token',
        'activation_code',
        'activation_code_expires_at',
        'is_registered',
        'is_active',
        'registered_by',
        'registered_at',
        'last_seen_at',
        'last_ip_address',
        'last_user_agent',
        'configuration_version',
    ];

    protected $casts = [
        'is_registered' => 'boolean',
        'is_active' => 'boolean',
        'activation_code_expires_at' => 'datetime',
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'configuration_version' => 'integer',
    ];

    protected $hidden = [
        'registration_token',
        'activation_code',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function complex()
    {
        return $this->belongsTo(ComplexLocation::class, 'complex_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(PosUser::class, 'registered_by');
    }

    public function syncNodes()
    {
        return $this->hasMany(SyncNode::class, 'till_id');
    }

    /**
     * Persist operational presence without advancing the configuration
     * timestamp. Eloquent's update/saveQuietly paths both maintain
     * updated_at, so every caller must use the base query builder here.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordPresence(
        array $attributes,
        ?DateTimeInterface $onlyIfLastSeenBefore = null
    ): bool {
        $attributes = array_intersect_key($attributes, array_flip([
            'last_seen_at',
            'last_ip_address',
            'last_user_agent',
        ]));
        if ($attributes === []) {
            return false;
        }

        $query = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->where('company_id', $this->getAttribute('company_id'))
            ->where('branch_id', $this->getAttribute('branch_id'))
            ->where('is_active', true)
            ->whereNull($this->getDeletedAtColumn());

        if ($onlyIfLastSeenBefore) {
            $query->where(function ($query) use ($onlyIfLastSeenBefore): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $onlyIfLastSeenBefore);
            });
        }

        $updated = $query->update($attributes) > 0;
        if ($updated) {
            $this->forceFill($attributes);
            $this->syncOriginalAttributes(array_keys($attributes));
        }

        return $updated;
    }
}
