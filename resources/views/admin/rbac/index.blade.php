<x-layouts.app title="Role & Permission - Kelola Otorisasi Fitur">

@php
$permissionPaths = [
    'manage_reference_tables' => 'Data Master',
    'configure_ews'           => 'Konfigurasi EWS',
    'manage_holidays'         => 'Hari Libur',
    'manage_user_mapping'     => 'User Management',
    'manage_rbac'             => 'Role & Permission',
    'view_audit_log'          => 'Audit Log',
    'view_all_pegawai'        => 'Data Pegawai (Lihat)',
    'manage_pegawai'          => 'Data Pegawai (Kelola)',
    'manage_riwayat'          => 'Data Pegawai (Riwayat)',
    'employee_histories.read' => 'Riwayat Pegawai (Pangkat, Jabatan, KGB, Pendidikan, Pengangkatan, Status)',
    'import_pegawai'          => 'Import Pegawai',
    'manage_supervisor'       => 'Data Pegawai (Supervisor)',
    'manage_documents'        => 'Dokumen & SK',
    'apply_cuti'              => 'Pengajuan Cuti',
    'view_all_cuti'           => 'Rekap Cuti',
    'approve_cuti_stage1'     => 'Approval Cuti (Stage 1)',
    'approve_cuti_stage3'     => 'Approval Cuti (Stage 3)',
    'view_all_ews'            => 'EWS Aktif',
    'generate_reports'        => 'Laporan (Export)',
    // Permission baru — dikontrol dari halaman ini
    'dokumen_sk.read'         => 'Dokumen & SK (Lihat)',
    'ews.read'                => 'EWS Aktif (Lihat)',
    'ews.configure'           => 'Konfigurasi EWS',
    'employee_histories.export' => 'Laporan Riwayat Kepangkatan',
];

