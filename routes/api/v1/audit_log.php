<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit Logs — permission efektif dan scope target tetap wajib
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'keycloak.auth', 'session.timeout', 'permission:audit_logs.read'])
    ->get('/audit-log', [AuditLogController::class, 'index'])
    ->name('audit-log.index');
