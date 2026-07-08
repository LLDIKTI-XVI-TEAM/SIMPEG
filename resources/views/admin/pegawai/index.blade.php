<x-layouts.app title="Data Pegawai">

<div x-data="{
    // ===== State Modal Riwayat (tidak berubah) =====
    showRiwayatModal: false,
    riwayatType: '',
    riwayatEmployeeId: '',
    riwayatEmployeeName: '',
    isSubmitting: false,
    errors: {},
    successMessage: '',
    newPangkat: { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '', file_sk: null },
    newJabatan: { jabatan_id: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', kelas_jabatan: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '', file_sk: null },
    newKgb: { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '', file_sk: null },

    // ===== State Tabel Pegawai =====
    pegawaiRows: @js($initialRows),
    meta: @js($initialMeta),
    isLoading: false,
    perPage: {{ $perPage }},
    dataChanged: @js(session('employee_data_changed', false)),
    sort: '{{ $sort }}',
    direction: '{{ $direction }}',
    filters: {
        search:           '{{ $filters['search'] }}',
        golongan:         '{{ $filters['golongan'] }}',
        unit_kerja_id:    '{{ $filters['unit_kerja_id'] }}',
        jenis_pegawai_id: '{{ $filters['jenis_pegawai_id'] }}',
        status_pegawai_id:'{{ $filters['status_pegawai_id'] ?: 'all' }}',
    },
    searchTimer: null,

    // ===== Computed: cache key berdasarkan semua filter + sort aktif =====
    get cacheKey() {
        const f = this.filters;
        return `pegawai_pp${this.perPage}_s${f.search}_g${f.golongan}_u${f.unit_kerja_id}_j${f.jenis_pegawai_id}_st${f.status_pegawai_id}_sort${this.sort}_dir${this.direction}`;
    },

    // ===== Hapus seluruh cache tabel dari sessionStorage =====
    clearCache() {
        const toDelete = [];
        for (let i = 0; i < sessionStorage.length; i++) {
            const key = sessionStorage.key(i);
            if (key && key.startsWith('pegawai_')) toDelete.push(key);
        }
        toDelete.forEach(k => sessionStorage.removeItem(k));
    },

    // ===== Ambil halaman (cek cache dulu, baru fetch API) =====
    async fetchPage(page) {
        const cKey = this.cacheKey + `_p${page}`;
        const cached = sessionStorage.getItem(cKey);
        if (cached) {
            try {
                const data = JSON.parse(cached);
                this.pegawaiRows = data.rows;
                this.meta = data.meta;
                return;
            } catch (e) {
                sessionStorage.removeItem(cKey);
            }
        }

        this.isLoading = true;
        try {
            const params = new URLSearchParams({
                page,
                per_page: this.perPage,
                sort: this.sort,
                direction: this.direction,
                ...Object.fromEntries(Object.entries(this.filters).filter(([, v]) => v !== '')),
            });
            const res  = await fetch(`/api/v1/pegawai?${params}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();

            const rows = json.employees.data;
            const meta = {
                total:        json.employees.total,
                current_page: json.employees.current_page,
                last_page:    json.employees.last_page,
                from:         json.employees.from ?? 0,
                to:           json.employees.to   ?? 0,
                per_page:     json.employees.per_page,
            };
            sessionStorage.setItem(cKey, JSON.stringify({ rows, meta }));
            this.pegawaiRows = rows;
            this.meta = meta;
        } catch (e) {
            console.error('Gagal fetch data pegawai:', e);
        } finally {
            this.isLoading = false;
        }
    },

    // ===== Terapkan filter (reset ke halaman 1 dan hapus cache lama) =====
    applyFilter() {
        this.clearCache();
        this.fetchPage(1);
    },

    // ===== Ubah sort =====
    setSort(column) {
        if (this.sort === column) {
            this.direction = this.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.sort = column;
            this.direction = 'asc';
        }
        this.clearCache();
        this.fetchPage(1);
    },

    // ===== Ubah per_page =====
    setPerPage(val) {
        this.perPage = parseInt(val);
        this.clearCache();
        this.fetchPage(1);
    },

    // ===== Warna badge status =====
    statusVariant(statusKey) {
        const map = { 'aktif': 'success', 'cuti': 'warning', 'non-aktif': 'danger', 'pensiun': 'danger', 'mutasi': 'warning' };
        return map[statusKey] ?? 'muted';
    },

    // ===== Modal Riwayat methods =====
    openRiwayatModal(type, employeeId, employeeName) {
        this.riwayatType = type;
        this.riwayatEmployeeId = employeeId;
        this.riwayatEmployeeName = employeeName;
        this.errors = {};
        this.successMessage = '';
        this.resetForm();
        this.showRiwayatModal = true;
    },
    resetForm() {
        this.newPangkat = { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '', file_sk: null };
        this.newJabatan = { jabatan_id: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', kelas_jabatan: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '', file_sk: null };
        this.newKgb = { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '', file_sk: null };
    },
    get modalTitle() {
        const titles = { pangkat: 'Tambah Riwayat Kepangkatan', jabatan: 'Tambah Riwayat Jabatan', kgb: 'Tambah Riwayat KGB' };
        return titles[this.riwayatType] || '';
    },
    async submitRiwayat() {
        if (this.isSubmitting) return;
        this.isSubmitting = true;
        this.errors = {};
        this.successMessage = '';
        const endpoints = {
            pangkat: `/api/v1/pegawai/${this.riwayatEmployeeId}/riwayat-kepangkatan`,
            jabatan: `/api/v1/pegawai/${this.riwayatEmployeeId}/riwayat-jabatan`,
            kgb: `/api/v1/pegawai/${this.riwayatEmployeeId}/riwayat-kgb`
        };
        const formData = new FormData();
        let source = this.riwayatType === 'pangkat' ? this.newPangkat : this.riwayatType === 'jabatan' ? this.newJabatan : this.newKgb;
        for (const [key, value] of Object.entries(source)) {
            if (key === 'file_sk') continue;
            if (value !== '' && value !== null) formData.append(key, value);
        }
        const fileInput = this.$refs.fileSkInput;
        if (fileInput && fileInput.files.length > 0) formData.append('file_sk', fileInput.files[0]);
        try {
            const response = await fetch(endpoints[this.riwayatType], {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: formData
            });
            const data = await response.json();
            if (response.ok) {
                this.successMessage = data.message || 'Riwayat berhasil ditambahkan.';
                this.resetForm();
                setTimeout(() => { this.showRiwayatModal = false; this.successMessage = ''; window.location.reload(); }, 1200);
            } else if (response.status === 422 && data.errors) {
                this.errors = data.errors;
            } else {
                this.errors = { _general: [data.message || 'Terjadi kesalahan saat menyimpan data.'] };
            }
        } catch (error) {
            this.errors = { _general: ['Gagal terhubung ke server. Periksa koneksi Anda.'] };
        } finally {
            this.isSubmitting = false;
        }
    },

    // ===== Init: cache halaman awal yang sudah dimuat dari PHP =====
    init() {
        if (this.dataChanged) {
            // Ada perubahan data dari server (edit/tambah/hapus) → bersihkan cache lama dan fetch ulang
            this.clearCache();
            this.fetchPage(1);
            return;
        }
        // Tidak ada perubahan → simpan data awal dari PHP ke cache agar navigasi kembali tidak refetch
        if (this.pegawaiRows.length > 0) {
            const cKey = this.cacheKey + `_p${this.meta.current_page}`;
            if (!sessionStorage.getItem(cKey)) {
                sessionStorage.setItem(cKey, JSON.stringify({ rows: this.pegawaiRows, meta: this.meta }));
            }
        }
    },
}">




    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Data Pegawai']
            ]" />
        </div>
        <div class="flex shrink-0 items-center gap-3">
            {{-- Export button --}}
            <button
                onclick="exportFilteredData()"
                id="export-btn"
                class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Excel
            </button>
            <a
                href="{{ route('data-nonaktif') }}"
                id="nonaktif-list-btn"
                class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-4 py-2 text-sm font-semibold text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-danger shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
                </svg>
                Pegawai Nonaktif
            </a>
            <div class="relative" x-data="{ open: false }">
                <button
                    @click="open = !open"
                    @click.outside="open = false"
                    id="add-pegawai-btn"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 animate-fade-in"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>Tambah Pegawai</span>
                </button>

                <div x-show="open" style="display: none;" x-transition
                    class="absolute right-0 top-full mt-1.5 w-full rounded-lg border border-border bg-surface p-1 shadow-lg z-20">
                    <a href="{{ route('pegawai.create') }}" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-ink hover:bg-soft transition-colors font-sans">
                        <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        Tambah Manual
                    </a>
                    <a href="{{ route('pegawai.import') }}" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-ink hover:bg-soft transition-colors font-sans mt-1">
                        <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                        Import Pegawai
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- FILTER BAR (Alpine-driven, tidak reload halaman) --}}
    <x-ui.card padding="none" class="mb-6">
        <form @submit.prevent="applyFilter()" class="flex flex-col gap-4 p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {{-- Search input --}}
            <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input
                    x-model="filters.search"
                    @input="clearTimeout(searchTimer); searchTimer = setTimeout(() => applyFilter(), 450)"
                    type="text"
                    placeholder="Cari nama atau NIP..."
                    class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans"
                >
            </div>

            {{-- Filter Golongan --}}
            <div class="relative">
                <select x-model="filters.golongan" @change="applyFilter()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Golongan</option>
                    @foreach($golonganOptions as $golongan)
                        <option value="{{ $golongan }}">Golongan {{ $golongan }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Unit --}}
            <div class="relative">
                <select x-model="filters.unit_kerja_id" @change="applyFilter()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit</option>
                    @foreach($unitKerjaOptions as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Jenis --}}
            <div class="relative">
                <select x-model="filters.jenis_pegawai_id" @change="applyFilter()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    @foreach($jenisPegawaiOptions as $jenis)
                        <option value="{{ $jenis->id }}">{{ $jenis->nama }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Status --}}
            <div class="relative">
                <select x-model="filters.status_pegawai_id" @change="applyFilter()" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="all">Semua Status</option>
                    @foreach($statusOptions as $status)
                        <option value="{{ $status->id }}">{{ $status->nama }}</option>
                    @endforeach
                </select>
            </div>
            </div>
        </form>
    </x-ui.card>

    {{-- TABLE --}}
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table id="pegawai-table">
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th class="w-10 select-none">
                            <x-form.checkbox id="check-all" size="sm" />
                        </x-ui.table-th>
                        {{-- Sort: nama_lengkap --}}
                        <x-ui.table-th class="select-none">
                            <button @click="setSort('nama_lengkap')" class="flex items-center gap-1 hover:text-ink transition-colors cursor-pointer">
                                Pegawai
                                <svg :class="'w-3.5 h-3.5 shrink-0 transition ' + (sort === 'nama_lengkap' ? 'text-primary ' + (direction === 'asc' ? 'rotate-180' : '') : '')" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </button>
                        </x-ui.table-th>
                        {{-- Sort: jabatan_terakhir --}}
                        <x-ui.table-th class="select-none">
                            <button @click="setSort('jabatan_terakhir')" class="flex items-center gap-1 hover:text-ink transition-colors cursor-pointer">
                                Jabatan & Unit
                                <svg :class="'w-3.5 h-3.5 shrink-0 transition ' + (sort === 'jabatan_terakhir' ? 'text-primary ' + (direction === 'asc' ? 'rotate-180' : '') : '')" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </button>
                        </x-ui.table-th>
                        {{-- Sort: golongan_terakhir --}}
                        <x-ui.table-th class="select-none">
                            <button @click="setSort('golongan_terakhir')" class="flex items-center gap-1 hover:text-ink transition-colors cursor-pointer">
                                Gol. / Jenis
                                <svg :class="'w-3.5 h-3.5 shrink-0 transition ' + (sort === 'golongan_terakhir' ? 'text-primary ' + (direction === 'asc' ? 'rotate-180' : '') : '')" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </button>
                        </x-ui.table-th>
                        <x-ui.table-th class="select-none">TMT</x-ui.table-th>
                        <x-ui.table-th class="select-none">Status</x-ui.table-th>
                        <x-ui.table-th class="select-none">Dokumen</x-ui.table-th>
                        <x-ui.table-th class="select-none">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    {{-- Loading state --}}
                    <template x-if="isLoading">
                        <tr>
                            <td colspan="8" class="py-10 text-center">
                                <div class="inline-flex items-center gap-2 text-sm text-muted">
                                    <svg class="w-4 h-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                    </svg>
                                    Memuat data...
                                </div>
                            </td>
                        </tr>
                    </template>

                    {{-- Data rows --}}
                    <template x-if="!isLoading && pegawaiRows.length > 0">
                        <template x-for="p in pegawaiRows" :key="p.id">
                            <tr class="border-b border-border last:border-0 hover:bg-soft/40 transition-colors" :data-id="p.id" :data-nip="p.nip">
                                <td class="px-4 py-3">
                                    <x-form.checkbox size="sm" class="row-check" />
                                </td>
                                {{-- Pegawai --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <a :href="`/pegawai/${p.id}`"
                                           class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20">
                                            <img x-show="p.foto_url" :src="p.foto_url" :alt="'Foto ' + p.nama_lengkap"
                                                 class="h-full w-full object-cover object-[center_25%]" loading="lazy"
                                                 x-on:error="$el.classList.add('hidden'); $el.nextElementSibling.classList.remove('hidden')">
                                            <span x-show="!p.foto_url" x-text="p.nama_lengkap.charAt(0).toUpperCase()" aria-hidden="true"></span>
                                        </a>
                                        <div class="min-w-0">
                                            <a :href="`/pegawai/${p.id}`"
                                               class="block truncate text-sm font-semibold text-ink transition hover:text-primary"
                                               x-text="p.nama_lengkap"></a>
                                            <p class="font-mono text-xs text-muted" x-text="'NIP. ' + p.nip"></p>
                                        </div>
                                    </div>
                                </td>
                                {{-- Jabatan & Unit --}}
                                <td class="px-4 py-3">
                                    <p class="text-sm font-medium text-ink" x-text="p.jabatan"></p>
                                    <p class="text-xs text-muted" x-text="p.unit_kerja"></p>
                                </td>
                                {{-- Golongan / Jenis --}}
                                <td class="px-4 py-3">
                                    <span class="text-sm font-medium text-ink" x-text="p.golongan_terakhir + ' / ' + p.jenis_pegawai"></span>
                                </td>
                                {{-- TMT --}}
                                <td class="px-4 py-3">
                                    <p class="text-sm text-ink font-mono" x-text="p.tmt ?? '-'"></p>
                                </td>
                                {{-- Status --}}
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 font-medium font-sans leading-none px-2.5 py-1 text-xs rounded-md"
                                        :class="{
                                            'bg-success/10 text-success': p.status_key === 'aktif',
                                            'bg-warning/10 text-warning': p.status_key === 'cuti' || p.status_key === 'mutasi',
                                            'bg-danger/10 text-danger':   p.status_key === 'non-aktif' || p.status_key === 'pensiun',
                                            'bg-muted/10 text-muted':     !['aktif','cuti','mutasi','non-aktif','pensiun'].includes(p.status_key),
                                        }">
                                        <span class="h-1.5 w-1.5 rounded-full"
                                            :class="{
                                                'bg-success': p.status_key === 'aktif',
                                                'bg-warning': p.status_key === 'cuti' || p.status_key === 'mutasi',
                                                'bg-danger':  p.status_key === 'non-aktif' || p.status_key === 'pensiun',
                                                'bg-muted':   !['aktif','cuti','mutasi','non-aktif','pensiun'].includes(p.status_key),
                                            }"></span>
                                        <span x-text="p.status_nama"></span>
                                    </span>
                                </td>
                                {{-- Dokumen --}}
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 font-medium text-xs rounded-md px-2.5 py-1"
                                        :class="p.is_lengkap ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="p.is_lengkap ? 'bg-success' : 'bg-warning'"></span>
                                        <span x-text="p.is_lengkap ? 'Lengkap' : 'Belum Lengkap'"></span>
                                    </span>
                                </td>
                                {{-- Aksi --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-start gap-1.5">
                                        <a :href="`/pegawai/${p.id}`"
                                           class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                           title="Detail">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </a>
                                        <a :href="`/pegawai/${p.id}/edit`"
                                           class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                           title="Edit">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="!isLoading && pegawaiRows.length === 0">
                        <tr>
                            <td colspan="8" class="px-0 py-0">
                                <x-ui.empty-state icon="search" title="Tidak ada data pegawai yang sesuai." />
                            </td>
                        </tr>
                    </template>
                </x-ui.table-body>
            </x-ui.table>
        </div>

        {{-- TABLE FOOTER --}}
        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted">Tampilkan</span>
                    <select @change="setPerPage($event.target.value)" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        <option value="10" :selected="perPage == 10">10</option>
                        <option value="25" :selected="perPage == 25">25</option>
                        <option value="50" :selected="perPage == 50">50</option>
                    </select>
                    <span class="text-sm text-muted">data per halaman</span>
                </div>
                <p class="text-sm text-muted hidden sm:block" x-show="meta.total > 0">
                    Menampilkan <span class="font-semibold text-ink" x-text="meta.from"></span> hingga <span class="font-semibold text-ink" x-text="meta.to"></span> dari <span class="font-semibold text-ink" x-text="meta.total"></span> hasil
                </p>
            </div>
            {{-- Alpine-driven pagination --}}
            <div class="w-full sm:w-auto" x-show="meta.last_page > 1">
                <div class="flex items-center justify-center gap-1">
                    <button @click="fetchPage(meta.current_page - 1)" :disabled="meta.current_page <= 1"
                        class="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-surface text-sm text-ink transition hover:bg-soft disabled:opacity-40 disabled:cursor-not-allowed">
                        &lsaquo;
                    </button>
                    <template x-for="n in meta.last_page" :key="n">
                        <button @click="fetchPage(n)"
                            :class="n === meta.current_page
                                ? 'bg-primary text-white border-primary'
                                : 'bg-surface text-ink border-border hover:bg-soft'"
                            class="flex h-8 w-8 items-center justify-center rounded-md border text-sm font-medium transition"
                            x-text="n">
                        </button>
                    </template>
                    <button @click="fetchPage(meta.current_page + 1)" :disabled="meta.current_page >= meta.last_page"
                        class="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-surface text-sm text-ink transition hover:bg-soft disabled:opacity-40 disabled:cursor-not-allowed">
                        &rsaquo;
                    </button>
                </div>
            </div>
        </div>
    </x-ui.card>

    {{-- BULK ACTION FLOATING BAR --}}
    <div id="bulk-bar" class="fixed bottom-6 left-1/2 z-40 hidden -translate-x-1/2 items-center gap-3 rounded-lg border border-border bg-surface px-6 py-3.5 shadow-lg">
        <p class="text-sm font-semibold text-ink"><span id="selected-count">0</span> pegawai dipilih</p>
        <div class="h-4 w-px bg-border"></div>
        <button onclick="exportSelectedData()" class="inline-flex items-center gap-1.5 text-xs font-semibold text-warning hover:underline transition-colors cursor-pointer">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            Export Pilihan
        </button>
        <button @click="
            const count = document.querySelectorAll('.row-check:checked').length;
            if (count === 0) {
                window.alert('Tidak ada data pegawai yang dipilih.');
                return;
            }
            document.getElementById('modal-title-bulk-delete').innerText = 'Nonaktifkan ' + count + ' Pegawai Terpilih';
            $dispatch('open-confirm-bulk-delete');
        " class="inline-flex items-center gap-1.5 text-xs font-semibold text-danger hover:underline transition-colors cursor-pointer">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
            </svg>
            Nonaktifkan Pilihan
        </button>
        <button onclick="clearBulk()" class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted hover:text-ink hover:underline transition-colors cursor-pointer">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Batal
        </button>
    </div>

    <x-ui.confirm-dialog
        id="bulk-delete"
        title="Nonaktifkan Pegawai Terpilih"
        message="Apakah Anda yakin ingin menonaktifkan pegawai yang dipilih?"
        confirm-text="Nonaktifkan"
        variant="danger"
    />

    {{-- MODAL TAMBAH RIWAYAT --}}
    <div x-show="showRiwayatModal" @open-riwayat.window="openRiwayatModal($event.detail.type, $event.detail.id, $event.detail.name)" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;" x-transition>
        <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-ink/60 transition-opacity" @click="showRiwayatModal = false"></div>
            <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

            <div class="relative z-10 inline-block transform overflow-hidden rounded-lg bg-surface px-4 pt-5 pb-4 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 sm:align-middle border border-border">
                {{-- Header --}}
                <div class="flex items-center justify-between border-b border-border pb-3 mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans" x-text="modalTitle"></h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Pegawai: <span class="font-semibold text-ink" x-text="riwayatEmployeeName"></span></p>
                    </div>
                    <button @click="showRiwayatModal = false" class="text-muted hover:text-ink cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Success Message --}}
                <template x-if="successMessage">
                    <x-ui.alert variant="success" size="sm" class="mb-4 font-semibold">
                        <span x-text="successMessage"></span>
                    </x-ui.alert>
                </template>

                {{-- General Error --}}
                <template x-if="errors._general">
                    <x-ui.alert variant="danger" size="sm" class="mb-4 font-semibold">
                        <span x-text="errors._general[0]"></span>
                    </x-ui.alert>
                </template>

                <form @submit.prevent="submitRiwayat()" class="space-y-4">
                    {{-- PANGKAT FORM --}}
                    <template x-if="riwayatType === 'pangkat'">
                        <div class="space-y-4">
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                                <select x-model="newPangkat.golongan_id" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    <option value="">-- Pilih Golongan --</option>
                                    @foreach($golonganRefOptions as $gol)
                                        <option value="{{ $gol->id }}">{{ $gol->kode }} - {{ $gol->nama }}</option>
                                    @endforeach
                                </select>
                                <template x-if="errors.golongan_id"><p class="text-[10px] text-danger font-sans" x-text="errors.golongan_id[0]"></p></template>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pangkat <span class="text-danger">*</span></label>
                                <input type="text" x-model="newPangkat.no_sk" required placeholder="SK-321-KP-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <template x-if="errors.no_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.no_sk[0]"></p></template>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-form.date label="Tanggal SK" required size="sm" class="bg-white" x-model="newPangkat.tanggal_sk">
                                        <template x-if="errors.tanggal_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.tanggal_sk[0]"></p></template>
                                    </x-form.date>
                                </div>
                                <div>
                                    <x-form.date label="TMT Pangkat" required size="sm" class="bg-white" x-model="newPangkat.tmt_pangkat">
                                        <template x-if="errors.tmt_pangkat"><p class="text-[10px] text-danger font-sans" x-text="errors.tmt_pangkat[0]"></p></template>
                                    </x-form.date>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK (Opsional)</label>
                                <input type="file" x-ref="fileSkInput" accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-lg border border-border bg-white px-3 py-1.5 text-xs text-ink file:mr-2 file:rounded file:border-0 file:bg-primary/10 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-primary hover:file:bg-primary/20 font-sans">
                                <template x-if="errors.file_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.file_sk[0]"></p></template>
                            </div>
                        </div>
                    </template>

                    {{-- JABATAN FORM --}}
                    <template x-if="riwayatType === 'jabatan'">
                        <div class="space-y-4">
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan <span class="text-danger">*</span></label>
                                <select x-model="newJabatan.jabatan_id" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    <option value="">-- Pilih Jabatan --</option>
                                    @foreach($jabatanOptions as $jabatan)
                                        <option value="{{ $jabatan->id }}">{{ $jabatan->nama }}{{ $jabatan->jenisJabatan ? ' - '.$jabatan->jenisJabatan->nama : '' }}</option>
                                    @endforeach
                                </select>
                                <template x-if="errors.jabatan_id"><p class="text-[10px] text-danger font-sans" x-text="errors.jabatan_id[0]"></p></template>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan</label>
                                    <select x-model="newJabatan.jenis_jabatan_id" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih --</option>
                                        @foreach($jenisJabatanOptions as $jj)
                                            <option value="{{ $jj->id }}">{{ $jj->nama }}</option>
                                        @endforeach
                                    </select>
                                    <template x-if="errors.jenis_jabatan_id"><p class="text-[10px] text-danger font-sans" x-text="errors.jenis_jabatan_id[0]"></p></template>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Eselon (Opsional)</label>
                                    <select x-model="newJabatan.eselon_id" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih --</option>
                                        @foreach($eselonOptions as $esl)
                                            <option value="{{ $esl->id }}">{{ $esl->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja <span class="text-danger">*</span></label>
                                <select x-model="newJabatan.unit_kerja_id" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    <option value="">-- Pilih Unit Kerja --</option>
                                    @foreach($unitKerjaOptions as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                                    @endforeach
                                </select>
                                <template x-if="errors.unit_kerja_id"><p class="text-[10px] text-danger font-sans" x-text="errors.unit_kerja_id[0]"></p></template>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kelas Jabatan</label>
                                <input type="text" x-model="newJabatan.kelas_jabatan" placeholder="8" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <template x-if="errors.kelas_jabatan"><p class="text-[10px] text-danger font-sans" x-text="errors.kelas_jabatan[0]"></p></template>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Jabatan <span class="text-danger">*</span></label>
                                <input type="text" x-model="newJabatan.no_sk" required placeholder="SK-910-JAB-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <template x-if="errors.no_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.no_sk[0]"></p></template>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-form.date label="Tanggal SK" required size="sm" class="bg-white" x-model="newJabatan.tanggal_sk">
                                        <template x-if="errors.tanggal_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.tanggal_sk[0]"></p></template>
                                    </x-form.date>
                                </div>
                                <div>
                                    <x-form.date label="TMT Jabatan" required size="sm" class="bg-white" x-model="newJabatan.tmt_jabatan">
                                        <template x-if="errors.tmt_jabatan"><p class="text-[10px] text-danger font-sans" x-text="errors.tmt_jabatan[0]"></p></template>
                                    </x-form.date>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK (Opsional)</label>
                                <input type="file" x-ref="fileSkInput" accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-lg border border-border bg-white px-3 py-1.5 text-xs text-ink file:mr-2 file:rounded file:border-0 file:bg-primary/10 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-primary hover:file:bg-primary/20 font-sans">
                                <template x-if="errors.file_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.file_sk[0]"></p></template>
                            </div>
                        </div>
                    </template>

                    {{-- KGB FORM --}}
                    <template x-if="riwayatType === 'kgb'">
                        <div class="space-y-4">
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Gaji Pokok Baru <span class="text-danger">*</span></label>
                                <input type="number" x-model="newKgb.gaji_pokok" required placeholder="4100000" min="0" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <template x-if="errors.gaji_pokok"><p class="text-[10px] text-danger font-sans" x-text="errors.gaji_pokok[0]"></p></template>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Surat KGB <span class="text-danger">*</span></label>
                                <input type="text" x-model="newKgb.no_sk" required placeholder="KGB-012-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <template x-if="errors.no_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.no_sk[0]"></p></template>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-form.date label="Tanggal Surat" required size="sm" class="bg-white" x-model="newKgb.tanggal_sk">
                                        <template x-if="errors.tanggal_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.tanggal_sk[0]"></p></template>
                                    </x-form.date>
                                </div>
                                <div>
                                    <x-form.date label="TMT KGB" required size="sm" class="bg-white" x-model="newKgb.tmt_kgb">
                                        <template x-if="errors.tmt_kgb"><p class="text-[10px] text-danger font-sans" x-text="errors.tmt_kgb[0]"></p></template>
                                    </x-form.date>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK (Opsional)</label>
                                <input type="file" x-ref="fileSkInput" accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-lg border border-border bg-white px-3 py-1.5 text-xs text-ink file:mr-2 file:rounded file:border-0 file:bg-primary/10 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-primary hover:file:bg-primary/20 font-sans">
                                <template x-if="errors.file_sk"><p class="text-[10px] text-danger font-sans" x-text="errors.file_sk[0]"></p></template>
                            </div>
                        </div>
                    </template>

                    {{-- BUTTONS --}}
                    <div class="border-t border-border pt-4 flex justify-end gap-2.5 mt-6">
                        <x-ui.button type="button" variant="muted" size="xs" @click="showRiwayatModal = false" x-bind:disabled="isSubmitting">
                            Batal
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="xs" x-bind:disabled="isSubmitting">
                            <template x-if="isSubmitting">
                                <x-ui.loading size="md" color="white" class="-ml-1 mr-2" />
                            </template>
                            <span x-text="isSubmitting ? 'Menyimpan...' : 'Simpan Riwayat'"></span>
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div> {{-- end Alpine.js x-data wrapper --}}

    @push('scripts')
    <script>
    // ===== Checkbox Bulk Action =====
    const checkAll = document.getElementById('check-all');
    const bulkBar  = document.getElementById('bulk-bar');
    const countEl  = document.getElementById('selected-count');

    function updateBulk() {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (countEl) countEl.textContent = n;
        if (bulkBar) {
            bulkBar.classList.toggle('hidden', n === 0);
            bulkBar.classList.toggle('flex',   n > 0);
        }
    }

    // Delegasi event untuk row-check (karena tabel di-render Alpine secara dinamis)
    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('row-check')) {
            const all    = document.querySelectorAll('.row-check').length;
            const ticked = document.querySelectorAll('.row-check:checked').length;
            if (checkAll) {
                checkAll.indeterminate = ticked > 0 && ticked < all;
                checkAll.checked       = ticked === all;
            }
            updateBulk();
        }
    });

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.row-check').forEach(cb => { cb.checked = checkAll.checked; });
            updateBulk();
        });
    }

    function clearBulk() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        if (checkAll) { checkAll.checked = false; checkAll.indeterminate = false; }
        updateBulk();
    }

    // ===== Export seluruh data yang difilter =====
    function exportFilteredData() {
        const alpineData = Alpine.store ? Alpine.$data(document.querySelector('[x-data]')) : null;
        const exportUrl = new URL(@json(route('pegawai.export')), window.location.origin);
        // Ambil filter dari Alpine state
        try {
            const el = document.querySelector('[x-data]');
            const data = Alpine.$data ? Alpine.$data(el) : null;
            if (data) {
                const f = data.filters;
                if (f.search)           exportUrl.searchParams.set('search', f.search);
                if (f.golongan)         exportUrl.searchParams.set('golongan', f.golongan);
                if (f.unit_kerja_id)    exportUrl.searchParams.set('unit_kerja_id', f.unit_kerja_id);
                if (f.jenis_pegawai_id) exportUrl.searchParams.set('jenis_pegawai_id', f.jenis_pegawai_id);
                if (f.status_pegawai_id)exportUrl.searchParams.set('status_pegawai_id', f.status_pegawai_id);
            }
        } catch(e) {}
        window.location.href = exportUrl.toString();
    }

    // ===== Export baris yang dipilih (Bulk Action) =====
    function exportSelectedData() {
        const selectedNips = Array.from(document.querySelectorAll('.row-check:checked'))
            .map(cb => cb.closest('tr')?.dataset?.nip)
            .filter(Boolean);

        if (selectedNips.length === 0) { window.alert('Tidak ada data pegawai yang dipilih.'); return; }

        const exportUrl = new URL(@json(route('pegawai.export')), window.location.origin);
        selectedNips.forEach(nip => exportUrl.searchParams.append('nips[]', nip));
        window.location.href = exportUrl.toString();
    }

    // ===== Bulk nonaktifkan =====
    window.addEventListener('confirm-bulk-delete', () => {
        const selectedIds = Array.from(document.querySelectorAll('.row-check:checked'))
            .map(cb => cb.closest('tr')?.dataset?.id)
            .filter(Boolean);

        if (selectedIds.length === 0) { window.alert('Tidak ada data pegawai yang dipilih.'); return; }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = @json(route('pegawai.bulkDestroy'));
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = '_token';
        csrfInput.value = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        form.appendChild(csrfInput);
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'ids[]'; input.value = id;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });
    </script>
    @endpush

</x-layouts.app>
