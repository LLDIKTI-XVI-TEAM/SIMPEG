<div>

    @php
        $allDocuments   = collect($p->documents ?? []);
        $riwayatDokumen = $allDocuments;

        $estimasiPangkatNext = $p->tanggal_kenaikan_pangkat_berikutnya
            ? \Carbon\Carbon::parse($p->tanggal_kenaikan_pangkat_berikutnya)->format('d-m-Y')
            : '-';
        $estimasiKgbNext = $p->tanggal_kgb_berikutnya
            ? \Carbon\Carbon::parse($p->tanggal_kgb_berikutnya)->format('d-m-Y')
            : '-';

        $now = \Carbon\Carbon::now();

        // Gunakan estimasi tanggal pensiun dari controller (sudah prioritaskan manual/BUP)
        $pensiunDate = $estimasiTanggalPensiun;
        $estimasiPensiun = $pensiunDate ? $pensiunDate->format('d-m-Y') : '-';

        $sisaPensiunStr = '-';
        if ($pensiunDate) {
            if ($pensiunDate->isFuture()) {
                $diff = $now->diff($pensiunDate);
                $sisaPensiunStr = $diff->y . ' Tahun, ' . $diff->m . ' Bulan lagi';
            } else {
                if ($pensiunDate->isFuture()) {
                    $diff = $now->diff($pensiunDate);
                    $sisaPensiunStr = $diff->y . ' Tahun, ' . $diff->m . ' Bulan lagi';
                } else {
                    $sisaPensiunStr = 'Memasuki Usia Pensiun';
                }
            }
        }

        $canAssignSupervisor = auth()->check()
            && in_array(auth()->user()->role, ['super_admin', 'admin_kepegawaian'], true)
            && auth()->user()->hasPermission('employees.update');
    @endphp

    <div x-data="{
        activeTab: new URLSearchParams(window.location.search).get('tab') || 'profile',
        kinerjaBaik: {{ $p->is_kinerja_baik ? 'true' : 'false' }},
        kinerjaEndpoint: @js(route('pegawai.kinerja.update', $p->id)),
        isUpdatingKinerja: false,
        satyalancanaEligible: {{ $p->is_satyalancana_eligible ? 'true' : 'false' }},
        satyalancanaNote: @js($p->satyalancana_note ?? ''),
        satyalancanaEndpoint: @js(route('pegawai.satyalancana.update', $p->id)),
        isUpdatingSatyalancana: false,
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

        keluargaList: {{ ($p->families ?? collect())->map(fn($f) => ['id' => $f->id, 'nama_anggota' => $f->nama_anggota, 'hubungan' => $f->hubungan, 'nik' => auth()->user()->role === 'pimpinan' ? null : $f->nik, 'tempat_lahir' => $f->tempat_lahir, 'tanggal_lahir' => $f->tanggal_lahir, 'jenis_kelamin' => $f->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki-laki', 'pekerjaan' => $f->pekerjaan, 'status' => $f->status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung'])->toJson() }},
        keluargaLoading: false,
        isDeletingKeluarga: false,
        pangkatList: {{ $p->rankHistories->map(fn($r) => ['golongan' => $r->golongan->nama ?? '-', 'no_sk' => $r->no_sk, 'tgl_sk' => $r->tanggal_sk, 'tmt' => $r->tmt_pangkat])->toJson() }},
        jabatanList: {{ $p->positionHistories->map(fn($j) => ['jabatan' => $j->jabatan?->nama ?? $j->nama_jabatan, 'unit' => $j->unitKerja->nama ?? '-', 'kelas_jabatan' => $j->kelas_jabatan, 'no_sk' => $j->no_sk, 'tgl_sk' => $j->tanggal_sk, 'tmt' => $j->tmt_jabatan])->toJson() }},
        kgbList: {{ $p->salaryHistories->map(fn($s) => ['gaji' => 'Rp ' . number_format($s->gaji_pokok, 0, ',', '.'), 'no_sk' => $s->no_sk, 'tgl_sk' => $s->tanggal_sk, 'tmt' => $s->tmt_kgb])->toJson() }},
        disiplinList: {{ $p->disciplineRecords->map(fn($d) => ['id' => $d->id, 'jenis' => $d->jenis_hukuman, 'alasan' => $d->deskripsi, 'no_sk' => $d->no_sk, 'tgl_sk' => $d->tanggal_sk ? \Carbon\Carbon::parse($d->tanggal_sk)->format('d-m-Y') : '-', 'masa' => ($d->tanggal_mulai ? \Carbon\Carbon::parse($d->tanggal_mulai)->format('d-m-Y') : '-') . ' s/d ' . ($d->tanggal_berakhir ? \Carbon\Carbon::parse($d->tanggal_berakhir)->format('d-m-Y') : 'Sekarang'), 'is_active' => $d->is_active])->toJson() }},
        pendidikanList: {{ ($p->educationHistories ?? collect())->map(fn($e) => ['id' => $e->id, 'jenjang_id' => $e->jenjang_id, 'tingkat' => $e->jenjang?->urutan ?? $e->tingkat ?? '-', 'institusi' => $e->nama_institusi ?? '-', 'prodi' => $e->jurusan ?? '-', 'lulus' => $e->tahun_lulus ?? '-', 'no_ijazah' => $e->no_ijazah ?? '-'])->toJson() }},
        pendidikanLoading: false,
        showEditPendidikan: false,
        showRiwayatStatus: false,
        editingPendidikan: null,
        editPendidikanError: '',
        editPendidikanForm: { jenjang_id: '', nama_institusi: '', jurusan: '', tahun_lulus: '', no_ijazah: '' },
        isUpdatingPendidikan: false,
        isDeletingPendidikan: false,
        
        // Form states
        newKeluarga: { nama_anggota: '', hubungan: 'Istri', nik: '', tempat_lahir: '', tanggal_lahir: '', jenis_kelamin: 'P', status_tunjangan: '0', pekerjaan: '' },
        newPangkat: { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '' },
        newJabatan: { jabatan_id: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', kelas_jabatan: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '' },
        newKgb: { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '' },
        newDisiplin: { jenis_hukuman: 'Ringan', deskripsi: '', no_sk: '', tanggal_sk: '', tanggal_mulai: '', tanggal_berakhir: '', file_sk: null, dokumen_id: '' },
        newPendidikan: { jenjang_id: '', nama_institusi: '', jurusan: '', tahun_lulus: '', no_ijazah: '' },

        // Upload berkas lainnya (KTP/KK, Ijazah, Lainnya) langsung dari tab Dokumen SK
        showUploadBerkas: false,
        isUploadingBerkas: false,
        uploadBerkasError: '',
        uploadBerkasErrors: {},
        newBerkas: { nama_dokumen: '', kategori_dokumen: 'ktp_kk', nomor_dokumen: '', tanggal_terbit: '', keterangan: '', file: null },
        dokumenList: {{ $riwayatDokumen->map(fn($d) => [
            'id'             => $d->id,
            'nama_dokumen'   => $d->nama_dokumen,
            'jenis_dokumen'  => $d->jenis_dokumen,
            'kategori_label' => \App\Support\Documents\DocumentCategory::label($d->jenis_dokumen),
            'nomor_dokumen'  => $d->nomor_dokumen,
            'tanggal_dokumen'=> $d->tanggal_dokumen ? \Carbon\Carbon::parse($d->tanggal_dokumen)->format('d-m-Y') : null,
            'file_size'      => $d->fileSizeLabel(),
            'file_path'      => $d->file_path,
            'keterangan'     => $d->keterangan,
            'detail_url'     => route('dokumen.show', $d->id),
            'download_url'   => route('dokumen.download', $d->id),
        ])->toJson() }},

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
                    this.dokumenList.unshift(json.document);
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
        _pendidikanCacheKey: 'pendidikan_{{ $p->id }}',

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
                    hubungan:      f.hubungan,
                    nik:           f.nik,
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
            // Coba baca dari sessionStorage terlebih dahulu.
            const cached = sessionStorage.getItem(this._pendidikanCacheKey);
            if (cached) {
                try {
                    this.pendidikanList = JSON.parse(cached);
                    return;
                } catch (e) {
                    sessionStorage.removeItem(this._pendidikanCacheKey);
                }
            }
            this.pendidikanLoading = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/riwayat-pendidikan`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const json = await res.json();
                this.pendidikanList = json.histories ?? [];
                sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify(this.pendidikanList));
            } catch (e) {
                console.error('Gagal memuat riwayat pendidikan:', e);
            } finally {
                this.pendidikanLoading = false;
            }
        },

        openEditPendidikan(edu) {
            this.editingPendidikan = edu;
            this.editPendidikanError = '';
            this.editPendidikanForm = {
                jenjang_id:     edu.jenjang_id ?? '',
                nama_institusi: edu.institusi ?? '',
                jurusan:        edu.prodi ?? '',
                tahun_lulus:    edu.lulus ?? '',
                no_ijazah:      edu.no_ijazah ?? '',
            };
            this.showEditPendidikan = true;
        },

        async submitEditPendidikan() {
            this.editPendidikanError = '';
            this.isUpdatingPendidikan = true;
            try {
                const res = await fetch(`/api/v1/pegawai/{{ $p->id }}/riwayat-pendidikan/${this.editingPendidikan.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(this.editPendidikanForm),
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
                        tingkat:   h.tingkat,
                        institusi: h.nama_institusi,
                        prodi:     h.jurusan ?? '-',
                        lulus:     h.tahun_lulus,
                        no_ijazah: h.no_ijazah ?? '-',
                    };
                }
                sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify(this.pendidikanList));
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
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    this.toast = { show: true, message: err.message ?? 'Gagal menghapus riwayat pendidikan.', type: 'error' };
                    return;
                }
                this.pendidikanList.splice(index, 1);
                sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify(this.pendidikanList));
                this.toast = { show: true, message: 'Riwayat pendidikan berhasil dihapus.', type: 'success' };
                setTimeout(() => this.toast.show = false, 3000);
            } catch (e) {
                this.toast = { show: true, message: 'Terjadi kesalahan jaringan. Coba lagi.', type: 'error' };
            } finally {
                this.isDeletingPendidikan = false;
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
                        const fmt = (d) => d ? d.split('-').reverse().join('-') : '-';
                        this.disiplinList.unshift({
                            id:        r.id,
                            jenis:     r.jenis_hukuman,
                            alasan:    r.deskripsi,
                            no_sk:     r.no_sk,
                            tgl_sk:    fmt(r.tanggal_sk),
                            masa:      fmt(r.tanggal_mulai) + ' s/d ' + (r.tanggal_berakhir ? fmt(r.tanggal_berakhir) : 'Sekarang'),
                            is_active: r.is_active,
                        });
                        this.newDisiplin = { jenis_hukuman: 'Ringan', deskripsi: '', no_sk: '', tanggal_sk: '', tanggal_mulai: '', tanggal_berakhir: '', file_sk: null, dokumen_id: '' };
                        this.disiplinFileMode = 'arsip';
                    } else if (this.modalType === 'kgb') {
                        this.kgbList.unshift({
                            gaji: 'Rp ' + parseInt(this.newKgb.gaji_pokok).toLocaleString('id-ID'),
                            no_sk: this.newKgb.no_sk,
                            tgl_sk: this.newKgb.tanggal_sk,
                            tmt: this.newKgb.tmt_kgb
                        });
                        this.newKgb = { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '' };
                    } else if (this.modalType === 'jabatan') {
                        this.jabatanList.unshift({
                            jabatan: '-',
                            unit: '-',
                            kelas_jabatan: this.newJabatan.kelas_jabatan,
                            no_sk: this.newJabatan.no_sk,
                            tgl_sk: this.newJabatan.tanggal_sk,
                            tmt: this.newJabatan.tmt_jabatan
                        });
                        this.newJabatan = { jabatan_id: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', kelas_jabatan: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '' };
                    } else if (this.modalType === 'pangkat') {
                        this.pangkatList.unshift({
                            golongan: '-', // Idealnya ini ambil dari nama referensi golongan
                            no_sk: this.newPangkat.no_sk,
                            tgl_sk: this.newPangkat.tanggal_sk,
                            tmt: this.newPangkat.tmt_pangkat
                        });
                        this.newPangkat = { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '' };
                    } else if (this.modalType === 'keluarga') {
                        const f = result.family;
                        this.keluargaList.unshift({
                            id: f.id,
                            nama_anggota: f.nama_anggota,
                            hubungan: f.hubungan,
                            nik: f.nik,
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
                            prodi:          h.jurusan ?? '-',
                            lulus:          h.tahun_lulus,
                            no_ijazah:      h.no_ijazah ?? '-',
                        });
                        // Perbarui cache sessionStorage agar navigasi kembali tetap sinkron.
                        sessionStorage.setItem(this._pendidikanCacheKey, JSON.stringify(this.pendidikanList));
                        this.newPendidikan = { jenjang_id: '', nama_institusi: '', jurusan: '', tahun_lulus: '', no_ijazah: '' };
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
        
        {{-- BREADCRUMBS & DYNAMIC ALERT --}}
        {{-- BREADCRUMBS & TOP HEADER ACTIONS --}}
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-2">
            <div>
                <h2 class="mb-1 text-2xl font-extrabold text-ink tracking-tight font-sans">Detail Pegawai</h2>
                <nav class="flex items-center gap-1.5 text-xs text-muted mb-4">
                    <a href="{{ auth()->user()->role === 'pimpinan' ? route('pimpinan.dashboard') : route('dashboard') }}" wire:navigate class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <a href="{{ auth()->user()->role === 'pimpinan' ? route('pimpinan.pegawai.index') : route('data-pegawai') }}" wire:navigate class="transition-colors hover:text-ink">Data Pegawai</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Detail Pegawai</span>
                </nav>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <a href="javascript:void(0)" onclick="if(document.referrer.includes(window.location.hostname)) { history.back(); } else { window.location.href = '{{ auth()->user()->role === 'pimpinan' ? route('pimpinan.pegawai.index') : route('data-pegawai') }}'; }" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft shadow-sm">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    Kembali
                </a>
                @if(auth()->user()->role !== 'pimpinan')
                <a href="{{ route('pegawai.edit', $p->id) }}" wire:navigate class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                    </svg>
                    Edit Pegawai
                </a>
                @endif
            </div>
        </div>

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
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            @php
                $fotoUrl = $p->foto_url;
            @endphp
            
            {{-- Header info --}}
            <div class="border-b border-border pb-6 flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 rounded-full border border-border bg-soft flex items-center justify-center overflow-hidden shrink-0">
                        @if($fotoUrl)
                            <img
                                src="{{ $fotoUrl }}"
                                alt="Foto {{ $p->nama_dengan_gelar ?? $p->nama_lengkap }}"
                                class="h-full w-full object-cover object-[center_25%]"
                            >
                        @else
                            <div class="flex h-full w-full items-center justify-center bg-primary/10 text-xl font-bold text-primary font-sans uppercase">
                                {{ strtoupper(substr($p->nama_dengan_gelar ?? $p->nama_lengkap, 0, 1)) }}
                            </div>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ $p->nama_dengan_gelar ?? $p->nama_lengkap }}</h2>
                        </div>
                        @if($p->nama_dengan_gelar)
                            <p class="text-xs text-muted font-sans mt-0.5">{{ $p->nama_lengkap }}</p>
                        @endif
                        <p class="text-xs text-muted">NIP. {{ $p->nip }}</p>
                        <div class="flex items-center gap-2 mt-1.5">
                            <x-ui.badge variant="primary" size="md" class="!font-bold">
                                {{ $p->jenisPegawai->nama ?? '-' }}
                            </x-ui.badge>
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
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB NAVIGATION --}}
            <div class="border-b border-border flex gap-4 md:gap-6 overflow-x-auto pb-1 select-none" aria-label="Navigasi detail pegawai" aria-orientation="vertical">
                <button @click="activeTab = 'profile'" :class="activeTab === 'profile' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Profil</button>
                <button @click="activeTab = 'keluarga'" :class="activeTab === 'keluarga' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Keluarga</button>
                <button @click="activeTab = 'kepangkatan'" :class="activeTab === 'kepangkatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Kepangkatan</button>
                <button @click="activeTab = 'jabatan'" :class="activeTab === 'jabatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Jabatan</button>
                <button @click="activeTab = 'kgb'" :class="activeTab === 'kgb' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">KGB</button>
                <button @click="activeTab = 'disiplin'" :class="activeTab === 'disiplin' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Hukuman Disiplin</button>
                <button @click="activeTab = 'pendidikan'" :class="activeTab === 'pendidikan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Pendidikan</button>
                <button @click="activeTab = 'pengangkatan'" :class="activeTab === 'pengangkatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Pengangkatan</button>
                <button @click="activeTab = 'docs'" :class="activeTab === 'docs' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'" class="text-xs md:text-sm transition-colors cursor-pointer focus:outline-none font-sans shrink-0">Dokumen SK</button>
            </div>

            <p class="history-export-unavailable hidden">Ekspor riwayat tidak tersedia</p>

            {{-- TAB 1: PROFIL LENGKAP --}}
            <div id="pimpinan-panel-info" aria-controls="pimpinan-panel-info" x-show="activeTab === 'profile'" class="space-y-6" x-transition>
                
                {{-- Toggle Flag Kinerja & Kepala Bagian --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-soft/40 rounded-lg p-4 border border-border">
                    {{-- Status Kinerja --}}
                    <div class="p-2">
                        <div class="flex items-start sm:items-center justify-between gap-4">
                            <div>
                            <span class="text-xs font-bold text-ink font-sans block">Toggle Flag "Kinerja Baik"</span>
                            <p class="text-xs text-muted">Flag manual pengganti SKP sementara untuk menentukan eligibility kenaikan pangkat di EWS.</p>
                            <p x-show="isUpdatingKinerja" class="mt-1 text-[10px] text-primary font-sans" style="display: none;">
                                Menyimpan status kinerja.
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer select-none">
                            @if(auth()->user()->role !== 'pimpinan')
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
                                @if(auth()->user()->role !== 'pimpinan')
                                <input type="checkbox" x-model="satyalancanaEligible" @change="updateSatyalancanaEligibility()" :disabled="isUpdatingSatyalancana" aria-label="Toggle Kelayakan Satyalancana" class="sr-only peer">
                                @else
                                <input type="checkbox" x-model="satyalancanaEligible" disabled aria-label="Toggle Kelayakan Satyalancana" class="sr-only peer">
                                @endif
                                <div class="w-11 h-6 bg-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-success"></div>
                            </label>
                        </div>
                        <div class="space-y-1">
                            <label for="satyalancana-note" class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Catatan Manual</label>
                            @if(auth()->user()->role === 'pimpinan')
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
                        @if(auth()->user()->role !== 'pimpinan')
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
                                    label="Tanggal Efektif"
                                    :value="old('effective_date', now()->toDateString())"
                                    required
                                    help="Tanggal mulai penugasan."
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

                {{-- Auto-Kalkulasi Jadwal --}}
                <div class="space-y-3">
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5 flex items-center gap-1.5">
                        Estimasi Jadwal Kepegawaian
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="rounded-lg border border-border bg-surface p-3 shadow-sm text-center">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Kenaikan Pangkat Terdekat</span>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiPangkatNext }}</p>
                            <p class="text-[9px] text-muted font-sans mt-0.5">(Estimasi 4 tahun sejak TMT)</p>
                        </div>
                        <div class="rounded-lg border border-border bg-surface p-3 shadow-sm text-center">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">KGB Terdekat</span>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiKgbNext }}</p>
                            <p class="text-[9px] text-muted font-sans mt-0.5">(Estimasi 2 tahun sejak TMT)</p>
                        </div>
                        <div class="rounded-lg border border-border bg-surface p-3 shadow-sm text-center">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Estimasi Tanggal Pensiun</span>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiPensiun }}</p>
                            <p class="text-[9px] text-danger font-semibold mt-0.5" x-text="'Sisa: ' + '{{ $sisaPensiunStr }}'"></p>
                        </div>
                    </div>
                </div>

                {{-- Detail Biodata --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Identitas & Data Pribadi</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5 sm:col-span-2">
                                <span class="font-semibold text-muted font-sans">Nama dengan Gelar</span>
                                <p class="text-ink font-sans font-semibold">{{ $p->nama_dengan_gelar ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5 sm:col-span-2">
                                <span class="font-semibold text-muted font-sans">Nama Lengkap (tanpa gelar)</span>
                                <p class="text-ink font-sans">{{ $p->nama_lengkap }}</p>
                            </div>
                            @if(auth()->user()->role !== 'pimpinan')
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">NIK (KTP)</span>
                                <p class="text-ink font-bold">{{ $p->nik ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">No. Kartu Keluarga (KK)</span>
                                <p class="text-ink font-bold">{{ $p->no_kk ?? '-' }}</p>
                            </div>
                            @endif
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Tempat / Tanggal Lahir</span>
                                <p class="text-ink font-sans">{{ $p->tempat_lahir ?? '-' }}, {{ isset($p->tanggal_lahir) ? \Carbon\Carbon::parse($p->tanggal_lahir)->format('d-m-Y') : '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Jenis Kelamin</span>
                                <p class="text-ink font-sans">{{ $p->jenis_kelamin === 'L' ? 'Laki-laki' : ($p->jenis_kelamin === 'P' ? 'Perempuan' : '-') }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Agama</span>
                                <p class="text-ink font-sans">{{ $p->agama->nama ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Status Kawin</span>
                                <p class="text-ink font-sans">{{ $p->statusKawin->nama ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Golongan Darah</span>
                                <p class="text-ink font-sans font-bold">{{ $p->golongan_darah ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Kepala Lembaga</span>
                                <p class="text-ink font-sans font-bold">{{ $p->is_kepala_lembaga ? 'Ya' : 'Tidak' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Status Kepegawaian</h3>
                            @if($p->statusHistories && $p->statusHistories->count() > 0)
                            <button type="button" @click="showRiwayatStatus = true" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-white px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary/5 shadow-sm cursor-pointer font-sans">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Lihat Riwayat
                            </button>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Status Saat Ini</span>
                                <p class="text-ink font-sans font-bold">{{ $p->statusPegawai->nama ?? $p->status_aktif ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Tanggal Efektif</span>
                                <p class="text-ink font-sans">{{ $p->status_tanggal ? \Carbon\Carbon::parse($p->status_tanggal)->format('d-m-Y') : '-' }}</p>
                            </div>
                            @if($p->status_keterangan)
                            <div class="space-y-0.5 sm:col-span-2">
                                <span class="font-semibold text-muted font-sans">Keterangan</span>
                                <p class="text-ink font-sans">{{ $p->status_keterangan }}</p>
                            </div>
                            @endif
                            <div class="space-y-0.5 sm:col-span-2">
                                <span class="font-semibold text-muted font-sans">Berkas SK Status</span>
                                @if($p->status_berkas_path)
                                    <p class="text-ink font-sans">
                                        <a href="{{ asset('storage/'.$p->status_berkas_path) }}" target="_blank" class="text-primary hover:underline font-semibold">
                                            {{ $p->status_nomor_berkas ?? 'Lihat Berkas' }}
                                        </a>
                                    </p>
                                @else
                                    <p class="text-muted font-sans">Tidak ada berkas terlampir.</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Kontak & Rumah</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Email Dinas</span>
                                <p class="text-ink font-sans">{{ '-' ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Email Pribadi</span>
                                <p class="text-ink font-sans">{{ $p->email_pribadi ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Nomor HP</span>
                                <p class="text-ink font-sans">{{ $p->no_hp ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Telepon Rumah</span>
                                <p class="text-ink font-sans">{{ $p->no_telepon_rumah ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5 sm:col-span-2">
                                <span class="font-semibold text-muted font-sans">Alamat</span>
                                <p class="text-ink font-sans leading-relaxed">{{ $p->alamat ?? '-' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Jabatan Kerja --}}
                <div class="space-y-4 border-t border-border pt-4">
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Informasi Pekerjaan Utama</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-xs">
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Jabatan Sekarang</span>
                            <p class="text-ink font-sans font-bold">{{ $p->latestPosition()?->jabatan?->nama ?? $p->latestPosition()?->nama_jabatan ?? $p->jabatan_terakhir ?? '-' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Unit Kerja</span>
                            <p class="text-ink font-sans">{{ $p->latestPosition()->unitKerja->nama ?? '-' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Pangkat</span>
                            <p class="text-ink font-sans font-bold">{{ $p->latestRank()->golongan->nama ?? $p->pangkat_terakhir ?? '-' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Golongan Saat Ini</span>
                            <p class="text-ink font-sans font-bold">{{ $p->latestRank()->golongan->kode ?? $p->golongan_terakhir ?? '-' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Kelas Jabatan</span>
                            <p class="text-ink font-sans font-bold">{{ $p->kelas_jabatan_terakhir ?? '-' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">TMT Golongan</span>
                            <p class="text-ink">{{ $p->latestRank()?->tmt_pangkat ? \Carbon\Carbon::parse($p->latestRank()->tmt_pangkat)->format('d-m-Y') : '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 2: DATA KELUARGA --}}
            <div x-show="activeTab === 'keluarga'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Data Keluarga</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Daftar istri/suami dan anak yang tercatat sebagai tanggungan.</p>
                    </div>
                    @if(auth()->user()->role !== 'pimpinan')
                            <button type="button" @click="openModal('keluarga', 'Tambah Anggota Keluarga')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Keluarga
                    </button>
                            @endif
                </div>

                {{-- Loading skeleton --}}
                <div x-show="keluargaLoading" class="flex items-center justify-center py-10 text-xs text-muted font-sans gap-2">
                    <svg class="w-4 h-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Memuat data keluarga...
                </div>

                <div x-show="!keluargaLoading" class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Nama Lengkap & NIK</th>
                                <th class="px-4 py-3">Hubungan</th>
                                <th class="px-4 py-3">TTL</th>
                                <th class="px-4 py-3">Pekerjaan</th>
                                <th class="px-4 py-3">Status</th>
                                @if(auth()->user()->role !== 'pimpinan')
                                        <th class="px-4 py-3 text-right">Aksi</th>
                                        @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="(fam, index) in keluargaList" :key="fam.id">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3">
                                        <p class="font-bold font-sans" x-text="fam.nama_anggota"></p>
                                        <p class="text-[10px] text-muted" x-text="fam.nik ? 'NIK. ' + fam.nik : 'NIK. -'"></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-sans" x-text="fam.hubungan"></p>
                                        <p class="text-[10px] text-muted font-sans" x-text="fam.jenis_kelamin"></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-sans" x-text="fam.tempat_lahir || '-'"></p>
                                        <p class="text-[10px] text-muted" x-text="formatDate(fam.tanggal_lahir)"></p>
                                    </td>
                                    <td class="px-4 py-3 font-sans" x-text="fam.pekerjaan || '-'"></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold"
                                              :class="fam.status === 'Ditanggung' ? 'text-success' : 'text-muted'"
                                              x-text="fam.status"></span>
                                    </td>
                                    @if(auth()->user()->role !== 'pimpinan')
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
                                <td colspan="6" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini belum memiliki data anggota keluarga.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 3: RIWAYAT KEPANGKATAN --}}
            <div x-show="activeTab === 'kepangkatan'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kepangkatan & Golongan</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan kenaikan pangkat reguler maupun pilihan selama masa dinas.</p>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Golongan</th>
                                <th class="px-4 py-3">Nomor SK Pangkat</th>
                                <th class="px-4 py-3">Tanggal SK</th>
                                <th class="px-4 py-3">TMT Pangkat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="p in pangkatList" :key="p.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="p.golongan"></td>
                                    <td class="px-4 py-3" x-text="p.no_sk"></td>
                                    <td class="px-4 py-3" x-text="formatDate(p.tgl_sk)"></td>
                                    <td class="px-4 py-3" x-text="formatDate(p.tmt)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 4: RIWAYAT JABATAN --}}
            <div x-show="activeTab === 'jabatan'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Jabatan & Struktural</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan penugasan jabatan fungsional maupun struktural.</p>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Nama Jabatan</th>
                                <th class="px-4 py-3">Unit Kerja</th>
                                <th class="px-4 py-3">Nomor SK Jabatan</th>
                                <th class="px-4 py-3">Tanggal SK</th>
                                <th class="px-4 py-3">TMT Jabatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="j in jabatanList" :key="j.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="j.jabatan"></td>
                                    <td class="px-4 py-3" x-text="j.unit"></td>
                                    <td class="px-4 py-3" x-text="j.no_sk"></td>
                                    <td class="px-4 py-3" x-text="j.tgl_sk"></td>
                                    <td class="px-4 py-3" x-text="j.tmt"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 5: RIWAYAT KGB --}}
            <div x-show="activeTab === 'kgb'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kenaikan Gaji Berkala (KGB)</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan penyesuaian gaji berkala setiap 2 tahun sekali.</p>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Gaji Pokok Baru</th>
                                <th class="px-4 py-3">Nomor Surat KGB</th>
                                <th class="px-4 py-3">Tanggal Surat</th>
                                <th class="px-4 py-3">TMT KGB</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="k in kgbList" :key="k.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="k.gaji"></td>
                                    <td class="px-4 py-3" x-text="k.no_sk"></td>
                                    <td class="px-4 py-3" x-text="k.tgl_sk"></td>
                                    <td class="px-4 py-3" x-text="k.tmt"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 6: HUKUMAN DISIPLIN --}}
            <div x-show="activeTab === 'disiplin'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Hukuman Disiplin</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan sanksi disiplin pegawai yang mempengaruhi promosi kepegawaian.</p>
                    </div>
                    @if(auth()->user()->role !== 'pimpinan')
                            <button type="button" @click="openModal('disiplin', 'Tambah Hukuman Disiplin')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Hukuman
                    </button>
                            @endif
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Jenis Hukuman</th>
                                <th class="px-4 py-3">Alasan / Pelanggaran</th>
                                <th class="px-4 py-3">Nomor SK</th>
                                <th class="px-4 py-3">Tanggal SK</th>
                                <th class="px-4 py-3">Masa Berlaku</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
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
                                    <td class="px-4 py-3" x-text="d.tgl_sk"></td>
                                    <td class="px-4 py-3" x-text="d.masa"></td>
                                </tr>
                            </template>
                            <tr x-show="disiplinList.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini tidak memiliki riwayat hukuman disiplin.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 7: RIWAYAT PENDIDIKAN --}}
            <div x-show="activeTab === 'pendidikan'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Pendidikan Formal</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Riwayat kualifikasi akademis tertinggi staf.</p>
                    </div>
                    @if(auth()->user()->role !== 'pimpinan')
                            <button type="button" @click="openModal('pendidikan', 'Tambah Riwayat Pendidikan')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Pendidikan
                    </button>
                            @endif
                </div>
                {{-- Loading skeleton --}}
                <div x-show="pendidikanLoading" class="flex items-center justify-center py-10 text-xs text-muted font-sans gap-2">
                    <svg class="w-4 h-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Memuat riwayat pendidikan...
                </div>

                <div x-show="!pendidikanLoading" class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Jenjang</th>
                                <th class="px-4 py-3">Nama Institusi</th>
                                <th class="px-4 py-3">Program Studi</th>
                                <th class="px-4 py-3">Tahun Lulus</th>
                                <th class="px-4 py-3">Nomor Ijazah</th>
                                @if(auth()->user()->role !== 'pimpinan')
                                        <th class="px-4 py-3 text-right">Aksi</th>
                                        @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="(edu, index) in pendidikanList" :key="edu.id ?? edu.no_ijazah">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="edu.tingkat ?? edu.jenjang?.nama ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.institusi ?? edu.nama_institusi ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.prodi ?? edu.jurusan ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.lulus ?? edu.tahun_lulus ?? '-'"></td>
                                    <td class="px-4 py-3" x-text="edu.no_ijazah ?? '-'"></td>
                                    @if(auth()->user()->role !== 'pimpinan')
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
                                <td colspan="6" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini belum memiliki riwayat pendidikan formal.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB 8: DATA PENGANGKATAN --}}
            <div x-show="activeTab === 'pengangkatan'" class="space-y-4" style="display: none;" x-transition>
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Data & SK Pengangkatan Pertama</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Berkas dasar penerimaan kepegawaian sebagai CPNS/PNS/PPPK.</p>
                </div>
                <div class="rounded-lg border border-border bg-soft/30 p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs font-sans">
                        <div class="space-y-2">
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Jenis Pengangkatan:</span>
                                <span class="text-ink font-bold">{{ $p->appointment->jenis_pengangkatan ?? '-' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Nomor SK Pengangkatan:</span>
                                <span class="text-ink font-bold">{{ $p->appointment->no_sk ?? '-' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Tanggal SK Terbit:</span>
                                <span class="text-ink">{{ $p->appointment?->tanggal_sk ? \Carbon\Carbon::parse($p->appointment->tanggal_sk)->format('d-m-Y') : '-' }}</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">TMT Pengangkatan:</span>
                                <span class="text-ink font-bold">{{ $p->appointment?->tmt_pengangkatan ? \Carbon\Carbon::parse($p->appointment->tmt_pengangkatan)->format('d-m-Y') : '-' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Pejabat yang Menetapkan:</span>
                                <span class="text-ink font-semibold">Kepala LLDIKTI Wilayah XVI</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Status Dokumen:</span>
                                <x-ui.badge variant="success" size="md" class="!font-bold">Verified</x-ui.badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 9: DOKUMEN & SK --}}
            <div x-show="activeTab === 'docs'" style="display: none;" class="space-y-4" x-transition>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Daftar Dokumen & Berkas Pegawai</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Seluruh berkas kepegawaian termasuk SK, ijazah, KTP/KK, dan dokumen lainnya.</p>
                    </div>
                    @can('update', $p)
                    <button type="button" @click="showUploadBerkas = !showUploadBerkas"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition hover:bg-primary/10 font-sans">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Unggah Berkas
                    </button>
                    @endcan
                </div>

                {{-- Panel Upload Berkas Lainnya --}}
                @can('update', $p)
                <div x-show="showUploadBerkas" x-transition class="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-4">
                    <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unggah Berkas Baru</h4>

                    {{-- Error global --}}
                    <p x-show="uploadBerkasError" x-text="uploadBerkasError"
                       class="text-xs text-danger font-semibold font-sans"></p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Nama Dokumen --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                Nama Dokumen <span class="text-danger">*</span>
                            </label>
                            <input type="text" x-model="newBerkas.nama_dokumen"
                                placeholder="Misal: KTP An. Budi Santoso"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <p x-show="uploadBerkasErrors.nama_dokumen" x-text="uploadBerkasErrors.nama_dokumen?.[0]"
                               class="text-xs text-danger font-sans"></p>
                        </div>

                        {{-- Kategori --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                Kategori <span class="text-danger">*</span>
                            </label>
                            <div class="relative">
                                <select x-model="newBerkas.kategori_dokumen"
                                    class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                                    <option value="ktp_kk">KTP & KK</option>
                                    <option value="ijazah">Ijazah</option>
                                    <option value="lainnya">Lainnya</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                            <p x-show="uploadBerkasErrors.kategori_dokumen" x-text="uploadBerkasErrors.kategori_dokumen?.[0]"
                               class="text-xs text-danger font-sans"></p>
                        </div>

                        {{-- Nomor Dokumen --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Dokumen</label>
                            <input type="text" x-model="newBerkas.nomor_dokumen"
                                placeholder="Opsional"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        </div>

                        {{-- Tanggal Terbit --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Terbit</label>
                            <input type="date" x-model="newBerkas.tanggal_terbit"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                        </div>

                        {{-- Keterangan --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Keterangan</label>
                            <input type="text" x-model="newBerkas.keterangan"
                                placeholder="Keterangan opsional"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        </div>

                        {{-- File Upload --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                File Berkas <span class="text-danger">*</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <label for="berkas_upload_input"
                                    class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition hover:bg-primary/10 font-sans">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                    </svg>
                                    Pilih File
                                </label>
                                <input type="file" id="berkas_upload_input" class="hidden"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                    @change="newBerkas.file = $event.target.files[0] || null">
                                <span class="min-w-0 flex-1 truncate text-xs font-sans"
                                    :class="newBerkas.file ? 'text-ink' : 'text-muted'"
                                    x-text="newBerkas.file ? newBerkas.file.name : 'Belum ada file dipilih'"></span>
                                <button x-show="newBerkas.file" type="button"
                                    @click="newBerkas.file = null; document.getElementById('berkas_upload_input').value = ''"
                                    class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                            </div>
                            <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/PNG/DOC/DOCX, maks. 10 MB.</p>
                            <p x-show="uploadBerkasErrors.berkas" x-text="uploadBerkasErrors.berkas?.[0]"
                               class="text-xs text-danger font-sans"></p>
                        </div>
                    </div>

                    {{-- Tombol aksi --}}
                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" @click="submitUploadBerkas()"
                            :disabled="isUploadingBerkas"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90 disabled:opacity-60 font-sans">
                            <svg x-show="isUploadingBerkas" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-text="isUploadingBerkas ? 'Mengunggah...' : 'Unggah'"></span>
                        </button>
                        <button type="button" @click="showUploadBerkas = false; uploadBerkasError = ''; uploadBerkasErrors = {};"
                            class="inline-flex items-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted transition hover:bg-soft font-sans">
                            Batal
                        </button>
                    </div>
                </div>
                @endcan

                {{-- Tabel Dokumen (Alpine reactive) --}}
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Nama Dokumen</th>
                                <th class="px-4 py-3">Kategori</th>
                                <th class="px-4 py-3">Nomor Dokumen</th>
                                <th class="px-4 py-3">Tanggal Terbit</th>
                                <th class="px-4 py-3">Ukuran</th>
                                <th class="px-4 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans text-ink">
                            <template x-if="dokumenList.length === 0">
                                <tr>
                                    <td colspan="6" class="px-4 py-6 text-center text-muted font-sans">
                                        Belum ada dokumen atau berkas yang diunggah untuk pegawai ini.
                                    </td>
                                </tr>
                            </template>
                            <template x-for="doc in dokumenList" :key="doc.id">
                                <tr class="transition-colors hover:bg-soft/30">
                                    <td class="px-4 py-3 max-w-xs">
                                        <div class="flex items-start gap-2.5">
                                            <div class="flex h-8 w-6 shrink-0 items-center justify-center rounded border border-border bg-soft p-0.5 shadow-sm">
                                                <span class="text-[5px] font-bold text-primary uppercase"
                                                    x-text="(doc.file_path || '').split('.').pop()?.substring(0, 4) || 'file'"></span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-bold font-sans truncate" x-text="doc.nama_dokumen"></p>
                                                <p x-show="doc.keterangan" class="text-[10px] text-muted truncate" x-text="doc.keterangan"></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-muted font-sans" x-text="doc.kategori_label"></td>
                                    <td class="px-4 py-3 text-muted font-mono" x-text="doc.nomor_dokumen || '-'"></td>
                                    <td class="px-4 py-3 text-muted" x-text="doc.tanggal_dokumen || '-'"></td>
                                    <td class="px-4 py-3 text-muted" x-text="doc.file_size"></td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <a :href="doc.detail_url"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                                title="Lihat detail">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                            </a>
                                            <a :href="doc.download_url"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                                title="Unduh">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                </svg>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

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
                            </div>
                        </template>

                        {{-- JABATAN FORM --}}
                        <template x-if="modalType === 'jabatan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan</label>
                                    <x-form.select x-model="newJabatan.jabatan_id" required>
                                        <option value="">-- Pilih Jabatan --</option>
                                        @foreach($jabatanOptions as $jabatan)
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
                                    <input type="text" x-model="newPendidikan.jurusan" placeholder="Manajemen Keuangan" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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
                    <input type="text" x-model="editPendidikanForm.jurusan" placeholder="Manajemen Keuangan" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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

    {{-- Modal: Riwayat Perubahan Status --}}
    <div
        x-show="showRiwayatStatus"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        style="display: none;"
        @keydown.escape.window="showRiwayatStatus = false"
    >
        <div
            @click.outside="showRiwayatStatus = false"
            class="w-full max-w-5xl rounded-xl bg-surface shadow-2xl overflow-hidden"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-border bg-soft/50 px-6 py-4">
                <h3 class="text-base font-bold text-ink font-sans">Riwayat Perubahan Status Kepegawaian</h3>
                <button type="button" @click="showRiwayatStatus = false" class="text-muted hover:text-ink transition cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Content: Grid 2 Kolom dengan Scroll --}}
            <div class="px-6 py-5">
                @if($p->statusHistories && $p->statusHistories->count() > 0)
                <div class="grid grid-cols-2 gap-4 max-h-[60vh] overflow-y-auto pr-2">
                    @foreach($p->statusHistories->sortByDesc('tanggal_efektif') as $history)
                    <div class="rounded-lg border {{ $history->is_latest ? 'border-primary bg-primary/5' : 'border-border bg-soft/30' }} p-4">
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-ink text-sm font-sans">{{ $history->status_nama }}</span>
                                @if($history->is_latest)
                                    <span class="inline-flex items-center rounded-full bg-primary px-2 py-0.5 text-[9px] font-bold text-white uppercase">Aktif</span>
                                @endif
                            </div>
                            <div class="text-xs text-muted font-sans space-y-1">
                                <p>
                                    <span class="font-semibold text-ink">Tanggal:</span>
                                    {{ $history->tanggal_efektif->format('d M Y') }}
                                </p>
                                @if($history->keterangan)
                                <p>
                                    <span class="font-semibold text-ink">Keterangan:</span>
                                    {{ Str::limit($history->keterangan, 100) }}
                                </p>
                                @endif
                                @php
                                    $currentFile = $history->document?->file_path ?? $history->file_sk;
                                @endphp
                                @if($currentFile)
                                <p class="pt-1">
                                    <a href="{{ asset('storage/'.$currentFile) }}" target="_blank" class="inline-flex items-center gap-1 text-primary hover:underline font-semibold text-[11px]">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                        </svg>
                                        Lihat SK
                                    </a>
                                </p>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="rounded-lg border border-border bg-soft/30 p-8 text-center">
                    <p class="text-sm text-muted font-sans">Belum ada riwayat perubahan status kepegawaian.</p>
                </div>
                @endif
            </div>

            {{-- Footer --}}
            <div class="flex justify-end border-t border-border bg-soft/50 px-6 py-4">
                <button type="button" @click="showRiwayatStatus = false"
                    class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink hover:bg-soft transition font-sans cursor-pointer">
                    Tutup
                </button>
            </div>
        </div>
    </div>

</div>{{-- /x-data utama --}}

</div>
