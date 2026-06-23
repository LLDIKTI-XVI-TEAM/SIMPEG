<x-layouts.app title="Data Pegawai">

    @php
    $pegawaiData = [
        [
            'id'=>1,
            'nama'=>'Ahmad Fauzi', 'email'=>'ahmadfauzi@gmail.com',
            'golongan'=>'III/c', 'jabatan'=>'Analis Kepegawaian',
            'kelas_jabatan'=>'8', 'nip'=>'19850312201001 1 001',
            'telepon'=>'081234567890', 'pangkat'=>'Penata Tkt. I',
            'pendidikan'=>'S1', 'tgl_lahir'=>'March 12, 1985',
            'pensiun'=>'Abd Rahim Har', 'atasan'=>'Abd Rahim Har',
            'person_familia'=>'Abd Rahim Har', 'prodi'=>'Manajemen',
            'jenis'=>'PNS', 'status'=>'aktif', 'tmt'=>'01-10-2010',
            'unit'=>'Bag. Umum', 'dok'=>'ok',
        ],
        [
            'id'=>2,
            'nama'=>'Siti Rahayu', 'email'=>'sitirahayu@gmail.com',
            'golongan'=>'II/d', 'jabatan'=>'Analis Ahli Madya',
            'kelas_jabatan'=>'8', 'nip'=>'19901120201501 2 003',
            'telepon'=>'085298765432', 'pangkat'=>'Pemula Tkt. I',
            'pendidikan'=>'S2', 'tgl_lahir'=>'November 20, 1990',
            'pensiun'=>'Riza Hamzah', 'atasan'=>'Riza Hamzah',
            'person_familia'=>'Riza Hamzah', 'prodi'=>'Administrasi Pemerintahan',
            'jenis'=>'PNS', 'status'=>'aktif', 'tmt'=>'01-01-2015',
            'unit'=>'Bag. Keuangan', 'dok'=>'warn',
        ],
        [
            'id'=>3,
            'nama'=>'Sabrina Rossa Adriani Wibowo', 'email'=>'sabrinarossa24@gmail.com',
            'golongan'=>'III/a', 'jabatan'=>'Analis SDM Aparatur Ahli Pertama',
            'kelas_jabatan'=>'8', 'nip'=>'20261210820500 0 04',
            'telepon'=>'081285066001', 'pangkat'=>'Pemula Tkt. I',
            'pendidikan'=>'S1', 'tgl_lahir'=>'October 12, 1998',
            'pensiun'=>'Sabrina Rossa', 'atasan'=>'Sabrina Rossa',
            'person_familia'=>'Sabrina Rossa', 'prodi'=>'Informatika',
            'jenis'=>'PNS', 'status'=>'aktif', 'tmt'=>'01-01-2026',
            'unit'=>'Bag. SDM', 'dok'=>'ok',
        ],
        [
            'id'=>4,
            'nama'=>'Cimma Sari Oktariani Di Silapu', 'email'=>'sikaemma@gmail.com',
            'golongan'=>'III/c', 'jabatan'=>'Pranata SDM Terampil',
            'kelas_jabatan'=>'6', 'nip'=>'26110820520600 0 04',
            'telepon'=>'081258206006', 'pangkat'=>'Pengatur DO',
            'pendidikan'=>'S1', 'tgl_lahir'=>'October 28, 2001',
            'pensiun'=>'Cimma Sari Oktariani Di', 'atasan'=>'Cimma Sari Oktariani Di',
            'person_familia'=>'Cimma Sari Oktariani Di', 'prodi'=>'Manajemen Informatika',
            'jenis'=>'PNS', 'status'=>'aktif', 'tmt'=>'01-10-2020',
            'unit'=>'Bag. IT', 'dok'=>'danger',
        ],
        [
            'id'=>5,
            'nama'=>'Nurarningsih Dumbea, S.P.', 'email'=>'rainingdumbea47@gmail.com',
            'golongan'=>'III/b', 'jabatan'=>'Pejabat Lelang Operational',
            'kelas_jabatan'=>'7', 'nip'=>'19880123202 1 005',
            'telepon'=>'082302200526', 'pangkat'=>'Penata Tkt. I',
            'pendidikan'=>'S1', 'tgl_lahir'=>'January 23, 1988',
            'pensiun'=>'Naning Dumbea', 'atasan'=>'Naning Dumbea',
            'person_familia'=>'Faria Dana Puri', 'prodi'=>'Agribisnis',
            'jenis'=>'PPPK', 'status'=>'aktif', 'tmt'=>'01-11-2021',
            'unit'=>'Bag. Umum', 'dok'=>'ok',
        ],
        [
            'id'=>6,
            'nama'=>'Nadia Kusuma', 'email'=>'nadiakusuma@gmail.com',
            'golongan'=>'II/c', 'jabatan'=>'Pengelola Kepegawaian',
            'kelas_jabatan'=>'6', 'nip'=>'19950822202001 2 002',
            'telepon'=>'081299887766', 'pangkat'=>'Pengatur',
            'pendidikan'=>'S1', 'tgl_lahir'=>'August 22, 1995',
            'pensiun'=>'Nadia Kusuma', 'atasan'=>'Nadia Kusuma',
            'person_familia'=>'Nadia Kusuma', 'prodi'=>'Ilmu Pemerintahan',
            'jenis'=>'PPPK', 'status'=>'cuti', 'tmt'=>'01-01-2020',
            'unit'=>'Bag. SDM', 'dok'=>'ok',
        ],
        [
            'id'=>7,
            'nama'=>'Yucna Dara, S.P., M.M.', 'email'=>'hanaryog101@gmail.com',
            'golongan'=>'III/b', 'jabatan'=>'Analis Ahli Pertama',
            'kelas_jabatan'=>'8', 'nip'=>'19840120099 2 002',
            'telepon'=>'081284920002', 'pangkat'=>'Penata Tkt. I',
            'pendidikan'=>'S2', 'tgl_lahir'=>'January 20, 1984',
            'pensiun'=>'Yucna Dara', 'atasan'=>'Ingat Gobel',
            'person_familia'=>'Ingat Gobel', 'prodi'=>'Teknik Informatika',
            'jenis'=>'PNS', 'status'=>'aktif', 'tmt'=>'01-05-1984',
            'unit'=>'Bag. Keuangan', 'dok'=>'warn',
        ]
    ];
    @endphp

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
                    <tr class="transition-colors hover:bg-soft/50" data-nama="{{ $p['nama'] }}" data-nip="{{ $p['nip'] }}" data-unit="{{ $p['unit'] }}" data-jenis="{{ $p['jenis'] }}" data-status="{{ $p['status'] }}" data-golongan="{{ $p['golongan'] }}">
                        <td class="px-4 py-3.5">
                            <input type="checkbox" class="row-check h-4 w-4 rounded border-border text-primary focus:ring-primary/20">
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                    {{ strtoupper(substr($p['nama'], 0, 1)) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink">{{ $p['nama'] }}</p>
                                    <p class="font-mono text-xs text-muted">{{ $p['nip'] }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm font-medium text-ink">{{ $p['jabatan'] }}</p>
                            <p class="text-xs text-muted">{{ $p['unit'] }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm font-bold text-ink leading-tight">{{ $p['golongan'] }}</p>
                            <p class="text-xs font-bold text-primary mt-0.5 leading-tight">{{ $p['jenis'] }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm text-ink font-mono">{{ $p['tmt'] }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
                            $statusClasses = [
                                'aktif' => 'bg-success/10 text-success',
                                'cuti' => 'bg-warning/10 text-warning',
                                'nonaktif' => 'bg-danger/10 text-danger'
                            ];
                            $statusDots = [
                                'aktif' => 'bg-success',
                                'cuti' => 'bg-warning',
                                'nonaktif' => 'bg-danger'
                            ];
                            $statusLabel = [
                                'aktif' => 'Aktif',
                                'cuti' => 'Cuti',
                                'nonaktif' => 'Nonaktif'
                            ];
                            $stClass = $statusClasses[$p['status']] ?? 'bg-soft text-muted';
                            $stDot = $statusDots[$p['status']] ?? 'bg-muted';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $stClass }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $stDot }}"></span>
                                {{ $statusLabel[$p['status']] ?? $p['status'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
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
                            $dkClass = $dokClasses[$p['dok']] ?? 'bg-soft text-muted';
                            $dkDot = $dokDots[$p['dok']] ?? 'bg-muted';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $dkClass }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dkDot }}"></span>
                                {{ $dokLabels[$p['dok']] ?? $p['dok'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                {{-- Detail --}}
                                <a href="{{ route('pegawai.show', ['id' => $p['id']]) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                                {{-- Edit --}}
                                <a href="{{ route('pegawai.edit', $p['id']) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                    </svg>
                                </a>
                                {{-- Nonaktifkan --}}
                                <form action="{{ route('pegawai.destroy', $p['id']) }}" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menonaktifkan pegawai ini?')">
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
        <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
            <div class="flex items-center gap-3">
                <p id="pegawai-count-text" class="text-sm text-muted">Menampilkan 1 - 7 dari 7 data aktif</p>
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
                <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-primary bg-primary text-sm font-semibold text-white transition hover:opacity-90">1</button>
                {{-- Next --}}
                <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
            </div>
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

    // Client-side CSV/Excel export based on visible filtered data
    function exportFilteredData() {
        const rows = document.querySelectorAll('tbody tr');
        let csvContent = "data:text/csv;charset=utf-8,";
        
        // Header
        csvContent += "No,Nama,NIP,Jabatan,Unit Kerja,Golongan,Jenis Pegawai,Status\n";
        
        let count = 1;
        rows.forEach(row => {
            if (row.style.display !== 'none' && row.getAttribute('data-nama')) {
                const nama = row.getAttribute('data-nama');
                const nip = row.getAttribute('data-nip');
                const unit = row.getAttribute('data-unit');
                const jenis = row.getAttribute('data-jenis');
                const status = row.getAttribute('data-status');
                const golongan = row.getAttribute('data-golongan');
                
                // Cari jabatan dari kolom
                const jabatanEl = row.querySelector('td:nth-child(3) p:first-child');
                const jabatan = jabatanEl ? jabatanEl.textContent.trim() : '';

                csvContent += `"${count}","${nama}","${nip}","${jabatan}","${unit}","${golongan}","${jenis}","${status}"\n`;
                count++;
            }
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Data_Pegawai_Filtered_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
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
