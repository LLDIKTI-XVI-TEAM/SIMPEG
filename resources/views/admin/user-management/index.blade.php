<x-layouts.app title="User Management - Kelola Akses User">

    @php
        $mappingFormHasErrors = old('form') === 'user-mapping' && $errors->any();
        $mappingFormShouldReopen = old('form') === 'user-mapping' && ($errors->any() || session()->has('error'));
    @endphp

    <div x-data="{
        {{-- ── Data Table State ─────────────────────────────────────────── --}}
        rows: [],
        meta: { current_page: 1, last_page: 1, from: 0, to: 0, total: 0, per_page: 10 },
        isLoading: false,
        perPage: 10,
        filters: { search: '', role: '', status: '' },

        fetchPage(page) {
            if (page < 1 || (this.meta.last_page > 0 && page > this.meta.last_page)) return;
            this.isLoading = true;
            const params = new URLSearchParams({
                page,
                per_page: this.perPage,
                search: this.filters.search,
                role: this.filters.role,
                status: this.filters.status,
            });
            fetch(`{{ route('user-management.data') }}?` + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(json => {
                this.rows = json.data;
                this.meta = json.meta;
                this.perPage = json.meta.per_page;
            })
            .finally(() => { this.isLoading = false; });
        },

        setPerPage(val) {
            this.perPage = parseInt(val) || 10;
            this.fetchPage(1);
        },

        applyFilter() {
            this.fetchPage(1);
        },

        {{-- ── Modal State ───────────────────────────────────────────────── --}}
        showEditModal: @js($mappingFormShouldReopen),
        selectedEmployee: {
            id: @js(old('employee_id', '')),
            nama: '',
            email: '',
            keycloak_id: @js(old('keycloak_id', '')),
            role: @js(old('role', '')),
            nip: ''
        },
        lastFocusedElement: null,

        init() {
            this.fetchPage(1);
            if (this.showEditModal) {
                this.$nextTick(() => this.focusModalField());
            }
        },

        openEdit(emp, trigger) {
            this.lastFocusedElement = trigger instanceof HTMLElement ? trigger : document.activeElement;
            this.selectedEmployee = {
                id: emp.id,
                nama: emp.nama,
                email: emp.mapped_email,
                keycloak_id: emp.keycloak_id === '-' ? '' : (emp.keycloak_id || ''),
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

            if (focusableElements.length === 0) return;

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

        {{-- DATA TABLE (x-ui.data-table) -- Alpine.js/AJAX mode --}}
        @php
            $tableColumns = [
                ['key' => 'no',                  'label' => 'NO'],
                ['key' => 'nama',                 'label' => 'NAMA PEGAWAI'],
                ['key' => 'nip',                  'label' => 'NIP'],
                ['key' => 'mapped_email',          'label' => 'EMAIL PEGAWAI'],
                ['key' => 'keycloak_id',           'label' => 'KEYCLOAK ID'],
                ['key' => 'role_label',            'label' => 'ROLE'],
                ['key' => 'mapping_status_label',  'label' => 'STATUS SSO'],
                ['key' => 'aksi',                  'label' => 'AKSI', 'align' => 'right'],
            ];
        @endphp

        <x-ui.data-table
            rows="rows"
            meta="meta"
            :columns="$tableColumns"
            fetchPage="fetchPage(page)"
            isLoading="isLoading"
            perPage="perPage"
            setPerPage="setPerPage($event.target.value)"
            searchModel="filters.search"
            searchPlaceholder="Cari nama, NIP, atau email pegawai..."
            emptyTitle="Tidak ada pegawai yang sesuai filter"
            emptyIcon="search"
            :colspanCount="count($tableColumns)"
            filterClass="lg:grid-cols-12"
        >
            {{-- ── Filter Slots ─────────────────────────────────────── --}}
            <x-slot:filters>
                {{-- Filter Role --}}
                <div class="col-span-1 lg:col-span-3">
                    <label for="user-mapping-role-filter" class="sr-only">Filter role internal SIMPEG</label>
                    <select
                        id="user-mapping-role-filter"
                        x-model="filters.role"
                        @change="applyFilter()"
                        class="h-[44px] w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans shadow-sm"
                    >
                        <option value="">Semua Role</option>
                        <option value="super_admin">Super Admin</option>
                        <option value="admin_kepegawaian">Admin Kepegawaian</option>
                        <option value="pimpinan">Pimpinan</option>
                        <option value="kepala_bagian">Kepala Bagian</option>
                        <option value="pegawai">Pegawai</option>
                    </select>
                </div>

                {{-- Filter Status Mapping --}}
                <div class="col-span-1 lg:col-span-3">
                    <label for="user-mapping-status-filter" class="sr-only">Filter status mapping</label>
                    <select
                        id="user-mapping-status-filter"
                        x-model="filters.status"
                        @change="applyFilter()"
                        class="h-[44px] w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans shadow-sm"
                    >
                        <option value="">Semua Status Mapping</option>
                        <option value="terhubung">Terhubung</option>
                        <option value="belum_ada_user">Belum Ada User Lokal</option>
                        <option value="identifier_kosong">Identifier Keycloak Kosong</option>
                        <option value="role_kosong">Role Belum Ditetapkan</option>
                    </select>
                </div>

                {{-- Reset Filter --}}
                <div class="col-span-1 lg:col-span-2 flex items-center">
                    <button
                        type="button"
                        @click="filters.search=''; filters.role=''; filters.status=''; applyFilter()"
                        x-show="filters.search || filters.role || filters.status"
                        x-cloak
                        class="text-xs font-semibold text-primary hover:underline"
                    >Reset filter</button>
                </div>
            </x-slot:filters>

            {{-- ── Custom Body Rows ─────────────────────────────────── --}}
            <template x-if="!isLoading && rows.length > 0">
                <template x-for="emp in rows" :key="emp.id">
                    <x-ui.table-row :interactive="true">
                        {{-- NO --}}
                        <x-ui.table-td class="text-muted whitespace-nowrap">
                            <span x-text="emp.no"></span>
                        </x-ui.table-td>

                        {{-- NAMA PEGAWAI --}}
                        <x-ui.table-td class="font-bold whitespace-nowrap">
                            <span x-text="emp.nama"></span>
                        </x-ui.table-td>

                        {{-- NIP --}}
                        <x-ui.table-td class="text-muted whitespace-nowrap">
                            <span x-text="emp.nip"></span>
                        </x-ui.table-td>

                        {{-- EMAIL --}}
                        <x-ui.table-td class="whitespace-nowrap">
                            <span x-text="emp.mapped_email"></span>
                        </x-ui.table-td>

                        {{-- KEYCLOAK ID --}}
                        <x-ui.table-td class="whitespace-nowrap">
                            <span x-text="emp.keycloak_id"></span>
                        </x-ui.table-td>

                        {{-- ROLE --}}
                        <x-ui.table-td class="whitespace-nowrap">
                            <span
                                x-text="emp.role_label"
                                :class="emp.role_class"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                            ></span>
                        </x-ui.table-td>

                        {{-- STATUS SSO --}}
                        <x-ui.table-td class="whitespace-nowrap">
                            <span
                                :class="emp.mapping_status_class"
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                            >
                                <span class="inline-block h-1.5 w-1.5 rounded-full" :class="emp.mapping_status_class.replace('text-', 'bg-').replace('/10', '')"></span>
                                <span x-text="emp.mapping_status_label"></span>
                            </span>
                        </x-ui.table-td>

                        {{-- AKSI --}}
                        <x-ui.table-td align="right" class="whitespace-nowrap">
                            <div class="flex items-center justify-end">
                                <x-ui.button
                                    type="button"
                                    variant="secondary"
                                    size="icon"
                                    ::title="'Edit pemetaan ' + emp.nama"
                                    ::aria-label="'Edit pemetaan ' + emp.nama"
                                    @click="openEdit(emp, $event.currentTarget)"
                                >
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                    </svg>
                                </x-ui.button>
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                </template>
            </template>
        </x-ui.data-table>

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
