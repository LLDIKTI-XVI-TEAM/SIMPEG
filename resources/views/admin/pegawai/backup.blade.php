<x-layouts.app title="Data Backup Pegawai">

<div x-data="{
    // ===== State Tabel =====
    rows: @js($initialRows),
    meta: @js($initialMeta),
    isLoading: false,
    fetchError: null,
    perPage: {{ $perPage }},
    searchTimer: null,
    dataChanged: @js($dataChanged),
    filters: { search: '{{ $search }}' },

    // ===== State Seleksi =====
    selectedIds: [],

    // ===== State Modals =====
    showBulkRestoreModal: false,
    isBulkRestoring: false,
    showSingleRestoreModal: false,
    singleRestoreId: null,
    singleRestoreName: '',
    isSingleRestoring: false,

    // ===== cacheKey unik per kombinasi filter =====
    get cacheKey() {
        return `backup_pp${this.perPage}_s${this.filters.search}`;
    },

    clearCache() {
        const toDelete = [];
        for (let i = 0; i < sessionStorage.length; i++) {
            const key = sessionStorage.key(i);
            if (key && key.startsWith('backup_')) toDelete.push(key);
        }
        toDelete.forEach(k => sessionStorage.removeItem(k));
    },

    async fetchPage(page) {
        const cKey = this.cacheKey + `_p${page}`;
        const cached = sessionStorage.getItem(cKey);
        if (cached) {
            try {
                const data = JSON.parse(cached);
                this.rows = data.rows;
                this.meta = data.meta;
                this.selectedIds = [];
                this._syncCheckboxes();
                return;
            } catch (e) { sessionStorage.removeItem(cKey); }
        }

        this.isLoading = true;
        this.fetchError = null;
        try {
            const params = new URLSearchParams({
                page,
                per_page: this.perPage,
                ...Object.fromEntries(Object.entries(this.filters).filter(([, v]) => v !== '')),
            });
            const res = await fetch(`/api/v1/pegawai/backup?${params}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();

            const rows = json.employees?.data ?? [];
            const meta = {
                total:        json.employees?.total        ?? 0,
                current_page: json.employees?.current_page ?? 1,
                last_page:    json.employees?.last_page    ?? 1,
                from:         json.employees?.from         ?? 0,
                to:           json.employees?.to           ?? 0,
                per_page:     json.employees?.per_page     ?? this.perPage,
            };

            sessionStorage.setItem(cKey, JSON.stringify({ rows, meta }));
            this.rows = rows;
            this.meta = meta;
            this.selectedIds = [];
            this._syncCheckboxes();
        } catch (e) {
            console.error('[Backup] Gagal fetch:', e);
            this.fetchError = e.message;
        } finally {
            this.isLoading = false;
        }
    },

    applyFilter() {
        this.clearCache();
        this.fetchPage(1);
    },

    // ===== Seleksi =====
    get selectedCount() { return this.selectedIds.length; },

    toggleAll(checked) {
        document.querySelectorAll('.backup-check').forEach(b => { b.checked = checked; });
        this._syncSelected();
    },

    _syncSelected() {
        this.selectedIds = Array.from(document.querySelectorAll('.backup-check:checked')).map(b => b.value);
        const all = document.querySelectorAll('.backup-check');
        const header = document.getElementById('backup-check-all');
        if (!header) return;
        if (this.selectedIds.length === 0) { header.checked = false; header.indeterminate = false; }
        else if (this.selectedIds.length === all.length) { header.checked = true; header.indeterminate = false; }
        else { header.checked = false; header.indeterminate = true; }
    },

    _syncCheckboxes() {
        this.$nextTick(() => {
            document.querySelectorAll('.backup-check').forEach(b => b.checked = false);
            const header = document.getElementById('backup-check-all');
            if (header) { header.checked = false; header.indeterminate = false; }
            this.selectedIds = [];
        });
    },

    clearSelection() {
        document.querySelectorAll('.backup-check').forEach(b => b.checked = false);
        this.selectedIds = [];
        const header = document.getElementById('backup-check-all');
        if (header) { header.checked = false; header.indeterminate = false; }
    },

    // ===== Restore Single =====
    openSingleRestore(id, name) {
        this.singleRestoreId = id;
        this.singleRestoreName = name;
        this.showSingleRestoreModal = true;
    },

    async confirmSingleRestore() {
        if (!this.singleRestoreId) return;
        this.isSingleRestoring = true;
        try {
            const res = await fetch(`/api/v1/pegawai/${this.singleRestoreId}/restore`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
            });
            if (!res.ok) { const d = await res.json(); throw new Error(d.message || `HTTP ${res.status}`); }
            this.clearCache();
            await this.fetchPage(this.meta.current_page);
            this.showSingleRestoreModal = false;
        } catch (e) {
            alert('Gagal memulihkan: ' + e.message);
        } finally {
            this.isSingleRestoring = false;
            this.singleRestoreId = null;
        }
    },

    init() {
        if (this.dataChanged) {
            this.clearCache();
            this.fetchPage(1);
            return;
        }
        const cKey = this.cacheKey + `_p${this.meta.current_page}`;
        const cached = sessionStorage.getItem(cKey);
        if (cached) {
            try {
                const data = JSON.parse(cached);
                this.rows = data.rows;
                this.meta = data.meta;
                this.$nextTick(() => this._watchFilters());
                return;
            } catch (e) { sessionStorage.removeItem(cKey); }
        }
        if (this.rows.length > 0 || this.meta.total === 0) {
            sessionStorage.setItem(cKey, JSON.stringify({ rows: this.rows, meta: this.meta }));
        }
        this._watchFilters();
    },

    _watchFilters() {
        this.$watch('filters.search', () => {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.applyFilter(), 300);
        });
        this.$watch('perPage', () => { this.clearCache(); this.fetchPage(1); });
    },
}" class="space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink font-sans">Data Backup Pegawai</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Data Pegawai', 'url' => route('data-pegawai')],
                ['label' => 'Data Backup'],
            ]" />
        </div>
        <div class="flex shrink-0 items-center gap-3">
            {{-- Refresh --}}
            <button type="button" @click="clearCache(); fetchPage(meta.current_page);"
                class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer font-sans"
                title="Refresh Data">
                <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Refresh
            </button>
            <a href="{{ route('data-pegawai') }}"
                class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans">
                <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                </svg>
                Data Pegawai
            </a>
        </div>
    </div>

    {{-- INFO BANNER --}}
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 px-5 py-4">
        <svg class="mt-0.5 w-5 h-5 shrink-0 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
        </svg>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-ink font-sans">Trash / Data Backup Pegawai</p>
            <p class="text-xs text-muted font-sans mt-0.5">
                Pegawai yang dihapus disimpan di sini selama <span class="font-semibold text-ink">{{ $retentionDays }} hari</span>.
                Setelah {{ $retentionDays }} hari, data beserta semua riwayat dan file akan
                <span class="font-semibold text-danger">dihapus permanen otomatis</span>.
                Pilih satu atau lebih untuk dipulihkan sekaligus.
            </p>
        </div>
        @if($expiredCount > 0)
        <div class="shrink-0 flex items-center gap-1.5 rounded-lg bg-danger/10 border border-danger/20 px-3 py-2">
            <svg class="w-4 h-4 text-danger shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <span class="text-xs font-bold text-danger font-sans whitespace-nowrap">{{ $expiredCount }} akan dihapus</span>
        </div>
        @endif
    </div>

    {{-- DATA TABLE --}}
    <x-ui.data-table
        rows="rows"
        meta="meta"
        :columns="[
            ['key' => 'check',            'label' => '', 'width' => 'w-10'],
            ['key' => 'nama_lengkap',     'label' => 'Pegawai'],
            ['key' => 'jabatan',          'label' => 'Jabatan & Unit Terakhir'],
            ['key' => 'golongan_terakhir','label' => 'Gol. / Jenis'],
            ['key' => 'deleted_at_human', 'label' => 'Dihapus Pada'],
            ['key' => 'sisa_waktu',       'label' => 'Sisa Waktu'],
            ['key' => 'aksi',             'label' => 'Aksi', 'align' => 'right'],
        ]"
        fetchPage="fetchPage(page)"
        isLoading="isLoading"
        perPage="perPage"
        setPerPage="perPage = parseInt($event.target.value)"
        searchModel="filters.search"
        searchPlaceholder="Cari nama atau NIP..."
        emptyTitle="Tidak ada data backup."
        emptyIcon="document"
        :colspanCount="7"
        checkAllId="backup-check-all"
        checkAllAction="toggleAll($event.target.checked)"
    >
        {{-- override select-all header dikerjakan manual via Alpine karena event @change tidak bisa di-bind di komponen --}}

        {{-- Rows --}}
        <template x-if="!isLoading && rows && rows.length > 0">
            <template x-for="(p, index) in rows" :key="p.id">
                <tr class="border-b border-border last:border-0 transition-colors hover:bg-soft/40"
                    :class="{
                        'bg-danger/5':  p.is_expired,
                        'bg-warning/5': p.is_urgent && !p.is_expired,
                    }">

                    {{-- Checkbox --}}
                    <td class="px-4 py-3.5">
                        <input type="checkbox" class="backup-check h-4 w-4 rounded border-border text-primary focus:ring-primary/20 cursor-pointer"
                            :value="p.id" @change="_syncSelected()">
                    </td>

                    {{-- Pegawai --}}
                    <td class="px-4 py-3.5">
                        <div class="flex items-center gap-3">
                            <div class="relative flex h-8 w-8 shrink-0 overflow-hidden items-center justify-center rounded-full border text-sm font-bold"
                                :class="p.is_expired ? 'border-danger/30 bg-danger/10 text-danger' : 'border-border bg-soft text-muted'">
                                <template x-if="p.foto_url">
                                    <img :src="p.foto_url" :alt="'Foto ' + p.nama_lengkap"
                                        class="h-full w-full object-cover object-[center_25%]" loading="lazy"
                                        x-on:error="$el.classList.add('hidden'); $el.nextElementSibling.classList.remove('hidden')">
                                </template>
                                <span x-show="!p.foto_url" x-text="p.nama_lengkap ? p.nama_lengkap.charAt(0).toUpperCase() : '?'" aria-hidden="true"></span>
                            </div>
                            <div class="min-w-0">
                                <p class="block truncate text-sm font-semibold text-ink font-sans" x-text="p.nama_lengkap"></p>
                                <p class="text-xs text-muted" x-text="'NIP. ' + p.nip"></p>
                            </div>
                        </div>
                    </td>

                    {{-- Jabatan & Unit --}}
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-medium text-ink font-sans" x-text="p.jabatan"></p>
                        <p class="text-xs text-muted" x-text="p.unit_kerja"></p>
                    </td>

                    {{-- Golongan / Jenis --}}
                    <td class="px-4 py-3.5">
                        <span class="text-sm font-medium text-ink" x-text="p.golongan_terakhir + ' / ' + p.jenis_pegawai"></span>
                    </td>

                    {{-- Dihapus Pada --}}
                    <td class="px-4 py-3.5">
                        <p class="text-xs text-ink" x-text="p.deleted_at_human.split(' ')[0]"></p>
                        <p class="text-[10px] text-muted" x-text="p.deleted_at_human.split(' ')[1] ?? ''"></p>
                    </td>

                    {{-- Sisa Waktu --}}
                    <td class="px-4 py-3.5">
                        <span class="inline-flex items-center gap-1.5 font-medium font-sans leading-none px-2.5 py-1 text-xs rounded-md"
                            :class="{
                                'bg-danger/10 text-danger':   p.is_expired || p.is_urgent,
                                'bg-warning/10 text-warning': p.is_warning,
                                'bg-soft text-muted':         !p.is_expired && !p.is_urgent && !p.is_warning,
                            }">
                            <span class="h-1.5 w-1.5 rounded-full"
                                :class="{
                                    'bg-danger animate-pulse': p.is_expired,
                                    'bg-danger':   p.is_urgent && !p.is_expired,
                                    'bg-warning':  p.is_warning,
                                    'bg-muted':    !p.is_expired && !p.is_urgent && !p.is_warning,
                                }"></span>
                            <span x-text="p.is_expired ? 'Akan dihapus' : p.sisa_hari + ' hari lagi'"></span>
                        </span>
                        <p class="text-[10px] text-muted font-sans mt-1" x-text="'Hapus: ' + p.purge_at_human"></p>
                    </td>

                    {{-- Aksi --}}
                    <td class="px-4 py-3.5 text-right">
                        <button type="button"
                            @click="openSingleRestore(p.id, p.nama_lengkap)"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-success/40 bg-surface px-3 py-1.5 text-xs font-semibold text-success transition hover:bg-success/10 hover:border-success shadow-sm font-sans"
                            :aria-label="'Pulihkan ' + p.nama_lengkap">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                            </svg>
                            Pulihkan
                        </button>
                    </td>

                </tr>
            </template>
        </template>

        {{-- Error state --}}
        <template x-if="!isLoading && fetchError">
            <tr>
                <td colspan="7" class="px-4 py-10 text-center">
                    <div class="flex flex-col items-center gap-2">
                        <svg class="w-8 h-8 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <p class="text-sm font-semibold text-ink font-sans">Gagal memuat data</p>
                        <button type="button" @click="clearCache(); fetchPage(meta.current_page)"
                            class="mt-1 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90 font-sans">
                            Coba Lagi
                        </button>
                    </div>
                </td>
            </tr>
        </template>

    </x-ui.data-table>

    {{-- KETERANGAN WARNA --}}
    <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-muted font-sans">
        <span class="font-semibold text-ink">Keterangan:</span>
        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-muted inline-block"></span>Aman (&gt;7 hari)</span>
        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-warning inline-block"></span>Peringatan (3–7 hari)</span>
        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-danger inline-block"></span>Segera dihapus (0–3 hari / expired)</span>
    </div>

    {{-- ============================================================ --}}
    {{-- FLOATING BULK ACTION BAR --}}
    {{-- ============================================================ --}}
    <div x-show="selectedCount > 0"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        style="display:none;"
        class="fixed bottom-6 left-1/2 z-40 -translate-x-1/2 flex items-center gap-3 rounded-xl border border-border bg-surface px-6 py-3.5 shadow-xl">
        <div class="flex items-center gap-2">
            <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-bold text-white font-sans"
                x-text="selectedCount"></span>
            <span class="text-sm font-semibold text-ink font-sans">pegawai dipilih</span>
        </div>
        <div class="h-4 w-px bg-border"></div>
        <button type="button" @click="showBulkRestoreModal = true"
            class="inline-flex items-center gap-1.5 text-sm font-semibold text-success transition hover:underline cursor-pointer font-sans">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
            </svg>
            Pulihkan Pilihan
        </button>
        <div class="h-4 w-px bg-border"></div>
        <button type="button" @click="clearSelection()"
            class="text-sm font-semibold text-muted transition hover:text-ink cursor-pointer font-sans">
            Batal Pilih
        </button>
    </div>

    {{-- ============================================================ --}}
    {{-- MODAL RESTORE SINGLE (via API) --}}
    {{-- ============================================================ --}}
    <x-ui.modal
        show="showSingleRestoreModal"
        title="Pulihkan Data Pegawai"
        closeAction="showSingleRestoreModal = false"
        maxWidth="sm"
    >
        <div class="space-y-4">
            <div class="flex items-start gap-3 rounded-lg bg-success/10 border border-success/20 p-3">
                <svg class="w-5 h-5 mt-0.5 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-sm text-ink font-sans">
                    Pulihkan pegawai <strong x-text="singleRestoreName" class="text-primary"></strong> ke daftar pegawai aktif?
                </p>
            </div>
            <div class="flex justify-end gap-3 pt-2 border-t border-border">
                <button type="button" @click="showSingleRestoreModal = false" :disabled="isSingleRestoring"
                    class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans disabled:opacity-50">
                    Batal
                </button>
                <button type="button" @click="confirmSingleRestore()" :disabled="isSingleRestoring"
                    class="inline-flex items-center justify-center rounded-lg bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                    <svg x-show="isSingleRestoring" class="mr-2 h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="isSingleRestoring ? 'Memproses...' : 'Pulihkan'"></span>
                </button>
            </div>
        </div>
    </x-ui.modal>

    {{-- ============================================================ --}}
    {{-- MODAL BULK RESTORE (form POST) --}}
    {{-- ============================================================ --}}
    <x-ui.modal
        show="showBulkRestoreModal"
        title="Pulihkan Pegawai Terpilih"
        closeAction="showBulkRestoreModal = false"
        maxWidth="sm"
    >
        <div class="space-y-4">
            <div class="flex items-start gap-3 rounded-lg bg-success/10 border border-success/20 p-3">
                <svg class="w-5 h-5 mt-0.5 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-sm text-ink font-sans">
                    Pulihkan <strong x-text="selectedCount" class="text-primary"></strong> pegawai terpilih ke daftar pegawai aktif?
                    <br><span class="text-xs text-muted mt-0.5 block">Semua data yang dipilih akan dikembalikan ke status aktif.</span>
                </p>
            </div>
            <form id="bulk-restore-form" method="POST" action="{{ route('pegawai.bulkRestore') }}">
                @csrf
            </form>
            <div class="flex justify-end gap-3 pt-2 border-t border-border">
                <button type="button" @click="showBulkRestoreModal = false" :disabled="isBulkRestoring"
                    class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans disabled:opacity-50">
                    Batal
                </button>
                <button type="button" :disabled="isBulkRestoring"
                    @click="
                        isBulkRestoring = true;
                        const form = document.getElementById('bulk-restore-form');
                        form.querySelectorAll('input[name=\'ids[]\']').forEach(el => el.remove());
                        selectedIds.forEach(id => {
                            const inp = document.createElement('input');
                            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
                            form.appendChild(inp);
                        });
                        form.submit();
                    "
                    class="inline-flex items-center justify-center rounded-lg bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                    <svg x-show="isBulkRestoring" class="mr-2 h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <svg x-show="!isBulkRestoring" class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    <span x-text="isBulkRestoring ? 'Memproses...' : 'Ya, Pulihkan Semua'"></span>
                </button>
            </div>
        </div>
    </x-ui.modal>

</div>{{-- end x-data --}}

</x-layouts.app>
