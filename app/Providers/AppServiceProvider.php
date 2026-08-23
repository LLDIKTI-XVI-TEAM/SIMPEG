<?php

namespace App\Providers;

use App\Services\TransactionSideEffectManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Keycloak\KeycloakExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu request harus berbagi daftar kompensasi yang sama antara middleware dan Action.
        $this->app->singleton(TransactionSideEffectManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            SocialiteWasCalled::class,
            KeycloakExtendSocialite::class.'@handle',
        );

        // Mendaftarkan custom permission agar @can() pada Blade dapat membaca hasPermission()
        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($ability)) {
                return true;
            }
        });
    }
}
