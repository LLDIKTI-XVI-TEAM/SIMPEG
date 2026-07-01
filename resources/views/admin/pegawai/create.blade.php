<x-layouts.app title="Tambah Pegawai">
    <div class="mx-auto max-w-7xl space-y-6">
        
        <x-admin.page-header title="Tambah Pegawai Baru">
            <x-slot:breadcrumb>
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Tambah</span>
            </x-slot:breadcrumb>
        </x-admin.page-header>

        {{-- Session Error --}}
        @if (session('error'))
            <x-ui.alert variant="danger" title="Gagal Menyimpan">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        {{-- Validation Errors --}}
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Terdapat kesalahan pengisian form">
                <ul role="list" class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- Form Card --}}
        <x-ui.card padding="lg">
            <div x-data="{
            activeTab: 'utama',
            nip: '',
            nipError: '',
            nik: '',
            kk: '',
            nikError: '',
            kkError: '',
            fotoPreview: null,
            skFileName: '',
            skFileSize: '',
            skFileError: '',
            validateUtama() {
                const requiredIds = ['nama_lengkap', 'nip', 'jenis_pegawai_id', 'tanggal_lahir', 'pangkat_terakhir', 'jabatan_terakhir', 'kelas_jabatan', 'pendidikan_terakhir', 'prodi_pendidikan_terakhir'];
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
        }">
            
            {{-- Tab Bar Navigasi --}}
            <x-ui.tabs label="Tahapan form pegawai" class="mb-6 flex-wrap">
                <x-ui.tab active="activeTab === 'utama'" click="
                    if (activeTab === 'pelengkap' && !validatePelengkap()) return;
                    if (activeTab === 'kontak' && !validateKontak()) return;
                    activeTab = 'utama';
                ">
                    1. Data Utama
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'pelengkap'" click="
                    if (activeTab === 'utama' && !validateUtama()) return;
                    if (activeTab === 'kontak' && !validateKontak()) return;
                    activeTab = 'pelengkap';
                ">
                    2. Data Pelengkap
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'kontak'" click="
                    if (activeTab === 'utama' && !validateUtama()) return;
                    if (activeTab === 'pelengkap' && !validatePelengkap()) return;
                    activeTab = 'kontak';
                ">
                    3. Data Kontak
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'pengangkatan'" click="
                    if (activeTab === 'utama' && !validateUtama()) return;
                    if (activeTab === 'pelengkap' && !validatePelengkap()) return;
                    if (activeTab === 'kontak' && !validateKontak()) return;
                    activeTab = 'pengangkatan';
                ">
                    4. Berkas & SK Pengangkatan
                </x-ui.tab>
            </x-ui.tabs>

            <form action="{{ route('pegawai.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate>
                @csrf

                {{-- TAB 1: DATA UTAMA --}}
                <div x-show="activeTab === 'utama'" class="space-y-6" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nama Lengkap --}}
                        <x-form.input
                            name="nama_lengkap"
                            label="Nama Lengkap"
                            type="text"
                            id="nama_lengkap"
                            placeholder="Ahmad Fauzi, S.Kom."
                            required
                        />

                        {{-- NIP --}}
                        <x-form.input
                            name="nip"
                            label="NIP"
                            type="text"
                            id="nip"
                            placeholder="198503122010011001"
                            maxlength="18"
                            x-model="nip"
                            x-on:input="validateNip"
                            required
                        >
                            <p x-show="nipError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nipError"></p>
                        </x-form.input>

                        {{-- Status Kepegawaian (Jenis) --}}

                        <x-form.select
                            name="jenis_pegawai_id"
                            label="Status Kepegawaian"
                            id="jenis_pegawai_id"
                            required
                        >
                            <option value="" disabled selected>Pilih Status Kepegawaian</option>
                            @foreach($jenisPegawai as $jenis)
                                <option value="{{ $jenis->id }}">{{ $jenis->nama }}</option>
                            @endforeach
                        </x-form.select>


                        {{-- Tanggal Lahir --}}
                        <x-form.date
                            name="tanggal_lahir"
                            label="Tanggal Lahir"
                            id="tanggal_lahir"
                            max="{{ date('Y-m-d') }}"
                            required
                        />

                        {{-- Golongan --}}
                        <div class="space-y-1" x-data="{ open: false, selected: 'I/a' }">
                            <label for="golongan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                            <div class="relative">
                                <input type="hidden" name="golongan_terakhir" :value="selected">
                                <button type="button" @click="open = !open" @click.away="open = false" class="w-full text-left appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <span x-text="selected"></span>
                                </button>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>

                                <div x-show="open" x-transition.opacity style="display: none;" class="absolute z-50 w-full mt-1 bg-surface border border-border rounded-lg shadow-lg max-h-56 overflow-y-auto">
                                    @foreach(['I/a','I/b','I/c','I/d','II/a','II/b','II/c','II/d','III/a','III/b','III/c','III/d','IV/a','IV/b','IV/c','IV/d','IV/e'] as $gol)
                                    <div @click="selected = '{{ $gol }}'; open = false" class="px-4 py-2 text-sm text-ink cursor-pointer hover:bg-soft transition-colors" :class="selected === '{{ $gol }}' ? 'bg-primary/10 text-primary font-bold' : ''">
                                        {{ $gol }}
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Pangkat --}}
                        <x-form.input
                            name="pangkat_terakhir"
                            label="Pangkat"
                            type="text"
                            id="pangkat_terakhir"
                            placeholder="Penata Tkt. I"
                            required
                        />

                        {{-- Jabatan --}}
                        <x-form.input
                            name="jabatan_terakhir"
                            label="Jabatan"
                            type="text"
                            id="jabatan_terakhir"
                            placeholder="Analis Kepegawaian"
                            required
                        />

                        {{-- Jenis Jabatan --}}
                        <div class="space-y-1">
                            <label for="jenis_jabatan_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="jenis_jabatan_id" name="jenis_jabatan_id" required class="w-full appearance-none bg-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="" disabled selected>Pilih Jenis Jabatan</option>
                                    @foreach($jenisJabatanOptions as $jenis)
                                        <option value="{{ $jenis->id }}">{{ $jenis->nama }}</option>
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
                            <label for="unit_kerja_id" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja <span class="text-danger">*</span></label>
                            <div class="relative">
                                <select id="unit_kerja_id" name="unit_kerja_id" required class="w-full appearance-none bg-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                    <option value="" disabled selected>Pilih Unit Kerja</option>
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

                        {{-- Kelas Jabatan --}}
                        <x-form.input
                            name="kelas_jabatan"
                            label="Kelas Jabatan"
                            type="text"
                            id="kelas_jabatan"
                            placeholder="8"
                            required
                        />

                        {{-- Pendidikan Terakhir --}}

                        <x-form.select
                            name="pendidikan_terakhir"
                            label="Pendidikan Terakhir"
                            id="pendidikan_terakhir"
                            required
                        >
                            <option value="Diploma III (D3)">Diploma III (D3)</option>
                            <option value="Sarjana (S1)">Sarjana (S1)</option>
                            <option value="Magister (S2)">Magister (S2)</option>
                            <option value="Doktor (S3)">Doktor (S3)</option>
                            <option value="SMA / Sederajat">SMA / Sederajat</option>
                        </x-form.select>


                        {{-- Program Studi --}}
                        <x-form.input
                            name="prodi_pendidikan_terakhir"
                            label="Program Studi"
                            type="text"
                            id="prodi_pendidikan_terakhir"
                            placeholder="Teknik Informatika"
                            required
                        />

                        

                        {{-- Tanggal Pensiun --}}
                        <x-form.date
                            name="tanggal_pensiun"
                            label="Tanggal Pensiun"
                            id="tanggal_pensiun"
                        />
                    </div>
                </div>

                {{-- TAB 2: DATA PELENGKAP --}}
                <div x-show="activeTab === 'pelengkap'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- NIK --}}
                        <x-form.input
                            name="nik"
                            label="NIK (No. KTP)"
                            type="text"
                            id="nik"
                            placeholder="3273251203850002"
                            maxlength="16"
                            x-model="nik"
                            x-on:input="validateNik"
                            required
                        >
                            <p x-show="nikError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nikError"></p>
                        </x-form.input>

                        {{-- KK --}}
                        <x-form.input
                            name="no_kk"
                            label="Nomor Kartu Keluarga (KK)"
                            type="text"
                            id="no_kk"
                            placeholder="3273250102120045"
                            maxlength="16"
                            x-model="kk"
                            x-on:input="validateKk"
                        >
                            <p x-show="kkError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="kkError"></p>
                        </x-form.input>

                        {{-- Tempat Lahir --}}
                        <x-form.input
                            name="tempat_lahir"
                            label="Tempat Lahir"
                            type="text"
                            id="tempat_lahir"
                            placeholder="Bandung"
                        />

                        {{-- Jenis Kelamin --}}

                        <x-form.select
                            name="jenis_kelamin"
                            label="Jenis Kelamin"
                            id="jenis_kelamin"
                        >
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </x-form.select>

                        {{-- Agama --}}
                        <x-form.select
                            name="agama_id"
                            label="Agama"
                            id="agama_id"
                        >
                            <option value="" disabled selected>Pilih Agama</option>
                            @foreach($agama as $a)
                                <option value="{{ $a->id }}">{{ $a->nama }}</option>
                            @endforeach
                        </x-form.select>

                        {{-- Status Pernikahan --}}
                        <x-form.select
                            name="status_kawin_id"
                            label="Status Kawin"
                            id="status_kawin_id"
                        >
                            <option value="" disabled selected>Pilih Status Kawin</option>
                            @foreach($statusKawin as $sk)
                                <option value="{{ $sk->id }}">{{ $sk->nama }}</option>
                            @endforeach
                        </x-form.select>

                        {{-- Golongan Darah --}}
                        <x-form.select
                            name="golongan_darah"
                            label="Golongan Darah"
                            id="golongan_darah"
                        >
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="AB">AB</option>
                            <option value="O">O</option>
                        </x-form.select>


                        {{-- Upload Foto Profil --}}
                        <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                            <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans block">Foto Profil Pegawai</label>
                            <div class="flex items-center gap-4">
                                <div class="h-16 w-16 rounded-full border border-border bg-soft flex items-center justify-center overflow-hidden shrink-0">
                                    <template x-if="fotoPreview">
                                        <img :src="fotoPreview" alt="Pratinjau foto profil pegawai" class="h-full w-full object-cover">
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
                        <x-form.input
                            name="no_hp"
                            label="Nomor HP"
                            type="tel"
                            id="no_hp"
                            placeholder="081234567890"
                            required
                        />

                        {{-- Telepon Rumah --}}
                        <x-form.input
                            name="no_telepon_rumah"
                            label="Telepon Rumah"
                            type="tel"
                            id="no_telepon_rumah"
                            placeholder="0227301234"
                        />

                        {{-- Email Pribadi --}}
                        <x-form.input
                            name="email"
                            label="Email Pribadi"
                            type="email"
                            id="email"
                            placeholder="pegawai@domain.com"
                            wrapper-class="sm:col-span-2"
                        />

                        {{-- Alamat Lengkap --}}
                        <x-form.textarea
                            name="alamat"
                            label="Alamat Tempat Tinggal"
                            id="alamat"
                            rows="3"
                            placeholder="Jl. Buah Batu No. 120, Lengkong, Bandung"
                            wrapper-class="sm:col-span-2"
                            required
                        />
                    </div>
                </div>

                {{-- TAB 4: BERKAS & SK PENGANGKATAN --}}
                <div x-show="activeTab === 'pengangkatan'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Jenis Pengangkatan --}}

                        <x-form.select
                            name="jenis_pengangkatan"
                            label="Jenis Pengangkatan"
                            id="jenis_pengangkatan"
                            required
                        >
                            <option value="" disabled selected>Pilih Jenis Pengangkatan</option>
                            <option value="CPNS">CPNS</option>
                            <option value="PNS">PNS</option>
                            <option value="PPPK">PPPK</option>
                        </x-form.select>


                        {{-- TMT --}}
                        <x-form.date
                            name="tmt"
                            label="TMT Pengangkatan"
                            id="tmt"
                            required
                        />

                        {{-- Nomor SK --}}
                        <x-form.input
                            name="nomor_sk"
                            label="Nomor SK Pengangkatan"
                            type="text"
                            id="nomor_sk"
                            placeholder="SK-882-KP-2024"
                            required
                        />

                        {{-- Tanggal SK --}}
                        <x-form.date
                            name="tanggal_sk"
                            label="Tanggal SK Terbit"
                            id="tanggal_sk"
                            required
                        />

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
                                x-show="activeTab === 'pengangkatan'" 
                                :disabled="nip.length < 18 || nik.length < 16 || (kk.length > 0 && kk.length < 16) || skFileError !== ''"
                                :class="(nip.length < 18 || nik.length < 16 || (kk.length > 0 && kk.length < 16) || skFileError !== '') ? 'opacity-50 cursor-not-allowed' : ''"
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
        </x-ui.card>
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
