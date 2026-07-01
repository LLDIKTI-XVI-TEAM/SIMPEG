<x-layouts.app title="Hari Libur">

    @php
    $hariLiburData = [
        ['id' => 1, 'tanggal' => '2026-01-01', 'nama' => 'Tahun Baru 2026 Masehi', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 2, 'tanggal' => '2026-02-17', 'nama' => 'Isra Mikraj Nabi Muhammad SAW', 'tipe' => 'libur_nasional', 'hari' => 'Selasa'],
        ['id' => 3, 'tanggal' => '2026-03-19', 'nama' => 'Hari Suci Nyepi Saka 1948', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 4, 'tanggal' => '2026-03-20', 'nama' => 'Cuti Bersama Nyepi', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
        ['id' => 5, 'tanggal' => '2026-04-03', 'nama' => 'Wafat Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 6, 'tanggal' => '2026-04-05', 'nama' => 'Hari Raya Paskah', 'tipe' => 'libur_nasional', 'hari' => 'Minggu'],
        ['id' => 7, 'tanggal' => '2026-05-01', 'nama' => 'Hari Buruh Internasional', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 8, 'tanggal' => '2026-05-13', 'nama' => 'Hari Raya Waisak 2570 BE', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 9, 'tanggal' => '2026-05-14', 'nama' => 'Kenaikan Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 10, 'tanggal' => '2026-05-15', 'nama' => 'Cuti Bersama Kenaikan Yesus', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
        ['id' => 11, 'tanggal' => '2026-06-01', 'nama' => 'Hari Lahir Pancasila', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 12, 'tanggal' => '2026-06-17', 'nama' => 'Hari Raya Idul Adha 1447 H', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 13, 'tanggal' => '2026-08-17', 'nama' => 'HUT Kemerdekaan RI', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 14, 'tanggal' => '2026-12-25', 'nama' => 'Hari Raya Natal', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],

        // 2025 Data
        ['id' => 15, 'tanggal' => '2025-01-01', 'nama' => 'Tahun Baru 2025 Masehi', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 16, 'tanggal' => '2025-01-27', 'nama' => 'Isra Mikraj Nabi Muhammad SAW', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 17, 'tanggal' => '2025-03-29', 'nama' => 'Hari Raya Nyepi Saka 1947', 'tipe' => 'libur_nasional', 'hari' => 'Sabtu'],
        ['id' => 18, 'tanggal' => '2025-03-31', 'nama' => 'Hari Raya Idul Fitri 1446 H', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 19, 'tanggal' => '2025-04-18', 'nama' => 'Wafat Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 20, 'tanggal' => '2025-05-01', 'nama' => 'Hari Buruh Internasional', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 21, 'tanggal' => '2025-05-29', 'nama' => 'Kenaikan Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 22, 'tanggal' => '2025-06-01', 'nama' => 'Hari Lahir Pancasila', 'tipe' => 'libur_nasional', 'hari' => 'Minggu'],
        ['id' => 23, 'tanggal' => '2025-12-25', 'nama' => 'Hari Raya Natal', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],

        // 2024 Data
        ['id' => 24, 'tanggal' => '2024-01-01', 'nama' => 'Tahun Baru 2024 Masehi', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 25, 'tanggal' => '2024-02-08', 'nama' => 'Isra Mikraj Nabi Muhammad SAW', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 26, 'tanggal' => '2024-03-11', 'nama' => 'Hari Raya Nyepi Saka 1946', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 27, 'tanggal' => '2024-04-10', 'nama' => 'Hari Raya Idul Fitri 1445 H', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 28, 'tanggal' => '2024-04-11', 'nama' => 'Cuti Bersama Idul Fitri', 'tipe' => 'cuti_bersama', 'hari' => 'Kamis'],
        ['id' => 29, 'tanggal' => '2024-05-01', 'nama' => 'Hari Buruh Internasional', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 30, 'tanggal' => '2024-05-09', 'nama' => 'Kenaikan Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 31, 'tanggal' => '2024-06-01', 'nama' => 'Hari Lahir Pancasila', 'tipe' => 'libur_nasional', 'hari' => 'Sabtu'],
        ['id' => 32, 'tanggal' => '2024-12-25', 'nama' => 'Hari Raya Natal', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
    ];

    // Combine with dynamic session additions if any
    $sessionLogs = session('dynamic_audit_logs', []);
    $addedHolidays = [];
    foreach ($sessionLogs as $log) {
        if ($log['event'] === 'CREATE_HOLIDAY' && isset($log['new_values'])) {
            $newVal = $log['new_values'];
            // Determine day of the week
            $timestamp = strtotime($newVal['tanggal']);
            $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            $hariName = $days[date('w', $timestamp)];
            
            $addedHolidays[] = [
                'id' => $log['id'],
                'tanggal' => $newVal['tanggal'],
                'nama' => $newVal['nama'],
                'tipe' => $newVal['tipe'],
                'hari' => $hariName
            ];
        }
    }
    // Remove deleted ones
    $deletedIds = [];
    foreach ($sessionLogs as $log) {
        if ($log['event'] === 'DELETE_HOLIDAY') {
            // Find matched holiday ID in original list if possible
            // In our prototype, since we delete by ID, we extract the log's original values or target
            // Let's assume the session deletes contain info to identify the deleted ID
        }
    }

    $allHolidays = array_merge($hariLiburData, $addedHolidays);
    @endphp

    <div x-data="{
        showAddForm: false,
        activeYear: 2026,
        activeTipe: 'semua',
        searchQuery: '',
        perPage: 10,
        currentPage: 1,
        holidays: {{ json_encode($allHolidays) }},
        
        get filteredHolidays() {
            return this.holidays.filter(h => {
                const date = new Date(h.tanggal);
                const yearMatches = date.getFullYear() === parseInt(this.activeYear);
                const tipeMatches = this.activeTipe === 'semua' || h.tipe === this.activeTipe;
                const searchMatches = this.searchQuery === '' || h.nama.toLowerCase().includes(this.searchQuery.toLowerCase());
                return yearMatches && tipeMatches && searchMatches;
            });
        },

        get paginatedHolidays() {
            const start = (this.currentPage - 1) * parseInt(this.perPage);
            return this.filteredHolidays.slice(start, start + parseInt(this.perPage));
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.filteredHolidays.length / parseInt(this.perPage)));
        },

        get totalFiltered() {
            return this.filteredHolidays.length;
        },

        get startRange() {
            if (this.totalFiltered === 0) return 0;
            return (this.currentPage - 1) * parseInt(this.perPage) + 1;
        },

        get endRange() {
            return Math.min(this.currentPage * parseInt(this.perPage), this.totalFiltered);
        },

        getYearCount(year) {
            return this.holidays.filter(h => new Date(h.tanggal).getFullYear() === year).length;
        },

        setPage(p) {
            if (p >= 1 && p <= this.totalPages) {
                this.currentPage = p;
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
            const parts = dateStr.split('-');
            if (parts.length !== 3) return dateStr;
            const year = parts[0];
            const monthIndex = parseInt(parts[1], 10) - 1;
            const day = parseInt(parts[2], 10);
            return `${day.toString().padStart(2, '0')} ${months[monthIndex]} ${year}`;
        }
    }" x-init="$watch('activeYear', () => { currentPage = 1; }); $watch('activeTipe', () => { currentPage = 1; }); $watch('searchQuery', () => { currentPage = 1; }); $watch('perPage', () => { currentPage = 1; });" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Hari Libur</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink font-sans">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink font-sans">Hari Libur</span>
                    <span>•</span>
                    <span class="text-muted italic font-sans">Akses: Khusus Super Admin</span>
                </nav>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button href="{{ route('audit-log') }}" variant="muted">
                    <svg class="w-4 h-4 mr-1.5 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                    Lihat Audit Log Master
                </x-ui.button>
                <button
                    @click="showAddForm = !showAddForm"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Tambah Hari Libur
                </button>
            </div>
        </div>

        {{-- Info Alert Card --}}
        <div class="rounded-lg border border-info/20 bg-info/5 p-4 flex gap-3 text-xs text-info leading-relaxed shadow-sm">
            <svg class="w-5 h-5 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 1 1 1.085 1.086L10.5 14.25a.75.75 0 0 1-1.086-1.085l1.086-1.086Zm.75-5.25a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5Zm-9 6c0 4.97 4.03 9 9 9s9-4.03 9-9-4.03-9-9-9-9 4.03-9 9Z" />
            </svg>
            <div>
                <span class="font-bold">ℹ️ PENTING UNTUK INTEGRITAS SISTEM:</span> Data hari libur nasional dan cuti bersama ini digunakan secara langsung oleh sistem untuk menghitung secara akurat jumlah **hari kerja efektif pengajuan cuti** pegawai serta menentukan jadwal pengiriman notifikasi/alert otomatis pada **Early Warning System (EWS)** kepegawaian.
            </div>
        </div>

        {{-- Form Tambah Hari Libur (Collapsible) --}}
        <x-ui.card padding="lg" x-show="showAddForm"   style="display: none;" class="space-y-4">
            <form action="{{ route('hari-libur.store') }}" method="POST" class="space-y-4">
                @csrf
                <h3 class="text-sm font-semibold text-primary font-sans">Tambah Hari Libur Baru</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-form.date
    name="tanggal"
    label="Tanggal"
    required
    size="lg"
/>
                    <x-form.input
    name="nama"
    label="Nama Hari Libur"
    type="text"
    placeholder="Contoh: Hari Raya Idul Fitri"
    required
    size="lg"
/>
                    <x-form.select
                        name="tipe"
                        label="Jenis Libur"
                        required
                    >
                        <option value="libur_nasional">Libur Nasional</option>
                        <option value="cuti_bersama">Cuti Bersama</option>
                    </x-form.select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button type="button" variant="link" size="xs" @click="showAddForm = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="xs">Simpan</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- Filter Bar --}}
        <x-ui.card padding="sm" class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            {{-- Tabs Tahun --}}
            <div class="flex items-center gap-4 border-b border-border pb-2 md:border-b-0 md:pb-0">
                <button @click="activeYear = 2026" :class="activeYear === 2026 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink'" class="text-sm pb-1 font-sans cursor-pointer focus:outline-none">
                    2026 (<span x-text="getYearCount(2026)"></span>)
                </button>
                <button @click="activeYear = 2025" :class="activeYear === 2025 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink'" class="text-sm pb-1 font-sans cursor-pointer focus:outline-none">
                    2025 (<span x-text="getYearCount(2025)"></span>)
                </button>
                <button @click="activeYear = 2024" :class="activeYear === 2024 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink'" class="text-sm pb-1 font-sans cursor-pointer focus:outline-none">
                    2024 (<span x-text="getYearCount(2024)"></span>)
                </button>
            </div>

            {{-- Search & Tipe Filters --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                {{-- Search --}}
                <div class="relative w-full sm:w-64">
                    <input type="text" x-model="searchQuery" placeholder="Cari nama hari libur..." class="w-full rounded-lg border border-border bg-surface pl-9 pr-4 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.602 10.602Z" />
                        </svg>
                    </div>
                </div>
                
                {{-- Tipe Dropdown --}}
                <div class="flex items-center gap-2">
                    <label class="text-xs text-muted font-sans font-medium whitespace-nowrap">Tipe Libur:</label>
                    <select x-model="activeTipe" class="rounded-lg border border-border bg-surface px-3 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <option value="semua">Semua Tipe</option>
                        <option value="libur_nasional">Libur Nasional</option>
                        <option value="cuti_bersama">Cuti Bersama</option>
                    </select>
                </div>
            </div>
        </x-ui.card>

        {{-- Table --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Tanggal</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Hari</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Nama Hari Libur</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Jenis Libur</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Tahun</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        <template x-for="(h, index) in paginatedHolidays" :key="h.id">
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td x-text="formatDate(h.tanggal)" padding="comfortable" class="text-sm font-medium"></x-ui.table-td>
                                <x-ui.table-td x-text="h.hari" padding="comfortable" class="text-sm"></x-ui.table-td>
                                <x-ui.table-td x-text="h.nama" padding="comfortable" class="text-sm font-medium"></x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <span class="text-xs font-semibold font-sans"
                                          :class="h.tipe === 'libur_nasional' ? 'text-primary' : 'text-secondary'"
                                          x-text="h.tipe === 'libur_nasional' ? 'Libur Nasional' : 'Cuti Bersama'">
                                    </span>
                                </x-ui.table-td>
                                <x-ui.table-td x-text="new Date(h.tanggal).getFullYear()" padding="comfortable" class="text-sm text-muted font-mono"></x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <div class="flex items-center gap-1.5">
                                        {{-- Edit Button --}}
                                        <x-ui.button as="a" x-bind:href="'/hari-libur/' + h.id + '/edit'" variant="secondary" size="icon" title="Edit Hari Libur" aria-label="Edit Hari Libur">
                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                            </svg>
                                        </x-ui.button>
                                        {{-- Delete Button --}}
                                        <form :action="'/hari-libur/' + h.id + '/delete'" method="POST" class="inline" @submit="return confirm('Apakah Anda yakin ingin menghapus hari libur \'' + h.nama + '\'?')">
                                            @csrf
                                            <x-ui.button type="submit" variant="danger" size="icon" title="Hapus Hari Libur" aria-label="Hapus Hari Libur">
                                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </x-ui.button>
                                        </form>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        </template>
                        {{-- Empty state --}}
                        <x-ui.table-row x-show="totalFiltered === 0">
                            <x-ui.table-td colspan="6" align="center" class="px-6 py-8 text-muted bg-surface">
                                Tidak ada data hari libur untuk filter yang dipilih.
                            </x-ui.table-td>
                        </x-ui.table-row>
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-xs text-muted font-sans">
                        Menampilkan <span class="font-semibold text-ink" x-text="startRange"></span> - <span class="font-semibold text-ink" x-text="endRange"></span> dari <span class="font-semibold text-ink" x-text="totalFiltered"></span> data
                    </p>
                    <div class="relative">
                        <select x-model="perPage" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option value="10">10 / halaman</option>
                            <option value="25">25 / halaman</option>
                            <option value="50">50 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Pagination Control --}}
                <div class="flex items-center gap-1.5" x-show="totalPages > 1">
                    {{-- Prev --}}
                    <x-ui.button type="button" variant="muted" size="icon" @click="setPage(currentPage - 1)" x-bind:disabled="currentPage === 1"
                            x-bind:class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-soft hover:text-ink'"
                            aria-label="Halaman sebelumnya">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </x-ui.button>
                    
                    {{-- Pages --}}
                    <template x-for="p in totalPages" :key="p">
                        <button @click="setPage(p)"
                                :class="currentPage === p ? 'border-primary bg-primary text-white hover:opacity-90' : 'border-border bg-surface text-muted hover:bg-soft hover:text-ink'"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border text-sm transition font-medium cursor-pointer"
                                x-text="p">
                        </button>
                    </template>
                    
                    {{-- Next --}}
                    <x-ui.button type="button" variant="muted" size="icon" @click="setPage(currentPage + 1)" x-bind:disabled="currentPage === totalPages"
                            x-bind:class="currentPage === totalPages ? 'opacity-40 cursor-not-allowed' : 'hover:bg-soft hover:text-ink'"
                            aria-label="Halaman berikutnya">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

    </div>

</x-layouts.app>
