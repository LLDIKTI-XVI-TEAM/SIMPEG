<x-layouts.app title="Detail Pegawai">
<div>

    @php
        $canManageDocuments = \App\Support\Documents\DocumentAuthorization::canManage(auth()->user());

        $canUpdateEmployee = auth()->check()
            && auth()->user()->hasPermission('employees.update');
        $canCreateFamily = auth()->check()
            && auth()->user()->hasPermission('employee_families.create');
        $canDeleteFamily = auth()->check()
            && auth()->user()->hasPermission('employee_families.delete');
        $canCreateDiscipline = auth()->check()
            && auth()->user()->hasPermission('discipline_records.create');
        $canDeleteDiscipline = auth()->check()
            && auth()->user()->hasPermission('discipline_records.delete');

        $canAssignSupervisor = $canUpdateEmployee
            && in_array(auth()->user()->role, ['super_admin', 'admin_kepegawaian'], true);
        $canDeactivateEmployee = auth()->check()
            && auth()->user()->hasPermission('employees.deactivate')
            && $p->isActive();

        $canCreateEmployeeHistory = auth()->check()
            && auth()->user()->hasPermission('employee_histories.create');
        $canUpdateEmployeeHistory = auth()->check()
            && (auth()->user()->hasPermission('employee_histories.update') || auth()->user()->hasPermission('employee_histories.create') || auth()->user()->getEffectiveRole() === 'super_admin');
        $canDeleteEmployeeHistory = auth()->check() && auth()->user()->hasPermission('employee_histories.delete');
        $hasEducationHistoryMutation = $canCreateEmployeeHistory || $canUpdateEmployeeHistory || $canDeleteEmployeeHistory;

        // Tabs granular: hanya tampil jika permission read tersedia (sesuai dashboard)
        $canReadFamiliesForTabs = $canReadFamilies ?? (auth()->check() && auth()->user()->hasPermission('employee_families.read'));
        $canReadHistoriesForTabs = $canReadHistories ?? (auth()->check() && auth()->user()->hasPermission('employee_histories.read'));
        $canReadDisciplineForTabs = $canReadDiscipline ?? (auth()->check() && auth()->user()->hasPermission('discipline_records.read'));
        $canReadDocumentsForTabs = $canReadDocuments ?? (auth()->check() && auth()->user()->hasPermission('dokumen_sk.read'));

        $detailTabs = [
            'profile' => 'Profil',
            ...($canReadFamiliesForTabs ? ['keluarga' => 'Keluarga'] : []),
            ...($canReadHistoriesForTabs ? ['kepangkatan' => 'Kepangkatan', 'jabatan' => 'Jabatan', 'kgb' => 'KGB', 'pendidikan' => 'Pendidikan', 'pengangkatan' => 'Pengangkatan'] : []),
            ...($canReadDisciplineForTabs ? ['disiplin' => 'Hukuman Disiplin'] : []),
            ...($canReadDocumentsForTabs ? ['docs' => 'Dokumen & SK'] : []),
        ];
        $requestedDetailTab = request()->query('tab');

        // Query lama atau tidak dikenal harus kembali ke tab pertama agar navigasi tetap dapat difokuskan.
        $initialDetailTab = is_string($requestedDetailTab) && array_key_exists($requestedDetailTab, $detailTabs)
            ? $requestedDetailTab
            : array_key_first($detailTabs);
    @endphp

    <div x-data="{
        activeTab: @js($initialDetailTab),
        tabs: {{ \Illuminate\Support\Js::from(array_keys($detailTabs)) }},
        selectTab(tab) {
            this.activeTab = tab;
            this.$nextTick(() => document.getElementById(`pimpinan-tab-${tab}`)?.focus());
        },
        moveTab(offset) {
            const current = this.tabs.indexOf(this.activeTab);
            this.selectTab(this.tabs[(current + offset + this.tabs.length) % this.tabs.length]);
        },
        kinerjaBaik: {{ $p->is_kinerja_baik ? 'true' : 'false' }},
        {{-- Surface read-only: endpoint mutasi pimpinan tidak ada; pemicu UI-nya
            juga disembunyikan oleh gate permission di bawah. String kosong agar
            render tidak 500 pada route() yang tidak terdaftar. --}}
        kinerjaEndpoint: @js(''),
        isUpdatingKinerja: false,
        satyalancanaEligible: {{ $p->is_satyalancana_eligible ? 'true' : 'false' }},
        satyalancanaNote: @js($p->satyalancana_note ?? ''),
        satyalancanaEndpoint: @js(''),
        isUpdatingSatyalancana: false,
        showDeactivateModal: false,
        supervisorLookupEndpoint: @js(''),
        supervisorQuery: @js($selectedSupervisorName ?? ''),
        supervisorSelectedId: @js($selectedSupervisorId ?? ''),
        supervisorSelectedName: @js($selectedSupervisorName ?? ''),
        supervisorResults: [],
        supervisorActiveIndex: -1,
        supervisorOpen: false,
        supervisorLoading: false,
        supervisorError: '',
        supervisorSelectionError: '',
        supervisorClearConfirmed: false,
        supervisorSearchTimer: null,
        supervisorLookupRequestId: 0,
        showModal: false,
        modalTitle: '',
        modalType: '',
        modalError: '',
        isSubmitting: false,
        toast: { show: false, message: '', type: 'success' },
        arsipDokumen: [],
        loadingArsip: false,
        disiplinFileMode: 'arsip',

        {{-- NIK sengaja tidak disertakan: surface read-only Pimpinan tidak boleh membawa identitas sensitif. --}}
        keluargaList: {{ ($p->families ?? collect())->map(fn($f) => ['id' => $f->id, 'nama_anggota' => $f->nama_anggota, 'hubungan' => $f->hubungan, 'tempat_lahir' => $f->tempat_lahir, 'tanggal_lahir' => $f->tanggal_lahir, 'jenis_kelamin' => $f->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki-laki', 'pekerjaan' => $f->pekerjaan, 'status' => $f->status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung'])->toJson() }},
        keluargaLoading: false,
        isDeletingKeluarga: false,
        pendidikanSummary: @js([
            'pendidikan_terakhir' => $p->pendidikan_terakhir,
            'program_studi' => $p->programStudi?->nama ?? $p->prodi_pendidikan_terakhir,
        ]),
        pangkatList: {{ $p->rankHistories->map(fn($r) => ['id' => $r->id, 'golongan' => $r->golongan->nama ?? '-', 'no_sk' => $r->no_sk, 'tgl_sk' => $r->tanggal_sk?->format('Y-m-d'), 'tmt' => $r->tmt_pangkat?->format('Y-m-d'), 'file_sk' => $r->file_sk, 'download_url' => $r->pimpinan_attachment_download_url])->toJson() }},
        jabatanList: {{ $p->positionHistories->map(fn($j) => ['id' => $j->id, 'jabatan' => $j->jabatan?->nama ?? $j->nama_jabatan, 'unit' => $j->unitKerja->nama ?? '-', 'kelas_jabatan' => $j->kelas_jabatan, 'no_sk' => $j->no_sk, 'tgl_sk' => $j->tanggal_sk?->format('Y-m-d'), 'tmt' => $j->tmt_jabatan?->format('Y-m-d'), 'file_sk' => $j->file_sk, 'download_url' => $j->pimpinan_attachment_download_url])->toJson() }},
        kgbList: {{ $p->salaryHistories->map(fn($s) => ['id' => $s->id, 'gaji' => 'Rp ' . number_format($s->gaji_pokok, 0, ',', '.'), 'no_sk' => $s->no_sk, 'tgl_sk' => $s->tanggal_sk?->format('Y-m-d'), 'tmt' => $s->tmt_kgb?->format('Y-m-d'), 'file_sk' => $s->file_sk, 'download_url' => $s->pimpinan_attachment_download_url])->toJson() }},
        disiplinList: {{ $p->disciplineRecords->map(fn($d) => ['id' => $d->id, 'jenis' => $d->jenis_hukuman, 'alasan' => $d->deskripsi, 'no_sk' => $d->no_sk, 'tgl_sk' => $d->tanggal_sk?->format('Y-m-d'), 'tgl_mulai' => $d->tanggal_mulai?->format('Y-m-d'), 'tgl_akhir' => $d->tanggal_berakhir?->format('Y-m-d'), 'is_active' => $d->is_active, 'download_url' => $d->pimpinan_attachment_download_url])->toJson() }},
        pendidikanList: {{ ($p->educationHistories ?? collect())->map(fn($e) => ['id' => $e->id, 'jenjang_id' => $e->jenjang_id, 'program_studi_id' => $e->program_studi_id, 'tingkat' => $e->jenjang?->urutan ?? $e->tingkat ?? '-', 'institusi' => $e->nama_institusi ?? '-', 'prodi' => $e->programStudi?->nama ?? $e->jurusan ?? '-', 'lulus' => $e->tahun_lulus ?? '-', 'no_ijazah' => $e->no_ijazah ?? '-', 'download_url' => $e->pimpinan_attachment_download_url])->toJson() }},
        pendidikanLoading: false,
        showEditPendidikan: false,
        editingPendidikan: null,
        editPendidikanError: '',
        editPendidikanForm: { jenjang_id: '', nama_institusi: '', program_studi_id: '', tahun_lulus: '', no_ijazah: '' },
        _initialProgramStudiId: null,
        isUpdatingPendidikan: false,
        isDeletingPendidikan: false,
        isDeletingDisiplin: false,
        
        // Form states
        newKeluarga: { nama_anggota: '', hubungan: 'Istri', nik: '', tempat_lahir: '', tanggal_lahir: '', jenis_kelamin: 'P', status_tunjangan: '0', pekerjaan: '' },
        newPangkat: { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '', file_sk: null },
        newJabatan: { jabatan_id: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', kelas_jabatan: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '', file_sk: null },
        newKgb: { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '', file_sk: null },
        newDisiplin: { jenis_hukuman: 'Ringan', deskripsi: '', no_sk: '', tanggal_sk: '', tanggal_mulai: '', tanggal_berakhir: '', file_sk: null, dokumen_id: '' },
        newPendidikan: { jenjang_id: '', nama_institusi: '', program_studi_id: '', tahun_lulus: '', no_ijazah: '' },

        // Upload berkas lainnya (KTP/KK, Ijazah, Lainnya) langsung dari tab Dokumen.
        showUploadBerkas: false,
        isUploadingBerkas: false,
        uploadBerkasError: '',
        uploadBerkasErrors: {},
        newBerkas: { nama_dokumen: '', kategori_dokumen: 'ktp_kk', nomor_dokumen: '', tanggal_terbit: '', keterangan: '', file: null },
        showEditBerkas: false,
        isUpdatingBerkas: false,
        editBerkasError: '',
        editBerkasErrors: {},
        editingBerkas: null,
        editBerkas: { nama_dokumen: '', kategori_dokumen: 'ktp_kk', nomor_dokumen: '', tanggal_terbit: '', keterangan: '', file: null },
        showDeleteBerkas: false,
        isDeletingBerkas: false,
        deleteBerkasError: '',
        deletingBerkas: null,
        berkasList: {{ \Illuminate\Support\Js::from($otherDocumentRows) }},

        // Data & SK Pengangkatan Pertama
        appointmentData: @js($p->appointment ? [
            'id' => $p->appointment->id,
            'jenis_pengangkatan' => $p->appointment->jenis_pengangkatan,
            'no_sk' => $p->appointment->no_sk,
            'tanggal_sk' => $p->appointment->tanggal_sk?->format('Y-m-d'),
            'tmt_pengangkatan' => $p->appointment->tmt_pengangkatan?->format('Y-m-d'),
            'file_sk' => $p->appointment->file_sk,
            'download_url' => $p->appointment->pimpinan_attachment_download_url,
        ] : null),
        showAppointmentModal: false,
        isEditingAppointment: false,
        isSavingAppointment: false,
        appointmentError: '',
        appointmentForm: {
            jenis_pengangkatan: 'CPNS',
            no_sk: '',
            tanggal_sk: '',
            tmt_pengangkatan: '',
            file_sk: null,
        },

        openAppointmentModal() {
            this.appointmentError = '';
            if (this.appointmentData) {
                this.isEditingAppointment = true;
                this.appointmentForm = {
                    jenis_pengangkatan: this.appointmentData.jenis_pengangkatan || 'CPNS',
                    no_sk: this.appointmentData.no_sk || '',
                    tanggal_sk: this.appointmentData.tanggal_sk || '',
                    tmt_pengangkatan: this.appointmentData.tmt_pengangkatan || '',
                    file_sk: null,
                };
            } else {
                this.isEditingAppointment = false;
                this.appointmentForm = {
                    jenis_pengangkatan: 'CPNS',
                    no_sk: '',
                    tanggal_sk: '',
                    tmt_pengangkatan: '',
                    file_sk: null,
                };
            }
            this.showAppointmentModal = true;
            this.$nextTick(() => {
                const el = document.getElementById('appointment_file_sk_input');
                if (el) el.value = '';
            });
        },

        async submitAppointment() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },

        // Upload / Ganti Berkas SK Riwayat (Kepangkatan, Jabatan, KGB, Pengangkatan)
        showUploadSkModal: false,
        uploadSkType: 'pangkat',
        uploadSkRecord: null,
        uploadSkFile: null,
        uploadSkError: '',
        isUploadingSk: false,

        openUploadSkModal(type, record) {
            this.uploadSkType = type;
            this.uploadSkRecord = record;
            this.uploadSkFile = null;
            this.uploadSkError = '';
            this.showUploadSkModal = true;
            this.$nextTick(() => {
                const el = document.getElementById('upload_file_sk_input');
                if (el) el.value = '';
            });
        },

        async submitUploadSk() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },

        clearDocumentArchiveCache() {
            try {
                const toDelete = [];
                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    if (key && key.startsWith('dokumen_')) toDelete.push(key);
                }
                toDelete.forEach(k => sessionStorage.removeItem(k));
                const now = String(Date.now());
                sessionStorage.setItem('simpeg_dokumen_last_mutation', now);
                localStorage.setItem('simpeg_dokumen_last_mutation', now);
            } catch (e) {}
        },

        async submitUploadBerkas() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        openEditBerkas(doc) {
            if (!doc.can_mutate) return;
            this.editingBerkas = doc;
            this.editBerkas = {
                nama_dokumen: doc.nama_dokumen ?? '',
                kategori_dokumen: doc.jenis_dokumen ?? 'lainnya',
                nomor_dokumen: doc.nomor_dokumen ?? '',
                tanggal_terbit: doc.tanggal_input ?? '',
                keterangan: doc.keterangan ?? '',
                file: null,
            };
            this.editBerkasError = '';
            this.editBerkasErrors = {};
            this.showEditBerkas = true;
        },
        async submitUpdateBerkas() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        openDeleteBerkas(doc) {
            if (!doc.can_mutate) return;
            this.deletingBerkas = doc;
            this.deleteBerkasError = '';
            this.showDeleteBerkas = true;
        },
        async submitDeleteBerkas() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        async updateKinerjaBaik(value) {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        async updateSatyalancanaEligibility() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        searchSupervisor() {
            window.clearTimeout(this.supervisorSearchTimer);
            this.supervisorLookupRequestId++;
            this.supervisorError = '';
            this.supervisorSelectionError = '';

            if (this.supervisorQuery !== this.supervisorSelectedName) {
                this.supervisorSelectedId = '';
            }

            if (this.supervisorQuery.trim().length < 2) {
                this.supervisorResults = [];
                this.supervisorOpen = false;
                this.supervisorLoading = false;

                return;
            }

            this.supervisorSearchTimer = window.setTimeout(() => this.fetchSupervisorCandidates(), 250);
        },
        async fetchSupervisorCandidates() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        selectSupervisor(candidate) {
            this.supervisorLookupRequestId++;
            this.supervisorSelectionError = '';
            this.supervisorSelectedId = candidate.id;
            this.supervisorSelectedName = candidate.nama_lengkap;
            this.supervisorQuery = candidate.nama_lengkap;
            this.supervisorResults = [];
            this.supervisorActiveIndex = -1;
            this.supervisorOpen = false;
        },
        clearSupervisorAndSubmit() {
            if (!window.confirm('Hapus penugasan Kepala Bagian ini? Perubahan akan disimpan sesuai tanggal efektif.')) {
                return;
            }

            const form = document.getElementById('assign-kepala-bagian-form');
            if (!form) return;

            if (!form.checkValidity()) {
                form.reportValidity();

                return;
            }

            window.clearTimeout(this.supervisorSearchTimer);
            this.supervisorLookupRequestId++;
            this.supervisorSelectedId = '';
            this.supervisorSelectedName = '';
            this.supervisorQuery = '';
            this.supervisorResults = [];
            this.supervisorActiveIndex = -1;
            this.supervisorOpen = false;
            this.supervisorError = '';
            this.supervisorLoading = false;
            this.supervisorClearConfirmed = true;
            this.$nextTick(() => form.requestSubmit());
        },
        validateSupervisorSelection(event) {
            if (this.supervisorSelectedId || this.supervisorClearConfirmed) {
                this.supervisorClearConfirmed = false;

                return;
            }

            event.preventDefault();
            this.supervisorSelectionError = 'Pilih kandidat Kepala Bagian dari hasil pencarian sebelum menyimpan.';
            this.$nextTick(() => document.getElementById('kepala_bagian_lookup')?.focus());
        },
        moveSupervisorActiveIndex(direction) {
            if (!this.supervisorOpen && this.supervisorQuery.trim().length >= 2) {
                this.fetchSupervisorCandidates();

                return;
            }

            if (this.supervisorResults.length === 0) {
                return;
            }

            this.supervisorActiveIndex = (this.supervisorActiveIndex + direction + this.supervisorResults.length) % this.supervisorResults.length;
        },
        chooseActiveSupervisor() {
            if (this.supervisorActiveIndex >= 0) {
                this.selectSupervisor(this.supervisorResults[this.supervisorActiveIndex]);
            }
        },
        closeSupervisorLookup() {
            window.setTimeout(() => this.supervisorOpen = false, 150);
        },
        
        openModal(type, title) {
            this.modalType = type;
            this.modalTitle = title;
            this.modalError = '';
            this.showModal = true;
            if (type === 'disiplin') {
                this.disiplinFileMode = 'arsip';
                this.fetchArsipDisiplin();
            }
        },
        async fetchArsipDisiplin() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },
        // ===== LAZY FETCH & CACHING KELUARGA + PENDIDIKAN =====
        _keluargaCacheKey:  'keluarga_{{ $p->id }}',
        _pendidikanCacheKey: 'pendidikan_v2_{{ $p->id }}',
        _pendidikanCacheVersion: @js($pendidikanCacheVersion ?? ''),
        _pendidikanCacheTTL: 5*60*1000,

        formatDate(dateString) {
            if (!dateString || dateString === '-') return '-';
            const datePart = String(dateString).split('T')[0];
            const parts = datePart.split('-');
            if (parts.length === 3 && parts[0].length === 4) {
                return `${parts[2]}-${parts[1]}-${parts[0]}`;
            }
            return dateString;
        },

        init() {
            // Deteksi reload (F5/Ctrl+R): buang semua cache tab agar data selalu segar.
            const navType = performance.getEntriesByType?.('navigation')?.[0]?.type;
            if (navType === 'reload') {
                sessionStorage.removeItem(this._keluargaCacheKey);
                sessionStorage.removeItem(this._pendidikanCacheKey);
            }

            // Pantau perpindahan tab — fetch otomatis saat tab dibuka pertama kali.
            this.$watch('activeTab', (tab) => {
                if (tab === 'keluarga' && this.keluargaList.length === 0 && !this.keluargaLoading) {
                    this.fetchKeluarga();
                }
                if (tab === 'pendidikan' && this.pendidikanList.length === 0 && !this.pendidikanLoading) {
                    this.fetchPendidikan();
                }
            });

            // Langsung fetch jika tab sudah aktif saat init (misal dari URL ?tab=keluarga).
            if (this.activeTab === 'keluarga')  this.fetchKeluarga();
            if (this.activeTab === 'pendidikan') this.fetchPendidikan();
        },

        // Read-only: daftar keluarga dirender server; tanpa refresh/hapus via API mentah.
        async fetchKeluarga() {
            this.keluargaLoading = false;
            return;
        },

        // Read-only: penghapusan keluarga tidak tersedia di surface Pimpinan.
        async deleteKeluarga(id, index) {
            return;
        },

        // Read-only: daftar pendidikan dirender server; tanpa refresh via API mentah.
        async fetchPendidikan() {
            this.pendidikanLoading = false;
            return;
        },

        applyEducationSummary(summary) {
            if (!summary) return;

            this.pendidikanSummary = {
                pendidikan_terakhir: summary.pendidikan_terakhir ?? null,
                program_studi: summary.program_studi ?? null,
            };
        },

        openEditPendidikan(edu) {
            this.editingPendidikan = edu;
            this.editPendidikanError = '';
            this._initialProgramStudiId = edu.program_studi_id ?? null;
            this.editPendidikanForm = {
                jenjang_id:     edu.jenjang_id ?? '',
                nama_institusi: edu.institusi ?? '',
                program_studi_id: edu.program_studi_id ?? '',
                tahun_lulus:    edu.lulus ?? '',
                no_ijazah:      edu.no_ijazah ?? '',
            };
            this.showEditPendidikan = true;
        },

        async submitEditPendidikan() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },

        async deletePendidikan(id, index) {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },

        async deleteDisiplin(id, index) {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        },

        async submitForm() {
            // Read-only: tidak tersedia di surface Pimpinan.
            return;
        }
    }" class="mx-auto max-w-5xl space-y-6">
        
        <x-pegawai.detail.page-header
            :dashboard-url="route('pimpinan.dashboard')"
            :employees-url="route('pimpinan.pegawai.index')"
        >
                @if($canUpdateEmployee)
                <x-ui.button href="{{ route('rbac.pegawai.edit', $p->id) }}" wire:navigate aria-label="Edit Pegawai">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                    </svg>
                    Edit Pegawai
                </x-ui.button>
                @endif
                @if($canDeactivateEmployee)
                <x-ui.button type="button" variant="danger-solid" @click="showDeactivateModal = true">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9 14.394 18m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                    Nonaktifkan
                </x-ui.button>
                @endif
        </x-pegawai.detail.page-header>

        @if (session('success'))
            <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger" class="mb-4">{{ session('error') }}</x-ui.alert>
        @endif

        {{-- DYNAMIC ALERT (MENGGANTIKAN TOAST) --}}
        <div x-show="toast.show" style="display: none;" class="mb-4" x-transition>
            <template x-if="toast.type === 'success'">
                <x-ui.alert variant="success">
                    <span x-text="toast.message"></span>
                </x-ui.alert>
            </template>
            <template x-if="toast.type === 'error'">
                <x-ui.alert variant="danger">
                    <span x-text="toast.message"></span>
                </x-ui.alert>
            </template>
        </div>

        {{-- MAIN DETAIL CARD --}}
        <x-pegawai.detail.shell>
            @php
                $fotoUrl = $p->foto_url;
            @endphp

            {{-- Header info --}}
            <x-pegawai.detail.identity-header
                :employee="$p"
                :photo-url="$fotoUrl"
                :primary-badge-label="$p->jenisPegawai->nama ?? '-'"
            >
                <x-slot:badges>
                    <template x-if="kinerjaBaik">
                        <x-ui.badge variant="success" size="md" class="!font-bold">
                            Kinerja Baik
                        </x-ui.badge>
                    </template>
                    @if($p->is_kepala_lembaga)
                        <x-ui.badge variant="primary" size="md" class="!font-bold">
                            Kepala Lembaga
                        </x-ui.badge>
                    @endif
                </x-slot:badges>
            </x-pegawai.detail.identity-header>

            {{-- TAB NAVIGATION --}}
            <x-pegawai.detail.tabs :tabs="$detailTabs" id-prefix="pimpinan" />

            <p class="history-export-unavailable hidden">Ekspor riwayat tidak tersedia</p>

            {{-- TAB 1: PROFIL LENGKAP --}}
            <x-pegawai.detail.panel tab="profile" id-prefix="pimpinan">
                <x-pegawai.detail.profile
                    :employee="$p"
                    :status-presentation="$statusPresentation"
                    :active-position="$latestPosition"
                    :latest-rank="$latestRank"
                    :latest-status-history="$latestStatusHistory"
                    :active-supervisor-assignments="collect([$currentSupervisor])->filter()"
                    :retirement-date="$estimasiTanggalPensiun"
                    :can-read-histories="$canReadHistories"
                    download-surface="pimpinan"
                >
                    <x-slot:controls>
                {{-- Toggle Flag Kinerja & Kepala Bagian --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-soft/40 rounded-lg p-4 border border-border">
                    {{-- Status Kinerja --}}
                    <div class="p-2">
                        <div class="flex items-start sm:items-center justify-between gap-4">
                            <div>
                            <span class="text-xs font-bold text-ink font-sans block">Toggle Flag "Kinerja Baik"</span>
                            <p class="text-xs text-muted">Flag ini menggantikan penilaian SKP yang belum tersedia di Fase 1. Akan digantikan oleh modul Penilaian Kinerja di fase selanjutnya.</p>
                            <p x-show="isUpdatingKinerja" class="mt-1 text-[10px] text-primary font-sans" style="display: none;">
                                Menyimpan status kinerja.
                            </p>
                        </div>
                        <label class="relative inline-flex items-center select-none opacity-80 cursor-not-allowed">
                            <input type="checkbox" x-model="kinerjaBaik" disabled aria-label="Toggle Kinerja Baik" class="sr-only peer">
                            <div class="w-11 h-6 bg-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-success"></div>
                        </label>
                        </div>
                    </div>

                    {{-- Satyalancana --}}
                    <div class="space-y-3 p-2 border-l border-border/80 pl-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <span class="text-xs font-bold text-ink font-sans block">Kelayakan Satyalancana</span>
                                <p class="text-xs text-muted">Flag dan catatan manual untuk EWS Satyalancana 10/20/30 tahun.</p>
                                <p x-show="isUpdatingSatyalancana" class="mt-1 text-[10px] text-primary font-sans" style="display: none;">
                                    Menyimpan kelayakan Satyalancana.
                                </p>
                            </div>
                            <label class="relative inline-flex items-center select-none opacity-80 cursor-not-allowed">
                                <input type="checkbox" x-model="satyalancanaEligible" disabled aria-label="Toggle Kelayakan Satyalancana" class="sr-only peer">
                                <div class="w-11 h-6 bg-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-success"></div>
                            </label>
                        </div>
                        <div class="space-y-1">
                            <label for="satyalancana-note" class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Catatan Manual</label>
                            <textarea id="satyalancana-note" x-model="satyalancanaNote" rows="2" readonly class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder-muted shadow-sm focus:outline-none focus:ring-0 opacity-70 resize-none"></textarea>
                        </div>
                    </div>

                    {{-- Kepala Bagian --}}
                    <div class="space-y-4 md:col-span-2 border-t border-border/80 pt-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary text-sm font-bold shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-[9px] font-bold text-muted uppercase tracking-wider font-sans block">Kepala Bagian/Supervisor Aktif</span>
                                <p class="text-xs font-bold text-ink font-sans">{{ $currentSupervisor?->supervisor?->nama_lengkap ?? '-' }}</p>
                                <p class="text-xs text-muted">NIP. {{ $currentSupervisor?->supervisor?->nip ?? '-' }} ({{ $currentSupervisorPosition?->nama_jabatan ?? '-' }})</p>
                                <p class="mt-1 text-xs text-muted">
                                    <span class="font-semibold text-ink">Mulai Penugasan:</span>
                                    {{ $currentSupervisor?->tanggal_mulai?->format('d-m-Y') ?? '-' }}
                                </p>
                            </div>
                    </div>
                    @if ($canAssignSupervisor)
                        <form id="assign-kepala-bagian-form" action="{{ route('rbac.pegawai.assign-atasan', $p->id) }}" method="POST" @submit="validateSupervisorSelection($event)" class="border-t border-border pt-4">
                            @csrf
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_13rem_auto] md:items-start">
                                <div class="space-y-1">
                                    <label for="kepala_bagian_lookup" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                        Ubah Kepala Bagian
                                    </label>
                                    <div class="relative">
                                        <input type="hidden" name="kepala_bagian_id" :value="supervisorSelectedId">
                                        <input
                                            id="kepala_bagian_lookup"
                                            x-model="supervisorQuery"
                                            @input="searchSupervisor()"
                                            @focus="supervisorQuery.trim().length >= 2 && (supervisorOpen = true)"
                                            @blur="closeSupervisorLookup()"
                                            @keydown.arrow-down.prevent="moveSupervisorActiveIndex(1)"
                                            @keydown.arrow-up.prevent="moveSupervisorActiveIndex(-1)"
                                            @keydown.enter.prevent="chooseActiveSupervisor()"
                                            @keydown.escape.prevent="supervisorOpen = false"
                                            type="search"
                                            autocomplete="off"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            :aria-expanded="supervisorOpen.toString()"
                                            aria-controls="kepala_bagian_lookup_results"
                                            :aria-activedescendant="supervisorActiveIndex >= 0 ? `kepala_bagian_option_${supervisorActiveIndex}` : null"
                                            aria-describedby="kepala_bagian_lookup_help kepala_bagian_lookup_selection_error {{ $errors->has('kepala_bagian_id') ? 'kepala_bagian_lookup_error' : '' }}"
                                            :aria-invalid="{{ $errors->has('kepala_bagian_id') ? 'true' : 'false' }}"
                                            placeholder="Ketik minimal 2 karakter nama atau NIP"
                                            class="w-full rounded-xl border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-all duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 {{ $errors->has('kepala_bagian_id') ? 'border-danger focus:border-danger focus:ring-danger/20' : 'border-border' }}"
                                        >
                                        <div
                                            id="kepala_bagian_lookup_results"
                                            x-cloak
                                            x-show="supervisorOpen"
                                            role="listbox"
                                            aria-label="Hasil pencarian Kepala Bagian"
                                            class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-border bg-surface p-1 shadow-md"
                                        >
                                            <div x-show="supervisorLoading" class="flex items-center gap-2 px-3 py-2 text-xs text-muted">
                                                <x-ui.loading size="sm" color="primary" />
                                                Memuat kandidat.
                                            </div>
                                            <p x-show="!supervisorLoading && supervisorError" x-text="supervisorError" class="px-3 py-2 text-xs text-danger"></p>
                                            <p x-show="!supervisorLoading && !supervisorError && supervisorResults.length === 0" class="px-3 py-2 text-xs text-muted">
                                                Tidak ada kandidat yang cocok.
                                            </p>
                                            <template x-for="(candidate, index) in supervisorResults" :key="candidate.id">
                                                <button
                                                    type="button"
                                                    :id="`kepala_bagian_option_${index}`"
                                                    role="option"
                                                    :aria-selected="supervisorActiveIndex === index"
                                                    @mousedown.prevent="selectSupervisor(candidate)"
                                                    @mouseenter="supervisorActiveIndex = index"
                                                    :class="supervisorActiveIndex === index ? 'bg-soft text-ink' : 'text-ink'"
                                                    class="flex w-full flex-col rounded-lg px-3 py-2 text-left transition-colors focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                >
                                                    <span x-text="candidate.nama_lengkap" class="text-sm font-semibold"></span>
                                                    <span x-text="`NIP. ${candidate.nip}`" class="text-xs text-muted"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                    <p id="kepala_bagian_lookup_help" class="text-xs text-muted font-sans">Cari nama atau NIP, minimal 2 karakter. Penugasan lampau dan masa depan berlaku sesuai tanggal efektif.</p>
                                    <p id="kepala_bagian_lookup_selection_error" x-show="supervisorSelectionError" x-cloak x-text="supervisorSelectionError" class="text-xs font-semibold text-danger font-sans" role="alert"></p>
                                    @error('kepala_bagian_id')
                                        <p id="kepala_bagian_lookup_error" class="text-[11px] font-semibold text-danger font-sans">{{ $message }}</p>
                                    @enderror
                                    <p x-show="supervisorSelectedName" class="text-xs text-muted">Dipilih: <span x-text="supervisorSelectedName" class="font-semibold text-ink"></span></p>
                                </div>
                                <x-form.input
                                    name="effective_date"
                                    type="date"
                                    label="Tanggal Mulai Penugasan Kepala Bagian"
                                    :value="old('effective_date', now()->toDateString())"
                                    required
                                    help="Tanggal mulai berlakunya penugasan Kepala Bagian untuk pegawai ini."
                                />
                                <div class="flex flex-wrap gap-2 md:pt-6">
                                    <x-ui.button type="submit" size="sm">Simpan</x-ui.button>
                                    <x-ui.button type="button" variant="danger" size="sm" aria-label="Hapus penugasan Kepala Bagian dan simpan" @click="clearSupervisorAndSubmit()">Hapus Kepala Bagian lalu simpan</x-ui.button>
                                </div>
                            </div>
                        </form>
                    @endif
                    </div>
                </div>

                    </x-slot:controls>
                </x-pegawai.detail.profile>
            </x-pegawai.detail.panel>

            {{-- TAB 2: DATA KELUARGA --}}
            @if($canReadFamilies)
            <x-pegawai.detail.panel tab="keluarga" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Data Keluarga"
                    description="Daftar istri/suami dan anak yang tercatat sebagai tanggungan."
                >
                </x-pegawai.detail.section-header>
                {{-- Loading skeleton --}}
                <div x-show="keluargaLoading" role="status" aria-live="polite" class="flex items-center justify-center gap-2 py-10 text-xs text-muted font-sans">
                    <svg class="w-4 h-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Memuat data keluarga...
                </div>

                <x-pegawai.detail.table
                    name="keluarga"
                    :headings="['Nama Lengkap & NIK', 'Hubungan', 'TTL', 'Pekerjaan', 'Status']"
                    :show-actions="false"
                    x-show="!keluargaLoading"
                >
                            {{-- Surface read-only: baris dirender server dengan NIK
                                tersamarkan (partial mode server). Pimpinan tidak
                                memakai refresh API mentah lintas pegawai. --}}
                            @foreach(($p->families ?? collect()) as $family)
                                <tr class="transition-colors hover:bg-soft/30 text-ink" data-family-readonly-row>
                                    @include('pegawai.partials.detail.family-readonly-cells')
                                </tr>
                            @endforeach
                            @if(($p->families ?? collect())->isEmpty())
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini belum memiliki data anggota keluarga.
                                </td>
                            </tr>
                            @endif
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 3: RIWAYAT KEPANGKATAN --}}
            @if($canReadHistories)
            <x-pegawai.detail.panel tab="kepangkatan" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Riwayat Kepangkatan & Golongan"
                    description="Catatan kenaikan pangkat reguler maupun pilihan selama masa dinas."
                >
                </x-pegawai.detail.section-header>
                <x-pegawai.detail.table
                    name="kepangkatan"
                    :headings="['Golongan', 'Nomor SK Pangkat', 'Tanggal SK', 'TMT Pangkat', 'Berkas']"
                >
                            <template x-for="p in pangkatList" :key="p.id || p.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="p.golongan"></td>
                                    <td class="px-4 py-3" x-text="p.no_sk"></td>
                                    <td class="px-4 py-3" x-text="formatDate(p.tgl_sk)"></td>
                                    <td class="px-4 py-3" x-text="formatDate(p.tmt)"></td>
                                    <td class="px-4 py-3">
                                        <template x-if="p.download_url">
                                            <a :href="p.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                        </template>
                                        <template x-if="!p.download_url">
                                            <span class="text-muted">-</span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="pangkatList.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center font-semibold text-muted">
                                    Pegawai ini belum memiliki riwayat kepangkatan.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 4: RIWAYAT JABATAN --}}
            @if($canReadHistoriesForTabs)
            <x-pegawai.detail.panel tab="jabatan" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Riwayat Jabatan & Struktural"
                    description="Catatan penugasan jabatan fungsional maupun struktural."
                >
                </x-pegawai.detail.section-header>
                <x-pegawai.detail.table
                    name="jabatan"
                    :headings="['Nama Jabatan', 'Unit Kerja', 'Nomor SK Jabatan', 'Tanggal SK', 'TMT Jabatan', 'Berkas']"
                >
                            <template x-for="j in jabatanList" :key="j.id || j.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="j.jabatan"></td>
                                    <td class="px-4 py-3" x-text="j.unit"></td>
                                    <td class="px-4 py-3" x-text="j.no_sk"></td>
                                    <td class="px-4 py-3" x-text="formatDate(j.tgl_sk)"></td>
                                    <td class="px-4 py-3" x-text="formatDate(j.tmt)"></td>
                                    <td class="px-4 py-3">
                                        <template x-if="j.download_url">
                                            <a :href="j.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                        </template>
                                        <template x-if="!j.download_url">
                                            <span class="text-muted">-</span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="jabatanList.length === 0">
                                <td colspan="6" class="px-4 py-6 text-center font-semibold text-muted">
                                    Pegawai ini belum memiliki riwayat jabatan.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 5: RIWAYAT KGB --}}
            @if($canReadHistoriesForTabs)
            <x-pegawai.detail.panel tab="kgb" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Riwayat Kenaikan Gaji Berkala (KGB)"
                    description="Catatan penyesuaian gaji berkala setiap 2 tahun sekali."
                >
                </x-pegawai.detail.section-header>
                <x-pegawai.detail.table
                    name="kgb"
                    :headings="['Gaji Pokok Baru', 'Nomor Surat KGB', 'Tanggal Surat', 'TMT KGB', 'Berkas']"
                >
                            <template x-for="k in kgbList" :key="k.id || k.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="k.gaji"></td>
                                    <td class="px-4 py-3" x-text="k.no_sk"></td>
                                    <td class="px-4 py-3" x-text="formatDate(k.tgl_sk)"></td>
                                    <td class="px-4 py-3" x-text="formatDate(k.tmt)"></td>
                                    <td class="px-4 py-3">
                                        <template x-if="k.download_url">
                                            <a :href="k.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                        </template>
                                        <template x-if="!k.download_url">
                                            <span class="text-muted">-</span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="kgbList.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center font-semibold text-muted">
                                    Pegawai ini belum memiliki riwayat KGB.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 6: HUKUMAN DISIPLIN --}}
            @if($canReadDisciplineForTabs)
            <x-pegawai.detail.panel tab="disiplin" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Riwayat Hukuman Disiplin"
                    description="Catatan sanksi disiplin pegawai yang mempengaruhi promosi kepegawaian."
                >
                </x-pegawai.detail.section-header>
                <x-pegawai.detail.table
                    name="disiplin"
                    :headings="['Jenis Hukuman', 'Alasan / Pelanggaran', 'Nomor SK', 'Tanggal SK', 'Masa Berlaku', 'Berkas']"
                    :show-actions="false"
                >
                            <template x-for="d in disiplinList" :key="d.id">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3">
                                        <span class="font-bold text-danger" x-text="d.jenis"></span>
                                        <template x-if="d.is_active">
                                            <span class="ml-1 inline-flex items-center rounded-full bg-danger/10 px-1.5 py-0.5 text-[8px] font-bold text-danger uppercase">Aktif</span>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3" x-text="d.alasan"></td>
                                    <td class="px-4 py-3" x-text="d.no_sk"></td>
                                    <td class="px-4 py-3" x-text="formatDate(d.tgl_sk)"></td>
                                    <td class="px-4 py-3" x-text="formatDate(d.tgl_mulai) + ' s/d ' + (d.tgl_akhir ? formatDate(d.tgl_akhir) : 'Sekarang')"></td>
                                    <td class="px-4 py-3">
                                        <a x-show="d.download_url" :href="d.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                        <span x-show="!d.download_url" class="text-muted">-</span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="disiplinList.length === 0">
                                <td colspan="6" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini tidak memiliki riwayat hukuman disiplin.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 7: RIWAYAT PENDIDIKAN --}}
            @if($canReadHistoriesForTabs)
            <x-pegawai.detail.panel tab="pendidikan" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Riwayat Pendidikan Formal"
                    description="Riwayat kualifikasi akademis tertinggi staf."
                >
                </x-pegawai.detail.section-header>
                <div class="grid gap-3 rounded-lg border border-border bg-soft/30 p-4 sm:grid-cols-2">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted">Pendidikan Terakhir</p>
                        <p class="mt-1 text-sm font-semibold text-ink" x-text="pendidikanSummary.pendidikan_terakhir ?? '-'">{{ $p->pendidikan_terakhir ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted">Program Studi</p>
                        <p class="mt-1 text-sm font-semibold text-ink" x-text="pendidikanSummary.program_studi ?? '-'">{{ $p->programStudi?->nama ?? $p->prodi_pendidikan_terakhir ?? '-' }}</p>
                    </div>
                </div>
                {{-- Loading skeleton --}}
                <div x-show="pendidikanLoading" role="status" aria-live="polite" class="flex items-center justify-center gap-2 py-10 text-xs text-muted font-sans">
                    <svg class="w-4 h-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Memuat riwayat pendidikan...
                </div>

                <x-pegawai.detail.table
                    name="pendidikan"
                    :headings="['Jenjang', 'Nama Institusi', 'Program Studi', 'Tahun Lulus', 'Nomor Ijazah', 'Berkas']"
                    :show-actions="false"
                    x-show="!pendidikanLoading"
                >
                            <template x-for="(edu, index) in pendidikanList" :key="edu.id ?? edu.no_ijazah">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="edu.tingkat ?? edu.jenjang?.nama ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.institusi ?? edu.nama_institusi ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.prodi ?? edu.jurusan ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.lulus ?? edu.tahun_lulus ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.no_ijazah ?? '-'"></td>
                                    <td class="px-4 py-3">
                                        <a x-show="edu.download_url" :href="edu.download_url" class="font-semibold text-primary hover:underline">Unduh Ijazah</a>
                                        <span x-show="!edu.download_url" class="text-muted">-</span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="!pendidikanLoading && pendidikanList.length === 0">
                                <td colspan="6" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini belum memiliki riwayat pendidikan formal.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 8: DATA PENGANGKATAN --}}
            @if($canReadHistoriesForTabs)
            <x-pegawai.detail.panel tab="pengangkatan" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Data & SK Pengangkatan Pertama"
                    description="Berkas dasar penerimaan kepegawaian sebagai CPNS/PNS/PPPK."
                >
                </x-pegawai.detail.section-header>

                <x-pegawai.detail.table
                    name="pengangkatan"
                    :headings="['Jenis Pengangkatan', 'Nomor SK Pengangkatan', 'Tanggal SK', 'TMT Pengangkatan', 'Berkas']"
                >
                    <template x-if="appointmentData">
                        <tr class="transition-colors hover:bg-soft/30 text-ink">
                            <td class="px-4 py-3 font-bold" x-text="appointmentData.jenis_pengangkatan || '-'"></td>
                            <td class="px-4 py-3" x-text="appointmentData.no_sk || '-'"></td>
                            <td class="px-4 py-3" x-text="formatDate(appointmentData.tanggal_sk)"></td>
                            <td class="px-4 py-3" x-text="formatDate(appointmentData.tmt_pengangkatan)"></td>
                            <td class="px-4 py-3">
                                <template x-if="appointmentData.download_url">
                                    <a :href="appointmentData.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                </template>
                                <template x-if="!appointmentData.download_url">
                                    <span class="text-muted">-</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <template x-if="!appointmentData">
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center font-semibold text-muted">
                                Belum ada data pengangkatan.
                            </td>
                        </tr>
                    </template>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

            {{-- TAB 9: DOKUMEN & SK (read-only, pimpinan-scoped). Partial admin
                tidak dipakai: ia memuat kontrol mutasi dan URL arsip admin.
                Baris berasal dari relasi documents yang sudah difilter kategori
                aman + URL unduh khusus Pimpinan oleh action. --}}
            @if($canReadDocuments)
            <x-pegawai.detail.panel tab="docs" id-prefix="pimpinan">
                <x-pegawai.detail.section-header
                    title="Daftar Dokumen & Berkas Pegawai"
                    description="Arsip dokumen SK dan berkas lainnya dalam mode baca-saja."
                />
                <x-pegawai.detail.table
                    name="docs"
                    :headings="['Dokumen', 'Nomor', 'Tanggal', 'Berkas']"
                >
                    @forelse(($p->documents ?? collect()) as $document)
                        <tr class="transition-colors hover:bg-soft/30 text-ink">
                            <td class="px-4 py-3">
                                <p class="font-bold font-sans">{{ $document->nama_dokumen }}</p>
                                <p class="text-[10px] text-muted">{{ $document->jenis_dokumen }}</p>
                            </td>
                            <td class="px-4 py-3 font-sans">{{ $document->nomor_dokumen ?? '-' }}</td>
                            <td class="px-4 py-3 font-sans">{{ $document->tanggal_dokumen?->format('d-m-Y') ?? '-' }}</td>
                            <td class="px-4 py-3">
                                @if($document->pimpinan_download_url)
                                    <a href="{{ $document->pimpinan_download_url }}" class="font-semibold text-primary hover:underline">Unduh</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                Belum ada dokumen.
                            </td>
                        </tr>
                    @endforelse
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>
            @endif

        </x-pegawai.detail.shell>

    @if($canDeactivateEmployee)
    <x-ui.modal show="showDeactivateModal" title="Nonaktifkan Pegawai" closeAction="showDeactivateModal = false" maxWidth="sm">
        <form method="POST" action="{{ route('rbac.pegawai.destroy', $p->id) }}" class="space-y-4">
            @csrf
            <p class="text-sm text-muted font-sans">
                Pegawai <strong class="text-ink">{{ $p->nama_lengkap }}</strong> akan dinonaktifkan: akses akun
                diblokir dan data keluar dari daftar pegawai aktif. Data tetap disimpan dan dapat diaktifkan
                kembali oleh Super Admin atau Admin Kepegawaian yang berwenang.
            </p>
            {{-- Kontrak perubahan status resmi: tanggal efektif + alasan wajib (US-2.9) --}}
            <div class="space-y-1.5">
                <label for="detail-deactivate-tanggal" class="block text-xs font-bold text-ink uppercase tracking-wider font-sans">
                    Tanggal Efektif <span class="text-danger">*</span>
                </label>
                <input id="detail-deactivate-tanggal" type="date" name="tanggal_efektif" required
                    value="{{ now()->toDateString() }}"
                    class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                <p class="mt-1 text-xs text-muted">
                    Jika memilih tanggal setelah hari ini, penonaktifan dijadwalkan dan akses baru diblokir setelah tanggal tersebut diproses.
                </p>
            </div>
            <div class="space-y-1.5">
                <label for="detail-deactivate-alasan" class="block text-xs font-bold text-ink uppercase tracking-wider font-sans">
                    Alasan Penonaktifan <span class="text-danger">*</span>
                </label>
                <textarea id="detail-deactivate-alasan" name="alasan" rows="2" required minlength="3"
                    placeholder="Contoh: Mutasi keluar, pengunduran diri, atau sanksi administratif"
                    class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"></textarea>
            </div>
            <div class="flex justify-end gap-3 border-t border-border pt-4">
                <button type="button" @click="showDeactivateModal = false"
                    class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft">
                    Batal
                </button>
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">
                    Ya, Nonaktifkan
                </button>
            </div>
        </form>
    </x-ui.modal>
    @endif


</div>{{-- /x-data utama --}}

</div>
</x-layouts.app>


