<x-layouts.app title="Laporan - Export Rekap Cuti">

    <div x-data="{
        activeBulan: '',
        activeTahun: '',
        activeUnit: '',
        activePegawai: '',
        activeJenis: '',
        currentPage: 1,
        perPage: 5,
        documents: {{ json_encode($riwayatCuti) }},
        init() {
            this.$watch('activeBulan', () => this.currentPage = 1);
            this.$watch('activeTahun', () => this.currentPage = 1);
            this.$watch('activeUnit', () => this.currentPage = 1);
            this.$watch('activePegawai', () => this.currentPage = 1);
            this.$watch('activeJenis', () => this.currentPage = 1);
        },
        get filteredCuti() {
            return this.documents.filter(c => {
                let matchesBulan = true;
                let matchesTahun = true;
                
                if (c.mulai) {
                    const parts = c.mulai.split('-'); // YYYY-MM-DD
                    const year = parts[0];
                    const month = parseInt(parts[1]).toString(); // 1-12
                    
                    if (this.activeBulan) {
                        matchesBulan = month === this.activeBulan;
                    }
                    if (this.activeTahun) {
                        matchesTahun = year === this.activeTahun;
                    }
                }
                
                const matchesUnit = !this.activeUnit || c.unit === this.activeUnit;
                const matchesPegawai = !this.activePegawai || c.nip === this.activePegawai;
                const matchesJenis = !this.activeJenis || c.jenis === this.activeJenis;
                
                return matchesBulan && matchesTahun && matchesUnit && matchesPegawai && matchesJenis;
            });
        },
        get paginatedCuti() {
            const start = (this.currentPage - 1) * this.perPage;
            const end = start + this.perPage;
            return this.filteredCuti.slice(start, end);
        },
        get totalPages() {
            return Math.ceil(this.filteredCuti.length / this.perPage) || 1;
        },
        get getPeriodeLabel() {
            if (!this.activeBulan && !this.activeTahun) return 'Semua Periode';
            const namaBulan = {
                '1': 'Januari', '2': 'Februari', '3': 'Maret', '4': 'April',
                '5': 'Mei', '6': 'Juni', '7': 'Juli', '8': 'Agustus',
                '9': 'September', '10': 'Oktober', '11': 'November', '12': 'Desember'
            };
            const bulan = this.activeBulan ? namaBulan[this.activeBulan] : '';
            const tahun = this.activeTahun ? this.activeTahun : '';
            return `${bulan} ${tahun}`.trim();
        },
        printReport() {
            window.print();
        }
    }" class="space-y-6">

        {{-- PRINT ONLY HEADER (Kop Surat Resmi) --}}
        <div class="hidden print:block mb-8">
            <div class="text-center border-b-2 border-ink pb-4">
                <h1 class="text-xl font-bold uppercase font-sans">Kementerian Pendidikan Tinggi, Sains, dan Teknologi</h1>
                <h2 class="text-lg font-bold uppercase font-sans text-primary">Lembaga Layanan Pendidikan Tinggi (LLDIKTI) Wilayah XVI</h2>
                <p class="text-xs text-muted mt-1 font-sans">Jl. Prof. Dr. Aloei Saboe, Wongkaditi, Kota Gorontalo</p>
                <div class="mt-6 font-bold uppercase font-sans tracking-wide text-sm underline">Laporan Rekap Cuti Pegawai</div>
                <p class="text-xs text-ink mt-2 font-sans font-semibold">Periode Laporan: <span x-text="getPeriodeLabel"></span></p>
                <p class="text-[10px] text-muted mt-1 font-sans">Tanggal Cetak: {{ now()->translatedFormat('d F Y') }}</p>
            </div>
        </div>

        {{-- PAGE HEADER (Screen only) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between print:hidden">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Laporan & Export Rekap Cuti</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Export Cuti</span>
                </nav>
            </div>
            
            <div class="flex shrink-0 items-center gap-3">
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
                    :href="'/laporan/export-cuti/excel?bulan=' + activeBulan + '&tahun=' + activeTahun + '&unit=' + activeUnit + '&pegawai=' + activePegawai + '&jenis=' + activeJenis"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Export Excel (.xlsx)
                </a>
            </div>
        </div>

        {{-- FILTER BAR (Screen only) --}}
        <x-ui.card padding="sm" class="flex flex-col gap-4 print:hidden">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12">
                {{-- Periode Bulan --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-2">
                    <select x-model="activeBulan" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Bulan</option>
                        <option value="1">Januari</option>
                        <option value="2">Februari</option>
                        <option value="3">Maret</option>
                        <option value="4">April</option>
                        <option value="5">Mei</option>
                        <option value="6">Juni</option>
                        <option value="7">Juli</option>
                        <option value="8">Agustus</option>
                        <option value="9">September</option>
                        <option value="10">Oktober</option>
                        <option value="11">November</option>
                        <option value="12">Desember</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Periode Tahun --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-2">
                    <select x-model="activeTahun" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Tahun</option>
                        <option>2026</option>
                        <option>2025</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Unit Kerja --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-3">
                    <select x-model="activeUnit" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Unit Kerja</option>
                        <option>Bag. Umum</option>
                        <option>Bag. Keuangan</option>
                        <option>Bag. SDM</option>
                        <option>Bag. IT</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Pegawai --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-3">
                    <select x-model="activePegawai" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Pegawai</option>
                        @foreach($pegawai as $p)
                            <option value="{{ $p['nip'] }}">{{ $p['nama'] }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Jenis Cuti --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-2">
                    <select x-model="activeJenis" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Cuti</option>
                        <option>Cuti Tahunan</option>
                        <option>Cuti Sakit</option>
                        <option>Cuti Melahirkan</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden print:border-none print:shadow-none print:bg-transparent">
            {{-- Header (Screen only) --}}
            <div class="px-6 py-4 border-b border-border bg-surface print:hidden">
                <h3 class="text-sm font-semibold text-ink font-sans">Pratinjau Rekap Penggunaan Cuti</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Menampilkan pengajuan riwayat cuti pegawai berdasarkan filter pencarian dan periode laporan di atas.</p>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto print:overflow-visible">
                <x-ui.table class="print:border-collapse print:border print:border-black">
                    <x-ui.table-head class="border-b border-border print:bg-gray-100">
                        <x-ui.table-row>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">No</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">NIP</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Nama Pegawai</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Tanggal Mulai</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Tanggal Selesai</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Durasi</x-ui.table-th>
                            <x-ui.table-th class="select-none print:border print:border-black print:text-black">Status</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="print:divide-y print:divide-black">
                        {{-- SCREEN VIEW --}}
                        <template x-for="(c, index) in paginatedCuti" :key="c.id">
                            <x-ui.table-row :interactive="true" class="print:hidden">
                                <x-ui.table-td x-text="(currentPage - 1) * perPage + index + 1" class="font-mono text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="c.nip" class="font-mono"></x-ui.table-td>
                                <x-ui.table-td x-text="c.nama" class="font-bold"></x-ui.table-td>
                                <x-ui.table-td x-text="c.jenis"></x-ui.table-td>
                                <x-ui.table-td x-text="c.mulai" class="font-mono text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="c.selesai" class="font-mono text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="c.hari + ' hari'"></x-ui.table-td>
                                <x-ui.table-td>
                                    <x-ui.badge
                                        variant="none"
                                        size="sm"
                                        x-bind:class="c.status === 'disetujui' ? 'border-success/20 bg-success/10 text-success' : 'border-warning/25 bg-warning/10 text-warning'"
                                        x-text="c.status"
                                    ></x-ui.badge>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </template>

                        {{-- PRINT ONLY VIEW --}}
                        <template x-for="(c, index) in filteredCuti" :key="'print-' + c.id">
                            <x-ui.table-row class="hidden print:table-row">
                                <x-ui.table-td x-text="index + 1" class="px-4 py-2 font-mono border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.nip" class="px-4 py-2 font-mono border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.nama" class="px-4 py-2 font-bold border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.jenis" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.mulai" class="px-4 py-2 font-mono border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.selesai" class="px-4 py-2 font-mono border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.hari + ' Hari'" class="px-4 py-2 border border-black"></x-ui.table-td>
                                <x-ui.table-td x-text="c.status" class="px-4 py-2 border border-black"></x-ui.table-td>
                            </x-ui.table-row>
                        </template>

                        <x-ui.table-row x-show="filteredCuti.length === 0">
                            <x-ui.table-td colspan="8" align="center" class="px-6 py-8 text-muted print:border print:border-black">
                                Tidak ada data cuti yang cocok dengan filter Anda.
                            </x-ui.table-td>
                        </x-ui.table-row>
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER (Screen only) --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface print:hidden">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">
                        Menampilkan <span x-text="filteredCuti.length === 0 ? 0 : (currentPage - 1) * perPage + 1"></span> - <span x-text="Math.min(currentPage * perPage, filteredCuti.length)"></span> dari <span x-text="filteredCuti.length"></span> data
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
                    {{-- Prev --}}
                    <x-ui.button type="button" variant="muted" size="icon" @click="if (currentPage > 1) currentPage--"
                            x-bind:disabled="currentPage === 1"
                            x-bind:class="currentPage === 1 ? 'opacity-50 cursor-not-allowed text-muted' : 'hover:bg-soft hover:text-ink text-ink cursor-pointer'"
                            aria-label="Halaman sebelumnya">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </x-ui.button>
                    
                    <template x-for="page in totalPages" :key="page">
                        <button @click="currentPage = page"
                                :class="currentPage === page ? 'bg-primary text-white border-primary' : 'bg-surface text-ink hover:bg-soft border-border'"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border text-sm font-semibold transition font-sans cursor-pointer"
                                x-text="page">
                        </button>
                    </template>
                    
                    {{-- Next --}}
                    <x-ui.button type="button" variant="muted" size="icon" @click="if (currentPage < totalPages) currentPage++"
                            x-bind:disabled="currentPage === totalPages"
                            x-bind:class="currentPage === totalPages ? 'opacity-50 cursor-not-allowed text-muted' : 'hover:bg-soft hover:text-ink text-ink cursor-pointer'"
                            aria-label="Halaman berikutnya">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        {{-- SIGNATURES (Print only) --}}
        <div class="hidden print:grid grid-cols-2 gap-8 mt-12 text-sm text-ink font-sans">
            <div class="text-center">
                <p>Mengetahui,</p>
                <p class="font-semibold mt-1">Kepala LLDIKTI Wilayah XVI</p>
                <div class="h-20"></div>
                <p class="font-bold underline">Prof. Dr. Ir. Munawir Sadzali, M.T.</p>
                <p class="text-xs text-muted">NIP. 19680512199403 1 002</p>
            </div>
            <div class="text-center">
                <p>Gorontalo, {{ now()->translatedFormat('d F Y') }}</p>
                <p class="font-semibold mt-1">Pembuat Laporan / Admin</p>
                <div class="h-20"></div>
                <p class="font-bold underline">Haris Munandar, S.Sos.</p>
                <p class="text-xs text-muted">NIP. 19851120201201 1 005</p>
            </div>
        </div>

    </div>

    {{-- CUSTOM CSS PRINTING --}}
    @push('head')
    <style>
        @media print {
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
            .print\:grid {
                display: grid !important;
            }
            @page {
                size: landscape;
                margin: 1.5cm;
            }
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
        }
    </style>
    @endpush

</x-layouts.app>
