<?php

use Illuminate\Support\Facades\Route;
use OTGH\AccessControl\SerialWiegandAdapter\Http\Controllers\Admin\Health\SerialDevicesController;

Route::middleware(['web', 'auth'])
    ->prefix('admin/health')
    ->group(function (): void {
        Route::get('/serial-devices', SerialDevicesController::class)
            ->name('admin.serial-devices');
    });
