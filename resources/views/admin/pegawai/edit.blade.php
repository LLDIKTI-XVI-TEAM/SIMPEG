<x-layouts.app title="Edit Pegawai">
    @php
        $formattedTglLahir = isset($p['tanggal_lahir']) ? \Carbon\Carbon::parse($p['tanggal_lahir'])->format('Y-m-d') : '';
        $formattedTmt = isset($p['tmt']) ? \Carbon\Carbon::parse($p['tmt'])->format('Y-m-d') : '';
        $formattedTglSk = isset($p['tanggal_sk']) ? \Carbon\Carbon::parse($p['tanggal_sk'])->format('Y-m-d') : '';
        $formattedTglPensiun = isset($p['tanggal_pensiun']) ? \Carbon\Carbon::parse($p['tanggal_pensiun'])->format('Y-m-d') : '';
        if (empty($formattedTglPensiun) && isset($p['tanggal_lahir'])) {
            // Default BUP kalkulasi
            $bup = 58;
            if (isset($p['jabatan']) && (str_contains(strtolower($p['jabatan']), 'madya') || str_contains(strtolower($p['jabatan']), 'utama') || str_contains(strtolower($p['jabatan']), 'pimpinan tinggi'))) {
                $bup = 60;
            }
            $formattedTglPensiun = \Carbon\Carbon::parse($p['tanggal_lahir'])->addYears($bup)->format('Y-m-d');
        }
    @endphp

    <div class="mx-auto max-w-4xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Edit Data Pegawai</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Edit - {{ $p['nama'] }}</span>
            </nav>
        </div>

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm" x-data="{
            activeTab: 'utama',
            nik: '{{ $p['nik'] ?? '' }}',
            kk: '{{ $p['kk'] ?? '' }}',
            nikError: '',
            kkError: '',
            fotoPreview: '{{ $p['foto'] ? asset('storage/foto/' . $p['foto']) : '' }}',
            skFileName: '{{ $p['nomor_sk'] ? 'SK_Pengangkatan_' . $p['id'] . '.pdf' : '' }}',
            skFileSize: '1.5 MB',
            skFileError: '',
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
            handleSkChange(e) {
                const file = e.target.files[0];
                if (file) {
                    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
                    if (!allowedTypes.includes(file.type)) {
                        this.skFileError = 'Format berkas harus PDF, JPG, JPEG, atau PNG!';
                        this.skFileName = '';
                        this.skFileSize = '';
                        e.target.value = '';
                        return;
                    }
                    this.skFileName = file.name;
                    const sizeInMb = (file.size / (1024 * 1024)).toFixed(2);
                    this.skFileSize = sizeInMb + ' MB';
                    if (file.size > 10 * 1024 * 1024) {
                        this.skFileError = 'Ukuran berkas SK melebihi batas 10MB! (Terdeteksi: ' + sizeInMb + 'MB)';
                    } else {
                        this.skFileError = '';
                    }
                } else {
                    this.skFileName = '';
                    this.skFileSize = '';
                    this.skFileError = '';
                }
            }
        }" x-init="validateNik(); validateKk();">
            
            {{-- Tab Bar Navigasi --}}
            <div class="border-b border-border flex flex-wrap gap-4 md:gap-6 mb-6">
                <button type="button" @click="activeTab = 'utama'"
                        :class="activeTab === 'utama' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    1. Data Utama
                </button>
                <button type="button" @click="
                    if (nik.length < 16) { nikError = 'NIK harus tepat 16 digit sebelum berpindah tab'; return; }
                    if (kk.length > 0 && kk.length < 16) { kkError = 'Nomor KK harus tepat 16 digit sebelum berpindah tab'; return; }
                    activeTab = 'pelengkap';
                "
                        :class="activeTab === 'pelengkap' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    2. Data Pelengkap
                </button>
                <button type="button" @click="
                    if (nik.length < 16) { nikError = 'NIK harus tepat 16 digit sebelum berpindah tab'; return; }
                    if (kk.length > 0 && kk.length < 16) { kkError = 'Nomor KK harus tepat 16 digit sebelum berpindah tab'; return; }
                    activeTab = 'kontak';
                "
                        :class="activeTab === 'kontak' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    3. Data Kontak
                </button>
                <button type="button" @click="
                    if (nik.length < 16) { nikError = 'NIK harus tepat 16 digit sebelum berpindah tab'; return; }
                    if (kk.length > 0 && kk.length < 16) { kkError = 'Nomor KK harus tepat 16 digit sebelum berpindah tab'; return; }
                    activeTab = 'pengangkatan';
                "
                        :class="activeTab === 'pengangkatan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    4. Berkas & SK Pengangkatan
                </button>
            </div>

            <form action="{{ route('pegawai.update', $p['id']) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf

                {{-- TAB 1: DATA UTAMA --}}
                <div x-show="activeTab === 'utama'" class="space-y-6" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nama Lengkap --}}
                        <div class="space-y-1">
                            <label for="nama" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Lengkap <span class="text-danger">*</span></label>
                            <input id="nama" name="nama" type="text" required value="{{ $p['nama'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- NIP --}}
                        <div class="space-y-1">
                            <label for="nip" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIP <span class="text-danger">*</span></label>
                            <input id="nip" name="nip" type="text" required value="{{ $p['nip'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Status Kepegawaian (Jenis) --}}
                        <div class="space-y-1">
                            <label for="jenis" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Kepegawaian <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="jenis" name="jenis" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="PNS" {{ ($p['jenis'] ?? '') === 'PNS' ? 'selected' : '' }}>PNS</option>
                                    <option value="PPPK" {{ ($p['jenis'] ?? '') === 'PPPK' ? 'selected' : '' }}>PPPK</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Tanggal Lahir --}}
                        <div class="space-y-1">
                            <label for="tanggal_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Lahir <span class="text-danger">*</span></label>
                            <input id="tanggal_lahir" name="tanggal_lahir" type="date" required value="{{ $formattedTglLahir }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                        </div>

                        {{-- Golongan --}}
                        <div class="space-y-1">
                            <label for="golongan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="golongan" name="golongan" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    @foreach(['I/a','I/b','I/c','I/d','II/a','II/b','II/c','II/d','III/a','III/b','III/c','III/d','IV/a','IV/b','IV/c','IV/d','IV/e'] as $gol)
                                        <option value="{{ $gol }}" {{ ($p['golongan'] ?? '') === $gol ? 'selected' : '' }}>{{ $gol }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Pangkat --}}
                        <div class="space-y-1">
                            <label for="pangkat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pangkat <span class="text-danger">*</span></label>
                            <input id="pangkat" name="pangkat" type="text" required value="{{ $p['pangkat'] ?? 'Penata Tkt. I' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Jabatan --}}
                        <div class="space-y-1">
                            <label for="jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan <span class="text-danger">*</span></label>
                            <input id="jabatan" name="jabatan" type="text" required value="{{ $p['jabatan'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Kelas Jabatan --}}
                        <div class="space-y-1">
                            <label for="kelas_jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kelas Jabatan <span class="text-danger">*</span></label>
                            <input id="kelas_jabatan" name="kelas_jabatan" type="text" required value="{{ $p['kelas_jabatan'] ?? '8' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Pendidikan Terakhir --}}
                        <div class="space-y-1">
                            <label for="pendidikan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pendidikan Terakhir <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="pendidikan_terakhir" name="pendidikan_terakhir" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    @foreach(['Diploma III (D3)', 'Sarjana (S1)', 'Magister (S2)', 'Doktor (S3)', 'SMA / Sederajat'] as $pend)
                                        <option value="{{ $pend }}" {{ ($p['pendidikan_terakhir'] ?? '') === $pend ? 'selected' : '' }}>{{ $pend }}</option>
                                    @endforeach
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
                            <label for="prodi_pendidikan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi <span class="text-danger">*</span></label>
                            <input id="prodi_pendidikan" name="prodi_pendidikan" type="text" required value="{{ $p['prodi_pendidikan'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Email Dinas / Keycloak --}}
                        <div class="space-y-1">
                            <label for="email_dinas" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Email Dinas (Keycloak SSO) <span class="text-danger">*</span></label>
                            <input id="email_dinas" name="email_dinas" type="email" required value="{{ $p['email_dinas'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Tanggal Pensiun --}}
                        <div class="space-y-1">
                            <label for="tanggal_pensiun" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Pensiun <span class="text-muted">(opsional)</span></label>
                            <input id="tanggal_pensiun" name="tanggal_pensiun" type="date" value="{{ $formattedTglPensiun }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                        </div>
                    </div>
                </div>

                {{-- TAB 2: DATA PELENGKAP --}}
                <div x-show="activeTab === 'pelengkap'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- NIK --}}
                        <div class="space-y-1">
                            <label for="nik" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIK (No. KTP) <span class="text-danger">*</span></label>
                            <input id="nik" name="nik" type="text" required maxlength="16" x-model="nik" @input="validateNik" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="nikError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nikError"></p>
                        </div>

                        {{-- KK --}}
                        <div class="space-y-1">
                            <label for="kk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Kartu Keluarga (KK)</label>
                            <input id="kk" name="kk" type="text" maxlength="16" x-model="kk" @input="validateKk" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="kkError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="kkError"></p>
                        </div>

                        {{-- Tempat Lahir --}}
                        <div class="space-y-1">
                            <label for="tempat_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tempat Lahir</label>
                            <input id="tempat_lahir" name="tempat_lahir" type="text" value="{{ $p['tempat_lahir'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Jenis Kelamin --}}
                        <div class="space-y-1">
                            <label for="jenis_kelamin" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Kelamin</label>
                            <div class="relative">
                                <select id="jenis_kelamin" name="jenis_kelamin" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="Laki-laki" {{ ($p['jenis_kelamin'] ?? '') === 'Laki-laki' ? 'selected' : '' }}>Laki-laki</option>
                                    <option value="Perempuan" {{ ($p['jenis_kelamin'] ?? '') === 'Perempuan' ? 'selected' : '' }}>Perempuan</option>
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
                            <label for="agama" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Agama</label>
                            <div class="relative">
                                <select id="agama" name="agama" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    @foreach(['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $agm)
                                        <option value="{{ $agm }}" {{ ($p['agama'] ?? '') === $agm ? 'selected' : '' }}>{{ $agm }}</option>
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
                            <label for="status_kawin" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Kawin</label>
                            <div class="relative">
                                <select id="status_kawin" name="status_kawin" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    @foreach(['Belum Kawin', 'Kawin', 'Cerai Hidup', 'Cerai Mati'] as $skw)
                                        <option value="{{ $skw }}" {{ ($p['status_kawin'] ?? '') === $skw ? 'selected' : '' }}>{{ $skw }}</option>
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
                                    @foreach(['A', 'B', 'AB', 'O'] as $goldar)
                                        <option value="{{ $goldar }}" {{ ($p['golongan_darah'] ?? '') === $goldar ? 'selected' : '' }}>{{ $goldar }}</option>
                                    @endforeach
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
                                    <template x-if="fotoPreview">
                                        <img :src="fotoPreview" class="h-full w-full object-cover">
                                    </template>
                                    <template x-if="!fotoPreview">
                                        <svg class="h-8 w-8 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                        </svg>
                                    </template>
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
                            <label for="telepon" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor HP <span class="text-danger">*</span></label>
                            <input id="telepon" name="telepon" type="tel" required value="{{ $p['telepon'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Telepon Rumah --}}
                        <div class="space-y-1">
                            <label for="telepon_rumah" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Telepon Rumah</label>
                            <input id="telepon_rumah" name="telepon_rumah" type="tel" value="{{ $p['telepon_rumah'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Email Pribadi --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="email" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Email Pribadi</label>
                            <input id="email" name="email" type="email" value="{{ $p['email'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Alamat Lengkap --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="alamat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alamat Tempat Tinggal <span class="text-danger">*</span></label>
                            <textarea id="alamat" name="alamat" rows="3" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none">{{ $p['alamat'] ?? '' }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- TAB 4: BERKAS & SK PENGANGKATAN --}}
                <div x-show="activeTab === 'pengangkatan'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Jenis Pengangkatan --}}
                        <div class="space-y-1">
                            <label for="jenis_pengangkatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Pengangkatan <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="jenis_pengangkatan" name="jenis_pengangkatan" class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    @foreach(['PNS Formasi Umum', 'PPPK Tahap I', 'PPPK Tahap II', 'Pengangkatan Khusus'] as $jnsPeng)
                                        <option value="{{ $jnsPeng }}" {{ ($p['jenis_pengangkatan'] ?? '') === $jnsPeng ? 'selected' : '' }}>{{ $jnsPeng }}</option>
                                    @endforeach
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- TMT --}}
                        <div class="space-y-1">
                            <label for="tmt" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pengangkatan <span class="text-danger">*</span></label>
                            <input id="tmt" name="tmt" type="date" required value="{{ $formattedTmt }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                        </div>

                        {{-- Nomor SK --}}
                        <div class="space-y-1">
                            <label for="nomor_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pengangkatan <span class="text-danger">*</span></label>
                            <input id="nomor_sk" name="nomor_sk" type="text" required value="{{ $p['nomor_sk'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Tanggal SK --}}
                        <div class="space-y-1">
                            <label for="tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit <span class="text-danger">*</span></label>
                            <input id="tanggal_sk" name="tanggal_sk" type="date" required value="{{ $formattedTglSk }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                        </div>

                        {{-- Upload File SK --}}
                        <div class="space-y-1 sm:col-span-2 border-t border-border pt-4">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans block">File SK Pengangkatan (PDF/JPG/PNG)</label>
                            <div class="mt-2 border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                <input type="file" id="file_sk" name="file_sk" accept=".pdf,image/*" @change="handleSkChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                <svg class="mx-auto h-12 w-12 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                </svg>
                                <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas di sini untuk mengunggah berkas SK</p>
                                <p class="text-[10px] text-muted mt-1 font-sans">Mendukung format PDF, JPG, atau PNG dengan ukuran maksimal 10MB.</p>
                                <template x-if="skFileName">
                                    <div class="mt-4 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                        <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                        <span x-text="skFileName"></span>
                                        <span class="text-muted" x-text="'(' + skFileSize + ')'"></span>
                                    </div>
                                </template>
                                <p x-show="skFileError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="skFileError"></p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-6 flex justify-between items-center gap-3">
                    <div>
                        <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                            Batal
                        </a>
                    </div>
                    <div class="flex items-center gap-3">
                        {{-- Tombol Sebelumnya --}}
                        <button type="button" 
                                x-show="activeTab !== 'utama'" 
                                @click="activeTab = activeTab === 'pengangkatan' ? 'kontak' : (activeTab === 'kontak' ? 'pelengkap' : 'utama')" 
                                class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans cursor-pointer">
                            Sebelumnya
                        </button>
                        
                        {{-- Tombol Selanjutnya --}}
                        <button type="button" 
                                x-show="activeTab !== 'pengangkatan'" 
                                @click="
                                    if (activeTab === 'utama') {
                                        activeTab = 'pelengkap';
                                    } else if (activeTab === 'pelengkap') {
                                        if (nik.length < 16) {
                                            nikError = 'NIK harus tepat 16 digit sebelum melanjutkan';
                                            return;
                                        }
                                        if (kk.length > 0 && kk.length < 16) {
                                            kkError = 'Nomor KK harus tepat 16 digit sebelum melanjutkan';
                                            return;
                                        }
                                        activeTab = 'kontak';
                                    } else if (activeTab === 'kontak') {
                                        activeTab = 'pengangkatan';
                                    }
                                " 
                                class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm font-sans cursor-pointer">
                            Selanjutnya
                        </button>

                        {{-- Tombol Simpan --}}
                        <button type="submit" 
                                x-show="activeTab === 'pengangkatan'" 
                                :disabled="nik.length < 16 || (kk.length > 0 && kk.length < 16) || skFileError !== ''"
                                :class="(nik.length < 16 || (kk.length > 0 && kk.length < 16) || skFileError !== '') ? 'opacity-50 cursor-not-allowed' : ''"
                                class="inline-flex items-center justify-center rounded-lg bg-success px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer">
                            Simpan Perubahan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
