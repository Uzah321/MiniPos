<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosCashup extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'pos_cashups';

    protected $fillable = [
        'company_id',
        'branch_id',
        'till_id',
        'shift_configuration_id',
        'shift_session_id',
        'cashier_id',
        'closed_by',

        /*
         |--------------------------------------------------------------------------
         | Business Mode / Cashup Bracket
         |--------------------------------------------------------------------------
         | fast_food_boh:
         | - BOH enabled + Fast Food mode enabled.
         | - Cashup is controlled by trading_date.
         |
         | retail:
         | - BOH/Fast Food not enabled.
         | - Cashup is bracketed by shift/till/cashier/period so overnight shifts
         |   and same-day afternoon shifts do not mix in reports.
         */
        'business_mode',
        'boh_enabled',
        'fast_food_mode_enabled',
        'trading_date',
        'cashup_period_key',
        'cashup_period_label',
        'cashup_opened_at',
        'cashup_closed_at',

        /*
         |--------------------------------------------------------------------------
         | Currency Bracket
         |--------------------------------------------------------------------------
         | Cashup is currency based. currency_summary still stores all currency
         | details, but cashup_currency_code is the main report bracket.
         */
        'cashup_currency_code',
        'base_currency_code',

        /*
         |--------------------------------------------------------------------------
         | Cash Totals
         |--------------------------------------------------------------------------
         */
        'opening_float',
        'expected_cash',
        'counted_cash',
        'cash_variance',

        'cashout_total',
        'cashout_count',
        'expected_cash_after_cashouts',

        /*
         |--------------------------------------------------------------------------
         | Overall Totals
         |--------------------------------------------------------------------------
         */
        'expected_total',
        'counted_total',
        'total_variance',

        /*
         |--------------------------------------------------------------------------
         | Activity Counts
         |--------------------------------------------------------------------------
         */
        'transaction_count',
        'void_count',
        'refund_count',

        /*
         |--------------------------------------------------------------------------
         | Summaries / Snapshots
         |--------------------------------------------------------------------------
         */
        'payment_summary',
        'currency_summary',
        'sales_summary',
        'cashout_summary',
        'shift_policy_snapshot',
        'business_mode_snapshot',

        /*
         |--------------------------------------------------------------------------
         | Status / Notes
         |--------------------------------------------------------------------------
         */
        'status',
        'notes',
        'force_close_reason',

        /*
         |--------------------------------------------------------------------------
         | Shift Times
         |--------------------------------------------------------------------------
         */
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        /*
         |--------------------------------------------------------------------------
         | Business Mode / Bracket
         |--------------------------------------------------------------------------
         */
        'boh_enabled' => 'boolean',
        'fast_food_mode_enabled' => 'boolean',
        'trading_date' => 'date:Y-m-d',
        'cashup_opened_at' => 'datetime',
        'cashup_closed_at' => 'datetime',

        /*
         |--------------------------------------------------------------------------
         | Money
         |--------------------------------------------------------------------------
         */
        'opening_float' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'counted_cash' => 'decimal:4',
        'cash_variance' => 'decimal:4',

        'cashout_total' => 'decimal:4',
        'cashout_count' => 'integer',
        'expected_cash_after_cashouts' => 'decimal:4',

        'expected_total' => 'decimal:4',
        'counted_total' => 'decimal:4',
        'total_variance' => 'decimal:4',

        /*
         |--------------------------------------------------------------------------
         | Counts
         |--------------------------------------------------------------------------
         */
        'transaction_count' => 'integer',
        'void_count' => 'integer',
        'refund_count' => 'integer',

        /*
         |--------------------------------------------------------------------------
         | JSON Summaries
         |--------------------------------------------------------------------------
         */
        'payment_summary' => 'array',
        'currency_summary' => 'array',
        'sales_summary' => 'array',
        'cashout_summary' => 'array',
        'shift_policy_snapshot' => 'array',
        'business_mode_snapshot' => 'array',

        /*
         |--------------------------------------------------------------------------
         | Shift Times
         |--------------------------------------------------------------------------
         */
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function denominations()
    {
        return $this->hasMany(PosCashupDenomination::class, 'cashup_id');
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForBranch($query, ?string $branchId)
    {
        if (!$branchId) {
            return $query;
        }

        return $query->where('branch_id', $branchId);
    }

    public function scopeForTradingDate($query, ?string $tradingDate)
    {
        if (!$tradingDate) {
            return $query;
        }

        return $query->whereDate('trading_date', $tradingDate);
    }

    public function scopeForCurrency($query, ?string $currencyCode)
    {
        if (!$currencyCode) {
            return $query;
        }

        return $query->where('cashup_currency_code', strtoupper($currencyCode));
    }

    public function scopeForBusinessMode($query, ?string $businessMode)
    {
        if (!$businessMode) {
            return $query;
        }

        return $query->where('business_mode', $businessMode);
    }

    public function getIsFastFoodBohAttribute(): bool
    {
        return $this->business_mode === 'fast_food_boh'
            || ((bool) $this->boh_enabled && (bool) $this->fast_food_mode_enabled);
    }

    public function getIsRetailModeAttribute(): bool
    {
        return !$this->is_fast_food_boh;
    }

    public function getReportTradingDateAttribute(): ?string
    {
        if (!$this->trading_date) {
            return null;
        }

        return $this->trading_date->format('Y-m-d');
    }

    public function getDisplayCurrencyAttribute(): string
    {
        return strtoupper($this->cashup_currency_code ?: $this->base_currency_code ?: 'USD');
    }

    public function getDisplayPeriodAttribute(): string
    {
        if (!empty($this->cashup_period_label)) {
            return $this->cashup_period_label;
        }

        if (!empty($this->cashup_period_key)) {
            return $this->cashup_period_key;
        }

        if ($this->opened_at && $this->closed_at) {
            return $this->opened_at->format('Y-m-d H:i') . ' - ' . $this->closed_at->format('Y-m-d H:i');
        }

        return '-';
    }
}
