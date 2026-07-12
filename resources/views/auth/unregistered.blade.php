<x-layouts.auth title="Akun Belum Terdaftar">

    <div class="text-center">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-warning/10">
            <svg class="h-7 w-7 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h2 class="text-xl font-semibold text-ink" style="text-wrap: balance">Akun Belum Terdaftar</h2>
        <p class="mt-2 text-sm text-muted" style="text-wrap: pretty">
            {{ $message ?? 'Akun Anda belum terdaftar di sistem SIMPEG. Hubungi administrator untuk mendapatkan akses.' }}
        </p>
    </div>

    <div class="mt-6 rounded-lg bg-soft px-4 py-4">
        <p class="text-xs text-muted text-center" style="text-wrap: pretty">
            Sudah mendapatkan akses? Coba login kembali atau hubungi tim IT LLDIKTI Wilayah XVI.
        </p>
    </div>

    <div class="mt-4">
        <a
            href="{{ route('logout.get') }}"
            id="retry-login-btn"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90"
        >
            Coba Login Kembali
        </a>
        
        @if(app()->environment('local'))
        <a
            href="{{ route('dev-login') }}"
            class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-secondary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90"
        >
            Gunakan Demo Login (Lokal)
        </a>
        @endif
    </div>

</x-layouts.auth>
