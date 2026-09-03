<x-layouts.app title="Laporan Nominatif Pegawai">
    <div class="space-y-6">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Laporan Nominatif Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                    ['label' => 'Laporan'],
                    ['label' => 'Nominatif Pegawai']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <x-ui.button type="submit" form="filter-form" variant="secondary" size="md" formaction="{{ route('pimpinan.laporan.nominatif.pdf') }}" formtarget="_blank">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    Unduh PDF
                </x-ui.button>
                <x-ui.button type="submit" form="filter-form" variant="primary" size="md" formaction="{{ route('pimpinan.laporan.nominatif.excel') }}" formtarget="_blank">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Unduh Excel
                </x-ui.button>
            </div>
        </div>

        <div x-data="{ filterOpen: true }">
            <x-ui.card padding="none" class="overflow-hidden">
                <button type="button" @click="filterOpen = !filterOpen"
                    class="w-full flex items-center justify-between px-6 py-4 border-b border-border bg-surface hover:bg-soft transition">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                            <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                            </svg>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-semibold text-ink font-sans">Filter Laporan Nominatif</p>
                            <p class="text-xs text-muted font-sans mt-0.5">Filter data nominatif pegawai tanpa menampilkan informasi kontak pribadi.</p>
                        </div>
                    </div>
                    <svg class="w-4 h-4 text-muted transition-transform duration-200" :class="filterOpen ? 'rotate-180' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                <div x-show="filterOpen" x-collapse class="bg-soft/30">
                    <div class="p-6">
                        <form id="filter-form" action="{{ route('pimpinan.laporan.nominatif') }}" method="GET" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label for="search" class="mb-2 block text-sm font-semibold text-ink">Pencarian</label>
                                    <input id="search" name="search" type="text" placeholder="Cari nama atau NIP" value="{{ $filters['search'] ?? '' }}"
                                        class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>
                                <div>
                                    <label for="unit" class="mb-2 block text-sm font-semibold text-ink">Unit Kerja</label>
                                    <x-form.select id="unit" name="unit" size="md" onchange="this.form.submit()">
                                        <option value="">Semua Unit Kerja</option>
                                        @foreach($unitKerjaOptions as $unit)
                                            <option value="{{ $unit->nama }}" @selected(($filters['unit'] ?? '') === $unit->nama)>{{ $unit->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="golongan" class="mb-2 block text-sm font-semibold text-ink">Golongan</label>
                                    <x-form.select id="golongan" name="golongan" size="md" onchange="this.form.submit()">
                                        <option value="">Semua Golongan</option>
                                        <option value="I" @selected(str_starts_with($filters['golongan'] ?? '', 'I'))>Golongan I</option>
                                        <option value="II" @selected(str_starts_with($filters['golongan'] ?? '', 'II'))>Golongan II</option>
                                        <option value="III" @selected(str_starts_with($filters['golongan'] ?? '', 'III'))>Golongan III</option>
                                        <option value="IV" @selected(str_starts_with($filters['golongan'] ?? '', 'IV'))>Golongan IV</option>
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="jenis" class="mb-2 block text-sm font-semibold text-ink">Jenis Pegawai</label>
                                    <x-form.select id="jenis" name="jenis" size="md" onchange="this.form.submit()">
                                        <option value="">Semua Jenis</option>
                                        @foreach($jenisPegawaiOptions as $jenis)
                                            <option value="{{ $jenis->nama }}" @selected(($filters['jenis'] ?? '') === $jenis->nama)>{{ $jenis->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </x-ui.card>
        </div>

        {{-- TABEL PRATINJAU NOMINATIF --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between border-b border-border bg-surface px-6 py-4">
                <h3 class="text-sm font-bold uppercase tracking-widest text-muted font-sans">Pratinjau Laporan Nominatif</h3>
                <span class="text-xs font-semibold text-muted font-sans">
                    Total {{ $previewData->total() }} Data Pegawai
                </span>
            </div>

            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">No</x-ui.table-th>
                            <x-ui.table-th padding="lg">NIP</x-ui.table-th>
                            <x-ui.table-th padding="lg">Nama Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Golongan</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jabatan</x-ui.table-th>
                            <x-ui.table-th padding="lg">Unit Kerja</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jenis</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($previewData as $index => $row)
                            <x-ui.table-row class="border-b border-border/50">
                                <x-ui.table-td padding="lg" class="text-xs text-muted">{{ $previewData->firstItem() + $index }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-xs font-medium text-ink font-mono">{{ $row['nip'] ?? '-' }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-xs font-semibold text-ink">{{ $row['nama'] ?? '-' }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-xs text-muted font-medium">{{ $row['golongan'] ?? '-' }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-xs text-muted">{{ $row['jabatan'] ?? '-' }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-xs text-muted">{{ $row['unit'] ?? '-' }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-xs text-muted">{{ $row['jenis'] ?? '-' }}</x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="7" class="py-8 text-center text-sm text-muted">
                                    Tidak ada data pegawai yang sesuai dengan filter.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <select form="filter-form" name="per_page" onchange="this.form.submit()" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center w-auto">
                            <option value="10" @selected(request('per_page', 10) == 10)>10</option>
                            <option value="25" @selected(request('per_page') == 25)>25</option>
                            <option value="50" @selected(request('per_page') == 50)>50</option>
                        </select>
                        <span class="text-sm text-muted font-sans">data per halaman</span>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <p class="text-sm text-muted font-sans">
                        Menampilkan
                        <span class="font-medium">{{ $previewData->firstItem() ?? 0 }}</span>–<span class="font-medium">{{ $previewData->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium">{{ $previewData->total() }}</span> data
                    </p>
                    <div class="flex items-center gap-1.5">
                        {{ $previewData->appends(request()->query())->links('vendor.pagination.simpeg') }}
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
