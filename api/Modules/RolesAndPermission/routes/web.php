<?php

use Illuminate\Support\Facades\Route;
use Modules\RolesAndPermission\Http\Controllers\RolesAndPermissionController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('rolesandpermissions', RolesAndPermissionController::class)->names('rolesandpermission');
});
