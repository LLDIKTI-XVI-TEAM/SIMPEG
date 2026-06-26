<?php

use App\Http\Controllers\HariLiburController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:super_admin'])
    ->prefix('hari-libur')
    ->name('hari-libur.')
    ->group(function (): void {
        Route::get('/', [HariLiburController::class, 'index'])
            ->middleware('permission:hari_libur.read')
            ->name('index');
        Route::post('/', [HariLiburController::class, 'store'])
            ->middleware('permission:hari_libur.create')
            ->name('store');
        Route::put('/{hariLibur}', [HariLiburController::class, 'update'])
            ->middleware('permission:hari_libur.update')
            ->name('update');
        Route::delete('/{hariLibur}', [HariLiburController::class, 'destroy'])
            ->middleware('permission:hari_libur.delete')
            ->name('destroy');
    });
