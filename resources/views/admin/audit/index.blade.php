<x-layouts.app title="Audit Log">

    <div x-data="{
        filterEvent: 'all',
        filterUser: 'all',
        filterModul: 'all',
        filterStartDate: '',
        filterEndDate: '',
        selectedLogId: null,
        showDrawer: false,
        searchQuery: '',
        logs: {{ json_encode($auditLogs) }},
        currentPage: 1,
        perPage: 25,
        sortField: 'timestamp',
        sortDirection: 'desc',
        toggleSort(field) {
            if (this.sortField === field) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortField = field;
                this.sortDirection = 'asc';
            }
            this.currentPage = 1;
        },
        getRingkasan(log) {
            if (!log) return '';
            if (log.event === 'LOGIN') return 'LOGIN: Login berhasil';
            if (log.event === 'LOGOUT') return 'LOGOUT: Logout dari sistem';
            if (log.event === 'SESSION_TIMEOUT') return 'SESSION_TIMEOUT: Sesi berakhir karena idle timeout';
            if (log.event === 'APPROVE' || log.event === 'POSTPONE') {
                return `${log.event}: Mengubah status pengajuan cuti`;
            }

            let target = log.modul;
            if (log.event === 'CREATE') {
                return `CREATE: Membuat data ${target} #${log.record_id}`;
            }
            if (log.event === 'UPDATE') {
                const fields = log.new_values ? Object.keys(log.new_values) : [];
                const fieldStr = fields.length > 0 ? fields.join(', ') : 'data';
                return `UPDATE: Mengubah ${fieldStr}`;
            }
            if (log.event === 'SOFT_DELETE') {
                return `SOFT_DELETE: Menonaktifkan data ${target} #${log.record_id}`;
            }
            if (log.event === 'RESTORE') {
                return `RESTORE: Mengaktifkan kembali data ${target} #${log.record_id}`;
            }

            return `${log.event}: ${log.event} pada ${target} #${log.record_id}`;
        },
        get filteredLogs() {
            let filtered = this.logs.filter(log => {
                const query = this.searchQuery.toLowerCase().trim();
                const matchesSearch = !query ||
                                      (log.operator && log.operator.toLowerCase().includes(query)) ||
                                      (log.record_id && log.record_id.toLowerCase().includes(query));

                const matchesEvent = this.filterEvent === 'all' || log.event === this.filterEvent;
                const matchesUser = this.filterUser === 'all' || log.operator === this.filterUser;
                const matchesModul = this.filterModul === 'all' || log.modul === this.filterModul;

                let matchesPeriode = true;
                if (log.timestamp) {
                    const logDateStr = log.timestamp.split(' ')[0];
                    if (this.filterStartDate) {
                        if (logDateStr < this.filterStartDate) matchesPeriode = false;
                    }
                    if (this.filterEndDate) {
                        if (logDateStr > this.filterEndDate) matchesPeriode = false;
                    }
                }

                return matchesSearch && matchesEvent && matchesUser && matchesModul && matchesPeriode;
            });

            return [...filtered].sort((a, b) => {
                let valA = a[this.sortField];
                let valB = b[this.sortField];

                if (this.sortField === 'timestamp') {
                    valA = new Date(valA || 0);
                    valB = new Date(valB || 0);
                } else if (typeof valA === 'string') {
                    valA = valA.toLowerCase();
                    valB = (valB || '').toLowerCase();
                }

                if (valA < valB) return this.sortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return this.sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
        },
        get paginatedLogs() {
            const start = (this.currentPage - 1) * this.perPage;
            const end = start + this.perPage;
            return this.filteredLogs.slice(start, end);
        },
        get totalPages() {
            return Math.ceil(this.filteredLogs.length / this.perPage) || 1;
        },
        get selectedLog() {
            return this.logs.find(l => l.id === this.selectedLogId) || this.logs[0] || {};
        },
        getDiffFields(log) {
            if (!log) return [];
            const diffs = [];
            const oldVals = log.old_values || {};
            const newVals = log.new_values || {};

            const allKeys = Array.from(new Set([...Object.keys(oldVals), ...Object.keys(newVals)]));

            for (const key of allKeys) {
                const oldVal = oldVals[key];
                const newVal = newVals[key];

                if (log.event === 'UPDATE') {
                    if (JSON.stringify(oldVal) !== JSON.stringify(newVal)) {
                        diffs.push({
                            field: key,
                            old: oldVal !== undefined && oldVal !== null ? oldVal : '-',
                            new: newVal !== undefined && newVal !== null ? newVal : '-'
                        });
                    }
                } else {
                    diffs.push({
                        field: key,
                        old: oldVal !== undefined && oldVal !== null ? oldVal : '-',
                        new: newVal !== undefined && newVal !== null ? newVal : '-'
                    });
                }
            }
            return diffs;
        }
    }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-primary font-sans">Audit Log</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Audit Log']
                ]" />
            </div>
        </div>

        {{-- FILTER PANEL --}}
        <x-ui.filter-bar
            class="sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 items-end"
            searchModel="searchQuery"
            searchLabel="Cari"
            searchPlaceholder="Ketik nama operator atau ID record..."
            searchCols="col-span-1 lg:col-span-2"
        >
            <x-slot:header>
                <h3 class="text-sm font-semibold text-ink font-sans">Filter & Pencarian</h3>
                <p class="text-xs text-muted">Saring jejak audit berdasarkan kriteria spesifik di bawah ini.</p>
            </x-slot:header>

            <x-slot:actions>
                <button @click="filterEvent = 'all'; filterUser = 'all'; filterModul = 'all'; filterStartDate = ''; filterEndDate = ''; searchQuery = '';"
                        class="text-xs text-primary font-semibold hover:underline font-sans cursor-pointer flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Reset Filter
                </button>
            </x-slot:actions>

            {{-- Dropdown Event --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-muted font-sans uppercase tracking-wider">Jenis Event</label>
                <div class="relative">
                    <x-form.select x-model="filterEvent">
                        <option value="all">Semua Event</option>
                        <option value="LOGIN">LOGIN</option>
                        <option value="LOGOUT">LOGOUT</option>
                        <option value="SESSION_TIMEOUT">SESSION_TIMEOUT</option>
                        <option value="CREATE">CREATE</option>
                        <option value="UPDATE">UPDATE</option>
                        <option value="SOFT_DELETE">SOFT_DELETE</option>
                        <option value="RESTORE">RESTORE</option>
                        <option value="APPROVE">APPROVE</option>
                        <option value="POSTPONE">POSTPONE</option>
                        <option value="IMPORT">IMPORT</option>
                    </x-form.select>
                </div>
            </div>

            {{-- Dropdown Operator --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-muted font-sans uppercase tracking-wider">User / Operator</label>
                <div class="relative">
                    <x-form.select x-model="filterUser">
                        <option value="all">Semua User</option>
                        <template x-for="op in [...new Set(logs.map(l => l.operator))]" :key="op">
                            <option :value="op" x-text="op"></option>
                        </template>
                    </x-form.select>
                </div>
            </div>

            {{-- Dropdown Modul --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-muted font-sans uppercase tracking-wider">Modul / Tabel</label>
                <div class="relative">
                    <x-form.select x-model="filterModul">
                        <option value="all">Semua Modul</option>
                        <template x-for="mod in [...new Set(logs.map(l => l.modul))]" :key="mod">
                            <option :value="mod" x-text="mod"></option>
                        </template>
                    </x-form.select>
                </div>
            </div>

            {{-- Periode Mulai --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-muted font-sans uppercase tracking-wider">Periode Mulai</label>
                <input type="date" x-model="filterStartDate" class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
            </div>

            {{-- Periode Selesai --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-muted font-sans uppercase tracking-wider">Periode Selesai</label>
                <input type="date" x-model="filterEndDate" class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
            </div>
        </x-ui.filter-bar>

        {{-- Table Card --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">

            {{-- Toolbar --}}
            <div class="px-6 py-4 border-b border-border flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Rekam Jejak Aktivitas (Audit Log)</h3>
                    <p class="text-xs text-muted">Catatan mutasi data dan otentikasi sistem kepegawaian secara kronologis.</p>
                </div>
            </div>

            {{-- Table render --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th @click="toggleSort('timestamp')" class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans cursor-pointer hover:text-primary transition-colors select-none">
                                <div class="flex items-center gap-1.5">
                                    Waktu
                                    <template x-if="sortField === 'timestamp'">
                                        <span>
                                            <svg x-show="sortDirection === 'asc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                            <svg x-show="sortDirection === 'desc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </span>
                                    </template>
                                    <template x-if="sortField !== 'timestamp'">
                                        <svg class="w-3 h-3 text-muted/40 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                                    </template>
                                </div>
                            </th>
                            <th @click="toggleSort('operator')" class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans cursor-pointer hover:text-primary transition-colors select-none">
                                <div class="flex items-center gap-1.5">
                                    User
                                    <template x-if="sortField === 'operator'">
                                        <span>
                                            <svg x-show="sortDirection === 'asc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                            <svg x-show="sortDirection === 'desc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </span>
                                    </template>
                                    <template x-if="sortField !== 'operator'">
                                        <svg class="w-3 h-3 text-muted/40 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                                    </template>
                                </div>
                            </th>
                            <th @click="toggleSort('event')" class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans cursor-pointer hover:text-primary transition-colors select-none">
                                <div class="flex items-center gap-1.5">
                                    Jenis Event
                                    <template x-if="sortField === 'event'">
                                        <span>
                                            <svg x-show="sortDirection === 'asc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                            <svg x-show="sortDirection === 'desc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </span>
                                    </template>
                                    <template x-if="sortField !== 'event'">
                                        <svg class="w-3 h-3 text-muted/40 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                                    </template>
                                </div>
                            </th>
                            <th @click="toggleSort('modul')" class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans cursor-pointer hover:text-primary transition-colors select-none">
                                <div class="flex items-center gap-1.5">
                                    Modul/Tabel
                                    <template x-if="sortField === 'modul'">
                                        <span>
                                            <svg x-show="sortDirection === 'asc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                            <svg x-show="sortDirection === 'desc'" class="w-3 h-3 text-primary inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                        </span>
                                    </template>
                                    <template x-if="sortField !== 'modul'">
                                        <svg class="w-3 h-3 text-muted/40 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                                    </template>
                                </div>
                            </th>
                            <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans select-none">Ringkasan Perubahan</th>
                            <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans select-none">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="log in paginatedLogs" :key="log.id">
                            <tr @click="selectedLogId = log.id; showDrawer = true" class="transition-colors hover:bg-soft/50 cursor-pointer">
                                <td class="px-4 py-3.5 text-xs text-ink" x-text="log.timestamp"></td>
                                <td class="px-4 py-3.5 text-sm font-semibold text-ink font-sans" x-text="log.operator"></td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold font-sans"
                                        :class="log.event === 'CREATE' || log.event === 'IMPORT' || log.event === 'APPROVE' || log.event === 'RESTORE' ? 'text-success' : (log.event === 'LOGIN' ? 'text-primary' : (log.event === 'SOFT_DELETE' || log.event === 'LOGOUT' ? 'text-danger' : 'text-warning'))"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full"
                                            :class="log.event === 'CREATE' || log.event === 'IMPORT' || log.event === 'APPROVE' || log.event === 'RESTORE' ? 'bg-success' : (log.event === 'LOGIN' ? 'bg-primary' : (log.event === 'SOFT_DELETE' || log.event === 'LOGOUT' ? 'bg-danger' : 'bg-warning'))"
                                        ></span>
                                        <span x-text="log.event"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-xs text-muted font-sans" x-text="log.modul"></td>
                                <td class="px-4 py-3.5 text-xs text-ink font-sans" x-text="getRingkasan(log)"></td>
                                <td class="px-4 py-3.5" @click.stop>
                                    <div class="flex items-center gap-1.5">
                                        <button
                                            @click.stop="selectedLogId = log.id; showDrawer = true"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30 shadow-sm cursor-pointer"
                                            title="Detail Drawer"
                                        >
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </button>
                                        <a
                                            @click.stop
                                            :href="'/dashboard/audit/' + log.id"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/30 shadow-sm"
                                            title="Halaman Detail"
                                        >
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                            </svg>
                                        </a>
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
                    <p class="text-sm text-muted font-sans">
                        Menampilkan
                        <span x-text="filteredLogs.length === 0 ? 0 : (currentPage - 1) * perPage + 1"></span> -
                        <span x-text="Math.min(currentPage * perPage, filteredLogs.length)"></span> dari
                        <span x-text="filteredLogs.length"></span> data
                    </p>
                    <div class="relative">
                        <select id="per-page" x-model.number="perPage" @change="currentPage = 1" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option value="10">10 / halaman</option>
                            <option value="25">25 / halaman</option>
                            <option value="50">50 / halaman</option>
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

        </div>

        {{-- Slide-over Drawer --}}
        <div x-show="showDrawer" class="fixed inset-0 z-50 overflow-hidden" style="display: none;" x-transition>
            <div class="absolute inset-0 bg-ink/30 transition-opacity z-40" @click="showDrawer = false"></div>
            <div class="fixed inset-y-0 right-0 pl-10 max-w-full flex z-50">
                <div class="w-screen max-w-md bg-surface border-l border-border shadow-xl flex flex-col justify-between relative z-50" x-transition:enter="transform transition ease-in-out duration-300 sm:duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300 sm:duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">

                    {{-- Drawer Header --}}
                    <div class="px-6 py-5 border-b border-border flex items-center justify-between bg-surface">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Detail Log Aktivitas</h3>
                            <p class="text-xs text-muted">Metadata operasional dan perubahan database.</p>
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
                                <p class="text-ink" x-text="selectedLog.timestamp"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">Pegawai</p>
                                <p class="text-ink font-sans" x-text="selectedLog.operator"></p>
                            </div>
                            <div class="space-y-0.5">
                                <p class="font-semibold text-muted font-sans">IP Address</p>
                                <p class="text-ink" x-text="selectedLog.ip_address"></p>
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
                                <p class="text-ink truncate" x-text="selectedLog.record_id"></p>
                            </div>
                        </div>

                        {{-- Diff Data Panel --}}
                        <div class="space-y-3 pt-4 border-t border-border">
                            <h4 class="text-xs font-bold text-ink font-sans uppercase tracking-wider">Perubahan Nilai Data</h4>

                            <div class="overflow-hidden rounded-lg border border-border bg-soft">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-primary/10 text-[10px] uppercase font-semibold text-muted font-sans border-b border-border">
                                            <th class="px-3 py-2">Nama Field</th>
                                            <th class="px-3 py-2">Sebelum</th>
                                            <th class="px-3 py-2">Sesudah</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border text-[11px] font-sans">
                                        <template x-for="item in getDiffFields(selectedLog)" :key="item.field">
                                            <tr>
                                                <td class="px-3 py-2 font-semibold text-ink" x-text="item.field"></td>
                                                <td class="px-3 py-2 text-danger bg-danger/5" x-text="typeof item.old === 'object' ? JSON.stringify(item.old) : item.old"></td>
                                                <td class="px-3 py-2 text-success bg-success/5" x-text="typeof item.new === 'object' ? JSON.stringify(item.new) : item.new"></td>
                                            </tr>
                                        </template>
                                        <template x-if="getDiffFields(selectedLog).length === 0">
                                            <tr>
                                                <td colspan="3" class="px-3 py-4 text-center text-muted font-sans">
                                                    Tidak ada detail perubahan nilai data (misal: event login/logout).
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                    {{-- Drawer Footer --}}
                    <div class="px-6 py-4 border-t border-border bg-soft flex justify-between items-center gap-2">
                        <div>
                            <template x-if="selectedLog.modul === 'Employee' || selectedLog.modul === 'LeaveRequest'">
                                <a :href="selectedLog.modul === 'Employee' ? '/pegawai/' + selectedLog.record_id : '/dashboard/cuti/' + selectedLog.record_id"
                                   class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:bg-primary-700 font-sans shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                                    Lihat Record
                                </a>
                            </template>
                        </div>
                        <div class="flex items-center gap-2">
                            <a :href="'/dashboard/audit/' + selectedLog.id" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft font-sans shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                                Detail Penuh
                            </a>
                            <button @click="showDrawer = false" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft cursor-pointer focus:outline-none font-sans shadow-sm focus:ring-2 focus:ring-primary/30">
                                Tutup
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

</x-layouts.app>
