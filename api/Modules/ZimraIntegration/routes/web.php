<?php

use Illuminate\Support\Facades\Route;
use Modules\ZimraIntegration\Http\Controllers\ZimraIntegrationController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('zimraintegrations', ZimraIntegrationController::class)->names('zimraintegration');
});
