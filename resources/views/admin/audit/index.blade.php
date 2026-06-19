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

        {{-- Navigation Flat Tabs --}}
        <div class="flex items-center gap-6 px-6 py-4 bg-surface border border-border rounded-lg shadow-sm">
            <button
                @click="activeFilter = 'all'"
                :class="activeFilter === 'all' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                Semua
            </button>
            <button
                @click="activeFilter = 'data_pegawai'"
                :class="activeFilter === 'data_pegawai' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                Data Pegawai
            </button>
            <button
                @click="activeFilter = 'autentikasi'"
                :class="activeFilter === 'autentikasi' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                Autentikasi
            </button>
            <button
                @click="activeFilter = 'transaksi_cuti'"
                :class="activeFilter === 'transaksi_cuti' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                Transaksi Cuti
            </button>
            <button
                @click="activeFilter = 'system_import'"
                :class="activeFilter === 'system_import' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                System Import
            </button>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            
            {{-- Toolbar --}}
            <div class="px-6 py-4 border-b border-border flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div>
                    <h2 class="text-base font-bold text-ink font-sans leading-tight">Rekam Jejak Aktivitas (Audit Log)</h2>
                    <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Catatan mutasi data dan otentikasi sistem kepegawaian secara kronologis.</p>
                </div>
                <div class="relative">
                    <input
                        type="text"
                        x-model="searchQuery"
                        placeholder="Cari pelaku/aktivitas/modul..."
                        class="h-[44px] w-72 rounded-lg border border-border bg-soft px-3 text-xs text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"
                    >
                </div>
            </div>

            {{-- Table render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft">
                        <tr>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Waktu</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Pelaku</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aktivitas</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Modul</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">IP Address</th>
                            <th class="px-6 py-2.5 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-6 py-2.5 text-xs font-mono text-ink" x-text="log.timestamp"></td>
                                <td class="px-6 py-2.5 text-sm font-semibold text-ink font-sans" x-text="log.operator"></td>
                                <td class="px-6 py-2.5 text-xs font-bold font-sans" 
                                    :class="log.event === 'CREATE' || log.event === 'IMPORT' ? 'text-success' : (log.event === 'LOGIN' ? 'text-primary' : 'text-warning')"
                                    x-text="log.event"
                                ></td>
                                <td class="px-6 py-2.5 text-xs text-muted font-sans" x-text="log.modul"></td>
                                <td class="px-6 py-2.5 text-xs font-mono text-muted" x-text="log.ip_address"></td>
                                <td class="px-6 py-2.5">
                                    <button 
                                        @click="selectedLogId = log.id; showDrawer = true" 
                                        class="text-xs font-semibold text-primary hover:underline font-sans cursor-pointer focus:outline-none"
                                    >
                                        Detail
                                    </button>
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

        </div>

        {{-- Slide-over Drawer --}}
        <div x-show="showDrawer" class="fixed inset-0 z-50 overflow-hidden" style="display: none;" x-transition>
            <div class="absolute inset-0 bg-ink/30 transition-opacity" @click="showDrawer = false"></div>
            <div class="fixed inset-y-0 right-0 pl-10 max-w-full flex">
                <div class="w-screen max-w-md bg-surface border-l border-border shadow-xl flex flex-col justify-between">
                    
                    {{-- Drawer Header --}}
                    <div class="px-6 py-5 border-b border-border flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Detail Log Aktivitas</h3>
                            <p class="text-[11px] text-muted font-sans mt-0.5">Metadata operasional dan perubahan database.</p>
                        </div>
                        <button @click="showDrawer = false" class="text-xs font-semibold text-muted hover:text-ink font-sans cursor-pointer focus:outline-none">
                            Tutup
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
                                <p class="font-semibold text-muted font-sans">Pelaku</p>
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
                                        <span class="text-muted">Tidak ada perubahan/data lama kosong</span>
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
                                        <span class="text-muted">Tidak ada data baru</span>
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
