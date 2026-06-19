<x-layouts.app title="Data Pegawai">

    @php
    $pegawaiData = [
        [
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
            'nama'=>'Nadia Kusuma', 'email'=>'nadiakusuma@gmail.com',
            'golongan'=>'II/c', 'jabatan'=>'Pengelola Kepegawaian',
            'kelas_jabatan'=>'6', 'nip'=>'19950822202001 2 002',
            'telepon'=>'081299887766', 'pangkat'=>'Pengatur',
            'pendidikan'=>'S1', 'tgl_lahir'=>'August 22, 1995',
            'pensiun'=>'Nadia Kusuma', 'atasan'=>'Nadia Kusuma',
            'person_familia'=>'Nadia Kusuma', 'prodi'=>'Ilmu Pemerintahan',
            'jenis'=>'PPNPN', 'status'=>'aktif', 'tmt'=>'01-01-2020',
            'unit'=>'Bag. SDM', 'dok'=>'ok',
        ],
        [
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
            'nama'=>'Siraajuddin Laluv, OE., M.', 'email'=>'siraajuddinlaluv@gmail.com',
            'golongan'=>'IV/a', 'jabatan'=>'Pengolah Data dan Informasi',
            'kelas_jabatan'=>'7', 'nip'=>'19721231984 0 1062',
            'telepon'=>'081238500', 'pangkat'=>'Pembina',
            'pendidikan'=>'S1', 'tgl_lahir'=>'December 31, 1972',
            'pensiun'=>'Siraajuddin Laluv', 'atasan'=>'Siraajuddin Laluv',
            'person_familia'=>'Siraajuddin Laluv', 'prodi'=>'Manajemen',
            'jenis'=>'PNS', 'status'=>'aktif', 'tmt'=>'01-12-2003',
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
        <div class="flex shrink-0 items-center gap-4">
            {{-- Export button --}}
            <a
                href="{{ route('pegawai.export') }}"
                id="export-btn"
                class="text-sm font-semibold text-primary hover:underline transition-colors"
            >
                Export Excel
            </a>
            <a
                href="#"
                id="add-pegawai-btn"
                class="text-sm font-semibold text-primary hover:underline transition-colors"
            >
                Tambah Pegawai
            </a>
        </div>
    </div>

    {{-- KPI CARDS --}}
    <div class="mb-6 grid grid-cols-2 gap-6 xl:grid-cols-4">
        <div class="flex flex-col justify-between h-full rounded-lg border border-border bg-surface p-6 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="shrink-0 rounded-lg bg-primary/10 p-2.5">
                    <svg class="h-5 w-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted">Total Pegawai</p>
                    <p class="text-2xl font-bold text-primary">248</p>
                </div>
            </div>
            <p class="mt-3 border-t border-border pt-3 text-xs text-muted">Aktif: 224 orang</p>
        </div>
        <div class="flex flex-col justify-between h-full rounded-lg border border-border bg-surface p-6 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="shrink-0 rounded-lg bg-warning/10 p-2.5">
                    <svg class="h-5 w-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted">Sedang Cuti</p>
                    <p class="text-2xl font-bold text-warning">12</p>
                </div>
            </div>
            <p class="mt-3 border-t border-border pt-3 text-xs text-muted">4 menunggu persetujuan</p>
        </div>
        <div class="flex flex-col justify-between h-full rounded-lg border border-border bg-surface p-6 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="shrink-0 rounded-lg bg-danger/10 p-2.5">
                    <svg class="h-5 w-5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted">Akan Pensiun</p>
                    <p class="text-2xl font-bold text-danger">7</p>
                </div>
            </div>
            <p class="mt-3 border-t border-border pt-3 text-xs text-muted">2 dalam 30 hari ke depan</p>
        </div>
        <div class="flex flex-col justify-between h-full rounded-lg border border-border bg-surface p-6 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="shrink-0 rounded-lg bg-danger/10 p-2.5">
                    <svg class="h-5 w-5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted">Dok. Kadaluarsa</p>
                    <p class="text-2xl font-bold text-danger">5</p>
                </div>
            </div>
            <p class="mt-3 border-t border-border pt-3 text-xs text-muted">Perlu segera diperbarui</p>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <div class="mb-4 overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <div class="flex flex-col divide-y divide-border lg:flex-row lg:items-center lg:divide-x lg:divide-y-0">
            <div class="flex items-center gap-6 px-6 py-4 bg-surface">
                @foreach([['Semua','248','semua'],['Aktif','224','aktif'],['Cuti','12','cuti'],['Nonaktif','12','nonaktif']] as $i => [$lbl,$cnt,$key])
                <button
                    id="tab-{{ $key }}"
                    onclick="switchTab('{{ $key }}')"
                    class="pb-3 text-sm transition-colors relative {{ $i === 0 ? 'text-primary font-semibold border-b-2 border-primary -mb-[17px]' : 'text-muted hover:text-ink font-medium' }}"
                >
                    {{ $lbl }} <span class="text-xs text-muted font-normal">({{ $cnt }})</span>
                </button>
                @endforeach
            </div>
            <div class="flex flex-1 flex-wrap items-center gap-2 p-3">
                <div class="flex min-w-48 flex-1 items-center gap-2 rounded-lg border border-border bg-soft px-3 py-2">
                    <svg class="h-4 w-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input id="search-input" type="text" placeholder="Cari nama atau NIP..."
                        class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>
                <select id="filter-unit" class="rounded-lg border border-border bg-soft px-3 py-2 text-sm text-ink transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit</option>
                    <option>Bag. Umum</option>
                    <option>Bag. Keuangan</option>
                    <option>Bag. SDM</option>
                    <option>Bag. IT</option>
                </select>
                <select id="filter-jenis" class="rounded-lg border border-border bg-soft px-3 py-2 text-sm text-ink transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis</option>
                    <option>PNS</option>
                    <option>CPNS</option>
                    <option>PPPK</option>
                    <option>PPNPN</option>
                </select>
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
                    <tr class="transition-colors hover:bg-soft/50">
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
                            <p class="text-sm font-bold text-ink">{{ $p['golongan'] }}</p>
                            <span class="mt-0.5 inline-block text-[10px] font-semibold {{ $jenisBadge[$p['jenis']] ?? 'text-muted' }}">
                                {{ $p['jenis'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm text-ink">{{ $p['tmt'] }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="text-xs font-semibold {{ $statusBadge[$p['status']] ?? 'text-muted' }}">
                                {{ $statusLabel[$p['status']] ?? $p['status'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-1.5">
                                <div class="h-2 w-2 shrink-0 rounded-full {{ $dokDot[$p['dok']] }}"></div>
                                <span class="text-xs font-semibold {{ $dokText[$p['dok']] }}">{{ $dokLabel[$p['dok']] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <a href="#" class="text-xs font-semibold text-primary hover:underline transition-colors">Detail</a>
                                <a href="#" class="text-xs font-semibold text-muted hover:text-ink hover:underline transition-colors">Edit</a>
                                <button class="text-xs font-semibold text-danger hover:underline transition-colors" title="Hapus font-sans">Hapus</button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- TABLE FOOTER --}}
        <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <p class="text-sm text-muted">Menampilkan <span class="font-semibold text-ink">1–8</span> dari <span class="font-semibold text-ink">248</span> pegawai</p>
                <select id="per-page" class="rounded-lg border border-border bg-surface px-2 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20">
                    <option>10 / hal</option>
                    <option>25 / hal</option>
                    <option>50 / hal</option>
                </select>
            </div>
            <div class="flex items-center gap-1">
                <button class="rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-muted transition-colors hover:bg-soft">Prev</button>
                @foreach([1, 2, 3, '…', 31] as $pg)
                <button class="rounded-lg px-3 py-1.5 text-sm transition-colors {{ $pg === 1 ? 'text-primary font-semibold' : 'text-muted hover:bg-soft' }}">{{ $pg }}</button>
                @endforeach
                <button class="rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-muted transition-colors hover:bg-soft">Next</button>
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
    const tabs = ['semua','aktif','cuti','nonaktif'];
    function switchTab(active) {
        tabs.forEach(t => {
            const btn = document.getElementById('tab-' + t);
            if (!btn) return;
            if (t === active) {
                btn.className = "pb-3 text-sm font-semibold transition-colors relative text-primary border-b-2 border-primary -mb-[17px]";
            } else {
                btn.className = "pb-3 text-sm font-medium transition-colors relative text-muted hover:text-ink";
            }
        });
    }

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
