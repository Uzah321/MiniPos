<?php

namespace Modules\GeneralSettings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\GeneralSettings\Database\Factories\PosCashDrawerMovementFactory;

class PosCashDrawerMovement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): PosCashDrawerMovementFactory
    // {
    //     // return PosCashDrawerMovementFactory::new();
    // }
}
