<x-layouts.app title="Data Pegawai">



    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
            <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <span class="font-medium text-ink">Data Pegawai</span>
            </nav>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            {{-- Export button --}}
            <button
                onclick="exportFilteredData()"
                id="export-btn"
                class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Excel
            </button>
            <a
                href="{{ route('data-nonaktif') }}"
                id="nonaktif-list-btn"
                class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-4 py-2 text-sm font-semibold text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-danger shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
                </svg>
                Pegawai Nonaktif
            </a>
            <a
                href="{{ route('pegawai.create') }}"
                id="add-pegawai-btn"
                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 animate-fade-in"
            >
                <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Tambah Pegawai
            </a>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <div class="mb-6 rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {{-- Search input --}}
            <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input id="search-input" type="text" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
            </div>
            
            {{-- Filter Golongan --}}
            <div class="relative">
                <select id="filter-golongan" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Golongan</option>
                    <option value="IV">Golongan IV</option>
                    <option value="III">Golongan III</option>
                    <option value="II">Golongan II</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Unit --}}
            <div class="relative">
                <select id="filter-unit" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit</option>
                    <option>Bag. Umum</option>
                    <option>Bag. Keuangan</option>
                    <option>Bag. SDM</option>
                    <option>Bag. IT</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Jenis --}}
            <div class="relative">
                <select id="filter-jenis" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    <option>PNS</option>
                    <option>PPPK</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>

            {{-- Filter Status --}}
            <div class="relative">
                <select id="filter-status" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Status</option>
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Non-Aktif</option>
                    <option value="cuti">Cuti</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>
        </div>
    </div>

    {{-- TABLE --}}
    <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full" id="pegawai-table">
                <thead class="bg-soft">
                    <tr>
                        <th class="w-10 px-4 py-3 select-none">
                            <input id="check-all" type="checkbox" class="h-4 w-4 rounded border-border text-primary focus:ring-primary/20">
                        </th>
                        <th onclick="sortTable(1)" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted cursor-pointer hover:text-ink transition-colors select-none">
                            <span class="flex items-center gap-1">
                                Pegawai
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </span>
                        </th>
                        <th onclick="sortTable(2)" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted cursor-pointer hover:text-ink transition-colors select-none">
                            <span class="flex items-center gap-1">
                                Jabatan & Unit
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </span>
                        </th>
                        <th onclick="sortTable(3)" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted cursor-pointer hover:text-ink transition-colors select-none">
                            <span class="flex items-center gap-1">
                                Gol. / Jenis
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </span>
                        </th>
                        <th onclick="sortTable(4)" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted cursor-pointer hover:text-ink transition-colors select-none">
                            <span class="flex items-center gap-1">
                                TMT
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </span>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">Dokumen</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($pegawaiData as $p)
                    <tr class="transition-colors hover:bg-soft/50" data-nama="{{ $p->nama_lengkap }}" data-nip="{{ $p->nip }}" data-unit="{{ $p->jabatan_terakhir ?? '-' }}" data-jenis="{{ $p->jenisPegawai->nama ?? '-' }}" data-status="{{ strtolower($p->status_aktif) }}" data-golongan="{{ $p->golongan_terakhir ?? '-' }}">
                        <td class="px-4 py-3.5">
                            <input type="checkbox" class="row-check h-4 w-4 rounded border-border text-primary focus:ring-primary/20">
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                    {{ strtoupper(substr($p->nama_lengkap, 0, 1)) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink">{{ $p->nama_lengkap }}</p>
                                    <p class="font-mono text-xs text-muted">{{ $p->nip }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm font-medium text-ink">{{ $p->jabatan_terakhir ?? '-' }}</p>
                            <p class="text-xs text-muted">{{ $p->jabatan_terakhir ?? '-' }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm font-bold text-ink leading-tight">{{ $p->golongan_terakhir ?? '-' }}</p>
                            <p class="text-xs font-bold text-primary mt-0.5 leading-tight">{{ $p->jenisPegawai->nama ?? '-' }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm text-ink font-mono">-</p>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
                            $status_lower = strtolower($p->status_aktif);
                            $statusClasses = [
                                'aktif' => 'bg-success/10 text-success',
                                'cuti' => 'bg-warning/10 text-warning',
                                'non-aktif' => 'bg-danger/10 text-danger',
                                'pensiun' => 'bg-danger/10 text-danger',
                                'mutasi' => 'bg-warning/10 text-warning'
                            ];
                            $statusDots = [
                                'aktif' => 'bg-success',
                                'cuti' => 'bg-warning',
                                'non-aktif' => 'bg-danger',
                                'pensiun' => 'bg-danger',
                                'mutasi' => 'bg-warning'
                            ];
                            $stClass = $statusClasses[$status_lower] ?? 'bg-soft text-muted';
                            $stDot = $statusDots[$status_lower] ?? 'bg-muted';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $stClass }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $stDot }}"></span>
                                {{ $p->status_aktif }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
                            // Default mock for 'dok' since we don't have it on model
                            $dok = 'ok';
                            $dokClasses = [
                                'ok' => 'bg-success/10 text-success',
                                'warn' => 'bg-warning/10 text-warning',
                                'danger' => 'bg-danger/10 text-danger'
                            ];
                            $dokDots = [
                                'ok' => 'bg-success',
                                'warn' => 'bg-warning',
                                'danger' => 'bg-danger'
                            ];
                            $dokLabels = [
                                'ok' => 'Lengkap',
                                'warn' => 'H-60',
                                'danger' => 'H-30'
                            ];
                            $dkClass = $dokClasses[$dok] ?? 'bg-soft text-muted';
                            $dkDot = $dokDots[$dok] ?? 'bg-muted';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $dkClass }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dkDot }}"></span>
                                {{ $dokLabels[$dok] ?? $dok }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                {{-- Detail --}}
                                <a href="{{ route('pegawai.show', $p->id) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                                {{-- Edit --}}
                                <a href="{{ route('pegawai.edit', $p->id) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                    </svg>
                                </a>
                                {{-- Nonaktifkan --}}
                                <form action="{{ route('pegawai.destroy', $p->id) }}" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menonaktifkan pegawai ini?')">
                                    @csrf
                                    <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Nonaktifkan">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- TABLE FOOTER --}}
        <div class="border-t border-border px-6 py-4 bg-surface">
            {{ $pegawaiData->links() }}
        </div>
    </div>

    {{-- BULK ACTION FLOATING BAR --}}
    <div id="bulk-bar" class="fixed bottom-6 left-1/2 z-40 hidden -translate-x-1/2 items-center gap-3 rounded-lg border border-border bg-surface px-6 py-3.5 shadow-lg">
        <p class="text-sm font-semibold text-ink"><span id="selected-count">0</span> pegawai dipilih</p>
        <div class="h-4 w-px bg-border"></div>
        <button class="text-xs font-semibold text-warning hover:underline transition-colors cursor-pointer">Export Pilihan</button>
        <button class="text-xs font-semibold text-danger hover:underline transition-colors cursor-pointer">Nonaktifkan Pilihan</button>
        <button onclick="clearBulk()" class="text-xs font-semibold text-muted hover:text-ink hover:underline transition-colors cursor-pointer">Batal</button>
    </div>

    @push('scripts')
    <script>
    const searchInput = document.getElementById('search-input');
    const filterGolongan = document.getElementById('filter-golongan');
    const filterUnit = document.getElementById('filter-unit');
    const filterJenis = document.getElementById('filter-jenis');
    const filterStatus = document.getElementById('filter-status');

    function applyFilters() {
        const query = searchInput.value.toLowerCase();
        const golongan = filterGolongan.value;
        const unit = filterUnit.value;
        const jenis = filterJenis.value;
        const status = filterStatus.value;

        const rows = document.querySelectorAll('tbody tr');
        let visibleCount = 0;

        rows.forEach(row => {
            const rNama = row.getAttribute('data-nama');
            if (!rNama) return;
            const rNip = row.getAttribute('data-nip').toLowerCase();
            const rGolongan = row.getAttribute('data-golongan');
            const rUnit = row.getAttribute('data-unit');
            const rJenis = row.getAttribute('data-jenis');
            const rStatus = row.getAttribute('data-status');

            const matchesSearch = rNama.toLowerCase().includes(query) || rNip.includes(query);
            const matchesGolongan = !golongan || rGolongan.startsWith(golongan);
            const matchesUnit = !unit || rUnit === unit;
            const matchesJenis = !jenis || rJenis === jenis;
            const matchesStatus = !status || rStatus === status;

            if (matchesSearch && matchesGolongan && matchesUnit && matchesJenis && matchesStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        const countText = document.getElementById('pegawai-count-text');
        if (countText) {
            countText.textContent = `Menampilkan 1 - ${visibleCount} dari ${visibleCount} data aktif`;
        }
    }

    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (filterGolongan) filterGolongan.addEventListener('change', applyFilters);
    if (filterUnit) filterUnit.addEventListener('change', applyFilters);
    if (filterJenis) filterJenis.addEventListener('change', applyFilters);
    if (filterStatus) filterStatus.addEventListener('change', applyFilters);

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const filterParam = urlParams.get('filter');
        if (filterParam === 'pensiun') {
            if (searchInput) {
                searchInput.value = 'Siti Rahayu';
                applyFilters();
            }
        } else if (filterParam === 'ews') {
            // Saring Budi Santoso (Masa Berlaku SK Pengangkatan H-30/danger)
            if (searchInput) {
                searchInput.value = 'Ahmad Fauzi'; // salah satu yang EWS-nya aktif
                applyFilters();
            }
        } else {
            applyFilters();
        }
    });

    // Checkbox bulk
    const checkAll = document.getElementById('check-all');
    const bulkBar  = document.getElementById('bulk-bar');
    const countEl  = document.getElementById('selected-count');

    function updateBulk() {
        const n = document.querySelectorAll('.row-check:checked').length;
        countEl.textContent = n;
        bulkBar.classList.toggle('hidden', n === 0);
        bulkBar.classList.toggle('flex',   n > 0);
    }

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.row-check').forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = checkAll.checked;
                }
            });
            updateBulk();
        });
    }

    document.querySelectorAll('.row-check').forEach(cb => {
        cb.addEventListener('change', () => {
            const all    = document.querySelectorAll('.row-check').length;
            const ticked = document.querySelectorAll('.row-check:checked').length;
            if (checkAll) {
                checkAll.indeterminate = ticked > 0 && ticked < all;
                checkAll.checked       = ticked === all;
            }
            updateBulk();
        });
    });

    function clearBulk() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        if (checkAll) {
            checkAll.checked       = false;
            checkAll.indeterminate = false;
        }
        updateBulk();
    }

    // Export baris yang sedang terlihat melalui generator XLSX di backend.
    function exportFilteredData() {
        const visibleNips = Array.from(document.querySelectorAll('tbody tr'))
            .filter(row => row.style.display !== 'none' && row.dataset.nip)
            .map(row => row.dataset.nip);

        if (visibleNips.length === 0) {
            window.alert('Tidak ada data pegawai yang dapat diekspor.');
            return;
        }

        const exportUrl = new URL(@json(route('pegawai.export')), window.location.origin);
        visibleNips.forEach(nip => exportUrl.searchParams.append('nips[]', nip));
        window.location.href = exportUrl.toString();
    }

    // Sorting functionality
    let sortDirections = {};
    function sortTable(colIndex) {
        const table = document.getElementById("pegawai-table");
        const tbody = table.querySelector("tbody");
        const rows = Array.from(tbody.querySelectorAll("tr"));
        
        // Tentukan arah sort
        const dir = sortDirections[colIndex] === 'asc' ? 'desc' : 'asc';
        sortDirections = { [colIndex]: dir }; // Reset sort lainnya

        // Tampilkan indikator arah sort secara visual
        const headers = table.querySelectorAll("thead th");
        headers.forEach((th, idx) => {
            const svg = th.querySelector("svg");
            if (svg) {
                if (idx === colIndex) {
                    svg.style.transform = dir === 'asc' ? 'rotate(180deg)' : '';
                    svg.style.color = '#122E92'; // warna primary aktif
                } else {
                    svg.style.transform = '';
                    svg.style.color = '';
                }
            }
        });

        const sortedRows = rows.sort((a, b) => {
            let valA = "", valB = "";
            
            if (colIndex === 1) { // Pegawai (Nama)
                valA = a.getAttribute('data-nama') || '';
                valB = b.getAttribute('data-nama') || '';
            } else if (colIndex === 2) { // Jabatan & Unit
                valA = a.querySelector('td:nth-child(3) p:first-child').textContent.trim();
                valB = b.querySelector('td:nth-child(3) p:first-child').textContent.trim();
            } else if (colIndex === 3) { // Gol. / Jenis
                valA = a.getAttribute('data-golongan') || '';
                valB = b.getAttribute('data-golongan') || '';
            } else if (colIndex === 4) { // TMT
                // Ubah format DD-MM-YYYY ke YYYYMMDD agar mudah disortir
                const dateA = (a.querySelector('td:nth-child(5) p').textContent.trim()).split('-').reverse().join('');
                const dateB = (b.querySelector('td:nth-child(5) p').textContent.trim()).split('-').reverse().join('');
                valA = dateA;
                valB = dateB;
            }

            return dir === 'asc' 
                ? valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' })
                : valB.localeCompare(valA, undefined, { numeric: true, sensitivity: 'base' });
        });

        // Append yang sudah disortir
        tbody.innerHTML = "";
        sortedRows.forEach(row => tbody.appendChild(row));
        
        // Re-apply filter checkbox
        clearBulk();
    }
    </script>
    @endpush

</x-layouts.app>
