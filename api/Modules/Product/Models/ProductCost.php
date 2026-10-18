<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

// use Modules\Product\Database\Factories\ProductCostFactory;

class ProductCost extends Model
{
    use HasUuids;

    protected $table = 'product_costs';

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'average_cost',
        'last_cost',
        'total_quantity',
        'total_value',
        'currency',
        'currency_code',
    ];

    protected $casts = [
        'average_cost' => 'decimal:4',
        'last_cost' => 'decimal:4',
        'total_quantity' => 'decimal:4',
        'total_value' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Keep cost writes operational while older installations still expose the
     * legacy `currency` column. The repair migration adds `currency_code`, but
     * deployments and local workspaces must remain usable before it is run.
     */
    public function setCurrencyCodeAttribute(mixed $value): void
    {
        $this->writeCurrencyAttribute($value);
    }

    public function getCurrencyCodeAttribute(mixed $value): mixed
    {
        return $value ?: ($this->attributes['currency'] ?? null);
    }

    public function setCurrencyAttribute(mixed $value): void
    {
        $this->writeCurrencyAttribute($value);
    }

    public function getCurrencyAttribute(mixed $value): mixed
    {
        return $value ?: ($this->attributes['currency_code'] ?? null);
    }

    private function writeCurrencyAttribute(mixed $value): void
    {
        if (Schema::hasColumn($this->getTable(), 'currency_code')) {
            $this->attributes['currency_code'] = $value;
        }

        if (Schema::hasColumn($this->getTable(), 'currency')) {
            $this->attributes['currency'] = $value;
        }
    }
}
