<x-layouts.auth title="Status Akun" heading="">
    <section aria-labelledby="account-status-title" class="space-y-6">
        <div class="flex items-start gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-danger/10 text-danger">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>
            <div>
                <h1 id="account-status-title" class="text-xl font-semibold text-ink">Akun Tidak Dapat Digunakan</h1>
                <p class="mt-1 text-sm leading-5 text-muted">
                    Akses ke fitur SIMPEG sementara dibatasi sampai data akun dapat diverifikasi.
                </p>
            </div>
        </div>

        <x-ui.alert variant="danger" title="Akses dibatasi">
            Status akun Anda sedang <strong class="font-semibold text-danger">nonaktif atau belum dapat diverifikasi</strong>.
            Seluruh fitur aplikasi tidak dapat diakses sampai status akun diperbaiki oleh Admin Kepegawaian.
        </x-ui.alert>

        @if (filled($statusNote ?? null))
            <div class="border-t border-border pt-5">
                <h2 class="text-sm font-semibold text-ink">Pesan dari Admin</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ $statusNote }}</p>
            </div>
        @endif

        <div class="border-t border-border pt-5">
            <p class="text-sm leading-5 text-muted">
                Silakan hubungi Admin Kepegawaian untuk informasi lebih lanjut atau pembaruan status akun.
            </p>

            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <x-ui.button type="submit" variant="primary" full-width>
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3-3H9m0 0 3-3m-3 3 3 3" />
                    </svg>
                    Keluar dari akun
                </x-ui.button>
            </form>
        </div>
    </section>
</x-layouts.auth>
