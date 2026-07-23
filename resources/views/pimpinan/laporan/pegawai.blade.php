<x-layouts.app title="Laporan Pegawai">
    <div class="space-y-6">
        {{-- PRINT ONLY HEADER (Kop Surat Resmi) --}}
        <div class="hidden print:block mb-8">
            <div class="flex items-center justify-center border-b-2 border-black pb-4">
                <img src="{{ asset('img/dikti16-favicon-blue-150x150.png') }}" class="h-16 w-16 mr-4" alt="Logo LLDIKTI XVI">
                <div class="text-center">
                    <h1 class="text-lg font-bold uppercase font-sans leading-tight">Kementerian Pendidikan Tinggi, Sains, dan Teknologi</h1>
                    <h2 class="text-base font-bold uppercase font-sans text-primary leading-tight">Lembaga Layanan Pendidikan Tinggi (LLDIKTI) Wilayah XVI</h2>
                    <p class="text-xs text-muted">Jl. Prof. Dr. Aloei Saboe, Wongkaditi, Kota Gorontalo</p>
                </div>
            </div>
            <div class="text-center mt-6">
                <div class="font-bold uppercase font-sans tracking-wide text-sm underline">Laporan Data Pegawai</div>
                <p class="text-[11px] text-muted mt-1 font-sans">Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
            </div>
        </div>

        {{-- PAGE HEADER WITH ACTION BUTTONS --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between print:hidden">
            <div>
                <h1 class="text-2xl font-semibold text-ink">Laporan Pegawai</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                    ['label' => 'Laporan Pegawai'],
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                {{-- Cetak / Unduh PDF --}}
                <x-ui.button type="button" onclick="window.print()" variant="secondary">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.617 0-1.11-.476-1.12-1.09l-.23-2.523M19.5 10.5v.375c0 .621-.504 1.125-1.125 1.125H5.625A1.125 1.125 0 0 1 4.5 11.25v-.375m15 0V9a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 9v1.5m15 0A1.5 1.5 0 0 0 18 9h-3V6a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3H6a1.5 1.5 0 0 0-1.5 1.5" />
                    </svg>
                    Unduh PDF
                </x-ui.button>
                {{-- Unduh Excel --}}
                <x-ui.button type="submit" form="filter-form" formaction="{{ route('pimpinan.laporan.pegawai.custom') }}" variant="secondary">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Unduh Excel
                </x-ui.button>
            </div>
        </div>

        <div class="print:hidden" x-data="{ configOpen: true }">
            <x-ui.card padding="none" class="overflow-hidden">
                {{-- Header Panel --}}
                <button type="button" @click="configOpen = !configOpen" class="w-full flex items-center justify-between px-6 py-4 border-b border-border bg-surface hover:bg-soft transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                            <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                            </svg>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-semibold text-ink font-sans">Konfigurasi Export</p>
                            <p id="employee-report-help" class="text-xs text-muted font-sans">Pilih kolom, filter data, dan range baris yang akan diekspor</p>
                        </div>
                    </div>
                    <svg class="w-4 h-4 text-muted transition-transform duration-200 shrink-0" :class="configOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                <form action="{{ route('pimpinan.laporan.pegawai') }}" method="GET" class="space-y-6" aria-describedby="employee-report-help" id="filter-form" x-on:change.debounce.500ms="$el.submit()">
                    <div x-show="configOpen" x-collapse x-data="{
                        columns: {{ json_encode(collect($allowedColumns)->mapWithKeys(fn($label, $key) => [$key => in_array($key, $selectedColumns, true)])->toArray()) }},
                        selectAll() { Object.keys(this.columns).forEach(k => this.columns[k] = true); setTimeout(() => this.$root.closest('form').submit(), 500); },
                        resetAll() { Object.keys(this.columns).forEach(k => this.columns[k] = (k === 'nip' || k === 'nama')); setTimeout(() => this.$root.closest('form').submit(), 500); }
                    }" class="p-6 space-y-6 bg-soft/30">

                        {{-- === BAGIAN 1: PILIH KOLOM === --}}
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-xs uppercase tracking-widest text-muted font-sans font-medium">Kolom yang Diekspor</h3>
                                <div class="flex gap-2">
                                    <button type="button" @click="selectAll()" class="text-xs font-semibold text-primary hover:underline font-sans cursor-pointer">Pilih Semua</button>
                                    <span class="text-muted text-xs">·</span>
                                    <button type="button" @click="resetAll()" class="text-xs font-semibold text-warning hover:underline font-sans cursor-pointer">Reset</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2.5">
                                @foreach($allowedColumns as $key => $label)
                                    <label class="flex items-center gap-2 rounded-lg border px-3 py-2.5 cursor-pointer transition"
                                        :class="columns['{{ $key }}'] ? 'border-primary/40 bg-primary/5 text-primary' : 'border-border bg-surface text-ink hover:bg-soft'">
                                        <input id="column-{{ $key }}" name="columns[]" value="{{ $key }}" type="checkbox"{{ in_array($key, $selectedColumns, true) ? ' checked' : '' }} x-model="columns['{{ $key }}']" class="rounded accent-primary hidden" />
                                        <div class="w-4 h-4 rounded border border-border flex items-center justify-center" :class="columns['{{ $key }}'] ? 'bg-primary border-primary text-white' : 'bg-surface'">
                                            <svg x-show="columns['{{ $key }}']" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        </div>
                                        <span class="text-xs font-semibold font-sans select-none">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-muted font-sans">
                                <span x-text="Object.values(columns).filter(v => v).length"></span> dari {{ count($allowedColumns) }} kolom dipilih
                            </p>
                        </div>

                        <div class="border-t border-border"></div>

                        {{-- === BAGIAN 2: FILTER DATA === --}}
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-widest text-muted font-sans mb-3">Filter Data</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                {{-- Search --}}
                                <div class="relative">
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-search">Cari Nama / NIP</label>
                                    <div class="relative">
                                        <input id="filter-search" name="search" type="text" placeholder="Cari nama atau NIP" value="{{ $filters['search'] ?? '' }}"
                                            class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Unit Kerja --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-unit">Unit Kerja</label>
                                    <x-form.select id="filter-unit" name="unit" size="md">
                                        <option value="">Semua Unit Kerja</option>
                                        @foreach($unitKerjaOptions as $unit)
                                            <option value="{{ $unit->nama }}" @selected(($filters['unit'] ?? '') === $unit->nama)>{{ $unit->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>

                                {{-- Golongan --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-golongan">Golongan</label>
                                    <x-form.select id="filter-golongan" name="golongan" size="md">
                                        <option value="">Semua Golongan</option>
                                        <option value="I" @selected(str_starts_with($filters['golongan'] ?? '', 'I'))>Golongan I</option>
                                        <option value="II" @selected(str_starts_with($filters['golongan'] ?? '', 'II'))>Golongan II</option>
                                        <option value="III" @selected(str_starts_with($filters['golongan'] ?? '', 'III'))>Golongan III</option>
                                        <option value="IV" @selected(str_starts_with($filters['golongan'] ?? '', 'IV'))>Golongan IV</option>
                                    </x-form.select>
                                </div>

                                {{-- Jenis Pegawai --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-jenis">Jenis Pegawai</label>
                                    <x-form.select id="filter-jenis" name="jenis" size="md">
                                        <option value="">Semua Jenis</option>
                                        @foreach($jenisPegawaiOptions as $jenis)
                                            <option value="{{ $jenis->nama }}" @selected(($filters['jenis'] ?? '') === $jenis->nama)>{{ $jenis->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                
                                {{-- Status Pegawai --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-status">Status</label>
                                    <x-form.select id="filter-status" name="status" size="md">
                                        <option value="">Semua Status</option>
                                        <option value="Aktif" @selected(($filters['status'] ?? '') === 'Aktif')>Aktif</option>
                                        <option value="Cuti" @selected(($filters['status'] ?? '') === 'Cuti')>Cuti</option>
                                        <option value="Pensiun" @selected(($filters['status'] ?? '') === 'Pensiun')>Pensiun</option>
                                    </x-form.select>
                                </div>

                                {{-- Jabatan --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-jabatan">Jabatan</label>
                                    <x-form.select id="filter-jabatan" name="jabatan" size="md">
                                        <option value="">Semua Jabatan</option>
                                        @foreach($jabatanOptions as $jabatan)
                                            <option value="{{ $jabatan->nama }}" @selected(($filters['jabatan'] ?? '') === $jabatan->nama)>{{ $jabatan->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>

                                {{-- Periode Pensiun Dari --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-pensiun-dari">Pensiun Dari</label>
                                    <input id="filter-pensiun-dari" name="pensiun_dari" type="date" value="{{ $filters['pensiun_dari'] ?? '' }}"
                                        class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>

                                {{-- Periode Pensiun Sampai --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1" for="filter-pensiun-sampai">Pensiun Sampai</label>
                                    <input id="filter-pensiun-sampai" name="pensiun_sampai" type="date" value="{{ $filters['pensiun_sampai'] ?? '' }}"
                                        class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>
                            </div>
                        </div>

                        @if($errors->any())
                            <div class="rounded-lg border border-danger/20 bg-danger/10 p-3 mt-4">
                                <ul class="list-disc space-y-1 pl-4 text-sm text-danger">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </form>
            </x-ui.card>
        </div>

        @php
            $previewRows = $previewData ?? collect($pegawai ?? []);
        @endphp

        {{-- TABLE PRATINJAU NOMINATIF --}}
        <x-ui.card padding="none" class="overflow-hidden print:border-none print:shadow-none print:bg-transparent">
            <div class="px-6 py-4 border-b border-border bg-surface flex items-center justify-between print:hidden">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Pratinjau Data Pegawai</h3>
                </div>
            </div>

            <div class="overflow-x-auto print:overflow-visible">
                <x-ui.table class="print:border-collapse print:border print:border-black">
                    <x-ui.table-head class="print:bg-gray-100">
                        <x-ui.table-row>
                            <x-ui.table-th class="w-12 text-center">No</x-ui.table-th>
                            @if(in_array('nip', $selectedColumns, true)) <x-ui.table-th>NIP</x-ui.table-th> @endif
                            @if(in_array('nama', $selectedColumns, true)) <x-ui.table-th>Nama Pegawai</x-ui.table-th> @endif
                            @if(in_array('golongan', $selectedColumns, true)) <x-ui.table-th>Golongan</x-ui.table-th> @endif
                            @if(in_array('jabatan', $selectedColumns, true)) <x-ui.table-th>Jabatan</x-ui.table-th> @endif
                            @if(in_array('unit', $selectedColumns, true)) <x-ui.table-th>Unit Kerja</x-ui.table-th> @endif
                            @if(in_array('jenis', $selectedColumns, true)) <x-ui.table-th>Jenis Pegawai</x-ui.table-th> @endif
                            @if(in_array('status', $selectedColumns, true)) <x-ui.table-th>Status</x-ui.table-th> @endif
                            @if(in_array('pendidikan', $selectedColumns, true)) <x-ui.table-th>Pendidikan</x-ui.table-th> @endif
                            @if(in_array('tanggal_pensiun', $selectedColumns, true)) <x-ui.table-th>Tgl. Pensiun</x-ui.table-th> @endif
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="print:divide-y print:divide-black">
                        @forelse($previewRows as $index => $row)
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td class="text-center font-medium text-muted">{{ ($previewData->currentPage() - 1) * $previewData->perPage() + $loop->iteration }}</x-ui.table-td>
                                @if(in_array('nip', $selectedColumns, true)) <x-ui.table-td class="font-mono text-xs">{{ $row['nip'] }}</x-ui.table-td> @endif
                                @if(in_array('nama', $selectedColumns, true)) <x-ui.table-td class="font-semibold text-ink">{{ $row['nama'] }}</x-ui.table-td> @endif
                                @if(in_array('golongan', $selectedColumns, true)) <x-ui.table-td>{{ $row['golongan'] }}</x-ui.table-td> @endif
                                @if(in_array('jabatan', $selectedColumns, true)) <x-ui.table-td>{{ $row['jabatan'] }}</x-ui.table-td> @endif
                                @if(in_array('unit', $selectedColumns, true)) <x-ui.table-td>{{ $row['unit'] }}</x-ui.table-td> @endif
                                @if(in_array('jenis', $selectedColumns, true)) <x-ui.table-td>{{ $row['jenis'] }}</x-ui.table-td> @endif
                                @if(in_array('status', $selectedColumns, true)) <x-ui.table-td><span class="inline-flex items-center rounded-md bg-success/10 px-2 py-0.5 text-xs font-medium text-success">{{ $row['status'] }}</span></x-ui.table-td> @endif
                                @if(in_array('pendidikan', $selectedColumns, true)) <x-ui.table-td>{{ $row['pendidikan'] }}</x-ui.table-td> @endif
                                @if(in_array('tanggal_pensiun', $selectedColumns, true)) <x-ui.table-td>{{ $row['tanggal_pensiun'] }}</x-ui.table-td> @endif
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="10" align="center" class="py-8 text-muted">
                                    Tidak ada data pegawai yang memenuhi kriteria filter.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- Footer: Paginasi --}}
            <div class="flex flex-col gap-4 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface print:hidden mt-4">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-muted font-sans">Tampilkan</span>
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
