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
        ['tabel' => 'ref_golongan', 'deskripsi' => 'Data Golongan PNS (I/a s/d IV/e)', 'baris' => 17, 'sprint' => 'Sprint 1'],
        ['tabel' => 'ref_jenis_jabatan', 'deskripsi' => 'Struktural, Fungsional Tertentu, Fungsional Umum', 'baris' => 4, 'sprint' => 'Sprint 1'],
        ['tabel' => 'ref_unit_kerja', 'deskripsi' => 'Pembagian sub-bidang / sekretariat LLDIKTI XVI', 'baris' => 6, 'sprint' => 'Sprint 1'],
        ['tabel' => 'ref_bup', 'deskripsi' => 'Batas Usia Pensiun per jenis jabatan kepegawaian', 'baris' => 3, 'sprint' => 'Sprint 1'],
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
            const idx = this.users.findIndex(u => u.id === this.selectedUser.id);
            if (idx !== -1) {
                this.selectedUser.status = this.selectedUser.keycloak_id.trim() ? 'Terhubung' : 'Belum Terhubung';
                this.users[idx] = this.selectedUser;
            }
            this.showEditModal = false;
        }
    }" class="flex flex-col lg:flex-row gap-6">
        
        {{-- Tab Sidebar Kiri --}}
        <aside class="w-full lg:w-64 shrink-0">
            <x-ui.card padding="sm" class="space-y-1">
                <p class="text-[10px] font-bold text-muted uppercase tracking-wide px-3 pb-2 border-b border-border mb-2 font-sans">Kategori Pengaturan</p>
                
                <x-ui.tabs variant="sidebar-soft" label="Kategori pengaturan">
                    <x-ui.tab variant="sidebar-soft" active="activeTab === 'umum'" click="activeTab = 'umum'">
                        <span>Umum & Instansi</span>
                    </x-ui.tab>

                    <x-ui.tab variant="sidebar-soft" active="activeTab === 'cuti'" click="activeTab = 'cuti'">
                        <span>Alur Approval Cuti</span>
                    </x-ui.tab>

                    <x-ui.tab variant="sidebar-soft" active="activeTab === 'rbac'" click="activeTab = 'rbac'">
                        <span>Pemetaan SSO & RBAC</span>
                    </x-ui.tab>

                    <x-ui.tab variant="sidebar-soft" active="activeTab === 'master'" click="activeTab = 'master'">
                        <span>Kamus Data Master</span>
                    </x-ui.tab>
                </x-ui.tabs>
            </x-ui.card>
        </aside>

        {{-- Form Panel Kanan --}}
        <main class="flex-1 min-w-0">
            
            {{-- TAB: UMUM & INSTANSI --}}
            <x-ui.card padding="lg" x-show="activeTab === 'umum'" class="space-y-6">
                <div class="border-b border-border pb-4">
                    <h2 class="text-base font-bold text-ink font-sans leading-tight">Pengaturan Umum & Instansi</h2>
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
                    <x-form.textarea
                        label="Alamat Kantor"
                        rows="3"
                        size="sm"
                        wrapper-class="col-span-1 md:col-span-2"
                        label-class="font-semibold normal-case tracking-normal"
                        x-model="instansi.alamat"
                    />
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

                <div class="border-t border-border pt-6 flex justify-end">
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                        Simpan Perubahan
                    </button>
                </div>
            </x-ui.card>

            {{-- TAB: ALUR APPROVAL CUTI --}}
            <x-ui.card padding="lg" x-show="activeTab === 'cuti'"   style="display: none;" class="space-y-6">
                <div class="border-b border-border pb-4">
                    <h2 class="text-base font-bold text-ink font-sans leading-tight">Alur Persetujuan Cuti</h2>
                    <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Konfigurasi rantai otorisasi bertingkat (3 Stage) untuk pengajuan cuti pegawai.</p>
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
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-ink font-sans">Nama Approver</label>
                                    <input type="text" x-model="cutiConfig.stage2_approver" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-ink font-sans">NIP Approver</label>
                                    <input type="text" x-model="cutiConfig.stage2_nip" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
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
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-ink font-sans">Nama Approver</label>
                                    <input type="text" x-model="cutiConfig.stage3_approver" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-ink font-sans">NIP Approver</label>
                                    <input type="text" x-model="cutiConfig.stage3_nip" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Rules Toggles --}}
                    <div class="pt-4 border-t border-border space-y-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kebijakan Otorisasi</h3>
                        
                        <div class="flex items-start justify-between p-3 rounded bg-soft/30 text-xs">
                            <div class="space-y-0.5">
                                <p class="font-semibold text-ink font-sans">Skip Approver Duplikat</p>
                                <p class="text-muted text-[10px] font-sans leading-normal">Jika atasan langsung pengaju cuti kebetulan menjabat sebagai verifikator kepegawaian, lewati stage duplikat.</p>
                            </div>
                            <div class="flex items-center h-5">
                                <x-form.checkbox x-model="cutiConfig.skip_duplicate" />
                            </div>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded bg-soft/30 text-xs">
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

                <div class="border-t border-border pt-6 flex justify-end">
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                        Simpan Kebijakan Cuti
                    </button>
                </div>
            </x-ui.card>

            {{-- TAB: PEMETAAN SSO & RBAC --}}
            <x-ui.card padding="lg" x-show="activeTab === 'rbac'"   style="display: none;" class="space-y-6">
                <div class="border-b border-border pb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-bold text-ink font-sans leading-tight">Pemetaan Akun SSO & Otorisasi RBAC</h2>
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
                <div class="overflow-x-auto border border-border rounded-lg">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="sm">Nama Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="sm">NIP</x-ui.table-th>
                                <x-ui.table-th padding="sm">Keycloak ID / Email SSO</x-ui.table-th>
                                <x-ui.table-th padding="sm">Role Internal</x-ui.table-th>
                                <x-ui.table-th padding="sm">Status</x-ui.table-th>
                                <x-ui.table-th padding="sm">Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            <template x-for="user in filteredUsers" :key="user.id">
                                <x-ui.table-row :interactive="true">
                                    <x-ui.table-td x-text="user.name" padding="sm" class="text-sm font-semibold"></x-ui.table-td>
                                    <x-ui.table-td x-text="user.nip" padding="sm" class="font-mono text-muted"></x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <div class="font-semibold" x-text="user.keycloak_id || '-'"></div>
                                        <div class="text-[10px] text-muted font-mono mt-0.5" x-text="user.email"></div>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <span class="text-[10px] font-bold uppercase tracking-wide"
                                            :class="{
                                                'bg-primary/10 text-primary': user.role === 'admin_kepegawaian',
                                                'bg-warning/10 text-warning': user.role === 'atasan_langsung',
                                                'bg-success/10 text-success': user.role === 'pegawai'
                                            }"
                                            x-text="user.role"
                                        ></span>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <span :class="user.status === 'Terhubung' ? 'text-success font-semibold' : 'text-danger font-semibold'" x-text="user.status"></span>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <button @click="openEditUser(user)" class="text-xs font-semibold text-primary hover:underline cursor-pointer focus:outline-none font-sans">
                                            Edit Pemetaan
                                        </button>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>

            {{-- TAB: DATA MASTER REFERENSI --}}
            <x-ui.card padding="lg" x-show="activeTab === 'master'"   style="display: none;" class="space-y-6">
                <div class="border-b border-border pb-4">
                    <h2 class="text-base font-bold text-ink font-sans leading-tight">Data Master Kamus Referensi</h2>
                    <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Kelola record data referensi sistem (static seeder tables).</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <template x-for="master in dataMaster" :key="master.tabel">
                        <div class="border border-border rounded-lg p-4 bg-soft/10 flex flex-col justify-between">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-mono font-bold text-primary" x-text="master.tabel"></span>
                                    <span class="rounded bg-soft px-2 py-0.5 text-[9px] font-mono text-muted" x-text="master.sprint"></span>
                                </div>
                                <p class="text-xs text-ink font-sans font-semibold pt-1" x-text="master.deskripsi"></p>
                                <p class="text-[10px] text-muted font-sans" x-text="master.baris + ' baris data terdaftar'"></p>
                            </div>
                            <div class="pt-3 flex justify-end">
                                <button class="text-xs font-semibold text-primary hover:underline cursor-pointer focus:outline-none font-sans">
                                    Kelola Data
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </x-ui.card>

        </main>

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
                                <option value="Pegawai">Pegawai</option>
                                <option value="Atasan Langsung">Atasan Langsung</option>
                                <option value="Admin Kepegawaian">Admin Kepegawaian</option>
                            </select>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 border-t border-border bg-soft flex justify-end gap-3">
                        <button @click="showEditModal = false" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2.5 text-xs font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none font-sans">
                            Batal
                        </button>
                        <button @click="saveUser" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                            Simpan Pemetaan
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>

</x-layouts.app>
