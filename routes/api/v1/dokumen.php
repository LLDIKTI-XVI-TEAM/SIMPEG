<?php

use App\Http\Controllers\Api\V1\DokumenApiController;
use Illuminate\Support\Facades\Route;

$disableEmployeeApiAuth = app()->environment('local')
    && config('services.simpeg.disable_employee_api_auth');

$dokumenGroupMiddleware = $disableEmployeeApiAuth
    ? []
    : ['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'];

Route::middleware($dokumenGroupMiddleware)
    ->prefix('dokumen')
    ->name('dokumen.')
    ->group(function (): void {
        Route::get('/', [DokumenApiController::class, 'index'])
            ->name('index');
    });
