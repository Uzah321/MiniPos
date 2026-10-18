<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Product\Database\Factories\RolePriceLevelFactory;

class RolePriceLevel extends Model
{
    use HasUuids;

    protected $table = 'role_price_level';

    protected $fillable = [
        'company_id',
        'role_id',
        'price_level_id',
    ];

    public function priceLevel()
    {
        return $this->belongsTo(PriceLevel::class);
    }
}
