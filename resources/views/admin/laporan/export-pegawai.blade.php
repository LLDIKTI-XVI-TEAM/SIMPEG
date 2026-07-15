<x-layouts.app title="Daftar Nominatif Pegawai">

    <div x-data="{
        searchQuery: @js($initialFilters['search']),
        activeUnit: @js($initialFilters['unit']),
        activeGolongan: @js($initialFilters['golongan']),
        activeJenis: @js($initialFilters['jenis']),
        activeStatus: @js($initialFilters['status']),
        sortBy: @js($initialFilters['sort']),
        currentPage: 1,
        perPage: 5,
        documents: @js($pegawai),
        filterOptions: @js($filterOptions),
        showCustomExportModal: @js($errors->has('columns')),
        customColumns: @js(old('columns', ['nip', 'nama', 'golongan', 'jabatan', 'unit', 'jenis', 'status'])),
        customJabatan: @js(old('jabatan', '')),
        customPensiunDari: @js(old('pensiun_dari', '')),
        customPensiunSampai: @js(old('pensiun_sampai', '')),
        customError: @js($errors->first('columns')),
        init() {
            this.$watch('searchQuery', () => this.currentPage = 1);
            this.$watch('activeUnit', () => this.currentPage = 1);
            this.$watch('activeGolongan', () => this.currentPage = 1);
            this.$watch('activeJenis', () => this.currentPage = 1);
            this.$watch('activeStatus', () => this.currentPage = 1);
            this.$watch('sortBy', () => this.currentPage = 1);
        },
        get filteredPegawai() {
            let result = this.documents.filter(p => {
                const query = this.searchQuery.toLowerCase().trim();
                const matchesSearch = !query || 
                                       p.nama.toLowerCase().includes(query) ||
                                       p.nip.replace(/\s+/g, '').includes(query.replace(/\s+/g, ''));
                const matchesUnit = !this.activeUnit || p.unit === this.activeUnit;
                const matchesGolongan = !this.activeGolongan || p.golongan === this.activeGolongan;
                const matchesJenis = !this.activeJenis || p.jenis === this.activeJenis;
                const matchesStatus = !this.activeStatus || p.status === this.activeStatus;
                
                return matchesSearch && matchesUnit && matchesGolongan && matchesJenis && matchesStatus;
            });

            // Sorting
            const golonganOrder = {
                'IV/e': 1, 'IV/d': 2, 'IV/c': 3, 'IV/b': 4, 'IV/a': 5,
                'III/d': 6, 'III/c': 7, 'III/b': 8, 'III/a': 9,
                'II/d': 10, 'II/c': 11, 'II/b': 12, 'II/a': 13,
                'I/d': 14, 'I/c': 15, 'I/b': 16, 'I/a': 17
            };

            result.sort((a, b) => {
                if (this.sortBy === 'nama') {
                    return a.nama.localeCompare(b.nama);
                } else if (this.sortBy === 'nip') {
                    return a.nip.localeCompare(b.nip);
                } else if (this.sortBy === 'golongan') {
                    const rankA = golonganOrder[a.golongan] || 99;
                    const rankB = golonganOrder[b.golongan] || 99;
                    return rankA - rankB;
                }
                return 0;
            });

            return result;
        },
        get paginatedPegawai() {
            const start = (this.currentPage - 1) * this.perPage;
            const end = start + this.perPage;
            return this.filteredPegawai.slice(start, end);
        },
        get totalPages() {
            return Math.ceil(this.filteredPegawai.length / this.perPage) || 1;
        },
        printReport() {
            window.print();
        },
        submitCustomExport(event) {
            this.customError = '';
            if (!this.customColumns.length) {
                this.customError = 'Pilih minimal satu kolom untuk export custom.';
                return;
            }

            event.target.submit();
        }
    }" class="space-y-6">

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
                <div class="font-bold uppercase font-sans tracking-wide text-sm underline">Daftar Nominatif Pegawai</div>
                <p class="text-[11px] text-muted mt-1 font-sans">Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
            </div>
        </div>

        {{-- PAGE HEADER (Screen only) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between print:hidden">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Daftar Nominatif Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Daftar Nominatif Pegawai']
                ]" />
            </div>
            
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                {{-- Export PDF Button --}}
                <button
                    @click="printReport()"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.617 0-1.11-.476-1.12-1.09l-.23-2.523M19.5 10.5v.375c0 .621-.504 1.125-1.125 1.125H5.625A1.125 1.125 0 0 1 4.5 11.25v-.375m15 0V9a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 9v1.5m15 0A1.5 1.5 0 0 0 18 9h-3V6a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3H6a1.5 1.5 0 0 0-1.5 1.5" />
                    </svg>
                    Cetak / Export PDF
                </button>

                {{-- Export Excel Button --}}
                <a
                    :href="'{{ route('laporan.pegawai.excel') }}?search=' + encodeURIComponent(searchQuery) + '&unit=' + encodeURIComponent(activeUnit) + '&golongan=' + encodeURIComponent(activeGolongan) + '&jenis=' + encodeURIComponent(activeJenis) + '&status=' + encodeURIComponent(activeStatus) + '&sort=' + encodeURIComponent(sortBy)"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Export Excel (.xlsx)
                </a>

                <button
                    type="button"
                    @click="showCustomExportModal = true"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/20 bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft cursor-pointer font-sans"
                >
                    Export Custom
                </button>
            </div>
        </div>

        {{-- FILTER BAR (Screen only) --}}

        <x-ui.filter-bar
            class="sm:grid-cols-2 lg:grid-cols-12"
            searchModel="searchQuery"
            searchPlaceholder="Cari nama atau NIP..."
            searchCols="col-span-1 sm:col-span-2 lg:col-span-4"
        >
            {{-- Filter Unit Kerja --}}
            <div class="relative col-span-1 sm:col-span-1 lg:col-span-4">
                <select x-model="activeUnit" class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit Kerja</option>
                    <template x-for="unit in filterOptions.units" :key="unit">
                        <option :value="unit" x-text="unit"></option>
                    </template>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />

                    </svg>
                </div>
            </div>


            {{-- Filter Golongan --}}
            <div class="relative col-span-1 sm:col-span-1 lg:col-span-4">
                <select x-model="activeGolongan" class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Golongan</option>
                    <template x-for="golongan in filterOptions.golongan" :key="golongan">
                        <option :value="golongan" x-text="golongan"></option>
                    </template>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Jenis Pegawai --}}
            <div class="relative col-span-1 sm:col-span-1 lg:col-span-4">
                <select x-model="activeJenis" class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    <template x-for="jenis in filterOptions.jenis" :key="jenis">
                        <option :value="jenis" x-text="jenis"></option>
                    </template>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Status --}}
            <div class="relative col-span-1 sm:col-span-1 lg:col-span-4">
                <select x-model="activeStatus" class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <template x-for="status in filterOptions.status" :key="status">
                        <option :value="status" x-text="status"></option>
                    </template>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Urutkan Berdasarkan --}}
            <div class="relative col-span-1 sm:col-span-1 lg:col-span-4">
                <select x-model="sortBy" class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="nama">Urut Nama</option>
                    <option value="nip">Urut NIP</option>
                    <option value="golongan">Urut Golongan</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>
        </x-ui.filter-bar>


        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden print:border-none print:shadow-none print:bg-transparent">
            {{-- Header (Screen only) --}}
            <div class="px-6 py-4 border-b border-border bg-surface print:hidden">
                <h3 class="text-sm font-semibold text-ink font-sans">Pratinjau Daftar Nominatif Pegawai</h3>
                <p class="text-xs text-muted">Menampilkan rekap pegawai aktif yang siap diexport berdasarkan filter pencarian di atas.</p>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto print:overflow-visible">
                <x-ui.table class="print:border-collapse print:border print:border-black">
                    <x-ui.table-head class="border-b border-border print:bg-gray-100">
                        <x-ui.table-row>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">No</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">NIP</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Nama Pegawai</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Golongan</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Jabatan</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Unit Kerja</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Jenis Pegawai</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Status</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="print:divide-y print:divide-black">
                        {{-- SCREEN VIEW --}}
                        <template x-for="(p, index) in paginatedPegawai" :key="p.id">
                            <x-ui.table-row :interactive="true" class="print:hidden">
                                <x-ui.table-td x-text="(currentPage - 1) * perPage + index + 1" class="text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="p.nip" class=""></x-ui.table-td>
                                <x-ui.table-td x-text="p.nama" class="font-bold"></x-ui.table-td>
                                <x-ui.table-td x-text="p.golongan" class="text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="p.jabatan"></x-ui.table-td>
                                <x-ui.table-td x-text="p.unit" class="text-muted"></x-ui.table-td>
                                <x-ui.table-td>
                                    <span class="font-semibold text-xs"
                                          :class="p.jenis === 'PNS' ? 'text-primary' : 'text-secondary'"
                                          x-text="p.jenis"></span>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <span class="font-semibold text-xs capitalize"
                                          :class="p.status === 'Aktif' ? 'text-success' : 'text-warning'"
                                          x-text="p.status"></span>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </template>

                        {{-- PRINT ONLY VIEW (Tampilkan semua baris terfilter sekaligus) --}}
                        <template x-for="(p, index) in filteredPegawai" :key="'print-' + p.id">
                            <x-ui.table-row class="hidden print:table-row">
                                <x-ui.table-td x-text="index + 1" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.nip" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.nama" class="px-4 py-2 font-bold border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.golongan" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.jabatan" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.unit" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.jenis" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="p.status" class="px-4 py-2 border border-black"></x-ui.table-td>
                            </x-ui.table-row>
                        </template>

                        <x-ui.table-row x-show="filteredPegawai.length === 0">
                            <x-ui.table-td colspan="8" align="center" class="px-0 py-0 text-muted print:border print:border-black">
                                <x-ui.empty-state icon="document" title="Tidak ada data pegawai yang cocok dengan filter Anda." />
                            </x-ui.table-td>
                        </x-ui.table-row>
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER (Screen only) --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface print:hidden">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">
                        Menampilkan <span x-text="filteredPegawai.length === 0 ? 0 : (currentPage - 1) * perPage + 1"></span> - <span x-text="Math.min(currentPage * perPage, filteredPegawai.length)"></span> dari <span x-text="filteredPegawai.length"></span> data
                    </p>
                    <div class="relative">
                        <select id="per-page" x-model.number="perPage" @change="currentPage = 1" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option value="5">5 / halaman</option>
                            <option value="10">10 / halaman</option>
                            <option value="25">25 / halaman</option>
                            <option value="50">50 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>
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
                    <button type="button" @click="showCustomExportModal = false" class="rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft">Batal</button>
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">Download Excel Custom</button>
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
            /* Sembunyikan chrome browser dan shell */
            aside, header, nav, button, select, input, .table-footer, .print\:hidden {
                display: none !important;
            }
            body, main, div {
                background: transparent !important;
                box-shadow: none !important;
                border: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .print\:block {
                display: block !important;
            }
            .print\:table-row {
                display: table-row !important;
            }
            /* Konfigurasi cetak landscape */
            @page {
                size: landscape;
                margin: 1.5cm;
            }
            /* Table border solid black */
            table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            th, td {
                border: 1px solid #000000 !important;
                padding: 8px 10px !important;
                color: #000000 !important;
                font-size: 11px !important;
                background-color: transparent !important;
            }
            #print-footer {
                position: fixed;
                bottom: -0.5cm;
                left: 0;
                right: 0;
                border-top: 1px solid #000000;
                text-align: right;
                font-size: 10px;
                font-family: 'Poppins', sans-serif;
                color: #6B7280;
                padding-top: 5px;
            }
            #print-footer::after {
                content: "Halaman " counter(page) " dari " counter(pages);
            }
        }
    </style>
    @endpush

</x-layouts.app>
