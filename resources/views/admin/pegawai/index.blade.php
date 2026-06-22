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
            'jenis'=>'CPNS', 'status'=>'aktif', 'tmt'=>'01-01-2026',
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
            'jenis'=>'CPNS', 'status'=>'aktif', 'tmt'=>'01-10-2020',
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
            'jenis'=>'PPNPN', 'status'=>'cuti', 'tmt'=>'01-01-2020',
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
        ],
        [
            'id'=>8,
            'nama'=>'Siraajuddin Laluv, OE., M.', 'email'=>'siraajuddinlaluv@gmail.com',
            'golongan'=>'IV/a', 'jabatan'=>'Pengolah Data dan Informasi',
            'kelas_jabatan'=>'7', 'nip'=>'19721231984 0 1062',
            'telepon'=>'081238500', 'pangkat'=>'Pembina',
            'pendidikan'=>'S1', 'tgl_lahir'=>'December 31, 1972',
            'pensiun'=>'Siraajuddin Laluv', 'atasan'=>'Siraajuddin Laluv',
            'person_familia'=>'Siraajuddin Laluv', 'prodi'=>'Manajemen',
            'jenis'=>'PNS', 'status'=>'nonaktif', 'tmt'=>'01-12-2003',
            'unit'=>'Bag. IT', 'dok'=>'ok',
        ],
    ];
    $statusBadge = ['aktif'=>'text-success','cuti'=>'text-warning','nonaktif'=>'text-danger'];
    $statusLabel = ['aktif'=>'Aktif','cuti'=>'Cuti','nonaktif'=>'Nonaktif'];
    $jenisBadge  = ['PNS'=>'text-primary','PPPK'=>'text-secondary','PPNPN'=>'text-info','CPNS'=>'text-info'];
    $dokDot      = ['ok'=>'bg-success','warn'=>'bg-warning','danger'=>'bg-danger'];
    $dokText     = ['ok'=>'text-success','warn'=>'text-warning','danger'=>'text-danger'];
    $dokLabel    = ['ok'=>'Lengkap','warn'=>'H-60','danger'=>'H-30'];
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
            <a
                href="{{ route('pegawai.export') }}"
                id="export-btn"
                class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm"
            >
                <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Excel
            </a>
            <a
                href="{{ route('pegawai.create') }}"
                id="add-pegawai-btn"
                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
            >
                <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Tambah Pegawai
            </a>
        </div>
    </div>



    {{-- FILTER BAR --}}
    <div class="mb-6 rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between shadow-sm">
        {{-- Tabs --}}
        <div class="flex items-center gap-6 overflow-x-auto shrink-0">
            @foreach([['Semua','248','semua'],['Aktif','224','aktif'],['Cuti','12','cuti'],['Nonaktif','12','nonaktif']] as $i => [$lbl,$cnt,$key])
            <button
                id="tab-{{ $key }}"
                onclick="switchTab('{{ $key }}')"
                class="pb-1 text-sm transition-colors relative {{ $i === 0 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink font-medium' }}"
            >
                {{ $lbl }} <span class="text-xs text-muted font-normal">({{ $cnt }})</span>
            </button>
            @endforeach
        </div>

        {{-- Inputs --}}
        <div class="flex flex-1 flex-wrap items-center justify-end gap-3 lg:flex-initial">
            {{-- Search input --}}
            <div class="flex min-w-64 flex-1 items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 lg:flex-initial">
                <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input id="search-input" type="text" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
            </div>
            
            {{-- Filter Unit --}}
            <div class="relative min-w-36 flex-1 lg:flex-initial">
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
            <div class="relative min-w-36 flex-1 lg:flex-initial">
                <select id="filter-jenis" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    <option>PNS</option>
                    <option>CPNS</option>
                    <option>PPPK</option>
                    <option>PPNPN</option>
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
            <table class="w-full">
                <thead class="bg-soft">
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <input id="check-all" type="checkbox" class="h-4 w-4 rounded border-border text-primary focus:ring-primary/20">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Pegawai</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Jabatan & Unit</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Gol. / Jenis</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">TMT</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Dokumen</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($pegawaiData as $p)
                    <tr class="transition-colors hover:bg-soft/50" data-nama="{{ $p['nama'] }}" data-nip="{{ $p['nip'] }}" data-unit="{{ $p['unit'] }}" data-jenis="{{ $p['jenis'] }}" data-status="{{ $p['status'] }}">
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
                            <p class="text-sm text-ink">{{ $p['tmt'] }}</p>
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
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-1.5">
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
                                {{-- Hapus --}}
                                <form action="{{ route('pegawai.destroy', $p['id']) }}" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pegawai ini?')">
                                    @csrf
                                    <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm" title="Hapus">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
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
                <p id="pegawai-count-text" class="text-sm text-muted">Menampilkan 1 - 10 dari 224 data</p>
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
                @foreach([1, 2, 3, '…', 23] as $pg)
                    @if($pg === 1)
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-primary bg-primary text-sm font-semibold text-white transition hover:opacity-90">{{ $pg }}</button>
                    @elseif($pg === '…')
                    <span class="flex h-8 w-8 items-center justify-center text-sm text-muted font-sans font-medium">{{ $pg }}</span>
                    @else
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-sm text-muted transition hover:bg-soft hover:text-ink font-medium">{{ $pg }}</button>
                    @endif
                @endforeach
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
        <button class="text-xs font-semibold text-warning hover:underline transition-colors">Export Pilihan</button>
        <button class="text-xs font-semibold text-danger hover:underline transition-colors">Hapus Pilihan</button>
        <button onclick="clearBulk()" class="text-xs font-semibold text-muted hover:text-ink hover:underline transition-colors">Batal</button>
    </div>

    @push('scripts')
    <script>
    // Tab switcher
    let currentTab = 'semua';
    const tabs = ['semua','aktif','cuti','nonaktif'];
    function switchTab(active) {
        currentTab = active;
        tabs.forEach(t => {
            const btn = document.getElementById('tab-' + t);
            if (!btn) return;
            if (t === active) {
                btn.className = "pb-1 text-sm font-semibold transition-colors relative text-primary border-b-2 border-primary";
            } else {
                btn.className = "pb-1 text-sm font-medium transition-colors relative text-muted hover:text-ink";
            }
        });
        applyFilters();
    }

    const searchInput = document.getElementById('search-input');
    const filterUnit = document.getElementById('filter-unit');
    const filterJenis = document.getElementById('filter-jenis');

    function applyFilters() {
        const query = searchInput.value.toLowerCase();
        const unit = filterUnit.value;
        const jenis = filterJenis.value;

        const rows = document.querySelectorAll('tbody tr');
        let visibleCount = 0;

        rows.forEach(row => {
            const rNama = row.getAttribute('data-nama');
            if (!rNama) return; // skip templates/empty
            const rNip = row.getAttribute('data-nip').toLowerCase();
            const rUnit = row.getAttribute('data-unit');
            const rJenis = row.getAttribute('data-jenis');
            const rStatus = row.getAttribute('data-status');

            const matchesSearch = rNama.toLowerCase().includes(query) || rNip.includes(query);
            const matchesUnit = !unit || rUnit === unit;
            const matchesJenis = !jenis || rJenis === jenis;
            const matchesTab = currentTab === 'semua' || rStatus === currentTab;

            if (matchesSearch && matchesUnit && matchesJenis && matchesTab) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update showing count text
        const countText = document.getElementById('pegawai-count-text');
        if (countText) {
            countText.textContent = `Menampilkan ${visibleCount} dari ${rows.length} data`;
        }
    }

    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (filterUnit) filterUnit.addEventListener('change', applyFilters);
    if (filterJenis) filterJenis.addEventListener('change', applyFilters);

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const filterParam = urlParams.get('filter');
        if (filterParam === 'pensiun') {
            if (searchInput) {
                searchInput.value = 'Siti Rahayu';
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

    checkAll.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = checkAll.checked);
        updateBulk();
    });

    document.querySelectorAll('.row-check').forEach(cb => {
        cb.addEventListener('change', () => {
            const all    = document.querySelectorAll('.row-check').length;
            const ticked = document.querySelectorAll('.row-check:checked').length;
            checkAll.indeterminate = ticked > 0 && ticked < all;
            checkAll.checked       = ticked === all;
            updateBulk();
        });
    });

    function clearBulk() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        checkAll.checked       = false;
        checkAll.indeterminate = false;
        updateBulk();
    }
    </script>
    @endpush

</x-layouts.app>
