<?php

use Illuminate\Support\Facades\Route;
use Modules\FiscalDevice\Http\Controllers\FiscalDeviceController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('fiscaldevices', FiscalDeviceController::class)->names('fiscaldevice');
});
