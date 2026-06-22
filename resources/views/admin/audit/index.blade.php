<x-layouts.app title="Audit Log">

    @php
    $auditLogs = [
        [
            'id' => 1,
            'timestamp' => '2026-06-19 13:42:15',
            'operator' => 'Ahmad Fauzi',
            'event' => 'LOGIN',
            'kategori' => 'autentikasi',
            'modul' => 'User',
            'record_id' => '1',
            'ip_address' => '192.168.1.102',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
            'old_values' => null,
            'new_values' => [
                'last_login_at' => '2026-06-19 13:42:15',
                'last_login_ip' => '192.168.1.102'
            ]
        ],
        [
            'id' => 2,
            'timestamp' => '2026-06-19 11:20:04',
            'operator' => 'Demo Klabat',
            'event' => 'CREATE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => 'c87f2807-ea0d-400f-bd34-f45d17da89db',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'old_values' => null,
            'new_values' => [
                'nama' => 'Sabrina Rossa',
                'nip' => '20261210820500004',
                'email' => 'sabrinarossa24@gmail.com',
                'status' => 'aktif'
            ]
        ],
        [
            'id' => 3,
            'timestamp' => '2026-06-19 10:15:30',
            'operator' => 'Ahmad Fauzi',
            'event' => 'APPROVE',
            'kategori' => 'transaksi_cuti',
            'modul' => 'LeaveRequest',
            'record_id' => '23',
            'ip_address' => '192.168.1.102',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/127.0',
            'old_values' => [
                'status' => 'menunggu',
                'approved_by_atasan' => false
            ],
            'new_values' => [
                'status' => 'menunggu_kabag',
                'approved_by_atasan' => true,
                'approved_by_atasan_at' => '2026-06-19 10:15:30'
            ]
        ],
        [
            'id' => 4,
            'timestamp' => '2026-06-18 16:05:00',
            'operator' => 'Demo Klabat',
            'event' => 'UPDATE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => 'c87f2807-ea0d-400f-bd34-f45d17da89db',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'old_values' => [
                'email' => 'sabrinarossa@gmail.com',
                'status' => 'cuti'
            ],
            'new_values' => [
                'email' => 'sabrinarossa24@gmail.com',
                'status' => 'aktif'
            ]
        ],
        [
            'id' => 5,
            'timestamp' => '2026-06-18 09:30:00',
            'operator' => 'Demo Klabat',
            'event' => 'IMPORT',
            'kategori' => 'system_import',
            'modul' => 'ExcelImport',
            'record_id' => 'import-20260618093000',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Console (CLI / Queue Worker)',
            'old_values' => null,
            'new_values' => [
                'file_name' => 'daftar_pegawai.xlsx',
                'status' => 'sukses',
                'records_imported' => 8,
                'records_skipped' => 0
            ]
        ]
    ];
    @endphp

    <div x-data="{
        activeFilter: 'all',
        selectedLogId: 2,
        showDrawer: false,
        searchQuery: '',
        logs: {{ json_encode($auditLogs) }},
        get filteredLogs() {
            return this.logs.filter(log => {
                const matchesSearch = log.operator.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                                      log.event.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                      log.modul.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                                      log.ip_address.toLowerCase().includes(this.searchQuery.toLowerCase());
                const matchesKategori = this.activeFilter === 'all' || log.kategori === this.activeFilter;
                return matchesSearch && matchesKategori;
            });
        },
        get selectedLog() {
            return this.logs.find(l => l.id === this.selectedLogId) || this.logs[0];
        }
    }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Audit Log</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Audit Log</span>
                </nav>
            </div>
        </div>

        {{-- FILTER / NAVIGATION TABS --}}
        <div class="rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between shadow-sm mb-6">
            <div class="flex items-center gap-6 overflow-x-auto shrink-0">
                <button
                    @click="activeFilter = 'all'"
                    :class="activeFilter === 'all' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    Semua
                </button>
                <button
                    @click="activeFilter = 'data_pegawai'"
                    :class="activeFilter === 'data_pegawai' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    Data Pegawai
                </button>
                <button
                    @click="activeFilter = 'autentikasi'"
                    :class="activeFilter === 'autentikasi' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    Autentikasi
                </button>
                <button
                    @click="activeFilter = 'transaksi_cuti'"
                    :class="activeFilter === 'transaksi_cuti' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    Transaksi Cuti
                </button>
                <button
                    @click="activeFilter = 'system_import'"
                    :class="activeFilter === 'system_import' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    System Import
                </button>
            </div>

            {{-- Inputs --}}
            <div class="flex flex-1 flex-wrap items-center justify-end gap-3 lg:flex-initial">
                {{-- Search Input with Glass Icon --}}
                <div class="relative flex items-center w-full lg:w-72 rounded-lg border border-border bg-surface px-3 py-1.5 shadow-sm focus-within:ring-2 focus-within:ring-primary/20 focus-within:border-primary">
                    <svg class="w-4 h-4 text-muted shrink-0 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        type="text"
                        x-model="searchQuery"
                        placeholder="Cari pegawai/aktivitas/modul..."
                        class="w-full bg-transparent text-xs text-ink placeholder:text-muted focus:outline-none font-sans"
                    >
                </div>
            </div>
        </div>

        {{-- Table Card --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            
            {{-- Toolbar --}}
            <div class="px-6 py-4 border-b border-border flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Rekam Jejak Aktivitas (Audit Log)</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Catatan mutasi data dan otentikasi sistem kepegawaian secara kronologis.</p>
                </div>
            </div>

            {{-- Table render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Waktu</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Pegawai</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Aktivitas</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Modul</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">IP Address</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <tr class="transition-colors hover:bg-soft/50">
                                <td class="px-4 py-3.5 text-xs font-mono text-ink" x-text="log.timestamp"></td>
                                <td class="px-4 py-3.5 text-sm font-semibold text-ink font-sans" x-text="log.operator"></td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold font-sans"
                                        :class="log.event === 'CREATE' || log.event === 'IMPORT' ? 'bg-success/10 text-success' : (log.event === 'LOGIN' ? 'bg-primary/10 text-primary' : 'bg-warning/10 text-warning')"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full"
                                            :class="log.event === 'CREATE' || log.event === 'IMPORT' ? 'bg-success' : (log.event === 'LOGIN' ? 'bg-primary' : 'bg-warning')"
                                        ></span>
                                        <span x-text="log.event"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-xs text-muted font-sans" x-text="log.modul"></td>
                                <td class="px-4 py-3.5 text-xs font-mono text-muted" x-text="log.ip_address"></td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-1.5">
                                        <button 
                                            @click="selectedLogId = log.id; showDrawer = true" 
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" 
                                            title="Detail"
                                        >
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filteredLogs.length === 0">
                            <td colspan="6" class="px-6 py-8 text-center text-xs text-muted font-sans">
                                Tidak ada log aktivitas yang cocok dengan filter pencarian.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted font-sans">Menampilkan 1 - 5 dari 5 data</p>
                    <div class="relative">
                        <select id="per-page" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option>10 / halaman</option>
                            <option>25 / halaman</option>
                            <option>50 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    {{-- Prev --}}
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </button>
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-primary bg-primary text-sm font-semibold text-white transition hover:opacity-90 font-sans">1</button>
                    {{-- Next --}}
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>
            </div>

        </div>

        {{-- Slide-over Drawer --}}
        <div x-show="showDrawer" class="fixed inset-0 z-50 overflow-hidden" style="display: none;" x-transition>
            <div class="absolute inset-0 bg-ink/30 transition-opacity" @click="showDrawer = false"></div>
            <div class="fixed inset-y-0 right-0 pl-10 max-w-full flex">
                <div class="w-screen max-w-md bg-surface border-l border-border shadow-xl flex flex-col justify-between" x-transition:enter="transform transition ease-in-out duration-300 sm:duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300 sm:duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
                    
                    {{-- Drawer Header --}}
                    <div class="px-6 py-5 border-b border-border flex items-center justify-between bg-surface">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Detail Log Aktivitas</h3>
                            <p class="text-[10px] text-muted font-sans mt-0.5">Metadata operasional dan perubahan database.</p>
                        </div>
                        <button @click="showDrawer = false" class="rounded-lg p-1.5 text-muted hover:bg-soft hover:text-ink transition-colors cursor-pointer focus:outline-none" aria-label="Close panel">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {{-- Drawer Body --}}
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        
                        {{-- Info List --}}
                        <div class="grid grid-cols-2 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">Aktivitas</p>
                                <p class="text-ink font-sans font-bold" x-text="selectedLog.event"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">Waktu</p>
                                <p class="text-ink font-mono" x-text="selectedLog.timestamp"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">Pegawai</p>
                                <p class="text-ink font-sans" x-text="selectedLog.operator"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">IP Address</p>
                                <p class="text-ink font-mono" x-text="selectedLog.ip_address"></p>
                            </div>
                            <div class="col-span-2 space-y-0.5">
                                <p class="font-semibold text-muted font-sans">Browser / User Agent</p>
                                <p class="text-ink font-sans leading-relaxed" x-text="selectedLog.user_agent"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">Target Modul</p>
                                <p class="text-ink font-sans" x-text="selectedLog.modul"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">ID Record</p>
                                <p class="text-ink font-mono truncate" x-text="selectedLog.record_id"></p>
                            </div>
                        </div>

                        {{-- Diff Data Panel --}}
                        <div class="space-y-3 pt-4 border-t border-border">
                            <h4 class="text-xs font-bold text-ink font-sans uppercase tracking-wider">Perubahan Nilai Data</h4>
                            
                            {{-- Old Values --}}
                            <div class="space-y-1">
                                <p class="text-[11px] font-semibold text-muted font-sans">Data Sebelum (Lama)</p>
                                <div class="rounded bg-soft p-3 text-[11px] font-mono text-danger leading-relaxed whitespace-pre-wrap max-h-48 overflow-y-auto">
                                    <template x-if="selectedLog.old_values">
                                        <span x-text="JSON.stringify(selectedLog.old_values, null, 2)"></span>
                                    </template>
                                    <template x-if="!selectedLog.old_values">
                                        <span class="text-muted font-sans">Tidak ada perubahan/data lama kosong</span>
                                    </template>
                                </div>
                            </div>

                            {{-- New Values --}}
                            <div class="space-y-1">
                                <p class="text-[11px] font-semibold text-muted font-sans">Data Sesudah (Baru)</p>
                                <div class="rounded bg-soft p-3 text-[11px] font-mono text-success leading-relaxed whitespace-pre-wrap max-h-48 overflow-y-auto">
                                    <template x-if="selectedLog.new_values">
                                        <span x-text="JSON.stringify(selectedLog.new_values, null, 2)"></span>
                                    </template>
                                    <template x-if="!selectedLog.new_values">
                                        <span class="text-muted font-sans">Tidak ada data baru</span>
                                    </template>
                                </div>
                            </div>
                        </div>

                    </div>

                    {{-- Drawer Footer --}}
                    <div class="px-6 py-4 border-t border-border bg-soft flex justify-end">
                        <button @click="showDrawer = false" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none font-sans">
                            Tutup Rincian
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>

</x-layouts.app>
