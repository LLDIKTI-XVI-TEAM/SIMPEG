<x-layouts.app title="Daftar Nominatif Pegawai">

    <div
        x-data="exportPegawai(@js($pegawai), @js($filterOptions), @js($initialFilters))"
        class="space-y-6"
    >

        {{-- ============================================================ --}}
        {{-- PAGE HEADER                                                  --}}
        {{-- ============================================================ --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Daftar Nominatif Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Daftar Nominatif Pegawai']
                ]" />
            </div>
            <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto sm:justify-end">
                {{-- Export PDF melalui rute backend resmi --}}
                <x-ui.button @click="exportPdf()" x-bind:disabled="previewLoading || !!previewError || !!pensiunError" variant="primary"
                    aria-describedby="pdf-export-error">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true" focusable="false">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.617 0-1.11-.476-1.12-1.09l-.23-2.523M19.5 10.5v.375c0 .621-.504 1.125-1.125 1.125H5.625A1.125 1.125 0 0 1 4.5 11.25v-.375m15 0V9a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 9v1.5m15 0A1.5 1.5 0 0 0 18 9h-3V6a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3H6a1.5 1.5 0 0 0-1.5 1.5" />
                    </svg>
                    Export PDF
                </x-ui.button>
                <x-ui.button type="submit" form="custom-export-form" x-bind:disabled="previewLoading || !!previewError || !!pensiunError" variant="primary">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Unduh Excel Kustom
                </x-ui.button>
            </div>
        </div>

        <p x-cloak x-show="pdfError" id="pdf-export-error" role="alert" class="text-sm text-danger font-sans" x-text="pdfError"></p>

        {{-- ============================================================ --}}
        {{-- PANEL KONFIGURASI EXPORT (Screen only)                       --}}
        {{-- ============================================================ --}}
        <form id="custom-export-form" method="POST" action="{{ route('laporan.pegawai.custom') }}" @submit="submitCustomExport($event)">
            @csrf

            <template x-for="column in activeColumns" :key="'custom-export-column-' + column.key">
                <input type="hidden" name="columns[]" :value="column.key">
            </template>
            <input type="hidden" name="sort_dir" :value="sortDir">

            <x-ui.card padding="none" class="overflow-hidden">
                {{-- Header Panel --}}
                <button type="button" @click="configOpen = !configOpen"
                    id="export-config-toggle"
                    aria-controls="export-config-panel"
                    x-bind:aria-expanded="configOpen ? 'true' : 'false'"
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
                        fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true" focusable="false">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                {{-- Body Panel --}}
                <div x-show="configOpen" x-collapse id="export-config-panel" role="region" aria-labelledby="export-config-toggle" class="bg-soft/30">
                    <div class="p-6 space-y-6">

                        {{-- === BAGIAN 1: PILIH KOLOM === --}}
                        <div x-ref="columnConfiguration" tabindex="-1" aria-describedby="column-selection-help export-error">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h3 class="text-xs uppercase tracking-widest text-muted font-sans font-medium">Kolom yang Diekspor</h3>
                                    <p id="column-selection-help" class="mt-1 text-xs text-muted font-sans">Pilihan dan urutan di panel ini digunakan untuk export Excel.</p>
                                </div>
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
                            <p x-cloak x-show="exportError" id="export-error" role="alert" class="mt-2 text-sm text-danger" x-text="exportError"></p>

                            <div x-cloak x-show="activeColumns.length > 0" class="mt-4 rounded-lg border border-border bg-soft/40 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-ink font-sans">Urutan kolom pada file Excel</p>
                                    <p class="text-xs text-muted font-sans">Gunakan panah untuk mengubah urutan</p>
                                </div>
                                <ol class="mt-3 space-y-2" aria-label="Urutan kolom export">
                                    <template x-for="(column, index) in activeColumns" :key="'column-order-' + column.key">
                                        <li class="flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2">
                                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary" x-text="index + 1"></span>
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink font-sans" x-text="column.label"></span>
                                            <div class="flex shrink-0 items-center gap-1">
                                                <button type="button" @click="moveColumn(column.key, -1)" :disabled="index === 0"
                                                    :aria-label="'Naikkan urutan ' + column.label"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted transition hover:border-primary/40 hover:bg-primary/5 hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-40">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m18 15-6-6-6 6" />
                                                    </svg>
                                                </button>
                                                <button type="button" @click="moveColumn(column.key, 1)" :disabled="index === activeColumns.length - 1"
                                                    :aria-label="'Turunkan urutan ' + column.label"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted transition hover:border-primary/40 hover:bg-primary/5 hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-40">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </li>
                                    </template>
                                </ol>
                            </div>
                        </div>

                        <div class="border-t border-border"></div>

                        {{-- === BAGIAN 2: FILTER DATA === --}}
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-widest text-muted font-sans mb-3">Filter Data</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                {{-- Search --}}
                                <div class="relative">
                                    <label for="filter-search" class="block text-xs font-semibold text-muted font-sans mb-1">Cari Nama / NIP</label>
                                    <div class="relative">
                                        <input type="text" id="filter-search" name="search" x-model="searchQuery" @keydown.enter.prevent placeholder="Cari nama atau NIP"
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
                                    <label for="filter-unit" class="block text-xs font-semibold text-muted font-sans mb-1">Unit Kerja</label>
                                    <div class="relative">
                                        <select id="filter-unit" name="unit" x-model="activeUnit"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Unit Kerja</option>
                                            @foreach($filterOptions['units'] as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                {{-- Golongan --}}
                                <div>
                                    <label for="filter-golongan" class="block text-xs font-semibold text-muted font-sans mb-1">Golongan</label>
                                    <div class="relative">
                                        <select id="filter-golongan" name="golongan" x-model="activeGolongan"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Golongan</option>
                                            @foreach($filterOptions['golongan'] as $gol)
                                                <option value="{{ $gol }}">{{ $gol }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                {{-- Jenis Pegawai --}}
                                <div>
                                    <label for="filter-jenis" class="block text-xs font-semibold text-muted font-sans mb-1">Jenis Pegawai</label>
                                    <div class="relative">
                                        <select id="filter-jenis" name="jenis" x-model="activeJenis"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Jenis</option>
                                            @foreach($filterOptions['jenis'] as $jenis)
                                                <option value="{{ $jenis }}">{{ $jenis }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                {{-- Status Pegawai --}}
                                <div>
                                    <label for="filter-status" class="block text-xs font-semibold text-muted font-sans mb-1">Status</label>
                                    <div class="relative">
                                        <select id="filter-status" name="status" x-model="activeStatus"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Status</option>
                                            @foreach($filterOptions['status'] as $st)
                                                <option value="{{ $st }}">{{ $st }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                {{-- Jabatan --}}
                                <div>
                                    <label for="filter-jabatan" class="block text-xs font-semibold text-muted font-sans mb-1">Jabatan</label>
                                    <div class="relative">
                                        <select id="filter-jabatan" name="jabatan" x-model="activeJabatan"
                                            class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                            <option value="">Semua Jabatan</option>
                                            <template x-for="jabatan in filterOptions.jabatan" :key="jabatan">
                                                <option :value="jabatan" x-text="jabatan"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>

                                {{-- Periode Pensiun --}}
                                <div>
                                    <label for="filter-pensiun-dari" class="block text-xs font-semibold text-muted font-sans mb-1">Pensiun dari</label>
                                    <input type="date" id="filter-pensiun-dari" name="pensiun_dari" x-model="pensiunDari"
                                        class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>
                                <div>
                                    <label for="filter-pensiun-sampai" class="block text-xs font-semibold text-muted font-sans mb-1">Pensiun sampai</label>
                                    <input type="date" id="filter-pensiun-sampai" name="pensiun_sampai" x-model="pensiunSampai" :min="pensiunDari || null"
                                        class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>

                                {{-- Urut Berdasarkan + Arah --}}
                                <div>
                                    <label for="filter-sort" class="block text-xs font-semibold text-muted font-sans mb-1">Urutkan Berdasarkan</label>
                                    <div class="flex gap-2">
                                        <div class="relative flex-1">
                                            <select id="filter-sort" name="sort" x-model="sortBy"
                                                class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                                <option value="nama">Nama Pegawai</option>
                                                <option value="nip">NIP</option>
                                                <option value="golongan">Golongan</option>
                                            </select>
                                        </div>
                                        {{-- Toggle Asc / Desc --}}
                                        <button type="button" @click="sortDir = sortDir === 'asc' ? 'desc' : 'asc'"
                                            :title="sortDir === 'asc' ? 'Ascending (A→Z / kecil→besar)' : 'Descending (Z→A / besar→kecil)'"
                                            x-bind:aria-label="sortDir === 'asc' ? 'Arah urutan: naik. Klik untuk mengubah ke menurun.' : 'Arah urutan: menurun. Klik untuk mengubah ke naik.'"
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
                                    <label for="filter-prefix-value" class="block text-xs font-semibold text-muted font-sans mb-1">
                                        Filter Awalan
                                        <span class="ml-1 text-muted font-normal">— tampilkan data yang diawali karakter tertentu</span>
                                    </label>
                                    <div class="flex gap-2">
                                        {{-- Pilih field untuk prefix --}}
                                        <div class="relative w-44 shrink-0">
                                            <select id="filter-prefix-field" name="prefix_field" x-model="prefixField"
                                                aria-label="Kolom yang dipakai filter awalan"
                                                class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                                <template x-for="(col, key) in columns" :key="key">
                                                    <option :value="col.key" x-text="col.label"></option>
                                                </template>
                                            </select>
                                        </div>
                                        {{-- Input awalan --}}
                                        <div class="relative flex-1">
                                            <input type="text" id="filter-prefix-value" name="prefix_value" x-model="prefixValue" @keydown.enter.prevent
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
                            <p x-cloak x-show="pensiunError" role="alert" class="mt-2 text-sm text-danger" x-text="pensiunError"></p>
                        </div>

                        <div class="border-t border-border"></div>

                        {{-- === BAGIAN 3: RANGE BARIS === --}}
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-widest text-muted font-sans mb-3">Range Baris</h3>
                            <div class="flex flex-wrap items-end gap-4">
                                <div>
                                    <label for="filter-row-start" class="block text-xs font-semibold text-muted font-sans mb-1">Dari Baris</label>
                                    <input type="number" id="filter-row-start" name="row_start" x-model.number="rowStart" min="1" placeholder="1"
                                        class="h-10 w-28 rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans" />
                                </div>
                                <div class="text-muted font-sans text-sm pb-2.5">–</div>
                                <div>
                                    <label for="filter-row-end" class="block text-xs font-semibold text-muted font-sans mb-1">Sampai Baris</label>
                                    <input type="number" id="filter-row-end" name="row_end" x-model.number="rowEnd" min="1" :placeholder="filteredPegawai.length || 'Semua'"
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
        </form>

        {{-- ============================================================ --}}
        {{-- TABLE PRATINJAU                                              --}}
        {{-- ============================================================ --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-6 py-4 border-b border-border bg-surface">
                <h3 class="text-sm font-semibold text-ink font-sans">Pratinjau Data Export</h3>
                <p x-cloak x-show="previewLoading" role="status" aria-live="polite" class="text-xs font-medium text-muted font-sans">Memperbarui pratinjau…</p>
            </div>
            <p x-cloak x-show="previewError" role="alert" class="px-6 pt-4 text-sm text-danger font-sans" x-text="previewError"></p>

            {{-- Table --}}
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th class="w-14 text-center select-none">No</x-ui.table-th>
                            <template x-for="col in activeColumns" :key="col.key">
                                <x-ui.table-th class="select-none" x-text="col.label"></x-ui.table-th>
                            </template>
                            <template x-if="activeColumns.length === 0">
                                <x-ui.table-th>Pilih minimal satu kolom</x-ui.table-th>
                            </template>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        {{-- Hanya baris halaman aktif yang dirender; berkas PDF/Excel dibentuk backend. --}}
                        <template x-for="(row, index) in paginatedPreview" :key="row.id">
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td class="w-14 text-center whitespace-nowrap" x-text="(currentPage - 1) * perPage + index + 1"></x-ui.table-td>
                                <template x-for="col in activeColumns" :key="col.key">
                                    <x-ui.table-td class="whitespace-nowrap" x-text="getCellValue(row, col.key)"></x-ui.table-td>
                                </template>
                            </x-ui.table-row>
                        </template>

                        {{-- Empty state --}}
                        <tr x-show="exportRows.length === 0">
                            <td :colspan="activeColumns.length + 1" class="px-0 py-0">
                                <x-ui.empty-state icon="document" title="Tidak ada data yang cocok dengan konfigurasi Anda." />
                            </td>
                        </tr>
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- Footer: Paginasi --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <div class="flex items-center gap-3 text-sm text-muted">
                    <span class="whitespace-nowrap font-sans">Tampilkan</span>
                    <select x-model.number="perPage" @change="currentPage = 1" aria-label="Jumlah data pratinjau per halaman" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center w-auto">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span class="hidden sm:inline font-sans">data</span>

                    {{-- Meta Info --}}
                    <div class="hidden md:block ml-2 border-l border-border pl-4 font-sans" x-show="exportRows.length > 0">
                        Menampilkan
                        <span class="font-medium text-ink" x-text="exportRows.length === 0 ? 0 : (currentPage - 1) * perPage + 1"></span>
                        – <span class="font-medium text-ink" x-text="Math.min(currentPage * perPage, exportRows.length)"></span>
                        dari <span class="font-medium text-ink" x-text="exportRows.length"></span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    <x-ui.pagination current="currentPage" total="totalPages" />
                </div>
            </div>
        </x-ui.card>

    </div>

    @push('scripts')
    <script>
        const registerExportPegawai = () => {
            Alpine.data('exportPegawai', (initialPegawai = [], initialFilterOptions = {}, initialFilters = {}) => ({
                // =====================================================================
                // DATA SOURCE — diisi dari PHP
                // =====================================================================
                allPegawai: initialPegawai,
                filterOptions: initialFilterOptions,
                previewEndpoint: @js(route('laporan.pegawai.preview')),
                pdfEndpoint: @js(route('laporan.pegawai.pdf')),
                maxPdfRows: @js(\App\Actions\Laporan\ExportPegawaiPdfAction::MAX_ROWS),

                // =====================================================================
                // FILTER STATE
            // =====================================================================
            searchQuery: initialFilters.search ?? '',
            activeUnit: initialFilters.unit ?? '',
            activeGolongan: initialFilters.golongan ?? '',
            activeJenis: initialFilters.jenis ?? '',
            activeStatus: initialFilters.status ?? 'Aktif',
            activeJabatan: initialFilters.jabatan ?? '',
            pensiunDari: initialFilters.pensiun_dari ?? '',
            pensiunSampai: initialFilters.pensiun_sampai ?? '',
            sortBy: initialFilters.sort === 'no' ? 'nama' : (initialFilters.sort ?? 'nama'),
            sortDir: initialFilters.sort_dir ?? 'asc',
            prefixField: initialFilters.prefix_field ?? 'nama',
            prefixValue: initialFilters.prefix_value ?? '',

            // =====================================================================
            // KONFIGURASI EXPORT (Column Picker & Row Range)
            // =====================================================================
            columns: {
                nip:             { label: 'NIP',                 active: true,  key: 'nip' },
                nama:            { label: 'Nama Pegawai',        active: true,  key: 'nama' },
                golongan:        { label: 'Golongan',            active: true,  key: 'golongan' },
                jabatan:         { label: 'Jabatan',             active: true,  key: 'jabatan' },
                unit:            { label: 'Unit Kerja',          active: true,  key: 'unit' },
                jenis:           { label: 'Jenis Pegawai',       active: true,  key: 'jenis' },
                status:          { label: 'Status',              active: true,  key: 'status' },
                pendidikan:      { label: 'Pendidikan Terakhir', active: false, key: 'pendidikan' },
                tanggal_pensiun: { label: 'Tgl. Pensiun',        active: false, key: 'tanggal_pensiun' },
            },
            columnOrder: ['nip', 'nama', 'golongan', 'jabatan', 'unit', 'jenis', 'status', 'pendidikan', 'tanggal_pensiun'],
            rowStart: initialFilters.row_start ?? 1,
            rowEnd: initialFilters.row_end ?? '',

            // =====================================================================
            // UI STATE
            // =====================================================================
            configOpen: true,
            currentPage: 1,
            perPage: 10,
            previewLoading: false,
            previewError: '',
            previewRequestId: 0,
            previewRefreshTimer: null,
            pdfError: '',
            exportError: @js($errors->first('columns') ?: $errors->first('columns.0')),
            pensiunError: @js($errors->first('pensiun_dari') ?: $errors->first('pensiun_sampai')),

            // =====================================================================
            // WATCHERS
            // =====================================================================
            init() {
                [
                    'searchQuery', 'activeUnit', 'activeGolongan', 'activeJenis', 'activeStatus',
                    'activeJabatan', 'pensiunDari', 'pensiunSampai', 'sortBy', 'sortDir',
                    'prefixField', 'prefixValue', 'rowStart', 'rowEnd',
                ].forEach((field) => this.$watch(field, () => {
                    this.currentPage = 1;
                    this.pdfError = '';
                    this.queuePreviewRefresh();
                }));
            },

            // =====================================================================
            // PREVIEW: Backend adalah sumber kanonis untuk filter, urutan, dan range.
            // Browser hanya menampilkan data yang dikembalikan endpoint preview.
            // =====================================================================
            get filteredPegawai() {
                return this.allPegawai;
            },

            // Data telah difilter, diurutkan, dan diberi range oleh backend.
            get exportRows() {
                return this.allPegawai;
            },

            queuePreviewRefresh() {
                const requestId = ++this.previewRequestId;

                if (this.previewRefreshTimer !== null) {
                    window.clearTimeout(this.previewRefreshTimer);
                }

                if (this.pensiunDari && this.pensiunSampai && this.pensiunSampai < this.pensiunDari) {
                    this.pensiunError = 'Tanggal pensiun sampai harus sama dengan atau setelah tanggal pensiun dari.';
                    this.previewLoading = false;

                    return;
                }

                this.pensiunError = '';
                this.previewLoading = true;
                this.previewRefreshTimer = window.setTimeout(() => this.refreshPreview(requestId), 350);
            },

            previewParams() {
                const params = new URLSearchParams();
                const values = {
                    search: this.searchQuery.trim(),
                    unit: this.activeUnit,
                    golongan: this.activeGolongan,
                    jenis: this.activeJenis,
                    status: this.activeStatus,
                    jabatan: this.activeJabatan,
                    pensiun_dari: this.pensiunDari,
                    pensiun_sampai: this.pensiunSampai,
                    sort: this.sortBy,
                    sort_dir: this.sortDir,
                    prefix_field: this.prefixField,
                    prefix_value: this.prefixValue.trim(),
                    row_start: Math.max(1, Number(this.rowStart) || 1),
                    row_end: this.rowEnd,
                };

                Object.entries(values).forEach(([key, value]) => {
                    if (key === 'status' || (value !== '' && value !== null && value !== undefined)) {
                        params.set(key, String(value));
                    }
                });

                return params;
            },

            async refreshPreview(requestId) {
                const params = this.previewParams();
                this.previewRefreshTimer = null;
                this.previewLoading = true;
                this.previewError = '';

                try {
                    const response = await fetch(`${this.previewEndpoint}?${params.toString()}`, {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json().catch(() => null);

                    if (!response.ok || !Array.isArray(payload?.pegawai)) {
                        throw new Error(payload?.message ?? 'Pratinjau tidak dapat diperbarui. Muat ulang halaman dan masuk kembali bila sesi berakhir.');
                    }

                    if (requestId !== this.previewRequestId) {
                        return;
                    }

                    this.allPegawai = payload?.pegawai ?? [];
                    const query = params.toString();
                    window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
                } catch (error) {
                    if (requestId === this.previewRequestId) {
                        this.previewError = error.message || 'Pratinjau tidak dapat diperbarui. Coba lagi.';
                    }
                } finally {
                    if (requestId === this.previewRequestId) {
                        this.previewLoading = false;
                    }
                }
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
                return this.columnOrder
                    .map((key) => this.columns[key])
                    .filter((column) => column?.active);
            },

            selectAllColumns()   { Object.keys(this.columns).forEach(k => this.columns[k].active = true); },
            deselectAllColumns() { Object.keys(this.columns).forEach(k => this.columns[k].active = k === 'nama'); },

            moveColumn(columnKey, direction) {
                const activeKeys = this.activeColumns.map((column) => column.key);
                const activeIndex = activeKeys.indexOf(columnKey);
                const targetKey = activeKeys[activeIndex + direction];

                if (!targetKey) {
                    return;
                }

                const sourceIndex = this.columnOrder.indexOf(columnKey);
                const targetIndex = this.columnOrder.indexOf(targetKey);
                const order = [...this.columnOrder];
                [order[sourceIndex], order[targetIndex]] = [order[targetIndex], order[sourceIndex]];
                this.columnOrder = order;
            },

            submitCustomExport(event) {
                this.exportError = '';
                this.pensiunError = '';

                if (this.activeColumns.length === 0) {
                    event.preventDefault();
                    this.exportError = 'Pilih minimal satu kolom untuk export custom.';
                    this.configOpen = true;
                    this.$nextTick(() => this.$refs.columnConfiguration.focus());

                    return;
                }

                if (this.pensiunDari && this.pensiunSampai && this.pensiunSampai < this.pensiunDari) {
                    event.preventDefault();
                    this.pensiunError = 'Tanggal pensiun sampai harus sama dengan atau setelah tanggal pensiun dari.';
                    this.configOpen = true;
                }
            },

            getCellValue(row, key) {
                return row[key] ?? '-';
            },

            // Mengunduh PDF melalui rute backend resmi supaya isi berkas, gerbang peran,
            // dan batas baris ditentukan server. Cetak browser tidak dipakai agar tidak ada
            // dua jalur PDF dengan hasil berbeda.
            exportPdf() {
                if (this.previewLoading || this.previewError || this.pensiunError) {
                    return;
                }

                // Pesan penyempitan filter ditampilkan lebih awal; backend tetap menolak
                // permintaan yang melewati batas sebagai penegakan sesungguhnya.
                if (this.exportRows.length > this.maxPdfRows) {
                    this.pdfError = `Laporan memuat ${this.exportRows.length} baris, melebihi batas ${this.maxPdfRows}. Persempit filter lalu coba lagi.`;

                    return;
                }

                this.pdfError = '';
                window.location.assign(`${this.pdfEndpoint}?${this.previewParams().toString()}`);
            }
            }));
        };

        if (window.Alpine) {
            registerExportPegawai();
        } else {
            document.addEventListener('alpine:init', registerExportPegawai);
        }
    </script>
    @endpush

</x-layouts.app>
