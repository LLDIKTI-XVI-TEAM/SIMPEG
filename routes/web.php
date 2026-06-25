<?php

use App\Http\Controllers\Auth\KeycloakAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('/login', [KeycloakAuthController::class, 'redirectToKeycloak'])->name('login');
Route::get('/auth/keycloak/callback', [KeycloakAuthController::class, 'handleCallback'])->name('auth.keycloak.callback');
Route::post('/logout', [KeycloakAuthController::class, 'logout'])->name('logout');

Route::middleware(['keycloak.auth', 'role:super_admin,admin_kepegawaian,pimpinan,atasan_langsung,pegawai'])->group(function (): void {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

});
