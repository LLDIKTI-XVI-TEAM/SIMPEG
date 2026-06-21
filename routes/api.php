<?php

use Illuminate\Support\Facades\Route;

// Semua endpoint REST SIMPEG dipasang di bawah versi agar kontrak API bisa berkembang tanpa mengubah route web/SSO.
Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api_v1.php'));
