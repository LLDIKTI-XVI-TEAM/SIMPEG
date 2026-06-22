<x-layouts.app title="Arsip Dokumen Kepegawaian">

    @php
    $dokumen = [
        [
            'id' => 1,
            'jenis' => 'SK Kenaikan Pangkat',
            'nama' => 'SK Kenaikan Pangkat Penata Tkt. I',
            'nomor' => 'SK-882-KP-2024',
            'tanggal' => '2024-04-01',
            'kategori' => 'sk_pangkat',
            'kategori_label' => 'SK Kenaikan Pangkat',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_sk_pangkat_2024.pdf',
            'file_size' => '1.2 MB',
            'deskripsi' => 'SK Kenaikan Pangkat Penata Tingkat I Golongan Ruang III/d atas nama Ahmad Fauzi.'
        ],
        [
            'id' => 2,
            'jenis' => 'SK Kenaikan Jabatan',
            'nama' => 'SK Pengangkatan Jabatan Analis Kepegawaian',
            'nomor' => 'SK-104-JAB-2022',
            'tanggal' => '2022-08-15',
            'kategori' => 'sk_jabatan',
            'kategori_label' => 'SK Kenaikan Jabatan',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_sk_jabatan_2022.pdf',
            'file_size' => '850 KB',
            'deskripsi' => 'SK Pengangkatan Pertama kali dalam Jabatan Fungsional Analis Kepegawaian Ahli Pertama.'
        ],
        [
            'id' => 3,
            'jenis' => 'SK KGB',
            'nama' => 'SK Kenaikan Gaji Berkala 2025',
            'nomor' => 'KGB-334-VII-2025',
            'tanggal' => '2025-07-01',
            'kategori' => 'sk_kgb',
            'kategori_label' => 'SK KGB',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_sk_kgb_2025.pdf',
            'file_size' => '420 KB',
            'deskripsi' => 'Surat Keterangan Kenaikan Gaji Berkala Reguler tahun berjalan 2025.'
        ],
        [
            'id' => 4,
            'jenis' => 'Ijazah',
            'nama' => 'Ijazah Sarjana (S1) Manajemen',
            'nomor' => 'IJZ-S1-MAN-2007',
            'tanggal' => '2007-09-20',
            'kategori' => 'ijazah',
            'kategori_label' => 'Ijazah',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_ijazah_s1.pdf',
            'file_size' => '2.1 MB',
            'deskripsi' => 'Ijazah Sarjana S1 Program Studi Manajemen dari Universitas Sam Ratulangi.'
        ],
        [
            'id' => 5,
            'jenis' => 'KTP',
            'nama' => 'Kartu Tanda Penduduk (KTP)',
            'nomor' => '3171-7403-8803-0001',
            'tanggal' => '2021-05-10',
            'kategori' => 'ktp_kk',
            'kategori_label' => 'KTP & KK',
            'nama_pegawai' => 'Siti Rahayu',
            'nip_pegawai' => '19901120201501 2 003',
            'unit_pegawai' => 'Bag. Keuangan',
            'file_path' => 'employees/docs/199011202015012003_ktp.pdf',
            'file_size' => '620 KB',
            'deskripsi' => 'KTP atas nama Siti Rahayu.'
        ],
        [
            'id' => 6,
            'jenis' => 'KK',
            'nama' => 'Kartu Keluarga (KK)',
            'nomor' => '3171-7403-8803-0002',
            'tanggal' => '2021-05-10',
            'kategori' => 'ktp_kk',
            'kategori_label' => 'KTP & KK',
            'nama_pegawai' => 'Siti Rahayu',
            'nip_pegawai' => '19901120201501 2 003',
            'unit_pegawai' => 'Bag. Keuangan',
            'file_path' => 'employees/docs/199011202015012003_kk.pdf',
            'file_size' => '580 KB',
            'deskripsi' => 'Kartu Keluarga terbaru atas nama kepala keluarga Siti Rahayu.'
        ],
        [
            'id' => 7,
            'jenis' => 'SK Pengangkatan',
            'nama' => 'SK Pengangkatan PNS 2026',
            'nomor' => 'SK-220-PNS-2026',
            'tanggal' => '2026-01-01',
            'kategori' => 'sk_pengangkatan',
            'kategori_label' => 'SK Pengangkatan',
            'nama_pegawai' => 'Sabrina Rossa Adriani Wibowo',
            'nip_pegawai' => '20261210820500 0 04',
            'unit_pegawai' => 'Bag. SDM',
            'file_path' => 'employees/docs/20261210820500004_sk_pns.pdf',
            'file_size' => '1.5 MB',
            'deskripsi' => 'SK Pengangkatan PNS atas nama Sabrina Rossa.'
        ]
    ];

    $pegawaiList = [
        ['nama' => 'Ahmad Fauzi', 'nip' => '19850312201001 1 001'],
        ['nama' => 'Siti Rahayu', 'nip' => '19901120201501 2 003'],
        ['nama' => 'Sabrina Rossa Adriani Wibowo', 'nip' => '20261210820500 0 04'],
        ['nama' => 'Cimma Sari Oktariani Di Silapu', 'nip' => '26110820520600 0 04'],
        ['nama' => 'Nurarningsih Dumbea, S.P.', 'nip' => '19880123202 1 005'],
        ['nama' => 'Nadia Kusuma', 'nip' => '19950822202001 2 002'],
        ['nama' => 'Yucna Dara, S.P., M.M.', 'nip' => '19840120099 2 002']
    ];
    @endphp

    <div x-data="{
        activeKategori: '',
        activeUnit: '',
        activePegawai: '',
        searchQuery: '',
        showUploadModal: false,
        documents: {{ json_encode($dokumen) }},
        init() {
            const urlParams = new URLSearchParams(window.location.search);
            const filterParam = urlParams.get('filter');
            if (filterParam === 'kadaluarsa') {
                this.activeKategori = 'sk_pengangkatan';
            }
        },
        get filteredDocuments() {
            return this.documents.filter(doc => {
                const matchesSearch = doc.nama.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                                       doc.nomor.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                       doc.jenis.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                       (doc.nama_pegawai && doc.nama_pegawai.toLowerCase().includes(this.searchQuery.toLowerCase())) ||
                                       (doc.nip_pegawai && doc.nip_pegawai.toLowerCase().includes(this.searchQuery.toLowerCase()));
                const matchesKategori = !this.activeKategori || doc.kategori === this.activeKategori;
                const matchesUnit = !this.activeUnit || doc.unit_pegawai === this.activeUnit;
                const matchesPegawai = !this.activePegawai || doc.nip_pegawai === this.activePegawai;
                return matchesSearch && matchesKategori && matchesUnit && matchesPegawai;
            });
        }
    }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Arsip Dokumen Kepegawaian</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Arsip Dokumen</span>
                </nav>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                {{-- Upload Button --}}
                <button
                    @click="showUploadModal = true"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Unggah Dokumen Baru
                </button>
            </div>
        </div>

        {{-- FILTER BAR --}}
        <div class="rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {{-- Search input --}}
                <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 col-span-1 sm:col-span-2 lg:col-span-1">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input type="text" x-model="searchQuery" placeholder="Cari nama, nomor, jenis..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>

                {{-- Filter Pegawai --}}
                <div class="relative">
                    <select x-model="activePegawai" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Pegawai</option>
                        @foreach($pegawaiList as $p)
                            <option value="{{ $p['nip'] }}">{{ $p['nama'] }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Unit Kerja --}}
                <div class="relative">
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

                {{-- Filter Kategori Dokumen --}}
                <div class="relative col-span-1 sm:col-span-2 lg:col-span-2">
                    <select x-model="activeKategori" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Kategori Dokumen</option>
                        <option value="sk_pengangkatan">SK Pengangkatan</option>
                        <option value="sk_pangkat">SK Kenaikan Pangkat</option>
                        <option value="sk_jabatan">SK Kenaikan Jabatan</option>
                        <option value="sk_kgb">SK KGB (Kenaikan Gaji Berkala)</option>
                        <option value="ijazah">Ijazah / Pendidikan</option>
                        <option value="ktp_kk">Identitas Diri (KTP & KK)</option>
                        <option value="lainnya">Lampiran / Dokumen Lain</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- TABLE CARD --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="px-6 py-4 border-b border-border bg-surface">
                <h3 class="text-sm font-semibold text-ink font-sans">Daftar Arsip Dokumen & SK</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Menampilkan seluruh data berkas fisik pendukung kepegawaian LLDIKTI Wilayah XVI.</p>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Pegawai</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Nama Dokumen</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Kategori</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Nomor Dokumen</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Tanggal Dokumen</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Ukuran</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="doc in filteredDocuments" :key="doc.id">
                            <tr class="transition-colors hover:bg-soft/50">
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="flex h-7.5 w-7.5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                            <span x-text="doc.nama_pegawai.substring(0,1)"></span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-ink font-sans leading-tight" x-text="doc.nama_pegawai"></p>
                                            <p class="font-mono text-[10px] text-muted leading-none mt-0.5" x-text="doc.nip_pegawai"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 max-w-xs">
                                    <div class="flex items-start gap-2.5">
                                        {{-- Document File Icon --}}
                                        <div class="flex h-9 w-7 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-0.5 shadow-sm relative">
                                            <div class="w-full space-y-0.5 mt-0.5">
                                                <div class="h-0.5 w-3 bg-muted/40 rounded-full mx-auto"></div>
                                                <div class="h-0.5 w-2 bg-muted/40 rounded-full mx-auto"></div>
                                            </div>
                                            <div class="w-full bg-primary/10 text-primary text-[5px] font-bold text-center py-0.5 uppercase tracking-wide" x-text="doc.file_path.split('.').pop()">
                                                PDF
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-ink font-sans truncate" x-text="doc.nama"></p>
                                            <p class="text-xs text-muted font-sans mt-0.5 truncate" x-text="doc.deskripsi"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-xs text-muted font-sans" x-text="doc.kategori_label"></td>
                                <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.nomor"></td>
                                <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.tanggal"></td>
                                <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.file_size"></td>
                                <td class="px-4 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <a :href="'/dashboard/dokumen/' + doc.id" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                            Detail
                                        </a>
                                        <span class="text-border">|</span>
                                        <a :href="'/dashboard/dokumen/' + doc.id + '/download'" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                            Unduh
                                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filteredDocuments.length === 0">
                            <td colspan="7" class="px-6 py-8 text-center text-xs text-muted font-sans">
                                Tidak ada dokumen yang cocok dengan filter atau pencarian Anda.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">Menampilkan 1 - <span x-text="filteredDocuments.length"></span> dari <span x-text="filteredDocuments.length"></span> data</p>
                    <div class="relative">
                        <select id="per-page" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option>10 / halaman</option>
                            <option>25 / halaman</option>
                            <option>50 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    {{-- Prev --}}
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </button>
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-primary bg-primary text-sm font-semibold text-white transition hover:opacity-90 font-sans">1</button>
                    {{-- Next --}}
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- MODAL UNGGAH DOKUMEN BARU (POPUP) --}}
        {{-- ================================================================ --}}
        <div x-show="showUploadModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4" style="display: none;" x-transition>
            <div @click.outside="showUploadModal = false" class="w-full max-w-lg rounded-lg border border-border bg-surface p-6 shadow-xl space-y-6">
                
                {{-- Modal Header --}}
                <div class="flex justify-between items-center border-b border-border pb-3">
                    <h3 class="text-base font-semibold text-ink font-sans">Unggah Dokumen Kepegawaian</h3>
                    <button @click="showUploadModal = false" class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none">Tutup</button>
                </div>
                
                <form action="{{ route('dokumen.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf

                    {{-- Relasi Pegawai --}}
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Hubungkan ke Pegawai <span class="text-danger">*</span></label>
                        <select name="pegawai_id" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="">Pilih Pegawai...</option>
                            @foreach($pegawaiList as $p)
                                <option value="{{ $p['nip'] }}">{{ $p['nama'] }} (NIP. {{ $p['nip'] }})</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Kategori Dokumen --}}
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Kategori Dokumen <span class="text-danger">*</span></label>
                        <select name="kategori_dokumen" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="sk_pengangkatan">SK Pengangkatan</option>
                            <option value="sk_pangkat">SK Kenaikan Pangkat</option>
                            <option value="sk_jabatan">SK Kenaikan Jabatan</option>
                            <option value="sk_kgb">SK KGB (Kenaikan Gaji Berkala)</option>
                            <option value="ijazah">Ijazah / Pendidikan</option>
                            <option value="ktp_kk">Identitas Diri (KTP & KK)</option>
                            <option value="lainnya">Lampiran / Dokumen Lain</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Nama Dokumen <span class="text-danger">*</span></label>
                        <input type="text" name="nama_dokumen" required placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Nomor Dokumen <span class="text-danger">*</span></label>
                            <input type="text" name="nomor_dokumen" required placeholder="SK-..." class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal Dokumen <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_terbit" required class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Keterangan / Deskripsi</label>
                        <textarea name="deskripsi" rows="3" placeholder="Tulis rincian atau catatan singkat mengenai dokumen..." class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none font-sans"></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Pilih File Berkas <span class="text-danger">*</span></label>
                        <input type="file" name="berkas" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <p class="text-[10px] text-muted mt-1 font-sans">Format yang diizinkan: **PDF, DOC, DOCX, JPG, JPEG, PNG**. Ukuran maksimal 10MB.</p>
                    </div>

                    {{-- Buttons --}}
                    <div class="flex justify-end gap-3 pt-4 border-t border-border">
                        <button type="button" @click="showUploadModal = false" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none">
                            Batal
                        </button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                            Mulai Unggah
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</x-layouts.app>
