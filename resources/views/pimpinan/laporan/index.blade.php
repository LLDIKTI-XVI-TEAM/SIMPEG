<x-layouts.app title="Laporan & Statistik">


<div class="space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Laporan & Statistik</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Laporan']
            ]" />
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 items-stretch">
        
        <x-ui.card class="flex flex-col justify-between h-full hover:bg-soft transition-colors">
            <div class="flex gap-4">
                <div class="shrink-0">
                    <div class="rounded-lg bg-primary/10 p-3 text-primary">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-ink">Laporan Data Pegawai</h3>
                    <p class="mt-1 text-sm text-muted">Export data lengkap pegawai, distribusi unit kerja, dan demografi.</p>
                </div>
            </div>
            <div class="flex gap-4 pt-4">
                <div class="w-12 shrink-0"></div>
                <div>
                    <x-ui.button href="{{ route('pimpinan.laporan.pegawai') }}" variant="primary" size="sm">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3h5.25m-5.25 3h5.25" /></svg>
                        Buka Laporan
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="flex flex-col justify-between h-full hover:bg-soft transition-colors">
            <div class="flex gap-4">
                <div class="shrink-0">
                    <div class="rounded-lg bg-primary/10 p-3 text-primary">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-ink">Rekapitulasi Cuti</h3>
                    <p class="mt-1 text-sm text-muted">Laporan cuti tahunan, cuti sakit, dan persentase kehadiran.</p>
                </div>
            </div>
            <div class="flex gap-4 pt-4">
                <div class="w-12 shrink-0"></div>
                <div>
                    <x-ui.button href="{{ route('pimpinan.laporan.cuti') }}" variant="primary" size="sm">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H18v-.008Zm0 2.25h.008v.008H18V15Z" /></svg>
                        Buka Laporan
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="flex flex-col justify-between h-full hover:bg-soft transition-colors">
            <div class="flex gap-4">
                <div class="shrink-0">
                    <div class="rounded-lg bg-primary/10 p-3 text-primary">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-ink">Riwayat Kepangkatan</h3>
                    <p class="mt-1 text-sm text-muted">Progress usulan kenaikan pangkat dan KGB seluruh pegawai.</p>
                </div>
            </div>
            <div class="flex gap-4 pt-4">
                <div class="w-12 shrink-0"></div>
                <div>
                    <x-ui.button href="{{ route('pimpinan.laporan.kepangkatan') }}" variant="primary" size="sm">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9ZM9.75 14.25v3.75m3-6v6m3-3v3" /></svg>
                        Buka Laporan
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

    </div>

</div>
</x-layouts.app>
