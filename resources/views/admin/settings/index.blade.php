<x-layouts.app title="Pengaturan">

    @php
    $instansi = [
        'nama' => 'LLDIKTI Wilayah XVI',
        'alamat' => 'Jl. Raya Klabat, Manado, Sulawesi Utara',
        'email' => 'info@lldikti16.kemdikbud.go.id',
        'telepon' => '(0431) 890123',
        'kepala' => 'Munawir Sadzali Razak, S.I.P., M.A.',
        'nip_kepala' => '198305142009121003',
        'session_lifetime' => 30,
        'smtp_host' => 'mailpit',
        'smtp_port' => 1025
    ];

    $cutiConfig = [
        'stage1_role' => 'atasan_langsung',
        'stage2_approver' => 'Riza Hamzah, S.Sos.',
        'stage2_nip' => '197804122005012002',
        'stage3_approver' => 'Munawir Sadzali Razak, S.I.P., M.A.',
        'stage3_nip' => '198305142009121003',
        'skip_duplicate' => true,
        'default_jatah' => 12
    ];

    $usersMapping = [
        [
            'id' => 1,
            'name' => 'Ahmad Fauzi',
            'nip' => '19850312201001 1 001',
            'email' => 'ahmadfauzi@gmail.com',
            'keycloak_id' => 'user-fauzi-85',
            'role' => 'admin_kepegawaian',
            'status' => 'Terhubung'
        ],
        [
            'id' => 2,
            'name' => 'Siti Rahayu',
            'nip' => '19901120201501 2 003',
            'email' => 'sitirahayu@gmail.com',
            'keycloak_id' => 'user-rahayu-90',
            'role' => 'atasan_langsung',
            'status' => 'Terhubung'
        ],
        [
            'id' => 3,
            'name' => 'Sabrina Rossa',
            'nip' => '20261210820500 0 04',
            'email' => 'sabrinarossa24@gmail.com',
            'keycloak_id' => 'user-sabrina-26',
            'role' => 'pegawai',
            'status' => 'Terhubung'
        ],
        [
            'id' => 4,
            'name' => 'Cimma Sari Oktariani',
            'nip' => '26110820520600 0 04',
            'email' => 'sikaemma@gmail.com',
            'keycloak_id' => 'user-cimma-26',
            'role' => 'pegawai',
            'status' => 'Terhubung'
        ],
        [
            'id' => 5,
            'name' => 'Nurarningsih Dumbea',
            'nip' => '19880123202 1 1005',
            'email' => 'rainingdumbea47@gmail.com',
            'keycloak_id' => '',
            'role' => 'pegawai',
            'status' => 'Belum Terhubung'
        ]
    ];

    $dataMaster = [
        ['tabel' => 'ref_golongan', 'deskripsi' => 'Data Golongan PNS (I/a s/d IV/e)', 'baris' => 17, 'sprint' => 'Sprint 1', 'route' => 'data-master'],
        ['tabel' => 'ref_jenis_jabatan', 'deskripsi' => 'Struktural, Fungsional Tertentu, Fungsional Umum, Pimpinan Tinggi', 'baris' => 4, 'sprint' => 'Sprint 1', 'route' => 'data-master'],
        ['tabel' => 'ref_unit_kerja', 'deskripsi' => 'Pembagian sub-bidang / sekretariat LLDIKTI XVI', 'baris' => 6, 'sprint' => 'Sprint 1', 'route' => 'data-master'],
        ['tabel' => 'ref_bup', 'deskripsi' => 'Batas Usia Pensiun per jenis jabatan kepegawaian', 'baris' => 3, 'sprint' => 'Sprint 1', 'route' => 'data-master'],
        ['tabel' => 'ref_eselon', 'deskripsi' => 'Tingkat jabatan struktural eselonering (I/a s/d IV/b)', 'baris' => 8, 'sprint' => 'Sprint 2', 'route' => 'data-master'],
        ['tabel' => 'ref_jenis_cuti', 'deskripsi' => 'Kategori cuti (Cuti Tahunan, Sakit, Melahirkan, Penting, dll)', 'baris' => 6, 'sprint' => 'Sprint 2', 'route' => 'data-master'],
        ['tabel' => 'ref_agama', 'deskripsi' => 'Daftar agama resmi untuk administrasi pegawai', 'baris' => 6, 'sprint' => 'Sprint 2', 'route' => 'data-master'],
        ['tabel' => 'ref_jenis_kelamin', 'deskripsi' => 'Identitas gender pegawai (Laki-laki, Perempuan)', 'baris' => 2, 'sprint' => 'Sprint 2', 'route' => 'data-master'],
        ['tabel' => 'ref_status_perkawinan', 'deskripsi' => 'Status marital (Belum Kawin, Kawin, Cerai Hidup, Cerai Mati)', 'baris' => 4, 'sprint' => 'Sprint 2', 'route' => 'data-master'],
        ['tabel' => 'ref_jenjang_pendidikan', 'deskripsi' => 'Pendidikan formal terakhir (SD, SMP, SMA, D3, S1, S2, S3)', 'baris' => 7, 'sprint' => 'Sprint 2', 'route' => 'data-master'],
        ['tabel' => 'ref_hari_libur', 'deskripsi' => 'Kalender libur nasional dan cuti bersama', 'baris' => 14, 'sprint' => 'Sprint 2', 'route' => 'hari-libur'],
    ];
    @endphp

    <div x-data="{
        activeTab: 'umum',
        instansi: {{ json_encode($instansi) }},
        cutiConfig: {{ json_encode($cutiConfig) }},
        users: {{ json_encode($usersMapping) }},
        dataMaster: {{ json_encode($dataMaster) }},
        
        searchUser: '',
        showEditModal: false,
        selectedUser: {id: null, name: '', nip: '', email: '', keycloak_id: '', role: '', status: ''},

        get filteredUsers() {
            return this.users.filter(u => {
                return u.name.toLowerCase().includes(this.searchUser.toLowerCase()) || 
                       u.nip.toLowerCase().includes(this.searchUser.toLowerCase()) ||
                       u.email.toLowerCase().includes(this.searchUser.toLowerCase()) ||
                       u.role.toLowerCase().includes(this.searchUser.toLowerCase());
            });
        },
        openEditUser(user) {
            this.selectedUser = Object.assign({}, user);
            this.showEditModal = true;
        },
        saveUser() {
            $dispatch('open-confirm-sso');
        },
        executeSaveUser() {
            const idx = this.users.findIndex(u => u.id === this.selectedUser.id);
            if (idx !== -1) {
                this.selectedUser.status = this.selectedUser.keycloak_id.trim() ? 'Terhubung' : 'Belum Terhubung';
                this.users[idx] = this.selectedUser;
            }
            this.showEditModal = false;
        },
        updateStage2(val) {
            const parts = val.split('|');
            this.cutiConfig.stage2_approver = parts[0];
            this.cutiConfig.stage2_nip = parts[1];
        },
        updateStage3(val) {
            const parts = val.split('|');
            this.cutiConfig.stage3_approver = parts[0];
            this.cutiConfig.stage3_nip = parts[1];
        }
    }" @confirm-sso.window="executeSaveUser()"
       @confirm-instansi.window="$refs.formUmum.submit()"
       @confirm-cuti.window="$refs.formCuti.submit()" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between border-b border-border pb-4">
            <div>
                <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Pengaturan Sistem</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink font-sans">Pengaturan</span>
                    <span>•</span>
                    <span class="text-muted italic">Akses: Khusus Super Admin</span>
                </nav>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-info">
                    🔒 Audit Trail Aktif
                </span>
            </div>
        </div>

        {{-- NOTIFICATIONS --}}
        @if(session('success'))
            <div class="rounded-lg border border-success/20 bg-success/10 p-4 text-sm font-semibold text-success flex items-center gap-2 shadow-sm">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            
            {{-- Tab Sidebar Kiri --}}
            <aside class="w-full lg:w-64 shrink-0">
                <div class="rounded-lg border border-border bg-surface p-4 shadow-sm space-y-1">
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wide px-3 pb-2 border-b border-border mb-2 font-sans">Kategori Pengaturan</p>
                    
                    <button
                        @click="activeTab = 'umum'"
                        :class="activeTab === 'umum' ? 'bg-primary text-white font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'"
                        class="w-full text-left px-4 py-3 text-sm rounded-lg transition-colors duration-200 focus:outline-none cursor-pointer flex items-center justify-between"
                    >
                        <span>Umum & Instansi</span>
                    </button>
                    
                    <button
                        @click="activeTab = 'cuti'"
                        :class="activeTab === 'cuti' ? 'bg-primary text-white font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'"
                        class="w-full text-left px-4 py-3 text-sm rounded-lg transition-colors duration-200 focus:outline-none cursor-pointer flex items-center justify-between"
                    >
                        <span>Alur Approval Cuti</span>
                    </button>
                    
                    <button
                        @click="activeTab = 'rbac'"
                        :class="activeTab === 'rbac' ? 'bg-primary text-white font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'"
                        class="w-full text-left px-4 py-3 text-sm rounded-lg transition-colors duration-200 focus:outline-none cursor-pointer flex items-center justify-between"
                    >
                        <span>Pemetaan SSO & RBAC</span>
                    </button>
                    
                    <button
                        @click="activeTab = 'master'"
                        :class="activeTab === 'master' ? 'bg-primary text-white font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'"
                        class="w-full text-left px-4 py-3 text-sm rounded-lg transition-colors duration-200 focus:outline-none cursor-pointer flex items-center justify-between"
                    >
                        <span>Kamus Data Master</span>
                    </button>

                    <hr class="border-border my-2" />

                    {{-- Shortcut EWS --}}
                    <a
                        href="{{ route('ews.config') }}"
                        class="w-full text-left px-4 py-3 text-sm rounded-lg text-muted hover:bg-soft hover:text-ink font-medium transition-colors duration-200 cursor-pointer flex items-center justify-between"
                    >
                        <span>Konfigurasi EWS</span>
                        <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                    </a>
                </div>
            </aside>

            {{-- Form Panel Kanan --}}
            <main class="flex-1 min-w-0">
                
                {{-- TAB: UMUM & INSTANSI --}}
                <div x-show="activeTab === 'umum'" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
                    <form x-ref="formUmum" action="{{ route('settings.update') }}" method="POST" @submit.prevent="$dispatch('open-confirm-instansi')" class="space-y-6">
                        @csrf
                        <div class="border-b border-border pb-4">
                            <h2 class="text-lg font-bold text-ink font-sans leading-tight">Pengaturan Umum & Instansi</h2>
                            <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Kelola profil lembaga LLDIKTI XVI dan parameter dasar server.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-ink font-sans">Nama Lembaga</label>
                                <input type="text" x-model="instansi.nama" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-ink font-sans">Kepala Instansi</label>
                                <input type="text" x-model="instansi.kepala" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-ink font-sans">NIP Kepala Instansi</label>
                                <input type="text" x-model="instansi.nip_kepala" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-ink font-sans">Session Lifetime (Menit)</label>
                                <input type="number" x-model="instansi.session_lifetime" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            </div>
                            <div class="col-span-1 md:col-span-2 space-y-1">
                                <label class="text-xs font-semibold text-ink font-sans">Alamat Kantor</label>
                                <textarea rows="3" x-model="instansi.alamat" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none"></textarea>
                            </div>
                        </div>

                        <div class="border-t border-border pt-6 space-y-4">
                            <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Konfigurasi SMTP Mail Server</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-1">
                                    <label class="text-xs font-semibold text-ink font-sans">SMTP Host</label>
                                    <input type="text" x-model="instansi.smtp_host" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-xs font-semibold text-ink font-sans">SMTP Port</label>
                                    <input type="number" x-model="instansi.smtp_port" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-border pt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <p class="text-[10px] text-muted italic font-sans flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                                </svg>
                                🔒 Perubahan ini akan dicatat ke dalam Audit Log sistem secara real-time.
                            </p>
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2 font-sans cursor-pointer">
                                Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>

                {{-- TAB: ALUR APPROVAL CUTI --}}
                <div x-show="activeTab === 'cuti'" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                    <form x-ref="formCuti" action="{{ route('settings.update') }}" method="POST" @submit.prevent="$dispatch('open-confirm-cuti')" class="space-y-6">
                        @csrf
                        <div class="border-b border-border pb-4">
                            <h2 class="text-lg font-bold text-ink font-sans leading-tight">Alur Persetujuan Cuti</h2>
                            <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Konfigurasi rantai otorisasi bertingkat (3 Stage) untuk pengajuan cuti pegawai.</p>
                        </div>

                        {{-- Disclaimer Info --}}
                        <div class="rounded-lg border border-warning/20 bg-warning/5 p-4 flex gap-3 text-xs text-warning leading-relaxed shadow-sm">
                            <svg class="w-5 h-5 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            <div>
                                <span class="font-bold">⚠️ INFORMASI ALUR:</span> Setiap perubahan alur persetujuan cuti <strong>hanya berlaku bagi pengajuan cuti baru</strong> yang didaftarkan setelah konfigurasi ini disimpan. Pengajuan cuti yang sedang berjalan (pending approval) tetap menggunakan alur lama.
                            </div>
                        </div>

                        <div class="space-y-6">
                            {{-- Stage 1 --}}
                            <div class="flex gap-4 items-start p-4 rounded-lg bg-soft/40 border border-border">
                                <span class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0 font-sans mt-0.5">1</span>
                                <div class="space-y-1 flex-1">
                                    <p class="text-xs font-bold text-ink font-sans">Stage 1: Verifikasi Atasan Langsung</p>
                                    <p class="text-[10px] text-muted font-sans leading-normal">Otomatis dicarikan berdasarkan NIP Atasan yang terdaftar di masing-masing profil pegawai.</p>
                                </div>
                            </div>

                            {{-- Stage 2 --}}
                            <div class="flex gap-4 items-start p-4 rounded-lg bg-soft/40 border border-border">
                                <span class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0 font-sans mt-0.5">2</span>
                                <div class="space-y-3 flex-1">
                                    <div>
                                        <p class="text-xs font-bold text-ink font-sans">Stage 2: Verifikator Kepegawaian (Kabag)</p>
                                        <p class="text-[10px] text-muted font-sans leading-normal">Verifikasi administratif berkas cuti oleh tim kepegawaian default.</p>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="space-y-1 col-span-1 md:col-span-2">
                                            <label class="text-[10px] font-semibold text-ink font-sans">Pilih Approver Default (Dropdown)</label>
                                            <select @change="updateStage2($event.target.value)" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                                <option value="Riza Hamzah, S.Sos.|197804122005012002" :selected="cutiConfig.stage2_nip === '197804122005012002'">Riza Hamzah, S.Sos. (NIP: 197804122005012002)</option>
                                                <option value="Ahmad Fauzi|198503122010011001" :selected="cutiConfig.stage2_nip === '198503122010011001'">Ahmad Fauzi (NIP: 198503122010011001)</option>
                                                <option value="Nadia Kusuma|199508222020012002" :selected="cutiConfig.stage2_nip === '199508222020012002'">Nadia Kusuma (NIP: 199508222020012002)</option>
                                            </select>
                                        </div>
                                        <div class="space-y-1">
                                            <label class="text-[10px] font-semibold text-ink font-sans">Nama Approver Terpilih</label>
                                            <input type="text" x-model="cutiConfig.stage2_approver" disabled class="h-[44px] w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-xs text-muted shadow-sm focus:outline-none font-sans">
                                        </div>
                                        <div class="space-y-1">
                                            <label class="text-[10px] font-semibold text-ink font-sans">NIP Approver Terpilih</label>
                                            <input type="text" x-model="cutiConfig.stage2_nip" disabled class="h-[44px] w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-xs text-muted shadow-sm focus:outline-none font-sans">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Stage 3 --}}
                            <div class="flex gap-4 items-start p-4 rounded-lg bg-soft/40 border border-border">
                                <span class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0 font-sans mt-0.5">3</span>
                                <div class="space-y-3 flex-1">
                                    <div>
                                        <p class="text-xs font-bold text-ink font-sans">Stage 3: Persetujuan Akhir (Pimpinan)</p>
                                        <p class="text-[10px] text-muted font-sans leading-normal">Otorisasi tertinggi oleh Kepala LLDIKTI XVI untuk keputusan disetujui / ditangguhkan.</p>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="space-y-1 col-span-1 md:col-span-2">
                                            <label class="text-[10px] font-semibold text-ink font-sans">Pilih Approver Default (Dropdown)</label>
                                            <select @change="updateStage3($event.target.value)" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                                <option value="Munawir Sadzali Razak, S.I.P., M.A.|198305142009121003" :selected="cutiConfig.stage3_nip === '198305142009121003'">Munawir Sadzali Razak, S.I.P., M.A. (NIP: 198305142009121003)</option>
                                                <option value="Siraajuddin Laluv, OE., M.|197212311984011062" :selected="cutiConfig.stage3_nip === '197212311984011062'">Siraajuddin Laluv, OE., M. (NIP: 197212311984011062)</option>
                                                <option value="Yucna Dara, S.P., M.M.|198401202009922002" :selected="cutiConfig.stage3_nip === '198401202009922002'">Yucna Dara, S.P., M.M. (NIP: 198401202009922002)</option>
                                            </select>
                                        </div>
                                        <div class="space-y-1">
                                            <label class="text-[10px] font-semibold text-ink font-sans">Nama Approver Terpilih</label>
                                            <input type="text" x-model="cutiConfig.stage3_approver" disabled class="h-[44px] w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-xs text-muted shadow-sm focus:outline-none font-sans">
                                        </div>
                                        <div class="space-y-1">
                                            <label class="text-[10px] font-semibold text-ink font-sans">NIP Approver Terpilih</label>
                                            <input type="text" x-model="cutiConfig.stage3_nip" disabled class="h-[44px] w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-xs text-muted shadow-sm focus:outline-none font-sans">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Rules Toggles --}}
                            <div class="pt-4 border-t border-border space-y-4">
                                <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kebijakan Otorisasi</h3>
                                
                                <div class="flex items-start justify-between p-3 rounded bg-soft/40 text-xs">
                                    <div class="space-y-0.5">
                                        <p class="font-semibold text-ink font-sans">Skip Approver Duplikat</p>
                                        <p class="text-muted text-[10px] font-sans leading-normal">Jika atasan langsung pengaju cuti kebetulan menjabat sebagai verifikator kepegawaian, lewati stage duplikat.</p>
                                    </div>
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" x-model="cutiConfig.skip_duplicate" class="rounded border-primary/20 text-primary focus:ring-primary/30 h-4.5 w-4.5 cursor-pointer">
                                    </div>
                                </div>

                                <div class="flex items-center justify-between p-3 rounded bg-soft/40 text-xs">
                                    <div class="space-y-0.5">
                                        <p class="font-semibold text-ink font-sans">Jatah Cuti Tahunan Pegawai (Default)</p>
                                        <p class="text-muted text-[10px] font-sans leading-normal">Kuota cuti tahun berjalan yang akan dialokasikan kepada pegawai baru.</p>
                                    </div>
                                    <div class="w-24">
                                        <input type="number" x-model="cutiConfig.default_jatah" class="h-[44px] w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink text-center shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="border-t border-border pt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <p class="text-[10px] text-muted italic font-sans flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                                </svg>
                                🔒 Perubahan ini akan dicatat ke dalam Audit Log sistem secara real-time.
                            </p>
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2 font-sans cursor-pointer">
                                Simpan Kebijakan Cuti
                            </button>
                        </div>
                    </form>
                </div>

                {{-- TAB: PEMETAAN SSO & RBAC --}}
                <div x-show="activeTab === 'rbac'" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                    <div class="border-b border-border pb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-ink font-sans leading-tight">Pemetaan Akun SSO & Otorisasi RBAC</h2>
                            <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Hubungkan email Keycloak SSO dengan data pegawai internal serta kelola role.</p>
                        </div>
                        <div>
                            <input
                                type="text"
                                x-model="searchUser"
                                placeholder="Cari nama/NIP..."
                                class="h-[44px] w-64 rounded-lg border border-border bg-soft px-3 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"
                            >
                        </div>
                    </div>

                    {{-- Table --}}
                    <div class="overflow-x-auto border border-border rounded-lg shadow-sm">
                        <table class="w-full">
                            <thead class="bg-soft/40">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Nama Pegawai</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">NIP</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Keycloak ID / Email SSO</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Role Internal</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Status</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <template x-for="user in filteredUsers" :key="user.id">
                                    <tr class="hover:bg-soft/30 transition-colors">
                                        <td class="px-4 py-3 text-sm font-semibold text-ink font-sans" x-text="user.name"></td>
                                        <td class="px-4 py-3 text-xs font-mono text-muted" x-text="user.nip"></td>
                                        <td class="px-4 py-3 text-xs text-ink">
                                            <div class="font-semibold" x-text="user.keycloak_id || '-'"></div>
                                            <div class="text-[10px] text-muted font-mono mt-0.5" x-text="user.email"></div>
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            <span class="text-[10px] font-bold uppercase tracking-wide"
                                                :class="{
                                                    'bg-danger/10 text-danger': user.role === 'super_admin',
                                                    'bg-primary/10 text-primary': user.role === 'admin_kepegawaian',
                                                    'bg-info/10 text-info': user.role === 'pimpinan',
                                                    'bg-warning/10 text-warning': user.role === 'atasan_langsung',
                                                    'bg-success/10 text-success': user.role === 'pegawai'
                                                }"
                                                x-text="user.role"
                                            ></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs font-sans">
                                            <span :class="user.status === 'Terhubung' ? 'text-success font-semibold' : 'text-danger font-semibold'" x-text="user.status"></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <button @click="openEditUser(user)" class="text-xs font-semibold text-primary hover:underline cursor-pointer focus:outline-none font-sans">
                                                Edit Pemetaan
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- TAB: DATA MASTER REFERENSI --}}
                <div x-show="activeTab === 'master'" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                    <div class="border-b border-border pb-4">
                        <h2 class="text-lg font-bold text-primary font-sans leading-tight">Data Master Kamus Referensi</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Kelola record data referensi sistem (static seeder tables).</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-for="master in dataMaster" :key="master.tabel">
                            <div class="border border-border rounded-lg p-4 bg-soft/40 flex flex-col justify-between shadow-sm">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-mono font-bold text-primary" x-text="master.tabel"></span>
                                        <span class="rounded bg-soft px-2 py-0.5 text-[9px] font-mono text-muted" x-text="master.sprint"></span>
                                    </div>
                                    <p class="text-xs text-ink font-sans font-semibold pt-1" x-text="master.deskripsi"></p>
                                    <p class="text-[10px] text-muted font-sans" x-text="master.baris + ' baris data terdaftar'"></p>
                                </div>
                                <div class="pt-3 flex justify-end">
                                    <a :href="'/' + master.route" class="text-xs font-semibold text-primary hover:underline cursor-pointer focus:outline-none font-sans">
                                        Kelola Data
                                    </a>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

            </main>
        </div>

        {{-- ================================================================ --}}
        {{-- MODAL GLOBAL EDIT USER MAPPING --}}
        {{-- ================================================================ --}}
        <div x-show="showEditModal" class="fixed inset-0 z-50 overflow-hidden" style="display: none;" x-transition>
            <div class="absolute inset-0 bg-ink/30 transition-opacity" @click="showEditModal = false"></div>
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-md bg-surface border border-border rounded-lg shadow-xl flex flex-col overflow-hidden">
                    
                    {{-- Header --}}
                    <div class="px-6 py-5 border-b border-border flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Edit Otorisasi & SSO</h3>
                            <p class="text-[11px] text-muted font-sans mt-0.5" x-text="selectedUser.name"></p>
                        </div>
                        <button @click="showEditModal = false" class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none">
                            Tutup
                        </button>
                    </div>

                    {{-- Body --}}
                    <div class="p-6 space-y-4">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Keycloak ID / Email SSO</label>
                            <input type="text" x-model="selectedUser.keycloak_id" placeholder="Masukkan ID Keycloak..." class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Role Internal</label>
                            <select x-model="selectedUser.role" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Super Admin">Super Admin</option>
                                <option value="Admin Kepegawaian">Admin Kepegawaian</option>
                                <option value="Pimpinan">Pimpinan</option>
                                <option value="Atasan Langsung">Atasan Langsung</option>
                                <option value="Pegawai">Pegawai</option>
                            </select>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 border-t border-border bg-soft flex justify-end gap-3">
                        <button @click="showEditModal = false" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2.5 text-xs font-semibold text-primary transition hover:border-primary/30 hover:bg-soft/40 cursor-pointer focus:outline-none font-sans">
                            Batal
                        </button>
                        <button @click="saveUser" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2 font-sans">
                            Simpan Pemetaan
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <x-ui.confirm-dialog
            id="sso"
            title="Ubah Pemetaan SSO"
            message="Apakah Anda yakin ingin mengubah pemetaan SSO & role user ini?"
            confirm-text="Ya, Simpan"
            variant="warning"
        />

        <x-ui.confirm-dialog
            id="instansi"
            title="Simpan Pengaturan Instansi"
            message="Apakah Anda yakin ingin menyimpan perubahan konfigurasi instansi & server SMTP ini?"
            confirm-text="Simpan Perubahan"
            variant="warning"
        />

        <x-ui.confirm-dialog
            id="cuti"
            title="Perbarui Alur Cuti"
            message="Apakah Anda yakin ingin memperbarui kebijakan alur persetujuan cuti? Perubahan ini hanya akan berdampak pada pengajuan cuti baru."
            confirm-text="Perbarui"
            variant="warning"
        />

    </div>

</x-layouts.app>
