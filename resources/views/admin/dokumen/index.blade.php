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
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="px-6 py-4 border-b border-border bg-surface">
                <h3 class="text-sm font-semibold text-ink font-sans">Daftar Arsip Dokumen & SK</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Menampilkan seluruh data berkas fisik pendukung
                    kepegawaian LLDIKTI Wilayah XVI.</p>
            </div>

            {{-- Table Render --}}
            <div class="overflow-x-auto">

                <x-ui.table>
                    <x-ui.table-head class="border-b border-border">
                        <x-ui.table-row>
                            <x-ui.table-th class="select-none">
                                Pegawai</x-ui.table-th>
                            <x-ui.table-th class="select-none">
                                Nama Dokumen</x-ui.table-th>
                            <x-ui.table-th class="select-none">
                                Kategori</x-ui.table-th>
                            <x-ui.table-th class="select-none">
                                Nomor Dokumen</x-ui.table-th>
                            <x-ui.table-th class="select-none">
                                Tanggal</x-ui.table-th>
                            <x-ui.table-th class="select-none">
                                Status</x-ui.table-th>
                            <x-ui.table-th align="right" class="select-none">
                                Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>

                        <template x-for="doc in paginatedDocuments" :key="doc.id">
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td>
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
                                </x-ui.table-td>
                                <x-ui.table-td class="max-w-xs">
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

                                </x-ui.table-td>
                                <x-ui.table-td x-text="doc.kategori_label" class="text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="doc.nomor" class="font-mono text-muted"></x-ui.table-td>
                                <x-ui.table-td x-text="doc.tanggal" class="font-mono text-muted"></x-ui.table-td>
                                <x-ui.table-td>
                                    <x-ui.badge
                                        variant="none"
                                        size="md"
                                        dot
                                        x-bind:class="{
                                            'border-success/20 bg-success/10 text-success': doc.status_dokumen === 'terverifikasi',
                                            'border-primary/20 bg-primary/10 text-primary': doc.status_dokumen === 'aktif',
                                            'border-warning/25 bg-warning/10 text-warning': doc.status_dokumen === 'perlu_review',
                                            'border-danger/20 bg-danger/10 text-danger': doc.status_dokumen === 'kadaluarsa'
                                        }"
                                    >
                                        <span x-text="doc.status_label"></span>
                                    </x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td align="right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        {{-- Detail --}}
                                        <x-ui.button as="a" x-bind:href="'/dashboard/dokumen/' + doc.id" variant="secondary" size="icon" title="Detail" aria-label="Detail">

                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </x-ui.button>
                                        {{-- Unduh --}}

                                        <x-ui.button as="a" x-bind:href="'/dashboard/dokumen/' + doc.id + '/download'" variant="secondary" size="icon" title="Unduh" aria-label="Unduh">

                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                            </svg>
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </template>
                        <x-ui.table-row x-show="filteredDocuments.length === 0">
                            <x-ui.table-td colspan="7" align="center" class="px-6 py-8 text-muted">
                                Tidak ada dokumen yang cocok dengan filter atau pencarian Anda.
                            </x-ui.table-td>
                        </x-ui.table-row>
                    </x-ui.table-body>
                </x-ui.table>
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
        </x-ui.card>

        {{-- ================================================================ --}}
        {{-- MODAL UNGGAH DOKUMEN BARU (POPUP) --}}
        {{-- ================================================================ --}}

        <x-ui.modal
            show="showUploadModal"
            title="Unggah Dokumen Kepegawaian"
            close-action="showUploadModal = false"
            max-width="lg"
            body-class="p-6"
            overlay-class="bg-ink/40"
        >

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

                    <x-form.select
                        name="pegawai_id"
                        label="Hubungkan ke Pegawai"
                        required
                    >
                        <option value="">Pilih Pegawai...</option>
                        @foreach($pegawaiList as $p)
                            <option value="{{ $p->id }}">{{ $p->nama_lengkap }} (NIP. {{ $p->nip }})</option>
                        @endforeach
                    </x-form.select>

                    {{-- Kategori Dokumen --}}
                    <x-form.select
                        name="kategori_dokumen"
                        label="Kategori Dokumen"
                        required
                    >
                        <option value="sk_pengangkatan">SK Pengangkatan</option>
                        <option value="sk_pangkat">SK Kenaikan Pangkat</option>
                        <option value="sk_jabatan">SK Kenaikan Jabatan</option>
                        <option value="sk_kgb">SK KGB (Kenaikan Gaji Berkala)</option>
                        <option value="ijazah">Ijazah / Pendidikan</option>
                        <option value="ktp_kk">Identitas Diri (KTP & KK)</option>
                        <option value="lainnya">Lampiran / Dokumen Lain</option>
                    </x-form.select>

                    <x-form.input
    name="nama_dokumen"
    label="Nama Dokumen"
    type="text"
    placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026"
    required
    size="sm"
/>

                    <div class="grid grid-cols-2 gap-3">
                        <x-form.input
    name="nomor_dokumen"
    label="Nomor Dokumen"
    type="text"
    placeholder="SK-..."
    required
    size="sm"
/>
                        <x-form.date
    name="tanggal_terbit"
    label="Tanggal Dokumen"
    required
    size="sm"
/>
                    </div>

                    <x-form.textarea
                        name="deskripsi"
                        label="Keterangan / Deskripsi"
                        rows="3"
                        size="sm"
                        label-class="font-semibold normal-case tracking-normal"
                        placeholder="Tulis rincian atau catatan singkat mengenai dokumen..."
                    />


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
        </x-ui.modal>

    </div>

</x-layouts.app>