$permissionGroupsForFilter = $permissionsByModule->map(function ($permissions, $moduleName) use ($permissionPaths) {
    $search = $permissions->map(function ($permission) use ($permissionPaths) {
        return implode(' ', [
            $permission->name,
            $permissionPaths[$permission->name] ?? $permission->name,
            $permission->description ?? '',
        ]);
    })->implode(' ');

    return [
        'module' => $moduleName,
        'search' => $search,
    ];
})->values()->all();
@endphp

    <div x-data="{
        searchQuery: '',
        moduleFilter: '',
        showConfirmModal: false,
        originalData: {},
        currentData: {},
        isDirty: false,
        lockedPermissionIdsByRole: {{ json_encode($lockedPermissionIdsByRole) }},
        permissionGroups: {{ json_encode($permissionGroupsForFilter) }},

        init() {
            // Initialize permission mappings as string arrays for reliable checkbox binding
            const initial = {};
            @foreach($roles as $role)
                initial[{{ json_encode($role->id) }}] = {{ json_encode($role->permissions->pluck('id')->map(fn($id) => (string) $id)->toArray()) }};
            @endforeach
            
            this.originalData = JSON.parse(JSON.stringify(initial));
            this.currentData = JSON.parse(JSON.stringify(initial));
        },

        checkDirty() {
            let dirty = false;
            for (let roleId in this.originalData) {
                const orig = [...this.originalData[roleId]].sort().join(',');
                const curr = [...this.currentData[roleId]].sort().join(',');
                if (orig !== curr) {
                    dirty = true;
                    break;
                }
            }
            this.isDirty = dirty;
        },

        resetChanges() {
            this.currentData = JSON.parse(JSON.stringify(this.originalData));
            this.isDirty = false;
        },

        matchesPermission(value) {
            const query = this.searchQuery.trim().toLocaleLowerCase();

            return query === '' || String(value ?? '').toLocaleLowerCase().includes(query);
        },

        hasMatchingPermission() {
            return this.permissionGroups.some(({ module, search }) =>
                (this.moduleFilter === '' || this.moduleFilter === module)
                && this.matchesPermission(search)
            );
        }
    }" @confirm-rbac.window="$refs.rbacForm.submit()" class="space-y-6">

        {{-- PAGE HEADER & BREADCRUMBS --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Role & Permission / RBAC</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Role & Permission']
                ]" />
            </div>
        </div>

        {{-- NOTIFICATIONS --}}
        @if(session('success'))
            <x-ui.alert variant="success" class="font-semibold">{{ session('success') }}</x-ui.alert>
        @endif

        {{-- INFO ARCHITECTURE CARD --}}
        <div class="rounded-lg border border-info/20 bg-info/5 p-4 text-xs text-info flex gap-3">
            <svg class="w-5 h-5 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
            </svg>
            <div>
                <span class="font-bold">Informasi Otorisasi:</span> Sistem menggunakan Keycloak SSO murni untuk autentikasi identitas login. Seluruh hak akses, role, dan permission dibaca serta dikonfigurasi melalui database internal SIMPEG (RBAC). Perubahan peran (role) akan berlaku saat pegawai melakukan login berikutnya.
            </div>
        </div>

        {{-- SUMMARY ROLES CARDS --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($roles as $role)
                <x-ui.card padding="sm" class="flex flex-col justify-between">
                    <div>
                        @php
                            $roleVariant = match ($role->name) {
                                'super_admin' => 'danger',
                                'admin_kepegawaian' => 'primary',
                                'kepala_bagian' => 'warning',
                                'pegawai' => 'success',
                                default => 'muted',
                            };
                        @endphp
                        <x-ui.badge :variant="$roleVariant" size="xs" uppercase>
                            {{ $role->name }}
                        </x-ui.badge>
                        <p class="mt-2 text-xs font-sans text-muted line-clamp-2">
                            {{ $role->description }}
                        </p>
                    </div>
                    <div class="mt-4 border-t border-border/50 pt-2 flex items-baseline justify-between">
                        <span class="text-xs text-muted font-sans font-medium">Izin Aktif:</span>
                        <span class="text-base font-bold text-ink">{{ $role->permissions->count() }}</span>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- MATRIX CARD --}}
        <form x-ref="rbacForm" action="{{ route('rbac.update') }}" method="POST" class="relative">
            @csrf

            <x-ui.card padding="none" class="overflow-hidden">
                {{-- TABLE HEADER SEARCH --}}
                <div class="px-6 py-4 border-b border-border bg-surface flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-ink font-sans">Matriks Konfigurasi RBAC</h3>
                        <p class="text-xs text-muted">Tentukan daftar permission dan modul yang diizinkan untuk setiap level peran.</p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                        {{-- Dropdown Filter Modul --}}
                        <div class="relative w-full sm:w-48">
                            @php
                                $modules = array_keys($permissionsByModule->toArray());
                            @endphp
                            <x-form.select x-model="moduleFilter" class="h-10" aria-label="Filter modul">
                                <option value="">Semua Modul</option>
                                @foreach($modules as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </x-form.select>
                        </div>

                        {{-- Search Input --}}
                        <div class="relative w-full sm:w-64">
                            <label for="rbac-permission-search" class="sr-only">Cari izin atau deskripsi</label>
                            <input
                                id="rbac-permission-search"
                                type="search"
                                x-model="searchQuery"
                                placeholder="Cari izin / deskripsi..."
                                class="h-10 w-full rounded-xl border border-border bg-surface py-2 pl-10 pr-4 text-sm text-ink shadow-sm placeholder:text-muted transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans"
                            >
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TABLE CONTENT --}}
                <div class="overflow-x-auto">
                    <x-ui.table class="border-collapse">
                        <x-ui.table-head class="border-b border-border">
                            <x-ui.table-row>
                                <x-ui.table-th class="w-[5%]">NO</x-ui.table-th>
                                <x-ui.table-th class="w-[45%]">MODUL / IZIN FITUR</x-ui.table-th>
                                @foreach($roles as $role)
                                    <x-ui.table-th align="center" class="w-[10%]">
                                        {{ $role->name }}
                                    </x-ui.table-th>
                                @endforeach
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @php $globalIndex = 1; @endphp
                            @foreach($permissionsByModule as $moduleName => $perms)
                                @php
                                    $moduleNameJson = json_encode($moduleName);
                                    $moduleSearch = $perms->map(function ($permission) use ($permissionPaths) {
                                        return implode(' ', [
                                            $permission->name,
                                            $permissionPaths[$permission->name] ?? $permission->name,
                                            $permission->description ?? '',
                                        ]);
                                    })->implode(' ');
                                    $moduleSearchJson = json_encode($moduleSearch);
                                @endphp
                                {{-- Module Header Row --}}
                                <x-ui.table-row x-show="(moduleFilter === '' || moduleFilter === {{ $moduleNameJson }}) && matchesPermission({{ $moduleSearchJson }})"
                                    class="bg-soft/50 font-semibold">
                                    <x-ui.table-td colspan="2" class="border-b border-border px-4 py-2 font-bold uppercase tracking-wider">
                                        📁 &nbsp;{{ $moduleName }}
                                    </x-ui.table-td>
                                    @foreach($roles as $role)
                                        <x-ui.table-td colspan="1" class="border-b border-border px-4 py-2"></x-ui.table-td>
                                    @endforeach
                                </x-ui.table-row>

                                {{-- Permission Rows --}}
                                @foreach($perms as $permission)
                                    @php
                                        $displayPath = $permissionPaths[$permission->name] ?? $permission->name;
                                        $isSensitive = in_array($permission->name, ['manage_user_mapping', 'manage_rbac', 'view_audit_log', 'configure_ews']);
                                    @endphp
                                    <x-ui.table-row data-permission-search="{{ $permission->name }} {{ $displayPath }} {{ $permission->description }}" x-show="(moduleFilter === '' || moduleFilter === {{ $moduleNameJson }}) && matchesPermission($el.dataset.permissionSearch)"
                                        class="hover:bg-soft/30 transition-colors">
                                        <x-ui.table-td class="text-muted">{{ $globalIndex++ }}</x-ui.table-td>
                                        <x-ui.table-td>
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="text-xs font-bold text-primary">{{ $displayPath }}</span>
                                                @if($isSensitive)
                                                    <span class="inline-flex items-center text-xs font-bold uppercase tracking-wider text-danger leading-none">⚠️ High Risk / Sensitif</span>
                                                @endif
                                            </div>
                                            <div class="mt-0.5 text-xs leading-relaxed text-muted">
                                                @if($permission->name === 'generate_reports')
                                                    Mengunduh/export rekap data pegawai dan cuti dalam format PDF dan Excel.
                                                @else
                                                    {{ $permission->description }}
                                                @endif
                                            </div>
                                        </x-ui.table-td>
                                        @foreach($roles as $role)
                                            @php
                                                $isLockedForRole = in_array($permission->id, $lockedPermissionIdsByRole[$role->id] ?? [], true);
                                                $isAssigned = $role->permissions->contains('id', $permission->id);
                                            @endphp
                                            <x-ui.table-td align="center" class="align-middle hover:bg-soft/40 transition" title="{{ $isLockedForRole ? 'Permission ini tidak berlaku untuk role tersebut.' : '' }}">
                                                @if($role->name === 'super_admin')
                                                    {{-- Super Admin is always checked and disabled to prevent lockout --}}
                                                    <div class="flex items-center justify-center">
                                                        <x-form.checkbox
                                                            checked
                                                            disabled
                                                            class="text-primary/45 bg-soft focus:ring-0"
                                                        />
                                                        {{-- Standard hidden inputs for checked values to send back --}}
                                                        <input type="hidden" name="matrix[{{ $role->id }}][]" value="{{ $permission->id }}">
                                                    </div>
                                                @elseif($isLockedForRole)
                                                    {{-- Kebijakan juga dipaksa ulang di backend saat matriks disimpan. --}}
                                                    <div class="flex items-center justify-center" title="Tidak dapat diberikan ke role ini">
                                                        <x-form.checkbox
                                                            :checked="$isAssigned"
                                                            disabled
                                                            class="text-muted/40 bg-soft focus:ring-0"
                                                        />
                                                    </div>
                                                @else
                                                    <div class="flex items-center justify-center">
                                                        <x-form.checkbox
                                                            name="matrix[{{ $role->id }}][]"
                                                            value="{{ $permission->id }}"
                                                            x-model="currentData[{{ json_encode($role->id) }}]"
                                                            @change="checkDirty()"
                                                            class="transition"
                                                        />
                                                    </div>
                                                @endif
                                            </x-ui.table-td>
                                        @endforeach
                                    </x-ui.table-row>
                                @endforeach
                            @endforeach
                            <x-ui.table-row x-show="!hasMatchingPermission()" style="display: none;">
                                <x-ui.table-td colspan="{{ 2 + $roles->count() }}" align="center" class="px-6 py-10 text-sm text-muted">
                                    Tidak ada izin yang sesuai dengan filter.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>

            {{-- STICKY SAVE BAR --}}
            <div
                x-show="isDirty"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-10"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-10"
                class="fixed bottom-6 left-6 right-6 lg:left-[280px] z-40 bg-ink text-white rounded-xl shadow-2xl px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border border-white/10"
                style="display: none;"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/20 text-secondary border border-secondary/20 shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold">Terdapat perubahan belum disimpan!</p>
                        <p class="text-xs text-white/60 font-sans">Simpan perubahan matriks hak akses atau klik Batal untuk membatalkan perubahan.</p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 shrink-0">
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="resetChanges()"
                        class="text-white/80 hover:bg-white/10 hover:text-white"
                    >
                        Batal
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        @click="$dispatch('open-confirm-rbac')"
                        size="sm"
                    >
                        Simpan Perubahan
                    </x-ui.button>
                </div>
            </div>

            {{-- CONFIRMATION MODAL --}}

            <x-ui.confirm-dialog
                id="rbac"
                title="Konfirmasi Perubahan Otorisasi"
                message="Harap tinjau kembali perubahan hak akses sebelum menyimpan."
                confirm-text="Ya, Simpan Perubahan"
                variant="primary"
            >
                <div class="space-y-4">
                    <div class="rounded-lg border border-warning/20 bg-warning/5 p-4 text-xs text-warning flex gap-3">
                        <svg class="w-5 h-5 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <div>
                            <span class="font-bold">⚠️ TINDAKAN SENSITIF:</span> Mengubah matriks RBAC (Role-Based Access Control) akan berdampak secara real-time dan langsung mempengaruhi hak akses seluruh pengguna aktif di sistem SIMPEG.
                        </div>
                    </div>
                    <p class="text-xs text-ink/80 leading-relaxed font-sans">
                        Perubahan pada hak akses modul sensitif (seperti <strong>User Management</strong>, <strong>Role & Permission</strong>, <strong>Audit Log</strong>, atau <strong>Konfigurasi EWS</strong>) berisiko tinggi. Pastikan wewenang yang diberikan telah sesuai dengan instruksi kedinasan.
                    </p>
                </div>
            </x-ui.confirm-dialog>


        </form>

    </div>

</x-layouts.app>
