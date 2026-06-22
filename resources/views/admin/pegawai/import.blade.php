<x-layouts.app title="Import Data Pegawai">
    <div class="mx-auto max-w-5xl space-y-6" x-data="{
        step: 1,
        fileName: '',
        fileSize: '',
        fileError: '',
        fileValid: false,
        dragover: false,
        
        // Headers template utama
        mainHeaders: ['No', 'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP', 'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula', 'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir'],
        
        // Mapping fields SIMPEG
        simpegFields: [
            { key: 'no', label: 'No' },
            { key: 'nama', label: 'Nama Lengkap' },
            { key: 'email_dinas', label: 'Email Dinas (Keycloak)' },
            { key: 'golongan', label: 'Golongan' },
            { key: 'jabatan', label: 'Jabatan' },
            { key: 'kelas_jabatan', label: 'Kelas Jabatan' },
            { key: 'nip', label: 'NIP' },
            { key: 'telepon', label: 'Nomor HP' },
            { key: 'pangkat', label: 'Pangkat' },
            { key: 'pendidikan_terakhir', label: 'Pendidikan Terakhir' },
            { key: 'tanggal_pensiun', label: 'Tanggal Pensiun' },
            { key: 'person', label: 'Person' },
            { key: 'person_formula', label: 'Person Formula' },
            { key: 'prodi_pendidikan', label: 'Prodi Pendidikan' },
            { key: 'jenis', label: 'Status Kepegawaian (PNS/PPPK)' },
            { key: 'tanggal_lahir', label: 'Tanggal Lahir' }
        ],
        
        // Current mapping state (Excel header -> SIMPEG field key)
        mappings: {},
        warningColumns: [],
        
        // Preview data
        previewRows: [
            ['1', 'Ahmad Fauzi', 'ahmadfauzi@lldikti16.go.id', 'III/c', 'Analis Kepegawaian', '8', '198503122010011001', '081234567890', 'Penata Tkt. I', 'Sarjana (S1)', '2043-03-12', 'Ahmad Fauzi', 'PF-1', 'Manajemen', 'PNS', '1985-03-12'],
            ['2', 'Rina Herlina', 'rina.herlina@lldikti16.go.id', 'III/b', 'Analis Kepegawaian', '8', '199204152018032002', '082384910002', 'Penata Tkt. I', 'Sarjana (S1)', '2050-04-15', 'Rina Herlina', 'PF-2', 'Administrasi', 'PNS', '1992-04-15'],
            ['3', 'Dedi Kusnadi', 'dedi.kusnadi@lldikti16.go.id', 'III/a', 'Pranata Komputer', '7', '198807202012121004', '085298765431', 'Penata Muda', 'Sarjana (S1)', '2046-07-20', 'Dedi Kusnadi', 'PF-3', 'Teknik Informatika', 'PNS', '1988-07-20'],
            ['4', 'Melani Putri', '', 'III/a', 'Analis SDM', '7', '199505122021012005', '081273940023', 'Penata Muda', 'Sarjana (S1)', '2053-05-12', 'Melani Putri', 'PF-4', 'Psikologi', 'PPPK', '1995-05-12'],
            ['5', 'Gunawan Wibisono', 'gunawan@lldikti16.go.id', 'IV/a', 'Kepala Bagian', '9', '198003102008011003', '081394020304', 'Pembina', 'Magister (S2)', '2038-03-10', 'Gunawan W', 'PF-5', 'Manajemen Publik', 'HONORER', '1980-03-10'],
            ['6', 'Taufik Hidayat', 'taufik@lldikti16.go.id', 'III/b', 'Pengolah Data', '8', '199312252020011006', '081294820392', 'Penata Muda Tkt. I', 'Sarjana (S1)', '2051-12-25', 'Taufik H', 'PF-6', 'Sistem Informasi', 'PNS', '1993-45-12'],
            ['7', 'Hesti Lestari', 'hesti@lldikti16.go.id', 'V/a', 'Arsiparis', '6', '199109082019032007', '085294020392', 'Pengatur', 'Diploma III (D3)', '2049-09-08', 'Hesti L', 'PF-7', 'Kearsipan', 'PPPK', '1991-09-08'],
            ['8', 'Rudi Tabuti', 'rudi@lldikti16.go.id', 'III/c', 'Analis Kepegawaian', '8', '198705052010011008', '081293029302', 'Penata Tkt. I', 'Sarjana (S1)', '2045-05-05', 'Rudi Tabuti', 'PF-8', 'Hukum', 'PNS', '1987-05-05'],
            ['9', 'Maya Indah', 'maya@lldikti16.go.id', 'III/a', 'Pranata Humas', '7', '199408182022012009', '085283928392', 'Penata Muda', 'Sarjana (S1)', '2052-08-18', 'Maya Indah', 'PF-9', 'Komunikasi', 'PPPK', '1994-08-18'],
            ['10', 'Agung Laksono', 'agung@lldikti16.go.id', 'III/b', 'Analis Kepegawaian', '8', '198602142010121010', '081283928302', 'Penata Muda Tkt. I', 'Sarjana (S1)', '2044-02-14', 'Agung L', 'PF-10', 'Manajemen', 'PNS', '1986-02-14']
        ],
        
        // Validation outcomes
        validations: [
            { row: 1, name: 'Ahmad Fauzi', status: 'skip', error: 'Sudah ada - akan di-skip (NIP duplikat)' },
            { row: 2, name: 'Rina Herlina', status: 'valid', error: '' },
            { row: 3, name: 'Dedi Kusnadi', status: 'valid', error: '' },
            { row: 4, name: 'Melani Putri', status: 'error', error: 'Email Pegawai wajib terisi', col: 'Email Pegawai' },
            { row: 5, name: 'Gunawan Wibisono', status: 'error', error: 'Status Kepegawaian tidak valid (HONORER). Harus PNS/CPNS/PPPK', col: 'Status Kepegawaian' },
            { row: 6, name: 'Taufik Hidayat', status: 'error', error: 'Format Tanggal Lahir tidak valid (1993-45-12)', col: 'Tanggal Lahir' },
            { row: 7, name: 'Hesti Lestari', status: 'error', error: 'Golongan V/a tidak ditemukan di master reference', col: 'Golongan' },
            { row: 8, name: 'Rudi Tabuti', status: 'valid', error: '' },
            { row: 9, name: 'Maya Indah', status: 'valid', error: '' },
            { row: 10, name: 'Agung Laksono', status: 'valid', error: '' }
        ],
        
        // Summary numbers
        totalRows: 10,
        validRows: 5,
        skipRows: 1,
        errorRows: 4,
        
        // Progress simulation
        progress: 0,
        progressText: 'Memulai proses impor...',
        
        // Download Template CSV/Excel
        downloadTemplate(type) {
            let headers = [];
            let rows = [];
            if (type === 'utama') {
                headers = this.mainHeaders;
                rows = [
                    ['1', 'Ahmad Fauzi', 'ahmadfauzi@lldikti16.go.id', 'III/c', 'Analis Kepegawaian', '8', '198503122010011001', '081234567890', 'Penata Tkt. I', 'Sarjana (S1)', '2043-03-12', 'Ahmad Fauzi', 'PF-1', 'Manajemen', 'PNS', '1985-03-12'],
                    ['2', 'Siti Rahayu', 'sitirahayu@lldikti16.go.id', 'II/d', 'Analis Ahli Madya', '8', '199011202015012003', '085298765432', 'Pemula Tkt. I', 'Magister (S2)', '2050-11-20', 'Siti Rahayu', 'PF-2', 'Akuntansi', 'PNS', '1990-11-20']
                ];
            } else if (type === 'pelengkap') {
                headers = ['NIP', 'NIK', 'No KK', 'Tempat Lahir', 'Jenis Kelamin', 'Agama', 'Status Kawin', 'Golongan Darah'];
                rows = [
                    ['198503122010011001', '3273251203850002', '3273250102120045', 'Bandung', 'Laki-laki', 'Islam', 'Kawin', 'O'],
                    ['199011202015012003', '3171052011900003', '3171050204160012', 'Jakarta', 'Perempuan', 'Islam', 'Kawin', 'A']
                ];
            } else if (type === 'kepangkatan') {
                headers = ['NIP', 'Golongan', 'TMT Pangkat', 'No SK', 'Tanggal SK'];
                rows = [
                    ['198503122010011001', 'III/b', '2020-04-01', 'SK-442-KP-2020', '2020-03-10'],
                    ['198503122010011001', 'III/c', '2024-04-01', 'SK-882-KP-2024', '2024-03-15']
                ];
            } else if (type === 'jabatan') {
                headers = ['NIP', 'Nama Jabatan', 'Jenis Jabatan', 'Unit Kerja', 'TMT Jabatan', 'No SK', 'Tanggal SK'];
                rows = [
                    ['198503122010011001', 'Analis Kepegawaian Ahli Pertama', 'Fungsional Tertentu', 'Bag. Umum', '2010-10-01', 'SK-121-JAB-2010', '2010-09-20'],
                    ['198503122010011001', 'Analis Kepegawaian', 'Fungsional Umum', 'Bag. Umum', '2020-10-01', 'SK-883-JAB-2020', '2020-09-15']
                ];
            } else if (type === 'kgb') {
                headers = ['NIP', 'TMT KGB', 'Gaji Pokok', 'No SK', 'Tanggal SK'];
                rows = [
                    ['198503122010011001', '2022-10-01', '3500000', 'KGB-102-2022', '2022-09-01'],
                    ['198503122010011001', '2024-10-01', '3800000', 'KGB-334-2024', '2024-09-01']
                ];
            }
            
            let csvContent = '\uFEFF' + [headers.join(','), ...rows.map(e => e.map(val => String.fromCharCode(34) + val + String.fromCharCode(34)).join(','))].join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', `template_${type}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },
        
        // Download Laporan Kesalahan
        downloadErrorReport() {
            let headers = ['Baris', 'Nama Pegawai', 'Kolom Bermasalah', 'Jenis Kesalahan'];
            let rows = this.validations
                .filter(v => v.status === 'error')
                .map(v => [v.row, v.name, v.col || '-', v.error]);
            
            let csvContent = '\uFEFF' + [headers.join(','), ...rows.map(e => e.map(val => String.fromCharCode(34) + val + String.fromCharCode(34)).join(','))].join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', `laporan_kegagalan_import.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },
        
        // Handle File Upload Select
        handleFileSelect(e) {
            const file = e.target.files[0];
            if (file) {
                this.fileName = file.name;
                const sizeInMb = (file.size / (1024 * 1024)).toFixed(2);
                this.fileSize = sizeInMb + ' MB';
                
                if (file.size > 10 * 1024 * 1024) {
                    this.fileError = 'Ukuran berkas melebihi batas 10MB! (Terdeteksi: ' + sizeInMb + 'MB)';
                    this.fileValid = false;
                    e.target.value = '';
                } else {
                    this.fileError = '';
                    this.fileValid = true;
                    // Auto-mapping headers (Auto Match)
                    this.mainHeaders.forEach(header => {
                        // Find matching SIMPEG field
                        let matchedField = this.simpegFields.find(f => 
                            f.label.toLowerCase().includes(header.toLowerCase()) || 
                            header.toLowerCase().includes(f.label.toLowerCase()) || 
                            f.key.toLowerCase().includes(header.toLowerCase().replace(' ', '_'))
                        );
                        this.mappings[header] = matchedField ? matchedField.key : '';
                    });
                }
            }
        },
        
        // Auto check for mismatch warnings
        checkMismatch() {
            this.warningColumns = [];
            this.mainHeaders.forEach(header => {
                if (!this.mappings[header]) {
                    this.warningColumns.push(header);
                }
            });
        },
        
        // Start simulation queue background
        startImportSimulation() {
            this.step = 4;
            this.progress = 0;
            this.progressText = 'Memulai antrean latar belakang (queue job)...';
            
            let interval = setInterval(() => {
                this.progress += 20;
                if (this.progress === 20) {
                    this.progressText = 'Memvalidasi data baris kepegawaian...';
                } else if (this.progress === 40) {
                    this.progressText = 'Menghitung estimasi TMT Pangkat & KGB berikutnya...';
                } else if (this.progress === 60) {
                    this.progressText = 'Menyimpan 5 data pegawai baru ke database...';
                } else if (this.progress === 80) {
                    this.progressText = 'Mencatat aktivitas ke dalam audit log...';
                } else if (this.progress === 100) {
                    clearInterval(interval);
                    this.progressText = 'Impor selesai!';
                    setTimeout(() => {
                        this.step = 5;
                    }, 500);
                }
            }, 800);
        }
    }">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Import Data Pegawai</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Import Excel/CSV</span>
            </nav>
        </div>

        {{-- STEP INDICATORS (Wizard) --}}
        <div class="rounded-lg border border-border bg-surface p-4 shadow-sm select-none">
            <div class="flex items-center justify-between max-w-3xl mx-auto text-xs font-semibold">
                
                {{-- Step 1 --}}
                <div class="flex items-center gap-2">
                    <span :class="step >= 1 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">1</span>
                    <span :class="step >= 1 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Upload Berkas</span>
                </div>
                <div :class="step > 1 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 max-w-[80px]"></div>

                {{-- Step 2 --}}
                <div class="flex items-center gap-2">
                    <span :class="step >= 2 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">2</span>
                    <span :class="step >= 2 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Preview & Mapping</span>
                </div>
                <div :class="step > 2 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 max-w-[80px]"></div>

                {{-- Step 3 --}}
                <div class="flex items-center gap-2">
                    <span :class="step >= 3 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">3</span>
                    <span :class="step >= 3 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Validasi Data</span>
                </div>
                <div :class="step > 3 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 max-w-[80px]"></div>

                {{-- Step 4 & 5 --}}
                <div class="flex items-center gap-2">
                    <span :class="step >= 5 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">4</span>
                    <span :class="step >= 5 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Hasil Akhir</span>
                </div>
            </div>
        </div>

        {{-- STEP CONTENT --}}
        
        {{-- STEP 1: DOWNLOAD TEMPLATE & UPLOAD --}}
        <div x-show="step === 1" class="space-y-6" x-transition>
            
            {{-- Download Template Card --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">1. Download Template Import Pegawai</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Gunakan template di bawah agar header kolom sesuai dan data dapat terbaca dengan tepat oleh sistem.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
                    <button @click="downloadTemplate('utama')" class="flex flex-col items-center justify-center p-3 rounded-lg border border-primary/20 bg-primary/5 text-center hover:bg-primary/10 transition group cursor-pointer">
                        <span class="text-2xl mb-1 group-hover:scale-105 transition-transform">📄</span>
                        <span class="text-xs font-bold text-primary font-sans">Template Utama</span>
                        <span class="text-[9px] text-muted font-sans mt-0.5">(NIP, Gol, Jabatan, dll.)</span>
                    </button>
                    <button @click="downloadTemplate('pelengkap')" class="flex flex-col items-center justify-center p-3 rounded-lg border border-border bg-surface text-center hover:bg-soft transition group cursor-pointer">
                        <span class="text-2xl mb-1 group-hover:scale-105 transition-transform">👤</span>
                        <span class="text-xs font-semibold text-ink font-sans">Data Pelengkap</span>
                        <span class="text-[9px] text-muted font-sans mt-0.5">(NIK, KK, TTL, dll.)</span>
                    </button>
                    <button @click="downloadTemplate('kepangkatan')" class="flex flex-col items-center justify-center p-3 rounded-lg border border-border bg-surface text-center hover:bg-soft transition group cursor-pointer">
                        <span class="text-2xl mb-1 group-hover:scale-105 transition-transform">🎖️</span>
                        <span class="text-xs font-semibold text-ink font-sans">Riwayat Pangkat</span>
                        <span class="text-[9px] text-muted font-sans mt-0.5">(Append-only pangkat)</span>
                    </button>
                    <button @click="downloadTemplate('jabatan')" class="flex flex-col items-center justify-center p-3 rounded-lg border border-border bg-surface text-center hover:bg-soft transition group cursor-pointer">
                        <span class="text-2xl mb-1 group-hover:scale-105 transition-transform">🏢</span>
                        <span class="text-xs font-semibold text-ink font-sans">Riwayat Jabatan</span>
                        <span class="text-[9px] text-muted font-sans mt-0.5">(Append-only jabatan)</span>
                    </button>
                    <button @click="downloadTemplate('kgb')" class="flex flex-col items-center justify-center p-3 rounded-lg border border-border bg-surface text-center hover:bg-soft transition group cursor-pointer">
                        <span class="text-2xl mb-1 group-hover:scale-105 transition-transform">💰</span>
                        <span class="text-xs font-semibold text-ink font-sans">Riwayat KGB</span>
                        <span class="text-[9px] text-muted font-sans mt-0.5">(Append-only KGB)</span>
                    </button>
                </div>
            </div>

            {{-- Upload File Area Card --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">2. Unggah Berkas Pegawai (Excel / CSV)</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Unggah berkas data pegawai dalam format CSV atau Excel (.xlsx) dengan ukuran maksimal 10MB.</p>
                </div>
                
                {{-- Drag and drop container --}}
                <div 
                    @dragover.prevent="dragover = true" 
                    @dragleave.prevent="dragover = false" 
                    @drop.prevent="dragover = false; handleFileSelect($event)"
                    :class="dragover ? 'border-primary bg-primary/5' : 'border-border bg-soft/50'"
                    class="border-2 border-dashed rounded-lg p-10 text-center relative hover:border-primary transition group"
                >
                    <input type="file" id="import_file" accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="handleFileSelect" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                    <svg class="mx-auto h-12 w-12 text-muted group-hover:text-primary transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                    </svg>
                    <p class="text-sm text-ink font-semibold mt-3 font-sans">Pilih berkas atau seret berkas Anda di sini</p>
                    <p class="text-xs text-muted mt-1 font-sans">Format yang diizinkan: CSV UTF-8 atau Excel (.xlsx). Maksimal 10MB.</p>
                    
                    {{-- File Upload Info State --}}
                    <template x-if="fileName">
                        <div class="mt-4 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                            <svg class="w-4 h-4 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span x-text="fileName"></span>
                            <span class="text-muted" x-text="'(' + fileSize + ')'"></span>
                        </div>
                    </template>
                    <p x-show="fileError" class="text-xs text-danger font-semibold mt-3 font-sans" x-text="fileError"></p>
                </div>

                {{-- Action bar --}}
                <div class="border-t border-border pt-4 flex justify-end">
                    <button 
                        type="button" 
                        @click="if(fileValid) { step = 2; checkMismatch(); }" 
                        :disabled="!fileValid"
                        :class="!fileValid ? 'opacity-50 cursor-not-allowed bg-muted' : 'bg-primary hover:opacity-90 cursor-pointer'"
                        class="inline-flex items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans"
                    >
                        Lanjutkan ke Preview
                    </button>
                </div>
            </div>
        </div>

        {{-- STEP 2: PREVIEW & MAPPING --}}
        <div x-show="step === 2" class="space-y-6" style="display: none;" x-transition>
            
            {{-- Columns Mapping panel --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">1. Pemetaan Kolom Berkas</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Hubungkan header kolom dari berkas Excel/CSV Anda dengan kolom field tujuan di aplikasi SIMPEG.</p>
                </div>
                
                {{-- Warning jika ada kolom mismatch --}}
                <div x-show="warningColumns.length > 0" class="rounded-lg bg-warning/10 border border-warning/20 p-4 text-xs text-warning font-sans flex items-start gap-2.5">
                    <span class="text-base leading-none">⚠️</span>
                    <div>
                        <span class="font-bold">Peringatan: Kolom tidak cocok!</span>
                        <p class="mt-0.5 leading-relaxed">Sistem mendeteksi ada <span x-text="warningColumns.length"></span> kolom berkas yang tidak ter-mapping otomatis ke field SIMPEG. Silakan periksa atau sesuaikan secara manual kolom: <span class="font-semibold text-ink" x-text="warningColumns.join(', ')"></span></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 border border-border rounded-lg p-4 bg-soft/30 max-h-[300px] overflow-y-auto">
                    <template x-for="header in mainHeaders" :key="header">
                        <div class="rounded-lg border border-border bg-surface p-3 flex flex-col gap-2 shadow-xs">
                            <div class="flex justify-between items-center">
                                <span class="text-xs font-bold text-ink font-sans truncate max-w-[150px]" x-text="header"></span>
                                <span :class="mappings[header] ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'" class="text-[9px] px-1.5 py-0.5 font-bold rounded">
                                    <span x-text="mappings[header] ? 'Matched' : 'Unmatched'"></span>
                                </span>
                            </div>
                            <div class="relative">
                                <select 
                                    x-model="mappings[header]" 
                                    @change="checkMismatch()"
                                    class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer"
                                >
                                    <option value="">-- Lewati Kolom Ini --</option>
                                    <template x-for="field in simpegFields" :key="field.key">
                                        <option :value="field.key" x-text="field.label" :selected="mappings[header] === field.key"></option>
                                    </template>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- 10 Rows Preview Table --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">2. Preview Berkas (10 Baris Pertama)</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Berikut adalah pratinjau data pegawai dari berkas yang Anda unggah sebelum masuk ke tahap validasi.</p>
                </div>
                
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-xs">
                        <thead class="bg-soft">
                            <tr>
                                <template x-for="header in mainHeaders" :key="header">
                                    <th class="px-3 py-2 text-left font-bold text-muted border-r border-border truncate max-w-[120px]" x-text="header"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="(row, rIndex) in previewRows" :key="rIndex">
                                <tr class="hover:bg-soft/20">
                                    <template x-for="(cell, cIndex) in row" :key="cIndex">
                                        <td class="px-3 py-2 border-r border-border font-mono text-ink truncate max-w-[120px]" x-text="cell || '-'"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-4 flex justify-between items-center gap-3">
                    <button 
                        type="button" 
                        @click="step = 1; fileName = ''; fileSize = ''; fileValid = false;" 
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans cursor-pointer"
                    >
                        Batal
                    </button>
                    <button 
                        type="button" 
                        @click="step = 3" 
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer"
                    >
                        Lanjutkan ke Validasi
                    </button>
                </div>
            </div>
        </div>

        {{-- STEP 3: VALIDASI DATA --}}
        <div x-show="step === 3" class="space-y-6" style="display: none;" x-transition>
            
            {{-- Ringkasan Validasi Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="rounded-lg border border-border bg-surface p-4 shadow-sm text-center">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Baris Berkas</span>
                    <p class="text-2xl font-bold text-ink font-sans mt-1" x-text="totalRows"></p>
                </div>
                <div class="rounded-lg border border-success/20 bg-success/5 p-4 shadow-sm text-center">
                    <span class="text-[10px] font-bold text-success uppercase tracking-wider font-sans">Baris Valid (Siap Impor)</span>
                    <p class="text-2xl font-bold text-success font-sans mt-1" x-text="validRows"></p>
                </div>
                <div class="rounded-lg border border-primary/20 bg-primary/5 p-4 shadow-sm text-center">
                    <span class="text-[10px] font-bold text-primary uppercase tracking-wider font-sans">Baris Di-skip (NIP Terdaftar)</span>
                    <p class="text-2xl font-bold text-primary font-sans mt-1" x-text="skipRows"></p>
                </div>
                <div class="rounded-lg border border-danger/20 bg-danger/5 p-4 shadow-sm text-center">
                    <span class="text-[10px] font-bold text-danger uppercase tracking-wider font-sans">Baris Error (Bermasalah)</span>
                    <p class="text-2xl font-bold text-danger font-sans mt-1" x-text="errorRows"></p>
                </div>
            </div>

            {{-- Detail Error List Table --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">Hasil Validasi Log Dokumen</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Tinjau daftar baris data yang bermasalah atau sudah terdaftar di database sebelum memulai proses impor.</p>
                </div>
                
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-xs">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-2 text-left font-bold text-muted">No. Baris</th>
                                <th class="px-4 py-2 text-left font-bold text-muted">Nama Pegawai</th>
                                <th class="px-4 py-2 text-left font-bold text-muted">Kolom Target</th>
                                <th class="px-4 py-2 text-left font-bold text-muted">Status Validasi</th>
                                <th class="px-4 py-2 text-left font-bold text-muted">Deskripsi Error / Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="item in validations" :key="item.row">
                                <tr :class="item.status === 'error' ? 'bg-danger/[0.02]' : (item.status === 'skip' ? 'bg-primary/[0.02]' : '')">
                                    <td class="px-4 py-2.5 font-mono text-ink" x-text="item.row"></td>
                                    <td class="px-4 py-2.5 font-semibold text-ink" x-text="item.name"></td>
                                    <td class="px-4 py-2.5 font-mono text-muted" x-text="item.col || '-'"></td>
                                    <td class="px-4 py-2.5">
                                        <span :class="item.status === 'valid' ? 'bg-success/10 text-success' : (item.status === 'skip' ? 'bg-primary/10 text-primary' : 'bg-danger/10 text-danger')" class="inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider font-sans">
                                            <span x-text="item.status"></span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 font-sans" :class="item.status === 'error' ? 'text-danger font-semibold' : (item.status === 'skip' ? 'text-primary' : 'text-success')" x-text="item.error || 'Data siap untuk diimpor'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-4 flex justify-between items-center gap-3">
                    <button 
                        type="button" 
                        @click="step = 1; fileName = ''; fileSize = ''; fileValid = false;" 
                        class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-5 py-2.5 text-sm font-semibold text-danger transition hover:bg-danger/5 shadow-sm font-sans cursor-pointer"
                    >
                        Batalkan Semua
                    </button>
                    <div class="flex items-center gap-3">
                        <button 
                            type="button" 
                            @click="step = 2" 
                            class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans cursor-pointer"
                        >
                            Kembali ke Preview
                        </button>
                        <button 
                            type="button" 
                            @click="startImportSimulation()" 
                            class="inline-flex items-center justify-center rounded-lg bg-success px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer"
                        >
                            Import Hanya yang Valid
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- STEP 4: PROSES IMPORT --}}
        <div x-show="step === 4" class="space-y-6" style="display: none;" x-transition>
            <div class="rounded-lg border border-border bg-surface p-12 shadow-sm text-center max-w-xl mx-auto space-y-6">
                <div class="flex items-center justify-center h-16 w-16 rounded-full bg-primary/10 text-primary mx-auto animate-pulse">
                    <svg class="w-8 h-8 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </div>
                <div class="space-y-2">
                    <h3 class="text-base font-bold text-ink font-sans" x-text="progressText"></h3>
                    <p class="text-xs text-muted font-sans">Proses impor data berjalan di background queue untuk menjaga stabilitas memori.</p>
                </div>
                
                {{-- Progress Bar --}}
                <div class="space-y-1">
                    <div class="w-full bg-soft rounded-full h-2.5 overflow-hidden border border-border">
                        <div class="bg-primary h-2.5 rounded-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                    </div>
                    <span class="text-[10px] font-bold text-muted font-mono" x-text="progress + '%'"></span>
                </div>
            </div>
        </div>

        {{-- STEP 5: LAPORAN HASIL --}}
        <div x-show="step === 5" class="space-y-6" style="display: none;" x-transition>
            
            {{-- Success Result Card --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
                <div class="flex items-center gap-4 border-b border-border pb-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-success/15 text-success text-2xl">
                        ✓
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-ink font-sans">Proses Impor Selesai Diproses!</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Hasil ringkasan data dari berkas yang diunggah tercantum di bawah ini.</p>
                    </div>
                </div>

                {{-- Result stats --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                    <div class="rounded-lg bg-success/5 border border-success/15 p-4">
                        <span class="text-xs font-bold text-success uppercase tracking-wider font-sans">Jumlah Berhasil</span>
                        <p class="text-2xl font-bold text-success mt-1" x-text="validRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(Status Aktif di database)</span>
                    </div>
                    <div class="rounded-lg bg-primary/5 border border-primary/15 p-4">
                        <span class="text-xs font-bold text-primary uppercase tracking-wider font-sans">Jumlah Di-skip</span>
                        <p class="text-2xl font-bold text-primary mt-1" x-text="skipRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(NIP terdaftar - dilewati)</span>
                    </div>
                    <div class="rounded-lg bg-danger/5 border border-danger/15 p-4">
                        <span class="text-xs font-bold text-danger uppercase tracking-wider font-sans">Jumlah Gagal</span>
                        <p class="text-2xl font-bold text-danger mt-1" x-text="errorRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(Data bermasalah)</span>
                    </div>
                </div>

                {{-- Download Failures & Audit log --}}
                <div class="bg-soft/40 border border-border rounded-lg p-4 space-y-3.5 text-xs text-ink font-sans">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <span class="font-bold">Laporan Kegagalan Berkas</span>
                            <p class="text-[11px] text-muted mt-0.5">Unduh file laporan berisi daftar baris bermasalah beserta alasan kegagalan untuk direvisi.</p>
                        </div>
                        <button 
                            @click="downloadErrorReport()"
                            class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-4 py-2 font-bold text-danger transition hover:bg-danger/5 shadow-xs cursor-pointer"
                        >
                            📥 Unduh Laporan Gagal (.csv)
                        </button>
                    </div>
                    
                    <div class="border-t border-border/80 pt-3 space-y-1 text-muted text-[11px]">
                        <span class="font-bold text-ink uppercase tracking-wider block text-[9px] mb-1">📝 Catatan Audit Log</span>
                        <p>• User Operator: <span class="font-semibold text-ink">{{ session('active_role') ?? 'Admin Kepegawaian' }}</span></p>
                        <p>• Timestamp: <span class="font-mono text-ink">{{ date('Y-m-d H:i:s') }} WITA</span></p>
                        <p>• Berkas: <span class="font-mono text-ink" x-text="fileName"></span></p>
                    </div>
                </div>

                {{-- Finish button --}}
                <div class="border-t border-border pt-4 flex justify-end">
                    <a 
                        href="{{ route('data-pegawai') }}"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans"
                    >
                        Kembali ke Daftar Pegawai
                    </a>
                </div>
            </div>
        </div>

    </div>
</x-layouts.app>
