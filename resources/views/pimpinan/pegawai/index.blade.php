<x-layouts.app title="Data Pegawai">

<div class="space-y-6">

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
                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary/90 shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-white shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Excel
            </a>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <x-ui.card padding="none" class="mb-6">
        <form id="filter-form" method="GET" action="{{ route('pimpinan.pegawai.index') }}" class="flex flex-col gap-4 p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {{-- Search input --}}
            <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                <label class="sr-only" for="search-input">Cari nama atau NIP</label>
                <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input id="search-input" name="search" value="{{ $filters['search'] }}" type="search" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
            </div>
            
            {{-- Filter Golongan --}}
            <div class="relative">
                <label class="sr-only" for="filter-golongan">Filter golongan</label>
                <select id="filter-golongan" name="golongan" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Golongan</option>
                    @foreach ($golonganOptions as $golongan)
                        <option value="{{ $golongan }}" @selected($filters['golongan'] === $golongan)>Golongan {{ $golongan }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Unit --}}
            <div class="relative">
                <label class="sr-only" for="filter-unit">Filter unit kerja</label>
                <select id="filter-unit" name="unit_kerja_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit</option>
                    @foreach ($unitKerjaOptions as $unitKerja)
                        <option value="{{ $unitKerja->id }}" @selected($filters['unit_kerja_id'] === $unitKerja->id)>{{ $unitKerja->nama }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Jenis --}}
            <div class="relative">
                <label class="sr-only" for="filter-jenis">Filter jenis pegawai</label>
                <select id="filter-jenis" name="jenis_pegawai_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    @foreach ($jenisPegawaiOptions as $jenisPegawai)
                        <option value="{{ $jenisPegawai->id }}" @selected($filters['jenis_pegawai_id'] === $jenisPegawai->id)>{{ $jenisPegawai->nama }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Status --}}
            <div class="relative">
                <label class="sr-only" for="filter-status">Filter status pegawai</label>
                <select id="filter-status" name="status_pegawai_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Pegawai aktif</option>
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status->id }}" @selected($filters['status_pegawai_id'] === $status->id)>{{ $status->nama }}</option>
                    @endforeach
                </select>
            </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition hover:bg-soft hover:border-primary/30 focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan filter</button>
            </div>
        </form>
    </x-ui.card>

    {{-- TABLE --}}
    @php
        $sortUrl = function (string $column) use ($filters, $perPage, $sort, $direction): string {
            return route('pimpinan.pegawai.index', array_filter([
                ...$filters,
                'per_page' => $perPage,
                'sort' => $column,
                'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
            ]));
        };
    @endphp
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table id="pegawai-table" caption="Daftar data pegawai">
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th class="select-none" :aria-sort="$sort === 'nama_lengkap' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none'">
                            <a href="{{ $sortUrl('nama_lengkap') }}" class="hover:text-ink transition-colors" aria-label="Urutkan pegawai berdasarkan nama">Pegawai</a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none" :aria-sort="$sort === 'jabatan_terakhir' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none'">
                            <a href="{{ $sortUrl('jabatan_terakhir') }}" class="hover:text-ink transition-colors" aria-label="Urutkan pegawai berdasarkan jabatan">Jabatan & Unit</a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none" :aria-sort="$sort === 'golongan_terakhir' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none'">
                            <a href="{{ $sortUrl('golongan_terakhir') }}" class="hover:text-ink transition-colors" aria-label="Urutkan pegawai berdasarkan golongan">Gol. / Jenis</a>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">Status</x-ui.table-th>
                        <x-ui.table-th class="select-none text-right">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse($employees as $emp)
                    @php
                        $statusLower = str_replace(' ', '-', strtolower($emp['status_key']));
                        $statusVariants = [
                            'aktif' => 'success',
                            'non-aktif' => 'danger',
                            'tugas-belajar' => 'info',
                            'pensiun' => 'danger',
                            'mutasi' => 'warning'
                        ];
                    @endphp

                    <x-ui.table-row :interactive="true">
                        <th scope="row" class="px-4 py-3.5 text-left text-xs text-ink font-sans">
                            <div class="flex items-center gap-3">
                                <x-ui.tooltip text="Buka detail profil {{ $emp['nama_lengkap'] }}" position="right">
                                    <a
                                        href="{{ route('pimpinan.pegawai.show', $emp['id']) }}"
                                        class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                    >
                                        <span aria-hidden="true">
                                            {{ strtoupper(substr($emp['nama_lengkap'], 0, 1)) }}
                                        </span>
                                    </a>
                                </x-ui.tooltip>
                                <div class="min-w-0">
                                    <x-ui.tooltip text="Buka detail {{ $emp['nama_lengkap'] }}" position="right">
                                        <a
                                            href="{{ route('pimpinan.pegawai.show', $emp['id']) }}"
                                            class="block truncate text-sm font-semibold text-ink transition hover:text-primary focus:outline-none rounded"
                                        >
                                            {{ $emp['nama_lengkap'] }}
                                        </a>
                                    </x-ui.tooltip>
                                    <p class="font-mono text-xs text-muted">NIP. {{ $emp['nip'] }}</p>
                                </div>
                            </div>
                        </th>
                        <x-ui.table-td>
                            <p class="text-sm font-medium text-ink">{{ $emp['jabatan'] }}</p>
                            <p class="text-xs text-muted">{{ $emp['unit_kerja'] }}</p>
                        </x-ui.table-td>
                        <x-ui.table-td>
                            <span class="text-sm font-medium text-ink">{{ $emp['golongan_terakhir'] }} / {{ $emp['jenis_pegawai'] }}</span>
                        </x-ui.table-td>
                        <x-ui.table-td>
                            <x-ui.badge :variant="$statusVariants[$statusLower] ?? 'muted'" size="md" dot>
                                {{ $emp['status_nama'] }}
                            </x-ui.badge>
                        </x-ui.table-td>
                        <x-ui.table-td class="text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                {{-- Detail --}}
                                <x-ui.button href="{{ route('pimpinan.pegawai.show', $emp['id']) }}" variant="secondary" size="icon" title="Detail" tooltip-position="top-end" aria-label="Detail pegawai {{ $emp['nama_lengkap'] }}">
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
                    <label class="sr-only" for="per-page">Jumlah hasil per halaman</label>
                    <select id="per-page" name="per_page" form="filter-form" onchange="this.form.submit()" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        @foreach ([10, 25, 50] as $option)
                            <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                    <span class="text-sm text-muted">data per halaman</span>
                </div>
                <p class="text-sm text-muted hidden sm:block">
                    Menampilkan <span class="font-semibold text-ink">{{ $employees->firstItem() ?? 0 }}</span> hingga <span class="font-semibold text-ink">{{ $employees->lastItem() ?? 0 }}</span> dari <span class="font-semibold text-ink">{{ $employees->total() }}</span> hasil
                </p>
            </div>
            <div class="w-full sm:w-auto">
                {{ $employees->onEachSide(1)->links() }}
            </div>
        </div>
    </x-ui.card>

</div>
</x-layouts.app>
