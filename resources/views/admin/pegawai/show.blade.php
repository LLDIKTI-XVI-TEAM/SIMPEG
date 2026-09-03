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

        $detailTabs = [
            'profile' => 'Profil',
            'keluarga' => 'Keluarga',
            'kepangkatan' => 'Kepangkatan',
            'jabatan' => 'Jabatan',
            'kgb' => 'KGB',
            'disiplin' => 'Hukuman Disiplin',
            'pendidikan' => 'Pendidikan',
            'pengangkatan' => 'Pengangkatan',
            'docs' => 'Dokumen & SK',
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
            this.$nextTick(() => document.getElementById(`admin-tab-${tab}`)?.focus());
        },
        moveTab(offset) {
            const current = this.tabs.indexOf(this.activeTab);
            this.selectTab(this.tabs[(current + offset + this.tabs.length) % this.tabs.length]);
        },
        kinerjaBaik: {{ $p->is_kinerja_baik ? 'true' : 'false' }},
        kinerjaEndpoint: @js(route('pegawai.kinerja.update', $p->id)),
        isUpdatingKinerja: false,
        satyalancanaEligible: {{ $p->is_satyalancana_eligible ? 'true' : 'false' }},
        satyalancanaNote: @js($p->satyalancana_note ?? ''),
        satyalancanaEndpoint: @js(route('pegawai.satyalancana.update', $p->id)),
        isUpdatingSatyalancana: false,
        showDeactivateModal: false,
        supervisorLookupEndpoint: @js(route('pegawai.supervisor-lookup', $p->id)),
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

        keluargaList: {{ ($p->families ?? collect())->map(fn($f) => ['id' => $f->id, 'nama_anggota' => $f->nama_anggota, 'nik' => $f->nik, 'hubungan' => $f->hubungan, 'tempat_lahir' => $f->tempat_lahir, 'tanggal_lahir' => $f->tanggal_lahir, 'jenis_kelamin' => $f->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki-laki', 'pekerjaan' => $f->pekerjaan, 'status' => $f->status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung'])->toJson() }},
        keluargaLoading: false,
        isDeletingKeluarga: false,
        pendidikanSummary: @js([
            'pendidikan_terakhir' => $p->pendidikan_terakhir,
            'program_studi' => $p->programStudi?->nama ?? $p->prodi_pendidikan_terakhir,
        ]),
        pangkatList: {{ $p->rankHistories->map(fn($r) => ['id' => $r->id, 'golongan' => $r->golongan->nama ?? '-', 'no_sk' => $r->no_sk, 'tgl_sk' => $r->tanggal_sk?->format('Y-m-d'), 'tmt' => $r->tmt_pangkat?->format('Y-m-d'), 'file_sk' => $r->file_sk, 'download_url' => $r->admin_attachment_download_url])->toJson() }},
        jabatanList: {{ $p->positionHistories->map(fn($j) => ['id' => $j->id, 'jabatan' => $j->jabatan?->nama ?? $j->nama_jabatan, 'unit' => $j->unitKerja->nama ?? '-', 'kelas_jabatan' => $j->kelas_jabatan, 'no_sk' => $j->no_sk, 'tgl_sk' => $j->tanggal_sk?->format('Y-m-d'), 'tmt' => $j->tmt_jabatan?->format('Y-m-d'), 'file_sk' => $j->file_sk, 'download_url' => $j->admin_attachment_download_url])->toJson() }},
        kgbList: {{ $p->salaryHistories->map(fn($s) => ['id' => $s->id, 'gaji' => 'Rp ' . number_format($s->gaji_pokok, 0, ',', '.'), 'no_sk' => $s->no_sk, 'tgl_sk' => $s->tanggal_sk?->format('Y-m-d'), 'tmt' => $s->tmt_kgb?->format('Y-m-d'), 'file_sk' => $s->file_sk, 'download_url' => $s->admin_attachment_download_url])->toJson() }},
        disiplinList: {{ $p->disciplineRecords->map(fn($d) => ['id' => $d->id, 'jenis' => $d->jenis_hukuman, 'alasan' => $d->deskripsi, 'no_sk' => $d->no_sk, 'tgl_sk' => $d->tanggal_sk?->format('Y-m-d'), 'tgl_mulai' => $d->tanggal_mulai?->format('Y-m-d'), 'tgl_akhir' => $d->tanggal_berakhir?->format('Y-m-d'), 'is_active' => $d->is_active, 'download_url' => $d->admin_attachment_download_url])->toJson() }},
        pendidikanList: {{ ($p->educationHistories ?? collect())->map(fn($e) => ['id' => $e->id, 'jenjang_id' => $e->jenjang_id, 'program_studi_id' => $e->program_studi_id, 'tingkat' => $e->jenjang?->urutan ?? $e->tingkat ?? '-', 'institusi' => $e->nama_institusi ?? '-', 'prodi' => $e->programStudi?->nama ?? $e->jurusan ?? '-', 'lulus' => $e->tahun_lulus ?? '-', 'no_ijazah' => $e->no_ijazah ?? '-', 'download_url' => $e->admin_attachment_download_url])->toJson() }},
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
            'download_url' => $p->appointment->admin_attachment_download_url,
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
            if (!this.appointmentForm.jenis_pengangkatan || !this.appointmentForm.no_sk || !this.appointmentForm.tanggal_sk || !this.appointmentForm.tmt_pengangkatan) {
                this.appointmentError = 'Mohon lengkapi semua kolom bertanda bintang (*).';
                return;
            }

            this.isSavingAppointment = true;
            this.appointmentError = '';

            const fd = new FormData();
            fd.append('jenis_pengangkatan', this.appointmentForm.jenis_pengangkatan);
            fd.append('no_sk', this.appointmentForm.no_sk);
            fd.append('tanggal_sk', this.appointmentForm.tanggal_sk);
            fd.append('tmt_pengangkatan', this.appointmentForm.tmt_pengangkatan);
            if (this.appointmentForm.file_sk) {
                fd.append('file_sk', this.appointmentForm.file_sk);
            }

            try {
                const response = await fetch(`/api/v1/pegawai/{{ $p->id }}/pengangkatan`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: fd,
                });

                if (response.ok) {
                    const result = await response.json();
                    this.appointmentData = result.appointment;
                    this.clearDocumentArchiveCache();
                    this.showAppointmentModal = false;
                    this.toast = { show: true, message: result.message || 'Data SK Pengangkatan berhasil disimpan!', type: 'success' };
                    setTimeout(() => this.toast.show = false, 3000);
                } else {
                    const err = await response.json();
                    if (err.errors) {
                        this.appointmentError = Object.values(err.errors).flat().join(' ');
                    } else {
                        this.appointmentError = err.message || 'Gagal menyimpan data pengangkatan.';
                    }
                }
            } catch (e) {
                this.appointmentError = 'Terjadi kesalahan jaringan saat menyimpan data.';
            } finally {
                this.isSavingAppointment = false;
            }
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
            if (!this.uploadSkFile) {
                this.uploadSkError = 'Silakan pilih berkas SK terlebih dahulu.';
                return;
            }

            if (!this.uploadSkRecord || (!this.uploadSkRecord.id && this.uploadSkType !== 'pengangkatan')) {
                this.uploadSkError = 'Data riwayat tidak valid.';
                return;
            }

            this.isUploadingSk = true;
            this.uploadSkError = '';

            let endpoint = '';
            if (this.uploadSkType === 'jabatan') {
                endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-jabatan/${this.uploadSkRecord.id}/upload-sk`;
            } else if (this.uploadSkType === 'kgb') {
                endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-kgb/${this.uploadSkRecord.id}/upload-sk`;
            } else if (this.uploadSkType === 'pengangkatan') {
                endpoint = `/api/v1/pegawai/{{ $p->id }}/pengangkatan/upload-sk`;
            } else {
                endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-kepangkatan/${this.uploadSkRecord.id}/upload-sk`;
            }

            const fd = new FormData();
            fd.append('file_sk', this.uploadSkFile);

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: fd,
                });

                if (response.ok) {
                    const result = await response.json();

                    if (this.uploadSkType === 'pengangkatan') {
                        this.appointmentData = result.appointment;
                    } else {
                        const updated = result.history;
                        if (this.uploadSkType === 'pangkat') {
                            const idx = this.pangkatList.findIndex(item => item.id === this.uploadSkRecord.id);
                            if (idx !== -1) {
                                this.pangkatList[idx].download_url = updated.download_url;
                                this.pangkatList[idx].file_sk = updated.file_sk;
                            }
                        } else if (this.uploadSkType === 'jabatan') {
                            const idx = this.jabatanList.findIndex(item => item.id === this.uploadSkRecord.id);
                            if (idx !== -1) {
                                this.jabatanList[idx].download_url = updated.download_url;
                                this.jabatanList[idx].file_sk = updated.file_sk;
                            }
                        } else if (this.uploadSkType === 'kgb') {
                            const idx = this.kgbList.findIndex(item => item.id === this.uploadSkRecord.id);
                            if (idx !== -1) {
                                this.kgbList[idx].download_url = updated.download_url;
                                this.kgbList[idx].file_sk = updated.file_sk;
                            }
                        }
                    }

                    this.clearDocumentArchiveCache();
                    this.showUploadSkModal = false;
                    this.toast = { show: true, message: result.message || 'Berkas SK berhasil diperbarui!', type: 'success' };
                    setTimeout(() => this.toast.show = false, 3000);
                } else {
                    const err = await response.json();
                    if (err.errors && err.errors.file_sk) {
                        this.uploadSkError = err.errors.file_sk.join(' ');
                    } else {
                        this.uploadSkError = err.message || 'Gagal mengunggah berkas SK. Silakan coba lagi.';
                    }
                }
            } catch (e) {
                this.uploadSkError = 'Terjadi kesalahan jaringan saat mengunggah berkas.';
            } finally {
                this.isUploadingSk = false;
            }
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
            if (!this.newBerkas.file) {
                this.uploadBerkasError = 'File berkas wajib dipilih.';
                return;
            }
            this.isUploadingBerkas = true;
            this.uploadBerkasError = '';
            this.uploadBerkasErrors = {};

            const fd = new FormData();
            fd.append('nama_dokumen',     this.newBerkas.nama_dokumen);
            fd.append('kategori_dokumen', this.newBerkas.kategori_dokumen);
            fd.append('nomor_dokumen',    this.newBerkas.nomor_dokumen);
            fd.append('tanggal_terbit',   this.newBerkas.tanggal_terbit);
            fd.append('keterangan',       this.newBerkas.keterangan);
            fd.append('berkas',           this.newBerkas.file);

            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/berkas-lainnya`, {
                    method: 'POST',
                    headers: {
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: fd,
                });

                if (res.ok) {
                    const json = await res.json();
                    // Tambahkan dokumen baru ke daftar secara reaktif (tanpa reload)
                    this.berkasList.unshift(json.document);
                    this.clearDocumentArchiveCache();
                    this.showUploadBerkas = false;
                    this.newBerkas = { nama_dokumen: '', kategori_dokumen: 'ktp_kk', nomor_dokumen: '', tanggal_terbit: '', keterangan: '', file: null };
                    const fileInput = document.getElementById('berkas_upload_input');
                    if (fileInput) fileInput.value = '';
                    this.toast = { show: true, message: 'Berkas berhasil diunggah.', type: 'success' };
                    setTimeout(() => { this.toast.show = false; }, 3500);
                } else if (res.status === 422) {
                    const json = await res.json();
                    this.uploadBerkasErrors = json.errors ?? {};
                    this.uploadBerkasError = json.message ?? 'Terdapat kesalahan pada data yang dikirim.';
                } else {
                    this.uploadBerkasError = 'Gagal mengunggah berkas. Silakan coba lagi.';
                }
            } catch (e) {
                this.uploadBerkasError = 'Gagal mengunggah berkas. Periksa koneksi internet Anda.';
            } finally {
                this.isUploadingBerkas = false;
            }
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
            if (!this.editingBerkas) return;
            this.isUpdatingBerkas = true;
            this.editBerkasError = '';
            this.editBerkasErrors = {};

            const fd = new FormData();
            fd.append('_method', 'PUT');
            fd.append('nama_dokumen', this.editBerkas.nama_dokumen);
            fd.append('kategori_dokumen', this.editBerkas.kategori_dokumen);
            fd.append('nomor_dokumen', this.editBerkas.nomor_dokumen);
            fd.append('tanggal_terbit', this.editBerkas.tanggal_terbit);
            fd.append('keterangan', this.editBerkas.keterangan);
            if (this.editBerkas.file) fd.append('berkas', this.editBerkas.file);

            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/berkas-lainnya/${this.editingBerkas.id}`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: fd,
                });
                const json = await res.json().catch(() => ({}));

                if (res.ok) {
                    this.berkasList = this.berkasList.map((doc) => doc.id === json.document.id ? json.document : doc);
                    this.clearDocumentArchiveCache();
                    this.showEditBerkas = false;
                    this.editingBerkas = null;
                    const fileInput = document.getElementById('edit_berkas_upload_input');
                    if (fileInput) fileInput.value = '';
                    this.toast = { show: true, message: 'Berkas berhasil diperbarui.', type: 'success' };
                    setTimeout(() => { this.toast.show = false; }, 3500);
                } else if (res.status === 422) {
                    this.editBerkasErrors = json.errors ?? {};
                    this.editBerkasError = json.message ?? 'Terdapat kesalahan pada data yang dikirim.';
                } else {
                    this.editBerkasError = json.message ?? 'Gagal memperbarui berkas. Silakan coba lagi.';
                }
            } catch (e) {
                this.editBerkasError = 'Gagal memperbarui berkas. Periksa koneksi internet Anda.';
            } finally {
                this.isUpdatingBerkas = false;
            }
        },
        openDeleteBerkas(doc) {
            if (!doc.can_mutate) return;
            this.deletingBerkas = doc;
            this.deleteBerkasError = '';
            this.showDeleteBerkas = true;
        },
        async submitDeleteBerkas() {
            if (!this.deletingBerkas) return;
            this.isDeletingBerkas = true;
            this.deleteBerkasError = '';

            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/berkas-lainnya/${this.deletingBerkas.id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                });
                const json = await res.json().catch(() => ({}));

                if (res.ok) {
                    const deletedId = this.deletingBerkas.id;
                    this.berkasList = this.berkasList.filter((doc) => doc.id !== deletedId);
                    this.clearDocumentArchiveCache();
                    this.showDeleteBerkas = false;
                    this.deletingBerkas = null;
                    this.toast = { show: true, message: 'Berkas berhasil dihapus.', type: 'success' };
                    setTimeout(() => { this.toast.show = false; }, 3500);
                } else {
                    this.deleteBerkasError = json.errors?.document?.[0] ?? json.message ?? 'Gagal menghapus berkas. Silakan coba lagi.';
                }
            } catch (e) {
                this.deleteBerkasError = 'Gagal menghapus berkas. Periksa koneksi internet Anda.';
            } finally {
                this.isDeletingBerkas = false;
            }
        },
        async updateKinerjaBaik(value) {
            const previous = !value;
            this.isUpdatingKinerja = true;

            try {
                const response = await fetch(this.kinerjaEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ is_kinerja_baik: value })
                });

                if (!response.ok) {
                    this.kinerjaBaik = previous;
                    this.toast = { show: true, message: 'Status kinerja gagal diperbarui.', type: 'error' };
                    setTimeout(() => this.toast.show = false, 5000);

                    return;
                }

                const result = await response.json();
                this.kinerjaBaik = result.is_kinerja_baik;
                this.toast = { show: true, message: result.message, type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (error) {
                this.kinerjaBaik = previous;
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan.', type: 'error' };
                setTimeout(() => this.toast.show = false, 5000);
            } finally {
                this.isUpdatingKinerja = false;
            }
        },
        async updateSatyalancanaEligibility() {
            this.isUpdatingSatyalancana = true;

            try {
                const response = await fetch(this.satyalancanaEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        is_satyalancana_eligible: this.satyalancanaEligible,
                        satyalancana_note: this.satyalancanaNote
                    })
                });

                if (!response.ok) {
                    this.toast = { show: true, message: 'Kelayakan Satyalancana gagal diperbarui.', type: 'error' };
                    setTimeout(() => this.toast.show = false, 5000);

                    return;
                }

                const result = await response.json();
                this.satyalancanaEligible = result.is_satyalancana_eligible;
                this.satyalancanaNote = result.satyalancana_note || '';
                this.toast = { show: true, message: result.message, type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (error) {
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan.', type: 'error' };
                setTimeout(() => this.toast.show = false, 5000);
            } finally {
                this.isUpdatingSatyalancana = false;
            }
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
            const requestId = ++this.supervisorLookupRequestId;
            this.supervisorLoading = true;
            this.supervisorOpen = true;
            this.supervisorActiveIndex = -1;

            try {
                const response = await fetch(`${this.supervisorLookupEndpoint}?q=${encodeURIComponent(this.supervisorQuery.trim())}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) {
                    throw new Error('Lookup Kepala Bagian tidak tersedia.');
                }

                const result = await response.json();
                if (requestId !== this.supervisorLookupRequestId) return;

                this.supervisorResults = Array.isArray(result.data) ? result.data : [];
            } catch (error) {
                if (requestId !== this.supervisorLookupRequestId) return;

                this.supervisorResults = [];
                this.supervisorError = 'Pencarian Kepala Bagian gagal. Coba lagi.';
            } finally {
                if (requestId === this.supervisorLookupRequestId) {
                    this.supervisorLoading = false;
                }
            }
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
            this.loadingArsip = true;
            this.arsipDokumen = [];
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/arsip-dokumen?kategori=sk_hukuman_disiplin`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (res.ok) {
                    const json = await res.json();
                    this.arsipDokumen = json.documents ?? [];
                }
            } catch (e) {
                this.arsipDokumen = [];
            } finally {
                this.loadingArsip = false;
            }
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

        async fetchKeluarga() {
            const cached = sessionStorage.getItem(this._keluargaCacheKey);
            if (cached) {
                try {
                    this.keluargaList = JSON.parse(cached);
                    return;
                } catch (e) {
                    sessionStorage.removeItem(this._keluargaCacheKey);
                }
            }
            this.keluargaLoading = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/keluarga`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const json = await res.json();
                // Normalisasi format tanggal dan label sesuai tampilan tabel.
                this.keluargaList = (json.families ?? []).map(f => ({
                    id:            f.id,
                    nama_anggota:  f.nama_anggota,
                    nik:           f.nik,
                    hubungan:      f.hubungan,
                    tempat_lahir:  f.tempat_lahir,
                    tanggal_lahir: f.tanggal_lahir,
                    jenis_kelamin: f.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan',
                    pekerjaan:     f.pekerjaan,
                    status:        f.status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung',
                }));
                sessionStorage.setItem(this._keluargaCacheKey, JSON.stringify(this.keluargaList));
            } catch (e) {
                console.error('Gagal memuat data keluarga:', e);
            } finally {
                this.keluargaLoading = false;
            }
        },

        async deleteKeluarga(id, index) {
            if (!window.confirm('Apakah Anda yakin ingin menghapus data anggota keluarga ini? Tindakan ini tidak dapat dibatalkan.')) return;
            this.isDeletingKeluarga = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/keluarga/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    this.toast = { show: true, message: err.message ?? 'Gagal menghapus data keluarga.', type: 'error' };
                    setTimeout(() => this.toast.show = false, 4000);
                    return;
                }
                this.keluargaList.splice(index, 1);
                sessionStorage.setItem(this._keluargaCacheKey, JSON.stringify(this.keluargaList));
                this.toast = { show: true, message: 'Anggota keluarga berhasil dihapus.', type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (e) {
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan. Coba lagi.', type: 'error' };
                setTimeout(() => this.toast.show = false, 4000);
            } finally {
                this.isDeletingKeluarga = false;
            }
        },

        async fetchPendidikan() {
            // Coba baca dari sessionStorage — validasi versi + TTL sebelum dipakai.
            const cached = sessionStorage.getItem(this._pendidikanCacheKey);
            if (cached) {
                try {
                    const envelope = JSON.parse(cached);
                    const isEnvelope = envelope && typeof envelope === 'object' && Array.isArray(envelope.data) && 'v' in envelope && 't' in envelope;
                    if (isEnvelope) {
                        const fresh = envelope.v === this._pendidikanCacheVersion && (Date.now() - envelope.t) < this._pendidikanCacheTTL;
                        if (fresh) {
                            this.pendidikanList = envelope.data;
                            if (envelope.summary) this.pendidikanSummary = envelope.summary;
                            return;
                        }
                    }
                } catch (e) {
                    // corrupted — biarkan jatuh ke fetch
                }
                sessionStorage.removeItem(this._pendidikanCacheKey);
            }
            this.pendidikanLoading = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/riwayat-pendidikan`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const json = await res.json();
                this.pendidikanList = json.histories ?? [];
                this.applyEducationSummary(json.education_summary);
                sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify({ v: this._pendidikanCacheVersion, t: Date.now(), data: this.pendidikanList, summary: json.education_summary ?? null }));
            } catch (e) {
                console.error('Gagal memuat riwayat pendidikan:', e);
            } finally {
                this.pendidikanLoading = false;
            }
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
            this.editPendidikanError = '';
            this.isUpdatingPendidikan = true;
            const payload = { ...this.editPendidikanForm };
            const cur = payload.program_studi_id || null;
            const init = this._initialProgramStudiId ?? null;
            if (cur === init) delete payload.program_studi_id;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/riwayat-pendidikan/${this.editingPendidikan.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const result = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const msgs = result.errors
                        ? Object.values(result.errors).flat().join(' ')
                        : (result.message ?? 'Gagal memperbarui riwayat pendidikan.');
                    this.editPendidikanError = msgs;
                    return;
                }
                const h = result.history;
                const idx = this.pendidikanList.findIndex(e => e.id === this.editingPendidikan.id);
                if (idx !== -1) {
                    this.pendidikanList[idx] = {
                        id:        h.id,
                        jenjang_id: h.jenjang_id,
                        program_studi_id: h.program_studi_id,
                        tingkat:   h.tingkat,
                        institusi: h.nama_institusi,
                        prodi:     h.program_studi ?? h.jurusan ?? '-',
                        lulus:     h.tahun_lulus,
                        no_ijazah: h.no_ijazah ?? '-',
                        download_url: h.download_url,
                    };
                }
                sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify({ v: this._pendidikanCacheVersion, t: Date.now(), data: this.pendidikanList, summary: result.education_summary ?? null }));
                this.applyEducationSummary(result.education_summary);
                this.showEditPendidikan = false;
                this.editingPendidikan = null;
                this.toast = { show: true, message: 'Riwayat pendidikan berhasil diperbarui.', type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (e) {
                this.editPendidikanError = 'Terjadi kesalahan jaringan. Coba lagi.';
            } finally {
                this.isUpdatingPendidikan = false;
            }
        },

        async deletePendidikan(id, index) {
            if (!window.confirm('Apakah Anda yakin ingin menghapus riwayat pendidikan ini? Tindakan ini tidak dapat dibatalkan.')) return;
            this.isDeletingPendidikan = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/riwayat-pendidikan/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const result = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.toast = { show: true, message: result.message ?? 'Gagal menghapus riwayat pendidikan.', type: 'error' };
                    return;
                }
                this.pendidikanList.splice(index, 1);
                sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify({ v: this._pendidikanCacheVersion, t: Date.now(), data: this.pendidikanList, summary: result.education_summary ?? null }));
                this.applyEducationSummary(result.education_summary);
                this.toast = { show: true, message: 'Riwayat pendidikan berhasil dihapus.', type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (e) {
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan. Coba lagi.', type: 'error' };
            } finally {
                this.isDeletingPendidikan = false;
            }
        },

        async deleteDisiplin(id, index) {
            if (!window.confirm('Apakah Anda yakin ingin menghapus riwayat hukuman disiplin ini? Berkas SK terkait juga akan dihapus. Tindakan ini tidak dapat dibatalkan.')) return;
            this.isDeletingDisiplin = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/disiplin/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const result = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.toast = { show: true, message: result.message ?? 'Gagal menghapus riwayat hukuman disiplin.', type: 'error' };
                    setTimeout(() => this.toast.show = false, 4000);
                    return;
                }
                this.disiplinList.splice(index, 1);
                this.toast = { show: true, message: 'Riwayat hukuman disiplin berhasil dihapus.', type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (e) {
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan. Coba lagi.', type: 'error' };
                setTimeout(() => this.toast.show = false, 4000);
            } finally {
                this.isDeletingDisiplin = false;
            }
        },

        async submitForm() {
            this.modalError = '';
            let payload = { type: this.modalType };
            if (this.modalType === 'keluarga') {
                payload = { ...payload, ...this.newKeluarga };
            } else if (this.modalType === 'pangkat') {
                payload = { ...payload, ...this.newPangkat };
            } else if (this.modalType === 'jabatan') {
                payload = { ...payload, ...this.newJabatan };
            } else if (this.modalType === 'kgb') {
                payload = { ...payload, ...this.newKgb };
            } else if (this.modalType === 'disiplin') {
                payload = { ...payload, ...this.newDisiplin };
            } else if (this.modalType === 'pendidikan') {
                payload = { ...payload, ...this.newPendidikan };
            }

            let endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-pendidikan`;
            if (this.modalType === 'pangkat') endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-kepangkatan`;
            else if (this.modalType === 'jabatan') endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-jabatan`;
            else if (this.modalType === 'kgb') endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-kgb`;
            else if (this.modalType === 'keluarga') endpoint = `/api/v1/pegawai/{{ $p->id }}/keluarga`;
            else if (this.modalType === 'disiplin') endpoint = `/api/v1/pegawai/{{ $p->id }}/disiplin`;

            this.isSubmitting = true;

            try {
                let fetchOptions;
                if (this.modalType === 'disiplin') {
                    const fd = new FormData();
                    const d  = this.newDisiplin;
                    fd.append('jenis_hukuman', d.jenis_hukuman);
                    fd.append('deskripsi', d.deskripsi);
                    fd.append('no_sk', d.no_sk);
                    fd.append('tanggal_sk', d.tanggal_sk);
                    fd.append('tanggal_mulai', d.tanggal_mulai);
                    if (d.tanggal_berakhir) fd.append('tanggal_berakhir', d.tanggal_berakhir);
                    if (d.file_sk)          fd.append('file_sk', d.file_sk);
                    if (d.dokumen_id)       fd.append('dokumen_id', d.dokumen_id);
                    fetchOptions = {
                        method:  'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body:    fd,
                    };
                } else if (['pangkat', 'jabatan', 'kgb'].includes(this.modalType)) {
                    const history = this.modalType === 'pangkat'
                        ? this.newPangkat
                        : (this.modalType === 'jabatan' ? this.newJabatan : this.newKgb);
                    const fd = new FormData();

                    Object.entries(history).forEach(([key, value]) => {
                        if (value !== null && value !== '') {
                            fd.append(key, value);
                        }
                    });

                    fetchOptions = {
                        method:  'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body:    fd,
                    };
                } else {
                    fetchOptions = {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body:    JSON.stringify(payload),
                    };
                }
                const response = await fetch(endpoint, fetchOptions);
                
                if (response.ok) {
                    const result = await response.json();
                    
                    if (this.modalType === 'disiplin') {
                        const r   = result.record;
                        this.disiplinList.unshift({
                            id:        r.id,
                            jenis:     r.jenis_hukuman,
                            alasan:    r.deskripsi,
                            no_sk:     r.no_sk,
                            tgl_sk:    r.tanggal_sk,
                            tgl_mulai: r.tanggal_mulai,
                            tgl_akhir: r.tanggal_berakhir,
                            is_active: r.is_active,
                            download_url: r.download_url,
                        });
                        this.newDisiplin = { jenis_hukuman: 'Ringan', deskripsi: '', no_sk: '', tanggal_sk: '', tanggal_mulai: '', tanggal_berakhir: '', file_sk: null, dokumen_id: '' };
                        this.disiplinFileMode = 'arsip';
                    } else if (this.modalType === 'kgb') {
                        const h = result.history;
                        this.kgbList.unshift({
                            id: h.id,
                            gaji: 'Rp ' + parseInt(this.newKgb.gaji_pokok).toLocaleString('id-ID'),
                            no_sk: this.newKgb.no_sk,
                            tgl_sk: this.newKgb.tanggal_sk,
                            tmt: this.newKgb.tmt_kgb,
                            download_url: h.download_url,
                        });
                        this.newKgb = { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '', file_sk: null };
                        document.getElementById('file_sk_kgb').value = '';
                    } else if (this.modalType === 'jabatan') {
                        const h = result.history;
                        this.jabatanList.unshift({
                            id: h.id,
                            jabatan: h.jabatan?.nama ?? h.nama_jabatan ?? '-',
                            unit: h.unit_kerja?.nama ?? '-',
                            kelas_jabatan: h.kelas_jabatan,
                            no_sk: h.no_sk,
                            tgl_sk: h.tanggal_sk,
                            tmt: h.tmt_jabatan,
                            download_url: h.download_url,
                        });
                        this.newJabatan = { jabatan_id: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', kelas_jabatan: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '', file_sk: null };
                        document.getElementById('file_sk_jabatan').value = '';
                    } else if (this.modalType === 'pangkat') {
                        const h = result.history;
                        this.pangkatList.unshift({
                            id: h.id,
                            golongan: h.golongan?.nama ?? '-',
                            no_sk: h.no_sk,
                            tgl_sk: h.tanggal_sk,
                            tmt: h.tmt_pangkat,
                            download_url: h.download_url,
                        });
                        this.newPangkat = { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '', file_sk: null };
                        document.getElementById('file_sk_pangkat').value = '';
                    } else if (this.modalType === 'keluarga') {
                        const f = result.family;
                        this.keluargaList.unshift({
                            id: f.id,
                            nama_anggota: f.nama_anggota,
                            nik: f.nik,
                            hubungan: f.hubungan,
                            tempat_lahir: f.tempat_lahir,
                            tanggal_lahir: f.tanggal_lahir,
                            jenis_kelamin: f.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan',
                            pekerjaan: f.pekerjaan,
                            status: f.status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung'
                        });
                        this.newKeluarga = { nama_anggota: '', hubungan: 'Istri', nik: '', tempat_lahir: '', tanggal_lahir: '', jenis_kelamin: 'P', status_tunjangan: '0', pekerjaan: '' };
                        // Perbarui cache agar navigasi kembali ke tab keluarga tetap sinkron.
                        sessionStorage.setItem(this._keluargaCacheKey, JSON.stringify(this.keluargaList));
                    } else if (this.modalType === 'pendidikan') {
                        const h = result.history;
                        this.pendidikanList.unshift({
                            id:             h.id,
                            tingkat:        h.tingkat,
                            institusi:      h.nama_institusi,
                            program_studi_id: h.program_studi_id,
                            prodi:          h.program_studi ?? h.jurusan ?? '-',
                            lulus:          h.tahun_lulus,
                            no_ijazah:      h.no_ijazah ?? '-',
                            download_url:   h.download_url,
                        });
                        // Perbarui cache sessionStorage agar navigasi kembali tetap sinkron.
                        sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify({ v: this._pendidikanCacheVersion, t: Date.now(), data: this.pendidikanList, summary: result.education_summary ?? null }));
                        this.applyEducationSummary(result.education_summary);
                        this.newPendidikan = { jenjang_id: '', nama_institusi: '', program_studi_id: '', tahun_lulus: '', no_ijazah: '' };
                    }

                    if (['disiplin', 'kgb', 'jabatan', 'pangkat', 'pendidikan'].includes(this.modalType)) {
                        this.clearDocumentArchiveCache();
                    }

                    // Matriks dihitung ulang di server dari riwayat resmi. Render
                    // ulang komponen agar status dan arsip tidak memakai snapshot lama.
                    if (['kgb', 'jabatan', 'pangkat'].includes(this.modalType)) {
                        await this.$wire.$refresh();
                    }
                    
                    this.showModal = false;
                    this.toast = { show: true, message: 'Data berhasil disimpan!', type: 'success' };
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => this.toast.show = false, 3000);
                } else {
                    const errorData = await response.json();
                    if (errorData.errors) {
                        const msgs = Object.values(errorData.errors).flat();
                        this.modalError = msgs.join(' ');
                    } else {
                        this.modalError = errorData.message || 'Terdapat kesalahan. Silakan coba lagi.';
                    }
                }
            } catch (error) {
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan', type: 'error' };
                setTimeout(() => this.toast.show = false, 5000);
            } finally {
                this.isSubmitting = false;
            }
        }
    }" class="mx-auto max-w-5xl space-y-6">
        
        <x-pegawai.detail.page-header
            :dashboard-url="route('dashboard')"
            :employees-url="route('data-pegawai')"
        >
                @if($canUpdateEmployee)
                <x-ui.button href="{{ route('pegawai.edit', $p->id) }}" wire:navigate aria-label="Edit Pegawai">
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
            <x-pegawai.detail.tabs :tabs="$detailTabs" id-prefix="admin" />

            <p class="history-export-unavailable hidden">Ekspor riwayat tidak tersedia</p>

            {{-- TAB 1: PROFIL LENGKAP --}}
            <x-pegawai.detail.panel tab="profile" id-prefix="admin">
                <x-pegawai.detail.profile
                    :employee="$p"
                    :status-presentation="$statusPresentation"
                    :active-position="$latestPosition"
                    :latest-rank="$latestRank"
                    :latest-status-history="$latestStatusHistory"
                    :active-supervisor-assignments="collect([$currentSupervisor])->filter()"
                    :retirement-date="$estimasiTanggalPensiun"
                    :mask-sensitive="false"
                    download-surface="admin"
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
                        <label class="relative inline-flex items-center cursor-pointer select-none">
                            @if($canUpdateEmployee)
                            <input type="checkbox" x-model="kinerjaBaik" @change="updateKinerjaBaik(kinerjaBaik)" :disabled="isUpdatingKinerja" aria-label="Toggle Kinerja Baik" class="sr-only peer">
                            @else
                            <input type="checkbox" x-model="kinerjaBaik" disabled aria-label="Toggle Kinerja Baik" class="sr-only peer">
                            @endif
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
                            <label class="relative inline-flex items-center cursor-pointer select-none">
                                @if($canUpdateEmployee)
                                <input type="checkbox" x-model="satyalancanaEligible" @change="updateSatyalancanaEligibility()" :disabled="isUpdatingSatyalancana" aria-label="Toggle Kelayakan Satyalancana" class="sr-only peer">
                                @else
                                <input type="checkbox" x-model="satyalancanaEligible" disabled aria-label="Toggle Kelayakan Satyalancana" class="sr-only peer">
                                @endif
                                <div class="w-11 h-6 bg-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-success"></div>
                            </label>
                        </div>
                        <div class="space-y-1">
                            <label for="satyalancana-note" class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Catatan Manual</label>
                            @if(! $canUpdateEmployee)
                            <textarea id="satyalancana-note" x-model="satyalancanaNote" rows="2" readonly class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder-muted shadow-sm focus:outline-none focus:ring-0 opacity-70 resize-none"></textarea>
                            @else
                            <textarea
                                id="satyalancana-note"
                                x-model="satyalancanaNote"
                                rows="2"
                                maxlength="1000"
                                placeholder="Catatan kelayakan Satyalancana"
                                class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder-muted shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 resize-none"
                            ></textarea>
                            @endif
                        </div>
                        @if($canUpdateEmployee)
                        <button
                            type="button"
                            @click="updateSatyalancanaEligibility()"
                            :disabled="isUpdatingSatyalancana"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-3 py-1.5 text-xs font-semibold text-primary shadow-sm transition-colors hover:bg-soft disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Simpan Satyalancana
                        </button>
                        @endif
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
                        <form id="assign-kepala-bagian-form" action="{{ route('pegawai.assign-atasan', $p->id) }}" method="POST" @submit="validateSupervisorSelection($event)" class="border-t border-border pt-4">
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
            <x-pegawai.detail.panel tab="keluarga" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Data Keluarga"
                    description="Daftar istri/suami dan anak yang tercatat sebagai tanggungan."
                >
                    @if($canCreateFamily)
                        <x-slot:actions>
                            <x-ui.button type="button" size="sm" @click="openModal('keluarga', 'Tambah Anggota Keluarga')">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Keluarga
                    </x-ui.button>
                        </x-slot:actions>
                    @endif
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
                    :show-actions="$canDeleteFamily"
                    x-show="!keluargaLoading"
                >
                            <template x-for="(fam, index) in keluargaList" :key="fam.id">
                                <tr class="transition-colors hover:bg-soft/30 text-ink" data-family-readonly-row>
                                    @include('pegawai.partials.detail.family-readonly-cells', ['mode' => 'alpine'])
                                    @if($canDeleteFamily)
                                            <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            @click="deleteKeluarga(fam.id, index)"
                                            :disabled="isDeletingKeluarga"
                                            class="inline-flex items-center gap-1 text-[10px] font-semibold text-danger hover:underline disabled:opacity-40 font-sans cursor-pointer transition-opacity"
                                            title="Hapus anggota keluarga ini"
                                        >
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.021-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                            Hapus
                                        </button>
                                    </td>
                                            @endif
                                </tr>
                            </template>
                            <tr x-show="!keluargaLoading && keluargaList.length === 0">
                                <td colspan="{{ $canDeleteFamily ? 6 : 5 }}" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini belum memiliki data anggota keluarga.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>

            {{-- TAB 3: RIWAYAT KEPANGKATAN --}}
            <x-pegawai.detail.panel tab="kepangkatan" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Riwayat Kepangkatan & Golongan"
                    description="Catatan kenaikan pangkat reguler maupun pilihan selama masa dinas."
                >
                    @if($canCreateEmployeeHistory)
                        <x-slot:actions>
                        <x-ui.button type="button" size="sm" @click="openModal('pangkat', 'Tambah Riwayat Kepangkatan')">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Tambah Riwayat Kepangkatan
                        </x-ui.button>
                        </x-slot:actions>
                    @endif
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
                                            <div class="flex items-center gap-2">
                                                <a :href="p.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                                @if($canUpdateEmployeeHistory)
                                                    <button type="button" @click="openUploadSkModal('pangkat', p)" class="text-xs text-muted hover:text-primary transition inline-flex items-center gap-0.5 cursor-pointer font-sans" title="Ganti Berkas SK">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                                        <span>Ganti</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </template>
                                        <template x-if="!p.download_url">
                                            <div>
                                                @if($canUpdateEmployeeHistory)
                                                    <button type="button" @click="openUploadSkModal('pangkat', p)" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer font-sans">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                                        </svg>
                                                        <span>Upload Berkas</span>
                                                    </button>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </div>
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

            {{-- TAB 4: RIWAYAT JABATAN --}}
            <x-pegawai.detail.panel tab="jabatan" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Riwayat Jabatan & Struktural"
                    description="Catatan penugasan jabatan fungsional maupun struktural."
                >
                    @if($canCreateEmployeeHistory)
                        <x-slot:actions>
                        <x-ui.button type="button" size="sm" @click="openModal('jabatan', 'Tambah Riwayat Jabatan')">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Tambah Riwayat Jabatan
                        </x-ui.button>
                        </x-slot:actions>
                    @endif
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
                                            <div class="flex items-center gap-2">
                                                <a :href="j.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                                @if($canUpdateEmployeeHistory)
                                                    <button type="button" @click="openUploadSkModal('jabatan', j)" class="text-xs text-muted hover:text-primary transition inline-flex items-center gap-0.5 cursor-pointer font-sans" title="Ganti Berkas SK">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                                        <span>Ganti</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </template>
                                        <template x-if="!j.download_url">
                                            <div>
                                                @if($canUpdateEmployeeHistory)
                                                    <button type="button" @click="openUploadSkModal('jabatan', j)" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer font-sans">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                                        </svg>
                                                        <span>Upload Berkas</span>
                                                    </button>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </div>
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

            {{-- TAB 5: RIWAYAT KGB --}}
            <x-pegawai.detail.panel tab="kgb" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Riwayat Kenaikan Gaji Berkala (KGB)"
                    description="Catatan penyesuaian gaji berkala setiap 2 tahun sekali."
                >
                    @if($canCreateEmployeeHistory)
                        <x-slot:actions>
                        <x-ui.button type="button" size="sm" @click="openModal('kgb', 'Tambah Riwayat KGB')">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Tambah Riwayat KGB
                        </x-ui.button>
                        </x-slot:actions>
                    @endif
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
                                            <div class="flex items-center gap-2">
                                                <a :href="k.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                                @if($canUpdateEmployeeHistory)
                                                    <button type="button" @click="openUploadSkModal('kgb', k)" class="text-xs text-muted hover:text-primary transition inline-flex items-center gap-0.5 cursor-pointer font-sans" title="Ganti Berkas SK">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                                        <span>Ganti</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </template>
                                        <template x-if="!k.download_url">
                                            <div>
                                                @if($canUpdateEmployeeHistory)
                                                    <button type="button" @click="openUploadSkModal('kgb', k)" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer font-sans">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                                        </svg>
                                                        <span>Upload Berkas</span>
                                                    </button>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </div>
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

            {{-- TAB 6: HUKUMAN DISIPLIN --}}
            <x-pegawai.detail.panel tab="disiplin" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Riwayat Hukuman Disiplin"
                    description="Catatan sanksi disiplin pegawai yang mempengaruhi promosi kepegawaian."
                >
                    @if($canCreateDiscipline)
                        <x-slot:actions>
                            <x-ui.button type="button" size="sm" @click="openModal('disiplin', 'Tambah Hukuman Disiplin')">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Hukuman
                    </x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-pegawai.detail.section-header>
                <x-pegawai.detail.table
                    name="disiplin"
                    :headings="['Jenis Hukuman', 'Alasan / Pelanggaran', 'Nomor SK', 'Tanggal SK', 'Masa Berlaku', 'Berkas']"
                    :show-actions="$canDeleteDiscipline"
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
                                    @if($canDeleteDiscipline)
                                            <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            @click="deleteDisiplin(d.id, index)"
                                            :disabled="isDeletingDisiplin"
                                            class="inline-flex items-center gap-1 text-[10px] font-semibold text-danger hover:underline disabled:opacity-40 font-sans cursor-pointer transition-opacity"
                                            title="Hapus riwayat hukuman disiplin"
                                        >
                                            Hapus
                                        </button>
                                    </td>
                                            @endif
                                </tr>
                            </template>
                            <tr x-show="disiplinList.length === 0">
                                <td colspan="{{ $canDeleteDiscipline ? 7 : 6 }}" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini tidak memiliki riwayat hukuman disiplin.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>

            {{-- TAB 7: RIWAYAT PENDIDIKAN --}}
            <x-pegawai.detail.panel tab="pendidikan" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Riwayat Pendidikan Formal"
                    description="Riwayat kualifikasi akademis tertinggi staf."
                >
                    @if($canCreateEmployeeHistory)
                        <x-slot:actions>
                            <x-ui.button type="button" size="sm" @click="openModal('pendidikan', 'Tambah Riwayat Pendidikan')">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Pendidikan
                    </x-ui.button>
                        </x-slot:actions>
                    @endif
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
                    :show-actions="$canCreateEmployeeHistory"
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
                                    @if($canCreateEmployeeHistory)
                                            <td class="px-4 py-3 text-right">
                                        <div class="inline-flex items-center gap-3">
                                            <button
                                                type="button"
                                                @click="openEditPendidikan(edu)"
                                                :disabled="isDeletingPendidikan"
                                                class="text-[10px] font-semibold text-primary hover:underline disabled:opacity-40 font-sans cursor-pointer transition-opacity"
                                                title="Edit riwayat pendidikan"
                                            >Edit</button>
                                            <button
                                                type="button"
                                                @click="deletePendidikan(edu.id, index)"
                                                :disabled="isDeletingPendidikan"
                                                class="inline-flex items-center gap-1 text-[10px] font-semibold text-danger hover:underline disabled:opacity-40 font-sans cursor-pointer transition-opacity"
                                                title="Hapus riwayat pendidikan"
                                            >
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.021-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                                Hapus
                                            </button>
                                        </div>
                                    </td>
                                            @endif
                                </tr>
                            </template>
                            <tr x-show="!pendidikanLoading && pendidikanList.length === 0">
                                <td colspan="{{ $canCreateEmployeeHistory ? 7 : 6 }}" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini belum memiliki riwayat pendidikan formal.
                                </td>
                            </tr>
                </x-pegawai.detail.table>
            </x-pegawai.detail.panel>

            {{-- TAB 8: DATA PENGANGKATAN --}}
            <x-pegawai.detail.panel tab="pengangkatan" id-prefix="admin">
                <x-pegawai.detail.section-header
                    title="Data & SK Pengangkatan Pertama"
                    description="Berkas dasar penerimaan kepegawaian sebagai CPNS/PNS/PPPK."
                >
                    @if($canUpdateEmployeeHistory)
                        <x-slot:actions>
                            <template x-if="!appointmentData">
                                <x-ui.button type="button" size="sm" @click="openAppointmentModal()">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Tambah Data Pengangkatan
                                </x-ui.button>
                            </template>
                            <template x-if="appointmentData">
                                <x-ui.button type="button" variant="outline" size="sm" @click="openAppointmentModal()">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                    </svg>
                                    Edit Data Pengangkatan
                                </x-ui.button>
                            </template>
                        </x-slot:actions>
                    @endif
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
                                    <div class="flex items-center gap-2">
                                        <a :href="appointmentData.download_url" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                        @if($canUpdateEmployeeHistory)
                                            <button type="button" @click="openUploadSkModal('pengangkatan', appointmentData)" class="text-xs text-muted hover:text-primary transition inline-flex items-center gap-0.5 cursor-pointer font-sans" title="Ganti Berkas SK">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                                <span>Ganti</span>
                                            </button>
                                        @endif
                                    </div>
                                </template>
                                <template x-if="!appointmentData.download_url">
                                    <div>
                                        @if($canUpdateEmployeeHistory)
                                            <button type="button" @click="openUploadSkModal('pengangkatan', appointmentData)" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer font-sans">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                                </svg>
                                                <span>Upload Berkas</span>
                                            </button>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </div>
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

            @include('admin.pegawai.partials.tab-dokumen-sk')

        </x-pegawai.detail.shell>

        {{-- MODAL DYNAMIC FORM --}}
        <div x-show="showModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;" x-transition>
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-ink/60 transition-opacity" @click="showModal = false"></div>
                
                {{-- Centering spacer --}}
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>
                
                <div class="relative z-10 inline-block transform overflow-hidden rounded-lg bg-surface px-4 pt-5 pb-4 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 sm:align-middle border border-border">
                    <div class="flex items-center justify-between border-b border-border pb-3 mb-4">
                        <h3 class="text-sm font-bold text-ink font-sans" x-text="modalTitle"></h3>
                        <button @click="showModal = false" class="text-muted hover:text-ink cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {{-- Modal Error --}}
                    <template x-if="modalError">
                        <div class="mb-4 rounded-lg bg-red-50 p-4 border border-red-200">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-red-800" x-text="modalError"></h3>
                                </div>
                            </div>
                        </div>
                    </template>

                    <form @submit.prevent="submitForm()" class="space-y-4">
                        {{-- KELUARGA FORM --}}
                        <template x-if="modalType === 'keluarga'">
                            <div class="space-y-3">
                                <x-form.input 
                                    name="nama_anggota"
                                    label="Nama Anggota Keluarga" 
                                    x-model="newKeluarga.nama_anggota" 
                                    required 
                                />
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <x-form.select 
                                        name="hubungan"
                                        label="Hubungan" 
                                        x-model="newKeluarga.hubungan"
                                    >
                                        <option value="Suami">Suami</option>
                                        <option value="Istri">Istri</option>
                                        <option value="Anak">Anak</option>
                                    </x-form.select>
                                    
                                    <x-form.input 
                                        name="nik"
                                        label="NIK" 
                                        type="text"
                                        placeholder="16 digit NIK (opsional)"
                                        minlength="16"
                                        maxlength="16" 
                                        pattern="[0-9]{16}"
                                        title="NIK harus berupa 16 digit angka"
                                        x-model="newKeluarga.nik" 
                                        x-on:input="newKeluarga.nik = newKeluarga.nik.replace(/[^0-9]/g, '')"
                                    />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <x-form.input 
                                        name="tempat_lahir"
                                        label="Tempat Lahir" 
                                        x-model="newKeluarga.tempat_lahir" 
                                    />
                                    <x-form.date 
                                        name="tanggal_lahir"
                                        label="Tanggal Lahir" 
                                        x-model="newKeluarga.tanggal_lahir" 
                                        required 
                                    />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <x-form.select 
                                        name="jenis_kelamin"
                                        label="Jenis Kelamin" 
                                        x-model="newKeluarga.jenis_kelamin" 
                                        required
                                    >
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </x-form.select>
                                    
                                    <x-form.select 
                                        name="status_tunjangan"
                                        label="Status Tunjangan" 
                                        x-model="newKeluarga.status_tunjangan" 
                                        required
                                    >
                                        <option value="1">Ditanggung</option>
                                        <option value="0">Tidak Ditanggung</option>
                                    </x-form.select>
                                </div>

                                <x-form.input 
                                    name="pekerjaan"
                                    label="Pekerjaan" 
                                    x-model="newKeluarga.pekerjaan" 
                                />
                            </div>
                        </template>

                        {{-- PANGKAT FORM --}}
                        <template x-if="modalType === 'pangkat'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan</label>
                                    <x-form.select x-model="newPangkat.golongan_id" required>
                                        <option value="">-- Pilih Golongan --</option>
                                        @foreach($golonganOptions as $gol)
                                            <option value="{{ $gol->id }}">{{ $gol->nama }} ({{ $gol->pangkat }})</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pangkat</label>
                                    <input type="text" x-model="newPangkat.no_sk" required placeholder="SK-321-KP-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                                    <input type="date" x-model="newPangkat.tanggal_sk" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Golongan</label>
                                    <input type="date" x-model="newPangkat.tmt_pangkat" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label for="file_sk_pangkat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                        Upload SK <span class="font-normal normal-case text-muted">(opsional)</span>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <label for="file_sk_pangkat" class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition-colors hover:bg-primary/10 font-sans">
                                            Pilih File
                                        </label>
                                        <input type="file" id="file_sk_pangkat" class="hidden" accept=".pdf,.jpg,.jpeg,.png"
                                            @change="newPangkat.file_sk = $event.target.files[0] || null">
                                        <span class="min-w-0 flex-1 truncate text-xs font-sans" :class="newPangkat.file_sk ? 'text-ink' : 'text-muted'"
                                            x-text="newPangkat.file_sk ? newPangkat.file_sk.name : 'Belum ada file dipilih'"></span>
                                        <button x-show="newPangkat.file_sk" type="button"
                                            @click="newPangkat.file_sk = null; document.getElementById('file_sk_pangkat').value = ''"
                                            class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                                    </div>
                                    <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/JPEG/PNG, maks. 10 MB.</p>
                                </div>
                            </div>
                        </template>

                        {{-- JABATAN FORM --}}
                        <template x-if="modalType === 'jabatan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan</label>
                                    <x-form.select x-model="newJabatan.jabatan_id" required>
                                        <option value="">-- Pilih Jabatan --</option>
                                        @foreach($jabatanOptions->where('is_active', true) as $jabatan)
                                            <option value="{{ $jabatan->id }}">{{ $jabatan->nama }}{{ $jabatan->jenisJabatan ? ' - '.$jabatan->jenisJabatan->nama : '' }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan</label>
                                    <x-form.select x-model="newJabatan.jenis_jabatan_id">
                                        <option value="">-- Pilih Jenis Jabatan --</option>
                                        @foreach($jenisJabatanOptions as $jj)
                                            <option value="{{ $jj->id }}">{{ $jj->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kelas Jabatan</label>
                                    <input type="text" x-model="newJabatan.kelas_jabatan" placeholder="8" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Eselon (Opsional)</label>
                                    <x-form.select x-model="newJabatan.eselon_id">
                                        <option value="">-- Pilih Eselon --</option>
                                        @foreach($eselonOptions as $esl)
                                            <option value="{{ $esl->id }}">{{ $esl->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja</label>
                                    <x-form.select x-model="newJabatan.unit_kerja_id" required>
                                        <option value="">-- Pilih Unit Kerja --</option>
                                        @foreach($unitKerjaOptions as $unit)
                                            <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Jabatan</label>
                                    <input type="text" x-model="newJabatan.no_sk" required placeholder="SK-910-JAB-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                                    <input type="date" x-model="newJabatan.tanggal_sk" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Jabatan</label>
                                    <input type="date" x-model="newJabatan.tmt_jabatan" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label for="file_sk_jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                        Upload SK <span class="font-normal normal-case text-muted">(opsional)</span>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <label for="file_sk_jabatan" class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition-colors hover:bg-primary/10 font-sans">
                                            Pilih File
                                        </label>
                                        <input type="file" id="file_sk_jabatan" class="hidden" accept=".pdf,.jpg,.jpeg,.png"
                                            @change="newJabatan.file_sk = $event.target.files[0] || null">
                                        <span class="min-w-0 flex-1 truncate text-xs font-sans" :class="newJabatan.file_sk ? 'text-ink' : 'text-muted'"
                                            x-text="newJabatan.file_sk ? newJabatan.file_sk.name : 'Belum ada file dipilih'"></span>
                                        <button x-show="newJabatan.file_sk" type="button"
                                            @click="newJabatan.file_sk = null; document.getElementById('file_sk_jabatan').value = ''"
                                            class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                                    </div>
                                    <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/JPEG/PNG, maks. 10 MB.</p>
                                </div>
                            </div>
                        </template>

                        {{-- KGB FORM --}}
                        <template x-if="modalType === 'kgb'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Gaji Pokok Baru</label>
                                    <input type="number" x-model="newKgb.gaji_pokok" required placeholder="4100000" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Surat KGB</label>
                                    <input type="text" x-model="newKgb.no_sk" required placeholder="KGB-012-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Surat Terbit</label>
                                    <input type="date" x-model="newKgb.tanggal_sk" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT KGB</label>
                                    <input type="date" x-model="newKgb.tmt_kgb" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label for="file_sk_kgb" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                        Upload SK <span class="font-normal normal-case text-muted">(opsional)</span>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <label for="file_sk_kgb" class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition-colors hover:bg-primary/10 font-sans">
                                            Pilih File
                                        </label>
                                        <input type="file" id="file_sk_kgb" class="hidden" accept=".pdf,.jpg,.jpeg,.png"
                                            @change="newKgb.file_sk = $event.target.files[0] || null">
                                        <span class="min-w-0 flex-1 truncate text-xs font-sans" :class="newKgb.file_sk ? 'text-ink' : 'text-muted'"
                                            x-text="newKgb.file_sk ? newKgb.file_sk.name : 'Belum ada file dipilih'"></span>
                                        <button x-show="newKgb.file_sk" type="button"
                                            @click="newKgb.file_sk = null; document.getElementById('file_sk_kgb').value = ''"
                                            class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                                    </div>
                                    <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/JPEG/PNG, maks. 10 MB.</p>
                                </div>
                            </div>
                        </template>

                        {{-- DISIPLIN FORM --}}
                        <template x-if="modalType === 'disiplin'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Hukuman</label>
                                    <x-form.select x-model="newDisiplin.jenis_hukuman">
                                        <option value="Ringan">Ringan</option>
                                        <option value="Sedang">Sedang</option>
                                        <option value="Berat">Berat</option>
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Deskripsi Pelanggaran</label>
                                    <textarea x-model="newDisiplin.deskripsi" required placeholder="Keterlambatan absensi berulang" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none" rows="2"></textarea>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Hukuman</label>
                                    <input type="text" x-model="newDisiplin.no_sk" required placeholder="SK-HD-023-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                                    <input type="date" x-model="newDisiplin.tanggal_sk" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                        File SK
                                        <span class="font-normal normal-case text-muted">(opsional)</span>
                                    </label>

                                    {{-- Tab toggle: Dari Arsip | Unggah Baru --}}
                                    <div class="flex gap-0.5 rounded-lg border border-border bg-soft p-0.5 w-fit">
                                        <button type="button"
                                            @click="disiplinFileMode = 'arsip'; newDisiplin.file_sk = null; document.getElementById('file_sk_disiplin').value = ''"
                                            :class="disiplinFileMode === 'arsip' ? 'bg-white shadow-sm text-ink' : 'text-muted hover:text-ink'"
                                            class="rounded-md px-3 py-1 text-xs font-semibold font-sans transition-all cursor-pointer"
                                        >Dari Arsip</button>
                                        <button type="button"
                                            @click="disiplinFileMode = 'baru'; newDisiplin.dokumen_id = ''; newDisiplin.no_sk = ''; newDisiplin.tanggal_sk = ''"
                                            :class="disiplinFileMode === 'baru' ? 'bg-white shadow-sm text-ink' : 'text-muted hover:text-ink'"
                                            class="rounded-md px-3 py-1 text-xs font-semibold font-sans transition-all cursor-pointer"
                                        >Unggah Baru</button>
                                    </div>

                                    {{-- Panel: Dari Arsip --}}
                                    <div x-show="disiplinFileMode === 'arsip'" class="space-y-1">
                                        <div x-show="loadingArsip" class="text-xs text-muted font-sans py-1">Memuat daftar arsip...</div>
                                        <template x-if="!loadingArsip">
                                            <div class="space-y-1">
                                                <x-form.select x-model="newDisiplin.dokumen_id"
                                                    @change="
                                                        const dok = arsipDokumen.find(d => d.id == $event.target.value);
                                                        if (dok) {
                                                            if (dok.nomor_dokumen) newDisiplin.no_sk = dok.nomor_dokumen;
                                                            if (dok.tanggal) newDisiplin.tanggal_sk = dok.tanggal;
                                                        }
                                                    "
                                                    class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                                    <option value="">-- Pilih dari Arsip Dokumen --</option>
                                                    <template x-for="dok in arsipDokumen" :key="dok.id">
                                                        <option :value="dok.id"
                                                            x-text="dok.nama_dokumen + (dok.nomor_dokumen ? ' (' + dok.nomor_dokumen + ')' : '') + (dok.tanggal ? ' — ' + dok.tanggal : '')">
                                                        </option>
                                                    </template>
                                                </x-form.select>
                                                <p x-show="arsipDokumen.length === 0" class="text-[10px] text-muted italic font-sans">
                                                    Belum ada arsip SK Hukuman Disiplin untuk pegawai ini.
                                                    <a href="{{ route('dokumen') }}" target="_blank" class="text-primary underline">Unggah di halaman Arsip Dokumen</a>.
                                                </p>
                                            </div>
                                        </template>
                                    </div>

                                    {{-- Panel: Unggah Baru --}}
                                    <div x-show="disiplinFileMode === 'baru'" class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <label for="file_sk_disiplin" class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition-colors hover:bg-primary/10 font-sans">
                                                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                                </svg>
                                                Pilih File
                                            </label>
                                            <input
                                                type="file"
                                                id="file_sk_disiplin"
                                                class="hidden"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                @change="newDisiplin.file_sk = $event.target.files[0] || null"
                                            >
                                            <span
                                                class="min-w-0 flex-1 truncate text-xs font-sans"
                                                :class="newDisiplin.file_sk ? 'text-ink' : 'text-muted'"
                                                x-text="newDisiplin.file_sk ? newDisiplin.file_sk.name : 'Belum ada file dipilih'"
                                            ></span>
                                            <button
                                                x-show="newDisiplin.file_sk"
                                                type="button"
                                                @click="newDisiplin.file_sk = null; document.getElementById('file_sk_disiplin').value = ''"
                                                class="shrink-0 text-xs text-danger hover:underline font-sans"
                                            >Hapus</button>
                                        </div>
                                        <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/PNG, maks. 10 MB. File akan masuk ke Arsip Dokumen otomatis.</p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Mulai</label>
                                        <input type="date" x-model="newDisiplin.tanggal_mulai" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Berakhir</label>
                                        <input type="date" x-model="newDisiplin.tanggal_berakhir" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                </div>
                                <p class="text-[10px] text-muted italic font-sans">* Kosongkan tanggal berakhir jika masa berlaku tidak ditentukan (aktif selamanya).</p>
                            </div>
                        </template>

                        {{-- PENDIDIKAN FORM --}}
                        <template x-if="modalType === 'pendidikan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenjang Pendidikan <span class="text-danger">*</span></label>
                                    <x-form.select x-model="newPendidikan.jenjang_id" required>
                                        <option value="">-- Pilih Jenjang --</option>
                                        @foreach($jenjangOptions as $jenjang)
                                            <option value="{{ $jenjang->id }}">{{ $jenjang->nama }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Institusi <span class="text-danger">*</span></label>
                                    <input type="text" x-model="newPendidikan.nama_institusi" required placeholder="Universitas Sam Ratulangi" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi</label>
                                    <select x-model="newPendidikan.program_studi_id" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih Program Studi --</option>
                                        @foreach($programStudiOptions as $programStudi)
                                            <option value="{{ $programStudi->id }}">{{ $programStudi->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tahun Lulus <span class="text-danger">*</span></label>
                                        <input type="number" x-model="newPendidikan.tahun_lulus" required placeholder="2007" min="1900" :max="new Date().getFullYear() + 1" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Ijazah</label>
                                        <input type="text" x-model="newPendidikan.no_ijazah" placeholder="IJZ-S1-MAN-2007" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div class="border-t border-border pt-6 flex justify-end gap-3 mt-6">
                            <button
                                type="button" 
                                @click="showModal = false" 
                                x-bind:disabled="isSubmitting"
                                class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink hover:bg-soft transition font-sans cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                </svg>
                                Batal
                            </button>
                            
                            <button
                                type="submit" 
                                x-bind:disabled="isSubmitting"
                                class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm font-sans cursor-pointer min-w-[130px] disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span class="flex items-center" x-show="!isSubmitting">
                                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    Simpan
                                </span>
                                <span class="flex items-center justify-center gap-2" x-show="isSubmitting" style="display: none;">
                                    <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Menyimpan...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    {{-- ============================================================ --}}
    {{-- MODAL EDIT RIWAYAT PENDIDIKAN                                --}}
    {{-- ============================================================ --}}
    <div
        x-show="showEditPendidikan"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4"
        style="display:none;"
        @keydown.escape.window="showEditPendidikan = false"
    >
        <div
            @click.outside="showEditPendidikan = false"
            class="w-full max-w-lg rounded-2xl bg-surface shadow-xl border border-border overflow-hidden"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-border">
                <h3 class="text-sm font-bold text-ink font-sans">Edit Riwayat Pendidikan</h3>
                <button type="button" @click="showEditPendidikan = false" class="text-muted hover:text-ink transition cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <form @submit.prevent="submitEditPendidikan()" class="px-6 py-5 space-y-4">
                {{-- Error --}}
                <div x-show="editPendidikanError" class="rounded-lg bg-danger/10 border border-danger/20 px-3 py-2 text-xs text-danger font-sans" x-text="editPendidikanError"></div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenjang Pendidikan <span class="text-danger">*</span></label>
                    <x-form.select x-model="editPendidikanForm.jenjang_id" required>
                        <option value="">-- Pilih Jenjang --</option>
                        @foreach($jenjangOptions as $jenjang)
                            <option value="{{ $jenjang->id }}">{{ $jenjang->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Institusi <span class="text-danger">*</span></label>
                    <input type="text" x-model="editPendidikanForm.nama_institusi" required placeholder="Universitas Sam Ratulangi" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi</label>
                    <select x-model="editPendidikanForm.program_studi_id" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <option value="">-- Pilih Program Studi --</option>
                        @foreach($educationProgramStudiOptions as $programStudi)
                            <option value="{{ $programStudi->id }}" :disabled="{{ $programStudi->is_active ? 'false' : 'editPendidikanForm.program_studi_id !== \''. $programStudi->id .'\'' }}">{{ $programStudi->nama }}{{ ! $programStudi->is_active ? ' (Nonaktif)' : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tahun Lulus <span class="text-danger">*</span></label>
                        <input type="number" x-model="editPendidikanForm.tahun_lulus" required placeholder="2007" min="1900" :max="new Date().getFullYear() + 1" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Ijazah</label>
                        <input type="text" x-model="editPendidikanForm.no_ijazah" placeholder="IJZ-S1-2007" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                </div>

                {{-- Footer --}}
                <div class="flex justify-end gap-3 pt-2 border-t border-border mt-4">
                    <button type="button" @click="showEditPendidikan = false" :disabled="isUpdatingPendidikan"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink hover:bg-soft transition font-sans cursor-pointer disabled:opacity-50">
                        Batal
                    </button>
                    <button type="submit" :disabled="isUpdatingPendidikan"
                        class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm font-sans cursor-pointer min-w-[120px] disabled:opacity-50">
                        <span x-show="!isUpdatingPendidikan">Simpan Perubahan</span>
                        <span x-show="isUpdatingPendidikan" class="flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Menyimpan...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if($canDeactivateEmployee)
    <x-ui.modal show="showDeactivateModal" title="Nonaktifkan Pegawai" closeAction="showDeactivateModal = false" maxWidth="sm">
        <form method="POST" action="{{ route('pegawai.destroy', $p->id) }}" class="space-y-4">
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

    {{-- ============================================================ --}}
    {{-- MODAL UPLOAD / GANTI BERKAS SK (PANGKAT, JABATAN, KGB)        --}}
    {{-- ============================================================ --}}
    <x-ui.modal
        show="showUploadSkModal"
        title="Upload / Ganti Berkas SK"
        closeAction="if(!isUploadingSk) showUploadSkModal = false"
        maxWidth="md"
    >
        <div class="space-y-4 text-xs font-sans">
            {{-- Info Riwayat Yang Dipilih --}}
            <div class="rounded-xl border border-border bg-soft/40 p-3.5 space-y-1.5">
                <template x-if="uploadSkType === 'pangkat'">
                    <div>
                        <div class="font-bold text-ink text-sm" x-text="'Golongan ' + (uploadSkRecord?.golongan || '-')"></div>
                        <div class="text-muted text-xs mt-0.5" x-text="'Nomor SK: ' + (uploadSkRecord?.no_sk || '-') + ' • TMT: ' + formatDate(uploadSkRecord?.tmt)"></div>
                    </div>
                </template>
                <template x-if="uploadSkType === 'jabatan'">
                    <div>
                        <div class="font-bold text-ink text-sm" x-text="(uploadSkRecord?.jabatan || '-')"></div>
                        <div class="text-muted text-xs mt-0.5" x-text="'Nomor SK: ' + (uploadSkRecord?.no_sk || '-') + ' • Unit: ' + (uploadSkRecord?.unit || '-')"></div>
                    </div>
                </template>
                <template x-if="uploadSkType === 'kgb'">
                    <div>
                        <div class="font-bold text-ink text-sm" x-text="'Gaji Pokok: ' + (uploadSkRecord?.gaji || '-')"></div>
                        <div class="text-muted text-xs mt-0.5" x-text="'Nomor Surat: ' + (uploadSkRecord?.no_sk || '-') + ' • TMT: ' + formatDate(uploadSkRecord?.tmt)"></div>
                    </div>
                </template>
                <template x-if="uploadSkType === 'pengangkatan'">
                    <div>
                        <div class="font-bold text-ink text-sm" x-text="'SK Pengangkatan ' + (uploadSkRecord?.jenis_pengangkatan || '-')"></div>
                        <div class="text-muted text-xs mt-0.5" x-text="'Nomor SK: ' + (uploadSkRecord?.no_sk || '-') + ' • TMT: ' + formatDate(uploadSkRecord?.tmt_pengangkatan)"></div>
                    </div>
                </template>
            </div>

            {{-- Pesan Error --}}
            <div x-show="uploadSkError" class="rounded-lg bg-danger/10 border border-danger/20 p-2.5 text-danger font-medium text-xs" x-text="uploadSkError"></div>

            {{-- Input File --}}
            <div class="space-y-1.5">
                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pilih Berkas SK <span class="text-danger">*</span></label>
                <div class="border-2 border-dashed border-border rounded-lg p-5 bg-soft/50 text-center relative hover:border-primary transition">
                    <input type="file" id="upload_file_sk_input"
                           accept=".pdf,.jpg,.jpeg,.png"
                           @change="uploadSkFile = $event.target.files[0] || null"
                           class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                    <svg class="mx-auto h-9 w-9 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                    </svg>
                    <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK (PDF/JPG/PNG, maks 10MB)</p>
                    <template x-if="uploadSkFile">
                        <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                            <svg class="w-4 h-4 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span class="font-medium" x-text="uploadSkFile.name"></span>
                            <span class="text-muted" x-text="'(' + (uploadSkFile.size ? (uploadSkFile.size / 1024 / 1024).toFixed(2) + ' MB' : '') + ')'"></span>
                            <button type="button" @click.stop="uploadSkFile = null; document.getElementById('upload_file_sk_input').value = ''" class="ml-1 text-danger hover:underline cursor-pointer">Hapus</button>
                        </div>
                    </template>
                    <template x-if="!uploadSkFile && uploadSkRecord?.download_url">
                        <div class="mt-2 text-xs text-muted">
                            Berkas saat ini: <a :href="uploadSkRecord.download_url" target="_blank" class="text-primary hover:underline font-semibold">Unduh SK</a>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Footer Buttons --}}
            <div class="flex items-center justify-end gap-2 border-t border-border pt-4 mt-2">
                <button type="button"
                        @click="showUploadSkModal = false"
                        :disabled="isUploadingSk"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-soft transition font-sans cursor-pointer disabled:opacity-50">
                    Batal
                </button>
                <button type="button"
                        @click="submitUploadSk"
                        :disabled="isUploadingSk || !uploadSkFile"
                        class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm font-sans cursor-pointer disabled:opacity-50">
                    <span x-show="!isUploadingSk" x-text="uploadSkRecord?.download_url ? 'Simpan Perubahan' : 'Upload Berkas'"></span>
                    <span x-show="isUploadingSk" class="inline-flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <span>Mengunggah...</span>
                    </span>
                </button>
            </div>
        </div>
    </x-ui.modal>

    {{-- ============================================================ --}}
    {{-- MODAL TAMBAH / EDIT DATA PENGANGKATAN PERTAMA                --}}
    {{-- ============================================================ --}}
    <x-ui.modal
        show="showAppointmentModal"
        title="Data & SK Pengangkatan Pertama"
        closeAction="if(!isSavingAppointment) showAppointmentModal = false"
        maxWidth="lg"
    >
        <form @submit.prevent="submitAppointment()" class="space-y-4 text-xs font-sans">
            {{-- Error Message --}}
            <div x-show="appointmentError" class="rounded-lg bg-danger/10 border border-danger/20 p-2.5 text-danger font-medium text-xs" x-text="appointmentError"></div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- Jenis Pengangkatan --}}
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Pengangkatan <span class="text-danger">*</span></label>
                    <div class="relative">
                        <select x-model="appointmentForm.jenis_pengangkatan" required class="w-full appearance-none rounded-lg border border-border bg-surface px-3 py-2 pr-10 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                            <option value="CPNS">CPNS</option>
                            <option value="PNS">PNS</option>
                            <option value="PPPK">PPPK</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- TMT Pengangkatan --}}
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pengangkatan <span class="text-danger">*</span></label>
                    <input type="date" x-model="appointmentForm.tmt_pengangkatan" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                </div>

                {{-- Nomor SK Pengangkatan --}}
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pengangkatan <span class="text-danger">*</span></label>
                    <input type="text" x-model="appointmentForm.no_sk" required placeholder="SK-882-KP-2024" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                </div>

                {{-- Tanggal SK Terbit --}}
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit <span class="text-danger">*</span></label>
                    <input type="date" x-model="appointmentForm.tanggal_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                </div>

                {{-- File SK Pengangkatan --}}
                <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                        File SK Pengangkatan
                        <span x-show="!isEditingAppointment" class="text-muted font-normal">(Opsional)</span>
                    </label>
                    <div class="mt-1">
                        <div class="border-2 border-dashed border-border rounded-lg p-5 bg-soft/50 text-center relative hover:border-primary transition">
                            <input type="file" id="appointment_file_sk_input" name="file_sk_pengangkatan"
                                   accept=".pdf,.jpg,.jpeg,.png"
                                   @change="appointmentForm.file_sk = $event.target.files[0] || null"
                                   class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                            <svg class="mx-auto h-9 w-9 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                            </svg>
                            <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Pengangkatan (PDF/JPG/PNG, maks 10MB)</p>
                            <template x-if="appointmentForm.file_sk">
                                <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                                    <svg class="w-4 h-4 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    <span class="font-medium" x-text="appointmentForm.file_sk.name"></span>
                                    <span class="text-muted" x-text="'(' + (appointmentForm.file_sk.size ? (appointmentForm.file_sk.size / 1024 / 1024).toFixed(2) + ' MB' : '') + ')'"></span>
                                    <button type="button" @click.stop="appointmentForm.file_sk = null; document.getElementById('appointment_file_sk_input').value = ''" class="ml-1 text-danger hover:underline cursor-pointer">Hapus</button>
                                </div>
                            </template>
                            <template x-if="!appointmentForm.file_sk && appointmentData?.download_url">
                                <div class="mt-2 text-xs text-muted">
                                    Berkas saat ini: <a :href="appointmentData.download_url" target="_blank" class="text-primary hover:underline font-semibold" x-text="appointmentData.file_sk ? appointmentData.file_sk.split('/').pop() : 'Unduh SK'"></a>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Footer Buttons --}}
            <div class="flex items-center justify-end gap-2 border-t border-border pt-4 mt-4">
                <button type="button"
                        @click="showAppointmentModal = false"
                        :disabled="isSavingAppointment"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-soft transition font-sans cursor-pointer disabled:opacity-50">
                    Batal
                </button>
                <button type="submit"
                        :disabled="isSavingAppointment"
                        class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm font-sans cursor-pointer min-w-[120px] disabled:opacity-50">
                    <span x-show="!isSavingAppointment" x-text="isEditingAppointment ? 'Simpan Perubahan' : 'Simpan Data'"></span>
                    <span x-show="isSavingAppointment" class="inline-flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <span>Menyimpan...</span>
                    </span>
                </button>
            </div>
        </form>
    </x-ui.modal>
</div>{{-- /x-data utama --}}

</div>
