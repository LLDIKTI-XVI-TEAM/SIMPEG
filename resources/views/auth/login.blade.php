<x-layouts.auth title="Masuk" heading="Sistem Informasi Manajemen Kepegawaian">

    <div class="text-center">
        <h2 class="text-xl font-semibold text-ink" style="text-wrap: balance">Selamat Datang</h2>
        <p class="mt-2 text-sm text-muted" style="text-wrap: pretty">
            Masuk menggunakan akun SSO LLDIKTI Wilayah XVI Anda untuk mengakses sistem.
        </p>
    </div>

    @if(session('auth_error'))
        <div class="mt-4 flex items-start gap-3 rounded-lg border border-danger/20 bg-danger/10 px-4 py-3">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm font-medium text-danger">{{ session('auth_error') }}</p>
        </div>
    @endif

    <div class="mt-6">
        <a
            href="{{ route('auth.keycloak.redirect') }}"
            id="sso-login-btn"
            class="flex w-full items-center justify-center gap-3 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
            Masuk dengan SSO LLDIKTI
        </a>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <div class="flex-1 border-t border-border"></div>
        <p class="text-xs text-muted">atau</p>
        <div class="flex-1 border-t border-border"></div>
    </div>

    <div class="mt-6 rounded-lg bg-soft px-4 py-4">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-xs font-semibold text-ink">Informasi Login</p>
                <p class="mt-0.5 text-xs text-muted" style="text-wrap: pretty">
                    Gunakan akun yang telah terdaftar di Portal SSO LLDIKTI XVI. Hubungi administrator jika mengalami kendala.
                </p>
            </div>
        </div>
    </div>

</x-layouts.auth>
