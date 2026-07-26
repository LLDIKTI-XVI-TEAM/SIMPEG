<x-layouts.app title="Edit Pegawai">
    @php
        $fotoUrl = $p->foto_url;
        $isPppkEmployee = strcasecmp((string) $p->jenisPegawai?->nama, 'PPPK') === 0;
        $pppkAppointment = $p->appointments
            ->filter(fn ($appointment) => strcasecmp((string) $appointment->jenis_pengangkatan, 'PPPK') === 0)
            ->sortByDesc('tmt_pengangkatan')
            ->first();
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
            <x-ui.alert variant="danger" title="Gagal Menyimpan:" class="mb-4">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        {{-- Validation Errors --}}
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Terdapat kesalahan pengisian form" class="mb-4" />
        @endif

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm" x-data="{
            isSubmitting: false,
            activeTab: 'utama',
            subTab: 'pangkat',
            nip: '{{ old('nip', $p->nip ?? '') }}',
            nipError: '',
            nik: '{{ old('nik', $p->nik ?? '') }}',
            kk: '{{ old('kk', $p->no_kk ?? '') }}',
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
            arsipPengangkatanList: @js($arsipPengangkatan ?? []),

            berkasLainnyaName: '',
            berkasLainnyaSize: '',
            berkasLainnyaError: '',
            berkasLainnyaForm: {
                jenis: '',
                jenis_manual: '',
                nomor_dokumen: '',
                deskripsi: '',
                tanggal: ''
            },

            generateNomorDokumen() {
                const jenis = this.berkasLainnyaForm.jenis === 'Lainnya' ? (this.berkasLainnyaForm.jenis_manual || 'BERKAS') : (this.berkasLainnyaForm.jenis || 'BERKAS');

                // Format prefix: hapus spasi, ambil alphanumerik, jadikan huruf besar. Max 10 char.
                const prefix = jenis.replace(/[^a-zA-Z0-9]/g, '').substring(0, 10).toUpperCase();

                const randomNum = Math.floor(1000 + Math.random() * 9000); // 4 digit random number

                const today = new Date();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const year = today.getFullYear();

                this.berkasLainnyaForm.nomor_dokumen = `${prefix}-${randomNum}-${month}-${year}`;
            },

            // Riwayat bersifat append-only: form riwayat selalu menambah record baru sehingga nilai awal dikosongkan.
            pangkatForm: {
                golongan_id: '',
                no_sk: '',
                tanggal_sk: '',
                tmt_pangkat: '',
            },

            jabatanForm: {
                jabatan_id: '',
                jenis_jabatan_id: '',
                eselon_id: '',
                unit_kerja_id: '',
                kelas_jabatan: '',
                no_sk: '',
                tanggal_sk: '',
                tmt_jabatan: '',
            },

            kgbForm: {
                gaji_pokok: '',
                no_sk: '',
                tanggal_sk: '',
                tmt_kgb: '',
            },

            validateUtama() {
                return true;
            },
            validateKontak() {
                return true;
            },
            validatePelengkap() {
                return true;
            },
            validateNik() {
                this.nik = this.nik.replace(/\D/g, '');
                if (this.nik.length > 0 && this.nik.length < 16) {
                    this.nikError = '';
                } else {
                    this.nikError = '';
                }
            },
            validateKk() {
                this.kk = this.kk.replace(/\D/g, '');
                if (this.kk.length > 0 && this.kk.length < 16) {
                    this.kkError = '';
                } else {
                    this.kkError = '';
                }
            },
            validateNip() {
                this.nip = this.nip.replace(/\D/g, '');
                if (this.nip.length > 0 && this.nip.length < 18) {
                    this.nipError = '';
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
            handleBerkasLainnyaChange(e) {
                const res = this.validateFile(e.target.files[0]);
                this.berkasLainnyaName = res.name;
                this.berkasLainnyaSize = res.size;
                this.berkasLainnyaError = res.error;
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

            <form action="{{ route('pegawai.update', $p->id) }}" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate @submit="isSubmitting = true">
                @csrf

                {{-- TAB 1: DATA UTAMA --}}
                <div x-show="activeTab === 'utama'" class="space-y-6" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nama dengan Gelar --}}
                        <div class="space-y-1">
                            <label for="nama_dengan_gelar" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama dengan Gelar</label>
                            <input id="nama_dengan_gelar" name="nama_dengan_gelar" type="text" placeholder="Grantly Sorongan, S.Kom." class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ old('nama_dengan_gelar', $p->nama_dengan_gelar) }}">
                            <p class="text-xs text-muted">Nama yang ditampilkan pada kartu &amp; header. Boleh kosong jika sama dengan nama lengkap.</p>
                        </div>

                        {{-- Nama Lengkap (tanpa gelar) --}}
                        <div class="space-y-1">
                            <label for="nama_lengkap" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Lengkap (tanpa gelar)</label>
                            <input id="nama_lengkap" name="nama_lengkap" type="text" placeholder="Grantly Antonio Edward Sorongan" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ old('nama_lengkap', $p->nama_lengkap) }}">
                            <p class="text-xs text-muted">Nama lengkap resmi sesuai KTP atau SK, tanpa gelar akademik. Data ini penting untuk dilengkapi.</p>
                        </div>

                        {{-- NIP --}}
                        <div class="space-y-1">
                            <label for="nip" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIP</label>
                            <input id="nip" name="nip" type="text" maxlength="18" x-model="nip" @input="validateNip" value="{{ $p->nip }}" placeholder="198503122010011001" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
                            <p x-show="nipError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nipError"></p>
                        </div>

                        {{-- Status Kepegawaian (READ-ONLY - ubah melalui SK Pengangkatan di Berkas & SK) --}}
                        @php
                            $currentJenisPegawai = $jenisPegawai->firstWhere('id', $p->jenis_pegawai_id);
                            $currentStatusPegawai = $statusPegawai->firstWhere('id', $p->status_pegawai_id);
                        @endphp
                        <input type="hidden" name="jenis_pegawai_id" value="{{ $p->jenis_pegawai_id }}">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Pegawai</label>
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                @if($currentJenisPegawai)
                                    <x-ui.badge variant="primary" size="md" class="!font-bold">{{ $currentJenisPegawai->nama }}</x-ui.badge>
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

                        <input type="hidden" name="status_pegawai_id" value="{{ $p->status_pegawai_id }}">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Pegawai</label>
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $currentStatusPegawai?->nama ?? $p->status_aktif ?? '-' }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Sinkron PRD</span>
                            </div>
                        </div>

                        {{-- Tanggal Lahir --}}
                        <div class="space-y-1">
                            <label for="tanggal_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Lahir</label>
                            <input id="tanggal_lahir" name="tanggal_lahir" type="date" max="{{ date('Y-m-d') }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->tanggal_lahir ? \Carbon\Carbon::parse($p->tanggal_lahir)->format('Y-m-d') : '' }}" >
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
                        </div>

                        {{-- Penanda eksplisit agar alur cuti tidak menebak Kepala Lembaga dari nama jabatan bebas. --}}
                        <div class="space-y-1 rounded-lg border border-border bg-soft/40 p-4 sm:col-span-2">
                            <input type="hidden" name="is_kepala_lembaga" value="0">
                            <label for="is_kepala_lembaga" class="flex items-start gap-3 text-sm font-semibold text-ink font-sans">
                                <input id="is_kepala_lembaga" name="is_kepala_lembaga" type="checkbox" value="1" @checked(old('is_kepala_lembaga', $p->is_kepala_lembaga)) class="mt-1 rounded border-border text-primary focus:ring-primary/20">
                                <span>
                                    Kepala Lembaga
                                    <span class="block text-[10px] font-normal text-muted">Aktifkan hanya untuk pegawai yang berwenang memberi keputusan Kepala Lembaga pada alur cuti.</span>
                                </span>
                            </label>
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
                            <input type="hidden" name="kelas_jabatan_terakhir" value="{{ $p->kelas_jabatan_terakhir }}">
                            <div class="flex items-center gap-2 w-full rounded-lg border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm font-sans cursor-not-allowed">
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <span>{{ $p->kelas_jabatan_terakhir ?? '-' }}</span>
                                <span class="ml-auto text-[10px] text-muted font-sans">Ubah via Berkas &amp; SK</span>
                            </div>
                        </div>

                        {{-- Pendidikan Terakhir --}}
                        <div class="space-y-1">
                            <label for="pendidikan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pendidikan Terakhir</label>
                            <div class="relative">
                                <select id="pendidikan_terakhir" name="pendidikan_terakhir" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
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
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
                        </div>

                        {{-- Program Studi --}}
                        <div class="space-y-1">
                            <label for="prodi_pendidikan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi</label>
                            <input id="prodi_pendidikan_terakhir" name="prodi_pendidikan_terakhir" type="text" placeholder="Teknik Informatika" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->prodi_pendidikan_terakhir }}" >
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
                        </div>

                        
                    </div>
                </div>

                {{-- TAB 2: DATA PELENGKAP --}}
                <div x-show="activeTab === 'pelengkap'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- NIK --}}
                        <div class="space-y-1">
                            <label for="nik" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIK (No. KTP)</label>
                            <input id="nik" name="nik" type="text" maxlength="16" x-model="nik" @input="validateNik" placeholder="3273251203850002" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
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
                                        class="h-full w-full object-cover object-[center_25%]"
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
                            <label for="no_hp" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor HP</label>
                            <input id="no_hp" name="no_hp" type="tel" placeholder="081234567890" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->no_hp }}" >
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
                        </div>

                        {{-- Telepon Rumah --}}
                        <div class="space-y-1">
                            <label for="no_telepon_rumah" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Telepon Rumah</label>
                            <input id="no_telepon_rumah" name="no_telepon_rumah" type="tel" placeholder="0227301234" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->no_telepon_rumah }}" >
                        </div>

                        {{-- Email Pribadi --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="email_pribadi" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Email Pribadi</label>
                            <input id="email_pribadi" name="email_pribadi" type="email" placeholder="pegawai@domain.com" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->email_pribadi }}" >
                        </div>

                        {{-- Alamat Lengkap --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="alamat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alamat Tempat Tinggal</label>
                            <textarea id="alamat" name="alamat" rows="3" placeholder="Jl. Buah Batu No. 120, Lengkong, Bandung" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none">{{ $p->alamat }}</textarea>
                            <p class="text-xs text-muted">Data ini penting untuk dilengkapi.</p>
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
                        <button type="button" @click="subTab = 'lainnya'"
                                :class="subTab === 'lainnya' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                                class="text-xs transition-colors cursor-pointer focus:outline-none font-sans">
                            E. Berkas Lainnya
                        </button>
                    </div>

                    {{-- SUB-TAB A: PANGKAT --}}
                    <div x-show="subTab === 'pangkat'" class="space-y-6" x-transition>
                        @php $latestRank = $p->latestRank(); @endphp
                        
                        <div class="mb-4 border-b border-border pb-4">
                            <div class="rounded-lg border border-primary/30 bg-primary/5 px-4 py-3 font-sans">
                                <p class="text-xs font-bold text-primary uppercase tracking-wider">Tambah Riwayat Kepangkatan Baru</p>
                                <p class="mt-1 text-xs text-muted">Riwayat kepangkatan bersifat append-only — riwayat yang sudah tersimpan tidak dapat diubah dari form ini.</p>
                                @if($latestRank)
                                    <p class="mt-1 text-xs text-muted">Riwayat terbaru: <span class="font-semibold text-ink">{{ $latestRank->golongan?->nama ?? '-' }}</span> (TMT: {{ $latestRank->tmt_pangkat ? $latestRank->tmt_pangkat->format('d-m-Y') : '-' }})</p>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {{-- Golongan --}}
                            <div class="space-y-1">
                                <label for="pangkat_golongan_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="pangkat_golongan_id" name="pangkat_golongan_id"  x-model="pangkatForm.golongan_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
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
                                <input id="pangkat_no_sk" name="pangkat_no_sk" type="text"  placeholder="SK-PANGKAT-321-KP-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="pangkatForm.no_sk">
                            </div>

                            {{-- Tanggal SK Pangkat --}}
                            <div class="space-y-1">
                                <label for="pangkat_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="pangkat_tanggal_sk" name="pangkat_tanggal_sk" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="pangkatForm.tanggal_sk">
                            </div>

                            {{-- TMT Pangkat --}}
                            <div class="space-y-1">
                                <label for="pangkat_tmt_pangkat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pangkat <span class="text-danger">*</span></label>
                                <input id="pangkat_tmt_pangkat" name="pangkat_tmt_pangkat" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="pangkatForm.tmt_pangkat">
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
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skPangkatName"></span>
                                                <span class="text-muted" x-show="skPangkatSize" x-text="'(' + skPangkatSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($latestRank && $latestRank->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skPangkatSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $latestRank->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold">{{ basename($latestRank->file_sk) }}</a>
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
                        
                        <div class="mb-4 border-b border-border pb-4">
                            <div class="rounded-lg border border-primary/30 bg-primary/5 px-4 py-3 font-sans">
                                <p class="text-xs font-bold text-primary uppercase tracking-wider">Tambah Riwayat Jabatan Baru</p>
                                <p class="mt-1 text-xs text-muted">Riwayat jabatan bersifat append-only — riwayat yang sudah tersimpan tidak dapat diubah dari form ini.</p>
                                @if($latestPosition)
                                    <p class="mt-1 text-xs text-muted">Riwayat terbaru: <span class="font-semibold text-ink">{{ $latestPosition->nama_jabatan ?? '-' }}</span> (TMT: {{ $latestPosition->tmt_jabatan ? $latestPosition->tmt_jabatan->format('d-m-Y') : '-' }})</p>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {{-- Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_jabatan_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="jabatan_jabatan_id" name="jabatan_jabatan_id"  x-model="jabatanForm.jabatan_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled>Pilih Jabatan</option>
                                        @foreach($jabatanOptions as $jabatan)
                                            <option value="{{ $jabatan->id }}">{{ $jabatan->nama }}{{ $jabatan->jenisJabatan ? ' - '.$jabatan->jenisJabatan->nama : '' }}</option>
                                        @endforeach
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            {{-- Jenis Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_jenis_jabatan_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan</label>
                                <div class="relative">
                                    <select id="jabatan_jenis_jabatan_id" name="jabatan_jenis_jabatan_id" x-model="jabatanForm.jenis_jabatan_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
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

                            {{-- Kelas Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_kelas_jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kelas Jabatan</label>
                                <input id="jabatan_kelas_jabatan" name="jabatan_kelas_jabatan" type="text" placeholder="8" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="jabatanForm.kelas_jabatan">
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
                                    <select id="jabatan_unit_kerja_id" name="jabatan_unit_kerja_id"  x-model="jabatanForm.unit_kerja_id" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
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
                                <input id="jabatan_no_sk" name="jabatan_no_sk" type="text"  placeholder="SK-JABATAN-910-JAB-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="jabatanForm.no_sk">
                            </div>

                            {{-- Tanggal SK Jabatan --}}
                            <div class="space-y-1">
                                <label for="jabatan_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="jabatan_tanggal_sk" name="jabatan_tanggal_sk" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="jabatanForm.tanggal_sk">
                            </div>

                            {{-- TMT Jabatan --}}
                            <div class="space-y-1 sm:col-span-2">
                                <label for="jabatan_tmt_jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Jabatan <span class="text-danger">*</span></label>
                                <input id="jabatan_tmt_jabatan" name="jabatan_tmt_jabatan" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="jabatanForm.tmt_jabatan">
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
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skJabatanName"></span>
                                                <span class="text-muted" x-show="skJabatanSize" x-text="'(' + skJabatanSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($latestPosition && $latestPosition->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skJabatanSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $latestPosition->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold">{{ basename($latestPosition->file_sk) }}</a>
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
                        
                        <div class="mb-4 border-b border-border pb-4">
                            <div class="rounded-lg border border-primary/30 bg-primary/5 px-4 py-3 font-sans">
                                <p class="text-xs font-bold text-primary uppercase tracking-wider">Tambah Riwayat KGB Baru</p>
                                <p class="mt-1 text-xs text-muted">Riwayat KGB bersifat append-only — riwayat yang sudah tersimpan tidak dapat diubah dari form ini.</p>
                                @if($latestSalary)
                                    <p class="mt-1 text-xs text-muted">Riwayat terbaru: <span class="font-semibold text-ink">Rp {{ number_format($latestSalary->gaji_pokok, 0, ',', '.') }}</span> (TMT: {{ $latestSalary->tmt_kgb ? $latestSalary->tmt_kgb->format('d-m-Y') : '-' }})</p>
                                @endif
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
                                    <input id="kgb_gaji_pokok" name="kgb_gaji_pokok" type="number"  placeholder="5000000" class="w-full rounded-lg border border-border bg-surface pl-12 pr-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="kgbForm.gaji_pokok">
                                </div>
                            </div>

                            {{-- Nomor SK KGB --}}
                            <div class="space-y-1">
                                <label for="kgb_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK KGB <span class="text-danger">*</span></label>
                                <input id="kgb_no_sk" name="kgb_no_sk" type="text"  placeholder="SK-KGB-543-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="kgbForm.no_sk">
                            </div>

                            {{-- Tanggal SK KGB --}}
                            <div class="space-y-1">
                                <label for="kgb_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="kgb_tanggal_sk" name="kgb_tanggal_sk" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="kgbForm.tanggal_sk">
                            </div>

                            {{-- TMT KGB --}}
                            <div class="space-y-1">
                                <label for="kgb_tmt_kgb" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT KGB <span class="text-danger">*</span></label>
                                <input id="kgb_tmt_kgb" name="kgb_tmt_kgb" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="kgbForm.tmt_kgb">
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
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skKgbName"></span>
                                                <span class="text-muted" x-show="skKgbSize" x-text="'(' + skKgbSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($latestSalary && $latestSalary->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skKgbSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $latestSalary->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold">{{ basename($latestSalary->file_sk) }}</a>
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
                                    <select id="pengangkatan_jenis_pengangkatan" name="pengangkatan_jenis_pengangkatan" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
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
                                <input id="pengangkatan_tmt_pengangkatan" name="pengangkatan_tmt_pengangkatan" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->appointment && $p->appointment->tmt_pengangkatan ? \Carbon\Carbon::parse($p->appointment->tmt_pengangkatan)->format('Y-m-d') : '' }}" >
                            </div>

                            {{-- Nomor SK Pengangkatan --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pengangkatan <span class="text-danger">*</span></label>
                                <input id="pengangkatan_no_sk" name="pengangkatan_no_sk" type="text"  placeholder="SK-882-KP-2024" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ $p->appointment ? $p->appointment->no_sk : '' }}" >
                            </div>

                            {{-- Tanggal SK --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit <span class="text-danger">*</span></label>
                                <input id="pengangkatan_tanggal_sk" name="pengangkatan_tanggal_sk" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" value="{{ $p->appointment && $p->appointment->tanggal_sk ? \Carbon\Carbon::parse($p->appointment->tanggal_sk)->format('Y-m-d') : '' }}" >
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
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skPengangkatanName"></span>
                                                <span class="text-muted" x-show="skPengangkatanSize" x-text="'(' + skPengangkatanSize + ')'"></span>
                                            </div>
                                        </template>
                                        @if($p->appointment && $p->appointment->file_sk)
                                            <div class="mt-2 text-xs text-muted" x-show="!skPengangkatanSize">
                                                Berkas saat ini: <a href="{{ asset('storage/' . $p->appointment->file_sk) }}" target="_blank" class="text-primary hover:underline font-semibold">{{ basename($p->appointment->file_sk) }}</a>
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

                    {{-- SUB-TAB E: BERKAS LAINNYA --}}
                    <div x-show="subTab === 'lainnya'" class="space-y-6" x-transition style="display: none;">
                        <div class="rounded-lg bg-soft/40 border border-border px-4 py-3">
                            <p class="text-[11px] text-muted font-sans">Berkas ini akan tersimpan ke arsip dokumen pegawai saat Anda menekan tombol "Simpan Pegawai". Kosongkan bila tidak ingin menambah berkas.</p>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            @if ($isPppkEmployee)
                                <div class="space-y-4 rounded-lg border border-primary/20 bg-primary/5 p-4 sm:col-span-2">
                                    <div>
                                        <h4 class="text-sm font-bold text-ink font-sans">Kontrak PPPK</h4>
                                        <p class="mt-1 text-xs text-muted">Tanggal akhir kontrak diprioritaskan EWS. Jika kosong, EWS memakai TMT PPPK ditambah masa kontrak global.</p>
                                    </div>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div class="space-y-1">
                                            <label for="pppk_tmt_pengangkatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pengangkatan PPPK</label>
                                            <input id="pppk_tmt_pengangkatan" name="pppk_tmt_pengangkatan" type="date" value="{{ old('pppk_tmt_pengangkatan', $pppkAppointment?->tmt_pengangkatan?->format('Y-m-d')) }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                                            <p class="text-xs text-muted">Mengubah TMT pada riwayat SK Pengangkatan PPPK terbaru.</p>
                                            @error('pppk_tmt_pengangkatan')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                                        </div>
                                        <div class="space-y-1">
                                            <label for="tanggal_akhir_kontrak" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Akhir Kontrak</label>
                                            <input id="tanggal_akhir_kontrak" name="tanggal_akhir_kontrak" type="date" value="{{ old('tanggal_akhir_kontrak', $p->tanggal_akhir_kontrak?->format('Y-m-d')) }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                                            <p class="text-xs text-muted">Kosongkan untuk memakai TMT PPPK + masa kontrak global.</p>
                                            @error('tanggal_akhir_kontrak')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Jenis Berkas --}}
                            <div class="space-y-1">
                                <label for="berkas_lainnya_jenis" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Berkas <span class="text-danger">*</span></label>
                                <div class="relative">
                                    <select id="berkas_lainnya_jenis" name="berkas_lainnya_jenis" x-model="berkasLainnyaForm.jenis" @change="generateNomorDokumen()" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled>Pilih Jenis Berkas</option>
                                        <option value="KTP">KTP</option>
                                        <option value="KK">Kartu Keluarga (KK)</option>
                                        <option value="SK Mutasi">SK Mutasi</option>
                                        <option value="SK Pensiun">SK Pensiun</option>
                                        <option value="Lainnya">Lainnya...</option>
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            {{-- Input Manual Jenis Berkas Lainnya --}}
                            <div class="space-y-1" x-show="berkasLainnyaForm.jenis === 'Lainnya'" x-transition>
                                <label for="berkas_lainnya_jenis_manual" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Sebutkan Jenis Berkas <span class="text-danger">*</span></label>
                                <input id="berkas_lainnya_jenis_manual" name="berkas_lainnya_jenis_manual" type="text" @input="generateNomorDokumen()" placeholder="Misal: Sertifikat Pelatihan" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="berkasLainnyaForm.jenis_manual">
                            </div>

                            {{-- Nomor Dokumen --}}
                            <div class="space-y-1" :class="berkasLainnyaForm.jenis !== 'Lainnya' ? 'sm:col-span-2' : ''">
                                <label for="berkas_lainnya_nomor" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Dokumen</label>
                                <input id="berkas_lainnya_nomor" name="berkas_lainnya_nomor" type="text" placeholder="KTP-1234-07-2026" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" x-model="berkasLainnyaForm.nomor_dokumen">
                            </div>

                            {{-- Deskripsi Berkas --}}
                            <div class="space-y-1 sm:col-span-2">
                                <label for="berkas_lainnya_deskripsi" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Deskripsi</label>
                                <textarea id="berkas_lainnya_deskripsi" name="berkas_lainnya_deskripsi" style="min-height: 50px; height: 50px;" placeholder="Keterangan opsional terkait berkas" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none" x-model="berkasLainnyaForm.deskripsi"></textarea>
                            </div>

                            {{-- Tanggal Berkas --}}
                            <div class="space-y-1">
                                <label for="berkas_lainnya_tanggal" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Berkas</label>
                                <input id="berkas_lainnya_tanggal" name="berkas_lainnya_tanggal" type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer" x-model="berkasLainnyaForm.tanggal">
                                <p class="text-xs text-muted">Boleh dikosongkan jika tidak relevan.</p>
                            </div>

                            {{-- Upload Berkas Lainnya --}}
                            <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                                <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans block">File Berkas Lainnya <span class="text-danger">*</span></label>
                                <div class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_berkas_lainnya" name="file_berkas_lainnya" accept=".pdf,image/*" @change="handleBerkasLainnyaChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="berkasLainnyaName">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="berkasLainnyaName"></span>
                                                <span class="text-muted" x-show="berkasLainnyaSize" x-text="'(' + berkasLainnyaSize + ')'"></span>
                                            </div>
                                        </template>
                                        <p x-show="berkasLainnyaError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="berkasLainnyaError"></p>
                                    </div>
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
                                :disabled="isSubmitting || skPangkatError !== '' || skJabatanError !== '' || skKgbError !== '' || skPengangkatanError !== ''"
                                :class="(isSubmitting || skPangkatError !== '' || skJabatanError !== '' || skKgbError !== '' || skPengangkatanError !== '') ? 'opacity-50 cursor-not-allowed' : ''"
                                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer">
                            <svg x-show="!isSubmitting" class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            <svg x-show="isSubmitting" style="display: none;" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span x-text="isSubmitting ? 'Menyimpan...' : 'Simpan Pegawai'"></span>
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
