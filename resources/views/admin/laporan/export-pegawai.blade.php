<x-layouts.app title="Daftar Nominatif Pegawai">

    @php
        $pegawaiJson = json_encode($pegawai);
    @endphp

    {{-- Data pegawai disuntikkan lewat script JSON agar aman dari escaping HTML --}}
    <script id="pegawai-data" type="application/json">{!! $pegawaiJson !!}</script>

    <div x-data="exportPegawai" class="space-y-6">

        {{-- ============================================================ --}}
        {{-- PRINT ONLY HEADER (Kop Surat Resmi)                         --}}
        {{-- ============================================================ --}}
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
                <div class="font-bold uppercase font-sans tracking-wide text-sm underline">Daftar Nominatif Pegawai</div>
                <p class="text-[11px] text-muted mt-1 font-sans">Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- PAGE HEADER (Screen only)                                    --}}
        {{-- ============================================================ --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between print:hidden">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Daftar Nominatif Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Daftar Nominatif Pegawai']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                {{-- Cetak PDF --}}
                <x-ui.button @click="printReport()" variant="secondary">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.617 0-1.11-.476-1.12-1.09l-.23-2.523M19.5 10.5v.375c0 .621-.504 1.125-1.125 1.125H5.625A1.125 1.125 0 0 1 4.5 11.25v-.375m15 0V9a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 9v1.5m15 0A1.5 1.5 0 0 0 18 9h-3V6a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3H6a1.5 1.5 0 0 0-1.5 1.5" />
                    </svg>
                    Cetak / PDF
                </x-ui.button>
                {{-- Export Excel --}}
                <x-ui.button x-bind:href="exportUrl" variant="primary">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Export Excel
                    <span class="ml-1.5 rounded-full bg-white/20 px-1.5 py-0.5 text-xs font-bold" x-text="exportRows.length + ' data'"></span>
                </x-ui.button>
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- PANEL KONFIGURASI EXPORT (Screen only)                       --}}
        {{-- ============================================================ --}}
        <div class="print:hidden">
            <x-ui.card padding="none" class="overflow-hidden">
                {{-- Header Panel --}}
                <button type="button" @click="configOpen = !configOpen"
                    class="w-full flex items-center justify-between px-6 py-4 border-b border-border bg-surface hover:bg-soft transition">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                            <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                            </svg>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-semibold text-ink font-sans">Konfigurasi Export</p>
                            <p class="text-xs text-muted font-sans">Pilih kolom, filter data, dan range baris yang akan diekspor</p>
                        </div>
                    </div>
                    <svg class="w-4 h-4 text-muted transition-transform duration-200" :class="configOpen ? 'rotate-180' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                {{-- Body Panel --}}
                <div x-show="configOpen" x-collapse class="bg-soft/30">
                    <div class="p-6 space-y-6">

                        {{-- === BAGIAN 1: PILIH KOLOM === --}}
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-xs font-bold uppercase tracking-widest text-muted font-sans">Kolom yang Diekspor</h3>
                                <div class="flex gap-2">
                                    <button type="button" @click="selectAllColumns()"
                                        class="text-xs font-semibold text-primary hover:underline font-sans cursor-pointer">Pilih Semua</button>
                                    <span class="text-muted text-xs">·</span>
                                    <button type="button" @click="deselectAllColumns()"
                                        class="text-xs font-semibold text-warning hover:underline font-sans cursor-pointer">Reset</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2.5">
                                <template x-for="(col, key) in columns" :key="key">
                                    <label class="flex items-center gap-2 rounded-lg border px-3 py-2.5 cursor-pointer transition"
                                        :class="col.active ? 'border-primary/40 bg-primary/5 text-primary' : 'border-border bg-surface text-ink hover:bg-soft'">
                                        <input type="checkbox" x-model="col.active" class="rounded accent-primary" />
                                        <span class="text-xs font-semibold font-sans select-none" x-text="col.label"></span>
                                    </label>
                                </template>
                            </div>
                            <p class="mt-2 text-xs text-muted font-sans">
                                <span x-text="activeColumns.length"></span> dari <span x-text="Object.keys(columns).length"></span> kolom dipilih
                            </p>
                        </div>

                        <div class="border-t border-border"></div>

                        {{-- === BAGIAN 2: FILTER DATA === --}}
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-widest text-muted font-sans mb-3">Filter Data</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                {{-- Search --}}
                                <div class="relative">
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Cari Nama / NIP</label>
                                    <div class="relative">
                                        <input type="text" x-model="searchQuery" placeholder="Cari nama atau NIP..."
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
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Unit Kerja</label>
                                    <div class="relative">
                                        <select x-model="activeUnit"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Unit Kerja</option>
                                            @foreach($unitList as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Golongan --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Golongan</label>
                                    <div class="relative">
                                        <select x-model="activeGolongan"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Golongan</option>
                                            @foreach($golonganList as $gol)
                                                <option value="{{ $gol }}">{{ $gol }}</option>
                                            @endforeach
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Jenis Pegawai --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Jenis Pegawai</label>
                                    <div class="relative">
                                        <select x-model="activeJenis"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Jenis</option>
                                            @foreach($jenisList as $jenis)
                                                <option value="{{ $jenis }}">{{ $jenis }}</option>
                                            @endforeach
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Status Pegawai --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Status</label>
                                    <div class="relative">
                                        <select x-model="activeStatus"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Status</option>
                                            @foreach($statusList as $st)
                                                <option value="{{ $st }}">{{ $st }}</option>
                                            @endforeach
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Urut Berdasarkan + Arah --}}
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Urutkan Berdasarkan</label>
                                    <div class="flex gap-2">
                                        <div class="relative flex-1">
                                            <select x-model="sortBy"
                                                class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                                {{-- Semua kolom yang ada di definisi columns (kecuali 'no') --}}
                                                <template x-for="(col, key) in columns" :key="key">
                                                    <option x-show="col.key !== 'no'" :value="col.key" x-text="col.label"></option>
                                                </template>
                                            </select>
                                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                            </div>
                                        </div>
                                        {{-- Toggle Asc / Desc --}}
                                        <button type="button" @click="sortDir = sortDir === 'asc' ? 'desc' : 'asc'"
                                            :title="sortDir === 'asc' ? 'Ascending (A→Z / kecil→besar)' : 'Descending (Z→A / besar→kecil)'"
                                            class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg border border-border bg-surface text-ink transition hover:bg-soft hover:border-primary/40 cursor-pointer">
                                            <svg x-show="sortDir === 'asc'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25m5.25-.75L17.25 9m0 0L21 12.75M17.25 9v12" />
                                            </svg>
                                            <svg x-show="sortDir === 'desc'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21l3.75-3.75" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Filter Awalan (Prefix) --}}
                                <div class="sm:col-span-2 lg:col-span-3 xl:col-span-4">
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">
                                        Filter Awalan
                                        <span class="ml-1 text-muted font-normal">— tampilkan data yang diawali karakter tertentu</span>
                                    </label>
                                    <div class="flex gap-2">
                                        {{-- Pilih field untuk prefix --}}
                                        <div class="relative w-44 shrink-0">
                                            <select x-model="prefixField"
                                                class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                                <template x-for="(col, key) in columns" :key="key">
                                                    <option x-show="col.key !== 'no'" :value="col.key" x-text="col.label"></option>
                                                </template>
                                            </select>
                                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                            </div>
                                        </div>
                                        {{-- Input awalan --}}
                                        <div class="relative flex-1">
                                            <input type="text" x-model="prefixValue"
                                                :placeholder="'Awali dengan… (misal: G, A, 1990, III)'"
                                                class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                                                </svg>
                                            </div>
                                        </div>
                                        {{-- Reset button --}}
                                        <button type="button" @click="prefixValue = ''"
                                            x-show="prefixValue.trim() !== ''"
                                            class="h-10 px-3 shrink-0 rounded-lg border border-border bg-surface text-muted text-xs font-semibold font-sans hover:bg-soft transition cursor-pointer">
                                            Hapus
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div class="border-t border-border"></div>

                        {{-- === BAGIAN 3: RANGE BARIS === --}}
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-widest text-muted font-sans mb-3">Range Baris</h3>
                            <div class="flex flex-wrap items-end gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Dari Baris</label>
                                    <input type="number" x-model.number="rowStart" min="1" placeholder="1"
                                        class="h-10 w-28 rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>
                                <div class="text-muted font-sans text-sm pb-2.5">–</div>
                                <div>
                                    <label class="block text-xs font-semibold text-muted font-sans mb-1">Sampai Baris</label>
                                    <input type="number" x-model.number="rowEnd" min="1" :placeholder="filteredPegawai.length || 'Semua'"
                                        class="h-10 w-28 rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>
                                <div class="pb-1">
                                    <span class="text-sm text-muted font-sans">
                                        Total yang akan diekspor: <strong class="text-ink" x-text="exportRows.length + ' pegawai'"></strong>
                                    </span>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-muted font-sans">Kosongkan "Sampai Baris" untuk mengekspor semua data yang terfilter.</p>
                        </div>

                    </div>
                </div>
            </x-ui.card>
        </div>

        {{-- ============================================================ --}}
        {{-- TABLE PRATINJAU                                              --}}
        {{-- ============================================================ --}}
        <x-ui.card padding="none" class="overflow-hidden print:border-none print:shadow-none print:bg-transparent">
            {{-- Header (Screen only) --}}
            <div class="px-6 py-4 border-b border-border bg-surface print:hidden flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Pratinjau Data Export</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Menampilkan data sesuai konfigurasi di atas. Hanya kolom yang dipilih yang akan muncul di file Excel.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted font-sans">Tampilkan:</span>
                    <x-form.select x-model.number="perPage" @change="currentPage = 1" class="w-auto py-1.5 pl-3 pr-8 text-xs font-sans">
                        <option value="10">10 / hal</option>
                        <option value="25">25 / hal</option>
                        <option value="50">50 / hal</option>
                    </x-form.select>
                </div>
            </div>

            {{-- Table --}}
            <div class="overflow-x-auto print:overflow-visible">
                <x-ui.table class="print:border-collapse print:border print:border-black">
                    <x-ui.table-head class="print:bg-gray-100">
                        <x-ui.table-row>
                            <template x-for="col in activeColumns" :key="col.key">
                                <x-ui.table-th class="select-none print:border print:border-black print:text-black"
                                    x-text="col.label"></x-ui.table-th>
                            </template>
                            <template x-if="activeColumns.length === 0">
                                <x-ui.table-th>Pilih minimal satu kolom</x-ui.table-th>
                            </template>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="print:divide-y print:divide-black">
                        {{-- SCREEN VIEW: paginasi --}}
                        <template x-for="(row, index) in paginatedPreview" :key="row.id">
                            <x-ui.table-row :interactive="true" class="print:hidden">
                                <template x-for="col in activeColumns" :key="col.key">
                                    <x-ui.table-td class="whitespace-nowrap print:border print:border-black"
                                        x-text="getCellValue(row, col.key, (currentPage - 1) * perPage + index)"></x-ui.table-td>
                                </template>
                            </x-ui.table-row>
                        </template>

                        {{-- PRINT VIEW: semua export rows --}}
                        <template x-for="(row, index) in exportRows" :key="'print-' + row.id">
                            <x-ui.table-row class="hidden print:table-row">
                                <template x-for="col in activeColumns" :key="col.key">
                                    <x-ui.table-td class="border border-black"
                                        x-text="getCellValue(row, col.key, index)"></x-ui.table-td>
                                </template>
                            </x-ui.table-row>
                        </template>

                        {{-- Empty state --}}
                        <tr x-show="exportRows.length === 0">
                            <td :colspan="activeColumns.length || 1" class="px-0 py-0 print:border print:border-black">
                                <x-ui.empty-state icon="document" title="Tidak ada data yang cocok dengan konfigurasi Anda." />
                            </td>
                        </tr>
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- Footer: Paginasi (Screen only) --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface print:hidden">
                <p class="text-sm text-muted font-sans">
                    Menampilkan
                    <span x-text="exportRows.length === 0 ? 0 : (currentPage - 1) * perPage + 1"></span>–<span x-text="Math.min(currentPage * perPage, exportRows.length)"></span>
                    dari <span x-text="exportRows.length"></span> data
                </p>
                <div class="flex items-center gap-1.5">
                    <x-ui.pagination current="currentPage" total="totalPages" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.modal
            show="showCustomExportModal"
            title="Export Nominatif Custom"
            closeAction="showCustomExportModal = false"
            maxWidth="2xl"
            bodyClass="p-6"
        >
            <form method="POST" action="{{ route('laporan.pegawai.custom') }}" @submit.prevent="submitCustomExport($event)" class="space-y-5">
                @csrf

                <input type="hidden" name="search" :value="searchQuery">
                <input type="hidden" name="unit" :value="activeUnit">
                <input type="hidden" name="golongan" :value="activeGolongan">
                <input type="hidden" name="jenis" :value="activeJenis">
                <input type="hidden" name="status" :value="activeStatus">
                <input type="hidden" name="sort" :value="sortBy">

                <div>
                    <p class="text-sm text-ink font-sans">Pilih kolom laporan. NIK, No. KK, kontak pribadi, dan data sensitif lain tidak tersedia.</p>
                    <p class="mt-1 text-xs text-muted font-sans">Filter aktif pada halaman ini ikut digunakan. Tambahkan jabatan dan periode pensiun di bawah bila diperlukan.</p>
                </div>

                <fieldset aria-describedby="custom-columns-help custom-columns-error">
                    <legend class="text-sm font-semibold text-ink font-sans">Kolom laporan <span class="text-danger">*</span></legend>
                    <p id="custom-columns-help" class="mt-1 text-xs text-muted font-sans">Kolom yang dipilih akan selalu memakai urutan baku laporan: NIP, Nama, Golongan, Jabatan, Unit Kerja, Jenis Pegawai, Status, Pendidikan Terakhir, lalu Tanggal Pensiun.</p>
                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach (\App\Http\Requests\Laporan\CustomEmployeeExportRequest::ALLOWED_COLUMNS as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-soft px-3 py-2 text-sm text-ink transition hover:border-primary/40">
                                <input type="checkbox" name="columns[]" value="{{ $key }}" x-model="customColumns" class="h-4 w-4 rounded border-border text-primary focus:ring-primary">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p x-cloak x-show="customError" id="custom-columns-error" role="alert" class="mt-2 text-sm text-danger" x-text="customError"></p>
                </fieldset>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="custom-jabatan" class="mb-1 block text-xs font-semibold text-ink">Jabatan</label>
                        <select id="custom-jabatan" name="jabatan" x-model="customJabatan" class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Jabatan</option>
                            <template x-for="jabatan in filterOptions.jabatan" :key="jabatan">
                                <option :value="jabatan" x-text="jabatan"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label for="custom-pensiun-dari" class="mb-1 block text-xs font-semibold text-ink">Pensiun dari</label>
                        <input id="custom-pensiun-dari" type="date" name="pensiun_dari" x-model="customPensiunDari" class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    </div>
                    <div>
                        <label for="custom-pensiun-sampai" class="mb-1 block text-xs font-semibold text-ink">Pensiun sampai</label>
                        <input id="custom-pensiun-sampai" type="date" name="pensiun_sampai" x-model="customPensiunSampai" class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-border pt-4">
                    <x-ui.button type="button" @click="showCustomExportModal = false" variant="secondary">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Download Excel Custom</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        {{-- PRINT ONLY FOOTER --}}
        <div id="print-footer" class="hidden print:block"></div>

    </div>

    {{-- CUSTOM CSS PRINTING --}}
    @push('head')
    <style>
        @media print {
            aside, header, nav, button, select, input, .table-footer, .print\:hidden { display: none !important; }
            body, main, div { background: transparent !important; box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; }
            .print\:block      { display: block !important; }
            .print\:table-row  { display: table-row !important; }
            @page { size: landscape; margin: 1.5cm; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th, td { border: 1px solid #000 !important; padding: 6px 8px !important; color: #000 !important; font-size: 10px !important; background-color: transparent !important; }
            #print-footer { position: fixed; bottom: -0.5cm; left: 0; right: 0; border-top: 1px solid #000; text-align: right; font-size: 10px; font-family: 'Poppins', sans-serif; color: #6B7280; padding-top: 5px; }
            #print-footer::after { content: "Halaman " counter(page) " dari " counter(pages); }
        }
    </style>
    @endpush

    @push('scripts')
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('exportPegawai', () => ({
            // =====================================================================
            // DATA SOURCE — diisi dari PHP
            // =====================================================================
            allPegawai: JSON.parse(document.getElementById('pegawai-data').textContent),

            // =====================================================================
            // FILTER STATE
            // =====================================================================
            searchQuery: '',
            activeUnit: '',
            activeGolongan: '',
            activeJenis: '',
            activeStatus: '',
            sortBy: 'nama',
            sortDir: 'asc',
            prefixField: 'nama',
            prefixValue: '',

            // =====================================================================
            // KONFIGURASI EXPORT (Column Picker & Row Range)
            // =====================================================================
            columns: {
                no:       { label: 'No',                  active: true,  key: 'no' },
                nip:      { label: 'NIP',                 active: true,  key: 'nip' },
                nama:     { label: 'Nama Pegawai',        active: true,  key: 'nama' },
                golongan: { label: 'Golongan',            active: true,  key: 'golongan' },
                jabatan:  { label: 'Jabatan',             active: true,  key: 'jabatan' },
                unit:     { label: 'Unit Kerja',          active: true,  key: 'unit' },
                jenis:    { label: 'Jenis Pegawai',       active: true,  key: 'jenis' },
                status:   { label: 'Status',              active: true,  key: 'status' },
                email:         { label: 'Email',               active: false, key: 'email' },
                no_hp:         { label: 'No. HP',             active: false, key: 'no_hp' },
                tanggal_lahir:   { label: 'Tanggal Lahir',        active: false, key: 'tanggal_lahir' },
                tanggal_pensiun: { label: 'Tgl. Pensiun',         active: false, key: 'tanggal_pensiun' },
                pendidikan:      { label: 'Pendidikan Terakhir',  active: false, key: 'pendidikan' },
                pangkat:         { label: 'Pangkat',              active: false, key: 'pangkat' },
            },
            rowStart: 1,
            rowEnd: '',

            // =====================================================================
            // UI STATE
            // =====================================================================
            configOpen: true,
            currentPage: 1,
            perPage: 10,

            // =====================================================================
            // WATCHERS
            // =====================================================================
            init() {
                this.$watch('searchQuery',    () => this.currentPage = 1);
                this.$watch('activeUnit',     () => this.currentPage = 1);
                this.$watch('activeGolongan', () => this.currentPage = 1);
                this.$watch('activeJenis',    () => this.currentPage = 1);
                this.$watch('activeStatus',   () => this.currentPage = 1);
                this.$watch('sortBy',         () => this.currentPage = 1);
                this.$watch('sortDir',        () => this.currentPage = 1);
                this.$watch('prefixField',    () => this.currentPage = 1);
                this.$watch('prefixValue',    () => this.currentPage = 1);
                this.$watch('rowStart',       () => this.currentPage = 1);
                this.$watch('rowEnd',         () => this.currentPage = 1);
            },

            // =====================================================================
            // COMPUTED: Filter saja tanpa sort
            // =====================================================================
            get filteredPegawai() {
                return this.allPegawai.filter(p => {
                    const query = this.searchQuery.toLowerCase().trim();
                    const matchesSearch = !query ||
                        p.nama.toLowerCase().includes(query) ||
                        p.nip.replace(/\s+/g, '').includes(query.replace(/\s+/g, ''));
                    const matchesUnit     = !this.activeUnit     || p.unit     === this.activeUnit;
                    const matchesGolongan = !this.activeGolongan || p.golongan === this.activeGolongan;
                    const matchesJenis    = !this.activeJenis    || p.jenis    === this.activeJenis;
                    const matchesStatus   = !this.activeStatus   || p.status   === this.activeStatus;
                    const pf = this.prefixValue.trim().toLowerCase();
                    const matchesPrefix = !pf || String(p[this.prefixField] ?? '').toLowerCase().startsWith(pf);
                    return matchesSearch && matchesUnit && matchesGolongan && matchesJenis && matchesStatus && matchesPrefix;
                });
            },

            // =====================================================================
            // COMPUTED: Filter → Slice range → Sort subset
            // =====================================================================
            get exportRows() {
                const filtered = this.filteredPegawai;
                const s      = Math.max(1, parseInt(this.rowStart) || 1) - 1;
                const rawEnd = this.rowEnd;
                const e      = (rawEnd !== '' && rawEnd !== null && Number(rawEnd) > 0)
                    ? Number(rawEnd)
                    : filtered.length;
                const sliced = filtered.slice(s, Math.min(e, filtered.length));

                const result = [...sliced];
                const golonganOrder = {
                    'IV/e':1,'IV/d':2,'IV/c':3,'IV/b':4,'IV/a':5,
                    'III/d':6,'III/c':7,'III/b':8,'III/a':9,
                    'II/d':10,'II/c':11,'II/b':12,'II/a':13,
                    'I/d':14,'I/c':15,'I/b':16,'I/a':17
                };
                const dir = this.sortDir === 'desc' ? -1 : 1;
                result.sort((a, b) => {
                    if (this.sortBy === 'golongan') {
                        return dir * ((golonganOrder[a.golongan] || 99) - (golonganOrder[b.golongan] || 99));
                    }
                    const va = String(a[this.sortBy] ?? '');
                    const vb = String(b[this.sortBy] ?? '');
                    return dir * va.localeCompare(vb, 'id', { numeric: true, sensitivity: 'base' });
                });
                return result;
            },

            // =====================================================================
            // COMPUTED: Paginasi
            // =====================================================================
            get paginatedPreview() {
                const start = (this.currentPage - 1) * this.perPage;
                return this.exportRows.slice(start, start + this.perPage);
            },
            get totalPages() {
                return Math.ceil(this.exportRows.length / this.perPage) || 1;
            },

            // =====================================================================
            // COMPUTED: Kolom aktif
            // =====================================================================
            get activeColumns() {
                return Object.values(this.columns).filter(c => c.active);
            },

            selectAllColumns()   { Object.keys(this.columns).forEach(k => this.columns[k].active = true); },
            deselectAllColumns() { Object.keys(this.columns).forEach(k => this.columns[k].active = k === 'no' || k === 'nama'); },

            // =====================================================================
            // BUILD EXPORT URL
            // =====================================================================
            get exportUrl() {
                const params = new URLSearchParams();
                params.set('search',       this.searchQuery);
                params.set('unit',         this.activeUnit);
                params.set('golongan',     this.activeGolongan);
                params.set('jenis',        this.activeJenis);
                params.set('status',       this.activeStatus);
                params.set('sort',         this.sortBy);
                params.set('sort_dir',     this.sortDir);
                if (this.prefixValue.trim()) {
                    params.set('prefix_field', this.prefixField);
                    params.set('prefix_value', this.prefixValue.trim());
                }
                if (this.rowStart > 1) params.set('row_start', this.rowStart);
                if (this.rowEnd !== '' && this.rowEnd !== null) params.set('row_end', this.rowEnd);
                this.activeColumns.forEach(c => params.append('columns[]', c.key));
                return '/laporan/export-pegawai/excel?' + params.toString();
            },

            getCellValue(row, key, index) {
                if (key === 'no') return index + 1;
                return row[key] ?? '-';
            },

            printReport() { window.print(); },
        }));
    });
    </script>
    @endpush

</x-layouts.app>
