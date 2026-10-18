<?php

use Illuminate\Support\Facades\Route;
use Modules\FoodManufacturing\Http\Controllers\FoodManufacturingController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('foodmanufacturings', FoodManufacturingController::class)->names('foodmanufacturing');
});
