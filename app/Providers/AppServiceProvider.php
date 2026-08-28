<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Notifications\WhatsApp\UnavailableWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use App\Services\Rbac\UiPermissionCapabilityService;
use App\Services\TransactionSideEffectManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;
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

        // Adapter default sengaja fail-closed sampai kontrak provider WhatsApp terverifikasi.
        $this->app->singleton(WhatsAppTemplateAdapter::class, UnavailableWhatsAppTemplateAdapter::class);

        // Cache capability dibatasi pada lifecycle request agar perubahan RBAC pada request berikutnya langsung berlaku.
        $this->app->scoped(UiPermissionCapabilityService::class);
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

        View::composer('components.layouts.app', function (ViewInstance $view): void {
            $authenticated = auth()->user();
            $actor = $authenticated instanceof User ? $authenticated : null;
            $permissionNames = in_array($actor?->role, ['super_admin', 'admin_kepegawaian'], true)
                ? ['employees.restore', 'cuti.balance.reconcile', 'cuti.manual.manage']
                : [];

            $view->with(
                'layoutCapabilities',
                app(UiPermissionCapabilityService::class)->resolve($actor, $permissionNames),
            );
        });
    }
}
