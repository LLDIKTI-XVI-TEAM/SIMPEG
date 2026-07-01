<x-layouts.app title="Detail Pegawai">

    @php
        // Mapping riwayat berkas digital pegawai
        $riwayatDokumen = $p->documents ?? [];

        // Kalkulator otomatis jadwal
        $tmtPangkatTerakhir = $p->latestRank()?->tmt_pangkat ? \Carbon\Carbon::parse($p->latestRank()->tmt_pangkat) : null;
        $estimasiPangkatNext = $tmtPangkatTerakhir ? $tmtPangkatTerakhir->copy()->addYears(4)->format('d-m-Y') : '-';
        $estimasiKgbNext = $p->tanggal_kgb_berikutnya
            ? \Carbon\Carbon::parse($p->tanggal_kgb_berikutnya)->format('d-m-Y')
            : ($p->latestSalary()?->tmt_kgb ? \Carbon\Carbon::parse($p->latestSalary()->tmt_kgb)->addYears(2)->format('d-m-Y') : '-');
        
        // Logika BUP dinamis berdasarkan jabatan
        $bup = 58;
        $jabatanStr = $p->latestPosition()?->nama_jabatan ?? '';
        if (str_contains(strtolower($jabatanStr), 'madya') || str_contains(strtolower($jabatanStr), 'utama') || str_contains(strtolower($jabatanStr), 'pimpinan tinggi')) {
            $bup = 60;
        }

        $tglLahir = isset($p->tanggal_lahir) ? \Carbon\Carbon::parse($p->tanggal_lahir) : null;
        $estimasiPensiun = $tglLahir ? $tglLahir->copy()->addYears($bup)->format('d-m-Y') : '-';
        
        $sisaPensiunStr = '-';
        if ($tglLahir) {
            $pensiunDate = $tglLahir->copy()->addYears($bup);
            $now = \Carbon\Carbon::now();
            if ($pensiunDate->isFuture()) {
                $diff = $now->diff($pensiunDate);
                $sisaPensiunStr = $diff->y . ' Tahun, ' . $diff->m . ' Bulan lagi';
            } else {
                $sisaPensiunStr = 'Memasuki Usia Pensiun';
            }
        }
    @endphp

    <div x-data="{
        activeTab: new URLSearchParams(window.location.search).get('tab') || 'profile',
        kinerjaBaik: {{ $p->is_kinerja_baik ? 'true' : 'false' }},
        showModal: false,
        modalTitle: '',
        modalType: '',
        modalError: '',
        isSubmitting: false,
        toast: { show: false, message: '', type: 'success' },
        
        // Mengambil data riwayat riil dari database melalui relasi model Employee
        keluargaList: {{ $p->families->map(fn($f) => ['id' => $f->id, 'nama_anggota' => $f->nama_anggota, 'hubungan' => $f->hubungan, 'nik' => $f->nik, 'tempat_lahir' => $f->tempat_lahir, 'tanggal_lahir' => $f->tanggal_lahir ? \Carbon\Carbon::parse($f->tanggal_lahir)->format('d-m-Y') : '-', 'jenis_kelamin' => $f->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan', 'pekerjaan' => $f->pekerjaan, 'status' => $f->status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung'])->toJson() }},
        pangkatList: {{ $p->rankHistories->map(fn($r) => ['golongan' => $r->golongan->nama ?? '-', 'no_sk' => $r->no_sk, 'tgl_sk' => $r->tanggal_sk, 'tmt' => $r->tmt_pangkat])->toJson() }},
        jabatanList: {{ $p->positionHistories->map(fn($j) => ['jabatan' => $j->nama_jabatan, 'unit' => $j->unitKerja->nama ?? '-', 'no_sk' => $j->no_sk, 'tgl_sk' => $j->tanggal_sk, 'tmt' => $j->tmt_jabatan])->toJson() }},
        kgbList: {{ $p->salaryHistories->map(fn($s) => ['gaji' => 'Rp ' . number_format($s->gaji_pokok, 0, ',', '.'), 'no_sk' => $s->no_sk, 'tgl_sk' => $s->tanggal_sk, 'tmt' => $s->tmt_kgb])->toJson() }},
        disiplinList: {{ $p->disciplineRecords->map(fn($d) => ['jenis' => $d->jenis_hukuman, 'alasan' => $d->deskripsi, 'no_sk' => $d->no_sk, 'tgl_sk' => $d->tanggal_sk ? \Carbon\Carbon::parse($d->tanggal_sk)->format('d-m-Y') : '-', 'masa' => ($d->tanggal_mulai ? \Carbon\Carbon::parse($d->tanggal_mulai)->format('d-m-Y') : '-') . ' s/d ' . ($d->tanggal_berakhir ? \Carbon\Carbon::parse($d->tanggal_berakhir)->format('d-m-Y') : 'Sekarang'), 'is_active' => $d->is_active])->toJson() }},
        pendidikanList: {{ $p->educationHistories->map(fn($e) => ['tingkat' => $e->jenjang->nama ?? '-', 'institusi' => $e->nama_institusi, 'prodi' => $e->jurusan, 'lulus' => $e->tahun_lulus, 'no_ijazah' => $e->no_ijazah])->toJson() }},
        
        // Form states
        newKeluarga: { nama_anggota: '', hubungan: 'Istri', nik: '', tempat_lahir: '', tanggal_lahir: '', jenis_kelamin: 'P', status_tunjangan: false, pekerjaan: '' },
        newPangkat: { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '' },
        newJabatan: { nama_jabatan: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '' },
        newKgb: { gaji_pokok: '', no_sk: '', tanggal_sk: '', tmt_kgb: '' },
        newDisiplin: { jenis_hukuman: 'Ringan', deskripsi: '', no_sk: '', tanggal_sk: '', tanggal_mulai: '', tanggal_berakhir: '' },
        newPendidikan: { tingkat: 'Sarjana (S1)', institusi: '', prodi: '', lulus: '', no_ijazah: '' },
        
        openModal(type, title) {
            this.modalType = type;
            this.modalTitle = title;
            this.modalError = '';
            this.showModal = true;
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

            let endpoint = `/pegawai/{{ $p->id }}/riwayat`;
            if (this.modalType === 'pangkat') endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-kepangkatan`;
            else if (this.modalType === 'jabatan') endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-jabatan`;
            else if (this.modalType === 'kgb') endpoint = `/api/v1/pegawai/{{ $p->id }}/riwayat-kgb`;
            else if (this.modalType === 'keluarga') endpoint = `/api/v1/pegawai/{{ $p->id }}/keluarga`;
            else if (this.modalType === 'disiplin') endpoint = `/api/v1/pegawai/{{ $p->id }}/disiplin`;

            this.isSubmitting = true;

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(payload)
                });
                
                if (response.ok) {
                    const result = await response.json();
                    
                    if (this.modalType === 'disiplin') {
                        this.disiplinList.unshift({
                            jenis: this.newDisiplin.jenis_hukuman,
                            alasan: this.newDisiplin.deskripsi,
                            no_sk: this.newDisiplin.no_sk,
                            tgl_sk: this.newDisiplin.tanggal_sk,
                            masa: this.newDisiplin.tanggal_mulai + ' s/d ' + (this.newDisiplin.tanggal_berakhir ? this.newDisiplin.tanggal_berakhir : 'Sekarang'),
                            is_active: true
                        });
                        this.newDisiplin = { jenis_hukuman: 'Ringan', deskripsi: '', no_sk: '', tanggal_sk: '', tanggal_mulai: '', tanggal_berakhir: '' };
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
                            jabatan: this.newJabatan.nama_jabatan,
                            unit: '-', // Idealnya ini ambil dari nama referensi unit kerja
                            no_sk: this.newJabatan.no_sk,
                            tgl_sk: this.newJabatan.tanggal_sk,
                            tmt: this.newJabatan.tmt_jabatan
                        });
                        this.newJabatan = { nama_jabatan: '', jenis_jabatan_id: '', eselon_id: '', unit_kerja_id: '', no_sk: '', tanggal_sk: '', tmt_jabatan: '' };
                    } else if (this.modalType === 'pangkat') {
                        this.pangkatList.unshift({
                            golongan: '-', // Idealnya ini ambil dari nama referensi golongan
                            no_sk: this.newPangkat.no_sk,
                            tgl_sk: this.newPangkat.tanggal_sk,
                            tmt: this.newPangkat.tmt_pangkat
                        });
                        this.newPangkat = { golongan_id: '', no_sk: '', tanggal_sk: '', tmt_pangkat: '' };
                    } else if (this.modalType === 'keluarga') {
                        this.keluargaList.unshift({
                            nama_anggota: this.newKeluarga.nama_anggota,
                            hubungan: this.newKeluarga.hubungan,
                            nik: this.newKeluarga.nik,
                            tempat_lahir: this.newKeluarga.tempat_lahir,
                            tanggal_lahir: this.newKeluarga.tanggal_lahir,
                            jenis_kelamin: this.newKeluarga.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan',
                            pekerjaan: this.newKeluarga.pekerjaan,
                            status: this.newKeluarga.status_tunjangan === 'true' || this.newKeluarga.status_tunjangan === true ? 'Ditanggung' : 'Tidak Ditanggung'
                        });
                        this.newKeluarga = { nama_anggota: '', hubungan: 'Istri', nik: '', tempat_lahir: '', tanggal_lahir: '', jenis_kelamin: 'P', status_tunjangan: false, pekerjaan: '' };
                    } else if (this.modalType === 'pendidikan') {
                        this.pendidikanList.unshift({
                            tingkat: this.newPendidikan.tingkat,
                            institusi: this.newPendidikan.institusi,
                            prodi: this.newPendidikan.prodi,
                            lulus: this.newPendidikan.lulus,
                            no_ijazah: this.newPendidikan.no_ijazah
                        });
                        this.newPendidikan = { tingkat: 'Sarjana (S1)', institusi: '', prodi: '', lulus: '', no_ijazah: '' };
                    }
                    
                    this.showModal = false;
                    this.toast = { show: true, message: 'Data berhasil disimpan!', type: 'success' };
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => this.toast.show = false, 3000);
                } else {
                    const errorData = await response.json();
                    let errMsg = '';
                    if (errorData.errors) {
                        errMsg = '<ul class=\'list-disc pl-5 mt-1\'>';
                        for (const key in errorData.errors) {
                            errMsg += '<li>' + errorData.errors[key][0] + '</li>';
                        }
                        errMsg += '</ul>';
                    } else {
                        errMsg = errorData.message || 'Data tidak valid';
                    }
                    this.modalError = errMsg;
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
        <div class="relative z-40 mb-2">
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Pegawai</span>
            </nav>

            {{-- DYNAMIC ALERT (MENGGANTIKAN TOAST) --}}
            <div x-show="toast.show" style="display: none;" class="mb-4" x-transition>
                
                <template x-if="toast.type === 'success'">
                    <x-ui.alert variant="success" class="font-medium">
                        <span x-text="toast.message"></span>
                    </x-ui.alert>
                </template>
                
                <template x-if="toast.type === 'error'">
                    <x-ui.alert variant="danger" class="font-medium">
                        <span x-text="toast.message"></span>
                    </x-ui.alert>
                </template>
            </div>
        </div>

        {{-- MAIN DETAIL CARD --}}
        <x-ui.card padding="lg" class="space-y-6">
            @php
                $fotoUrl = $p->foto_url;
            @endphp
            
            {{-- Header info --}}
            <div class="border-b border-border pb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 rounded-full border border-border bg-soft flex items-center justify-center overflow-hidden shrink-0">
                        @if($fotoUrl)
                            <img
                                src="{{ $fotoUrl }}"
                                alt="Foto {{ $p->nama_lengkap }}"
                                class="h-full w-full object-cover"
                            >
                        @else
                            <div class="flex h-full w-full items-center justify-center bg-primary/10 text-xl font-bold text-primary font-sans uppercase">
                                {{ strtoupper(substr($p->nama_lengkap, 0, 1)) }}
                            </div>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ $p->nama_lengkap }}</h2>
                            <template x-if="kinerjaBaik">
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-success font-sans">
                                    KINERJA BAIK
                                </span>
                            </template>
                        </div>
                        <p class="text-xs text-muted font-sans font-mono mt-0.5">NIP. {{ $p->nip }}</p>
                            <x-ui.badge variant="primary" size="md" uppercase class="mt-1.5">{{ $p->jenisPegawai->nama ?? '-' }}</x-ui.badge>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <a href="{{ route('pegawai.edit', $p->id) }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                        </svg>
                        Edit Pegawai
                    </a>
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        Kembali
                    </a>
                </div>
            </div>

            {{-- TAB NAVIGATION --}}
            <x-ui.tabs label="Navigasi detail pegawai">
                <x-ui.tab active="activeTab === 'profile'" click="activeTab = 'profile'">Profil</x-ui.tab>
                <x-ui.tab active="activeTab === 'keluarga'" click="activeTab = 'keluarga'">Keluarga</x-ui.tab>
                <x-ui.tab active="activeTab === 'kepangkatan'" click="activeTab = 'kepangkatan'">Kepangkatan</x-ui.tab>
                <x-ui.tab active="activeTab === 'jabatan'" click="activeTab = 'jabatan'">Jabatan</x-ui.tab>
                <x-ui.tab active="activeTab === 'kgb'" click="activeTab = 'kgb'">KGB</x-ui.tab>
                <x-ui.tab active="activeTab === 'disiplin'" click="activeTab = 'disiplin'">Hukuman Disiplin</x-ui.tab>
                <x-ui.tab active="activeTab === 'pendidikan'" click="activeTab = 'pendidikan'">Pendidikan</x-ui.tab>
                <x-ui.tab active="activeTab === 'pengangkatan'" click="activeTab = 'pengangkatan'">Pengangkatan</x-ui.tab>
                <x-ui.tab active="activeTab === 'docs'" click="activeTab = 'docs'">Dokumen SK</x-ui.tab>
            </x-ui.tabs>

            {{-- TAB 1: PROFIL LENGKAP --}}
            <div x-show="activeTab === 'profile'" class="space-y-6" x-transition>
                
                {{-- Toggle Flag Kinerja & Atasan Langsung --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-soft/40 rounded-lg p-4 border border-border">
                    {{-- Status Kinerja --}}
                    <div class="flex items-center justify-between p-2">
                        <div>
                            <span class="text-xs font-bold text-ink font-sans block">Toggle Flag "Kinerja Baik"</span>
                            <p class="text-[10px] text-muted font-sans mt-0.5">Flag ini mempengaruhi kualifikasi rekomendasi promosi berkala.</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer select-none">
                            <input type="checkbox" x-model="kinerjaBaik" class="sr-only peer">
                            <div class="w-11 h-6 bg-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-success"></div>
                        </label>
                    </div>

                    {{-- Atasan Langsung --}}
                    <div class="flex items-center gap-3 border-l border-border/80 pl-6">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary text-sm font-bold shrink-0">
                            🏢
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-muted uppercase tracking-wider font-sans block">Atasan Langsung</span>
                            <p class="text-xs font-bold text-ink font-sans">{{ $p->currentSupervisor()?->supervisor->nama_lengkap ?? '-' }}</p>
                            <p class="text-[10px] text-muted font-mono leading-none mt-0.5">NIP. {{ $p->currentSupervisor()?->supervisor->nip ?? '-' }} ({{ $p->currentSupervisor()?->supervisor->latestPosition()?->nama_jabatan ?? '-' }})</p>
                        </div>
                    </div>
                </div>

                {{-- Auto-Kalkulasi Jadwal --}}
                <div class="space-y-3">
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5 flex items-center gap-1.5">
                        Estimasi Jadwal Kepegawaian <span class="text-[9px] text-primary lowercase font-normal">(kalkulator otomatis)</span>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-ui.card padding="none" class="p-3 text-center">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Kenaikan Pangkat Terdekat</span>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiPangkatNext }}</p>
                            <p class="text-[9px] text-muted font-sans mt-0.5">(Estimasi 4 tahun sejak TMT)</p>
                        </x-ui.card>
                        <x-ui.card padding="none" class="p-3 text-center">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">KGB Terdekat</span>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiKgbNext }}</p>
                            <p class="text-[9px] text-muted font-sans mt-0.5">(Estimasi 2 tahun sejak TMT)</p>
                        </x-ui.card>
                        <x-ui.card padding="none" class="p-3 text-center">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Estimasi Tanggal Pensiun</span>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiPensiun }}</p>
                            <p class="text-[9px] text-danger font-semibold mt-0.5" x-text="'Sisa: ' + '{{ $sisaPensiunStr }}'"></p>
                        </x-ui.card>
                    </div>
                </div>

                {{-- Detail Biodata --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Identitas & Data Pribadi</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">NIK (KTP)</span>
                                <p class="text-ink font-mono font-bold">{{ $p->nik ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">No. Kartu Keluarga (KK)</span>
                                <p class="text-ink font-mono font-bold">{{ $p->no_kk ?? '-' }}</p>
                            </div>
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
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Kontak & Rumah</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Email Dinas</span>
                                <p class="text-ink font-sans font-mono">{{ '-' ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Email Pribadi</span>
                                <p class="text-ink font-sans font-mono">{{ $p->email ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Nomor HP</span>
                                <p class="text-ink font-sans font-mono">{{ $p->no_hp ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Telepon Rumah</span>
                                <p class="text-ink font-sans font-mono">{{ $p->no_telepon_rumah ?? '-' }}</p>
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
                            <p class="text-ink font-sans font-bold">{{ $p->latestPosition()->nama_jabatan ?? $p->jabatan_terakhir ?? '-' }}</p>
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
                            <p class="text-ink font-sans font-bold">{{ $p->kelas_jabatan ?? '-' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">TMT Golongan</span>
                            <p class="text-ink font-mono">{{ $p->latestRank()?->tmt_pangkat ? \Carbon\Carbon::parse($p->latestRank()->tmt_pangkat)->format('d-m-Y') : '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 2: DATA KELUARGA --}}
            <div x-show="activeTab === 'keluarga'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Susunan Anggota Keluarga</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Daftar istri/suami dan anak yang tercatat sebagai tanggungan.</p>
                    </div>
                    <x-ui.button type="button" variant="primary" size="sm" @click="openModal('keluarga', 'Tambah Anggota Keluarga')">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Keluarga
                    </x-ui.button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Nama Lengkap & NIK</x-ui.table-th>
                                <x-ui.table-th>Hubungan</x-ui.table-th>
                                <x-ui.table-th>TTL</x-ui.table-th>
                                <x-ui.table-th>Pekerjaan</x-ui.table-th>
                                <x-ui.table-th>Status</x-ui.table-th>
                                <x-ui.table-th align="right">Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans">
                            <template x-for="(fam, index) in keluargaList" :key="index">
                                <x-ui.table-row :interactive="true" class="text-ink">
                                    <x-ui.table-td padding="sm">
                                        <p class="font-bold font-sans" x-text="fam.nama_anggota"></p>
                                        <p class="text-[10px] text-muted font-mono" x-text="fam.nik ? 'NIK. ' + fam.nik : 'NIK. -'"></p>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <p class="font-sans" x-text="fam.hubungan"></p>
                                        <p class="text-[10px] text-muted font-sans" x-text="fam.jenis_kelamin"></p>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <p class="font-sans" x-text="fam.tempat_lahir || '-'"></p>
                                        <p class="text-[10px] text-muted font-mono" x-text="fam.tanggal_lahir"></p>
                                    </x-ui.table-td>
                                    <x-ui.table-td x-text="fam.pekerjaan || '-'" padding="sm"></x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold"
                                              :class="fam.status === 'Ditanggung' ? 'text-success' : 'text-muted'"
                                              x-text="fam.status"></span>
                                    </x-ui.table-td>
                                    <x-ui.table-td align="right" padding="sm" class="text-muted">-</x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB 3: RIWAYAT KEPANGKATAN --}}
            <div x-show="activeTab === 'kepangkatan'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kepangkatan & Golongan</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan kenaikan pangkat reguler maupun pilihan selama masa dinas.</p>
                    </div>
                    <x-ui.button type="button" variant="primary" size="sm" @click="openModal('pangkat', 'Tambah Riwayat Kepangkatan')">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Pangkat
                    </x-ui.button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Golongan</x-ui.table-th>
                                <x-ui.table-th>Nomor SK Pangkat</x-ui.table-th>
                                <x-ui.table-th>Tanggal SK</x-ui.table-th>
                                <x-ui.table-th>TMT Pangkat</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans">
                            <template x-for="p in pangkatList" :key="p.no_sk">
                                <x-ui.table-row :interactive="true" class="text-ink">
                                    <x-ui.table-td x-text="p.golongan" padding="sm" class="font-bold"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.no_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.tgl_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.tmt" padding="sm" class="font-mono"></x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB 4: RIWAYAT JABATAN --}}
            <div x-show="activeTab === 'jabatan'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Jabatan & Struktural</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan penugasan jabatan fungsional maupun struktural.</p>
                    </div>
                    <x-ui.button type="button" variant="primary" size="sm" @click="openModal('jabatan', 'Tambah Riwayat Jabatan')">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Jabatan
                    </x-ui.button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Nama Jabatan</x-ui.table-th>
                                <x-ui.table-th>Unit Kerja</x-ui.table-th>
                                <x-ui.table-th>Nomor SK Jabatan</x-ui.table-th>
                                <x-ui.table-th>Tanggal SK</x-ui.table-th>
                                <x-ui.table-th>TMT Jabatan</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans">
                            <template x-for="j in jabatanList" :key="j.no_sk">
                                <x-ui.table-row :interactive="true" class="text-ink">
                                    <x-ui.table-td x-text="j.jabatan" padding="sm" class="font-bold"></x-ui.table-td>
                                    <x-ui.table-td x-text="j.unit" padding="sm"></x-ui.table-td>
                                    <x-ui.table-td x-text="j.no_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="j.tgl_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="j.tmt" padding="sm" class="font-mono"></x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB 5: RIWAYAT KGB --}}
            <div x-show="activeTab === 'kgb'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kenaikan Gaji Berkala (KGB)</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan penyesuaian gaji berkala setiap 2 tahun sekali.</p>
                    </div>
                    <x-ui.button type="button" variant="primary" size="sm" @click="openModal('kgb', 'Tambah Riwayat KGB')">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah KGB
                    </x-ui.button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Gaji Pokok Baru</x-ui.table-th>
                                <x-ui.table-th>Nomor Surat KGB</x-ui.table-th>
                                <x-ui.table-th>Tanggal Surat</x-ui.table-th>
                                <x-ui.table-th>TMT KGB</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans">
                            <template x-for="k in kgbList" :key="k.no_sk">
                                <x-ui.table-row :interactive="true" class="text-ink">
                                    <x-ui.table-td x-text="k.gaji" padding="sm" class="font-bold"></x-ui.table-td>
                                    <x-ui.table-td x-text="k.no_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="k.tgl_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="k.tmt" padding="sm" class="font-mono"></x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB 6: HUKUMAN DISIPLIN --}}
            <div x-show="activeTab === 'disiplin'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Hukuman Disiplin</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan sanksi disiplin pegawai yang mempengaruhi promosi kepegawaian.</p>
                    </div>
                    <x-ui.button type="button" variant="primary" size="sm" @click="openModal('disiplin', 'Tambah Hukuman Disiplin')">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Hukuman
                    </x-ui.button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Jenis Hukuman</x-ui.table-th>
                                <x-ui.table-th>Alasan / Pelanggaran</x-ui.table-th>
                                <x-ui.table-th>Nomor SK</x-ui.table-th>
                                <x-ui.table-th>Tanggal SK</x-ui.table-th>
                                <x-ui.table-th>Masa Berlaku</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans">
                            <template x-for="(d, index) in disiplinList" :key="index">
                                <x-ui.table-row :interactive="true" class="text-ink">
                                    <x-ui.table-td padding="sm">
                                        <span class="font-bold text-danger" x-text="d.jenis"></span>
                                        <template x-if="d.is_active">
                                            <x-ui.badge variant="danger" size="xs" uppercase class="ml-1">Aktif</x-ui.badge>
                                        </template>
                                    </x-ui.table-td>
                                    <x-ui.table-td x-text="d.alasan" padding="sm"></x-ui.table-td>
                                    <x-ui.table-td x-text="d.no_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="d.tgl_sk" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="d.masa" padding="sm" class="font-mono"></x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                            <x-ui.table-row x-show="disiplinList.length === 0">
                                <x-ui.table-td colspan="5" align="center" class="px-4 py-6 text-muted font-semibold">
                                    Pegawai ini tidak memiliki riwayat hukuman disiplin. Bersih (Clean Record). ✅
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB 7: RIWAYAT PENDIDIKAN --}}
            <div x-show="activeTab === 'pendidikan'" class="space-y-4" style="display: none;" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Pendidikan Formal</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Riwayat kualifikasi akademis tertinggi staf.</p>
                    </div>
                    <x-ui.button type="button" variant="primary" size="sm" @click="openModal('pendidikan', 'Tambah Riwayat Pendidikan')">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Pendidikan
                    </x-ui.button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Tingkat</x-ui.table-th>
                                <x-ui.table-th>Nama Institusi</x-ui.table-th>
                                <x-ui.table-th>Program Studi</x-ui.table-th>
                                <x-ui.table-th>Tahun Lulus</x-ui.table-th>
                                <x-ui.table-th>Nomor Ijazah</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans">
                            <template x-for="p in pendidikanList" :key="p.no_ijazah">
                                <x-ui.table-row :interactive="true" class="text-ink">
                                    <x-ui.table-td x-text="p.tingkat" padding="sm" class="font-bold"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.institusi" padding="sm"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.prodi" padding="sm"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.lulus" padding="sm" class="font-mono"></x-ui.table-td>
                                    <x-ui.table-td x-text="p.no_ijazah" padding="sm" class="font-mono"></x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
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
                                <span class="text-ink font-mono font-bold">{{ $p->appointment->no_sk ?? '-' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Tanggal SK Terbit:</span>
                                <span class="text-ink font-mono">{{ $p->appointment?->tanggal_sk ? \Carbon\Carbon::parse($p->appointment->tanggal_sk)->format('d-m-Y') : '-' }}</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">TMT Pengangkatan:</span>
                                <span class="text-ink font-mono font-bold">{{ $p->appointment?->tmt_pengangkatan ? \Carbon\Carbon::parse($p->appointment->tmt_pengangkatan)->format('d-m-Y') : '-' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Pejabat yang Menetapkan:</span>
                                <span class="text-ink font-semibold">Kepala LLDIKTI Wilayah XVI</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Status Dokumen:</span>
                                <x-ui.badge variant="success" size="xs" :pill="false" uppercase>Verified</x-ui.badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 9: BERKAS DOKUMEN SK --}}
            <div x-show="activeTab === 'docs'" style="display: none;" class="space-y-4" x-transition>
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Daftar Berkas Fisik Kepegawaian</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Daftar berkas PDF pendukung mutasi pangkat, jabatan, dan KGB.</p>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table>
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th>Nama Dokumen</x-ui.table-th>
                                <x-ui.table-th>Kategori</x-ui.table-th>
                                <x-ui.table-th>Nomor Dokumen</x-ui.table-th>
                                <x-ui.table-th>Tanggal Terbit</x-ui.table-th>
                                <x-ui.table-th>Ukuran</x-ui.table-th>
                                <x-ui.table-th align="right">Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-xs font-sans text-ink">
                            @forelse($riwayatDokumen as $doc)
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td padding="sm" class="max-w-xs">
                                    <div class="flex items-start gap-2.5">
                                        <div class="flex h-8 w-6 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-0.5 shadow-sm relative">
                                            <div class="w-full bg-primary/10 text-primary text-[5px] font-bold text-center py-0.5 uppercase tracking-wide">
                                                PDF
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold font-sans truncate">{{ $doc['nama'] }}</p>
                                            <p class="text-[10px] text-muted font-sans mt-0.5 truncate">{{ $doc['deskripsi'] }}</p>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-muted">{{ $doc['kategori_label'] }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="font-mono text-muted">{{ $doc['nomor'] }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="font-mono text-muted">{{ $doc['tanggal'] }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="font-mono text-muted">{{ $doc['file_size'] }}</x-ui.table-td>
                                <x-ui.table-td align="right" padding="sm">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <a href="/dashboard/dokumen/{{ $doc['id'] }}" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline font-sans">
                                            Detail
                                        </a>
                                        <span class="text-border">|</span>
                                        <a href="/dashboard/dokumen/{{ $doc['id'] }}/download" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline font-sans">
                                            Unduh
                                        </a>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                            @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="6" align="center" class="px-4 py-6 text-muted">
                                    Belum ada dokumen atau SK kepegawaian yang diunggah untuk staf ini.
                                </x-ui.table-td>
                            </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

        </x-ui.card>

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
                        <x-ui.alert variant="danger" title="Terdapat kesalahan pengisian form" class="mb-4">
                            <div x-html="modalError"></div>
                        </x-ui.alert>
                    </template>

                    <form @submit.prevent="submitForm()" class="space-y-4">
                        {{-- KELUARGA FORM --}}
                        <template x-if="modalType === 'keluarga'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Anggota Keluarga</label>
                                    <input type="text" x-model="newKeluarga.nama_anggota" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Hubungan</label>
                                        <select x-model="newKeluarga.hubungan" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                            <option value="Suami">Suami</option>
                                            <option value="Istri">Istri</option>
                                            <option value="Anak">Anak</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIK (Nomor Induk Kependudukan)</label>
                                        <input type="text" maxlength="16" x-model="newKeluarga.nik" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tempat Lahir</label>
                                        <input type="text" x-model="newKeluarga.tempat_lahir" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                    <x-form.date label="Tanggal Lahir" required size="sm" class="bg-white" x-model="newKeluarga.tanggal_lahir" />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Kelamin</label>
                                        <select x-model="newKeluarga.jenis_kelamin" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                            <option value="L">Laki-laki</option>
                                            <option value="P">Perempuan</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Tunjangan</label>
                                        <select x-model="newKeluarga.status_tunjangan" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                            <option :value="true">Ditanggung</option>
                                            <option :value="false">Tidak Ditanggung</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pekerjaan</label>
                                    <input type="text" x-model="newKeluarga.pekerjaan" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        {{-- PANGKAT FORM --}}
                        <template x-if="modalType === 'pangkat'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan</label>
                                    <select x-model="newPangkat.golongan_id" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih Golongan --</option>
                                        @foreach($golonganOptions as $gol)
                                            <option value="{{ $gol->id }}">{{ $gol->nama }} ({{ $gol->pangkat }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pangkat</label>
                                    <input type="text" x-model="newPangkat.no_sk" required placeholder="SK-321-KP-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <x-form.date label="Tanggal SK Terbit" required size="sm" class="bg-white" x-model="newPangkat.tanggal_sk" />
                                <x-form.date label="TMT Golongan" required size="sm" class="bg-white" x-model="newPangkat.tmt_pangkat" />
                            </div>
                        </template>

                        {{-- JABATAN FORM --}}
                        <template x-if="modalType === 'jabatan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Jabatan</label>
                                    <input type="text" x-model="newJabatan.nama_jabatan" required placeholder="Analis Kepegawaian Muda" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan</label>
                                    <select x-model="newJabatan.jenis_jabatan_id" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih Jenis Jabatan --</option>
                                        @foreach($jenisJabatanOptions as $jj)
                                            <option value="{{ $jj->id }}">{{ $jj->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Eselon (Opsional)</label>
                                    <select x-model="newJabatan.eselon_id" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih Eselon --</option>
                                        @foreach($eselonOptions as $esl)
                                            <option value="{{ $esl->id }}">{{ $esl->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja</label>
                                    <select x-model="newJabatan.unit_kerja_id" required class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">-- Pilih Unit Kerja --</option>
                                        @foreach($unitKerjaOptions as $unit)
                                            <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Jabatan</label>
                                    <input type="text" x-model="newJabatan.no_sk" required placeholder="SK-910-JAB-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <x-form.date label="Tanggal SK Terbit" required size="sm" class="bg-white" x-model="newJabatan.tanggal_sk" />
                                <x-form.date label="TMT Jabatan" required size="sm" class="bg-white" x-model="newJabatan.tmt_jabatan" />
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
                                <x-form.date label="Tanggal Surat Terbit" required size="sm" class="bg-white" x-model="newKgb.tanggal_sk" />
                                <x-form.date label="TMT KGB" required size="sm" class="bg-white" x-model="newKgb.tmt_kgb" />
                            </div>
                        </template>

                        {{-- DISIPLIN FORM --}}
                        <template x-if="modalType === 'disiplin'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Hukuman</label>
                                    <select x-model="newDisiplin.jenis_hukuman" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="Ringan">Ringan</option>
                                        <option value="Sedang">Sedang</option>
                                        <option value="Berat">Berat</option>
                                    </select>
                                </div>
                                <x-form.textarea
                                    label="Deskripsi Pelanggaran"
                                    rows="2"
                                    size="sm"
                                    placeholder="Keterlambatan absensi berulang"
                                    class="bg-white"
                                    x-model="newDisiplin.deskripsi"
                                    required
                                />
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Hukuman</label>
                                    <input type="text" x-model="newDisiplin.no_sk" required placeholder="SK-HD-023-2026" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <x-form.date label="Tanggal SK Terbit" required size="sm" class="bg-white" x-model="newDisiplin.tanggal_sk" />
                                <div class="grid grid-cols-2 gap-4">
                                    <x-form.date label="Tanggal Mulai" required size="sm" class="bg-white" x-model="newDisiplin.tanggal_mulai" />
                                    <x-form.date label="Tanggal Berakhir" size="sm" class="bg-white" x-model="newDisiplin.tanggal_berakhir" />
                                </div>
                                <p class="text-[10px] text-muted italic font-sans">* Kosongkan tanggal berakhir jika masa berlaku tidak ditentukan (aktif selamanya).</p>
                            </div>
                        </template>

                        {{-- PENDIDIKAN FORM --}}
                        <template x-if="modalType === 'pendidikan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tingkat Pendidikan</label>
                                    <select x-model="newPendidikan.tingkat" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="Diploma III (D3)">Diploma III (D3)</option>
                                        <option value="Sarjana (S1)">Sarjana (S1)</option>
                                        <option value="Magister (S2)">Magister (S2)</option>
                                        <option value="Doktor (S3)">Doktor (S3)</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Institusi</label>
                                    <input type="text" x-model="newPendidikan.institusi" required placeholder="Universitas Sam Ratulangi" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi</label>
                                    <input type="text" x-model="newPendidikan.prodi" placeholder="Manajemen Keuangan" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tahun Lulus</label>
                                    <input type="number" x-model="newPendidikan.lulus" required placeholder="2007" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Ijazah</label>
                                    <input type="text" x-model="newPendidikan.no_ijazah" placeholder="IJZ-S1-MAN-2007" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        <div class="border-t border-border pt-4 flex justify-end gap-2.5 mt-6">
                            <x-ui.button type="button" variant="muted" size="xs" @click="showModal = false" x-bind:disabled="isSubmitting">
                                Batal
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="xs" x-bind:disabled="isSubmitting" class="min-w-[120px]">
                                <template x-if="!isSubmitting">
                                    <span>Simpan</span>
                                </template>
                                <template x-if="isSubmitting">
                                    <span class="flex items-center justify-center gap-2">
                                        <svg class="animate-spin h-3.5 w-3.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Menyimpan...
                                    </span>
                                </template>
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</x-layouts.app>
