<x-layouts.app title="Arsip Dokumen Kepegawaian">

    @php
        $dokumen = $documents->map(function ($doc) {
            $currentPosition = $doc->employee->positionHistories->first();
            $unit = $currentPosition?->unitKerja?->nama ?? '-';

            $kategoriLabels = [
                'sk_pengangkatan' => 'SK Pengangkatan',
                'sk_pangkat' => 'SK Kenaikan Pangkat',
                'sk_jabatan' => 'SK Kenaikan Jabatan',
                'sk_kgb' => 'SK KGB',
                'ijazah' => 'Ijazah',
                'ktp_kk' => 'KTP & KK',
                'lainnya' => 'Lainnya',
            ];

            return [
                'id' => $doc->id,
                'jenis' => $kategoriLabels[$doc->jenis_dokumen] ?? 'Dokumen',
                'nama' => $doc->nama_dokumen,
                'nomor' => $doc->nomor_dokumen ?? '-',
                'tanggal' => $doc->tanggal_dokumen ? $doc->tanggal_dokumen->format('Y-m-d') : '-',
                'kategori' => $doc->jenis_dokumen,
                'kategori_label' => $kategoriLabels[$doc->jenis_dokumen] ?? 'Lainnya',
                'nama_pegawai' => $doc->employee->nama_lengkap,
                'nip_pegawai' => $doc->employee->nip,
                'unit_pegawai' => $unit,
                'file_path' => $doc->file_path,
                'file_size' => '1.5 MB',
                'status_dokumen' => 'terverifikasi',
                'status_label' => 'Terverifikasi',
                'deskripsi' => $doc->keterangan ?? '',
            ];
        });
    @endphp

    <div x-data="{
        activeKategori: '',
        activeUnit: '',
        activePegawai: '',
        searchQuery: '',
        showUploadModal: false,
        documents: {{ json_encode($dokumen) }},
        currentPage: 1,
        perPage: 5,
        init() {
            const urlParams = new URLSearchParams(window.location.search);
            const filterParam = urlParams.get('filter');
            if (filterParam === 'kadaluarsa') {
                this.activeKategori = 'sk_pengangkatan';
            }
            this.$watch('searchQuery', () => this.currentPage = 1);
            this.$watch('activeKategori', () => this.currentPage = 1);
            this.$watch('activeUnit', () => this.currentPage = 1);
            this.$watch('activePegawai', () => this.currentPage = 1);
        },
        get filteredDocuments() {
            return this.documents.filter(doc => {
                const matchesSearch = doc.nama.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                                       doc.nomor.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                       doc.jenis.toLowerCase().includes(this.searchQuery.toLowerCase());
                const matchesKategori = !this.activeKategori || doc.kategori === this.activeKategori;
                const matchesUnit = !this.activeUnit || doc.unit_pegawai === this.activeUnit;
                const matchesPegawai = !this.activePegawai || doc.nip_pegawai === this.activePegawai;
                return matchesSearch && matchesKategori && matchesUnit && matchesPegawai;
            });
        },
        get paginatedDocuments() {
            const start = (this.currentPage - 1) * this.perPage;
            const end = start + this.perPage;
            return this.filteredDocuments.slice(start, end);
        },
        get totalPages() {
            return Math.ceil(this.filteredDocuments.length / this.perPage) || 1;
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
                <button @click="showUploadModal = true"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Unggah Dokumen Baru
                </button>
            </div>
        </div>

        {{-- FILTER BAR --}}
        <div class="rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Search input --}}
                <div
                    class="flex h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 col-span-1 sm:col-span-2 lg:col-span-1 focus-within:ring-2 focus-within:ring-primary/20 focus-within:border-primary">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input type="text" x-model="searchQuery" placeholder="Cari nama, nomor, jenis..."
                        class="h-full flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>

                {{-- Filter Pegawai --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-1">
                    <select x-model="activePegawai"
                        class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Pegawai</option>
                        @foreach($pegawaiList as $p)
                            <option value="{{ $p->nip }}">{{ $p->nama_lengkap }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Filter Unit Kerja --}}
                <div class="relative col-span-1 sm:col-span-1 lg:col-span-1">
                    <select x-model="activeUnit"
                        class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Unit Kerja</option>
                        <option>Bag. Umum</option>
                        <option>Bag. Keuangan</option>
                        <option>Bag. SDM</option>
                        <option>Bag. IT</option>
                    </select>
                </div>

                {{-- Filter Kategori Dokumen --}}
                <div class="relative col-span-1 sm:col-span-2 lg:col-span-1">
                    <select x-model="activeKategori"
                        class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Kategori Dokumen</option>
                        <option value="sk_pengangkatan">SK Pengangkatan</option>
                        <option value="sk_pangkat">SK Kenaikan Pangkat</option>
                        <option value="sk_jabatan">SK Kenaikan Jabatan</option>
                        <option value="sk_kgb">SK KGB (Kenaikan Gaji Berkala)</option>
                        <option value="ijazah">Ijazah / Pendidikan</option>
                        <option value="ktp_kk">Identitas Diri (KTP & KK)</option>
                        <option value="lainnya">Lampiran / Dokumen Lain</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- TABLE CARD --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="px-6 py-4 border-b border-border bg-surface">
                <h3 class="text-sm font-semibold text-ink font-sans">Daftar Arsip Dokumen & SK</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Menampilkan seluruh data berkas fisik pendukung
                    kepegawaian LLDIKTI Wilayah XVI.</p>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Pegawai</th>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Nama Dokumen</th>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Kategori</th>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Nomor Dokumen</th>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Tanggal</th>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Status</th>
                            <th
                                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border select-none">
                                Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="doc in paginatedDocuments" :key="doc.id">
                            <tr class="transition-colors hover:bg-soft/50">
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="flex h-7.5 w-7.5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                            <span x-text="doc.nama_pegawai.substring(0,1)"></span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-ink font-sans leading-tight"
                                                x-text="doc.nama_pegawai"></p>
                                            <p class="font-mono text-[10px] text-muted leading-none mt-0.5"
                                                x-text="doc.nip_pegawai"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 max-w-xs">
                                    <div class="flex items-start gap-2.5">
                                        {{-- Document File Icon --}}
                                        <div
                                            class="flex h-9 w-7 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-0.5 shadow-sm relative">
                                            <div class="w-full space-y-0.5 mt-0.5">
                                                <div class="h-0.5 w-3 bg-muted/40 rounded-full mx-auto"></div>
                                                <div class="h-0.5 w-2 bg-muted/40 rounded-full mx-auto"></div>
                                            </div>
                                            <div class="w-full bg-primary/10 text-primary text-[5px] font-bold text-center py-0.5 uppercase tracking-wide"
                                                x-text="doc.file_path.split('.').pop()">
                                                PDF
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-ink font-sans truncate"
                                                x-text="doc.nama"></p>
                                            <p class="text-xs text-muted font-sans mt-0.5 truncate"
                                                x-text="doc.deskripsi"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-xs text-muted font-sans" x-text="doc.kategori_label"></td>
                                <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.nomor"></td>
                                <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.tanggal"></td>
                                <td class="px-4 py-3.5">
                                    <span
                                        class="inline-flex items-center gap-1.5 text-xs font-semibold"
                                        :class="{
                                            'text-success': doc.status_dokumen === 'terverifikasi',
                                            'text-primary': doc.status_dokumen === 'aktif',
                                            'text-warning': doc.status_dokumen === 'perlu_review',
                                            'text-danger': doc.status_dokumen === 'kadaluarsa'
                                        }">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="{
                                                'bg-success': doc.status_dokumen === 'terverifikasi',
                                                'bg-primary': doc.status_dokumen === 'aktif',
                                                'bg-warning': doc.status_dokumen === 'perlu_review',
                                                'bg-danger': doc.status_dokumen === 'kadaluarsa'
                                            }"></span>
                                        <span x-text="doc.status_label"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-left">
                                    <div class="flex items-center justify-start gap-1.5">
                                        {{-- Detail --}}
                                        <a :href="'/dashboard/dokumen/' + doc.id"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                            title="Detail">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </a>
                                        {{-- Unduh --}}
                                        <a :href="'/dashboard/dokumen/' + doc.id + '/download'"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                            title="Unduh">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
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
            <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-muted">Tampilkan</span>
                        <select id="per-page" x-model.number="perPage" @change="currentPage = 1"
                            class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span class="text-sm text-muted">data per halaman</span>
                    </div>
                    
                    <p class="text-sm text-muted hidden sm:block" x-show="filteredDocuments.length > 0">
                        Menampilkan <span class="font-semibold text-ink" x-text="(currentPage - 1) * perPage + 1"></span> hingga <span class="font-semibold text-ink" x-text="Math.min(currentPage * perPage, filteredDocuments.length)"></span> dari <span class="font-semibold text-ink" x-text="filteredDocuments.length"></span> hasil
                    </p>
                </div>
                
                <div class="w-full sm:w-auto">
                    <div class="flex items-center justify-center gap-1.5" x-show="totalPages > 1">
                        {{-- Prev --}}
                        <button @click="if (currentPage > 1) currentPage--" :disabled="currentPage === 1"
                            :class="currentPage === 1 ? 'opacity-50 cursor-not-allowed text-muted' : 'hover:bg-soft hover:text-ink text-ink cursor-pointer'"
                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                            </svg>
                        </button>
    
                        <template x-for="page in totalPages" :key="page">
                            <button @click="currentPage = page"
                                :class="currentPage === page ? 'bg-primary text-white border-primary' : 'bg-surface text-ink hover:bg-soft border-border'"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border text-sm font-semibold transition font-sans cursor-pointer"
                                x-text="page"></button>
                        </template>
    
                        {{-- Next --}}
                        <button @click="if (currentPage < totalPages) currentPage++" :disabled="currentPage === totalPages"
                            :class="currentPage === totalPages ? 'opacity-50 cursor-not-allowed text-muted' : 'hover:bg-soft hover:text-ink text-ink cursor-pointer'"
                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- MODAL UNGGAH DOKUMEN BARU (POPUP) --}}
        {{-- ================================================================ --}}
        <div x-show="showUploadModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
            style="display: none;" x-transition>
            <div @click.outside="showUploadModal = false"
                class="w-full max-w-lg rounded-lg border border-border bg-surface p-6 shadow-xl space-y-6">

                {{-- Modal Header --}}
                <div class="flex justify-between items-center border-b border-border pb-3">
                    <h3 class="text-base font-semibold text-ink font-sans">Unggah Dokumen Kepegawaian</h3>
                    <button @click="showUploadModal = false"
                        class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none">Tutup</button>
                </div>

                <form action="{{ route('dokumen.store') }}" method="POST" enctype="multipart/form-data"
                    class="space-y-4">
                    @csrf

                    {{-- Relasi Pegawai --}}
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Hubungkan ke Pegawai <span
                                class="text-danger">*</span></label>
                        <select name="pegawai_id" required
                            class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="">Pilih Pegawai...</option>
                            @foreach($pegawaiList as $p)
                                <option value="{{ $p->id }}">{{ $p->nama_lengkap }} (NIP. {{ $p->nip }})</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Kategori Dokumen --}}
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Kategori Dokumen <span
                                class="text-danger">*</span></label>
                        <select name="kategori_dokumen" required
                            class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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
                        <label class="text-xs font-semibold text-ink font-sans">Nama Dokumen <span
                                class="text-danger">*</span></label>
                        <input type="text" name="nama_dokumen" required
                            placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Nomor Dokumen <span
                                    class="text-danger">*</span></label>
                            <input type="text" name="nomor_dokumen" required placeholder="SK-..."
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal Dokumen <span
                                    class="text-danger">*</span></label>
                            <input type="date" name="tanggal_terbit" required
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Keterangan / Deskripsi</label>
                        <textarea name="deskripsi" rows="3"
                            placeholder="Tulis rincian atau catatan singkat mengenai dokumen..."
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none font-sans"></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Pilih File Berkas <span
                                class="text-danger">*</span></label>
                        <input type="file" name="berkas" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <p class="text-[10px] text-muted mt-1 font-sans">Format yang diizinkan: **PDF, DOC, DOCX, JPG,
                            JPEG, PNG**. Ukuran maksimal 10MB.</p>
                    </div>

                    {{-- Buttons --}}
                    <div class="flex justify-end gap-3 pt-4 border-t border-border">
                        <button type="button" @click="showUploadModal = false"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none">
                            Batal
                        </button>
                        <button type="submit"
                            class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                            Mulai Unggah
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</x-layouts.app>