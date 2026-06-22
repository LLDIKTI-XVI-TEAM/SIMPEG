<x-layouts.app title="Dokumen">

    @php
    $dokumen = [
        [
            'id' => 1,
            'jenis' => 'SK Pangkat',
            'nama' => 'SK Kenaikan Pangkat Penata Tkt. I',
            'nomor' => 'SK-882-KP-2024',
            'tanggal' => '2024-04-01',
            'kategori' => 'sk_kepegawaian',
            'kategori_label' => 'SK Kepegawaian',
            'file_path' => 'employees/docs/198503122010011001_sk_pangkat_2024.pdf',
            'file_size' => '1.2 MB',
            'deskripsi' => 'SK Kenaikan Pangkat Penata Tingkat I Golongan Ruang III/d atas nama Ahmad Fauzi.'
        ],
        [
            'id' => 2,
            'jenis' => 'SK Jabatan',
            'nama' => 'SK Pengangkatan Jabatan Analis Kepegawaian',
            'nomor' => 'SK-104-JAB-2022',
            'tanggal' => '2022-08-15',
            'kategori' => 'sk_kepegawaian',
            'kategori_label' => 'SK Kepegawaian',
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
            'kategori' => 'sk_kepegawaian',
            'kategori_label' => 'SK Kepegawaian',
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
            'kategori' => 'pendidikan',
            'kategori_label' => 'Pendidikan',
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
            'kategori' => 'identitas',
            'kategori_label' => 'Identitas & KK',
            'file_path' => 'employees/docs/198503122010011001_ktp.pdf',
            'file_size' => '620 KB',
            'deskripsi' => 'KTP sesuai data kependudukan yang berlaku.'
        ],
        [
            'id' => 6,
            'jenis' => 'KK',
            'nama' => 'Kartu Keluarga (KK)',
            'nomor' => '3171-7403-8803-0001',
            'tanggal' => '2021-05-10',
            'kategori' => 'identitas',
            'kategori_label' => 'Identitas & KK',
            'file_path' => 'employees/docs/198503122010011001_kk.pdf',
            'file_size' => '580 KB',
            'deskripsi' => 'Kartu Keluarga terbaru.'
        ],
    ];
    @endphp

    <div x-data="{
        activeFolder: 'all',
        searchQuery: '',
        showUploadModal: false,
        documents: {{ json_encode($dokumen) }},
        init() {
            const urlParams = new URLSearchParams(window.location.search);
            const filterParam = urlParams.get('filter');
            if (filterParam === 'kadaluarsa') {
                this.activeFolder = 'sk_kepegawaian';
                this.documents = this.documents.filter(doc => doc.id === 1 || doc.id === 2 || doc.id === 3);
            }
        },
        get filteredDocuments() {
            return this.documents.filter(doc => {
                const matchesSearch = doc.nama.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                                       doc.nomor.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                       doc.jenis.toLowerCase().includes(this.searchQuery.toLowerCase());
                const matchesFolder = this.activeFolder === 'all' || doc.kategori === this.activeFolder;
                return matchesSearch && matchesFolder;
            });
        },
        get folderCounts() {
            return {
                all: this.documents.length,
                sk_kepegawaian: this.documents.filter(d => d.kategori === 'sk_kepegawaian').length,
                pendidikan: this.documents.filter(d => d.kategori === 'pendidikan').length,
                identitas: this.documents.filter(d => d.kategori === 'identitas').length,
            };
        }
    }" class="space-y-6">
        {{-- Table Card --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            
            {{-- Toolbar Table --}}
            <div class="px-6 py-4 border-b border-border flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between bg-surface">
                <div>
                    <h3 class="text-base font-bold text-ink font-sans leading-tight">Daftar Berkas Anda</h3>
                    <p class="text-xs text-muted mt-0.5 font-sans leading-normal">Menampilkan dokumen kepegawaian pribadi yang telah terunggah.</p>
                </div>
                
                {{-- Search & Upload button --}}
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    {{-- Search Input with Glass Icon --}}
                    <div class="relative flex items-center w-full sm:w-72 rounded-lg border border-border bg-surface px-3 py-2.5 shadow-sm focus-within:ring-2 focus-within:ring-primary/20 focus-within:border-primary">
                        <svg class="w-4 h-4 text-muted shrink-0 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input
                            type="text"
                            x-model="searchQuery"
                            placeholder="Cari nama dokumen/nomor..."
                            class="w-full bg-transparent text-xs text-ink placeholder:text-muted focus:outline-none font-sans"
                        >
                    </div>

                    {{-- Upload Button --}}
                    <button
                        @click="showUploadModal = true"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans"
                    >
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        Unggah Dokumen
                    </button>
                </div>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nama Dokumen</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Kategori</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nomor Dokumen</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Tanggal Terbit</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Ukuran</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="doc in filteredDocuments" :key="doc.id">
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-6 py-4 max-w-xs sm:max-w-md">
                                    <div class="flex items-start gap-3">
                                        {{-- PDF File Icon --}}
                                        <div class="flex h-10 w-8 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-1 shadow-sm relative">
                                            <div class="w-full space-y-0.5 mt-0.5">
                                                <div class="h-0.5 w-4 bg-muted/40 rounded-full mx-auto"></div>
                                                <div class="h-0.5 w-3 bg-muted/40 rounded-full mx-auto"></div>
                                            </div>
                                            <div class="w-full bg-danger rounded-sm py-0.5 text-[6px] font-bold text-white text-center uppercase tracking-wide">
                                                PDF
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-ink font-sans truncate" x-text="doc.nama"></p>
                                            <p class="text-[11px] text-muted font-sans mt-0.5" x-text="doc.deskripsi"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs text-muted font-sans" x-text="doc.kategori_label"></td>
                                <td class="px-6 py-4 text-xs font-mono text-muted" x-text="doc.nomor"></td>
                                <td class="px-6 py-4 text-xs font-mono text-muted" x-text="doc.tanggal"></td>
                                <td class="px-6 py-4 text-xs font-mono text-muted" x-text="doc.file_size"></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <a :href="'/dashboard/dokumen/' + doc.id" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                            Detail
                                        </a>
                                        <span class="text-border">|</span>
                                        <a :href="'/dashboard/dokumen/' + doc.id + '/download'" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                            Unduh
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filteredDocuments.length === 0">
                            <td colspan="6" class="px-6 py-8 text-center text-xs text-muted font-sans">
                                Tidak ada dokumen yang cocok dengan filter atau pencarian Anda.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">Menampilkan 1 - 6 dari 6 data</p>
                    <div class="relative">
                        <select id="per-page" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option>10 / halaman</option>
                            <option>25 / halaman</option>
                            <option>50 / halaman</option>
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
        {{-- MODAL UNGGAH DOKUMEN BARU (COLLAPSIBLE/POPUP) --}}
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
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Kategori Dokumen</label>
                        <select name="kategori_dokumen" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="sk_kepegawaian">SK Kepegawaian (SK Pangkat, SK Jabatan, SK KGB)</option>
                            <option value="pendidikan">Pendidikan (Ijazah, Sertifikat)</option>
                            <option value="identitas">Identitas Diri (KTP, KK, Paspor)</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Nama Dokumen</label>
                        <input type="text" name="nama_dokumen" required placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Nomor Dokumen</label>
                            <input type="text" name="nomor_dokumen" required placeholder="SK-..." class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal Terbit</label>
                            <input type="date" name="tanggal_terbit" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Keterangan / Deskripsi</label>
                        <textarea name="deskripsi" rows="3" placeholder="Tulis rincian atau catatan singkat mengenai dokumen..." class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none font-sans"></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Pilih File Berkas</label>
                        <input type="file" name="berkas" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <p class="text-[10px] text-muted mt-1 font-sans">Format yang diizinkan: PDF. Ukuran maksimal 10MB.</p>
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
