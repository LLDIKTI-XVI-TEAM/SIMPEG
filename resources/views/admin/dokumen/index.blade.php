<x-layouts.app title="Arsip Dokumen Kepegawaian">

    <div x-data="{
        filters: {
            search: '',
            kategori: '',
            unit_kerja: '',
            status: '',
        },
        showUploadModal: {{ $errors->any() ? 'true' : 'false' }},
        documentsRows: [],
        meta: { current_page: 1, last_page: 1, total: 0, from: 0, to: 0 },
        isLoading: false,
        perPage: 10,
        searchTimer: null,
        fetchError: null,
        dataChanged: @js(session('document_data_changed', false)),

        get cacheKey() {
            const f = this.filters;
            return `dokumen_pp${this.perPage}_s${f.search}_k${f.kategori}_u${f.unit_kerja}_st${f.status}`;
        },

        clearCache() {
            const toDelete = [];
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                if (key && key.startsWith('dokumen_')) toDelete.push(key);
            }
            toDelete.forEach(k => sessionStorage.removeItem(k));
        },

        async fetchPage(page) {
            const cKey = this.cacheKey + `_p${page}`;
            const cached = sessionStorage.getItem(cKey);

            if (cached) {
                try {
                    const data = JSON.parse(cached);
                    this.documentsRows = data.rows;
                    this.meta = data.meta;
                    return;
                } catch (e) {
                    sessionStorage.removeItem(cKey);
                }
            }

            this.isLoading = true;
            this.fetchError = null;
            try {
                const params = new URLSearchParams({
                    page,
                    per_page: this.perPage,
                    ...Object.fromEntries(Object.entries(this.filters).filter(([, v]) => v !== '')),
                });
                const res = await fetch(`/api/v1/dokumen?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!res.ok) {
                    const errText = await res.text();
                    throw new Error(`HTTP ${res.status}: ${errText.substring(0, 200)}`);
                }
                const json = await res.json();

                const rows = json.documents?.data ?? [];
                const meta = {
                    total:        json.documents?.total        ?? 0,
                    current_page: json.documents?.current_page ?? 1,
                    last_page:    json.documents?.last_page    ?? 1,
                    from:         json.documents?.from         ?? 0,
                    to:           json.documents?.to           ?? 0,
                    per_page:     json.documents?.per_page     ?? this.perPage,
                };

                sessionStorage.setItem(cKey, JSON.stringify({ rows, meta }));
                this.documentsRows = rows;
                this.meta = meta;
            } catch (e) {
                console.error('[Dokumen] Gagal fetch data dokumen:', e);
                this.fetchError = e.message;
            } finally {
                this.isLoading = false;
            }
        },

        applyFilter() {
            this.clearCache();
            this.fetchPage(1);
        },

        init() {
            if (this.dataChanged) {
                this.clearCache();
                this.fetchPage(1);
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const filterParam = urlParams.get('filter');
            if (filterParam === 'kadaluarsa') {
                this.filters.kategori = 'sk_pengangkatan';
            }

            const cKey = this.cacheKey + `_p${this.meta.current_page}`;
            const cached = sessionStorage.getItem(cKey);
            if (cached) {
                try {
                    const data = JSON.parse(cached);
                    this.documentsRows = data.rows;
                    this.meta = data.meta;
                    this.$nextTick(() => this._watchFilters());
                    return;
                } catch (e) {
                    sessionStorage.removeItem(cKey);
                }
            }

            this._watchFilters();
            this.fetchPage(1);
        },

        _watchFilters() {
            this.$watch('filters.search', () => {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.applyFilter(), 300);
            });
            this.$watch('filters.kategori', () => this.applyFilter());
            this.$watch('filters.unit_kerja', () => this.applyFilter());
            this.$watch('filters.status', () => this.applyFilter());
            this.$watch('perPage', () => this.applyFilter());
        },
    }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Arsip Dokumen Kepegawaian</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Arsip Dokumen'],
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <button @click="showUploadModal = true"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    Unggah Dokumen Baru
                </button>
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- DATA TABLE (x-ui.data-table) --}}
        {{-- ============================================================ --}}
        <x-ui.data-table
            rows="documentsRows"
            meta="meta"
            :columns="[
                ['key' => 'nama_pegawai',  'label' => 'Pegawai'],
                ['key' => 'nama',          'label' => 'Nama Dokumen'],
                ['key' => 'kategori_label','label' => 'Kategori'],
                ['key' => 'nomor',         'label' => 'Nomor Dokumen'],
                ['key' => 'tanggal',       'label' => 'Tanggal'],
                ['key' => 'status_dokumen','label' => 'Status'],
                ['key' => 'aksi',          'label' => 'Aksi', 'align' => 'left'],
            ]"
            fetchPage="fetchPage(page)"
            isLoading="isLoading"
            perPage="perPage"
            setPerPage="perPage = parseInt($event.target.value)"
            searchModel="filters.search"
            searchPlaceholder="Cari nama, nomor, jenis..."
            emptyTitle="Tidak ada dokumen ditemukan"
            emptyIcon="document"
            :colspanCount="7"
        >
            {{-- ---- Filter Slots ---- --}}
            <x-slot:filters>
                {{-- Filter Unit Kerja --}}
                <div class="relative col-span-1">
                    <select x-model="filters.unit_kerja"
                        class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Unit Kerja</option>
                        <option>Bag. Umum</option>
                        <option>Bag. Keuangan</option>
                        <option>Bag. SDM</option>
                        <option>Bag. IT</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                </div>

                {{-- Filter Kategori Dokumen --}}
                <div class="relative col-span-1">
                    <select x-model="filters.kategori"
                        class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Kategori Dokumen</option>
                        @foreach ($categoryLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                </div>

                {{-- Filter Status --}}
                <div class="relative col-span-1">
                    <select x-model="filters.status"
                        class="h-10 w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Status</option>
                        <option value="tersedia">File tersedia</option>
                        <option value="file_tidak_ditemukan">File tidak ditemukan</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                </div>
            </x-slot:filters>

            {{-- ---- Custom Body Rows ---- --}}
            <template x-if="!isLoading && documentsRows && documentsRows.length > 0">
                <template x-for="doc in documentsRows.filter(d => d && d.id)" :key="doc.id">
                    <tr class="transition-colors hover:bg-soft/50">

                        {{-- Pegawai --}}
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary">
                                    <template x-if="doc.foto_pegawai">
                                        <img :src="doc.foto_pegawai" :alt="'Foto ' + doc.nama_pegawai" class="h-full w-full object-cover object-[center_25%]">
                                    </template>
                                    <template x-if="!doc.foto_pegawai">
                                        <span aria-hidden="true">
                                            <svg class="h-5 w-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                            </svg>
                                        </span>
                                    </template>
                                </div>
                                <div class="min-w-0">
                                    <p class="block truncate text-sm font-semibold text-ink" x-text="doc.nama_pegawai"></p>
                                    <p class="font-mono text-xs text-muted" x-text="'NIP. ' + doc.nip_pegawai"></p>
                                </div>
                            </div>
                        </td>

                        {{-- Nama Dokumen --}}
                        <td class="px-4 py-3.5 max-w-xs">
                            <div class="flex items-start gap-2.5">
                                <div class="flex h-9 w-7 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-0.5 shadow-sm">
                                    <div class="w-full space-y-0.5 mt-0.5">
                                        <div class="h-0.5 w-3 bg-muted/40 rounded-full mx-auto"></div>
                                        <div class="h-0.5 w-2 bg-muted/40 rounded-full mx-auto"></div>
                                    </div>
                                    <div class="w-full bg-primary/10 text-primary text-[5px] font-bold text-center py-0.5 uppercase tracking-wide"
                                        x-text="(doc.file_path || '').split('.').pop()">
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink font-sans truncate" x-text="doc.nama"></p>
                                    <p class="text-xs text-muted font-sans mt-0.5 truncate" x-text="doc.deskripsi"></p>
                                </div>
                            </div>
                        </td>

                        {{-- Kategori --}}
                        <td class="px-4 py-3.5 text-xs text-muted font-sans" x-text="doc.kategori_label"></td>

                        {{-- Nomor Dokumen --}}
                        <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.nomor"></td>

                        {{-- Tanggal --}}
                        <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="doc.tanggal"></td>

                        {{-- Status --}}
                        <td class="px-4 py-3.5">
                            <span class="inline-flex items-center gap-1.5 font-medium font-sans leading-none px-2.5 py-1 text-xs rounded-md"
                                :class="{
                                    'bg-success/10 text-success': doc.status_dokumen === 'tersedia',
                                    'bg-danger/10 text-danger':   doc.status_dokumen === 'file_tidak_ditemukan',
                                }">
                                <span class="h-1.5 w-1.5 rounded-full"
                                    :class="{
                                        'bg-success': doc.status_dokumen === 'tersedia',
                                        'bg-danger':  doc.status_dokumen === 'file_tidak_ditemukan',
                                    }"></span>
                                <span x-text="doc.status_label"></span>
                            </span>
                        </td>

                        {{-- Aksi --}}
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-1.5">
                                <a :href="'/dashboard/dokumen/' + doc.id"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                    title="Detail" :aria-label="'Lihat detail ' + doc.nama">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                                <a :href="'/dashboard/dokumen/' + doc.id + '/download'"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                    title="Unduh" :aria-label="'Unduh ' + doc.nama">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                </a>
                            </div>
                        </td>

                    </tr>
                </template>
            </template>

        </x-ui.data-table>


        {{-- ============================================================ --}}
        {{-- MODAL UNGGAH DOKUMEN BARU --}}
        {{-- ============================================================ --}}
        <x-ui.modal
            show="showUploadModal"
            title="Unggah Dokumen Kepegawaian"
            closeAction="showUploadModal = false"
            maxWidth="lg"
            bodyClass="p-5 space-y-3"
        >
            <form action="{{ route('dokumen.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                @csrf

                @if ($errors->any())
                    <div class="rounded-lg bg-danger/10 p-3 text-xs text-danger font-bold font-sans">
                        Terdapat kesalahan pengisian form
                    </div>
                @endif

                {{-- Relasi Pegawai --}}
                <div class="space-y-1" x-data="{
                    open: false,
                    search: '',
                    selectedId: '{{ old('pegawai_id') }}',
                    selectedLabel: 'Pilih Pegawai...',
                    options: @js($pegawaiOptions),
                    get filteredOptions() {
                        if (this.search === '') return this.options;
                        return this.options.filter(opt => opt.label.toLowerCase().includes(this.search.toLowerCase()));
                    },
                    init() {
                        if (this.selectedId) {
                            const found = this.options.find(opt => opt.id === this.selectedId);
                            if (found) this.selectedLabel = found.label;
                        }
                    },
                    selectOption(opt) {
                        this.selectedId = opt.id;
                        this.selectedLabel = opt.label;
                        this.open = false;
                        this.search = '';
                    }
                }">
                    <label class="text-xs font-semibold text-ink font-sans">Hubungkan ke Pegawai <span class="text-danger">*</span></label>
                    <div class="relative">
                        <input type="hidden" name="pegawai_id" x-model="selectedId" required>
                        <button type="button" @click="open = !open" @click.outside="open = false"
                            class="w-full flex items-center justify-between rounded-lg border border-border bg-surface pl-4 pr-10 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans text-left">
                            <span x-text="selectedLabel" class="truncate" :class="!selectedId ? 'text-muted' : 'text-ink'"></span>
                        </button>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                            <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                        <div x-show="open" style="display: none;" x-transition
                            class="absolute z-10 w-full mt-1 bg-surface border border-border rounded-lg shadow-lg overflow-hidden">
                            <div class="p-2 border-b border-border bg-soft/50">
                                <input type="text" x-model="search" placeholder="Cari nama atau NIP..."
                                    class="w-full rounded-md border border-border bg-surface px-3 py-1.5 text-xs text-ink focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary font-sans"
                                    @click.stop>
                            </div>
                            <ul class="max-h-56 overflow-y-auto py-1">
                                <template x-for="opt in filteredOptions" :key="opt.id">
                                    <li @click="selectOption(opt)"
                                        class="px-3 py-2 text-xs cursor-pointer hover:bg-soft transition-colors text-ink font-sans flex items-center justify-between"
                                        :class="selectedId === opt.id ? 'bg-primary/5 font-semibold text-primary' : ''">
                                        <span x-text="opt.label"></span>
                                        <svg x-show="selectedId === opt.id" class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    </li>
                                </template>
                                <li x-show="filteredOptions.length === 0" class="px-4 py-3 text-xs text-muted text-center italic font-sans">
                                    Pegawai tidak ditemukan
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Kategori Dokumen --}}
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Kategori Dokumen <span class="text-danger">*</span></label>
                    <div class="relative">
                        <select name="kategori_dokumen" required
                            class="w-full appearance-none rounded-lg border border-border bg-surface pl-4 pr-10 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            @foreach ($categoryLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                            <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                    </div>
                </div>

                {{-- Nama Dokumen --}}
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Nama Dokumen <span class="text-danger">*</span></label>
                    <input type="text" name="nama_dokumen" required
                        value="{{ old('nama_dokumen') }}"
                        placeholder="Contoh: SK Kenaikan Pangkat Penata Tkt. I 2026"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                </div>

                {{-- Nomor & Tanggal --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Nomor Dokumen</label>
                        <input type="text" name="nomor_dokumen" value="{{ old('nomor_dokumen') }}"
                            placeholder="SK-..."
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Tanggal Dokumen</label>
                        <input type="date" name="tanggal_terbit" value="{{ old('tanggal_terbit') }}"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                </div>

                {{-- Deskripsi --}}
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Keterangan / Deskripsi</label>
                    <textarea name="deskripsi" rows="2"
                        placeholder="Tulis rincian atau catatan singkat mengenai dokumen..."
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none font-sans">{{ old('deskripsi') }}</textarea>
                </div>

                {{-- File Upload --}}
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Pilih File Berkas <span class="text-danger">*</span></label>
                    <input type="file" name="berkas" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    <p class="text-[10px] text-muted mt-1 font-sans">Format yang diizinkan: PDF, DOC, DOCX, JPG, JPEG, PNG. Ukuran maksimal 10MB.</p>
                </div>

                {{-- Tombol Aksi --}}
                <div class="flex justify-end gap-3 pt-3 border-t border-border">
                    <button type="button" @click="showUploadModal = false"
                        class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none font-sans">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
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
