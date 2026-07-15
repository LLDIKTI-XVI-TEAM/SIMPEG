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
    'import_pegawai'          => 'Import Pegawai',
    'manage_supervisor'       => 'Data Pegawai (Supervisor)',
    'manage_documents'        => 'Dokumen & SK',
    'apply_cuti'              => 'Pengajuan Cuti',
    'view_all_cuti'           => 'Rekap Cuti',
    'approve_cuti_stage1'     => 'Approval Cuti (Stage 1)',
    'approve_cuti_stage3'     => 'Approval Cuti (Stage 3)',
    'view_all_ews'            => 'EWS Aktif',
    'generate_reports'        => 'Laporan (Export)',
];
@endphp

    <div x-data="{
        searchQuery: '',
        moduleFilter: '',
        showConfirmModal: false,
        originalData: {},
        currentData: {},
        isDirty: false,

        init() {
            // Initialize permission mappings as string arrays for reliable checkbox binding
            const initial = {};
            @foreach($roles as $role)
                initial[{{ $role->id }}] = {{ json_encode($role->permissions->pluck('id')->map(fn($id) => (string)$id)->toArray()) }};
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
                <div class="mt-1 flex items-center text-xs text-muted">
                    <span>•</span>
                    <span class="ml-1 text-muted italic">Akses: Khusus Super Admin</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button href="{{ route('audit-log') }}" variant="muted">
                    <svg class="w-4 h-4 mr-1.5 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                    Lihat Audit Log Otorisasi
                </x-ui.button>
            </div>
        </div>

        {{-- NOTIFICATIONS --}}
        @if(session('success'))
            <x-ui.alert variant="success" class="font-semibold">{{ session('success') }}</x-ui.alert>
        @endif

        {{-- SYNC & CONCEPT EXPLANATION CARD --}}
        <div class="rounded-lg border-l-4 border-primary border-y border-r border-border bg-primary/5 p-4 shadow-sm">
            <div class="flex items-start gap-3">
                <div class="text-primary shrink-0 mt-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-primary font-sans">Otorisasi & Keamanan Internal</h3>
                    <div class="text-xs text-ink/80 font-sans mt-1 leading-relaxed space-y-1">
                        <p>Keycloak hanya berperan sebagai gerbang autentikasi login (SSO). Penentuan menu, hak akses halaman, dan aksi fitur diatur sepenuhnya di database internal SIMPEG melalui matriks RBAC di bawah ini.</p>
                        <p class="font-semibold text-primary">Aturan Efektivitas Perubahan:</p>
                        <ul class="list-disc pl-4 space-y-0.5">
                            <li><strong>Perubahan Mapping Peran (Role Pegawai):</strong> Baru aktif setelah pegawai bersangkutan melakukan <strong>login berikutnya</strong>.</li>
                            <li><strong>Perubahan Hak Akses Peran (Permission Matrix):</strong> Berlaku secara <strong>langsung (real-time)</strong> untuk semua pengguna aktif yang sedang memegang peran tersebut.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        {{-- SUMMARY ROLES CARDS --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($roles as $role)
                <x-ui.card padding="sm" class="flex flex-col justify-between hover:border-primary/25 transition-all duration-300">
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
                        <x-ui.tooltip text="{{ $role->description }}">
                            <p class="text-[10px] text-muted mt-2 font-sans line-clamp-2">
                                {{ $role->description }}
                            </p>
                        </x-ui.tooltip>
                    </div>
                    <div class="mt-4 border-t border-border/50 pt-2 flex items-baseline justify-between">
                        <span class="text-[10px] text-muted font-sans font-medium">Izin Aktif:</span>
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
                            <x-form.select x-model="moduleFilter">
                                <option value="">Semua Modul</option>
                                @foreach($modules as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </x-form.select>
                        </div>

                        {{-- Search Input --}}
                        <div class="relative w-full sm:w-64">
                            <input
                                type="text"
                                x-model="searchQuery"
                                placeholder="Cari izin / deskripsi..."
                                class="w-full rounded-lg border border-border bg-surface pl-9 pr-4 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans"
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
                                    $permsJson = json_encode($perms->map(function($p) use ($permissionPaths) {
                                        return [
                                            'name' => $p->name,
                                            'display' => $permissionPaths[$p->name] ?? $p->name,
                                            'description' => $p->description
                                        ];
                                    })->toArray());
                                @endphp
                                <x-ui.table-body x-show="moduleFilter === '' || moduleFilter === '{{ $moduleName }}'" x-data="{ perms: {{ $permsJson }} }" class="border-b border-border">
                                    {{-- Module Header Row --}}
                                    <x-ui.table-row x-show="(moduleFilter === '' || moduleFilter === '{{ $moduleName }}') && (searchQuery === '' || perms.some(p => p.name.toLowerCase().includes(searchQuery.toLowerCase()) || p.display.toLowerCase().includes(searchQuery.toLowerCase()) || (p.description || '').toLowerCase().includes(searchQuery.toLowerCase())))"
                                        class="bg-soft/50 font-semibold">
                                        <x-ui.table-td colspan="2" class="px-4 py-2 font-bold uppercase tracking-wider border-b border-border">
                                            📁 &nbsp;{{ $moduleName }}
                                        </x-ui.table-td>
                                        @foreach($roles as $role)
                                            <x-ui.table-td colspan="1" class="px-4 py-2 border-b border-border"></x-ui.table-td>
                                        @endforeach
                                    </x-ui.table-row>
                                    
                                    {{-- Permission Rows --}}
                                    @foreach($perms as $permission)
                                        @php
                                            $displayPath = $permissionPaths[$permission->name] ?? $permission->name;
                                            $isSensitive = in_array($permission->name, ['manage_user_mapping', 'manage_rbac', 'view_audit_log', 'configure_ews']);
                                        @endphp
                                        <x-ui.table-row x-show="(moduleFilter === '' || moduleFilter === '{{ $moduleName }}') && (searchQuery === '' || '{{ strtolower($permission->name) }}'.includes(searchQuery.toLowerCase()) || '{{ strtolower($displayPath) }}'.includes(searchQuery.toLowerCase()) || '{{ strtolower($permission->description) }}'.includes(searchQuery.toLowerCase()))"
                                            class="hover:bg-soft/30 transition-colors">
                                            <x-ui.table-td class="text-muted">{{ $globalIndex++ }}</x-ui.table-td>
                                            <x-ui.table-td>
                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                    <span class="font-bold text-primary text-[11px]">{{ $displayPath }}</span>
                                                    @if($isSensitive)
                                                        <span class="inline-flex items-center text-[9px] font-bold uppercase tracking-wider text-danger leading-none">⚠️ High Risk / Sensitif</span>
                                                    @endif
                                                </div>
                                                <div class="text-[10px] text-muted mt-0.5 leading-relaxed">
                                                    @if($permission->name === 'generate_reports')
                                                        Mengunduh/export rekap data pegawai dan cuti dalam format PDF dan Excel.
                                                    @else
                                                        {{ $permission->description }}
                                                    @endif
                                                </div>
                                            </x-ui.table-td>
                                            @foreach($roles as $role)
                                                <x-ui.table-td align="center" class="align-middle hover:bg-soft/40 transition">
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
                                                    @else
                                                        <div class="flex items-center justify-center">
                                                            <x-form.checkbox
                                                                name="matrix[{{ $role->id }}][]"
                                                                value="{{ $permission->id }}"
                                                                x-model="currentData[{{ $role->id }}]"
                                                                @change="checkDirty()"
                                                                class="transition"
                                                            />
                                                        </div>
                                                    @endif
                                                </x-ui.table-td>
                                            @endforeach
                                        </x-ui.table-row>
                                    @endforeach
                                </x-ui.table-body>
                            @endforeach
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
                        <p class="text-[11px] text-white/60 font-sans">Simpan perubahan matriks hak akses atau klik Batal untuk membatalkan perubahan.</p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 shrink-0">
                    <button
                        type="button"
                        @click="resetChanges()"
                        class="px-4 py-2 text-xs font-semibold text-white/80 hover:text-white transition cursor-pointer font-sans"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="$dispatch('open-confirm-rbac')"
                        class="inline-flex items-center justify-center rounded-lg bg-secondary px-5 py-2.5 text-xs font-bold text-ink shadow-sm transition hover:opacity-90 cursor-pointer font-sans"
                    >
                        Simpan Perubahan
                    </button>
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
