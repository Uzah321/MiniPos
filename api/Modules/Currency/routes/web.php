<?php

use Illuminate\Support\Facades\Route;
use Modules\Currency\Http\Controllers\CurrencyController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('currencies', CurrencyController::class)->names('currency');
});
