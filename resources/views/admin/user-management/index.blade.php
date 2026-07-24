<x-layouts.app title="User Management - Kelola Akses User">

    @php
        $mappingFormHasErrors = old('form') === 'user-mapping' && $errors->any();
        $mappingFormShouldReopen = old('form') === 'user-mapping' && ($errors->any() || session()->has('error'));
    @endphp

    <div x-data="{
        showEditModal: @js($mappingFormShouldReopen),
        selectedEmployee: {
            id: @js(old('employee_id', '')),
            nama: '',
            email: '',
            keycloak_id: @js(old('keycloak_id', '')),
            role: @js(old('role', '')),
            nip: ''
        },
        employees: @js($pegawai->items()),
        lastFocusedElement: null,

        init() {
            if (this.showEditModal) {
                this.restoreFailedMappingForm();
                this.$nextTick(() => this.focusModalField());
            }
        },

        restoreFailedMappingForm() {
            const employee = this.employees.find((candidate) => candidate.id === this.selectedEmployee.id);

            if (!employee) {
                return;
            }

            this.selectedEmployee = {
                id: employee.id,
                nama: employee.nama,
                email: employee.mapped_email,
                keycloak_id: this.selectedEmployee.keycloak_id,
                role: this.selectedEmployee.role,
                nip: employee.nip
            };
        },

        openEdit(emp, trigger) {
            this.lastFocusedElement = trigger instanceof HTMLElement ? trigger : document.activeElement;
            this.selectedEmployee = {
                id: emp.id,
                nama: emp.nama,
                email: emp.mapped_email,
                keycloak_id: emp.keycloak_id || '',
                role: emp.role || '',
                nip: emp.nip
            };
            this.showEditModal = true;
            this.$nextTick(() => this.focusModalField());
        },

        closeEdit() {
            this.showEditModal = false;
            this.$nextTick(() => {
                if (this.lastFocusedElement instanceof HTMLElement) {
                    this.lastFocusedElement.focus();
                }
            });
        },

        focusModalField() {
            if (this.$refs.roleSelect?.getAttribute('aria-invalid') === 'true') {
                this.$refs.roleSelect.focus();

                return;
            }

            this.$refs.keycloakIdentifier?.focus();
        },

        trapFocus(event, container) {
            const focusableElements = [...container.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]'
            )].filter((element) => element.offsetParent !== null && element.getAttribute('tabindex') !== '-1');

            if (focusableElements.length === 0) {
                return;
            }

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            if (event.shiftKey && document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();

                return;
            }

            if (!event.shiftKey && document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
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
            <form id="user-mapping-filter-form" action="{{ route('user-management') }}" method="GET" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12">
                {{-- Search Bar --}}
                <div class="col-span-1 sm:col-span-2 lg:col-span-5 relative">
                    <label for="user-mapping-search" class="sr-only">Cari nama, NIP, atau email pegawai</label>
                    <input
                        id="user-mapping-search"
                        type="text"
                        name="search"
                        value="{{ request('search') }}"
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
                    <label for="user-mapping-role-filter" class="sr-only">Filter role internal SIMPEG</label>
                    <select id="user-mapping-role-filter" name="role" onchange="this.form.requestSubmit()" class="h-[44px] w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Role</option>
                        <option value="super_admin" @selected(request('role') === 'super_admin')>Super Admin</option>
                        <option value="admin_kepegawaian" @selected(request('role') === 'admin_kepegawaian')>Admin Kepegawaian</option>
                        <option value="pimpinan" @selected(request('role') === 'pimpinan')>Pimpinan</option>
                        <option value="kepala_bagian" @selected(request('role') === 'kepala_bagian')>Kepala Bagian</option>
                        <option value="pegawai" @selected(request('role') === 'pegawai')>Pegawai</option>
                    </select>
                </div>

                {{-- Filter Status Mapping --}}
                <div class="col-span-1 lg:col-span-3 relative">
                    <label for="user-mapping-status-filter" class="sr-only">Filter status mapping</label>
                    <select id="user-mapping-status-filter" name="status" onchange="this.form.requestSubmit()" class="h-[44px] w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Status Mapping</option>
                        <option value="terhubung" @selected(request('status') === 'terhubung')>Terhubung</option>
                        <option value="belum_ada_user" @selected(request('status') === 'belum_ada_user')>Belum Ada User Lokal</option>
                        <option value="identifier_kosong" @selected(request('status') === 'identifier_kosong')>Identifier Keycloak Kosong</option>
                        <option value="role_kosong" @selected(request('status') === 'role_kosong')>Role Belum Ditetapkan</option>
                    </select>
                </div>
                <div class="col-span-1 flex gap-2 lg:col-span-1">
                    <button type="submit" class="h-[44px] w-full rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/20">Terapkan</button>
                </div>
                @if(request()->hasAny(['search', 'role', 'status', 'per_page']))
                    <div class="col-span-1 sm:col-span-2 lg:col-span-12">
                        <a href="{{ route('user-management') }}" class="text-xs font-semibold text-primary hover:underline">Reset filter</a>
                    </div>
                @endif
            </form>
        </x-ui.card>

        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="px-6 py-4 border-b border-border bg-surface">
                <h3 class="text-sm font-semibold text-ink font-sans">Pemetaan Akun SSO & Otorisasi RBAC</h3>
                <p class="text-xs text-muted">Hubungkan identifier Keycloak SSO dengan data pegawai internal serta kelola role.</p>
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
                        @forelse ($pegawai as $emp)
                            @php
                                $roleLabel = match ($emp['role']) {
                                    null, '' => 'Belum diberi role',
                                    'super_admin' => 'Super Admin',
                                    'admin_kepegawaian' => 'Admin Kepegawaian',
                                    'pimpinan' => 'Pimpinan',
                                    'kepala_bagian' => 'Kepala Bagian',
                                    'pegawai' => 'Pegawai',
                                    default => 'Role tidak dikenal',
                                };
                                $roleClass = match ($emp['role']) {
                                    'super_admin' => 'bg-danger/10 text-danger',
                                    'admin_kepegawaian' => 'bg-primary/10 text-primary',
                                    'kepala_bagian' => 'bg-warning/10 text-warning',
                                    'pegawai' => 'bg-success/10 text-success',
                                    default => 'bg-soft text-muted',
                                };
                                $statusClass = match ($emp['mapping_status']) {
                                    'terhubung' => 'bg-success/10 text-success',
                                    'role_kosong' => 'bg-warning/10 text-warning',
                                    default => 'bg-danger/10 text-danger',
                                };
                            @endphp
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td class="text-muted whitespace-nowrap">{{ ($pegawai->firstItem() ?? 0) + $loop->index }}</x-ui.table-td>
                                <x-ui.table-td class="font-bold whitespace-nowrap">{{ $emp['nama'] }}</x-ui.table-td>
                                <x-ui.table-td class="text-muted whitespace-nowrap">{{ $emp['nip'] }}</x-ui.table-td>
                                <x-ui.table-td class="whitespace-nowrap">{{ $emp['mapped_email'] ?: '-' }}</x-ui.table-td>
                                <x-ui.table-td class="whitespace-nowrap">{{ $emp['keycloak_id'] ?: '-' }}</x-ui.table-td>
                                <x-ui.table-td class="whitespace-nowrap">
                                     <x-ui.badge variant="none" size="xs" uppercase class="{{ $roleClass }}">{{ $roleLabel }}</x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td class="whitespace-nowrap">
                                     <x-ui.badge variant="none" size="xs" dot class="{{ $statusClass }}">{{ $emp['mapping_status_label'] }}</x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td align="right" class="whitespace-nowrap">
                                    <div class="flex items-center justify-end">
                                        <x-ui.button
                                            type="button"
                                            variant="secondary"
                                            size="icon"
                                            @click="openEdit(employees.find((employee) => employee.id === '{{ $emp['id'] }}'), $event.currentTarget)"
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
                        @empty
                        <x-ui.table-row>
                            <x-ui.table-td colspan="8" align="center" class="px-6 py-8 text-muted">
                                Tidak ada pegawai yang cocok dengan filter pencarian Anda.
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER / PAGINATION --}}
            <div class="flex flex-col gap-3 border-t border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">
                        Menampilkan {{ $pegawai->firstItem() ?? 0 }} - {{ $pegawai->lastItem() ?? 0 }} dari {{ $pegawai->total() }} data
                    </p>
                    <form action="{{ route('user-management') }}" method="GET" class="relative">
                        <input type="hidden" name="search" value="{{ request('search') }}">
                        <input type="hidden" name="role" value="{{ request('role') }}">
                        <input type="hidden" name="status" value="{{ request('status') }}">
                        <label for="user-mapping-per-page" class="sr-only">Jumlah data per halaman</label>
                        <select id="user-mapping-per-page" name="per_page" onchange="this.form.submit()" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option value="5" @selected((int) request('per_page', 10) === 5)>5 / halaman</option>
                            <option value="10" @selected((int) request('per_page', 10) === 10)>10 / halaman</option>
                            <option value="25" @selected((int) request('per_page', 10) === 25)>25 / halaman</option>
                            <option value="50" @selected((int) request('per_page', 10) === 50)>50 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </form>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $pegawai->onEachSide(1)->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>

        {{-- EDIT PEMETAAN MODAL --}}
        <div
            x-show="showEditModal"
            @keydown.escape.window="if (showEditModal) closeEdit()"
            class="fixed inset-0 z-50 overflow-hidden"
            style="display: none;"
            x-transition
        >
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-ink/30 transition-opacity" @click="closeEdit()"></div>
            
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <form action="{{ route('user-management.update') }}" method="POST" class="w-full max-w-md bg-surface border border-border rounded-lg shadow-xl flex flex-col overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="user-mapping-modal-title" tabindex="-1" @keydown.tab="trapFocus($event, $el)">
                    @csrf
                    <input type="hidden" name="form" value="user-mapping">
                    
                    {{-- Header --}}
                    <div class="px-6 py-5 border-b border-border flex items-center justify-between">
                        <div>
                            <h3 id="user-mapping-modal-title" class="text-sm font-bold text-ink font-sans">Edit Otorisasi & SSO</h3>
                            <p class="text-[11px] text-muted font-sans mt-0.5" x-text="selectedEmployee.nama"></p>
                        </div>
                        <button type="button" @click="closeEdit()" class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none" aria-label="Tutup modal pemetaan user">
                            Tutup
                        </button>
                    </div>

                    {{-- Body --}}
                    <div class="p-6 space-y-4">
                        @if($mappingFormHasErrors)
                            <x-ui.alert variant="danger" title="Periksa kembali data pemetaan">
                                Perbaiki field yang ditandai sebelum menyimpan kembali.
                            </x-ui.alert>
                        @endif

                        @error('mapping')
                            <x-ui.alert variant="danger" title="Pemetaan dibatalkan">
                                {{ $message }}
                            </x-ui.alert>
                        @enderror

                        <input type="hidden" name="employee_id" :value="selectedEmployee.id">

                        <div class="space-y-1">
                            <label for="user-mapping-employee" class="text-xs font-semibold text-ink font-sans">Pegawai Terpilih (Read-only)</label>
                            <input
                                id="user-mapping-employee"
                                type="text"
                                :value="selectedEmployee.email"
                                disabled
                                @if ($errors->has('employee_id')) aria-invalid="true" aria-describedby="user-mapping-employee-error" @endif
                                class="h-[44px] w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-xs text-muted font-sans select-none focus:outline-none"
                            >
                            @error('employee_id')
                                <p id="user-mapping-employee-error" class="text-[11px] text-danger font-semibold font-sans" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-1">
                            <label for="user-mapping-keycloak-id" class="text-xs font-semibold text-ink font-sans">Identifier Keycloak</label>
                            <input
                                id="user-mapping-keycloak-id"
                                type="text"
                                name="keycloak_id"
                                x-model="selectedEmployee.keycloak_id"
                                x-ref="keycloakIdentifier"
                                required
                                placeholder="Masukkan identifier Keycloak..."
                                aria-describedby="{{ $errors->has('keycloak_id') ? 'user-mapping-keycloak-id-help user-mapping-keycloak-id-error' : 'user-mapping-keycloak-id-help' }}"
                                @if ($errors->has('keycloak_id')) aria-invalid="true" @endif
                                class="h-[44px] w-full rounded-lg border {{ $errors->has('keycloak_id') ? 'border-danger focus:border-danger focus:ring-danger/20' : 'border-border focus:border-primary focus:ring-primary/20' }} bg-surface px-4 py-2.5 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 font-sans"
                            >
                            <p id="user-mapping-keycloak-id-help" class="text-[10px] text-muted font-sans">Identifier Keycloak wajib diisi. Disconnect belum tersedia pada halaman ini.</p>
                            @error('keycloak_id')
                                <p id="user-mapping-keycloak-id-error" class="text-[11px] text-danger font-semibold font-sans" role="alert">{{ $message }}</p>
                            @enderror
                            <p class="text-[10px] text-danger font-semibold font-sans mt-1">⚠️ Aturan Unik: Satu Keycloak ID hanya boleh dipetakan ke satu pegawai saja.</p>
                        </div>
 
                        <div>
                            <x-form.select
                                id="user-mapping-role"
                                name="role"
                                label="Role Internal SIMPEG"
                                help="Pilih tingkat otorisasi internal yang akan diberikan kepada user ini."
                                required
                                x-model="selectedEmployee.role"
                                x-ref="roleSelect"
                            >
                                <option value="" disabled>Pilih role internal</option>
                                <option value="super_admin">Super Admin</option>
                                <option value="admin_kepegawaian">Admin Kepegawaian</option>
                                <option value="pimpinan">Pimpinan</option>
                                <option value="kepala_bagian">Kepala Bagian</option>
                                <option value="pegawai">Pegawai</option>
                            </x-form.select>
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
                        <x-ui.button type="button" variant="secondary" size="xs" @click="closeEdit()">
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
