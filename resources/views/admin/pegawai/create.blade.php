<x-layouts.app title="Tambah Pegawai">
    <div class="mx-auto max-w-4xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Tambah Pegawai Baru</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Tambah</span>
            </nav>
        </div>

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm" x-data="{
            activeTab: 'diri',
            nik: '',
            kk: '',
            nikError: '',
            kkError: '',
            fotoPreview: null,
            skFileName: '',
            skFileSize: '',
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
            <div class="border-b border-border flex flex-wrap gap-4 md:gap-6 mb-6">
                <button type="button" @click="activeTab = 'diri'"
                        :class="activeTab === 'diri' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    1. Data Diri
                </button>
                <button type="button" @click="activeTab = 'kepegawaian'"
                        :class="activeTab === 'kepegawaian' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    2. Data Kepegawaian
                </button>
                <button type="button" @click="activeTab = 'sk'"
                        :class="activeTab === 'sk' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    3. Berkas & SK Pengangkatan
                </button>
                <button type="button" @click="activeTab = 'pendidikan'"
                        :class="activeTab === 'pendidikan' ? 'border-b-2 border-primary text-primary font-bold pb-2' : 'text-muted hover:text-ink font-semibold pb-2'"
                        class="text-sm transition-colors cursor-pointer focus:outline-none font-sans">
                    4. Riwayat Pendidikan
                </button>
            </div>

            <form action="{{ route('pegawai.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf

                {{-- TAB 1: DATA DIRI --}}
                <div x-show="activeTab === 'diri'" class="space-y-6" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nama Lengkap --}}
                        <div class="space-y-1">
                            <label for="nama" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Lengkap <span class="text-danger">*</span></label>
                            <input id="nama" name="nama" type="text" required placeholder="Ahmad Fauzi, S.Kom." class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- NIP --}}
                        <div class="space-y-1">
                            <label for="nip" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIP <span class="text-danger">*</span></label>
                            <input id="nip" name="nip" type="text" required placeholder="19850312201001 1 001" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- NIK --}}
                        <div class="space-y-1">
                            <label for="nik" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">NIK (No. KTP) <span class="text-danger">*</span></label>
                            <input id="nik" name="nik" type="text" required maxlength="16" x-model="nik" @input="validateNik" placeholder="3273251203850002" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="nikError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nikError"></p>
                        </div>

                        {{-- KK --}}
                        <div class="space-y-1">
                            <label for="kk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Kartu Keluarga (KK) <span class="text-danger">*</span></label>
                            <input id="kk" name="kk" type="text" required maxlength="16" x-model="kk" @input="validateKk" placeholder="3273250102120045" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p x-show="kkError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="kkError"></p>
                        </div>

                        {{-- Tempat Lahir --}}
                        <div class="space-y-1">
                            <label for="tempat_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tempat Lahir</label>
                            <input id="tempat_lahir" name="tempat_lahir" type="text" placeholder="Bandung" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Tanggal Lahir --}}
                        <div class="space-y-1">
                            <label for="tanggal_lahir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Lahir <span class="text-danger">*</span></label>
                            <input id="tanggal_lahir" name="tanggal_lahir" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Jenis Kelamin --}}
                        <div class="space-y-1">
                            <label for="jenis_kelamin" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Kelamin</label>
                            <select id="jenis_kelamin" name="jenis_kelamin" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                        </div>

                        {{-- Agama --}}
                        <div class="space-y-1">
                            <label for="agama" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Agama</label>
                            <select id="agama" name="agama" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Islam">Islam</option>
                                <option value="Kristen">Kristen</option>
                                <option value="Katolik">Katolik</option>
                                <option value="Hindu">Hindu</option>
                                <option value="Buddha">Buddha</option>
                                <option value="Khonghucu">Khonghucu</option>
                            </select>
                        </div>

                        {{-- Status Pernikahan --}}
                        <div class="space-y-1">
                            <label for="status_kawin" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Kawin</label>
                            <select id="status_kawin" name="status_kawin" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Belum Kawin">Belum Kawin</option>
                                <option value="Kawin">Kawin</option>
                                <option value="Cerai Hidup">Cerai Hidup</option>
                                <option value="Cerai Mati">Cerai Mati</option>
                            </select>
                        </div>

                        {{-- Golongan Darah --}}
                        <div class="space-y-1">
                            <label for="golongan_darah" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan Darah</label>
                            <select id="golongan_darah" name="golongan_darah" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="AB">AB</option>
                                <option value="O">O</option>
                            </select>
                        </div>

                        {{-- Email --}}
                        <div class="space-y-1">
                            <label for="email" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Email Pribadi</label>
                            <input id="email" name="email" type="email" placeholder="pegawai@domain.com" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Telepon Handphone --}}
                        <div class="space-y-1">
                            <label for="telepon" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor HP</label>
                            <input id="telepon" name="telepon" type="tel" placeholder="081234567890" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Telepon Rumah --}}
                        <div class="space-y-1">
                            <label for="telepon_rumah" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Telepon Rumah</label>
                            <input id="telepon_rumah" name="telepon_rumah" type="tel" placeholder="0227301234" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Alamat Lengkap --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="alamat" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alamat Tempat Tinggal</label>
                            <textarea id="alamat" name="alamat" rows="2" placeholder="Jl. Buah Batu No. 120, Lengkong, Bandung" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none"></textarea>
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
                                    <input type="file" id="foto" name="foto" accept="image/*" @change="handleFotoChange" class="text-xs text-muted focus:outline-none">
                                    <p class="text-[10px] text-muted font-sans">Format file: JPG, PNG. Maksimal ukuran 10MB.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TAB 2: KEPEGAWAIAN --}}
                <div x-show="activeTab === 'kepegawaian'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Jabatan --}}
                        <div class="space-y-1">
                            <label for="jabatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan <span class="text-danger">*</span></label>
                            <input id="jabatan" name="jabatan" type="text" placeholder="Analis Kepegawaian" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Unit Kerja --}}
                        <div class="space-y-1">
                            <label for="unit" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja <span class="text-danger">*</span></label>
                            <select id="unit" name="unit" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Bag. Umum">Bag. Umum</option>
                                <option value="Bag. Keuangan">Bag. Keuangan</option>
                                <option value="Bag. SDM">Bag. SDM</option>
                                <option value="Bag. IT">Bag. IT</option>
                            </select>
                        </div>

                        {{-- Golongan --}}
                        <div class="space-y-1">
                            <label for="golongan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                            <select id="golongan" name="golongan" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="I/a">I/a</option>
                                <option value="I/b">I/b</option>
                                <option value="I/c">I/c</option>
                                <option value="I/d">I/d</option>
                                <option value="II/a">II/a</option>
                                <option value="II/b">II/b</option>
                                <option value="II/c">II/c</option>
                                <option value="II/d">II/d</option>
                                <option value="III/a">III/a</option>
                                <option value="III/b">III/b</option>
                                <option value="III/c">III/c</option>
                                <option value="III/d">III/d</option>
                                <option value="IV/a">IV/a</option>
                                <option value="IV/b">IV/b</option>
                                <option value="IV/c">IV/c</option>
                                <option value="IV/d">IV/d</option>
                                <option value="IV/e">IV/e</option>
                            </select>
                        </div>

                        {{-- Jenis Kepegawaian --}}
                        <div class="space-y-1">
                            <label for="jenis" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Status Kepegawaian <span class="text-danger">*</span></label>
                            <select id="jenis" name="jenis" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="PNS">PNS</option>
                                <option value="PPPK">PPPK</option>
                            </select>
                        </div>

                        {{-- TMT --}}
                        <div class="space-y-1">
                            <label for="tmt" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Kepegawaian <span class="text-danger">*</span></label>
                            <input id="tmt" name="tmt" type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Jenis Pengangkatan --}}
                        <div class="space-y-1">
                            <label for="jenis_pengangkatan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Pengangkatan</label>
                            <select id="jenis_pengangkatan" name="jenis_pengangkatan" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="PNS Formasi Umum">PNS Formasi Umum</option>
                                <option value="PPPK Tahap I">PPPK Tahap I</option>
                                <option value="PPPK Tahap II">PPPK Tahap II</option>
                                <option value="Pengangkatan Khusus">Pengangkatan Khusus</option>
                            </select>
                        </div>

                        {{-- Tanggal Pensiun --}}
                        <div class="space-y-1">
                            <label for="tanggal_pensiun" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Pensiun</label>
                            <input id="tanggal_pensiun" name="tanggal_pensiun" type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>
                    </div>
                </div>

                {{-- TAB 3: SK PENGANGKATAN --}}
                <div x-show="activeTab === 'sk'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nomor SK --}}
                        <div class="space-y-1">
                            <label for="nomor_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pengangkatan</label>
                            <input id="nomor_sk" name="nomor_sk" type="text" placeholder="SK-882-KP-2024" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        {{-- Tanggal SK --}}
                        <div class="space-y-1">
                            <label for="tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                            <input id="tanggal_sk" name="tanggal_sk" type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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

                {{-- TAB 4: PENDIDIKAN --}}
                <div x-show="activeTab === 'pendidikan'" class="space-y-6" style="display: none;" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Pendidikan Terakhir --}}
                        <div class="space-y-1">
                            <label for="pendidikan_terakhir" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pendidikan Terakhir</label>
                            <select id="pendidikan_terakhir" name="pendidikan_terakhir" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Diploma III (D3)">Diploma III (D3)</option>
                                <option value="Sarjana (S1)">Sarjana (S1)</option>
                                <option value="Magister (S2)">Magister (S2)</option>
                                <option value="Doktor (S3)">Doktor (S3)</option>
                                <option value="SMA / Sederajat">SMA / Sederajat</option>
                            </select>
                        </div>

                        {{-- Program Studi --}}
                        <div class="space-y-1">
                            <label for="prodi_pendidikan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi / Bidang Ilmu</label>
                            <input id="prodi_pendidikan" name="prodi_pendidikan" type="text" placeholder="Teknik Informatika" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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
                                x-show="activeTab !== 'diri'" 
                                @click="activeTab = activeTab === 'pendidikan' ? 'sk' : (activeTab === 'sk' ? 'kepegawaian' : 'diri')" 
                                class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans">
                            Sebelumnya
                        </button>
                        
                        {{-- Tombol Selanjutnya --}}
                        <button type="button" 
                                x-show="activeTab !== 'pendidikan'" 
                                @click="activeTab = activeTab === 'diri' ? 'kepegawaian' : (activeTab === 'kepegawaian' ? 'sk' : 'pendidikan')" 
                                class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm font-sans">
                            Selanjutnya
                        </button>

                        {{-- Tombol Simpan --}}
                        <button type="submit" 
                                x-show="activeTab === 'pendidikan'" 
                                class="inline-flex items-center justify-center rounded-lg bg-success px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans">
                            Simpan Pegawai
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
