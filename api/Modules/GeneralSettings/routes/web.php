<?php

use Illuminate\Support\Facades\Route;
use Modules\GeneralSettings\Http\Controllers\GeneralSettingsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('generalsettings', GeneralSettingsController::class)->names('generalsettings');
});
