<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit Logs — hanya admin kepegawaian berizin audit
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian', 'permission:audit_logs.read'])
    ->get('/audit-log', [AuditLogController::class, 'index'])
    ->name('audit-log.index');
