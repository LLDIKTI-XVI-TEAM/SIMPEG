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
            'nomor' => '7171021203850001',
            'tanggal' => '2019-11-12',
            'kategori' => 'identitas',
            'kategori_label' => 'Identitas & Keluarga',
            'file_path' => 'employees/docs/198503122010011001_ktp.pdf',
            'file_size' => '320 KB',
            'deskripsi' => 'Salinan Kartu Tanda Penduduk Elektronik (e-KTP) untuk verifikasi kependudukan.'
        ],
        [
            'id' => 6,
            'jenis' => 'KK',
            'nama' => 'Kartu Keluarga (KK)',
            'nomor' => '7171020211150041',
            'tanggal' => '2023-01-10',
            'kategori' => 'identitas',
            'kategori_label' => 'Identitas & Keluarga',
            'file_path' => 'employees/docs/198503122010011001_kk.pdf',
            'file_size' => '1.5 MB',
            'deskripsi' => 'Salinan Kartu Keluarga pembaruan tahun berjalan 2023.'
        ],
    ];
    @endphp

    <div x-data="{
        activeFolder: 'all',
        searchQuery: '',
        showUploadModal: false,
        documents: {{ json_encode($dokumen) }},
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

        {{-- Folder Grid (Top Row) --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <button
                @click="activeFolder = 'all'"
                :class="activeFolder === 'all' ? 'border-primary bg-primary/[0.02] ring-2 ring-primary/5' : 'border-border hover:bg-soft/30'"
                class="h-[96px] rounded-xl border bg-surface p-4 text-left shadow-[0_1px_2px_rgba(0,0,0,0.05)] transition-all focus:outline-none cursor-pointer flex flex-col justify-between"
            >
                <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Semua Berkas</p>
                <div class="flex items-baseline gap-1.5 leading-none">
                    <span class="text-3xl font-extrabold text-ink font-sans leading-none" x-text="folderCounts.all"></span>
                    <span class="text-[11px] text-muted font-sans font-medium">Berkas</span>
                </div>
            </button>
            <button
                @click="activeFolder = 'sk_kepegawaian'"
                :class="activeFolder === 'sk_kepegawaian' ? 'border-primary bg-primary/[0.02] ring-2 ring-primary/5' : 'border-border hover:bg-soft/30'"
                class="h-[96px] rounded-xl border bg-surface p-4 text-left shadow-[0_1px_2px_rgba(0,0,0,0.05)] transition-all focus:outline-none cursor-pointer flex flex-col justify-between"
            >
                <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">SK Kepegawaian</p>
                <div class="flex items-baseline gap-1.5 leading-none">
                    <span class="text-3xl font-extrabold text-ink font-sans leading-none" x-text="folderCounts.sk_kepegawaian"></span>
                    <span class="text-[11px] text-muted font-sans font-medium">Berkas</span>
                </div>
            </button>
            <button
                @click="activeFolder = 'pendidikan'"
                :class="activeFolder === 'pendidikan' ? 'border-primary bg-primary/[0.02] ring-2 ring-primary/5' : 'border-border hover:bg-soft/30'"
                class="h-[96px] rounded-xl border bg-surface p-4 text-left shadow-[0_1px_2px_rgba(0,0,0,0.05)] transition-all focus:outline-none cursor-pointer flex flex-col justify-between"
            >
                <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Pendidikan</p>
                <div class="flex items-baseline gap-1.5 leading-none">
                    <span class="text-3xl font-extrabold text-ink font-sans leading-none" x-text="folderCounts.pendidikan"></span>
                    <span class="text-[11px] text-muted font-sans font-medium">Berkas</span>
                </div>
            </button>
            <button
                @click="activeFolder = 'identitas'"
                :class="activeFolder === 'identitas' ? 'border-primary bg-primary/[0.02] ring-2 ring-primary/5' : 'border-border hover:bg-soft/30'"
                class="h-[96px] rounded-xl border bg-surface p-4 text-left shadow-[0_1px_2px_rgba(0,0,0,0.05)] transition-all focus:outline-none cursor-pointer flex flex-col justify-between"
            >
                <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Identitas & KK</p>
                <div class="flex items-baseline gap-1.5 leading-none">
                    <span class="text-3xl font-extrabold text-ink font-sans leading-none" x-text="folderCounts.identitas"></span>
                    <span class="text-[11px] text-muted font-sans font-medium">Berkas</span>
                </div>
            </button>
        </div>

        {{-- Table Card --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            
            {{-- Toolbar Table --}}
            <div class="px-6 py-4 border-b border-border flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div>
                    <h2 class="text-base font-bold text-ink font-sans leading-tight">Daftar Berkas Anda</h2>
                    <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Menampilkan dokumen kepegawaian pribadi yang telah terunggah.</p>
                </div>
                
                {{-- Search & Upload button --}}
                <div class="flex items-center gap-4">
                    <input
                        type="text"
                        x-model="searchQuery"
                        placeholder="Cari nama dokumen/nomor..."
                        class="h-[44px] w-72 rounded-lg border border-border bg-soft px-4 text-xs text-ink placeholder:text-muted shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"
                    >
                    <button @click="showUploadModal = true" class="text-sm font-semibold text-primary hover:underline cursor-pointer focus:outline-none font-sans">
                        Unggah Dokumen
                    </button>
                </div>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft">
                        <tr>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Nama Dokumen</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Kategori</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Nomor Dokumen</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Tanggal Terbit</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Ukuran</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="doc in filteredDocuments" :key="doc.id">
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-6 py-2.5 max-w-xs sm:max-w-md">
                                    <p class="text-sm font-semibold text-ink font-sans truncate" x-text="doc.nama"></p>
                                    <p class="text-[11px] text-muted font-sans mt-0.5 truncate" x-text="doc.deskripsi"></p>
                                </td>
                                <td class="px-6 py-2.5 text-xs text-muted font-sans" x-text="doc.kategori_label"></td>
                                <td class="px-6 py-2.5 text-xs font-mono text-muted" x-text="doc.nomor"></td>
                                <td class="px-6 py-2.5 text-xs font-mono text-muted" x-text="doc.tanggal"></td>
                                <td class="px-6 py-2.5 text-xs font-mono text-muted" x-text="doc.file_size"></td>
                                <td class="px-6 py-2.5">
                                    <a href="#" class="text-xs font-semibold text-primary hover:underline font-sans">Unduh</a>
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
                
                {{-- Form --}}
                <form class="space-y-4" @submit.prevent>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Kategori Dokumen</label>
                        <select class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="sk_kepegawaian">SK Kepegawaian (SK Pangkat, SK Jabatan, SK KGB)</option>
                            <option value="pendidikan">Pendidikan (Ijazah, Sertifikat)</option>
                            <option value="identitas">Identitas Diri (KTP, KK, Paspor)</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Nama Dokumen</label>
                        <input type="text" placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Nomor Dokumen</label>
                            <input type="text" placeholder="SK-..." class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal Terbit</label>
                            <input type="date" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Keterangan / Deskripsi</label>
                        <textarea rows="3" placeholder="Tulis rincian atau catatan singkat mengenai dokumen..." class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none font-sans"></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Pilih File Berkas</label>
                        <input type="file" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <p class="text-[10px] text-muted mt-1 font-sans">Format yang diizinkan: PDF, DOC, DOCX, JPG, PNG. Ukuran maksimal 10MB.</p>
                    </div>

                    {{-- Buttons --}}
                    <div class="flex justify-end gap-3 pt-4 border-t border-border">
                        <button type="button" @click="showUploadModal = false" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none">
                            Batal
                        </button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 cursor-pointer focus:outline-none">
                            Mulai Unggah
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</x-layouts.app>
