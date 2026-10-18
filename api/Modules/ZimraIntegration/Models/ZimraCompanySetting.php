<?php

namespace Modules\ZimraIntegration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ZimraCompanySetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'enabled',
        'environment',
        'base_url',
        'auto_open_day',
        'block_sales_without_open_day',
        'block_sale_if_fiscalisation_fails',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_open_day' => 'boolean',
        'block_sales_without_open_day' => 'boolean',
        'block_sale_if_fiscalisation_fails' => 'boolean',
        'metadata' => 'array',
    ];
}
