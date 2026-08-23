<div>
    @php
        $employeeShowUrlPrefix = $employeeShowUrlPrefix ?? route('data-pegawai');
        $serverRenderedDetailLinks = $serverRenderedDetailLinks ?? [];
        $isReadOnly = $isReadOnly ?? false;
        $openStatusModal = $openStatusModal ?? false;
        $statusFormEmployee = $statusFormEmployee ?? null;
        $canChangeStatus = $canChangeStatus ?? false;
    @endphp

    <div x-data="{
    @if (! $isReadOnly)
    // ===== State Modal Riwayat =====
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


    // ===== State Modal Nonaktifkan Pegawai =====
    showDeleteModal: false,
    deletePegawaiId: null,
    deletePegawaiName: '',
    isDeleting: false,

    // ===== State Modal Aktifkan Kembali Pegawai =====
    showRestoreModal: false,
    restorePegawaiId: null,
    restorePegawaiName: '',
    isRestoring: false,

    // ===== State Modal Rincian Dokumen =====
    showDocumentStatusModal: false,
    documentStatusEmployee: null,
    documentStatus: { status_kelengkapan: 'kosong', is_lengkap: false, total_riwayat: 0, file_tersedia: 0, records: [], total_dokumen: 0, dokumen_tersedia: 0, documents: [] },
    isLoadingDocumentStatus: false,
    documentStatusError: '',
    @endif

    // ===== Modal Ubah Status Pegawai =====
    showStatusModal: @js($openStatusModal),
    statusEmployee: @js($statusFormEmployee ? [
        'id' => $statusFormEmployee->id,
        'nama_lengkap' => $statusFormEmployee->nama_lengkap,
        'nip' => $statusFormEmployee->nip,
    ] : null),

    // ===== State Tabel Pegawai =====
    pegawaiRows: @js($initialRows),
    meta: @js($initialMeta),
    isLoading: false,
    perPage: {{ $perPage }},
    @if (! $isReadOnly)
    dataChanged: @js(session('employee_data_changed', false)),
    editedEmployeeId: @js(session('edited_employee_id', null)),
    editedEmployeeData: @js(session('edited_employee_data', null)),
    @endif
    sort: '{{ $sort }}',
    direction: '{{ $direction }}',
    employeeShowUrlPrefix: @js($employeeShowUrlPrefix),
    filters: {
        search:            '{{ $filters['search'] }}',
        golongan:          '{{ $filters['golongan'] }}',
        unit_kerja_id:     '{{ $filters['unit_kerja_id'] }}',
        jenis_pegawai_id:  '{{ $filters['jenis_pegawai_id'] }}',
        status_pegawai_id: '{{ $filters['status_pegawai_id'] ?: 'all' }}',
        show_nonaktif: {{ ($filters['show_nonaktif'] ?? false) ? 'true' : 'false' }},
    },
    searchTimer: null,

    detailUrl(employee) {
        return `${this.employeeShowUrlPrefix}/${employee.id}`;
    },

    openStatusModal(employee) {
        this.statusEmployee = {
            id: employee.id,
            nama_lengkap: employee.nama_lengkap,
            nip: employee.nip,
        };
        this.showStatusModal = true;
    },

    closeStatusModal() {
        this.showStatusModal = false;
    },

    get cacheKey() {
        const f = this.filters;
        return `pegawai_pp${this.perPage}_s${f.search}_g${f.golongan}_u${f.unit_kerja_id}_j${f.jenis_pegawai_id}_st${f.status_pegawai_id}_na${f.show_nonaktif}_sort${this.sort}_dir${this.direction}`;
    },

    clearCacheByPrefixes(prefixes) {
        const toDelete = [];
        for (let i = 0; i < sessionStorage.length; i++) {
            const key = sessionStorage.key(i);
            if (key && prefixes.some((prefix) => key.startsWith(prefix))) toDelete.push(key);
        }
        toDelete.forEach(k => sessionStorage.removeItem(k));
    },

    clearCache() {
        this.clearCacheByPrefixes(['pegawai_']);
    },

    @if (! $isReadOnly)
    clearEmployeeLifecycleCache() {
        // Perubahan status aktif/nonaktif memengaruhi daftar pegawai dan Data Backup.
        this.clearCacheByPrefixes(['pegawai_', 'backup_']);
    },
    @endif

    async fetchPage(page) {
        const cKey = this.cacheKey + `_p${page}`;
        const cached = sessionStorage.getItem(cKey);
        if (cached) {
            try {
                const data = JSON.parse(cached);
                this.pegawaiRows = data.rows;
                this.meta = data.meta;
                @if (! $isReadOnly)
                // Pilihan baris tidak boleh terbawa antar halaman atau antar mode filter.
                this.$nextTick(() => {
                    document.querySelectorAll('.row-check').forEach(c => c.checked = false);
                    updateBulkBar();
                });
                @endif
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
                ...Object.fromEntries(Object.entries({
                    ...this.filters,
                    show_nonaktif: this.filters.show_nonaktif ? '1' : '0',
                }).filter(([, v]) => v !== '')),
            });
            const res = await fetch(`/api/v1/pegawai?${params}`, {
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
            @if (! $isReadOnly)
            // Reset semua checkbox saat data baru dimuat
            this.$nextTick(() => {
                document.querySelectorAll('.row-check').forEach(c => c.checked = false);
                updateBulkBar();
            });
            @endif
        } catch (e) {
            console.error('Gagal fetch data pegawai:', e);
        } finally {
            this.isLoading = false;
        }
    },

    applyFilter() {
        this.fetchPage(1);
    },

    @if (! $isReadOnly)
    /**
     * Nonaktifkan dan pulihkan memindahkan pegawai antar daftar sehingga jumlah data di server
     * berubah. Halaman dimuat ulang agar jumlah baris, penomoran, dan rentang data tidak
     * menyisakan metadata lama, termasuk saat baris terakhir sebuah halaman ikut berpindah.
     */
    async refreshAfterListMembershipChange() {
        const requestedPage = this.meta?.current_page ?? 1;
        await this.fetchPage(requestedPage);

        const lastPage = this.meta?.last_page ?? 1;
        if (requestedPage > lastPage) {
            await this.fetchPage(lastPage);
        }
    },
    @endif

    setSort(column) {
        if (this.sort === column) {
            this.direction = this.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.sort = column;
            this.direction = 'asc';
        }
        this.fetchPage(1);
    },

    setPerPage(val) {
        this.perPage = parseInt(val);
        this.fetchPage(1);
    },

    @if (! $isReadOnly)
    deletePegawai(id, name) {
        this.deletePegawaiId = id;
        this.deletePegawaiName = name || '';
        this.showDeleteModal = true;
    },

    async openDocumentStatus(employee) {
        this.documentStatusEmployee = { id: employee.id, nama_lengkap: employee.nama_lengkap, nip: employee.nip };
        this.documentStatus = { status_kelengkapan: employee.is_lengkap, is_lengkap: employee.is_lengkap === 'lengkap', total_riwayat: 0, file_tersedia: 0, records: [], total_dokumen: 0, dokumen_tersedia: 0, documents: [] };
        this.documentStatusError = '';
        this.showDocumentStatusModal = true;
        this.isLoadingDocumentStatus = true;

        try {
            const response = await fetch(`/api/v1/pegawai/${employee.id}/status-dokumen`, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);

            this.documentStatusEmployee = data.employee;
            this.documentStatus = data.document_status;
        } catch (error) {
            console.error('Gagal memuat rincian dokumen pegawai:', error);
            this.documentStatusError = error.message || 'Rincian dokumen tidak dapat dimuat.';
        } finally {
            this.isLoadingDocumentStatus = false;
        }
    },

    async confirmDeletePegawai() {
        if (!this.deletePegawaiId) return;
        this.isDeleting = true;
        try {
            // Soft delete — data dipindahkan ke Backup dan dapat dipulihkan.
            const res = await fetch(`/api/v1/pegawai/${this.deletePegawaiId}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}'
                }
            });
            if (!res.ok) {
                const data = await res.json();
                throw new Error(data.message || `HTTP ${res.status}`);
            }
            // Cache daftar aktif dan nonaktif harus dimuat ulang agar kedua mode konsisten.
            this.clearEmployeeLifecycleCache();

            this.showDeleteModal = false;
            await this.refreshAfterListMembershipChange();
        } catch (error) {
            console.error('Error menghapus pegawai:', error);
            alert('Gagal menghapus pegawai: ' + error.message);
        } finally {
            this.isDeleting = false;
            this.deletePegawaiId = null;
            this.deletePegawaiName = '';
        }
    },

    restorePegawai(id, name) {
        this.restorePegawaiId = id;
        this.restorePegawaiName = name || '';
        this.showRestoreModal = true;
    },

    async confirmRestorePegawai() {
        if (!this.restorePegawaiId) return;
        this.isRestoring = true;
        try {
            // Endpoint restore memakai gate permission employees.restore di backend,
            // sehingga tombol ini hanya mempercepat akses dan bukan penentu otorisasi.
            const res = await fetch(`/api/v1/pegawai/${this.restorePegawaiId}/restore`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}'
                }
            });
            if (!res.ok) {
                const data = await res.json();
                throw new Error(data.message || `HTTP ${res.status}`);
            }
            // Pegawai berpindah dari daftar nonaktif ke daftar aktif, sehingga cache kedua mode harus dibuang.
            this.clearEmployeeLifecycleCache();

            this.showRestoreModal = false;
            await this.refreshAfterListMembershipChange();
        } catch (error) {
            console.error('Error mengaktifkan kembali pegawai:', error);
            alert('Gagal mengaktifkan kembali pegawai: ' + error.message);
        } finally {
            this.isRestoring = false;
            this.restorePegawaiId = null;
            this.restorePegawaiName = '';
        }
    },

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
            kgb:     `/api/v1/pegawai/${this.riwayatEmployeeId}/riwayat-kgb`
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
                
                // Ambil data terbaru dari backend dan update cache secara instan
                this.patchEditedEmployee(this.riwayatEmployeeId);
                
                setTimeout(() => { this.showRiwayatModal = false; this.successMessage = ''; }, 1200);
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

    async patchEditedEmployee(id) {
        try {
            const res = await fetch(`/api/v1/pegawai/${id}/table-row`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            
            this.applyEditedDataToCache(id, data.employee);
        } catch (e) {
            console.error('Gagal mem-patch pegawai yang diedit:', e);
            this.clearCache();
            this.fetchPage(1);
        }
    },

    applyEditedDataToCache(id, data) {
        // Perbarui cache sessionStorage di semua halaman yang mungkin mengandung pegawai ini
        for (let i = 0; i < sessionStorage.length; i++) {
            const key = sessionStorage.key(i);
            if (key && key.startsWith('pegawai_')) {
                try {
                    const cached = JSON.parse(sessionStorage.getItem(key));
                    const idx = cached.rows.findIndex(r => r.id === id);
                    if (idx !== -1) {
                        cached.rows[idx] = Object.assign({}, cached.rows[idx], data);
                        sessionStorage.setItem(key, JSON.stringify(cached));
                    }
                } catch(e) {}
            }
        }
        
        // Setelah cache di-patch, coba muat ulang data dari cache untuk halaman saat ini
        const cKey = this.cacheKey + `_p${this.meta.current_page}`;
        const cached = sessionStorage.getItem(cKey);
        if (cached) {
            const parsed = JSON.parse(cached);
            this.pegawaiRows = parsed.rows;
            this.meta = parsed.meta;
            this.isLoading = false;
        } else {
            this.fetchPage(this.meta.current_page);
        }
    },

    @endif

    init() {
        @if (! $isReadOnly)
        if (this.dataChanged) {
            if (this.editedEmployeeId && this.editedEmployeeData) {
                // Perbarui cache secara sinkron tanpa loading delay untuk pengalaman instant save
                this.applyEditedDataToCache(this.editedEmployeeId, this.editedEmployeeData);
            } else if (this.editedEmployeeId) {
                this.isLoading = true;
                this.patchEditedEmployee(this.editedEmployeeId);
            } else {
                this.clearCache();
                this.isLoading = true;
                this.fetchPage(1);
            }
            return;
        }
        @endif
        
        // ── Cek sessionStorage terlebih dahulu ──
        // Jika data ada → tampilkan langsung tanpa loading, tanpa skeleton
        // Jika tidak ada → set isLoading=true (tampilkan skeleton), lalu fetch
        const cKey = this.cacheKey + `_p${this.meta.current_page}`;
        const cached = sessionStorage.getItem(cKey);
        if (cached) {
            try {
                const data = JSON.parse(cached);
                this.pegawaiRows = data.rows;
                this.meta = data.meta;
                // isLoading sudah false dari awal — tidak perlu diubah
                return;
            } catch (e) {
                sessionStorage.removeItem(cKey);
            }
        }
        
        // Data tidak ada di cache → tampilkan skeleton lalu fetch
        this.isLoading = true;
        this.fetchPage(this.meta.current_page);
    },
}" class="space-y-6">

        @if ($isReadOnly && $serverRenderedDetailLinks !== [])
            <noscript>
                <nav aria-label="Daftar detail pegawai">
                    <ul>
                        @foreach ($serverRenderedDetailLinks as $detailLink)
                            <li>
                                <a href="{{ $detailLink['url'] }}" aria-label="Detail pegawai {{ $detailLink['name'] }}">
                                    {{ $detailLink['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </noscript>
        @endif

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
                <x-ui.breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Data Pegawai'],
    ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <button type="button" @click="clearCache(); fetchPage(meta.current_page);"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer"
                    title="Refresh Data">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Refresh
                </button>
                @if(!$isReadOnly)
                <button x-show="!filters.show_nonaktif" onclick="exportFilteredData()" id="export-btn"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer">
                    <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    Export Excel
                </button>
                <button x-show="!filters.show_nonaktif" onclick="exportFilteredDataPdf()" id="export-pdf-btn"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.617 0-1.11-.476-1.12-1.09l-.23-2.523M19.5 10.5v.375c0 .621-.504 1.125-1.125 1.125H5.625A1.125 1.125 0 0 1 4.5 11.25v-.375m15 0V9a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 9v1.5m15 0A1.5 1.5 0 0 0 18 9h-3V6a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3H6a1.5 1.5 0 0 0-1.5 1.5" />
                    </svg>
                    Export PDF
                </button>
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" @click.outside="open = false" id="add-pegawai-btn"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Pegawai
                    </button>
                    <div x-show="open" style="display: none;" x-transition
                        class="absolute right-0 top-full mt-1.5 w-full rounded-lg border border-border bg-surface p-1 shadow-lg z-20">
                        <a href="{{ route('pegawai.create') }}" wire:navigate
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-ink hover:bg-soft transition-colors font-sans">
                            <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                            </svg>
                            Tambah Manual
                        </a>
                        <a href="{{ route('pegawai.import') }}" wire:navigate
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-ink hover:bg-soft transition-colors font-sans mt-1">
                            <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                            Import Pegawai
                        </a>
                    </div>
                </div>
                @else
                <a href="{{ route('pimpinan.laporan.nominatif') }}"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer">
                    <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    Laporan Pegawai
                </a>
                @endif
            </div>
        </div>


        {{-- ============================================================ --}}
        {{-- DATA TABLE (x-ui.data-table) --}}
        {{-- ============================================================ --}}
        @php
            $tableColumns = [
                ['key' => 'nama_lengkap', 'label' => 'Pegawai', 'sortable' => true],
                ['key' => 'jabatan', 'label' => 'Jabatan & Unit', 'sortable' => true],
                ['key' => 'golongan_terakhir', 'label' => 'Gol. / Jenis', 'sortable' => true],
                ['key' => 'tmt', 'label' => 'TMT'],
                ['key' => 'status_nama', 'label' => 'Status'],
                ['key' => 'is_lengkap', 'label' => 'Dokumen'],
                ['key' => 'aksi', 'label' => 'Aksi'],
            ];
            if (! ($isReadOnly ?? false)) {
                array_unshift($tableColumns, ['key' => 'check', 'label' => '', 'width' => 'w-10']);
            }
        @endphp
        <x-ui.data-table rows="pegawaiRows" meta="meta" :columns="$tableColumns" fetchPage="fetchPage(page)"
            isLoading="isLoading" perPage="perPage" setPerPage="setPerPage($event.target.value)" sort="sort"
            direction="direction" setSort="setSort(col)" searchModel="filters.search"
            searchPlaceholder="Cari nama atau NIP" emptyTitle="Tidak ada data pegawai yang sesuai."
            emptyIcon="search" :colspanCount="count($tableColumns)" :checkAllId="!($isReadOnly ?? false) ? 'check-all' : null" checkAllShow="!filters.show_nonaktif" filterClass="lg:grid-cols-6">
            {{-- ---- Filter Slots ---- --}}
            <x-slot:filters>
                @if (! $isReadOnly)
                    <label class="flex min-h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 text-xs font-semibold text-ink cursor-pointer">
                        <input type="checkbox" x-model="filters.show_nonaktif" @change="applyFilter()"
                            aria-label="Tampilkan Pegawai Non-Aktif"
                            class="h-4 w-4 rounded border-border text-primary focus:ring-primary">
                        Tampilkan Pegawai Non-Aktif
                    </label>
                    @if (auth()->user()->hasPermission('employees.restore'))
                        {{-- Tautan hanya muncul pada mode nonaktif agar halaman kelola nonaktif tidak
                             perlu ditemukan lewat URL manual. --}}
                        <a x-show="filters.show_nonaktif" href="{{ route('data-nonaktif') }}" wire:navigate
                            class="flex min-h-10 items-center justify-center gap-1.5 rounded-lg border border-primary/15 bg-surface px-3 text-xs font-semibold text-primary transition hover:bg-soft">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                            </svg>
                            Kelola Pegawai Non-Aktif
                        </a>
                    @endif
                @endif
                {{-- Filter Golongan --}}
                <div>
                    <x-form.select x-model="filters.golongan" @change="applyFilter()" size="md" aria-label="Filter golongan">
                        <option value="">Semua Golongan</option>
                        @foreach($golonganOptions as $golongan)
                            <option value="{{ $golongan }}">Golongan {{ $golongan }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                {{-- Filter Unit Kerja --}}
                <div>
                    <x-form.select x-model="filters.unit_kerja_id" @change="applyFilter()" size="md" aria-label="Filter unit kerja">
                        <option value="">Semua Unit</option>
                        @foreach($unitKerjaOptions as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                {{-- Filter Jenis Pegawai --}}
                <div>
                    <x-form.select x-model="filters.jenis_pegawai_id" @change="applyFilter()" size="md" aria-label="Filter jenis pegawai">
                        <option value="">Semua Jenis</option>
                        @foreach($jenisPegawaiOptions as $jenis)
                            <option value="{{ $jenis->id }}">{{ $jenis->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                {{-- Filter Status --}}
                <div>
                    <x-form.select x-model="filters.status_pegawai_id" @change="applyFilter()" size="md" aria-label="Filter status pegawai">
                        <option value="all">Semua Status</option>
                        @foreach($statusOptions as $status)
                            <option value="{{ $status->id }}">{{ $status->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>
            </x-slot:filters>

            {{-- ---- Custom Header: override kolom sortable dengan tombol sort Alpine ---- --}}
            {{-- NOTE: komponen x-ui.data-table sudah render header dari $columns,
            tapi sort pegawai menggunakan setSort() custom bukan mekanisme generik.
            Header sudah terhubung via prop sort/direction/setSort di komponen. --}}

            {{-- ---- Custom Body Rows ---- --}}
            <template x-if="!isLoading && pegawaiRows.length > 0">
                <template x-for="(p, index) in pegawaiRows" :key="p.id">
                    <x-ui.table-row class="border-b border-border last:border-0" x-bind:data-id="p.id"
                        x-bind:data-nip="p.nip">

                        {{-- Checkbox --}}
                        @if (! ($isReadOnly ?? false))
                            <td class="px-4 py-3">
                                <x-form.checkbox x-show="!filters.show_nonaktif"
                                    x-bind:disabled="filters.show_nonaktif" size="sm" class="row-check" />
                            </td>
                        @endif

                        {{-- Pegawai --}}
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <x-ui.tooltip dynamicText="'Buka detail ' + p.nama_lengkap" position="right">
                                    @if ($isReadOnly)
                                        <a :href="filters.show_nonaktif ? null : detailUrl(p)" @click="if (filters.show_nonaktif) $event.preventDefault()" wire:navigate
                                    @else
                                        <a :href="filters.show_nonaktif ? null : `/pegawai/${p.id}`" @click="if (filters.show_nonaktif) $event.preventDefault()" wire:navigate
                                    @endif
                                        class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20">
                                        <img x-show="p.foto_url" :src="p.foto_url" :alt="'Foto ' + p.nama_lengkap"
                                            class="h-full w-full object-cover object-[center_25%]" loading="lazy"
                                            x-on:error="$el.classList.add('hidden'); $el.nextElementSibling.classList.remove('hidden')">
                                        <span x-show="!p.foto_url" x-text="p.nama_lengkap.charAt(0).toUpperCase()"
                                            aria-hidden="true"></span>
                                    </a>
                                </x-ui.tooltip>
                                <div class="min-w-0">
                                    <x-ui.tooltip dynamicText="'Buka detail ' + p.nama_lengkap" position="right">
                                        @if ($isReadOnly)
                                            <a :href="filters.show_nonaktif ? null : detailUrl(p)" @click="if (filters.show_nonaktif) $event.preventDefault()"
                                        @else
                                            <a :href="filters.show_nonaktif ? null : `/pegawai/${p.id}`" @click="if (filters.show_nonaktif) $event.preventDefault()"
                                        @endif
                                            class="block truncate text-sm font-semibold text-ink transition hover:text-primary"
                                            x-text="p.nama_lengkap"></a>
                                    </x-ui.tooltip>
                                    <p class="text-xs text-muted" x-text="'NIP. ' + p.nip"></p>
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
                            <span class="text-sm font-medium text-ink"
                                x-text="p.golongan_terakhir + ' / ' + p.jenis_pegawai"></span>
                        </td>

                        {{-- TMT --}}
                        <td class="px-4 py-3">
                            <p class="text-sm text-ink" x-text="p.tmt ?? '-'"></p>
                        </td>

                        {{-- Status --}}
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1.5 font-medium font-sans leading-none px-2.5 py-1 text-xs rounded-md"
                                :class="{
                                'bg-success/10 text-success': p.status_key === 'aktif',
                                'bg-warning/10 text-warning': p.status_key === 'cuti' || p.status_key === 'mutasi',
                                'bg-danger/10 text-danger':   p.status_key === 'nonaktif' || p.status_key === 'pensiun',
                                'bg-muted/10 text-muted':     !['aktif','cuti','mutasi','nonaktif','pensiun'].includes(p.status_key),
                            }">
                                <span class="h-1.5 w-1.5 rounded-full" :class="{
                                    'bg-success': p.status_key === 'aktif',
                                    'bg-warning': p.status_key === 'cuti' || p.status_key === 'mutasi',
                                    'bg-danger':  p.status_key === 'nonaktif' || p.status_key === 'pensiun',
                                    'bg-muted':   !['aktif','cuti','mutasi','nonaktif','pensiun'].includes(p.status_key),
                                }"></span>
                                <span x-text="p.status_nama"></span>
                            </span>
                        </td>

                        {{-- Dokumen --}}
                        <td class="px-4 py-3">
                            @if ($isReadOnly)
                            <span x-show="!filters.show_nonaktif"
                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                                :class="{
                                'bg-success/10 text-success': p.is_lengkap === 'lengkap',
                                'bg-warning/10 text-warning': p.is_lengkap === 'tidak_lengkap',
                                'bg-primary/10 text-primary': p.is_lengkap === 'tersedia',
                                'bg-muted/20 text-muted': p.is_lengkap === 'kosong',
                            }" title="Status kelengkapan dokumen">
                                <span class="h-1.5 w-1.5 rounded-full" :class="{
                                    'bg-success': p.is_lengkap === 'lengkap',
                                    'bg-warning': p.is_lengkap === 'tidak_lengkap',
                                    'bg-primary': p.is_lengkap === 'tersedia',
                                    'bg-muted': p.is_lengkap === 'kosong',
                                }"></span>
                                <span x-text="
                                    p.is_lengkap === 'lengkap'       ? 'Lengkap' :
                                    p.is_lengkap === 'tidak_lengkap' ? 'Tidak Lengkap' :
                                    p.is_lengkap === 'tersedia'      ? 'Tersedia' :
                                                                       'Belum Ada'
                                "></span>
                            </span>
                            @else
                            <button x-show="!filters.show_nonaktif" type="button" @click="openDocumentStatus(p)"
                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap transition hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                :class="{
                                'bg-success/10 text-success hover:bg-success/15': p.is_lengkap === 'lengkap',
                                'bg-warning/10 text-warning hover:bg-warning/15': p.is_lengkap === 'tidak_lengkap',
                                'bg-primary/10 text-primary hover:bg-primary/15': p.is_lengkap === 'tersedia',
                                'bg-muted/20 text-muted hover:bg-muted/30':       p.is_lengkap === 'kosong',
                            }" title="Klik untuk melihat rincian status dokumen">
                                <span class="h-1.5 w-1.5 rounded-full" :class="{
                                    'bg-success': p.is_lengkap === 'lengkap',
                                    'bg-warning': p.is_lengkap === 'tidak_lengkap',
                                    'bg-primary': p.is_lengkap === 'tersedia',
                                    'bg-muted':   p.is_lengkap === 'kosong',
                                }"></span>
                                <span x-text="
                                    p.is_lengkap === 'lengkap'       ? 'Lengkap' :
                                    p.is_lengkap === 'tidak_lengkap' ? 'Tidak Lengkap' :
                                    p.is_lengkap === 'tersedia'      ? 'Tersedia' :
                                                                       'Belum Ada'
                                "></span>
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                                </svg>
                            </button>
                            @endif
                        </td>

                        {{-- Aksi --}}
                        <td class="px-4 py-3">
                            <div x-show="!filters.show_nonaktif" class="flex items-center justify-start gap-1.5">
                                {{-- Detail --}}
                                <x-ui.tooltip text="Detail" position="top">
                                    @if ($isReadOnly)
                                        <a :href="detailUrl(p)" :aria-label="'Detail pegawai ' + p.nama_lengkap" wire:navigate
                                    @else
                                        <a :href="`/pegawai/${p.id}`" wire:navigate
                                    @endif
                                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </x-ui.tooltip>
                                @if (!$isReadOnly)
                                {{-- Edit --}}
                                <x-ui.tooltip text="Edit" position="top">
                                    <a x-show="!filters.show_nonaktif" :href="`/pegawai/${p.id}/edit`" wire:navigate
                                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                        </svg>
                                    </a>
                                </x-ui.tooltip>
                                @endif

                                @if ($canChangeStatus)
                                    {{-- Ubah status tersedia bagi pengelola pegawai yang memiliki
                                         permission employees.update; backend menegakkan batas
                                         yang sama melalui middleware route dan FormRequest. --}}
                                    <x-ui.tooltip text="Ubah Status" position="top">
                                        <button type="button" data-testid="change-status-trigger" @click="openStatusModal(p)"
                                            :aria-label="'Ubah status pegawai ' + p.nama_lengkap"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm-3.75 6c0-1.035.84-1.875 1.875-1.875h.008c1.035 0 1.875.84 1.875 1.875v.375H6.75v-.375Z" />
                                            </svg>
                                        </button>
                                    </x-ui.tooltip>
                                @endif

                                @if (! $isReadOnly && auth()->user()->hasPermission('employees.deactivate'))
                                    {{-- Nonaktifkan → masuk Backup (berdasarkan permission) --}}
                                    <x-ui.tooltip text="Nonaktifkan Pegawai" position="top-end">
                                        <button x-show="!filters.show_nonaktif" type="button" @click="deletePegawai(p.id, p.nama_lengkap)"
                                            :aria-label="'Nonaktifkan pegawai ' + p.nama_lengkap"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-danger/30 bg-surface text-danger transition hover:bg-danger/10 shadow-sm">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    </x-ui.tooltip>
                                @endif
                            </div>

                            {{-- Mode nonaktif hanya menampilkan pemulihan; aksi khusus pegawai aktif
                                 seperti detail, edit, dan nonaktifkan tidak berlaku untuk record terhapus. --}}
                            @if (! $isReadOnly && auth()->user()->hasPermission('employees.restore'))
                                <div x-show="filters.show_nonaktif" class="flex items-center justify-start gap-1.5">
                                    <x-ui.tooltip text="Aktifkan Kembali" position="top-end">
                                        <button type="button" @click="restorePegawai(p.id, p.nama_lengkap)"
                                            :aria-label="'Aktifkan kembali pegawai ' + p.nama_lengkap"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-success/40 bg-surface text-success transition hover:bg-success/10 shadow-sm">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                            </svg>
                                        </button>
                                    </x-ui.tooltip>
                                </div>
                            @endif
                        </td>
                    </x-ui.table-row>
                </template>
            </template>

        </x-ui.data-table>


        @if (! ($isReadOnly ?? false))
        {{-- ============================================================ --}}
        {{-- BULK ACTION FLOATING BAR --}}
        {{-- ============================================================ --}}
        {{-- Aksi massal hanya berlaku untuk pegawai aktif; dataset nonaktif hanya mendukung pemulihan
             satu per satu sehingga selector dan bar aksi massal ditutup pada mode tersebut. --}}
        <div id="bulk-bar" x-show="!filters.show_nonaktif"
            class="fixed bottom-6 left-1/2 z-40 hidden -translate-x-1/2 items-center gap-3 rounded-lg border border-border bg-surface px-6 py-3.5 shadow-lg">
            <p class="text-sm font-semibold text-ink"><span id="selected-count">0</span> pegawai dipilih</p>
            <div class="h-4 w-px bg-border"></div>
            <button onclick="exportSelectedData()"
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-warning hover:underline transition-colors cursor-pointer">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Pilihan
            </button>
            <button @click="
            const count = document.querySelectorAll('.row-check:not([disabled]):checked').length;
            if (count === 0) { window.alert('Tidak ada data pegawai yang dipilih.'); return; }
            document.getElementById('modal-title-bulk-delete').innerText = 'Nonaktifkan ' + count + ' Pegawai';
            $dispatch('open-confirm-bulk-delete');
        " class="inline-flex items-center gap-1.5 text-xs font-semibold text-danger hover:underline transition-colors cursor-pointer">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                </svg>
                Nonaktifkan Pegawai
            </button>
            <button
                onclick="document.querySelectorAll(\'.row-check\').forEach(c => c.checked = false); updateBulkBar();"
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted hover:underline transition-colors cursor-pointer">
                Batal Pilih
            </button>
        </div>
        @endif

        @if (! $isReadOnly)
        {{-- ============================================================ --}}
        {{-- MODAL RINCIAN STATUS DOKUMEN --}}
        {{-- ============================================================ --}}
        <x-ui.modal show="showDocumentStatusModal" title="Rincian Dokumen Pegawai"
            closeAction="showDocumentStatusModal = false" maxWidth="2xl" bodyClass="p-5">
            <div class="space-y-4">
                <div class="flex items-center justify-between gap-3 rounded-lg border border-border bg-soft/40 p-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-ink"
                            x-text="documentStatusEmployee?.nama_lengkap ?? 'Pegawai'"></p>
                        <p class="text-xs text-muted" x-text="'NIP. ' + (documentStatusEmployee?.nip ?? '-')"></p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold"
                        :class="{
                        'bg-success/10 text-success': documentStatus.status_kelengkapan === 'lengkap',
                        'bg-warning/10 text-warning': documentStatus.status_kelengkapan === 'tidak_lengkap',
                        'bg-primary/10 text-primary': documentStatus.status_kelengkapan === 'tersedia',
                        'bg-muted/20 text-muted':     documentStatus.status_kelengkapan === 'kosong' || !documentStatus.status_kelengkapan,
                    }">
                        <span class="h-1.5 w-1.5 rounded-full" :class="{
                            'bg-success': documentStatus.status_kelengkapan === 'lengkap',
                            'bg-warning': documentStatus.status_kelengkapan === 'tidak_lengkap',
                            'bg-primary': documentStatus.status_kelengkapan === 'tersedia',
                            'bg-muted':   documentStatus.status_kelengkapan === 'kosong' || !documentStatus.status_kelengkapan,
                        }"></span>
                        <span x-text="
                            documentStatus.status_kelengkapan === 'lengkap'       ? 'Lengkap' :
                            documentStatus.status_kelengkapan === 'tidak_lengkap' ? 'Tidak Lengkap' :
                            documentStatus.status_kelengkapan === 'tersedia'      ? 'Tersedia' :
                                                                                    'Belum Ada'
                        "></span>
                    </span>
                </div>

                <template x-if="isLoadingDocumentStatus">
                    <div class="flex items-center justify-center gap-2 py-10 text-sm text-muted">
                        <svg class="h-5 w-5 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v8z"></path>
                        </svg>
                        Memeriksa file SK di storage...
                    </div>
                </template>

                <template x-if="!isLoadingDocumentStatus && documentStatusError">
                    <div class="rounded-lg border border-danger/20 bg-danger/10 p-3 text-sm text-danger"
                        x-text="documentStatusError"></div>
                </template>

                <template
                    x-if="!isLoadingDocumentStatus && !documentStatusError && documentStatus.total_riwayat === 0 && documentStatus.total_dokumen === 0">
                    <div class="rounded-lg border border-border bg-soft/40 p-5 text-center">
                        <p class="text-sm font-semibold text-ink">Belum ada dokumen pegawai</p>
                        <p class="mt-1 text-xs text-muted">Arsip dokumen dan riwayat yang memiliki file akan tampil di
                            sini.</p>
                    </div>
                </template>

                <template x-if="!isLoadingDocumentStatus && !documentStatusError && documentStatus.total_dokumen > 0">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-ink">Daftar Dokumen</p>
                                <p class="text-xs text-muted"
                                    x-text="`${documentStatus.dokumen_tersedia} dari ${documentStatus.total_dokumen} file tersedia di storage.`">
                                </p>
                            </div>
                        </div>
                        <div class="max-h-[55vh] space-y-2 overflow-y-auto pr-1">
                            <template x-for="(document, index) in documentStatus.documents"
                                :key="document.id ?? `${document.kategori}-${document.file_path ?? index}`">
                                <div class="rounded-lg border p-3"
                                    :class="document.file_tersedia ? 'border-border bg-surface' : 'border-warning/30 bg-warning/5'">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <span class="text-[10px] font-bold uppercase tracking-wide text-muted"
                                                x-text="document.kategori"></span>
                                            <p class="truncate text-sm font-semibold text-ink" x-text="document.nama">
                                            </p>
                                            <p class="mt-0.5 truncate text-xs text-muted" x-text="document.keterangan">
                                            </p>
                                        </div>
                                        <span
                                            class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-[10px] font-semibold"
                                            :class="document.file_tersedia ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'">
                                            <span class="h-1.5 w-1.5 rounded-full"
                                                :class="document.file_tersedia ? 'bg-success' : 'bg-warning'"></span>
                                            <span x-text="document.status_label"></span>
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                                        <span x-text="'No. Dokumen: ' + document.nomor"></span>
                                        <span x-text="'Tanggal: ' + document.tanggal"></span>
                                        <a x-show="document.file_tersedia" :href="document.file_url" target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                            Buka file
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M13.5 6H19.5m0 0v6m0-6L10.5 15m-3 3h-3a1.5 1.5 0 0 1-1.5-1.5v-12A1.5 1.5 0 0 1 4.5 3h12A1.5 1.5 0 0 1 18 4.5v3" />
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>


        {{-- ============================================================ --}}
        {{-- MODAL NONAKTIFKAN PEGAWAI → BACKUP (berdasarkan permission) --}}
        {{-- ============================================================ --}}
        <x-ui.modal show="showDeleteModal" title="Nonaktifkan Pegawai" closeAction="showDeleteModal = false"
            maxWidth="sm" dusk="deactivate-employee-modal">
            <div class="space-y-4">
                {{-- Info backup --}}
                <div class="flex items-start gap-3 rounded-lg bg-warning/10 border border-warning/20 p-3">
                    <svg class="w-5 h-5 mt-0.5 shrink-0 text-warning" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-ink font-sans">
                            Konfirmasi Nonaktifkan Pegawai
                        </p>
                        <p class="text-xs text-muted font-sans mt-1">
                            Pegawai <strong x-text="deletePegawaiName" class="text-ink"></strong> akan dinonaktifkan
                            dan dipindahkan dari daftar pegawai aktif ke <strong>Data Backup</strong>. Seluruh data,
                            riwayat, dan dokumen tetap disimpan dan dapat dipulihkan kembali oleh pengguna yang
                            memiliki <strong>permission pemulihan</strong>. Data tidak dihapus permanen secara otomatis.
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2 border-t border-border">
                    <button type="button" @click="showDeleteModal = false" :disabled="isDeleting"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans disabled:opacity-50">
                        Batal
                    </button>
                    <button type="button" @click="confirmDeletePegawai()" :disabled="isDeleting"
                        class="inline-flex items-center justify-center rounded-lg bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                        <svg x-show="isDeleting" class="mr-2 h-4 w-4 animate-spin text-white"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <svg x-show="!isDeleting" class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                        <span x-text="isDeleting ? 'Memproses...' : 'Ya, Nonaktifkan Pegawai'"></span>
                    </button>
                </div>
            </div>
        </x-ui.modal>

        {{-- ============================================================ --}}
        {{-- MODAL AKTIFKAN KEMBALI PEGAWAI --}}
        {{-- ============================================================ --}}
        <x-ui.modal show="showRestoreModal" title="Aktifkan Kembali Pegawai"
            closeAction="showRestoreModal = false" maxWidth="sm" dusk="restore-employee-modal">
            <div class="space-y-4">
                <div class="flex items-start gap-3 rounded-lg border border-success/20 bg-success/10 p-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-success" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-ink font-sans">Konfirmasi Aktifkan Kembali Pegawai</p>
                        <p class="mt-1 text-xs text-muted font-sans">
                            Apakah Anda yakin ingin mengaktifkan kembali pegawai
                            <strong x-text="restorePegawaiName" class="text-ink"></strong>?
                            Data akan kembali muncul di daftar pegawai aktif.
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-border pt-2">
                    <button type="button" @click="showRestoreModal = false" :disabled="isRestoring"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans disabled:opacity-50">
                        Batal
                    </button>
                    <button type="button" @click="confirmRestorePegawai()" :disabled="isRestoring"
                        class="inline-flex items-center justify-center rounded-lg bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                        <svg x-show="isRestoring" class="mr-2 h-4 w-4 animate-spin text-white"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <svg x-show="!isRestoring" class="mr-1.5 h-4 w-4 shrink-0" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        <span x-text="isRestoring ? 'Memproses...' : 'Ya, Aktifkan Kembali'"></span>
                    </button>
                </div>
            </div>
        </x-ui.modal>

        {{-- ============================================================ --}}
        {{-- MODAL BULK NONAKTIFKAN PEGAWAI → BACKUP (berdasarkan permission) --}}
        {{-- ============================================================ --}}
        <div x-data="{ open: false, isBulkDeleting: false }" @open-confirm-bulk-delete.window="open = true">
            <x-ui.modal show="open" title="" closeAction="open = false" maxWidth="sm"
                dusk="bulk-deactivate-employee-modal">
                <div class="space-y-4">
                    <p id="modal-title-bulk-delete" class="text-sm font-bold text-ink font-sans">Nonaktifkan Pegawai
                    </p>
                    <div class="flex items-start gap-3 rounded-lg bg-warning/10 border border-warning/20 p-3">
                        <svg class="w-5 h-5 mt-0.5 shrink-0 text-warning" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                        </svg>
                        <p class="text-xs text-muted font-sans">
                            Pegawai terpilih akan dinonaktifkan dan dipindahkan dari daftar pegawai aktif ke
                            <strong class="text-ink">Data Backup</strong>. Seluruh data, riwayat, dan dokumen tetap
                            disimpan dan dapat dipulihkan kembali oleh pengguna yang memiliki
                            <strong class="text-ink">permission pemulihan</strong>. Data tidak dihapus permanen secara
                            otomatis.
                        </p>
                    </div>

                    <form id="bulk-destroy-form" method="POST" action="{{ route('pegawai.bulkDestroy') }}">
                        @csrf
                    </form>

                    <div class="flex justify-end gap-3 pt-2 border-t border-border">
                        <button type="button" @click="open = false" :disabled="isBulkDeleting"
                            class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans disabled:opacity-50">
                            Batal
                        </button>
                        <button type="button" :disabled="isBulkDeleting" @click="
                            isBulkDeleting = true;
                            const form = document.getElementById('bulk-destroy-form');
                            form.querySelectorAll('input[name=\'ids[]\']').forEach(el => el.remove());
                            document.querySelectorAll('.row-check:not([disabled]):checked').forEach(cb => {
                                const inp = document.createElement('input');
                                inp.type = 'hidden'; inp.name = 'ids[]';
                                inp.value = cb.closest('tr')?.dataset.id ?? cb.value;
                                form.appendChild(inp);
                            });
                            form.submit();
                        "
                            class="inline-flex items-center justify-center rounded-lg bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                            <svg x-show="isBulkDeleting" class="mr-2 h-4 w-4 animate-spin text-white" fill="none"
                                viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <svg x-show="!isBulkDeleting" class="w-4 h-4 mr-1.5 shrink-0" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                            </svg>
                            <span x-text="isBulkDeleting ? 'Memproses...' : 'Ya, Nonaktifkan Pegawai'"></span>
                        </button>
                    </div>
                </div>
            </x-ui.modal>
        </div>

        {{-- ============================================================ --}}
        {{-- MODAL TAMBAH RIWAYAT (Pangkat / Jabatan / KGB) --}}
        {{-- ============================================================ --}}
        <x-ui.modal show="showRiwayatModal" closeAction="showRiwayatModal = false" maxWidth="lg"
            bodyClass="p-5 space-y-4">
            <x-slot:header>
                <div class="flex items-center justify-between w-full">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans" x-text="modalTitle"></h3>
                        <p class="text-xs text-muted font-sans mt-0.5" x-text="'Pegawai: ' + riwayatEmployeeName"></p>
                    </div>
                    <button type="button" @click="showRiwayatModal = false"
                        class="rounded-lg p-1.5 text-muted transition-colors hover:bg-soft hover:text-ink focus:outline-none"
                        aria-label="Tutup">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </x-slot:header>

            {{-- Success message --}}
            <template x-if="successMessage">
                <div class="rounded-lg bg-success/10 p-3 text-xs text-success font-bold font-sans"
                    x-text="successMessage"></div>
            </template>

            {{-- General error --}}
            <template x-if="errors._general">
                <div class="rounded-lg bg-danger/10 p-3 text-xs text-danger font-bold font-sans"
                    x-text="errors._general[0]"></div>
            </template>

            {{-- ===== FORM KEPANGKATAN ===== --}}
            <template x-if="riwayatType === 'pangkat'">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Golongan <span
                                    class="text-danger">*</span></label>
                            <select x-model="newPangkat.golongan_id"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="">Pilih Golongan</option>
                                @foreach($golonganRefOptions ?? [] as $ref)
                                    <option value="{{ $ref->id }}">{{ $ref->nama }}</option>
                                @endforeach
                            </select>
                            <template x-if="errors.golongan_id">
                                <p class="text-xs text-danger font-sans" x-text="errors.golongan_id[0]"></p>
                            </template>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">No. SK <span
                                    class="text-danger">*</span></label>
                            <input type="text" x-model="newPangkat.no_sk" placeholder="SK-..."
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <template x-if="errors.no_sk">
                                <p class="text-xs text-danger font-sans" x-text="errors.no_sk[0]"></p>
                            </template>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal SK</label>
                            <input type="date" x-model="newPangkat.tanggal_sk"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">TMT Pangkat</label>
                            <input type="date" x-model="newPangkat.tmt_pangkat"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">File SK</label>
                        <input type="file" x-ref="fileSkInput" accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none font-sans">
                    </div>
                </div>
            </template>

            {{-- ===== FORM JABATAN ===== --}}
            <template x-if="riwayatType === 'jabatan'">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Jabatan <span
                                    class="text-danger">*</span></label>
                            <select x-model="newJabatan.jabatan_id"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="">Pilih Jabatan</option>
                                {{-- Hanya jabatan aktif ditawarkan karena validasi riwayat jabatan
                                     menolak jabatan nonaktif; menampilkannya berarti menyuguhkan
                                     pilihan yang pasti gagal disimpan. --}}
                                @foreach(collect($jabatanOptions ?? [])->where('is_active', true) as $ref)
                                    <option value="{{ data_get($ref, 'id') }}">{{ data_get($ref, 'nama') }}</option>
                                @endforeach
                            </select>
                            <template x-if="errors.jabatan_id">
                                <p class="text-xs text-danger font-sans" x-text="errors.jabatan_id[0]"></p>
                            </template>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Jenis Jabatan</label>
                            <select x-model="newJabatan.jenis_jabatan_id"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="">Pilih Jenis</option>
                                @foreach($jenisJabatanOptions ?? [] as $ref)
                                    <option value="{{ data_get($ref, 'id') }}">{{ data_get($ref, 'nama') }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Unit Kerja</label>
                            <select x-model="newJabatan.unit_kerja_id"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="">Pilih Unit</option>
                                @foreach($unitKerjaOptions ?? [] as $unit)
                                    <option value="{{ data_get($unit, 'id') }}">{{ data_get($unit, 'nama') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Kelas Jabatan</label>
                            <input type="text" x-model="newJabatan.kelas_jabatan" placeholder="cth: 9"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">No. SK</label>
                            <input type="text" x-model="newJabatan.no_sk" placeholder="SK-..."
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal SK</label>
                            <input type="date" x-model="newJabatan.tanggal_sk"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">TMT Jabatan</label>
                        <input type="date" x-model="newJabatan.tmt_jabatan"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">File SK</label>
                        <input type="file" x-ref="fileSkInput" accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none font-sans">
                    </div>
                </div>
            </template>

            {{-- ===== FORM KGB ===== --}}
            <template x-if="riwayatType === 'kgb'">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Gaji Pokok <span
                                    class="text-danger">*</span></label>
                            <input type="number" x-model="newKgb.gaji_pokok" placeholder="0"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <template x-if="errors.gaji_pokok">
                                <p class="text-xs text-danger font-sans" x-text="errors.gaji_pokok[0]"></p>
                            </template>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">No. SK</label>
                            <input type="text" x-model="newKgb.no_sk" placeholder="SK-..."
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Tanggal SK</label>
                            <input type="date" x-model="newKgb.tanggal_sk"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">TMT KGB</label>
                            <input type="date" x-model="newKgb.tmt_kgb"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">File SK</label>
                        <input type="file" x-ref="fileSkInput" accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none font-sans">
                    </div>
                </div>
            </template>

            {{-- Footer tombol --}}
            <div class="flex justify-end gap-3 pt-3 border-t border-border">
                <button type="button" @click="showRiwayatModal = false"
                    class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer font-sans">
                    Batal
                </button>
                <button type="button" @click="submitRiwayat()" :disabled="isSubmitting"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-60 cursor-pointer font-sans">
                    <template x-if="isSubmitting">
                        <svg class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                    </template>
                    <span x-text="isSubmitting ? 'Menyimpan...' : 'Simpan Riwayat'"></span>
                </button>
            </div>
        </x-ui.modal>
        @endif

    @if ($canChangeStatus)
        <x-ui.modal
            show="showStatusModal"
            title="Ubah Status Pegawai"
            titleId="change-status-modal-title"
            descriptionId="change-status-modal-description"
            maxWidth="2xl"
            closeAction="closeStatusModal()"
        >
            <p id="change-status-modal-description" class="mb-5 text-sm text-muted">
                Perubahan dicatat sebagai riwayat, dapat disertai berkas SK, dan akan memberi notifikasi kepada pegawai.
            </p>

            <form id="change-status-form" action="{{ route('pegawai.status.update') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                @csrf
                <input type="hidden" name="pegawai_id" :value="statusEmployee?.id ?? ''">

                <div class="rounded-lg border border-border bg-soft px-4 py-3">
                    <p class="text-xs font-semibold text-muted">Pegawai</p>
                    <p class="mt-1 text-sm font-semibold text-ink" x-text="statusEmployee ? statusEmployee.nama_lengkap : 'Pegawai tidak ditemukan'"></p>
                    <p class="mt-0.5 text-xs text-muted" x-text="statusEmployee?.nip ? 'NIP. ' + statusEmployee.nip : ''"></p>
                </div>
                @error('pegawai_id')
                    <p class="text-xs font-medium text-danger">{{ $message }}</p>
                @enderror

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="status_pegawai_id" class="mb-1.5 block text-sm font-semibold text-ink">Status Baru <span class="text-danger">*</span></label>
                        <select name="status_pegawai_id" id="status_pegawai_id" required aria-describedby="status_pegawai_id-error"
                            class="block w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 @error('status_pegawai_id') border-danger @enderror">
                            <option value="">-- Pilih Status --</option>
                            @foreach($statusChangeOptions as $status)
                                <option value="{{ $status->id }}" @selected(old('status_pegawai_id') === $status->id)>{{ $status->nama }}</option>
                            @endforeach
                        </select>
                        @error('status_pegawai_id')
                            <p id="status_pegawai_id-error" class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="tanggal" class="mb-1.5 block text-sm font-semibold text-ink">Tanggal Efektif Status Kepegawaian <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal') }}" required aria-describedby="tanggal-error"
                            class="block w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 @error('tanggal') border-danger @enderror">
                        @error('tanggal')
                            <p id="tanggal-error" class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label for="nomor_berkas" class="mb-1.5 block text-sm font-semibold text-ink">Nomor Berkas (Otomatis)</label>
                        <input type="text" id="nomor_berkas" value="SK-STATUS-{{ date('Ymd') }}-XXX" disabled
                            class="block w-full cursor-not-allowed rounded-lg border border-border bg-soft px-3 py-2 text-sm text-muted">
                        <p class="mt-1 text-xs text-muted">Nomor berkas dibuat otomatis hanya jika Anda melampirkan berkas pendukung.</p>
                    </div>
                </div>

                <div>
                    <label for="keterangan" class="mb-1.5 block text-sm font-semibold text-ink">Keterangan <span class="font-normal text-muted">(Opsional)</span></label>
                    <textarea name="keterangan" id="keterangan" rows="3" placeholder="Masukkan catatan atau keterangan perubahan status..." aria-describedby="keterangan-error"
                        class="block w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 @error('keterangan') border-danger @enderror">{{ old('keterangan') }}</textarea>
                    @error('keterangan')
                        <p id="keterangan-error" class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="berkas" class="mb-1.5 block text-sm font-semibold text-ink">Upload Berkas SK Pendukung <span class="font-normal text-muted">(Opsional)</span></label>
                    <input type="file" name="berkas" id="berkas" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="berkas-help berkas-error"
                        class="block w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-primary-hover @error('berkas') border-danger @enderror">
                    <p id="berkas-help" class="mt-1 text-xs text-muted">PDF, JPG, atau PNG; maksimal 10 MB. Berkas akan tersimpan pada arsip dokumen pegawai.</p>
                    @error('berkas')
                        <p id="berkas-error" class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
                    @enderror
                </div>
            </form>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-3">
                    <x-ui.button type="button" variant="muted" @click="closeStatusModal()">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                        <span>Batal</span>
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary" form="change-status-form">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        <span>Simpan Status</span>
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    </div>{{-- end x-data --}}

    @if (! $isReadOnly)
    <script>
        function updateBulkBar() {
            // Baris yang dinonaktifkan (mode daftar nonaktif) tidak boleh ikut dihitung sebagai
            // pilihan agar bar aksi massal tidak muncul untuk data yang tidak mendukungnya.
            const allBoxes = document.querySelectorAll('.row-check:not([disabled])');
            const checked = document.querySelectorAll('.row-check:not([disabled]):checked');
            const bar = document.getElementById('bulk-bar');
            const count = document.getElementById('selected-count');
            const checkAll = document.getElementById('check-all');

            if (!bar || !count || !checkAll) return;

            // Bulk bar visibility
            if (checked.length > 0) {
                bar.classList.remove('hidden');
                bar.classList.add('flex');
            } else {
                bar.classList.add('hidden');
                bar.classList.remove('flex');
            }

            if (count) count.innerText = checked.length;

            // Select-all checkbox state: checked / indeterminate / unchecked
            if (checkAll) {
                if (checked.length === 0) {
                    checkAll.checked = false;
                    checkAll.indeterminate = false;
                } else if (checked.length === allBoxes.length) {
                    checkAll.checked = true;
                    checkAll.indeterminate = false;
                } else {
                    checkAll.checked = false;
                    checkAll.indeterminate = true;
                }
            }
        }

        function exportFilteredData() {
            // Find the Alpine component data by passing a child element
            const btnEl = document.getElementById('export-btn');
            if (!btnEl) { alert('Export button not found'); return; }
            const alpineData = Alpine.$data(btnEl);
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = '{{ route("pegawai.export") }}';
            
            const params = {
                search: alpineData.filters.search,
                golongan: alpineData.filters.golongan,
                unit_kerja_id: alpineData.filters.unit_kerja_id,
                jenis_pegawai_id: alpineData.filters.jenis_pegawai_id,
                status_pegawai_id: alpineData.filters.status_pegawai_id === 'all' ? '' : alpineData.filters.status_pegawai_id
            };

            for (const key in params) {
                if (params[key]) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = params[key];
                    form.appendChild(input);
                }
            }
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        const PDF_MAX_ROWS = @js(\App\Actions\Laporan\ExportPegawaiPdfAction::MAX_ROWS);

        function exportFilteredDataPdf() {
            const btnEl = document.getElementById('export-pdf-btn');
            if (!btnEl) { return; }
            const alpineData = Alpine.$data(btnEl);

            // Pesan awal untuk pengguna; backend tetap menolak permintaan di atas batas.
            if (alpineData.meta.total > PDF_MAX_ROWS) {
                alert(`Laporan memuat ${alpineData.meta.total} baris, melebihi batas ${PDF_MAX_ROWS}. Persempit filter lalu coba lagi.`);
                return;
            }

            const params = {
                search: alpineData.filters.search,
                golongan: alpineData.filters.golongan,
                unit_kerja_id: alpineData.filters.unit_kerja_id,
                jenis_pegawai_id: alpineData.filters.jenis_pegawai_id,
                status_pegawai_id: alpineData.filters.status_pegawai_id === 'all' ? '' : alpineData.filters.status_pegawai_id,
            };

            const query = new URLSearchParams();
            Object.entries(params).forEach(([key, value]) => {
                // status_pegawai_id selalu dikirim walau kosong. Nilai kosong berarti
                // "semua status", sedangkan parameter yang hilang membuat backend
                // kembali ke default Aktif sehingga pilihan pengguna terabaikan.
                if (key === 'status_pegawai_id' || (value !== '' && value !== null && value !== undefined)) {
                    query.set(key, String(value));
                }
            });

            window.location.assign(`{{ route('laporan.pegawai.pdf') }}?${query.toString()}`);
        }

        function exportSelectedData() {
            const checked = document.querySelectorAll('.row-check:not([disabled]):checked');
            const ids = Array.from(checked).map(c => c.closest('tr')?.dataset.id).filter(Boolean);
            if (!ids.length) { alert('Tidak ada data yang dipilih.'); return; }
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = '{{ route("pegawai.export") }}';
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        document.addEventListener('change', function (e) {
            if (e.target && (e.target.classList.contains('row-check') || e.target.id === 'check-all')) {
                if (e.target.id === 'check-all') {
                    // check-all diklik → set semua row sesuai state-nya (indeterminate → check all)
                    const shouldCheck = e.target.indeterminate ? true : e.target.checked;
                    e.target.indeterminate = false;
                    e.target.checked = shouldCheck;
                    document.querySelectorAll('.row-check:not([disabled])').forEach(c => c.checked = shouldCheck);
                }
                updateBulkBar();
            }
        });

        // Reset check-all & bulk bar setiap kali data rows berubah (pindah halaman / filter)
        document.addEventListener('alpine:init', () => {
            document.addEventListener('pegawai-rows-changed', () => {
                document.querySelectorAll('.row-check').forEach(c => c.checked = false);
                updateBulkBar();
            });
        });
    </script>
    @endif

</div>
