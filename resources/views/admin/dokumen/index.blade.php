<x-layouts.app title="Arsip Dokumen Kepegawaian">

    <div x-data="{
        activeKategori: '',
        activeUnit: '',
        activeStatus: '',
        searchQuery: '',
        showUploadModal: {{ $errors->any() ? 'true' : 'false' }},
        documents: @js($documentsForTable),
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
            this.$watch('activeStatus', () => this.currentPage = 1);
        },
        get filteredDocuments() {
            return this.documents.filter(doc => {
                const matchesSearch = doc.nama.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                                       doc.nomor.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                       doc.jenis.toLowerCase().includes(this.searchQuery.toLowerCase());
                const matchesKategori = !this.activeKategori || doc.kategori === this.activeKategori;
                const matchesUnit = !this.activeUnit || doc.unit_pegawai === this.activeUnit;
                const matchesStatus = !this.activeStatus || doc.status_dokumen === this.activeStatus;
                return matchesSearch && matchesKategori && matchesUnit && matchesStatus;
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
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Arsip Dokumen']
                ]" />
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

        <x-ui.filter-bar 
            searchModel="searchQuery" 
            searchPlaceholder="Cari nama, nomor, jenis..."
        >
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
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Kategori Dokumen --}}
            <div class="relative col-span-1 sm:col-span-1 lg:col-span-1">
                <select x-model="activeKategori"
                    class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Kategori Dokumen</option>
                    @foreach ($categoryLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Status Dokumen --}}
            <div class="relative col-span-1 sm:col-span-2 lg:col-span-1">
                <select x-model="activeStatus"
                    class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Status</option>
                    <option value="tersedia">File tersedia</option>
                    <option value="file_tidak_ditemukan">File tidak ditemukan</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>
        </x-ui.filter-bar>

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
                                            'text-success': doc.status_dokumen === 'tersedia',
                                            'text-danger': doc.status_dokumen === 'file_tidak_ditemukan'
                                        }">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="{
                                                'bg-success': doc.status_dokumen === 'tersedia',
                                                'bg-danger': doc.status_dokumen === 'file_tidak_ditemukan'
                                            }"></span>
                                        <span x-text="doc.status_label"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-left">
                                    <div class="flex items-center justify-start gap-1.5">
                                        {{-- Detail --}}
                                        <a :href="'/dashboard/dokumen/' + doc.id"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                            title="Detail" :aria-label="'Lihat detail ' + doc.nama">
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
                                            title="Unduh" :aria-label="'Unduh ' + doc.nama">
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
                    <div class="flex items-center justify-center gap-1.5">
                        <x-ui.pagination current="currentPage" total="totalPages" />
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- MODAL UNGGAH DOKUMEN BARU (POPUP) --}}
        {{-- ================================================================ --}}
        <div x-show="showUploadModal" class="fixed inset-0 z-50 overflow-y-auto bg-ink/40"
            style="display: none;" x-transition>
            <div class="flex min-h-full items-center justify-center p-4">
                <div @click.outside="showUploadModal = false"
                    class="relative w-full max-w-lg rounded-lg border border-border bg-surface p-5 shadow-xl space-y-4"
                    role="dialog" aria-modal="true" aria-labelledby="upload-dokumen-title">

                {{-- Modal Header --}}
                <div class="flex justify-between items-center border-b border-border pb-3">
                    <h3 id="upload-dokumen-title" class="text-base font-semibold text-ink font-sans">Unggah Dokumen Kepegawaian</h3>
                    <button @click="showUploadModal = false"
                        class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none">Tutup</button>
                </div>

                <form action="{{ route('dokumen.store') }}" method="POST" enctype="multipart/form-data"
                    class="space-y-3">
                    @csrf
                    
                    @if ($errors->any())
                        <div class="rounded-lg bg-danger/10 p-3 text-xs text-danger font-sans">
                            <ul class="list-disc pl-4 space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Relasi Pegawai --}}
                    <div class="space-y-1">
                        <label for="pegawai_id" class="text-xs font-semibold text-ink font-sans">Hubungkan ke Pegawai <span
                                class="text-danger">*</span></label>
                        <div class="relative">
                            <select id="pegawai_id" name="pegawai_id" required
                                class="w-full appearance-none bg-none rounded-lg border border-border bg-surface pl-4 pr-10 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="">Pilih Pegawai...</option>
                                @foreach ($pegawaiOptions as $pegawai)
                                    <option value="{{ $pegawai['id'] }}" @selected(old('pegawai_id') === $pegawai['id'])>{{ $pegawai['label'] }}</option>
                                @endforeach
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                                <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                        </div>
                    </div>

                    {{-- Kategori Dokumen --}}
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Kategori Dokumen <span
                                class="text-danger">*</span></label>
                        <div class="relative">
                            <select name="kategori_dokumen" required
                                class="w-full appearance-none bg-none rounded-lg border border-border bg-surface pl-4 pr-10 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                @foreach ($categoryLabels as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                                <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Nama Dokumen <span
                                class="text-danger">*</span></label>
                        <input type="text" name="nama_dokumen" required
                            placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Nomor Dokumen</label>
                            <input type="text" name="nomor_dokumen" placeholder="SK-..."
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal Dokumen</label>
                            <input type="date" name="tanggal_terbit"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Keterangan / Deskripsi</label>
                        <textarea name="deskripsi" rows="2"
                            placeholder="Tulis rincian atau catatan singkat mengenai dokumen..."
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none font-sans"></textarea>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Pilih File Berkas <span
                                class="text-danger">*</span></label>
                        <input type="file" name="berkas" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <p class="text-[10px] text-muted mt-1 font-sans">Format yang diizinkan: PDF, DOC, DOCX, JPG, JPEG, PNG. Ukuran maksimal 10MB.</p>
                    </div>

                    {{-- Buttons --}}
                    <div class="flex justify-end gap-3 pt-3 border-t border-border">
                        <button type="button" @click="showUploadModal = false"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none">
                            Batal
                        </button>
                        <button type="submit"
                            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                            Mulai Unggah
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</x-layouts.app>
