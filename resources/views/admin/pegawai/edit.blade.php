<x-layouts.app title="Edit Pegawai">
    @php
        $fotoUrl = $p->foto_url;
        $golonganRefOptions = $golonganRefOptions ?? \App\Models\RefGolongan::orderBy('kode')->get();
        $eselonOptions = $eselonOptions ?? \App\Models\RefEselon::orderBy('nama')->get();
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Edit Data Pegawai</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Edit</span>
            </nav>
        </div>

        {{-- Session Error --}}
        @if (session('error'))
            <div class="rounded-lg bg-red-50 p-4 border border-red-200">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Gagal Menyimpan:</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <p>{{ session('error') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Validation Errors --}}
        @if ($errors->any())
            <div class="rounded-lg bg-red-50 p-4 border border-red-200">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Terdapat kesalahan pengisian form:</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <ul role="list" class="list-disc space-y-1 pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm" x-data="{
            activeTab: 'utama',
            subTab: 'pangkat',
            nip: '{{ $p->nip ?? '' }}',
            nipError: '',
            nik: '{{ $p->nik ?? '' }}',
            kk: '{{ $p->no_kk ?? '' }}',
            nikError: '',
            kkError: '',
            fotoPreview: @js($fotoUrl),
            
            // File uploads state
            skPangkatName: '{{ $p->latestRank() && $p->latestRank()->file_sk ? basename($p->latestRank()->file_sk) : "" }}',
            skPangkatSize: '',
            skPangkatError: '',
            skPangkatMode: 'upload', // 'upload' | 'arsip'
            selectedArsipPangkatId: '',
            arsipPangkatList: @js($arsipPangkat),
            
            skJabatanName: '{{ $p->latestPosition() && $p->latestPosition()->file_sk ? basename($p->latestPosition()->file_sk) : "" }}',
            skJabatanSize: '',
            skJabatanError: '',
            skJabatanMode: 'upload',
            selectedArsipJabatanId: '',
            arsipJabatanList: @js($arsipJabatan),
            
            skKgbName: '{{ $p->latestSalary() && $p->latestSalary()->file_sk ? basename($p->latestSalary()->file_sk) : "" }}',
            skKgbSize: '',
            skKgbError: '',
            skKgbMode: 'upload',
            selectedArsipKgbId: '',
            arsipKgbList: @js($arsipKgb),
            
            skPengangkatanName: '{{ $p->appointment && $p->appointment->file_sk ? basename($p->appointment->file_sk) : "" }}',
            skPengangkatanSize: '',
            skPengangkatanError: '',
            skPengangkatanMode: 'upload',
            selectedArsipPengangkatanId: '',
            arsipPengangkatanList: @js($arsipPengangkatan),

            pangkatHistories: @js($p->rankHistories->keyBy('id')),
            selectedPangkatId: '{{ $p->latestRank()?->id ?? 'new' }}',
            pangkatForm: {
                golongan_id: '{{ $p->latestRank()?->golongan_id ?? '' }}',
                no_sk: '{{ $p->latestRank()?->no_sk ?? '' }}',
                tanggal_sk: '{{ $p->latestRank()?->tanggal_sk?->format('Y-m-d') ?? '' }}',
                tmt_pangkat: '{{ $p->latestRank()?->tmt_pangkat?->format('Y-m-d') ?? '' }}',
            },
            
            jabatanHistories: @js($p->positionHistories->keyBy('id')),
            selectedJabatanId: '{{ $p->latestPosition()?->id ?? 'new' }}',
            jabatanForm: {
                nama_jabatan: '{{ $p->latestPosition()?->nama_jabatan ?? $p->jabatan_terakhir ?? '' }}',
                jenis_jabatan_id: '{{ $p->latestPosition()?->jenis_jabatan_id ?? '' }}',
                eselon_id: '{{ $p->latestPosition()?->eselon_id ?? '' }}',
                unit_kerja_id: '{{ $p->latestPosition()?->unit_kerja_id ?? '' }}',
                no_sk: '{{ $p->latestPosition()?->no_sk ?? '' }}',
                tanggal_sk: '{{ $p->latestPosition()?->tanggal_sk?->format('Y-m-d') ?? '' }}',
                tmt_jabatan: '{{ $p->latestPosition()?->tmt_jabatan?->format('Y-m-d') ?? '' }}',
            },
            
            kgbHistories: @js($p->salaryHistories->keyBy('id')),
            selectedKgbId: '{{ $p->latestSalary()?->id ?? 'new' }}',
            kgbForm: {
                gaji_pokok: '{{ $p->latestSalary()?->gaji_pokok ? (int) $p->latestSalary()->gaji_pokok : '' }}',
                no_sk: '{{ $p->latestSalary()?->no_sk ?? '' }}',
                tanggal_sk: '{{ $p->latestSalary()?->tanggal_sk?->format('Y-m-d') ?? '' }}',
                tmt_kgb: '{{ $p->latestSalary()?->tmt_kgb?->format('Y-m-d') ?? '' }}',
            },

            loadPangkatData() {
                if (this.selectedPangkatId === 'new') {
                    this.pangkatForm.golongan_id = '';
                    this.pangkatForm.no_sk = '';
                    this.pangkatForm.tanggal_sk = '';
                    this.pangkatForm.tmt_pangkat = '';
                    this.skPangkatName = '';
                    this.skPangkatSize = '';
                    this.skPangkatError = '';
                } else {
                    const data = this.pangkatHistories[this.selectedPangkatId];
                    if (data) {
                        this.pangkatForm.golongan_id = data.golongan_id || '';
                        this.pangkatForm.no_sk = data.no_sk || '';
                        this.pangkatForm.tanggal_sk = data.tanggal_sk ? data.tanggal_sk.substring(0, 10) : '';
                        this.pangkatForm.tmt_pangkat = data.tmt_pangkat ? data.tmt_pangkat.substring(0, 10) : '';
                        this.skPangkatName = data.file_sk ? data.file_sk.split('/').pop() : '';
                        this.skPangkatSize = '';
                        this.skPangkatError = '';
                    }
                }
            },
            loadJabatanData() {
                if (this.selectedJabatanId === 'new') {
                    this.jabatanForm.nama_jabatan = '';
                    this.jabatanForm.jenis_jabatan_id = '';
                    this.jabatanForm.eselon_id = '';
                    this.jabatanForm.unit_kerja_id = '';
                    this.jabatanForm.no_sk = '';
                    this.jabatanForm.tanggal_sk = '';
                    this.jabatanForm.tmt_jabatan = '';
                    this.skJabatanName = '';
                    this.skJabatanSize = '';
                    this.skJabatanError = '';
                } else {
                    const data = this.jabatanHistories[this.selectedJabatanId];
                    if (data) {
                        this.jabatanForm.nama_jabatan = data.nama_jabatan || '';
                        this.jabatanForm.jenis_jabatan_id = data.jenis_jabatan_id || '';
                        this.jabatanForm.eselon_id = data.eselon_id || '';
                        this.jabatanForm.unit_kerja_id = data.unit_kerja_id || '';
                        this.jabatanForm.no_sk = data.no_sk || '';
                        this.jabatanForm.tanggal_sk = data.tanggal_sk ? data.tanggal_sk.substring(0, 10) : '';
                        this.jabatanForm.tmt_jabatan = data.tmt_jabatan ? data.tmt_jabatan.substring(0, 10) : '';
                        this.skJabatanName = data.file_sk ? data.file_sk.split('/').pop() : '';
                        this.skJabatanSize = '';
                        this.skJabatanError = '';
                    }
                }
            },
            loadKgbData() {
                if (this.selectedKgbId === 'new') {
                    this.kgbForm.gaji_pokok = '';
                    this.kgbForm.no_sk = '';
                    this.kgbForm.tanggal_sk = '';
                    this.kgbForm.tmt_kgb = '';
                    this.skKgbName = '';
                    this.skKgbSize = '';
                    this.skKgbError = '';
                } else {
                    const data = this.kgbHistories[this.selectedKgbId];
                    if (data) {
                        this.kgbForm.gaji_pokok = data.gaji_pokok ? parseInt(data.gaji_pokok) : '';
                        this.kgbForm.no_sk = data.no_sk || '';
                        this.kgbForm.tanggal_sk = data.tanggal_sk ? data.tanggal_sk.substring(0, 10) : '';
                        this.kgbForm.tmt_kgb = data.tmt_kgb ? data.tmt_kgb.substring(0, 10) : '';
                        this.skKgbName = data.file_sk ? data.file_sk.split('/').pop() : '';
                        this.skKgbSize = '';
                        this.skKgbError = '';
                    }
                }
            },

            validateUtama() {
                const requiredIds = ['nama_lengkap', 'nip', 'tanggal_lahir', 'pendidikan_terakhir', 'prodi_pendidikan_terakhir'];
                for (let id of requiredIds) {
                    const el = document.getElementById(id);
                    if (el && !el.value.trim()) {
                        el.setCustomValidity('Mohon lengkapi isian kolom ini terlebih dahulu.');
                        el.reportValidity();
                        return false;
                    } else if (el) {
                        el.setCustomValidity('');
                    }
                }

                const elNip = document.getElementById('nip');
                if (this.nip.length < 18) {
                    this.nipError = 'NIP harus tepat 18 digit sebelum melanjutkan';
                    if (elNip) {
                        elNip.setCustomValidity('Mohon lengkapi NIP dengan tepat 18 digit.');
                        elNip.reportValidity();
                    }
                    return false;
                } else if (elNip) {
                    elNip.setCustomValidity('');
                }
                return true;
            },
            validateKontak() {
                const requiredIds = ['no_hp', 'alamat'];
                for (let id of requiredIds) {
                    const el = document.getElementById(id);
                    if (el && !el.value.trim()) {
                        el.setCustomValidity('Mohon lengkapi isian kolom ini terlebih dahulu.');
                        el.reportValidity();
                        return false;
                    } else if (el) {
                        el.setCustomValidity('');
                    }
                }
                return true;
            },
            validatePelengkap() {
                const elNik = document.getElementById('nik');
                if (this.nik.length < 16) {
                    this.nikError = 'NIK harus tepat 16 digit sebelum melanjutkan';
                    if (elNik) {
                        elNik.setCustomValidity('Mohon lengkapi NIK dengan tepat 16 digit.');
                        elNik.reportValidity();
                    }
                    return false;
                } else if (elNik) {
                    elNik.setCustomValidity('');
                }

                const elKk = document.getElementById('no_kk');
                if (this.kk.length > 0 && this.kk.length < 16) {
                    this.kkError = 'Nomor KK harus tepat 16 digit sebelum melanjutkan';
                    if (elKk) {
                        elKk.setCustomValidity('Mohon lengkapi Nomor KK dengan tepat 16 digit.');
                        elKk.reportValidity();
                    }
                    return false;
                } else if (elKk) {
                    elKk.setCustomValidity('');
                }
                return true;
            },
            validateNik() {
                this.nik = this.nik.replace(/\D/g, '');
                if (this.nik.length > 0 && this.nik.length < 16) {
                    this.nikError = 'NIK harus tepat 16 digit (Saat ini: ' + this.nik.length + ' digit)';
                } else {
                    this.nikError = '';
                }
            },
            validateKk() {
                this.kk = this.kk.replace(/\D/g, '');
                if (this.kk.length > 0 && this.kk.length < 16) {
                    this.kkError = 'Nomor KK harus tepat 16 digit (Saat ini: ' + this.kk.length + ' digit)';
                } else {
                    this.kkError = '';
                }
            },
            validateNip() {
                this.nip = this.nip.replace(/\D/g, '');
                if (this.nip.length > 0 && this.nip.length < 18) {
                    this.nipError = 'NIP harus tepat 18 digit (Saat ini: ' + this.nip.length + ' digit)';
                } else {
                    this.nipError = '';
                }
            },
            handleFotoChange(e) {
                const file = e.target.files[0];
                if (file) {
                    if (file.size > 10 * 1024 * 1024) {
                        alert('Ukuran berkas foto maksimal 10MB!');
                        e.target.value = '';
                        this.fotoPreview = null;
                        return;
                    }
                    this.fotoPreview = URL.createObjectURL(file);
                }
            },
            validateFile(file) {
                if (!file) return { name: '', size: '', error: '' };
                const allowedTypes = ['application/pdf', 'application/x-pdf', 'image/jpeg', 'image/png', 'image/jpg'];
                const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
                const sizeInMb = (file.size / (1024 * 1024)).toFixed(2);
                
                let isValidType = allowedTypes.includes(file.type);
                if (!isValidType) {
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (allowedExtensions.includes(ext)) {
                        isValidType = true;
                    }
                }
                
                if (!isValidType) {
                    return { name: '', size: '', error: 'Format berkas harus PDF, JPG, JPEG, atau PNG!' };
                }
                if (file.size > 10 * 1024 * 1024) {
                    return { name: file.name, size: sizeInMb + ' MB', error: 'Ukuran berkas melebihi batas 10MB! (Terdeteksi: ' + sizeInMb + 'MB)' };
                }
                return { name: file.name, size: sizeInMb + ' MB', error: '' };
            },
            handleSkPangkatChange(e) {
                const res = this.validateFile(e.target.files[0]);
                this.skPangkatName = res.name;
                this.skPangkatSize = res.size;
                this.skPangkatError = res.error;
                if (res.error) e.target.value = '';
            },
            handleSkJabatanChange(e) {
                const res = this.validateFile(e.target.files[0]);
                this.skJabatanName = res.name;
                this.skJabatanSize = res.size;
                this.skJabatanError = res.error;
                if (res.error) e.target.value = '';
            },
            handleSkKgbChange(e) {
                const res = this.validateFile(e.target.files[0]);
                this.skKgbName = res.name;
                this.skKgbSize = res.size;
                this.skKgbError = res.error;
                if (res.error) e.target.value = '';
            },
            handleSkPengangkatanChange(e) {
                const res = this.validateFile(e.target.files[0]);
                this.skPengangkatanName = res.name;
                this.skPengangkatanSize = res.size;
                this.skPengangkatanError = res.error;
                if (res.error) e.target.value = '';
            },
            // Pilih dari arsip: autofill No SK & Tanggal SK
            onSelectArsipPangkat() {
                const doc = this.arsipPangkatList.find(d => d.id === this.selectedArsipPangkatId);
                if (doc) {
                    this.skPangkatName = doc.nama_dokumen;
                    this.skPangkatSize = '';
                    this.skPangkatError = '';
                    if (doc.nomor_dokumen) this.pangkatForm.no_sk = doc.nomor_dokumen;
                    if (doc.tanggal_dokumen) this.pangkatForm.tanggal_sk = doc.tanggal_dokumen;
                }
            },
            onSelectArsipJabatan() {
                const doc = this.arsipJabatanList.find(d => d.id === this.selectedArsipJabatanId);
                if (doc) {
                    this.skJabatanName = doc.nama_dokumen;
                    this.skJabatanSize = '';
                    this.skJabatanError = '';
                    if (doc.nomor_dokumen) this.jabatanForm.no_sk = doc.nomor_dokumen;
                    if (doc.tanggal_dokumen) this.jabatanForm.tanggal_sk = doc.tanggal_dokumen;
                }
            },
            onSelectArsipKgb() {
                const doc = this.arsipKgbList.find(d => d.id === this.selectedArsipKgbId);
                if (doc) {
                    this.skKgbName = doc.nama_dokumen;
                    this.skKgbSize = '';
                    this.skKgbError = '';
                    if (doc.nomor_dokumen) this.kgbForm.no_sk = doc.nomor_dokumen;
                    if (doc.tanggal_dokumen) this.kgbForm.tanggal_sk = doc.tanggal_dokumen;
                }
            },
            onSelectArsipPengangkatan() {
                const doc = this.arsipPengangkatanList.find(d => d.id === this.selectedArsipPengangkatanId);
                if (doc) {
                    this.skPengangkatanName = doc.nama_dokumen;
                    this.skPengangkatanSize = '';
                    this.skPengangkatanError = '';
                    if (doc.nomor_dokumen) document.getElementById('pengangkatan_no_sk').value = doc.nomor_dokumen;
                    if (doc.tanggal_dokumen) document.getElementById('pengangkatan_tanggal_sk').value = doc.tanggal_dokumen;
                }
            }
        }">
            
            {{-- Tab Bar Navigasi --}}
            <div class="border-b border-border flex flex-wrap gap-4 md:gap-6 mb-6">
                <button type="button" @click="
                    if (activeTab === 'pelengkap' && !validatePelengkap()) return;
                    if (activeTab === 'kontak' && !validateKontak()) return;
                    activeTab = 'utama';
                "
                        :class="activeTab === 'utama' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    1. Data Utama
                </button>
                <button type="button" @click="
                    if (activeTab === 'utama' && !validateUtama()) return;
                    if (activeTab === 'kontak' && !validateKontak()) return;
                    activeTab = 'pelengkap';
                "
                        :class="activeTab === 'pelengkap' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    2. Data Pelengkap
                </button>
                <button type="button" @click="
                    if (activeTab === 'utama' && !validateUtama()) return;
                    if (activeTab === 'pelengkap' && !validatePelengkap()) return;
                    activeTab = 'kontak';
                "
                        :class="activeTab === 'kontak' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    3. Data Kontak
                </button>
                <button type="button" @click="
                    if (activeTab === 'utama' && !validateUtama()) return;
                    if (activeTab === 'pelengkap' && !validatePelengkap()) return;
                    if (activeTab === 'kontak' && !validateKontak()) return;
                    activeTab = 'pengangkatan';
                "
                        :class="activeTab === 'pengangkatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    4. Berkas & SK
                </button>
            </div>

            <form action="{{ route('pegawai.update', $p->id) }}" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate>
                @csrf

                {{-- TAB 1: DATA UTAMA --}}
                <div x-show="activeTab === 'utama'" class="space-y-6" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nama Lengkap --}}
                        <div class="space-y-1">
                            <label for="nama_lengkap" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Lengkap <span class="text-danger">*</span></label>
                            <input id="nama_lengkap" name="nama_lengkap" type="text" required placeholder="Ahmad Fauzi, S.Kom." class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->nama_lengkap }}" >
                        </div>

                        {{-- NIP --}}
                        <div class="space-y-1">
                            <label for="nip" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIP <span class="text-danger">*</span></label>
                            <input id="nip" name="nip" type="text" required maxlength="18" x-model="nip" @input="validateNip" value="{{ $p->nip }}" placeholder="198503122010011001" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="nipError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nipError"></p>
                        </div>

                        {{-- Status Kepegawaian (READ-ONLY - ubah melalui SK Pengangkatan di Berkas & SK) --}}
                        @php
                            $currentJenisPegawai = $jenisPegawai->firstWhere('id', $p->jenis_pegawai_id);
                        @endphp
                        <input type="hidden" name="jenis_pegawai_id" value="{{ $p->jenis_pegawai_id }}">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Kepegawaian</label>
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                @if($currentJenisPegawai)
                                    @php
                                        $badgeClass = match($currentJenisPegawai->nama) {
                                            'PNS'  => 'bg-blue-100 text-blue-700',
                                            'PPPK' => 'bg-green-100 text-green-700',
                                            'CPNS' => 'bg-yellow-100 text-yellow-700',
                                            default => 'bg-surface text-ink border border-border',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold {{ $badgeClass }}">{{ $currentJenisPegawai->nama }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via SK Pengangkatan</span>
                            </div>
                            <p class="text-[10px] text-muted font-sans mt-1">
                                Perubahan status kepegawaian harus disertai SK Pengangkatan. Buka tab
                                <button type="button" @click="activeTab = 'pengangkatan'; subTab = 'pengangkatan'" class="text-primary font-semibold hover:underline cursor-pointer">Berkas &amp; SK → Pengangkatan</button>
                                untuk mengunggah SK.
                            </p>
                        </div>

                        {{-- Tanggal Lahir --}}
                        <div class="space-y-1">
                            <label for="tanggal_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Lahir <span class="text-danger">*</span></label>
                            <input id="tanggal_lahir" name="tanggal_lahir" type="date" required max="{{ date('Y-m-d') }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->tanggal_lahir ? \Carbon\Carbon::parse($p->tanggal_lahir)->format('Y-m-d') : '' }}" >
                        </div>

                        {{-- Golongan (READ-ONLY - ubah melalui Berkas & SK) --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan</label>
                            <input type="hidden" name="golongan_terakhir" value="{{ $p->golongan_terakhir }}">
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span class="font-semibold">{{ $p->golongan_terakhir ?? '-' }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Pangkat (READ-ONLY - ubah melalui Berkas & SK) --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pangkat</label>
                            <input type="hidden" name="pangkat_terakhir" value="{{ $p->pangkat_terakhir }}">
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $p->pangkat_terakhir ?? '-' }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Jabatan (READ-ONLY - ubah melalui Berkas & SK) --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan Terakhir</label>
                            <input type="hidden" name="jabatan_terakhir" value="{{ $p->jabatan_terakhir }}">
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $p->jabatan_terakhir ?? '-' }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Jenis Jabatan (READ-ONLY - ubah melalui Berkas & SK) --}}
                        @php
                            $latestPosHistory = $p->positionHistories->where('is_latest', true)->first();
                            $currentJenisId = $latestPosHistory?->jenis_jabatan_id;
                            $currentJenisNama = $latestPosHistory?->jenisJabatan?->nama ?? '-';
                            $currentUnitId = $latestPosHistory?->unit_kerja_id;
                            $currentUnitNama = $latestPosHistory?->unitKerja?->nama ?? '-';
                        @endphp
                        <input type="hidden" name="jenis_jabatan_id" value="{{ $currentJenisId }}">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan</label>
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $currentJenisNama }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Unit Kerja (READ-ONLY - ubah melalui Berkas & SK) --}}
                        <input type="hidden" name="unit_kerja_id" value="{{ $currentUnitId }}">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja</label>
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $currentUnitNama }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Kelas Jabatan (READ-ONLY - ubah melalui Berkas & SK) --}}
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kelas Jabatan</label>
                            <input type="hidden" name="kelas_jabatan" value="{{ $p->kelas_jabatan }}">
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $p->kelas_jabatan ?? '-' }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Pendidikan Terakhir --}}
                        <div class="space-y-1">
                            <label for="pendidikan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pendidikan Terakhir <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="pendidikan_terakhir" name="pendidikan_terakhir" required class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="Diploma III (D3)" {{ $p->pendidikan_terakhir == 'Diploma III (D3)' ? 'selected' : '' }}>Diploma III (D3)</option>
                                    <option value="Sarjana (S1)" {{ $p->pendidikan_terakhir == 'Sarjana (S1)' ? 'selected' : '' }}>Sarjana (S1)</option>
                                    <option value="Magister (S2)" {{ $p->pendidikan_terakhir == 'Magister (S2)' ? 'selected' : '' }}>Magister (S2)</option>
                                    <option value="Doktor (S3)" {{ $p->pendidikan_terakhir == 'Doktor (S3)' ? 'selected' : '' }}>Doktor (S3)</option>
                                    <option value="SMA / Sederajat" {{ $p->pendidikan_terakhir == 'SMA / Sederajat' ? 'selected' : '' }}>SMA / Sederajat</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Program Studi --}}
                        <div class="space-y-1">
                            <label for="prodi_pendidikan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi <span class="text-danger">*</span></label>
                            <input id="prodi_pendidikan_terakhir" name="prodi_pendidikan_terakhir" type="text" required placeholder="Teknik Informatika" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->prodi_pendidikan_terakhir }}" >
                        </div>

                        

                        {{-- Tanggal Pensiun --}}
                        <div class="space-y-1">
                            <label for="tanggal_pensiun" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Pensiun</label>
                            <input id="tanggal_pensiun" name="tanggal_pensiun" type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->tanggal_pensiun ? \Carbon\Carbon::parse($p->tanggal_pensiun)->format('Y-m-d') : '' }}" >
                        </div>
                    </div>
                </div>

                {{-- TAB 2: DATA PELENGKAP --}}
                <div x-show="activeTab === 'pelengkap'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- NIK --}}
                        <div class="space-y-1">
                            <label for="nik" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIK (No. KTP) <span class="text-danger">*</span></label>
                            <input id="nik" name="nik" type="text" required maxlength="16" x-model="nik" @input="validateNik" placeholder="3273251203850002" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="nikError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nikError"></p>
                        </div>

                        {{-- KK --}}
                        <div class="space-y-1">
                            <label for="no_kk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Kartu Keluarga (KK)</label>
                            <input id="no_kk" name="no_kk" type="text" maxlength="16" x-model="kk" @input="validateKk" placeholder="3273250102120045" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="kkError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="kkError"></p>
                        </div>

                        {{-- Tempat Lahir --}}
                        <div class="space-y-1">
                            <label for="tempat_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tempat Lahir</label>
                            <input id="tempat_lahir" name="tempat_lahir" type="text" placeholder="Bandung" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->tempat_lahir }}" >
                        </div>

                        {{-- Jenis Kelamin --}}
                        <div class="space-y-1">
                            <label for="jenis_kelamin" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Kelamin</label>
                            <div class="relative">
                                <select id="jenis_kelamin" name="jenis_kelamin" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="L" {{ $p->jenis_kelamin == 'L' ? 'selected' : '' }}>Laki-laki</option>
                                    <option value="P" {{ $p->jenis_kelamin == 'P' ? 'selected' : '' }}>Perempuan</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Agama --}}
                        <div class="space-y-1">
                            <label for="agama_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Agama</label>
                            <div class="relative">
                                <select id="agama_id" name="agama_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="" disabled {{ empty($p->agama_id) ? 'selected' : '' }}>Pilih Agama</option>
                                    @foreach($agama as $a)
                                        <option value="{{ $a->id }}" {{ $p->agama_id == $a->id ? 'selected' : '' }}>{{ $a->nama }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Status Pernikahan --}}
                        <div class="space-y-1">
                            <label for="status_kawin_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Kawin</label>
                            <div class="relative">
                                <select id="status_kawin_id" name="status_kawin_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="" disabled {{ empty($p->status_kawin_id) ? 'selected' : '' }}>Pilih Status Kawin</option>
                                    @foreach($statusKawin as $sk)
                                        <option value="{{ $sk->id }}" {{ $p->status_kawin_id == $sk->id ? 'selected' : '' }}>{{ $sk->nama }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Golongan Darah --}}
                        <div class="space-y-1">
                            <label for="golongan_darah" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan Darah</label>
                            <div class="relative">
                                <select id="golongan_darah" name="golongan_darah" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="A" {{ $p->golongan_darah == 'A' ? 'selected' : '' }}>A</option>
                                    <option value="B" {{ $p->golongan_darah == 'B' ? 'selected' : '' }}>B</option>
                                    <option value="AB" {{ $p->golongan_darah == 'AB' ? 'selected' : '' }}>AB</option>
                                    <option value="O" {{ $p->golongan_darah == 'O' ? 'selected' : '' }}>O</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Upload Foto Profil --}}
                        <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans block">Foto Profil Pegawai</label>
                            <div class="flex items-center gap-4">
                                <div class="h-16 w-16 rounded-full border border-border bg-soft flex items-center justify-center overflow-hidden shrink-0">
                                    <img
                                        x-show="fotoPreview"
                                        :src="fotoPreview"
                                        src="{{ $fotoUrl ?? '' }}"
                                        alt="Foto {{ $p->nama_lengkap }}"
                                        class="h-full w-full object-cover"
                                        @if(! $fotoUrl) style="display: none;" @endif
                                    >
                                    <div x-show="!fotoPreview" @if($fotoUrl) style="display: none;" @endif>
                                        <svg class="h-8 w-8 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <input type="file" id="foto" name="foto" accept="image/*" @change="handleFotoChange" class="text-xs text-muted focus:outline-none file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 file:transition file:cursor-pointer">
                                    <p class="text-[10px] text-muted font-sans">Format file: JPG, PNG. Maksimal ukuran 10MB.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TAB 3: DATA KONTAK --}}
                <div x-show="activeTab === 'kontak'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Telepon Handphone --}}
                        <div class="space-y-1">
                            <label for="no_hp" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor HP <span class="text-danger">*</span></label>
                            <input id="no_hp" name="no_hp" type="tel" required placeholder="081234567890" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->no_hp }}" >
                        </div>

                        {{-- Telepon Rumah --}}
                        <div class="space-y-1">
                            <label for="no_telepon_rumah" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Telepon Rumah</label>
                            <input id="no_telepon_rumah" name="no_telepon_rumah" type="tel" placeholder="0227301234" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->no_telepon_rumah }}" >
                        </div>

                        {{-- Email Pribadi --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="email" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Email Pribadi</label>
                            <input id="email" name="email" type="email" placeholder="pegawai@domain.com" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->email }}" >
                        </div>

                        {{-- Alamat Lengkap --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="alamat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alamat Tempat Tinggal <span class="text-danger">*</span></label>
                            <textarea id="alamat" name="alamat" rows="3" required placeholder="Jl. Buah Batu No. 120, Lengkong, Bandung" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none">{{ $p->alamat }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- TAB 4: BERKAS & SK --}}
                <div x-show="activeTab === 'pengangkatan'" class="space-y-6" style="display: none;" x-transition>
                    {{-- Sub Navigation Bar --}}
                    <div class="border-b border-border flex flex-wrap gap-4 md:gap-6 mb-4">
                        <button type="button" @click="subTab = 'pangkat'"
                                :class="subTab === 'pangkat' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                                class="text-xs transition-colors cursor-pointer focus:outline-none font-sans">
                            A. SK Pangkat
                        </button>
                        <button type="button" @click="subTab = 'jabatan'"
                                :class="subTab === 'jabatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                                class="text-xs transition-colors cursor-pointer focus:outline-none font-sans">
                            B. SK Jabatan
                        </button>
                        <button type="button" @click="subTab = 'kgb'"
                                :class="subTab === 'kgb' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                                class="text-xs transition-colors cursor-pointer focus:outline-none font-sans">
                            C. SK KGB
                        </button>
                        <button type="button" @click="subTab = 'pengangkatan'"
                                :class="subTab === 'pengangkatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                                class="text-xs transition-colors cursor-pointer focus:outline-none font-sans">
                            D. SK Pengangkatan
                        </button>
                    </div>

                    {{-- SUB-TAB A: PANGKAT --}}
                    <div x-show="subTab === 'pangkat'" class="space-y-6" x-transition>
                        @php $latestRank = $p->latestRank(); @endphp
                        
                        <div class="space-y-1 mb-4 border-b border-border pb-4">
                            <label for="pangkat_history_id" class="text-xs font-bold text-primary uppercase tracking-wider font-sans">Pilih Riwayat Kepangkatan</label>
                            <div class="relative">
                                <select id="pangkat_history_id" name="pangkat_history_id" x-model="selectedPangkatId" @change="loadPangkatData()" class="w-full appearance-none rounded-lg border border-primary/50 bg-primary/5 px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer font-semibold">
                                    <option value="new">-- ✨ Tambah Riwayat Baru --</option>
                                    @foreach($p->rankHistories->sortByDesc('tmt_pangkat') as $rh)
                                        <option value="{{ $rh->id }}">Edit Riwayat: {{ $rh->golongan->nama }} (TMT: {{ $rh->tmt_pangkat ? $rh->tmt_pangkat->format('d-m-Y') : '-' }}) {{ $rh->is_latest ? '[Terbaru]' : '' }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-primary">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {{-- Golongan --}}
                            <div class="space-y-1">
                                <label for="pangkat_golongan_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="pangkat_golongan_id" name="pangkat_golongan_id" required x-model="pangkatForm.golongan_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled>Pilih Golongan</option>
                                        @foreach($golonganRefOptions as $gol)
                                            <option value="{{ $gol->id }}">{{ $gol->kode }} - {{ $gol->nama }}</option>
                                        @endforeach
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            {{-- Nomor SK Pangkat --}}
                            <div class="space-y-1">
                                <label for="pangkat_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pangkat <span class="text-danger">*</span></label>
                                <input id="pangkat_no_sk" name="pangkat_no_sk" type="text" required placeholder="SK-PANGKAT-321-KP-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="pangkatForm.no_sk">
                            </div>

                            {{-- Tanggal SK Pangkat --}}
                            <div class="space-y-1">
                                <label for="pangkat_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="pangkat_tanggal_sk" name="pangkat_tanggal_sk" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="pangkatForm.tanggal_sk">
                            </div>

                            {{-- TMT Pangkat --}}
                            <div class="space-y-1">
                                <label for="pangkat_tmt_pangkat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pangkat <span class="text-danger">*</span></label>
                                <input id="pangkat_tmt_pangkat" name="pangkat_tmt_pangkat" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="pangkatForm.tmt_pangkat">
                            </div>

                            {{-- Upload / Pilih Arsip SK Pangkat --}}
                            <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK Pangkat</label>
                                    {{-- Toggle Upload / Arsip --}}
                                    <div class="flex rounded-lg border border-border overflow-hidden text-xs font-sans" x-show="arsipPangkatList.length > 0">
                                        <button type="button" @click="skPangkatMode = 'upload'"
                                            :class="skPangkatMode === 'upload' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'"
                                            class="px-3 py-1 transition font-semibold cursor-pointer">Upload Baru</button>
                                        <button type="button" @click="skPangkatMode = 'arsip'"
                                            :class="skPangkatMode === 'arsip' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'"
                                            class="px-3 py-1 transition font-semibold cursor-pointer">Pilih dari Arsip</button>
                                    </div>
                                </div>

                                {{-- Mode: Upload Baru --}}
                                <div x-show="skPangkatMode === 'upload'" class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_pangkat" name="file_sk_pangkat" accept=".pdf,image/*" @change="handleSkPangkatChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                        </svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Pangkat (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skPangkatName && skPangkatMode === 'upload'">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skPangkatName"></span>
                                                <span class="text-muted" x-show="skPangkatSize" x-text="'(' + skPangkatSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($latestRank && $latestRank->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skPangkatSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $latestRank->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold font-mono">{{ basename($latestRank->file_sk) }}</a>
                                            </div>
                                        @endif
                                        <p x-show="skPangkatError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="skPangkatError"></p>
                                    </div>
                                </div>

                                {{-- Mode: Pilih dari Arsip --}}
                                <div x-show="skPangkatMode === 'arsip'" class="mt-1 space-y-2">
                                    <input type="hidden" name="existing_document_id_pangkat" :value="selectedArsipPangkatId">
                                    <div class="relative">
                                        <select x-model="selectedArsipPangkatId" @change="onSelectArsipPangkat()"
                                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                            <option value="">-- Pilih dokumen dari arsip --</option>
                                            <template x-for="doc in arsipPangkatList" :key="doc.id">
                                                <option :value="doc.id" x-text="doc.label"></option>
                                            </template>
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </div>
                                    </div>
                                    <template x-if="selectedArsipPangkatId">
                                        <p class="text-xs text-success font-semibold font-sans">✓ Dokumen arsip dipilih. No. SK dan Tanggal SK telah terisi otomatis.</p>
                                    </template>
                                    <p class="text-xs text-muted font-sans" x-show="arsipPangkatList.length === 0">Tidak ada dokumen SK Pangkat di arsip untuk pegawai ini.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- SUB-TAB B: JABATAN --}}
                    <div x-show="subTab === 'jabatan'" class="space-y-6" x-transition>
                        @php $latestPosition = $p->latestPosition(); @endphp
                        
                        <div class="space-y-1 mb-4 border-b border-border pb-4">
                            <label for="jabatan_history_id" class="text-xs font-bold text-primary uppercase tracking-wider font-sans">Pilih Riwayat Jabatan</label>
                            <div class="relative">
                                <select id="jabatan_history_id" name="jabatan_history_id" x-model="selectedJabatanId" @change="loadJabatanData()" class="w-full appearance-none rounded-lg border border-primary/50 bg-primary/5 px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer font-semibold">
                                    <option value="new">-- ✨ Tambah Riwayat Baru --</option>
                                    @foreach($p->positionHistories->sortByDesc('tmt_jabatan') as $jh)
                                        <option value="{{ $jh->id }}">Edit Riwayat: {{ $jh->nama_jabatan }} (TMT: {{ $jh->tmt_jabatan ? $jh->tmt_jabatan->format('d-m-Y') : '-' }}) {{ $jh->is_latest ? '[Terbaru]' : '' }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-primary">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {{-- Nama Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_nama_jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Jabatan <span class="text-danger">*</span></label>
                                <input id="jabatan_nama_jabatan" name="jabatan_nama_jabatan" type="text" required placeholder="Analis Kepegawaian" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="jabatanForm.nama_jabatan">
                            </div>

                            {{-- Jenis Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_jenis_jabatan_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="jabatan_jenis_jabatan_id" name="jabatan_jenis_jabatan_id" required x-model="jabatanForm.jenis_jabatan_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled>Pilih Jenis Jabatan</option>
                                        @foreach($jenisJabatanOptions as $jj)
                                            <option value="{{ $jj->id }}">{{ $jj->nama }}</option>
                                        @endforeach
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            {{-- Eselon --}}
                            <div class="space-y-1">
                                <label for="jabatan_eselon_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Eselon (Opsional)</label>
                                <div class="relative">
                                    <select id="jabatan_eselon_id" name="jabatan_eselon_id" x-model="jabatanForm.eselon_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="">-- Pilih --</option>
                                        @foreach($eselonOptions as $esl)
                                            <option value="{{ $esl->id }}">{{ $esl->nama }}</option>
                                        @endforeach
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            {{-- Unit Kerja --}}
                            <div class="space-y-1">
                                <label for="jabatan_unit_kerja_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="jabatan_unit_kerja_id" name="jabatan_unit_kerja_id" required x-model="jabatanForm.unit_kerja_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled>Pilih Unit Kerja</option>
                                        @foreach($unitKerja as $unit)
                                            <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                                        @endforeach
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            {{-- Nomor SK Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Jabatan <span class="text-danger">*</span></label>
                                <input id="jabatan_no_sk" name="jabatan_no_sk" type="text" required placeholder="SK-JABATAN-910-JAB-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="jabatanForm.no_sk">
                            </div>

                            {{-- Tanggal SK Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="jabatan_tanggal_sk" name="jabatan_tanggal_sk" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="jabatanForm.tanggal_sk">
                            </div>

                            {{-- TMT Jabatan --}}
                            <div class="space-y-1 sm:col-span-2">
                                <label for="jabatan_tmt_jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Jabatan <span class="text-danger">*</span></label>
                                <input id="jabatan_tmt_jabatan" name="jabatan_tmt_jabatan" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="jabatanForm.tmt_jabatan">
                            </div>

                            {{-- Upload / Pilih Arsip SK Jabatan --}}
                            <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK Jabatan</label>
                                    <div class="flex rounded-lg border border-border overflow-hidden text-xs font-sans" x-show="arsipJabatanList.length > 0">
                                        <button type="button" @click="skJabatanMode = 'upload'" :class="skJabatanMode === 'upload' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'" class="px-3 py-1 transition font-semibold cursor-pointer">Upload Baru</button>
                                        <button type="button" @click="skJabatanMode = 'arsip'" :class="skJabatanMode === 'arsip' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'" class="px-3 py-1 transition font-semibold cursor-pointer">Pilih dari Arsip</button>
                                    </div>
                                </div>
                                <div x-show="skJabatanMode === 'upload'" class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_jabatan" name="file_sk_jabatan" accept=".pdf,image/*" @change="handleSkJabatanChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Jabatan (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skJabatanName && skJabatanMode === 'upload'">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skJabatanName"></span>
                                                <span class="text-muted" x-show="skJabatanSize" x-text="'(' + skJabatanSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($latestPosition && $latestPosition->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skJabatanSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $latestPosition->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold font-mono">{{ basename($latestPosition->file_sk) }}</a>
                                            </div>
                                        @endif
                                        <p x-show="skJabatanError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="skJabatanError"></p>
                                    </div>
                                </div>
                                <div x-show="skJabatanMode === 'arsip'" class="mt-1 space-y-2">
                                    <input type="hidden" name="existing_document_id_jabatan" :value="selectedArsipJabatanId">
                                    <div class="relative">
                                        <select x-model="selectedArsipJabatanId" @change="onSelectArsipJabatan()" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                            <option value="">-- Pilih dokumen dari arsip --</option>
                                            <template x-for="doc in arsipJabatanList" :key="doc.id">
                                                <option :value="doc.id" x-text="doc.label"></option>
                                            </template>
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></div>
                                    </div>
                                    <template x-if="selectedArsipJabatanId">
                                        <p class="text-xs text-success font-semibold font-sans">✓ Dokumen arsip dipilih. No. SK dan Tanggal SK telah terisi otomatis.</p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- SUB-TAB C: KGB --}}
                    <div x-show="subTab === 'kgb'" class="space-y-6" x-transition>
                        @php $latestSalary = $p->latestSalary(); @endphp
                        
                        <div class="space-y-1 mb-4 border-b border-border pb-4">
                            <label for="kgb_history_id" class="text-xs font-bold text-primary uppercase tracking-wider font-sans">Pilih Riwayat KGB</label>
                            <div class="relative">
                                <select id="kgb_history_id" name="kgb_history_id" x-model="selectedKgbId" @change="loadKgbData()" class="w-full appearance-none rounded-lg border border-primary/50 bg-primary/5 px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer font-semibold">
                                    <option value="new">-- ✨ Tambah Riwayat Baru --</option>
                                    @foreach($p->salaryHistories->sortByDesc('tmt_kgb') as $sh)
                                        <option value="{{ $sh->id }}">Edit Riwayat: Rp {{ number_format($sh->gaji_pokok, 0, ',', '.') }} (TMT: {{ $sh->tmt_kgb ? $sh->tmt_kgb->format('d-m-Y') : '-' }}) {{ $sh->is_latest ? '[Terbaru]' : '' }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-primary">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {{-- Gaji Pokok --}}
                            <div class="space-y-1">
                                <label for="kgb_gaji_pokok" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Gaji Pokok Terakhir <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                        <span class="text-muted text-sm font-semibold">Rp</span>
                                    </div>
                                    <input id="kgb_gaji_pokok" name="kgb_gaji_pokok" type="number" required placeholder="5000000" class="w-full rounded-lg border border-border bg-surface pl-12 pr-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="kgbForm.gaji_pokok">
                                </div>
                            </div>

                            {{-- Nomor SK KGB --}}
                            <div class="space-y-1">
                                <label for="kgb_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK KGB <span class="text-danger">*</span></label>
                                <input id="kgb_no_sk" name="kgb_no_sk" type="text" required placeholder="SK-KGB-543-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="kgbForm.no_sk">
                            </div>

                            {{-- Tanggal SK KGB --}}
                            <div class="space-y-1">
                                <label for="kgb_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="kgb_tanggal_sk" name="kgb_tanggal_sk" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="kgbForm.tanggal_sk">
                            </div>

                            {{-- TMT KGB --}}
                            <div class="space-y-1">
                                <label for="kgb_tmt_kgb" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT KGB <span class="text-danger">*</span></label>
                                <input id="kgb_tmt_kgb" name="kgb_tmt_kgb" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="kgbForm.tmt_kgb">
                            </div>

                            {{-- Upload / Pilih Arsip SK KGB --}}
                            <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK KGB</label>
                                    <div class="flex rounded-lg border border-border overflow-hidden text-xs font-sans" x-show="arsipKgbList.length > 0">
                                        <button type="button" @click="skKgbMode = 'upload'" :class="skKgbMode === 'upload' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'" class="px-3 py-1 transition font-semibold cursor-pointer">Upload Baru</button>
                                        <button type="button" @click="skKgbMode = 'arsip'" :class="skKgbMode === 'arsip' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'" class="px-3 py-1 transition font-semibold cursor-pointer">Pilih dari Arsip</button>
                                    </div>
                                </div>
                                <div x-show="skKgbMode === 'upload'" class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_kgb" name="file_sk_kgb" accept=".pdf,image/*" @change="handleSkKgbChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK KGB (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skKgbName && skKgbMode === 'upload'">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skKgbName"></span>
                                                <span class="text-muted" x-show="skKgbSize" x-text="'(' + skKgbSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($latestSalary && $latestSalary->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skKgbSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $latestSalary->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold font-mono">{{ basename($latestSalary->file_sk) }}</a>
                                            </div>
                                        @endif
                                        <p x-show="skKgbError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="skKgbError"></p>
                                    </div>
                                </div>
                                <div x-show="skKgbMode === 'arsip'" class="mt-1 space-y-2">
                                    <input type="hidden" name="existing_document_id_kgb" :value="selectedArsipKgbId">
                                    <div class="relative">
                                        <select x-model="selectedArsipKgbId" @change="onSelectArsipKgb()" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                            <option value="">-- Pilih dokumen dari arsip --</option>
                                            <template x-for="doc in arsipKgbList" :key="doc.id">
                                                <option :value="doc.id" x-text="doc.label"></option>
                                            </template>
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></div>
                                    </div>
                                    <template x-if="selectedArsipKgbId">
                                        <p class="text-xs text-success font-semibold font-sans">✓ Dokumen arsip dipilih. No. SK dan Tanggal SK telah terisi otomatis.</p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- SUB-TAB D: PENGANGKATAN --}}
                    <div x-show="subTab === 'pengangkatan'" class="space-y-6" x-transition>
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {{-- Jenis Pengangkatan --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_jenis_pengangkatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Pengangkatan <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="pengangkatan_jenis_pengangkatan" name="pengangkatan_jenis_pengangkatan" required class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled {{ empty($p->appointment->jenis_pengangkatan) ? 'selected' : '' }}>Pilih Jenis Pengangkatan</option>
                                        <option value="CPNS" {{ ($p->appointment->jenis_pengangkatan ?? '') == 'CPNS' ? 'selected' : '' }}>CPNS</option>
                                        <option value="PNS" {{ ($p->appointment->jenis_pengangkatan ?? '') == 'PNS' ? 'selected' : '' }}>PNS</option>
                                        <option value="PPPK" {{ ($p->appointment->jenis_pengangkatan ?? '') == 'PPPK' ? 'selected' : '' }}>PPPK</option>
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
                                <label for="pengangkatan_tmt_pengangkatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pengangkatan <span class="text-danger">*</span></label>
                                <input id="pengangkatan_tmt_pengangkatan" name="pengangkatan_tmt_pengangkatan" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->appointment && $p->appointment->tmt_pengangkatan ? \Carbon\Carbon::parse($p->appointment->tmt_pengangkatan)->format('Y-m-d') : '' }}" >
                            </div>

                            {{-- Nomor SK Pengangkatan --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pengangkatan <span class="text-danger">*</span></label>
                                <input id="pengangkatan_no_sk" name="pengangkatan_no_sk" type="text" required placeholder="SK-882-KP-2024" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->appointment ? $p->appointment->no_sk : '' }}" >
                            </div>

                            {{-- Tanggal SK --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit <span class="text-danger">*</span></label>
                                <input id="pengangkatan_tanggal_sk" name="pengangkatan_tanggal_sk" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->appointment && $p->appointment->tanggal_sk ? \Carbon\Carbon::parse($p->appointment->tanggal_sk)->format('Y-m-d') : '' }}" >
                            </div>

                            {{-- Upload / Pilih Arsip SK Pengangkatan --}}
                            <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK Pengangkatan</label>
                                    <div class="flex rounded-lg border border-border overflow-hidden text-xs font-sans" x-show="arsipPengangkatanList.length > 0">
                                        <button type="button" @click="skPengangkatanMode = 'upload'" :class="skPengangkatanMode === 'upload' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'" class="px-3 py-1 transition font-semibold cursor-pointer">Upload Baru</button>
                                        <button type="button" @click="skPengangkatanMode = 'arsip'" :class="skPengangkatanMode === 'arsip' ? 'bg-primary text-white' : 'bg-surface text-muted hover:text-ink'" class="px-3 py-1 transition font-semibold cursor-pointer">Pilih dari Arsip</button>
                                    </div>
                                </div>
                                <div x-show="skPengangkatanMode === 'upload'" class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_pengangkatan" name="file_sk_pengangkatan" accept=".pdf,image/*" @change="handleSkPengangkatanChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Pengangkatan (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skPengangkatanName && skPengangkatanMode === 'upload'">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skPengangkatanName"></span>
                                                <span class="text-muted" x-show="skPengangkatanSize" x-text="'(' + skPengangkatanSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($p->appointment && $p->appointment->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skPengangkatanSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $p->appointment->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold font-mono">{{ basename($p->appointment->file_sk) }}</a>
                                            </div>
                                        @endif
                                        <p x-show="skPengangkatanError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="skPengangkatanError"></p>
                                    </div>
                                </div>
                                <div x-show="skPengangkatanMode === 'arsip'" class="mt-1 space-y-2">
                                    <input type="hidden" name="existing_document_id_pengangkatan" :value="selectedArsipPengangkatanId">
                                    <div class="relative">
                                        <select x-model="selectedArsipPengangkatanId" @change="onSelectArsipPengangkatan()" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                            <option value="">-- Pilih dokumen dari arsip --</option>
                                            <template x-for="doc in arsipPengangkatanList" :key="doc.id">
                                                <option :value="doc.id" x-text="doc.label"></option>
                                            </template>
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg></div>
                                    </div>
                                    <template x-if="selectedArsipPengangkatanId">
                                        <p class="text-xs text-success font-semibold font-sans">✓ Dokumen arsip dipilih. No. SK dan Tanggal SK telah terisi otomatis.</p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-6 flex justify-between items-center gap-3">
                    <div>
                        <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                            </svg>
                            Batal
                        </a>
                    </div>
                    <div class="flex items-center gap-3">
                        {{-- Tombol Sebelumnya --}}
                        <button type="button" 
                                x-show="activeTab !== 'utama'" 
                                @click="activeTab = activeTab === 'pengangkatan' ? 'kontak' : (activeTab === 'kontak' ? 'pelengkap' : 'utama')" 
                                class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans cursor-pointer">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                            Sebelumnya
                        </button>
                        
                        {{-- Tombol Selanjutnya --}}
                        <button type="button" 
                                x-show="activeTab !== 'pengangkatan'" 
                                @click="
                                    if (activeTab === 'utama') {
                                        if (!validateUtama()) return;
                                        activeTab = 'pelengkap';
                                    } else if (activeTab === 'pelengkap') {
                                        if (!validatePelengkap()) return;
                                        activeTab = 'kontak';
                                    } else if (activeTab === 'kontak') {
                                        if (!validateKontak()) return;
                                        activeTab = 'pengangkatan';
                                    }
                                " 
                                class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm font-sans cursor-pointer">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                            Selanjutnya
                        </button>

                        {{-- Tombol Simpan --}}
                        <button type="submit" 
                                :disabled="nip.length < 18 || (nik.length > 0 && nik.length < 16) || (kk.length > 0 && kk.length < 16) || skPangkatError !== '' || skJabatanError !== '' || skKgbError !== '' || skPengangkatanError !== ''"
                                :class="(nip.length < 18 || (nik.length > 0 && nik.length < 16) || (kk.length > 0 && kk.length < 16) || skPangkatError !== '' || skJabatanError !== '' || skKgbError !== '' || skPengangkatanError !== '') ? 'opacity-50 cursor-not-allowed' : ''"
                                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            Simpan Pegawai
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const requiredElements = document.querySelectorAll('input[required], select[required], textarea[required]');
            requiredElements.forEach(el => {
                el.addEventListener('invalid', function(e) {
                    if (e.target.validity.valueMissing) {
                        e.target.setCustomValidity('Mohon lengkapi isian kolom ini terlebih dahulu.');
                    }
                });
                el.addEventListener('input', function(e) {
                    e.target.setCustomValidity('');
                });
                el.addEventListener('change', function(e) {
                    e.target.setCustomValidity('');
                });
            });
        });
    </script>
    @endpush
</x-layouts.app>
