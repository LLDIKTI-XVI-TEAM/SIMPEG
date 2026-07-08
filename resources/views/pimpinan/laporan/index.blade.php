<x-layouts.app title="EWS Pimpinan">


<div class="max-w-7xl mx-auto px-6 py-6 space-y-6">

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

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        
        <x-ui.card class="hover:border-primary/50 transition-colors">
            <div class="flex items-start gap-4">
                <div class="rounded-lg bg-primary/10 p-3 text-primary shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-ink">Laporan Data Pegawai</h3>
                    <p class="mt-1 text-sm text-muted">Export data lengkap pegawai, distribusi unit kerja, dan demografi.</p>
                    <div class="mt-4">
                        <a href="{{ route('pimpinan.laporan.pegawai') }}" class="text-sm font-semibold text-primary hover:underline">Buka Laporan &rarr;</a>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="hover:border-primary/50 transition-colors">
            <div class="flex items-start gap-4">
                <div class="rounded-lg bg-primary/10 p-3 text-primary shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-ink">Rekapitulasi Cuti</h3>
                    <p class="mt-1 text-sm text-muted">Laporan cuti tahunan, cuti sakit, dan persentase kehadiran.</p>
                    <div class="mt-4">
                        <a href="{{ route('pimpinan.laporan.cuti') }}" class="text-sm font-semibold text-primary hover:underline">Buka Laporan &rarr;</a>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="hover:border-primary/50 transition-colors">
            <div class="flex items-start gap-4">
                <div class="rounded-lg bg-primary/10 p-3 text-primary shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-ink">Riwayat Kepangkatan</h3>
                    <p class="mt-1 text-sm text-muted">Progress usulan kenaikan pangkat dan KGB seluruh pegawai.</p>
                    <div class="mt-4">
                        <a href="{{ route('pimpinan.laporan.kepangkatan') }}" class="text-sm font-semibold text-primary hover:underline">Buka Laporan &rarr;</a>
                    </div>
                </div>
            </div>
        </x-ui.card>

    </div>

</div>
</x-layouts.app>
