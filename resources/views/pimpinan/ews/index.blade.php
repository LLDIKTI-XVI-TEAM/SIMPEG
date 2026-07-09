<x-layouts.app title="EWS Pimpinan">

<div class="space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Early Warning System (EWS)</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'EWS']
            ]" />
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <a
                href="{{ route('pimpinan.laporan.index') }}"
                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary/90 shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-white shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Semua Laporan
            </a>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <x-ui.card padding="none" class="mb-6">
        <form method="GET" action="{{ route('pimpinan.ews.index') }}" class="flex flex-col gap-4 p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Search --}}
                <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input name="search" value="{{ request('search') }}" onchange="this.form.submit()" type="text" placeholder="Cari Pegawai atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>
                
                {{-- Jenis Event --}}
                <div class="relative">
                    <select name="jenis" onchange="this.form.submit()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Jenis Event</option>
                        <option value="Kenaikan Pangkat" {{ request('jenis') == 'Kenaikan Pangkat' ? 'selected' : '' }}>Kenaikan Pangkat</option>
                        <option value="Kenaikan Gaji Berkala" {{ request('jenis') == 'Kenaikan Gaji Berkala' ? 'selected' : '' }}>Kenaikan Gaji Berkala</option>
                        <option value="Batas Usia Pensiun" {{ request('jenis') == 'Batas Usia Pensiun' ? 'selected' : '' }}>Batas Usia Pensiun</option>
                        <option value="Satyalancana Karya Satya" {{ request('jenis') == 'Satyalancana Karya Satya' ? 'selected' : '' }}>Satyalancana Karya Satya</option>
                        <option value="Berakhir Tugas Belajar" {{ request('jenis') == 'Berakhir Tugas Belajar' ? 'selected' : '' }}>Berakhir Tugas Belajar</option>
                    </select>
                </div>

                {{-- Status --}}
                <div class="relative">
                    <select name="status" onchange="this.form.submit()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Status</option>
                        <option value="Belum Diproses" {{ request('status') == 'Belum Diproses' ? 'selected' : '' }}>Belum Diproses</option>
                        <option value="Diproses" {{ request('status') == 'Diproses' ? 'selected' : '' }}>Diproses</option>
                        <option value="Aman" {{ request('status') == 'Aman' ? 'selected' : '' }}>Aman / Selesai</option>
                    </select>
                </div>

                {{-- Indikator --}}
                <div class="relative">
                    <select name="indikator" onchange="this.form.submit()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Indikator</option>
                        <option value="merah" {{ request('indikator') == 'merah' ? 'selected' : '' }}>🔴 Merah</option>
                        <option value="kuning" {{ request('indikator') == 'kuning' ? 'selected' : '' }}>🟡 Kuning</option>
                        <option value="hijau" {{ request('indikator') == 'hijau' ? 'selected' : '' }}>🟢 Hijau</option>
                    </select>
                </div>
            </div>
        </form>
    </x-ui.card>

    {{-- TABLE --}}
    @php
        $sortIconClass = fn (string $column) => 'w-3.5 h-3.5 shrink-0 transition text-muted hover:text-ink';
    @endphp
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th class="select-none">
                            <a href="#" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Pegawai
                                <svg class="{{ $sortIconClass('pegawai') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">Jenis Event</x-ui.table-th>
                        <x-ui.table-th class="select-none">
                            <a href="#" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Sisa Hari
                                <svg class="{{ $sortIconClass('sisa_hari') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">Status</x-ui.table-th>
                        <x-ui.table-th class="select-none text-right">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse($ewsList as $ews)
                        @php
                            $indikatorVariant = match($ews['indikator']) {
                                'merah' => 'danger',
                                'kuning' => 'warning',
                                'hijau' => 'info',
                                default => 'muted'
                            };
                            
                            $statusVariant = match($ews['status']) {
                                'Belum Diproses' => 'danger',
                                'Diproses' => 'warning',
                                'Aman' => 'success',
                                default => 'muted'
                            };
                        @endphp
                        <x-ui.table-row :interactive="true">
                            <x-ui.table-td>
                                <div class="flex items-center gap-3">
                                    <x-ui.tooltip text="Buka detail profil {{ $ews['nama'] }}" position="right">
                                        <a
                                            href="{{ route('pimpinan.pegawai.show', $ews['id']) }}"
                                            class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                        >
                                            <span aria-hidden="true">
                                                {{ strtoupper(substr($ews['nama'], 0, 1)) }}
                                            </span>
                                        </a>
                                    </x-ui.tooltip>
                                    <div class="min-w-0">
                                        <x-ui.tooltip text="Buka detail profil {{ $ews['nama'] }}" position="right">
                                            <a
                                                href="{{ route('pimpinan.pegawai.show', $ews['id']) }}"
                                                class="block truncate text-sm font-semibold text-ink transition hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 rounded"
                                            >
                                                {{ $ews['nama'] }}
                                            </a>
                                        </x-ui.tooltip>
                                        <p class="font-mono text-xs text-muted">NIP. {{ $ews['nip'] }}</p>
                                    </div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <span class="text-sm font-medium text-ink">{{ $ews['jenis'] }}</span>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <x-ui.badge :variant="$indikatorVariant" size="md">
                                    H-{{ $ews['sisa_hari'] }}
                                </x-ui.badge>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <x-ui.badge :variant="$statusVariant" size="md" dot>
                                    {{ $ews['status'] }}
                                </x-ui.badge>
                            </x-ui.table-td>
                            <x-ui.table-td class="text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.button href="{{ route('pimpinan.pegawai.show', $ews['id']) }}" variant="secondary" size="icon" title="Detail Pegawai" tooltip-position="top-end" aria-label="Detail">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </x-ui.button>
                                </div>
                            </x-ui.table-td>
                        </x-ui.table-row>
                    @empty
                        <x-ui.table-row>
                            <x-ui.table-td colspan="5" align="center" class="px-0 py-0 text-sm text-muted">
                                <x-ui.empty-state icon="document" title="Tidak ada data peringatan dini (EWS)." />
                            </x-ui.table-td>
                        </x-ui.table-row>
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </div>

        {{-- TABLE FOOTER --}}
        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted">Tampilkan</span>
                    <select class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span class="text-sm text-muted">data per halaman</span>
                </div>
                <p class="text-sm text-muted hidden sm:block">
                    Menampilkan <span class="font-semibold text-ink">1</span> hingga <span class="font-semibold text-ink">{{ count($ewsList) }}</span> dari <span class="font-semibold text-ink">{{ count($ewsList) }}</span> hasil
                </p>
            </div>
            <div class="w-full sm:w-auto flex gap-1">
                <x-ui.button variant="secondary" size="sm" disabled>Sebelumnya</x-ui.button>
                <x-ui.button variant="primary" size="sm">1</x-ui.button>
                <x-ui.button variant="secondary" size="sm">Selanjutnya</x-ui.button>
            </div>
        </div>
    </x-ui.card>

</div>
</x-layouts.app>
