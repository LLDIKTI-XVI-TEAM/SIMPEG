<x-layouts.app title="Data Pegawai">

<div class="max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8 space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Data Pegawai']
            ]" />
        </div>
        <div class="flex shrink-0 items-center gap-3">
            {{-- Export button --}}
            <a
                href="{{ route('pimpinan.laporan.pegawai') }}"
                id="export-btn"
                class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Excel
            </a>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <x-ui.card padding="none" class="mb-6">
        <form id="filter-form" method="GET" action="#" class="flex flex-col gap-4 p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {{-- Search input --}}
            <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input id="search-input" name="search" type="text" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
            </div>
            
            {{-- Filter Golongan --}}
            <div class="relative">
                <select id="filter-golongan" name="golongan" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Golongan</option>
                    <option value="IV/e">Golongan IV/e</option>
                    <option value="IV/d">Golongan IV/d</option>
                    <option value="IV/c">Golongan IV/c</option>
                    <option value="IV/b">Golongan IV/b</option>
                    <option value="IV/a">Golongan IV/a</option>
                    <option value="III/d">Golongan III/d</option>
                    <option value="III/c">Golongan III/c</option>
                </select>
            </div>

            {{-- Filter Unit --}}
            <div class="relative">
                <select id="filter-unit" name="unit_kerja_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit</option>
                    <option value="1">Bagian Umum</option>
                    <option value="2">Bagian Kepegawaian</option>
                </select>
            </div>

            {{-- Filter Jenis --}}
            <div class="relative">
                <select id="filter-jenis" name="jenis_pegawai_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    <option value="PNS">PNS</option>
                    <option value="PPPK">PPPK</option>
                    <option value="CPNS">CPNS</option>
                </select>
            </div>

            {{-- Filter Status --}}
            <div class="relative">
                <select id="filter-status" name="status_pegawai_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Status</option>
                    <option value="aktif">Aktif</option>
                    <option value="cuti">Cuti</option>
                    <option value="tugas_belajar">Tugas Belajar</option>
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
            <x-ui.table id="pegawai-table">
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th class="select-none">
                            <a href="#" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Pegawai
                                <svg class="{{ $sortIconClass('pegawai') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">
                            <a href="#" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Jabatan & Unit
                                <svg class="{{ $sortIconClass('jabatan') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">
                            <a href="#" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Gol. / Jenis
                                <svg class="{{ $sortIconClass('golongan') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">Status</x-ui.table-th>
                        <x-ui.table-th class="select-none text-right">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse($employees as $emp)
                    @php
                        $status_lower = strtolower($emp['status']);
                        $statusVariants = [
                            'aktif' => 'success',
                            'cuti' => 'warning',
                            'non-aktif' => 'danger',
                            'tugas_belajar' => 'info',
                            'pensiun' => 'danger',
                            'mutasi' => 'warning'
                        ];
                        $statusLabel = match($emp['status']) {
                            'aktif' => 'Aktif',
                            'cuti' => 'Cuti',
                            'tugas_belajar' => 'Tugas Belajar',
                            default => ucfirst($emp['status'])
                        };
                    @endphp

                    <x-ui.table-row :interactive="true">
                        <x-ui.table-td>
                            <div class="flex items-center gap-3">
                                <x-ui.tooltip text="Buka detail profil {{ $emp['nama'] }}" position="right">
                                    <a
                                        href="{{ route('pimpinan.pegawai.show', $emp['id']) }}"
                                        class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                    >
                                        <span aria-hidden="true">
                                            {{ strtoupper(substr($emp['nama'], 0, 1)) }}
                                        </span>
                                    </a>
                                </x-ui.tooltip>
                                <div class="min-w-0">
                                    <x-ui.tooltip text="Buka detail {{ $emp['nama'] }}" position="right">
                                        <a
                                            href="{{ route('pimpinan.pegawai.show', $emp['id']) }}"
                                            class="block truncate text-sm font-semibold text-ink transition hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 rounded"
                                        >
                                            {{ $emp['nama'] }}
                                        </a>
                                    </x-ui.tooltip>
                                    <p class="font-mono text-xs text-muted">NIP. {{ $emp['nip'] }}</p>
                                </div>
                            </div>
                        </x-ui.table-td>
                        <x-ui.table-td>
                            <p class="text-sm font-medium text-ink">{{ $emp['jabatan'] }}</p>
                            <p class="text-xs text-muted">Bagian Kepegawaian</p>
                        </x-ui.table-td>
                        <x-ui.table-td>
                            <span class="text-sm font-medium text-ink">{{ $emp['golongan'] }} / PNS</span>
                        </x-ui.table-td>
                        <x-ui.table-td>
                            <x-ui.badge :variant="$statusVariants[$status_lower] ?? 'muted'" size="md" dot>
                                {{ $statusLabel }}
                            </x-ui.badge>
                        </x-ui.table-td>
                        <x-ui.table-td class="text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                {{-- Detail --}}
                                <x-ui.button href="{{ route('pimpinan.pegawai.show', $emp['id']) }}" variant="secondary" size="icon" title="Detail" tooltip-position="top-end" aria-label="Detail">
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
                                <x-ui.empty-state icon="search" title="Tidak ada data pegawai yang sesuai." />
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
                    Menampilkan <span class="font-semibold text-ink">1</span> hingga <span class="font-semibold text-ink">{{ count($employees) }}</span> dari <span class="font-semibold text-ink">{{ count($employees) }}</span> hasil
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
