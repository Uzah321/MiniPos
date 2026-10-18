<?php

namespace Modules\Currency\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;



// use Modules\Currency\Database\Factories\CurrencyFactory;

class Currency extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'code',
        'name',
        'country',
        'is_active',
        'is_base_currency',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_base_currency' => 'boolean',
    ];

    public function rates()
    {
        return $this->hasMany(CurrencyRate::class);
    }

    public function currencyRate(): HasOne
    {
        // A new row is inserted whenever a rate changes.  Consumers using the
        // legacy currencyRate relation must therefore receive the newest row,
        // otherwise new sales can continue using the very first rate forever.
        return $this->hasOne(CurrencyRate::class, 'currency_id', 'id')->latestOfMany();
    }

    public function latestRate(): HasOne
    {
        return $this->hasOne(CurrencyRate::class)->latestOfMany();
    }

    /**
     * Limit currency configuration to the requested branch and its company-wide
     * fallback. Branch configuration wins when both rows exist.
     */
    public function scopeForBranchOrGlobal(Builder $query, ?string $branchId): Builder
    {
        if (!$branchId) {
            // Legacy callers do not supply a branch. Keep them operational,
            // while deterministically preferring company-global configuration.
            return $query->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END');
        }

        return $query
            ->where(function (Builder $branchQuery) use ($branchId) {
                $branchQuery->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId]);
    }

    /**
     * Resolve the newest rate inside the same branch/global precedence as the
     * currency row. Rates belonging to another branch are never candidates.
     */
    public function latestRateForBranch(?string $branchId): ?CurrencyRate
    {
        $query = $this->rates()
            ->where('company_id', $this->company_id);

        if ($branchId) {
            $query
                ->where(function (Builder $branchQuery) use ($branchId) {
                    $branchQuery->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                })
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId]);
        } else {
            $query->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class);
    }
}
