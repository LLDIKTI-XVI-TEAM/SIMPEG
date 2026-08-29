<x-layouts.auth title="Akun Tidak Dapat Digunakan" heading="Akun Tidak Dapat Digunakan">
    <div class="min-h-screen flex items-center justify-center bg-surface p-6">
        <div class="w-full max-w-md rounded-2xl border border-danger/25 bg-white p-8 text-center shadow-lg">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-danger/10">
                <svg class="h-9 w-9 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>

            <h2 class="text-xl font-bold text-ink">Akun Tidak Dapat Digunakan</h2>

            <div class="mt-4 rounded-lg border border-danger/20 bg-danger/5 p-4">
                <p class="text-sm font-semibold text-danger uppercase tracking-wide">Perhatian</p>
                <p class="mt-1 text-sm text-muted">
                    Status akun Anda sedang <strong class="text-ink">nonaktif atau belum dapat diverifikasi</strong>.
                    Seluruh fitur aplikasi tidak dapat diakses sampai status akun diperbaiki oleh Admin Kepegawaian.
                </p>
            </div>

            @if (filled($statusNote ?? null))
                <div class="mt-3 rounded-lg border border-border bg-surface p-4 text-left">
                    <p class="text-xs font-bold text-ink uppercase tracking-wide">Pesan dari Admin</p>
                    <p class="mt-1 text-sm text-ink">{{ $statusNote }}</p>
                </div>
            @endif

            <p class="mt-5 text-xs text-muted">
                Silakan hubungi Admin Kepegawaian untuk informasi lebih lanjut.
            </p>

            <form method="POST" action="{{ route('logout') }}" class="mt-6">
                @csrf
                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans">
                    Keluar
                </button>
            </form>
        </div>
    </div>
</x-layouts.auth>
