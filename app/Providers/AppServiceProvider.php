<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Notifications\WhatsApp\QontakWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use App\Services\Rbac\UiPermissionCapabilityService;
use App\Services\TransactionSideEffectManager;
use Illuminate\Pagination\Paginator;
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

        // Adapter Qontak tersedia sebagai capability, tetapi dispatcher tetap fail-closed
        // sampai readiness, kill-switch channel, dan kebijakan event seluruhnya aktif.
        $this->app->singleton(WhatsAppTemplateAdapter::class, QontakWhatsAppTemplateAdapter::class);

        // Memo per proses hanya berlaku untuk artefak non-rahasia. Snapshot provider
        // dibaca langsung agar rotasi/clear utuh terlihat oleh worker tanpa TTL.
        $this->app->singleton(WhatsAppRuntimeConfig::class);
        $this->app->bind(
            WhatsAppRuntimeConfiguration::class,
            fn (): WhatsAppRuntimeConfig => $this->app->make(WhatsAppRuntimeConfig::class),
        );

        // Cache capability dibatasi pada lifecycle request agar perubahan RBAC pada request berikutnya langsung berlaku.
        $this->app->scoped(UiPermissionCapabilityService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.simpeg');

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
            $permissionNames = ['employees.read', 'cuti.balance.reconcile', 'cuti.manual.manage', 'audit_logs.read'];

            if (in_array($actor?->role, ['super_admin', 'admin_kepegawaian'], true)) {
                $permissionNames = [
                    ...$permissionNames,
                    'cuti.cancellation.manage',
                ];
            }

            $view->with(
                'layoutCapabilities',
                app(UiPermissionCapabilityService::class)->resolve($actor, $permissionNames),
            );
        });
    }
}
