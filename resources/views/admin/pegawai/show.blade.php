<x-layouts.app title="Detail Pegawai">

    @php
        // Mapping riwayat berkas digital pegawai
        $riwayatDokumen = [];
        if ($p['id'] == 1) {
            $riwayatDokumen = [
                [
                    'id' => 1,
                    'jenis' => 'SK Kenaikan Pangkat',
                    'nama' => 'SK Kenaikan Pangkat Penata Tkt. I',
                    'nomor' => 'SK-882-KP-2024',
                    'tanggal' => '2024-03-15',
                    'kategori_label' => 'SK Kenaikan Pangkat',
                    'file_size' => '1.2 MB',
                    'deskripsi' => 'SK Kenaikan Pangkat Penata Tingkat I Golongan Ruang III/d atas nama Ahmad Fauzi.'
                ],
                [
                    'id' => 2,
                    'jenis' => 'SK Kenaikan Jabatan',
                    'nama' => 'SK Pengangkatan Jabatan Analis Kepegawaian',
                    'nomor' => 'SK-104-JAB-2022',
                    'tanggal' => '2022-08-15',
                    'kategori_label' => 'SK Kenaikan Jabatan',
                    'file_size' => '850 KB',
                    'deskripsi' => 'SK Pengangkatan Pertama kali dalam Jabatan Fungsional Analis Kepegawaian Ahli Pertama.'
                ],
                [
                    'id' => 3,
                    'jenis' => 'SK KGB',
                    'nama' => 'SK Kenaikan Gaji Berkala 2025',
                    'nomor' => 'KGB-334-VII-2025',
                    'tanggal' => '2025-07-01',
                    'kategori_label' => 'SK KGB',
                    'file_size' => '420 KB',
                    'deskripsi' => 'Surat Keterangan Kenaikan Gaji Berkala Reguler tahun berjalan 2025.'
                ]
            ];
        }

        // Kalkulator otomatis jadwal
        $tmtPangkatTerakhir = isset($p['tmt']) ? \Carbon\Carbon::parse($p['tmt']) : null;
        $estimasiPangkatNext = $tmtPangkatTerakhir ? $tmtPangkatTerakhir->copy()->addYears(4)->format('d-m-Y') : '-';
        $estimasiKgbNext = $tmtPangkatTerakhir ? $tmtPangkatTerakhir->copy()->addYears(2)->format('d-m-Y') : '-';
        
        // Logika BUP dinamis berdasarkan jabatan
        $bup = 58;
        if (isset($p['jabatan']) && (str_contains(strtolower($p['jabatan']), 'madya') || str_contains(strtolower($p['jabatan']), 'utama') || str_contains(strtolower($p['jabatan']), 'pimpinan tinggi'))) {
            $bup = 60;
        }

        $tglLahir = isset($p['tanggal_lahir']) ? \Carbon\Carbon::parse($p['tanggal_lahir']) : null;
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
        activeTab: 'profile',
        kinerjaBaik: {{ $p['kinerja_baik'] ? 'true' : 'false' }},
        showModal: false,
        modalTitle: '',
        modalType: '',
        
        // Data list dummy untuk riwayat
        keluargaList: [
            { nama: 'Siti Aminah', hubungan: 'Istri', tgl_lahir: '1987-05-14', pekerjaan: 'Guru', status: 'Hidup' },
            { nama: 'Budi Fauzi', hubungan: 'Anak', tgl_lahir: '2015-08-20', pekerjaan: 'Pelajar', status: 'Hidup' }
        ],
        pangkatList: [
            { golongan: 'III/b', no_sk: 'SK-442-KP-2020', tgl_sk: '2020-03-10', tmt: '2020-04-01' },
            { golongan: 'III/c', no_sk: 'SK-882-KP-2024', tgl_sk: '2024-03-15', tmt: '2024-04-01' }
        ],
        jabatanList: [
            { jabatan: 'Analis Kepegawaian Ahli Pertama', unit: 'Bag. Umum', no_sk: 'SK-121-JAB-2010', tgl_sk: '2010-09-20', tmt: '2010-10-01' },
            { jabatan: 'Analis Kepegawaian', unit: 'Bag. Umum', no_sk: 'SK-883-JAB-2020', tgl_sk: '2020-09-15', tmt: '2020-10-01' }
        ],
        kgbList: [
            { gaji: 'Rp 3.500.000', no_sk: 'KGB-102-2022', tgl_sk: '2022-09-01', tmt: '2022-10-01' },
            { gaji: 'Rp 3.800.000', no_sk: 'KGB-334-2024', tgl_sk: '2024-09-01', tmt: '2024-10-01' }
        ],
        disiplinList: [],
        pendidikanList: [
            { tingkat: '{{ $p['pendidikan_terakhir'] ?? 'Sarjana (S1)' }}', institusi: 'Universitas Sam Ratulangi', prodi: '{{ $p['prodi_pendidikan'] ?? 'Manajemen' }}', lulus: '2007', no_ijazah: 'IJZ-S1-MAN-2007' }
        ],
        
        // Form states
        newKeluarga: { nama: '', hubungan: 'Istri', tgl_lahir: '', pekerjaan: '' },
        newPangkat: { golongan: 'III/c', no_sk: '', tgl_sk: '', tmt: '' },
        newJabatan: { jabatan: '', unit: 'Bag. Umum', no_sk: '', tgl_sk: '', tmt: '' },
        newKgb: { gaji: '', no_sk: '', tgl_sk: '', tmt: '' },
        newDisiplin: { jenis: 'Teguran Tertulis', alasan: '', no_sk: '', tgl_sk: '', masa: '' },
        newPendidikan: { tingkat: 'Sarjana (S1)', institusi: '', prodi: '', lulus: '', no_ijazah: '' },
        
        openModal(type, title) {
            this.modalType = type;
            this.modalTitle = title;
            this.showModal = true;
        },
        submitForm() {
            if (this.modalType === 'keluarga') {
                this.keluargaList.push({...this.newKeluarga, status: 'Hidup'});
                this.newKeluarga = { nama: '', hubungan: 'Istri', tgl_lahir: '', pekerjaan: '' };
            } else if (this.modalType === 'pangkat') {
                this.pangkatList.push({...this.newPangkat});
                this.newPangkat = { golongan: 'III/c', no_sk: '', tgl_sk: '', tmt: '' };
            } else if (this.modalType === 'jabatan') {
                this.jabatanList.push({...this.newJabatan});
                this.newJabatan = { jabatan: '', unit: 'Bag. Umum', no_sk: '', tgl_sk: '', tmt: '' };
            } else if (this.modalType === 'kgb') {
                this.kgbList.push({...this.newKgb});
                this.newKgb = { gaji: '', no_sk: '', tgl_sk: '', tmt: '' };
            } else if (this.modalType === 'disiplin') {
                this.disiplinList.push({...this.newDisiplin});
                this.newDisiplin = { jenis: 'Teguran Tertulis', alasan: '', no_sk: '', tgl_sk: '', masa: '' };
            } else if (this.modalType === 'pendidikan') {
                this.pendidikanList.push({...this.newPendidikan});
                this.newPendidikan = { tingkat: 'Sarjana (S1)', institusi: '', prodi: '', lulus: '', no_ijazah: '' };
            }
            this.showModal = false;
        }
    }" class="mx-auto max-w-5xl space-y-6">
        
        {{-- BREADCRUMBS --}}
        <div class="mb-2">
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Pegawai</span>
            </nav>
        </div>

        {{-- MAIN DETAIL CARD --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            
            {{-- Header info --}}
            <div class="border-b border-border pb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 rounded-full border border-border bg-soft flex items-center justify-center overflow-hidden shrink-0">
                        @if($p['foto'])
                            <svg class="h-8 w-8 text-primary/70" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                            </svg>
                        @else
                            <div class="flex h-full w-full items-center justify-center bg-primary/10 text-xl font-bold text-primary font-sans uppercase">
                                {{ strtoupper(substr($p['nama'], 0, 1)) }}
                            </div>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ $p['nama'] }}</h2>
                            <template x-if="kinerjaBaik">
                                <span class="inline-flex items-center gap-1 rounded bg-success/10 px-2 py-0.5 text-[10px] font-bold text-success font-sans">
                                    🌟 KINERJA BAIK
                                </span>
                            </template>
                        </div>
                        <p class="text-xs text-muted font-sans font-mono mt-0.5">NIP. {{ $p['nip'] }}</p>
                        <span class="inline-block mt-1.5 rounded-full bg-primary/10 text-primary px-2.5 py-0.5 text-xs font-semibold font-sans uppercase">{{ $p['jenis'] }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <a href="{{ route('pegawai.edit', $p['id']) }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 shadow-sm">
                        Edit Pegawai
                    </a>
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft">
                        Kembali
                    </a>
                </div>
            </div>

            {{-- TAB NAVIGATION --}}
            <div class="border-b border-border flex gap-4 md:gap-6 overflow-x-auto pb-1 select-none">
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
                            <p class="text-xs font-bold text-ink font-sans">{{ $p['atasan_nama'] ?? 'Rina Amalia, S.Sos., M.M.' }}</p>
                            <p class="text-[10px] text-muted font-mono leading-none mt-0.5">NIP. {{ $p['atasan_nip'] ?? '19810405200501 2 004' }} ({{ $p['atasan_jabatan'] ?? 'Kepala Bagian' }})</p>
                        </div>
                    </div>
                </div>

                {{-- Auto-Kalkulasi Jadwal --}}
                <div class="space-y-3">
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5 flex items-center gap-1.5">
                        🗓️ Estimasi Jadwal Kepegawaian <span class="text-[9px] bg-primary/10 text-primary rounded px-1 py-0.5 lowercase font-normal">(kalkulator otomatis)</span>
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
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">NIK (KTP)</span>
                                <p class="text-ink font-mono font-bold">{{ $p['nik'] ?? '3273251203850002' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">No. Kartu Keluarga (KK)</span>
                                <p class="text-ink font-mono font-bold">{{ $p['kk'] ?? '3273250102120045' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Tempat / Tanggal Lahir</span>
                                <p class="text-ink font-sans">{{ $p['tempat_lahir'] ?? 'Bandung' }}, {{ isset($p['tanggal_lahir']) ? \Carbon\Carbon::parse($p['tanggal_lahir'])->format('d-m-Y') : '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Jenis Kelamin</span>
                                <p class="text-ink font-sans">{{ $p['jenis_kelamin'] ?? 'Laki-laki' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Agama</span>
                                <p class="text-ink font-sans">{{ $p['agama'] ?? 'Islam' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Status Kawin</span>
                                <p class="text-ink font-sans">{{ $p['status_kawin'] ?? 'Kawin' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Golongan Darah</span>
                                <p class="text-ink font-sans font-bold">{{ $p['golongan_darah'] ?? 'O' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Kontak & Rumah</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Email Dinas</span>
                                <p class="text-ink font-sans font-mono">{{ $p['email_dinas'] ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Email Pribadi</span>
                                <p class="text-ink font-sans font-mono">{{ $p['email'] ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Nomor HP</span>
                                <p class="text-ink font-sans font-mono">{{ $p['telepon'] ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Telepon Rumah</span>
                                <p class="text-ink font-sans font-mono">{{ $p['telepon_rumah'] ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5 sm:col-span-2">
                                <span class="font-semibold text-muted font-sans">Alamat</span>
                                <p class="text-ink font-sans leading-relaxed">{{ $p['alamat'] ?? '-' }}</p>
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
                            <p class="text-ink font-sans font-bold">{{ $p['jabatan'] }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Unit Kerja</span>
                            <p class="text-ink font-sans">{{ $p['unit'] }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Pangkat</span>
                            <p class="text-ink font-sans font-bold">{{ $p['pangkat'] ?? 'Penata Tkt. I' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Golongan Saat Ini</span>
                            <p class="text-ink font-sans font-bold">{{ $p['golongan'] }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">Kelas Jabatan</span>
                            <p class="text-ink font-sans font-bold">{{ $p['kelas_jabatan'] ?? '8' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <span class="font-semibold text-muted font-sans">TMT Golongan</span>
                            <p class="text-ink font-mono">{{ isset($p['tmt']) ? \Carbon\Carbon::parse($p['tmt'])->format('d-m-Y') : '-' }}</p>
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
                    <button type="button" @click="openModal('keluarga', 'Tambah Anggota Keluarga')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        + Tambah Keluarga
                    </button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Nama Anggota</th>
                                <th class="px-4 py-3">Hubungan</th>
                                <th class="px-4 py-3">Tanggal Lahir</th>
                                <th class="px-4 py-3">Pekerjaan</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="fam in keluargaList" :key="fam.nama">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="fam.nama"></td>
                                    <td class="px-4 py-3" x-text="fam.hubungan"></td>
                                    <td class="px-4 py-3 font-mono" x-text="fam.tgl_lahir"></td>
                                    <td class="px-4 py-3" x-text="fam.pekerjaan"></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-1 rounded bg-success/10 px-2 py-0.5 text-[10px] font-bold text-success" x-text="fam.status"></span>
                                    </td>
                                </tr>
                            </template>
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
                    <button type="button" @click="openModal('pangkat', 'Tambah Riwayat Kepangkatan')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        + Tambah Pangkat
                    </button>
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
                                    <td class="px-4 py-3 font-mono" x-text="p.no_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="p.tgl_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="p.tmt"></td>
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
                    <button type="button" @click="openModal('jabatan', 'Tambah Riwayat Jabatan')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        + Tambah Jabatan
                    </button>
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
                                    <td class="px-4 py-3 font-mono" x-text="j.no_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="j.tgl_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="j.tmt"></td>
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
                    <button type="button" @click="openModal('kgb', 'Tambah Riwayat KGB')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        + Tambah KGB
                    </button>
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
                                    <td class="px-4 py-3 font-mono" x-text="k.no_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="k.tgl_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="k.tmt"></td>
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
                    <button type="button" @click="openModal('disiplin', 'Tambah Hukuman Disiplin')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        + Tambah Hukuman
                    </button>
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
                            <template x-for="d in disiplinList" :key="d.no_sk">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold text-danger" x-text="d.jenis"></td>
                                    <td class="px-4 py-3" x-text="d.alasan"></td>
                                    <td class="px-4 py-3 font-mono" x-text="d.no_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="d.tgl_sk"></td>
                                    <td class="px-4 py-3 font-mono" x-text="d.masa"></td>
                                </tr>
                            </template>
                            <tr x-show="disiplinList.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-xs text-muted font-sans font-semibold">
                                    Pegawai ini tidak memiliki riwayat hukuman disiplin. Bersih (Clean Record). ✅
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
                    <button type="button" @click="openModal('pendidikan', 'Tambah Riwayat Pendidikan')" class="inline-flex items-center gap-1.5 rounded-lg border border-primary bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 shadow-sm cursor-pointer font-sans">
                        + Tambah Pendidikan
                    </button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr class="text-left text-xs font-semibold text-muted uppercase tracking-wide font-sans">
                                <th class="px-4 py-3">Tingkat</th>
                                <th class="px-4 py-3">Nama Institusi</th>
                                <th class="px-4 py-3">Program Studi</th>
                                <th class="px-4 py-3">Tahun Lulus</th>
                                <th class="px-4 py-3">Nomor Ijazah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans">
                            <template x-for="p in pendidikanList" :key="p.no_ijazah">
                                <tr class="transition-colors hover:bg-soft/30 text-ink">
                                    <td class="px-4 py-3 font-bold" x-text="p.tingkat"></td>
                                    <td class="px-4 py-3" x-text="p.institusi"></td>
                                    <td class="px-4 py-3" x-text="p.prodi"></td>
                                    <td class="px-4 py-3 font-mono" x-text="p.lulus"></td>
                                    <td class="px-4 py-3 font-mono" x-text="p.no_ijazah"></td>
                                </tr>
                            </template>
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
                                <span class="text-ink font-bold">{{ $p['jenis_pengangkatan'] ?? 'PNS Formasi Umum' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Nomor SK Pengangkatan:</span>
                                <span class="text-ink font-mono font-bold">{{ $p['nomor_sk'] ?? 'SK-882-KP-2024' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Tanggal SK Terbit:</span>
                                <span class="text-ink font-mono">{{ isset($p['tanggal_sk']) ? \Carbon\Carbon::parse($p['tanggal_sk'])->format('d-m-Y') : '-' }}</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">TMT Pengangkatan:</span>
                                <span class="text-ink font-mono font-bold">{{ isset($p['tmt']) ? \Carbon\Carbon::parse($p['tmt'])->format('d-m-Y') : '-' }}</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Pejabat yang Menetapkan:</span>
                                <span class="text-ink font-semibold">Kepala LLDIKTI Wilayah XVI</span>
                            </div>
                            <div class="flex justify-between border-b border-border pb-1">
                                <span class="font-semibold text-muted">Status Dokumen:</span>
                                <span class="inline-flex items-center gap-1 rounded bg-success/15 px-1.5 py-0.2 text-[9px] font-bold text-success uppercase">VERIFIED</span>
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
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nama Dokumen</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Kategori</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nomor Dokumen</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Tanggal Terbit</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Ukuran</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-xs font-sans text-ink">
                            @forelse($riwayatDokumen as $doc)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 max-w-xs">
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
                                </td>
                                <td class="px-4 py-3 text-muted font-sans">{{ $doc['kategori_label'] }}</td>
                                <td class="px-4 py-3 font-mono text-muted">{{ $doc['nomor'] }}</td>
                                <td class="px-4 py-3 font-mono text-muted">{{ $doc['tanggal'] }}</td>
                                <td class="px-4 py-3 font-mono text-muted">{{ $doc['file_size'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <a href="/dashboard/dokumen/{{ $doc['id'] }}" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline font-sans">
                                            Detail
                                        </a>
                                        <span class="text-border">|</span>
                                        <a href="/dashboard/dokumen/{{ $doc['id'] }}/download" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline font-sans">
                                            Unduh
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-muted font-sans">
                                    Belum ada dokumen atau SK kepegawaian yang diunggah untuk staf ini.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        {{-- MODAL DYNAMIC FORM --}}
        <div x-show="showModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;" x-transition>
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-ink/30 transition-opacity" @click="showModal = false"></div>
                
                {{-- Centering spacer --}}
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>
                
                <div class="inline-block transform overflow-hidden rounded-lg bg-surface px-4 pt-5 pb-4 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 sm:align-middle border border-border">
                    <div class="flex items-center justify-between border-b border-border pb-3 mb-4">
                        <h3 class="text-sm font-bold text-ink font-sans" x-text="modalTitle"></h3>
                        <button @click="showModal = false" class="text-muted hover:text-ink cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm()" class="space-y-4">
                        {{-- KELUARGA FORM --}}
                        <template x-if="modalType === 'keluarga'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Lengkap</label>
                                    <input type="text" x-model="newKeluarga.nama" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Hubungan</label>
                                    <select x-model="newKeluarga.hubungan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="Suami">Suami</option>
                                        <option value="Istri">Istri</option>
                                        <option value="Anak">Anak</option>
                                        <option value="Orang Tua">Orang Tua</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Lahir</label>
                                    <input type="date" x-model="newKeluarga.tgl_lahir" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Pekerjaan</label>
                                    <input type="text" x-model="newKeluarga.pekerjaan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        {{-- PANGKAT FORM --}}
                        <template x-if="modalType === 'pangkat'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan</label>
                                    <select x-model="newPangkat.golongan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Pangkat</label>
                                    <input type="text" x-model="newPangkat.no_sk" required placeholder="SK-321-KP-2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                                    <input type="date" x-model="newPangkat.tgl_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Golongan</label>
                                    <input type="date" x-model="newPangkat.tmt" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        {{-- JABATAN FORM --}}
                        <template x-if="modalType === 'jabatan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Jabatan</label>
                                    <input type="text" x-model="newJabatan.jabatan" required placeholder="Analis Kepegawaian Muda" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja</label>
                                    <select x-model="newJabatan.unit" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="Bag. Umum">Bag. Umum</option>
                                        <option value="Bag. Keuangan">Bag. Keuangan</option>
                                        <option value="Bag. SDM">Bag. SDM</option>
                                        <option value="Bag. IT">Bag. IT</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Jabatan</label>
                                    <input type="text" x-model="newJabatan.no_sk" required placeholder="SK-910-JAB-2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                                    <input type="date" x-model="newJabatan.tgl_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Jabatan</label>
                                    <input type="date" x-model="newJabatan.tmt" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        {{-- KGB FORM --}}
                        <template x-if="modalType === 'kgb'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Gaji Pokok Baru</label>
                                    <input type="text" x-model="newKgb.gaji" required placeholder="Rp 4.100.000" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Surat KGB</label>
                                    <input type="text" x-model="newKgb.no_sk" required placeholder="KGB-012-2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Surat Terbit</label>
                                    <input type="date" x-model="newKgb.tgl_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT KGB</label>
                                    <input type="date" x-model="newKgb.tmt" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        {{-- DISIPLIN FORM --}}
                        <template x-if="modalType === 'disiplin'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Hukuman</label>
                                    <select x-model="newDisiplin.jenis" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="Teguran Lisan">Teguran Lisan</option>
                                        <option value="Teguran Tertulis">Teguran Tertulis</option>
                                        <option value="Pernyataan Tidak Puas secara Tertulis">Pernyataan Tidak Puas secara Tertulis</option>
                                        <option value="Penundaan Kenaikan Pangkat">Penundaan Kenaikan Pangkat</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alasan / Pelanggaran</label>
                                    <input type="text" x-model="newDisiplin.alasan" required placeholder="Keterlambatan absensi berulang" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK Hukuman</label>
                                    <input type="text" x-model="newDisiplin.no_sk" required placeholder="SK-HD-023-2026" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK Terbit</label>
                                    <input type="date" x-model="newDisiplin.tgl_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Masa Berlaku</label>
                                    <input type="text" x-model="newDisiplin.masa" placeholder="6 Bulan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        {{-- PENDIDIKAN FORM --}}
                        <template x-if="modalType === 'pendidikan'">
                            <div class="space-y-4">
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tingkat Pendidikan</label>
                                    <select x-model="newPendidikan.tingkat" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="Diploma III (D3)">Diploma III (D3)</option>
                                        <option value="Sarjana (S1)">Sarjana (S1)</option>
                                        <option value="Magister (S2)">Magister (S2)</option>
                                        <option value="Doktor (S3)">Doktor (S3)</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Institusi</label>
                                    <input type="text" x-model="newPendidikan.institusi" required placeholder="Universitas Sam Ratulangi" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Program Studi</label>
                                    <input type="text" x-model="newPendidikan.prodi" placeholder="Manajemen Keuangan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tahun Lulus</label>
                                    <input type="number" x-model="newPendidikan.lulus" required placeholder="2007" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Ijazah</label>
                                    <input type="text" x-model="newPendidikan.no_ijazah" placeholder="IJZ-S1-MAN-2007" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </template>

                        <div class="border-t border-border pt-4 flex justify-end gap-2.5 mt-6">
                            <button type="button" @click="showModal = false" class="inline-flex items-center justify-center rounded border border-border bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-soft transition font-sans cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" class="inline-flex items-center justify-center rounded bg-primary px-4 py-2 text-xs font-semibold text-white hover:opacity-90 transition font-sans cursor-pointer">
                                Simpan Riwayat
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</x-layouts.app>
