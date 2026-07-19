<x-layouts.app title="Laporan & Statistik">
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Laporan & Statistik</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Laporan & Statistik'],
            ]" />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {{-- Laporan Cuti --}}
            <a href="{{ route('pimpinan.laporan.cuti') }}"
               class="group flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 shadow-sm transition hover:border-primary/40 hover:shadow-md">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary transition group-hover:bg-primary group-hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-ink group-hover:text-primary">Laporan Cuti</p>
                    <p class="mt-0.5 text-xs text-muted">Rekap pengajuan dan persetujuan cuti pegawai</p>
                </div>
            </a>

            {{-- Laporan Kepangkatan --}}
            <a href="{{ route('pimpinan.laporan.kepangkatan') }}"
               class="group flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 shadow-sm transition hover:border-primary/40 hover:shadow-md">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-secondary/10 text-secondary transition group-hover:bg-secondary group-hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-ink group-hover:text-secondary">Laporan Kepangkatan</p>
                    <p class="mt-0.5 text-xs text-muted">Riwayat dan perkembangan pangkat/golongan pegawai</p>
                </div>
            </a>

            {{-- Laporan Pegawai Custom --}}
            <a href="{{ route('pimpinan.laporan.pegawai') }}"
               class="group flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 shadow-sm transition hover:border-primary/40 hover:shadow-md">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-success/10 text-success transition group-hover:bg-success group-hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-ink group-hover:text-success">Laporan Pegawai</p>
                    <p class="mt-0.5 text-xs text-muted">Unduh data pegawai dengan kolom dan filter pilihan</p>
                </div>
            </a>
        </div>
    </div>
</x-layouts.app>
