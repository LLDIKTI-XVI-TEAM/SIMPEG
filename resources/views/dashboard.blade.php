<x-layouts.app title="Dashboard" subtitle="Ringkasan eksekutif dan pemantauan aktivitas kepegawaian hari ini.">

    {{-- ================================================================ --}}
    {{-- WELCOME BANNER --}}
    {{-- ================================================================ --}}
    <div class="mb-6 overflow-hidden rounded-lg border border-primary/20 bg-primary px-6 py-5 shadow-sm">
        <div class="space-y-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-white/60">Selamat datang kembali</p>
            <h2 class="text-2xl font-extrabold text-white leading-tight">
                {{ auth()->user()->name }}
            </h2>
            <p class="text-sm text-white/70">
                {{ now()->translatedFormat('l, d F Y') }} · Ada
                <span class="font-bold text-secondary">3 item</span> yang perlu perhatian hari ini.
            </p>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- KPI CARDS — 4 KOLOM --}}
    {{-- ================================================================ --}}
    <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">

        {{-- Total Pegawai --}}
        <a href="{{ route('data-pegawai') }}" class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Pegawai</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-primary leading-none font-mono">248</p>
                </div>
                <div class="rounded-lg bg-primary/10 p-2.5 shrink-0">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>Seluruh pegawai aktif</span>
            </div>
        </a>

        {{-- Sedang Cuti --}}
        <a href="{{ route('cuti', ['status' => 'pending']) }}" class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sedang Cuti</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-secondary leading-none font-mono">12</p>
                </div>
                <div class="rounded-lg bg-secondary/10 p-2.5 shrink-0">
                    <svg class="w-6 h-6 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>4 menunggu persetujuan</span>
            </div>
        </a>

        {{-- Akan Pensiun --}}
        <a href="{{ route('data-pegawai', ['filter' => 'pensiun']) }}" class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Akan Pensiun</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-info leading-none font-mono">7</p>
                </div>
                <div class="rounded-lg bg-info/10 p-2.5 shrink-0">
                    <svg class="w-6 h-6 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>Dalam 6 bulan ke depan</span>
            </div>
        </a>

        {{-- Dokumen Kadaluarsa --}}
        <a href="{{ route('dokumen', ['filter' => 'kadaluarsa']) }}" class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Dok. Kadaluarsa</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-danger leading-none font-mono">5</p>
                </div>
                <div class="rounded-lg bg-danger/10 p-2.5 shrink-0">
                    <svg class="w-6 h-6 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>Perlu diperbarui segera</span>
            </div>
        </a>
    </div>

    {{-- ================================================================ --}}
    {{-- ROW UTAMA: TABEL + AKTIVITAS | SIDEBAR KANAN --}}
    {{-- ================================================================ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Kolom Kiri (2/3) --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Tabel Pegawai Terbaru --}}
            <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Pegawai Terbaru</h3>
                        <p class="text-[10px] text-muted font-sans mt-0.5">5 penambahan data kepegawaian terakhir</p>
                    </div>
                    <a href="{{ route('data-pegawai') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                        Lihat Semua
                    </a>
                </div>

                @php
                $pegawaiList = [
                    ['id' => 1, 'nama' => 'Ahmad Fauzi',                  'nip' => '19850312 201001 1 001', 'jabatan' => 'Analis Kepegawaian',             'unit' => 'Bag. Umum',     'jenis' => 'PNS',   'golongan' => 'III/c', 'status' => 'aktif'],
                    ['id' => 2, 'nama' => 'Siti Rahayu',                  'nip' => '19901120 201501 2 003', 'jabatan' => 'Analis Ahli Madya',              'unit' => 'Bag. Keuangan', 'jenis' => 'PNS',   'golongan' => 'II/d',  'status' => 'aktif'],
                    ['id' => 3, 'nama' => 'Sabrina Rossa Adriani Wibowo', 'nip' => '20261210 820500 0 04',  'jabatan' => 'Analis SDM Aparatur Ahli Pertama', 'unit' => 'Bag. SDM',      'jenis' => 'CPNS',  'golongan' => 'III/a', 'status' => 'aktif'],
                    ['id' => 4, 'nama' => 'Cimma Sari Oktariani Di',      'nip' => '26110820 520600 0 04',  'jabatan' => 'Pranata SDM Terampil',           'unit' => 'Bag. IT',       'jenis' => 'CPNS',  'golongan' => 'III/c', 'status' => 'aktif'],
                    ['id' => 5, 'nama' => 'Nurarningsih Dumbea, S.P.',    'nip' => '19880123 202001 1 005', 'jabatan' => 'Pejabat Lelang Operational',     'unit' => 'Bag. Umum',     'jenis' => 'PPPK',  'golongan' => 'III/b', 'status' => 'aktif'],
                ];
                $badge = [
                    'aktif'    => 'text-success',
                    'cuti'     => 'text-warning',
                    'nonaktif' => 'text-danger',
                ];
                $label    = ['aktif' => 'Aktif', 'cuti' => 'Cuti', 'nonaktif' => 'Nonaktif'];
                $jenisBg  = ['PNS' => 'bg-primary/10 text-primary', 'PPPK' => 'bg-secondary/10 text-secondary', 'PPNPN' => 'bg-info/10 text-info', 'CPNS' => 'bg-info/10 text-info'];
                @endphp

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Pegawai</th>
                                <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Jabatan</th>
                                <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Unit Kerja</th>
                                <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Status</th>
                                <th class="px-6 py-3 text-right text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($pegawaiList as $p)
                            <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                            <span class="text-xs font-bold text-primary">{{ strtoupper(substr($p['nama'], 0, 1)) }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-ink font-sans leading-tight">{{ $p['nama'] }}</p>
                                            <p class="text-[10px] text-muted font-sans leading-none mt-0.5">NIP. {{ $p['nip'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5">
                                    <p class="text-xs font-medium text-ink font-sans">{{ $p['jabatan'] }}</p>
                                </td>
                                <td class="px-6 py-3.5">
                                    <p class="text-xs font-medium text-ink font-sans">{{ $p['unit'] }}</p>
                                </td>
                                <td class="px-6 py-3.5">
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
                                    $statusLabels = [
                                        'aktif' => 'Aktif',
                                        'cuti' => 'Cuti',
                                        'nonaktif' => 'Nonaktif'
                                    ];
                                    $stClass = $statusClasses[$p['status']] ?? 'bg-soft text-muted';
                                    $stDot = $statusDots[$p['status']] ?? 'bg-muted';
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $stClass }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $stDot }}"></span>
                                        {{ $statusLabels[$p['status']] ?? $p['status'] }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <div class="flex items-center justify-end">
                                        <a href="{{ route('pegawai.show', ['id' => $p['id']]) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
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
            </div>

            {{-- Aktivitas Terkini --}}
            <div id="audit-log" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Aktivitas Terkini</h3>
                        <p class="text-[10px] text-muted font-sans mt-0.5">Log perubahan sistem kepegawaian hari ini</p>
                    </div>
                    <a href="{{ route('audit-log') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Audit Log</a>
                </div>
                <div class="divide-y divide-border">
                    @php
                    $auditLog = [
                        ['user' => 'Admin HR',   'aksi' => 'Menambahkan data pegawai baru',           'target' => 'Sabrina Rossa Adriani Wibowo', 'waktu' => '2 menit lalu',  'type' => 'tambah'],
                        ['user' => 'Admin HR',   'aksi' => 'Menyetujui pengajuan cuti',               'target' => 'Siti Rahayu',                 'waktu' => '15 menit lalu', 'type' => 'setujui'],
                        ['user' => 'Supervisor', 'aksi' => 'Mengunggah dokumen SK Pengangkatan',      'target' => 'Ahmad Fauzi',                 'waktu' => '1 jam lalu',    'type' => 'unggah'],
                        ['user' => 'Admin HR',   'aksi' => 'Memperbarui data jabatan pegawai',        'target' => 'Nurarningsih Dumbea, S.P.',   'waktu' => '3 jam lalu',    'type' => 'perbarui'],
                    ];
                    $auditColorMap = [
                        'tambah'   => 'bg-success/10 text-success',
                        'setujui'  => 'bg-info/10 text-info',
                        'unggah'   => 'bg-primary/10 text-primary',
                        'perbarui' => 'bg-warning/10 text-warning',
                    ];
                    @endphp
                    @foreach($auditLog as $log)
                    @php $auditColor = $auditColorMap[$log['type']] ?? 'bg-soft text-muted'; @endphp
                    <div class="flex items-center gap-4 px-6 py-4 transition-colors hover:bg-soft/30 cursor-pointer">
                        <div class="shrink-0 rounded-full {{ $auditColor }} p-2">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-ink font-sans leading-tight">
                                <span class="font-bold">{{ $log['user'] }}</span> {{ $log['aksi'] }}
                            </p>
                            <p class="text-[10px] text-muted mt-0.5 font-sans leading-none">{{ $log['target'] }}</p>
                        </div>
                        <span class="shrink-0 text-[10px] text-muted font-sans">{{ $log['waktu'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

        </div>{{-- end kolom kiri --}}

        {{-- Kolom Kanan (1/3) --}}
        <div class="space-y-6">

            {{-- Perlu Perhatian --}}
            <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="border-b border-border px-6 py-4 bg-surface">
                    <h3 class="text-sm font-bold text-ink font-sans">Perlu Perhatian</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Item yang memerlukan tindakan administrasi segera</p>
                </div>
                <div class="space-y-4 p-6">
                    <a href="{{ route('dokumen', ['filter' => 'kadaluarsa']) }}" class="group flex items-start gap-3 rounded-lg border border-border border-l-4 border-l-danger bg-surface p-4 shadow-sm transition-colors hover:bg-soft/40">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[9px] font-bold uppercase tracking-wider text-danger font-sans">Dokumen Kadaluarsa (H-30)</span>
                                <span class="text-[9px] text-primary font-semibold group-hover:underline font-sans shrink-0">Tinjau</span>
                            </div>
                            <p class="text-xs font-bold text-ink font-sans">SK Pengangkatan — Budi Santoso</p>
                            <p class="text-[10px] text-muted font-sans leading-normal">Masa berlaku dokumen penting pegawai segera berakhir.</p>
                        </div>
                    </a>
                    <a href="{{ route('data-pegawai', ['filter' => 'pensiun']) }}" class="group flex items-start gap-3 rounded-lg border border-border border-l-4 border-l-warning bg-surface p-4 shadow-sm transition-colors hover:bg-soft/40">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[9px] font-bold uppercase tracking-wider text-warning font-sans">Masa Pensiun (H-60)</span>
                                <span class="text-[9px] text-primary font-semibold group-hover:underline font-sans shrink-0">Tinjau</span>
                            </div>
                            <p class="text-xs font-bold text-ink font-sans">Siti Rahayu — Februari 2026</p>
                            <p class="text-[10px] text-muted font-sans leading-normal">Persiapan administrasi pensiun batas usia pensiun.</p>
                        </div>
                    </a>
                    <a href="{{ route('data-pegawai') }}" class="group flex items-start gap-3 rounded-lg border border-border border-l-4 border-l-info bg-surface p-4 shadow-sm transition-colors hover:bg-soft/40">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[9px] font-bold uppercase tracking-wider text-info font-sans">Kontrak PPNPN (H-90)</span>
                                <span class="text-[9px] text-primary font-semibold group-hover:underline font-sans shrink-0">Tinjau</span>
                            </div>
                            <p class="text-xs font-bold text-ink font-sans">Dewi Pertiwi — Perlu perpanjangan</p>
                            <p class="text-[10px] text-muted font-sans leading-normal">Peninjauan berkas perpanjangan kontrak kerja instansi.</p>
                        </div>
                    </a>
                </div>
            </div>

            {{-- Cuti Pending --}}
            <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Cuti Pending</h3>
                        <p class="text-[10px] text-muted font-sans mt-0.5">Menunggu otorisasi approval</p>
                    </div>
                    <span class="rounded-full bg-warning/10 text-warning px-2.5 py-0.5 text-xs font-bold font-sans">4</span>
                </div>
                <div class="divide-y divide-border">
                    @php
                    $cutiList = [
                        ['nama' => 'Ahmad Fauzi',  'info' => 'Tahunan · 5 hari · 20–24 Jun'],
                        ['nama' => 'Nadia Kusuma', 'info' => 'Sakit · 3 hari · 19–21 Jun'],
                        ['nama' => 'Teguh Wibowo', 'info' => 'Melahirkan · 90 hari · 1 Jul'],
                    ];
                    @endphp
                    @foreach($cutiList as $c)
                    <div class="flex items-center justify-between px-6 py-4 transition-colors hover:bg-soft/30">
                        <div class="min-w-0 flex-1 pr-4">
                            <p class="text-xs font-bold text-ink font-sans">{{ $c['nama'] }}</p>
                            <p class="text-[10px] text-muted mt-0.5 font-sans leading-none">{{ $c['info'] }}</p>
                        </div>
                        <div class="ml-3 flex shrink-0 items-center gap-3">
                            <button
                                onclick="this.closest('div.flex').innerHTML = '<div class=\'w-full text-center py-1\'><span class=\'text-xs font-bold text-success font-sans\'>✓ Disetujui</span></div>'"
                                class="text-xs font-semibold text-success hover:underline transition-colors font-sans cursor-pointer focus:outline-none">
                                Setuju
                            </button>
                            <button
                                onclick="this.closest('div.flex').innerHTML = '<div class=\'w-full text-center py-1\'><span class=\'text-xs font-bold text-warning font-sans\'>⏸ Ditunda</span></div>'"
                                class="text-xs font-semibold text-warning hover:underline transition-colors font-sans cursor-pointer focus:outline-none">
                                Tunda
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="border-t border-border px-6 py-4 bg-soft/20">
                    <button
                        id="setujui-semua-btn"
                        onclick="
                            document.querySelectorAll('#setujui-semua-btn').forEach(b => b.disabled = true);
                            document.querySelectorAll('.divide-y.divide-border .flex.items-center.justify-between').forEach(row => {
                                const actionBox = row.querySelector('.ml-3');
                                if (actionBox) {
                                    actionBox.innerHTML = '<div class=\'w-full text-center py-1\'><span class=\'text-xs font-bold text-success font-sans\'>✓ Disetujui</span></div>';
                                }
                            });
                            this.textContent = 'Semua Disetujui ✓';
                            this.className = this.className.replace('text-primary hover:underline','text-success cursor-default');
                        "
                        class="flex w-full items-center justify-center text-xs font-bold text-primary hover:underline transition-all font-sans cursor-pointer focus:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                        Setujui Semua
                    </button>
                </div>
            </div>


        </div>{{-- end kolom kanan --}}
    </div>{{-- end row utama --}}

    {{-- ================================================================ --}}
    {{-- ROW KEDUA: HARI LIBUR MENDATANG (2/3) + KOMPOSISI PEGAWAI (1/3) --}}
    {{-- ================================================================ --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        
        {{-- Hari Libur Mendatang --}}
        <div class="lg:col-span-2 rounded-lg border border-border bg-surface p-6 shadow-sm flex flex-col">
            <div class="flex items-center justify-between border-b border-border pb-4 mb-5">
                <div>
                    <h3 class="text-lg font-bold text-ink font-sans">Hari Libur Mendatang</h3>
                    <p class="text-xs text-muted font-sans mt-1">Garis waktu 3-4 bulan ke depan</p>
                </div>
                <a href="{{ route('hari-libur.index') }}" class="text-sm font-semibold text-primary hover:underline font-sans">Kelola</a>
            </div>
            
            @php
            $hariLiburData = [
                ['tanggal' => '2026-01-01', 'nama' => 'Tahun Baru 2026 Masehi', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
                ['tanggal' => '2026-02-17', 'nama' => 'Isra Mikraj Nabi Muhammad SAW', 'tipe' => 'libur_nasional', 'hari' => 'Selasa'],
                ['tanggal' => '2026-03-19', 'nama' => 'Hari Suci Nyepi Saka 1948', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
                ['tanggal' => '2026-03-20', 'nama' => 'Cuti Bersama Nyepi', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
                ['tanggal' => '2026-04-03', 'nama' => 'Wafat Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
                ['tanggal' => '2026-04-05', 'nama' => 'Hari Raya Paskah', 'tipe' => 'libur_nasional', 'hari' => 'Minggu'],
                ['tanggal' => '2026-05-01', 'nama' => 'Hari Buruh Internasional', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
                ['tanggal' => '2026-05-13', 'nama' => 'Hari Raya Waisak 2570 BE', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
                ['tanggal' => '2026-05-14', 'nama' => 'Kenaikan Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
                ['tanggal' => '2026-05-15', 'nama' => 'Cuti Bersama Kenaikan Yesus', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
                ['tanggal' => '2026-06-01', 'nama' => 'Hari Lahir Pancasila', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
                ['tanggal' => '2026-06-17', 'nama' => 'Hari Raya Idul Adha 1447 H', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
                ['tanggal' => '2026-08-17', 'nama' => 'HUT Kemerdekaan RI', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
                ['tanggal' => '2026-12-25', 'nama' => 'Hari Raya Natal', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
            ];

            $today = \Carbon\Carbon::today();
            $upcomingHolidays = [];
            foreach ($hariLiburData as $hl) {
                if (\Carbon\Carbon::parse($hl['tanggal'])->greaterThanOrEqualTo($today)) {
                    $upcomingHolidays[] = $hl;
                }
            }

            if (count($upcomingHolidays) < 4) {
                $upcomingHolidays = array_slice($hariLiburData, -4);
            } else {
                $upcomingHolidays = array_slice($upcomingHolidays, 0, 4);
            }
            @endphp

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($upcomingHolidays as $h)
                @php
                $carbonDate = \Carbon\Carbon::parse($h['tanggal']);
                $tgl = $carbonDate->translatedFormat('d');
                $bln = strtoupper($carbonDate->translatedFormat('M'));
                if ($bln === 'AGU') {
                    $bln = 'AGT';
                }
                // Bersihkan suffix tahun agar nama libur sama seperti mockup
                $namaClean = str_replace([' 1447 H', ' 2026 Masehi', ' Saka 1948', ' 2570 BE'], '', $h['nama']);
                @endphp
                <div class="rounded-xl border border-border bg-surface p-4 hover:bg-soft/20 transition-colors cursor-pointer flex flex-col justify-between h-full">
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-primary/5">
                            <span class="text-lg font-bold leading-none text-primary font-sans">{{ $tgl }}</span>
                            <span class="text-[10px] font-bold uppercase leading-none text-primary font-sans mt-1">{{ $bln }}</span>
                        </div>
                        <div class="flex-1 min-w-0 h-12 flex items-center">
                            <p class="text-xs font-bold text-ink font-sans leading-snug line-clamp-3">{{ $namaClean }}</p>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-border my-3"></div>
                    <div class="text-xs text-muted font-medium font-sans">
                        {{ $h['hari'] }} &bull; {{ $h['tipe'] === 'libur_nasional' ? 'Nasional' : 'Cuti Bersama' }}
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Komposisi Pegawai --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm flex flex-col justify-between">
            <div>
                <div class="mb-4">
                    <h3 class="text-sm font-bold text-ink font-sans">Komposisi Pegawai</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Berdasarkan jenis status kepegawaian</p>
                </div>
                <div class="space-y-4">
                    @php
                    $komposisi = [
                        ['label' => 'PNS',   'jumlah' => 186, 'persen' => '75%', 'bar' => 'bg-primary',   'dot' => 'bg-primary'],
                        ['label' => 'PPPK',  'jumlah' => 42,  'persen' => '17%', 'bar' => 'bg-secondary', 'dot' => 'bg-secondary'],
                        ['label' => 'PPNPN', 'jumlah' => 20,  'persen' => '8%',  'bar' => 'bg-info',      'dot' => 'bg-info'],
                    ];
                    @endphp
                    @foreach($komposisi as $k)
                    <div class="flex items-center gap-3">
                        <div class="h-2 w-2 shrink-0 rounded-full {{ $k['dot'] }}"></div>
                        <div class="flex-1">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs font-semibold text-ink font-sans">{{ $k['label'] }}</span>
                                <span class="text-xs text-muted font-sans">{{ $k['jumlah'] }} ({{ $k['persen'] }})</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-soft">
                                <div class="h-1.5 rounded-full {{ $k['bar'] }}" style="width: {{ $k['persen'] }}"></div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>

</x-layouts.app>
