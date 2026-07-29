<div>
    <div class="mx-auto max-w-7xl space-y-6">
        
        <x-admin.page-header title="Tambah Pegawai Baru">
            <x-slot:breadcrumb>
                <a href="{{ route('dashboard') }}" wire:navigate class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" wire:navigate class="transition-colors hover:text-ink">Data Pegawai</a>
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
            </x-ui.alert>
        @endif

        {{-- Form Card --}}
        <x-ui.card padding="lg">
            <div x-data="createPegawai()">
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
                    4. Berkas & SK
                </x-ui.tab>
            </x-ui.tabs>

            <form id="form-create-pegawai" action="{{ route('pegawai.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate @submit="isSubmitting = true">
                @csrf

{{-- TAB 1: DATA UTAMA --}}
                <div x-show="activeTab === 'utama'" class="space-y-6" x-transition>
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {{-- Nama dengan Gelar --}}
                        <div class="space-y-1">
                            <label for="nama_dengan_gelar" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama dengan Gelar</label>
                            <input id="nama_dengan_gelar" name="nama_dengan_gelar" type="text" placeholder="Grantly Sorongan, S.Kom." class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ old('nama_dengan_gelar') }}">
                            <p class="text-xs text-muted">Nama yang ditampilkan pada kartu &amp; header. Boleh kosong jika sama dengan nama lengkap.</p>
                        </div>

                        {{-- Nama Lengkap (tanpa gelar) --}}
                        <div class="space-y-1">
                            <label for="nama_lengkap" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Lengkap (tanpa gelar) <span class="text-danger">*</span></label>
                            <input id="nama_lengkap" name="nama_lengkap" type="text" required placeholder="Grantly Antonio Edward Sorongan" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans" value="{{ old('nama_lengkap') }}">
                            <p class="text-xs text-muted">Nama lengkap resmi sesuai KTP atau SK, tanpa gelar akademik.</p>
                        </div>

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
                            
                        >
                            <p x-show="nipError" class="text-[11px] text-danger font-semibold mt-1 font-sans" x-text="nipError"></p>
                        </x-form.input>

                        {{-- Jenis Pegawai --}}

                        <x-form.select
                            name="jenis_pegawai_id"
                            label="Jenis Pegawai"
                            id="jenis_pegawai_id"
                            
                        >
                            <option value="" disabled selected>Pilih Jenis Pegawai</option>
                            @foreach($jenisPegawai as $jenis)
                                <option value="{{ $jenis->id }}">{{ $jenis->nama }}</option>
                            @endforeach
                        </x-form.select>

                        {{-- Status Pegawai --}}
                        <x-form.select
                            name="status_pegawai_id"
                            label="Status Pegawai"
                            id="status_pegawai_id"
                            value="{{ old('status_pegawai_id', $statusPegawai->firstWhere('is_default', true)?->id) }}"
                            
                        >
                            <option value="" disabled>Pilih Status Pegawai</option>
                            @foreach($statusPegawai as $status)
                                <option value="{{ $status->id }}">{{ $status->nama }}</option>
                            @endforeach
                        </x-form.select>


                        {{-- Tanggal Lahir --}}
                        <x-form.date
                            name="tanggal_lahir"
                            label="Tanggal Lahir"
                            id="tanggal_lahir"
                            max="{{ date('Y-m-d') }}"
                            
                        />

                        {{-- Pendidikan Terakhir --}}

                        <x-form.select
                            name="pendidikan_terakhir"
                            label="Pendidikan Terakhir"
                            id="pendidikan_terakhir"
                            
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
                            
                        />

                        

                        {{-- Tanggal Pensiun --}}
                        <x-form.date
                            name="tanggal_pensiun"
                            label="Tanggal Pensiun"
                            id="tanggal_pensiun"
                        />

                        {{-- Penanda ini dipakai cuti untuk membedakan Kepala Lembaga dari jabatan biasa. --}}
                        <div class="space-y-1 rounded-lg border border-border bg-soft/40 p-4 sm:col-span-2">
                            <input type="hidden" name="is_kepala_lembaga" value="0">
                            <label for="is_kepala_lembaga" class="flex items-start gap-3 text-sm font-semibold text-ink font-sans">
                                <input id="is_kepala_lembaga" name="is_kepala_lembaga" type="checkbox" value="1" @checked(old('is_kepala_lembaga')) class="mt-1 rounded border-border text-primary focus:ring-primary/20">
                                <span>
                                    Kepala Lembaga
                                    <span class="block text-[10px] font-normal text-muted">Aktifkan hanya untuk pegawai yang berwenang memberi keputusan Kepala Lembaga pada alur cuti.</span>
                                </span>
                            </label>
                        </div>
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
                                        <img :src="fotoPreview" alt="Pratinjau foto profil pegawai" class="h-full w-full object-cover object-[center_25%]">
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
                            name="email_pribadi"
                            label="Email Pribadi"
                            type="email"
                            id="email_pribadi"
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
                            
                        />
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
                                    
                                    
                                </div>

                                
                                <div  class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_pangkat" name="file_sk_pangkat" accept=".pdf,image/*" @change="handleSkPangkatChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                        </svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Pangkat (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skPangkatName ">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skPangkatName"></span>
                                                <span class="text-muted" x-show="skPangkatSize" x-text="'(' + skPangkatSize + ')'"></span>
                                            </div>
                                        </template>
                                        
                                        <p x-show="skPangkatError" class="text-xs text-danger font-semibold mt-2 font-sans" x-text="skPangkatError"></p>
                                    </div>
                                </div>

                                
                            </div>
                        </div>
                    </div>

                    {{-- SUB-TAB B: JABATAN --}}
                    <div x-show="subTab === 'jabatan'" class="space-y-6" x-transition>
                        
                        
                        

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
                                    
                                </div>
                                <div  class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_jabatan" name="file_sk_jabatan" accept=".pdf,image/*" @change="handleSkJabatanChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Jabatan (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skJabatanName ">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skJabatanName"></span>
                                                <span class="text-muted" x-show="skJabatanSize" x-text="'(' + skJabatanSize + ')'"></span>
                                            </div>
                                        </template>
                                        
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
                                    
                                </div>
                                <div  class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_kgb" name="file_sk_kgb" accept=".pdf,image/*" @change="handleSkKgbChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK KGB (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skKgbName ">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skKgbName"></span>
                                                <span class="text-muted" x-show="skKgbSize" x-text="'(' + skKgbSize + ')'"></span>
                                            </div>
                                        </template>
                                        
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
                                    <select id="pengangkatan_jenis_pengangkatan" name="pengangkatan_jenis_pengangkatan"  class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                                        <option value="" disabled >Pilih Jenis Pengangkatan</option>
                                        <option value="CPNS" >CPNS</option>
                                        <option value="PNS" >PNS</option>
                                        <option value="PPPK" >PPPK</option>
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
                                <input id="pengangkatan_tmt_pengangkatan" name="pengangkatan_tmt_pengangkatan" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer"  >
                            </div>

                            {{-- Nomor SK Pengangkatan --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_no_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pengangkatan <span class="text-danger">*</span></label>
                                <input id="pengangkatan_no_sk" name="pengangkatan_no_sk" type="text"  placeholder="SK-882-KP-2024" class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"  >
                            </div>

                            {{-- Tanggal SK --}}
                            <div class="space-y-1">
                                <label for="pengangkatan_tanggal_sk" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit <span class="text-danger">*</span></label>
                                <input id="pengangkatan_tanggal_sk" name="pengangkatan_tanggal_sk" type="date"  class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer"  >
                            </div>

                            {{-- Upload / Pilih Arsip SK Pengangkatan --}}
                            <div class="space-y-2 sm:col-span-2 border-t border-border pt-4">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK Pengangkatan</label>
                                    
                                </div>
                                <div  class="mt-1">
                                    <div class="border-2 border-dashed border-border rounded-lg p-6 bg-soft/50 text-center relative hover:border-primary transition">
                                        <input type="file" id="file_sk_pengangkatan" name="file_sk_pengangkatan" accept=".pdf,image/*" @change="handleSkPengangkatanChange" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                                        <svg class="mx-auto h-10 w-10 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                        <p class="text-xs text-ink font-semibold mt-2 font-sans">Klik atau Seret berkas SK Pengangkatan (PDF/JPG/PNG, maks 10MB)</p>
                                        <template x-if="skPengangkatanName ">
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                                <span x-text="skPengangkatanName"></span>
                                                <span class="text-muted" x-show="skPengangkatanSize" x-text="'(' + skPengangkatanSize + ')'"></span>
                                            </div>
                                        </template>
                                        
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
                                <p class="text-[10px] text-muted font-sans mt-0.5">Boleh dikosongkan jika tidak relevan.</p>
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
                                            <div class="mt-3 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
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
                        <a href="javascript:void(0)" onclick="if(document.referrer.includes(window.location.hostname)) { history.back(); } else { window.location.href = '{{ route('data-pegawai') }}'; }" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft">
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
                                :disabled="isSubmitting || skPangkatError !== '' || skJabatanError !== '' || skKgbError !== '' || skPengangkatanError !== ''"
                                :class="(isSubmitting || skPangkatError !== '' || skJabatanError !== '' || skKgbError !== '' || skPengangkatanError !== '') ? 'opacity-50 cursor-not-allowed' : ''"
                                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            <span x-text="isSubmitting ? 'Menyimpan...' : 'Simpan Pegawai'"></span>
                        </button>
                    </div>
                </div>
            </form>
            </div>
        </x-ui.card>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('createPegawai', () => ({
                isSubmitting: false,
                activeTab: 'utama',
                subTab: 'pangkat',
                nip: '{{ old('nip') }}',
                nipError: '',
                nik: '{{ old('nik') }}',
                kk: '{{ old('kk') }}',
                nikError: '',
                kkError: '',
                fotoPreview: null,
                
                skPangkatName: '',
                skPangkatSize: '',
                skPangkatError: '',
                pangkatForm: {
                    golongan_id: '',
                    no_sk: '',
                    tanggal_sk: '',
                    tmt_pangkat: '',
                },
                
                skJabatanName: '',
                skJabatanSize: '',
                skJabatanError: '',
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
                
                skKgbName: '',
                skKgbSize: '',
                skKgbError: '',
                kgbForm: {
                    gaji_pokok: '',
                    no_sk: '',
                    tanggal_sk: '',
                    tmt_kgb: '',
                },
                
                skPengangkatanName: '',
                skPengangkatanSize: '',
                skPengangkatanError: '',
                
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
                    const prefix = jenis.replace(/[^a-zA-Z0-9]/g, '').substring(0, 10).toUpperCase();
                    const randomNum = Math.floor(1000 + Math.random() * 9000);
                    const today = new Date();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const year = today.getFullYear();
                    this.berkasLainnyaForm.nomor_dokumen = `${prefix}-${randomNum}-${month}-${year}`;
                },

                validateUtama() {
                    const requiredIds = [];
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
                    if (this.nip.length > 0 && this.nip.length < 18) {
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
                    const requiredIds = [];
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
                    if (this.nik.length > 0 && this.nik.length < 16) {
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
                }
            }));
        });
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
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('form-create-pegawai');
        if (!form) return;

        const STORAGE_KEY = 'simpeg_create_employee_draft';

        // Load draft
        const draft = localStorage.getItem(STORAGE_KEY);
        if (draft) {
            try {
                const data = JSON.parse(draft);
                for (const key in data) {
                    const input = form.elements[key];
                    if (input) {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                            if (input.value === data[key]) {
                                input.checked = true;
                            }
                        } else if (input.type !== 'file' && input.name !== '_token') {
                            input.value = data[key];
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                            // For Alpine or other JS listeners
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }
                }
            } catch (e) {
                console.error('Error loading draft', e);
            }
        }

        const saveDraft = function (e) {
            if (e.target && e.target.name && e.target.type !== 'file' && e.target.type !== 'password' && e.target.name !== '_token') {
                const draftData = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                if (e.target.type === 'checkbox' || e.target.type === 'radio') {
                    if (e.target.checked) {
                        draftData[e.target.name] = e.target.value;
                    } else if (e.target.type === 'checkbox') {
                        delete draftData[e.target.name];
                    }
                } else {
                    draftData[e.target.name] = e.target.value;
                }
                localStorage.setItem(STORAGE_KEY, JSON.stringify(draftData));
            }
        };

        form.addEventListener('input', saveDraft);
        form.addEventListener('change', saveDraft);

        form.addEventListener('submit', function () {
            // Only clear draft if the form submission is not prevented
            // If validation passes on backend, page will redirect anyway, and we might want to clear.
            // But if validation fails, old() inputs will come back. However, if we clear it, on refresh it will be empty!
            // Actually it's best to clear it because if the user successfully saves, we don't want the next "Tambah Pegawai" to be pre-filled.
            localStorage.removeItem(STORAGE_KEY);
        });
    });
</script>
@endpush
</div>
