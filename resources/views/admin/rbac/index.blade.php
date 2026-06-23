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
    }" class="space-y-6">

        {{-- PAGE HEADER & BREADCRUMBS --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Role & Permission / RBAC</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Role & Permission</span>
                </nav>
            </div>
        </div>

        {{-- NOTIFICATIONS --}}
        @if(session('success'))
            <div class="rounded-lg border border-success/20 bg-success/10 p-4 text-sm font-semibold text-success flex items-center gap-2">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ session('success') }}</span>
            </div>
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
                    <p class="text-xs text-ink/80 font-sans mt-1 leading-relaxed">
                        Keycloak hanya berperan sebagai gerbang autentikasi login (SSO). Penentuan menu, hak akses halaman, 
                        dan aksi fitur diatur sepenuhnya di database internal SIMPEG melalui matriks RBAC di bawah ini. 
                        Perubahan hak akses peran berlaku secara langsung.
                    </p>
                </div>
            </div>
        </div>

        {{-- SUMMARY ROLES CARDS --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($roles as $role)
                <div class="rounded-lg border border-border bg-surface p-4 shadow-sm flex flex-col justify-between hover:border-primary/25 transition-all duration-300">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wide
                            {{ $role->name === 'Super Admin' ? 'text-danger' : '' }}
                            {{ $role->name === 'Admin Kepegawaian' ? 'text-primary' : '' }}
                            {{ $role->name === 'Pimpinan' ? 'text-secondary' : '' }}
                            {{ $role->name === 'Atasan Langsung' ? 'text-warning' : '' }}
                            {{ $role->name === 'Pegawai' ? 'text-success' : '' }}
                        ">
                            {{ $role->name }}
                        </span>
                        <p class="text-[10px] text-muted mt-2 font-sans line-clamp-2" title="{{ $role->description }}">
                            {{ $role->description }}
                        </p>
                    </div>
                    <div class="mt-4 border-t border-border/50 pt-2 flex items-baseline justify-between">
                        <span class="text-[10px] text-muted font-sans font-medium">Izin Aktif:</span>
                        <span class="text-base font-bold text-ink font-mono">{{ $role->permissions->count() }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- MATRIX CARD --}}
        <form action="{{ route('rbac.update') }}" method="POST" class="relative">
            @csrf

            <div class="rounded-lg border border-border bg-surface shadow-sm overflow-hidden">
                {{-- TABLE HEADER SEARCH --}}
                <div class="px-6 py-4 border-b border-border bg-surface flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-ink font-sans">Matriks Konfigurasi RBAC</h3>
                        <p class="text-[10px] text-muted font-sans mt-0.5">Tentukan daftar permission dan modul yang diizinkan untuk setiap level peran.</p>
                    </div>
                    <div class="relative w-full sm:w-72">
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

                {{-- TABLE CONTENT --}}
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead class="bg-soft border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans w-[5%] border-b border-border">NO</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans w-[45%] border-b border-border">MODUL / IZIN FITUR</th>
                                @foreach($roles as $role)
                                    <th class="px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wide text-muted font-sans w-[10%] border-b border-border">
                                        {{ $role->name }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
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
                                <tbody class="divide-y divide-border border-b border-border" x-data="{ perms: {{ $permsJson }} }">
                                    {{-- Module Header Row --}}
                                    <tr x-show="searchQuery === '' || perms.some(p => p.name.toLowerCase().includes(searchQuery.toLowerCase()) || p.display.toLowerCase().includes(searchQuery.toLowerCase()) || (p.description || '').toLowerCase().includes(searchQuery.toLowerCase()))" 
                                        class="bg-soft/50 font-semibold">
                                        <td class="px-4 py-2 text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border" colspan="2">
                                            📁 &nbsp;{{ $moduleName }}
                                        </td>
                                        @foreach($roles as $role)
                                            <td class="px-4 py-2 border-b border-border" colspan="1"></td>
                                        @endforeach
                                    </tr>
                                    
                                    {{-- Permission Rows --}}
                                    @foreach($perms as $permission)
                                        @php
                                            $displayPath = $permissionPaths[$permission->name] ?? $permission->name;
                                        @endphp
                                        <tr x-show="searchQuery === '' || '{{ strtolower($permission->name) }}'.includes(searchQuery.toLowerCase()) || '{{ strtolower($displayPath) }}'.includes(searchQuery.toLowerCase()) || '{{ strtolower($permission->description) }}'.includes(searchQuery.toLowerCase())"
                                            class="hover:bg-soft/30 transition-colors">
                                            <td class="px-4 py-3.5 text-xs font-mono text-muted">{{ $globalIndex++ }}</td>
                                            <td class="px-4 py-3.5 text-xs font-sans">
                                                <div class="font-bold text-primary font-mono text-[11px]">{{ $displayPath }}</div>
                                                <div class="text-[10px] text-muted mt-0.5 leading-relaxed">{{ $permission->description }}</div>
                                            </td>
                                            @foreach($roles as $role)
                                                <td class="px-4 py-3.5 text-center align-middle hover:bg-soft/40 transition">
                                                    @if($role->name === 'Super Admin')
                                                        {{-- Super Admin is always checked and disabled to prevent lockout --}}
                                                        <div class="flex items-center justify-center">
                                                            <input
                                                                type="checkbox"
                                                                checked
                                                                disabled
                                                                class="h-4.5 w-4.5 rounded border-border text-primary/45 bg-soft cursor-not-allowed focus:ring-0"
                                                            >
                                                            {{-- Standard hidden inputs for checked values to send back --}}
                                                            <input type="hidden" name="matrix[{{ $role->id }}][]" value="{{ $permission->id }}">
                                                        </div>
                                                    @else
                                                        <div class="flex items-center justify-center">
                                                            <input
                                                                type="checkbox"
                                                                name="matrix[{{ $role->id }}][]"
                                                                value="{{ $permission->id }}"
                                                                x-model="currentData[{{ $role->id }}]"
                                                                @change="checkDirty()"
                                                                class="h-4.5 w-4.5 rounded border-border text-primary focus:ring-primary/20 cursor-pointer transition"
                                                            >
                                                        </div>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

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
                        class="px-4 py-2 text-xs font-semibold text-white/80 hover:text-white transition cursor-pointer"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-secondary px-5 py-2.5 text-xs font-bold text-ink shadow-sm transition hover:opacity-90 cursor-pointer"
                    >
                        Simpan Perubahan
                    </button>
                </div>
            </div>

        </form>

    </div>

</x-layouts.app>
