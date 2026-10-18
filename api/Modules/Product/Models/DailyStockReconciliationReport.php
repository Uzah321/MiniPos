<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStockReconciliationReport extends Model
{
    protected $table = 'daily_stock_reconciliation_reports';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','company_id','branch_id','trading_day_id','trading_date','status','generated_by',
        'generated_at','finalized_at','currency_code','total_revenue','total_recipe_cost',
        'total_variance_value','gross_profit','metadata'
    ];

    protected $casts = [
        'trading_date' => 'date',
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines()
    {
        return $this->hasMany(DailyStockReconciliationLine::class, 'report_id');
    }

    public function getTotalRevenueAttribute(mixed $value): mixed
    {
        return $this->headlineValue($value, 'headline_sales_totals_available');
    }

    public function getTotalRecipeCostAttribute(mixed $value): mixed
    {
        return $this->headlineValue($value, 'headline_sales_totals_available');
    }

    public function getGrossProfitAttribute(mixed $value): mixed
    {
        return $this->headlineValue($value, 'headline_sales_totals_available');
    }

    public function getTotalVarianceValueAttribute(mixed $value): mixed
    {
        return $this->headlineValue($value, 'headline_variance_total_available');
    }

    /**
     * The physical columns predate multi-currency DSR and are non-nullable.
     * Their zero placeholder must never be serialized as a real accounting
     * total when metadata says unlike currencies cannot be aggregated.
     */
    private function headlineValue(mixed $storedValue, string $availabilityKey): mixed
    {
        $rawMetadata = $this->getAttributeFromArray('metadata');
        $metadata = is_array($rawMetadata)
            ? $rawMetadata
            : (json_decode((string) ($rawMetadata ?? ''), true) ?: []);
        $summary = is_array($metadata['summary'] ?? null) ? $metadata['summary'] : [];

        if (array_key_exists($availabilityKey, $summary) && $summary[$availabilityKey] === false) {
            return null;
        }

        return $storedValue;
    }
}
