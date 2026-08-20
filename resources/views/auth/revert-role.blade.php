<x-layouts.auth
    title="Pemulihan Role"
    heading="Pemulihan Mode Simulasi"
    description="Kembalikan role asli untuk memulihkan akses SIMPEG"
>
    <div class="space-y-6 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-warning/10" aria-hidden="true">
            <svg class="h-7 w-7 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
        </div>

        <div class="space-y-2">
            <h1 class="text-lg font-bold text-ink">Akses simulasi perlu dipulihkan</h1>
            <p class="text-sm leading-6 text-muted">
                Role sementara <strong class="font-semibold text-ink">{{ ucwords(str_replace('_', ' ', $temporaryRole)) }}</strong>
                tidak dapat digunakan untuk membuka halaman aplikasi saat ini.
            </p>
            <p class="text-sm leading-6 text-muted">
                Kembalikan role asli Anda untuk melanjutkan akses SIMPEG. Identitas akun dan data pegawai tidak berubah.
            </p>
        </div>

        <form method="POST" action="{{ route('revert-role') }}">
            @csrf
            <button
                type="submit"
                class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
            >
                Kembalikan Role Asli
            </button>
        </form>
    </div>
</x-layouts.auth>
