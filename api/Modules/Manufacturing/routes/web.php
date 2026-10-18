<?php

use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\ManufacturingController;

if ((bool) config('servnest.hospitality_only', true)) {
    return;
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('manufacturings', ManufacturingController::class)->names('manufacturing');
});
