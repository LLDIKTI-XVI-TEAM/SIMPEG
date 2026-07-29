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
            'role' => 'kepala_bagian',
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
    }" @confirm-sso.window="executeSaveUser()"
       @confirm-instansi.window="$refs.formUmum.submit()" class="space-y-6">


        <x-admin.page-header title="Pengaturan Sistem" class="border-b border-border pb-4">
            <x-slot:breadcrumb>
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink font-sans">Pengaturan</span>
                    <span>•</span>
                    <span class="text-muted italic">Akses: Khusus Super Admin</span>
            </x-slot:breadcrumb>
            <x-slot:actions>

                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-info">
                    🔒 Audit Trail Aktif
                </span>
            </x-slot:actions>
        </x-admin.page-header>

        {{-- NOTIFICATIONS --}}
        @if(session('success'))
            <x-ui.alert variant="success" class="font-semibold shadow-sm">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            
            {{-- Tab Sidebar Kiri --}}
            <aside class="w-full lg:w-64 shrink-0">
                <x-ui.card padding="sm" class="space-y-1">
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wide px-3 pb-2 border-b border-border mb-2 font-sans">Kategori Pengaturan</p>
                    
                    <x-ui.tabs variant="sidebar" label="Kategori pengaturan sistem">
                        <x-ui.tab variant="sidebar" active="activeTab === 'umum'" click="activeTab = 'umum'">
                            <span>Umum & Instansi</span>
                        </x-ui.tab>

                        <x-ui.tab variant="sidebar" active="activeTab === 'rbac'" click="activeTab = 'rbac'">
                            <span>Pemetaan SSO & RBAC</span>
                        </x-ui.tab>

                        <x-ui.tab variant="sidebar" active="activeTab === 'master'" click="activeTab = 'master'">
                            <span>Kamus Data Master</span>
                        </x-ui.tab>
                    </x-ui.tabs>

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
                </x-ui.card>
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

                        <div class="border-t border-border pt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <p class="text-[10px] text-muted italic font-sans flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                                </svg>
                                🔒 Perubahan ini akan dicatat ke dalam Audit Log sistem secara real-time.
                            </p>
                            <x-ui.button type="submit" variant="primary" size="lg">
                                Simpan Perubahan
                            </x-ui.button>
                        </div>
                    </form>
                </div>

                {{-- TAB: PEMETAAN SSO & RBAC --}}
                <x-ui.card padding="lg" x-show="activeTab === 'rbac'"   style="display: none;" class="space-y-6">
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
                        <x-ui.table>
                            <x-ui.table-head variant="muted">
                                <x-ui.table-row>
                                    <x-ui.table-th>Nama Pegawai</x-ui.table-th>
                                    <x-ui.table-th>NIP</x-ui.table-th>
                                    <x-ui.table-th>Keycloak ID / Email SSO</x-ui.table-th>
                                    <x-ui.table-th>Role Internal</x-ui.table-th>
                                    <x-ui.table-th>Status</x-ui.table-th>
                                    <x-ui.table-th>Aksi</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                <template x-for="user in filteredUsers" :key="user.id">
                                    <x-ui.table-row :interactive="true">
                                        <x-ui.table-td x-text="user.name" padding="sm" class="text-sm font-semibold"></x-ui.table-td>
                                        <x-ui.table-td x-text="user.nip" padding="sm" class="text-muted"></x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <div class="font-semibold" x-text="user.keycloak_id || '-'"></div>
                                            <div class="text-[10px] text-muted mt-0.5" x-text="user.email"></div>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <x-ui.badge
                                                variant="none"
                                                size="xs"
                                                uppercase
                                                x-bind:class="{
                                                    'bg-danger/10 text-danger': user.role === 'super_admin',
                                                    'bg-primary/10 text-primary': user.role === 'admin_kepegawaian',
                                                    'bg-info/10 text-info': user.role === 'pimpinan',
                                                    'bg-warning/10 text-warning': user.role === 'kepala_bagian',
                                                    'bg-success/10 text-success': user.role === 'pegawai'
                                                }"
                                                x-text="user.role"
                                            ></x-ui.badge>
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
                        <h2 class="text-lg font-bold text-primary font-sans leading-tight">Data Master Kamus Referensi</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Kelola record data referensi sistem (static seeder tables).</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-for="master in dataMaster" :key="master.tabel">
                            <div class="border border-border rounded-lg p-4 bg-soft/40 flex flex-col justify-between shadow-sm">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-primary" x-text="master.tabel"></span>
                                        <span class="rounded bg-soft px-2 py-0.5 text-[9px] text-muted" x-text="master.sprint"></span>
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
                </x-ui.card>

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
                                <option value="Kepala Bagian">Kepala Bagian</option>
                                <option value="Pegawai">Pegawai</option>
                            </select>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 border-t border-border bg-soft flex justify-end gap-3">
                        <x-ui.button type="button" variant="secondary" size="xs" @click="showEditModal = false">
                            Batal
                        </x-ui.button>
                        <x-ui.button type="button" variant="primary" size="xs" @click="saveUser">
                            Simpan Pemetaan
                        </x-ui.button>
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

    </div>

</x-layouts.app>
