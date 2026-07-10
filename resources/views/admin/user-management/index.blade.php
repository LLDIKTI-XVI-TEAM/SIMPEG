<x-layouts.app title="User Management - Kelola Akses User">

    <div x-data="{
        searchQuery: '',
        filterRole: '',
        filterStatus: '',
        currentPage: 1,
        perPage: 5,
        showEditModal: false,
        selectedEmployee: { nama: '', email: '', keycloak_id: '', role: '', nip: '' },
        
        employees: {{ json_encode($pegawai) }},

        init() {
            this.$watch('searchQuery', () => this.currentPage = 1);
            this.$watch('filterRole', () => this.currentPage = 1);
            this.$watch('filterStatus', () => this.currentPage = 1);
        },

        get filteredEmployees() {
            return this.employees.filter(e => {
                // Search match
                const nameMatch = e.nama.toLowerCase().includes(this.searchQuery.toLowerCase());
                const nipMatch = e.nip.toLowerCase().includes(this.searchQuery.toLowerCase());
                const emailMatch = (e.mapped_email || '').toLowerCase().includes(this.searchQuery.toLowerCase());
                const matchesSearch = nameMatch || nipMatch || emailMatch;

                // Role filter match
                const matchesRole = !this.filterRole || e.role === this.filterRole;

                // Status filter match
                const statusStr = e.is_connected ? 'connected' : 'disconnected';
                const matchesStatus = !this.filterStatus || statusStr === this.filterStatus;

                return matchesSearch && matchesRole && matchesStatus;
            });
        },

        get paginatedEmployees() {
            const start = (this.currentPage - 1) * this.perPage;
            const end = start + this.perPage;
            return this.filteredEmployees.slice(start, end);
        },

        get totalPages() {
            return Math.ceil(this.filteredEmployees.length / this.perPage) || 1;
        },

        openEdit(emp) {
            this.selectedEmployee = {
                nama: emp.nama,
                email: emp.mapped_email,
                keycloak_id: emp.keycloak_id || '',
                role: emp.role || 'pegawai',
                nip: emp.nip
            };
            this.showEditModal = true;
        }
    }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Kelola Akses User</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Kelola Akses User']
                ]" />
                <div class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <span>•</span>
                    <span class="text-muted italic">Akses: Khusus Super Admin</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button href="{{ route('audit-log') }}" variant="muted">
                    <svg class="w-4 h-4 mr-1.5 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                    Lihat Audit Log Akses
                </x-ui.button>
            </div>
        </div>

        {{-- INFO ARCHITECTURE CARD --}}
        <div class="rounded-lg border border-info/20 bg-info/5 p-4 text-xs text-info flex gap-3">
            <svg class="w-5 h-5 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
            </svg>
            <div>
                <span class="font-bold">Informasi Otorisasi:</span> Sistem menggunakan Keycloak SSO murni untuk autentikasi identitas login. Seluruh hak akses, role, dan permission dibaca serta dikonfigurasi melalui database internal SIMPEG (RBAC). Perubahan peran (role) akan berlaku saat pegawai melakukan login berikutnya.
            </div>
        </div>

        {{-- NOTIFICATIONS --}}
        @if(session('success'))
            <x-ui.alert variant="success" class="font-semibold">{{ session('success') }}</x-ui.alert>
        @endif

        @if(session('error'))
            <x-ui.alert variant="danger" class="font-semibold">{{ session('error') }}</x-ui.alert>
        @endif

        {{-- FILTER BAR --}}
        <x-ui.card padding="sm" class="flex flex-col gap-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12">
                {{-- Search Bar --}}
                <div class="col-span-1 sm:col-span-2 lg:col-span-6 relative">
                    <input
                        type="text"
                        x-model="searchQuery"
                        placeholder="Cari nama, NIP, atau email pegawai..."
                        class="w-full rounded-lg border border-border bg-surface pl-10 pr-4 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans"
                    >
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Role --}}
                <div class="col-span-1 lg:col-span-3 relative">
                    <select x-model="filterRole" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Role</option>
                        <option value="Super Admin">Super Admin</option>
                        <option value="Admin Kepegawaian">Admin Kepegawaian</option>
                        <option value="Pimpinan">Pimpinan</option>
                        <option value="Kepala Bagian">Kepala Bagian</option>
                        <option value="Pegawai">Pegawai</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Status Mapping --}}
                <div class="col-span-1 lg:col-span-3 relative">
                    <select x-model="filterStatus" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Status SSO</option>
                        <option value="connected">Terhubung</option>
                        <option value="disconnected">Belum Terhubung</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="px-6 py-4 border-b border-border bg-surface">
                <h3 class="text-sm font-semibold text-ink font-sans">Pemetaan Akun SSO & Otorisasi RBAC</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Hubungkan email Keycloak SSO dengan data pegawai internal serta kelola role.</p>
            </div>            <div class="overflow-x-auto">
                <x-ui.table class="border-collapse">
                    <x-ui.table-head class="border-b border-border">
                        <x-ui.table-row>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">NO</x-ui.table-th>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">NAMA PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">NIP</x-ui.table-th>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">EMAIL PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">KEYCLOAK ID</x-ui.table-th>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">ROLE</x-ui.table-th>
                            <x-ui.table-th padding="sm" class="whitespace-nowrap">STATUS SSO</x-ui.table-th>
                            <x-ui.table-th align="right" padding="sm" class="whitespace-nowrap">AKSI</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        <template x-for="(emp, index) in paginatedEmployees" :key="emp.nip">
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td x-text="(currentPage - 1) * perPage + index + 1" class="font-mono text-muted whitespace-nowrap"></x-ui.table-td>
                                <x-ui.table-td x-text="emp.nama" class="font-bold whitespace-nowrap"></x-ui.table-td>
                                <x-ui.table-td x-text="emp.nip" class="font-mono text-muted whitespace-nowrap"></x-ui.table-td>
                                <x-ui.table-td x-text="emp.mapped_email" class="font-mono whitespace-nowrap"></x-ui.table-td>
                                <x-ui.table-td : x-text="emp.keycloak_id || '-'" class="font-mono whitespace-nowrap"></x-ui.table-td>
                                <x-ui.table-td class="whitespace-nowrap">
                                     <x-ui.badge
                                           variant="none"
                                           size="xs"
                                           uppercase
                                           x-bind:class="{
                                               'bg-danger/10 text-danger': emp.role === 'super_admin',
                                               'bg-primary/10 text-primary': emp.role === 'admin_kepegawaian',
                                               'bg-soft text-muted': emp.role === 'pimpinan',
                                               'bg-warning/10 text-warning': emp.role === 'kepala_bagian',
                                               'bg-success/10 text-success': emp.role === 'pegawai'
                                           }"
                                           x-text="emp.role"
                                     ></x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td class="whitespace-nowrap">
                                    <x-ui.badge
                                          variant="none"
                                          size="xs"
                                          dot
                                          x-bind:class="emp.is_connected ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'">
                                        <span x-text="emp.is_connected ? 'Terhubung' : 'Belum Terhubung'"></span>
                                    </x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td align="right" class="whitespace-nowrap">
                                    <div class="flex items-center justify-end">
                                        <x-ui.button
                                            type="button"
                                            variant="secondary"
                                            size="icon"
                                            @click="openEdit(emp)"
                                            title="Edit Pemetaan"
                                            aria-label="Edit Pemetaan"
                                        >
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                            </svg>
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </template>

                        <x-ui.table-row x-show="filteredEmployees.length === 0">
                            <x-ui.table-td colspan="8" align="center" class="px-6 py-8 text-muted">
                                Tidak ada pegawai yang cocok dengan filter pencarian Anda.
                            </x-ui.table-td>
                        </x-ui.table-row>
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER / PAGINATION --}}
            <div class="flex flex-col gap-3 border-t border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">
                        Menampilkan <span x-text="filteredEmployees.length === 0 ? 0 : (currentPage - 1) * perPage + 1"></span> - <span x-text="Math.min(currentPage * perPage, filteredEmployees.length)"></span> dari <span x-text="filteredEmployees.length"></span> data
                    </p>
                    <div class="relative">
                        <select x-model.number="perPage" @change="currentPage = 1" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option value="5">5 / halaman</option>
                            <option value="10">10 / halaman</option>
                            <option value="25">25 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">

                    <x-ui.pagination current="currentPage" total="totalPages" />

                </div>
            </div>
        </x-ui.card>

        {{-- EDIT PEMETAAN MODAL --}}
        <div x-show="showEditModal" class="fixed inset-0 z-50 overflow-hidden" style="display: none;" x-transition>
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-ink/30 transition-opacity" @click="showEditModal = false"></div>
            
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <form action="{{ route('user-management.update') }}" method="POST" class="w-full max-w-md bg-surface border border-border rounded-lg shadow-xl flex flex-col overflow-hidden">
                    @csrf
                    
                    {{-- Header --}}
                    <div class="px-6 py-5 border-b border-border flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Edit Otorisasi & SSO</h3>
                            <p class="text-[11px] text-muted font-sans mt-0.5" x-text="selectedEmployee.nama"></p>
                        </div>
                        <button type="button" @click="showEditModal = false" class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none">
                            Tutup
                        </button>
                    </div>

                    {{-- Body --}}
                    <div class="p-6 space-y-4">
                        <input type="hidden" name="email" :value="selectedEmployee.email">

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Email Pegawai (Read-only)</label>
                            <input type="text" :value="selectedEmployee.email" disabled class="h-[44px] w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-xs text-muted font-mono font-sans select-none focus:outline-none">
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Keycloak ID / Email SSO</label>
                            <input type="text" name="keycloak_id" x-model="selectedEmployee.keycloak_id" placeholder="Masukkan ID / Email SSO Keycloak..." class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <p class="text-[10px] text-muted font-sans">Kosongkan jika ingin memutuskan (disconnect) akun SSO pegawai.</p>
                            <p class="text-[10px] text-danger font-semibold font-sans mt-1">⚠️ Aturan Unik: Satu Keycloak ID hanya boleh dipetakan ke satu pegawai saja.</p>
                        </div>
 
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-ink font-sans">Role Internal SIMPEG</label>
                            <select name="role" x-model="selectedEmployee.role" class="h-[44px] w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                <option value="Super Admin">Super Admin</option>
                                <option value="Admin Kepegawaian">Admin Kepegawaian</option>
                                <option value="Pimpinan">Pimpinan</option>
                                <option value="Kepala Bagian">Kepala Bagian</option>
                                <option value="Pegawai">Pegawai</option>
                            </select>
                            <p class="text-[10px] text-muted font-sans">Pilih tingkat otorisasi internal untuk di-assign ke user ini.</p>
                            <p class="text-[10px] text-warning font-semibold font-sans mt-1">⚠️ Catatan: Perubahan role baru akan aktif setelah user melakukan login berikutnya.</p>
                        </div>

                        {{-- WARNING SENSITIVE ROLE --}}
                        <div x-show="selectedEmployee.role === 'super_admin'" class="rounded-lg border border-danger/20 bg-danger/5 p-3 text-xs text-danger flex gap-2" style="display: none;">
                            <svg class="w-4 h-4 shrink-0 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            <div>
                                <span class="font-bold">⚠️ PERINGATAN AKSES:</span> Anda memilih peran <strong>Super Admin</strong>. Peran ini memiliki tingkat otorisasi tertinggi dengan hak penuh atas sistem. Pastikan wewenang ini sah.
                            </div>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 border-t border-border bg-soft flex justify-end gap-3">
                        <x-ui.button type="button" variant="secondary" size="xs" @click="showEditModal = false">
                            Batal
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="xs">
                            Simpan Pemetaan
                        </x-ui.button>
                    </div>

                </form>
            </div>
        </div>

    </div>

</x-layouts.app>
