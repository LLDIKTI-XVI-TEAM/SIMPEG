<x-layouts.app title="Cuti Pegawai">

    @php
    $riwayatCuti = [
        [
            'id' => 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19850312201001 1 001',
            'unit' => 'Bag. Umum',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-06-20',
            'selesai' => '2026-06-24',
            'hari' => 5,
            'status' => 'menunggu',
            'tgl_pengajuan' => '2026-06-18',
            'alasan' => 'Acara keluarga di luar kota',
            'stage_atasan' => 'menunggu',
            'stage_kepala' => 'menunggu',
            'periode' => 'Juni 2026'
        ],
        [
            'id' => 2,
            'nama' => 'Siti Rahayu',
            'nip' => '19901120201501 2 003',
            'unit' => 'Bag. Keuangan',
            'jenis' => 'Cuti Sakit',
            'mulai' => '2026-04-10',
            'selesai' => '2026-04-12',
            'hari' => 3,
            'status' => 'disetujui',
            'tgl_pengajuan' => '2026-04-09',
            'alasan' => 'Sakit demam berdarah',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'disetujui',
            'periode' => 'April 2026'
        ],
        [
            'id' => 3,
            'nama' => 'Sabrina Rossa Adriani Wibowo',
            'nip' => '20261210820500 0 04',
            'unit' => 'Bag. SDM',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-02-01',
            'selesai' => '2026-02-05',
            'hari' => 5,
            'status' => 'ditunda',
            'tgl_pengajuan' => '2026-01-28',
            'alasan' => 'Menunggu konfirmasi pengganti tugas',
            'stage_atasan' => 'ditunda',
            'stage_kepala' => 'menunggu',
            'periode' => 'Februari 2026'
        ],
        [
            'id' => 4,
            'nama' => 'Cimma Sari Oktariani Di Silapu',
            'nip' => '26110820520600 0 04',
            'unit' => 'Bag. IT',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-06-22',
            'selesai' => '2026-06-26',
            'hari' => 5,
            'status' => 'disetujui',
            'tgl_pengajuan' => '2026-06-19',
            'alasan' => 'Cuti liburan tahunan',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'disetujui',
            'periode' => 'Juni 2026'
        ],
        [
            'id' => 5,
            'nama' => 'Nurarningsih Dumbea, S.P.',
            'nip' => '19880123202 1 005',
            'unit' => 'Bag. Umum',
            'jenis' => 'Cuti Melahirkan',
            'mulai' => '2025-10-01',
            'selesai' => '2025-12-29',
            'hari' => 90,
            'status' => 'disetujui',
            'tgl_pengajuan' => '2025-09-15',
            'alasan' => 'Persalinan anak pertama',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'disetujui',
            'periode' => 'Oktober 2025'
        ],
        [
            'id' => 6,
            'nama' => 'Nadia Kusuma',
            'nip' => '19950822202001 2 002',
            'unit' => 'Bag. SDM',
            'jenis' => 'Cuti Sakit',
            'mulai' => '2026-06-25',
            'selesai' => '2026-06-27',
            'hari' => 3,
            'status' => 'menunggu',
            'tgl_pengajuan' => '2026-06-23',
            'alasan' => 'Sakit migrain berat',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'menunggu',
            'periode' => 'Juni 2026'
        ],
        [
            'id' => 7,
            'nama' => 'Yucna Dara, S.P., M.M.',
            'nip' => '19840120099 2 002',
            'unit' => 'Bag. Keuangan',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-06-27',
            'selesai' => '2026-07-01',
            'hari' => 5,
            'status' => 'ditunda',
            'tgl_pengajuan' => '2026-06-24',
            'alasan' => 'Ada audit internal keuangan',
            'stage_atasan' => 'ditunda',
            'stage_kepala' => 'menunggu',
            'periode' => 'Juni 2026'
        ]
    ];

    $statusFilter = request()->query('status');
    if ($statusFilter === 'pending') {
        $riwayatCuti = array_filter($riwayatCuti, function($item) {
            return $item['status'] === 'menunggu';
        });
    }

    $statusClass = [
        'menunggu'  => 'text-warning',
        'disetujui' => 'text-success',
        'ditunda'   => 'text-danger',
    ];

    $statusDot = [
        'menunggu'  => 'bg-warning',
        'disetujui' => 'bg-success',
        'ditunda'   => 'bg-danger',
    ];

    $statusLabel = [
        'menunggu'  => 'Menunggu',
        'disetujui' => 'Disetujui',
        'ditunda'   => 'Ditunda',
    ];

    $stageClass = [
        'menunggu' => 'bg-soft text-muted',
        'disetujui' => 'bg-success/10 text-success',
        'ditunda' => 'bg-danger/10 text-danger',
    ];

    $stageLabel = [
        'menunggu' => 'Menunggu',
        'disetujui' => 'Disetujui',
        'ditunda' => 'Ditunda',
    ];
    @endphp

    <div class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Monitoring Cuti Pegawai</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Cuti</span>
                </nav>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <button
                    onclick="exportCutiData()"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    Export Laporan Cuti
                </button>
            </div>
        </div>

        {{-- METRICS SUMMARY CARD (GLOBAL MONITORING) --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
            {{-- Pending --}}
            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Menunggu Persetujuan</p>
                        <p class="mt-1.5 text-2xl font-extrabold text-warning leading-none font-mono">2</p>
                    </div>
                    <div class="rounded-lg bg-warning/10 p-2.5 shrink-0">
                        <svg class="w-6 h-6 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                </div>
            </div>

            {{-- Approved --}}
            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Disetujui (Bulan Ini)</p>
                        <p class="mt-1.5 text-2xl font-extrabold text-success leading-none font-mono">3</p>
                    </div>
                    <div class="rounded-lg bg-success/10 p-2.5 shrink-0">
                        <svg class="w-6 h-6 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                </div>
            </div>

            {{-- Postponed --}}
            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Ditunda</p>
                        <p class="mt-1.5 text-2xl font-extrabold text-danger leading-none font-mono">2</p>
                    </div>
                    <div class="rounded-lg bg-danger/10 p-2.5 shrink-0">
                        <svg class="w-6 h-6 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                    </div>
                </div>
            </div>

            {{-- Total Cuti Active --}}
            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Staf Cuti</p>
                        <p class="mt-1.5 text-2xl font-extrabold text-primary leading-none font-mono">7</p>
                    </div>
                    <div class="rounded-lg bg-primary/10 p-2.5 shrink-0">
                        <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- FILTER BAR --}}
        <div class="rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {{-- Search input --}}
                <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 col-span-1 sm:col-span-2 lg:col-span-1">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input id="search-cuti" type="text" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>

                {{-- Filter Status --}}
                <div class="relative">
                    <select id="filter-status" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Status</option>
                        <option value="menunggu">Menunggu</option>
                        <option value="disetujui">Disetujui</option>
                        <option value="ditunda">Ditunda</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Jenis Cuti --}}
                <div class="relative">
                    <select id="filter-jenis" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Jenis Cuti</option>
                        <option>Cuti Tahunan</option>
                        <option>Cuti Sakit</option>
                        <option>Cuti Melahirkan</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                {{-- Filter Unit Kerja --}}
                <div class="relative">
                    <select id="filter-unit" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Unit Kerja</option>
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

                {{-- Filter Periode Bulan --}}
                <div class="relative">
                    <select id="filter-periode" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Periode</option>
                        <option value="Juni 2026">Juni 2026</option>
                        <option value="April 2026">April 2026</option>
                        <option value="Februari 2026">Februari 2026</option>
                        <option value="Oktober 2025">Oktober 2025</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- TABLE CARD --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Pemantauan Pengajuan Cuti Pegawai</h3>
                    <p class="text-[10px] text-muted font-sans">Daftar semua pengajuan cuti yang diajukan oleh staf</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full" id="cuti-table">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Pegawai</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Unit Kerja</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Detail Cuti</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Tanggal & Durasi</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Stage Approval</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Status Akhir</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted font-sans select-none">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach($riwayatCuti as $r)
                        <tr class="transition-colors hover:bg-soft/50" data-nama="{{ $r['nama'] }}" data-nip="{{ $r['nip'] }}" data-unit="{{ $r['unit'] }}" data-jenis="{{ $r['jenis'] }}" data-status="{{ $r['status'] }}" data-periode="{{ $r['periode'] }}">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                        {{ strtoupper(substr($r['nama'], 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink font-sans">{{ $r['nama'] }}</p>
                                        <p class="font-mono text-xs text-muted">{{ $r['nip'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="text-sm text-ink font-sans">{{ $r['unit'] }}</span>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="text-sm font-semibold text-ink font-sans">{{ $r['jenis'] }}</p>
                                <p class="text-xs text-muted font-sans mt-0.5 max-w-xs truncate" title="{{ $r['alasan'] }}">{{ $r['alasan'] }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="text-sm text-ink font-mono">{{ \Carbon\Carbon::parse($r['mulai'])->translatedFormat('d M') }} - {{ \Carbon\Carbon::parse($r['selesai'])->translatedFormat('d M Y') }}</p>
                                <p class="text-xs text-primary font-semibold mt-0.5 leading-none">{{ $r['hari'] }} Hari Kerja</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex flex-col gap-1 text-[11px] font-medium text-ink font-sans">
                                    <div class="flex items-center gap-1.5">
                                        @php
                                        $dotAtasan = $r['stage_atasan'] === 'disetujui' ? 'bg-success' : ($r['stage_atasan'] === 'ditunda' ? 'bg-danger' : 'bg-muted');
                                        @endphp
                                        <span class="h-1.5 w-1.5 rounded-full {{ $dotAtasan }}"></span>
                                        <span>Atasan: <strong class="capitalize">{{ $r['stage_atasan'] }}</strong></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        @php
                                        $dotKepala = $r['stage_kepala'] === 'disetujui' ? 'bg-success' : ($r['stage_kepala'] === 'ditunda' ? 'bg-danger' : 'bg-muted');
                                        @endphp
                                        <span class="h-1.5 w-1.5 rounded-full {{ $dotKepala }}"></span>
                                        <span>Kepala: <strong class="capitalize">{{ $r['stage_kepala'] }}</strong></span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $statusClass[$r['status']] }} font-sans">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $statusDot[$r['status']] }}"></span>
                                    {{ $statusLabel[$r['status']] }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="{{ route('cuti.show', $r['id']) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p id="cuti-count-text" class="text-sm text-muted font-sans">Menampilkan 1 - 7 dari 7 data</p>
                    <div class="relative">
                        <select id="per-page" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                            <option>10 / halaman</option>
                            <option>25 / halaman</option>
                            <option>50 / halaman</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
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
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink font-sans">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

    </div>

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('search-cuti');
        const filterStatus = document.getElementById('filter-status');
        const filterJenis = document.getElementById('filter-jenis');
        const filterUnit = document.getElementById('filter-unit');
        const filterPeriode = document.getElementById('filter-periode');

        function applyCutiFilters() {
            const query = searchInput.value.toLowerCase();
            const status = filterStatus.value;
            const jenis = filterJenis.value;
            const unit = filterUnit.value;
            const periode = filterPeriode.value;

            const rows = document.querySelectorAll('#cuti-table tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const rNama = row.getAttribute('data-nama');
                if (!rNama) return;
                const rNip = row.getAttribute('data-nip').toLowerCase();
                const rUnit = row.getAttribute('data-unit');
                const rJenis = row.getAttribute('data-jenis');
                const rStatus = row.getAttribute('data-status');
                const rPeriode = row.getAttribute('data-periode');

                const matchesSearch = rNama.toLowerCase().includes(query) || rNip.includes(query);
                const matchesStatus = !status || rStatus === status;
                const matchesJenis = !jenis || rJenis === jenis;
                const matchesUnit = !unit || rUnit === unit;
                const matchesPeriode = !periode || rPeriode === periode;

                if (matchesSearch && matchesStatus && matchesJenis && matchesUnit && matchesPeriode) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update showing count text
            const countText = document.getElementById('cuti-count-text');
            if (countText) {
                countText.textContent = `Menampilkan 1 - ${visibleCount} dari ${visibleCount} data`;
            }
        }

        if (searchInput) searchInput.addEventListener('input', applyCutiFilters);
        if (filterStatus) filterStatus.addEventListener('change', applyCutiFilters);
        if (filterJenis) filterJenis.addEventListener('change', applyCutiFilters);
        if (filterUnit) filterUnit.addEventListener('change', applyCutiFilters);
        if (filterPeriode) filterPeriode.addEventListener('change', applyCutiFilters);

        // Pre-apply filter if status query exists
        const urlParams = new URLSearchParams(window.location.search);
        const statusParam = urlParams.get('status');
        if (statusParam === 'pending' && filterStatus) {
            filterStatus.value = 'menunggu';
            applyCutiFilters();
        }
    });

    // Client-side CSV export
    function exportCutiData() {
        const rows = document.querySelectorAll('#cuti-table tbody tr');
        let csvContent = "data:text/csv;charset=utf-8,";
        
        // Header
        csvContent += "No,Nama,NIP,Unit Kerja,Jenis Cuti,Tanggal Mulai,Tanggal Selesai,Durasi (Hari Kerja),Status\n";
        
        let count = 1;
        rows.forEach(row => {
            if (row.style.display !== 'none' && row.getAttribute('data-nama')) {
                const nama = row.getAttribute('data-nama');
                const nip = row.getAttribute('data-nip');
                const unit = row.getAttribute('data-unit');
                const jenis = row.getAttribute('data-jenis');
                const status = row.getAttribute('data-status');
                
                // Cari durasi
                const durasiEl = row.querySelector('td:nth-child(4) p:last-child');
                const durasi = durasiEl ? durasiEl.textContent.replace(' Hari Kerja', '').trim() : '';

                // Cari tanggal
                const tglEl = row.querySelector('td:nth-child(4) p:first-child');
                const tglString = tglEl ? tglEl.textContent.trim() : '';
                const parts = tglString.split(' - ');
                const mulai = parts[0] || '';
                const selesai = parts[1] || '';

                csvContent += `"${count}","${nama}","${nip}","${unit}","${jenis}","${mulai}","${selesai}","${durasi}","${status}"\n`;
                count++;
            }
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Laporan_Cuti_Pegawai_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    </script>
    @endpush

</x-layouts.app>
