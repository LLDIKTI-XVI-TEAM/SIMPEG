<x-layouts.app title="Arsip Dokumen Kepegawaian">

    @php
        $isKepalaBagianArchive = ($isKepalaBagian ?? false) === true;
        $isPegawaiArchive = ($isPegawai ?? false) === true || auth()->user()?->getEffectiveRole() === 'pegawai';
        $archiveBawahans = $bawahans ?? collect();
    @endphp
    <div x-data="{
        filters: {
            search: '',
            kategori: '',
            employee_id: '',
        },
        documentsRows: [],
        meta: { current_page: 1, last_page: 1, total: 0, from: 0, to: 0 },
        isLoading: false,
        perPage: 10,
        searchTimer: null,
        fetchError: null,
        dataChanged: @js(session('document_data_changed', false)),
        _cacheTTL: 300000,
        _cacheVersion: 'v1',
        _mutationKey: 'simpeg_dokumen_last_mutation',

        get cacheKey() {
            const f = this.filters;
            return `dokumen_pp${this.perPage}_s${f.search}_k${f.kategori}_e${f.employee_id}`;
        },

        clearCache() {
            const toDelete = [];
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                if (key && key.startsWith('dokumen_')) toDelete.push(key);
            }
            toDelete.forEach(k => sessionStorage.removeItem(k));
        },

        getCachedPage(page) {
            const cKey = this.cacheKey + `_p${page}`;
            const cached = sessionStorage.getItem(cKey);
            if (!cached) return null;

            try {
                const data = JSON.parse(cached);
                if (!data || typeof data !== 'object' || !Array.isArray(data.rows)) {
                    sessionStorage.removeItem(cKey);
                    return null;
                }

                if (data.v !== this._cacheVersion) {
                    sessionStorage.removeItem(cKey);
                    return null;
                }

                const now = Date.now();
                if (!data.t || (now - data.t) > this._cacheTTL) {
                    sessionStorage.removeItem(cKey);
                    return null;
                }

                const localMutation = parseInt(localStorage.getItem(this._mutationKey) || '0', 10);
                const sessionMutation = parseInt(sessionStorage.getItem(this._mutationKey) || '0', 10);
                const lastMutation = Math.max(localMutation, sessionMutation);
                if (data.t < lastMutation) {
                    sessionStorage.removeItem(cKey);
                    return null;
                }

                return data;
            } catch (e) {
                sessionStorage.removeItem(cKey);
                return null;
            }
        },

        async fetchPage(page, forceRefresh = false) {
            const cKey = this.cacheKey + `_p${page}`;

            if (!forceRefresh) {
                const cached = this.getCachedPage(page);
                if (cached) {
                    this.documentsRows = cached.rows;
                    this.meta = cached.meta;
                    return;
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
                if (forceRefresh) {
                    params.set('refresh', '1');
                }

                const res = await fetch(`/api/v1/dokumen?${params}`, {
                    cache: forceRefresh ? 'no-store' : 'default',
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

                sessionStorage.setItem(cKey, JSON.stringify({
                    v: this._cacheVersion,
                    t: Date.now(),
                    rows,
                    meta,
                }));
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
            const navType = performance.getEntriesByType?.('navigation')?.[0]?.type;
            if (navType === 'reload' || this.dataChanged) {
                this.clearCache();
            }

            const urlParams = new URLSearchParams(window.location.search);
            const filterParam = urlParams.get('filter');
            if (filterParam === 'kadaluarsa') {
                this.filters.kategori = 'sk_pengangkatan';
            }

            const cached = this.getCachedPage(this.meta.current_page);
            if (cached) {
                this.documentsRows = cached.rows;
                this.meta = cached.meta;
                // Tampilkan cache segera, lalu revalidasi agar arsip tetap sinkron dengan mutasi dari profil.
                this.$nextTick(() => {
                    this._watchFilters();
                    this.fetchPage(this.meta.current_page, true);
                });
                return;
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
            this.$watch('filters.employee_id', () => this.applyFilter());
            this.$watch('perPage', () => this.applyFilter());
        },
    }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Arsip Dokumen Kepegawaian</h2>
                @php
                    $archiveEffectiveRole = auth()->user()?->getEffectiveRole();
                    $archiveDashboardUrl = $archiveEffectiveRole === 'kepala_bagian' ? route('kepala-bagian.dashboard') : route('dashboard');
                    $archivePegawaiUrl = $archiveEffectiveRole === 'kepala_bagian' ? route('kepala-bagian.bawahan.index') : ($archiveEffectiveRole === 'pegawai' ? route('profil') : route('data-pegawai'));
                @endphp
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => $archiveDashboardUrl],
                    ['label' => 'Arsip Dokumen'],
                ]" />
                @if($isKepalaBagianArchive)
                    <p class="mt-1 text-xs text-muted font-sans">Hanya menampilkan dokumen bawahan langsung Anda.</p>
                @elseif($isPegawaiArchive)
                    <p class="mt-1 text-xs text-muted font-sans">Hanya menampilkan dokumen Anda sendiri.</p>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <div class="hidden sm:flex max-w-xs flex-col items-end gap-1 text-right">
                    <p class="text-xs text-muted font-sans">Arsip bersifat baca-saja. Unggah, ubah, dan hapus dokumen dilakukan dari bagian Dokumen &amp; SK pada halaman detail pegawai.</p>
                    <a href="{{ $archivePegawaiUrl }}" class="text-xs font-semibold text-primary hover:underline font-sans">Buka Data Pegawai</a>
                </div>
                <x-ui.button type="button" variant="secondary" @click="clearCache(); fetchPage(meta.current_page, true);"
                    title="Refresh data dan periksa ulang status file di storage">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Refresh
                </x-ui.button>
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
            searchPlaceholder="Cari dokumen, nama, atau NIP pegawai..."
            emptyTitle="Tidak ada dokumen ditemukan"
            emptyIcon="document"
            :colspanCount="7"
        >
            {{-- ---- Filter Slots ---- --}}
            <x-slot:filters>
                {{-- Filter Kategori Dokumen --}}
                <div class="relative col-span-1">
                    <x-form.select x-model="filters.kategori"
                       >
                        <option value="">Semua Kategori Dokumen</option>
                        @foreach ($categoryLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                @if($isKepalaBagianArchive)
                    {{-- Filter Bawahan khusus Kepala Bagian --}}
                    <div class="relative col-span-1">
                        <x-form.select x-model="filters.employee_id" aria-label="Filter bawahan">
                            <option value="">Semua Bawahan</option>
                            @foreach ($archiveBawahans as $bawahan)
                                <option value="{{ $bawahan->id }}">{{ $bawahan->nama_lengkap }} — {{ $bawahan->nip }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                @endif
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
                                    <p class="text-xs text-muted" x-text="'NIP. ' + doc.nip_pegawai"></p>
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
                        <td class="px-4 py-3.5 text-xs text-muted" x-text="doc.nomor"></td>

                        {{-- Tanggal --}}
                        <td class="px-4 py-3.5 text-xs text-muted" x-text="doc.tanggal"></td>

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


    </div>

</x-layouts.app>
