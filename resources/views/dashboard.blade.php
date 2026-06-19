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
        <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Pegawai</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-primary leading-none">248</p>
                </div>
                <div class="rounded-lg bg-primary/10 p-2.5 shrink-0">
                    <svg class="h-5 w-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>Seluruh pegawai aktif</span>
            </div>
        </div>

        {{-- Sedang Cuti --}}
        <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sedang Cuti</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-secondary leading-none">12</p>
                </div>
                <div class="rounded-lg bg-secondary/10 p-2.5 shrink-0">
                    <svg class="h-5 w-5 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>4 menunggu persetujuan</span>
            </div>
        </div>

        {{-- Akan Pensiun --}}
        <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Akan Pensiun</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-info leading-none">7</p>
                </div>
                <div class="rounded-lg bg-info/10 p-2.5 shrink-0">
                    <svg class="h-5 w-5 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>Dalam 6 bulan ke depan</span>
            </div>
        </div>

        {{-- Dokumen Kadaluarsa --}}
        <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Dok. Kadaluarsa</p>
                    <p class="mt-1.5 text-2xl font-extrabold text-danger leading-none">5</p>
                </div>
                <div class="rounded-lg bg-danger/10 p-2.5 shrink-0">
                    <svg class="h-5 w-5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                            d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[10px] text-muted font-sans">
                <span>Perlu diperbarui segera</span>
            </div>
        </div>

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
                    <a href="{{ route('pegawai.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                        Lihat Semua
                    </a>
                </div>

                @php
                $pegawaiList = [
                    ['nama' => 'Ahmad Fauzi',   'nip' => '19850312 201001 1 001', 'jabatan' => 'Analis Kepegawaian', 'unit' => 'Bag. Umum',     'jenis' => 'PNS',   'golongan' => 'III/c', 'status' => 'aktif'],
                    ['nama' => 'Siti Rahayu',   'nip' => '19901120 201501 2 003', 'jabatan' => 'Staf Administrasi',  'unit' => 'Bag. Keuangan', 'jenis' => 'PNS',   'golongan' => 'II/d',  'status' => 'aktif'],
                    ['nama' => 'Budi Santoso',  'nip' => '19780601 200312 1 002', 'jabatan' => 'Kepala Subbagian',  'unit' => 'Bag. SDM',      'jenis' => 'PNS',   'golongan' => 'III/d', 'status' => 'cuti'],
                    ['nama' => 'Dewi Pertiwi',  'nip' => '19931205 201901 2 001', 'jabatan' => 'Pranata Komputer',  'unit' => 'Bag. IT',       'jenis' => 'PPPK',  'golongan' => 'III/a', 'status' => 'aktif'],
                    ['nama' => 'Rudi Hermawan', 'nip' => '19751010 199903 1 004', 'jabatan' => 'Arsiparis',         'unit' => 'Bag. Umum',     'jenis' => 'PPNPN', 'golongan' => 'II/b',  'status' => 'nonaktif'],
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
                                    <span class="text-xs font-semibold font-sans {{ $badge[$p['status']] ?? 'text-muted' }}">
                                        {{ $label[$p['status']] ?? $p['status'] }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <a href="{{ route('pegawai.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Detail</a>
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
                    <a href="#audit-log" class="text-xs font-semibold text-primary hover:underline font-sans">Audit Log</a>
                </div>
                <div class="divide-y divide-border">
                    @php
                    $auditLog = [
                        ['user' => 'Admin HR',   'aksi' => 'Menambahkan data pegawai baru', 'target' => 'Ahmad Fauzi',  'waktu' => '2 menit lalu',  'color' => 'bg-success/10 text-success'],
                        ['user' => 'Admin HR',   'aksi' => 'Menyetujui pengajuan cuti',     'target' => 'Siti Rahayu',  'waktu' => '15 menit lalu', 'color' => 'bg-info/10 text-info'],
                        ['user' => 'Supervisor', 'aksi' => 'Mengunggah dokumen SK',         'target' => 'Budi Santoso', 'waktu' => '1 jam lalu',    'color' => 'bg-primary/10 text-primary'],
                        ['user' => 'Admin HR',   'aksi' => 'Memperbarui data jabatan',      'target' => 'Dewi Pertiwi', 'waktu' => '3 jam lalu',    'color' => 'bg-warning/10 text-warning'],
                    ];
                    @endphp
                    @foreach($auditLog as $log)
                    <div class="flex items-center gap-4 px-6 py-4 transition-colors hover:bg-soft/30 cursor-pointer">
                        <div class="shrink-0 rounded-full {{ $log['color'] }} p-2">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
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
                    <a href="{{ Route::has('dokumen.index') ? route('dokumen.index') : '#dokumen' }}" class="group flex items-start gap-3 rounded-lg border border-border border-l-4 border-l-danger bg-surface p-4 shadow-sm transition-colors hover:bg-soft/40">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[9px] font-bold uppercase tracking-wider text-danger font-sans">Dokumen Kadaluarsa (H-30)</span>
                                <span class="text-[9px] text-primary font-semibold group-hover:underline font-sans shrink-0">Tinjau</span>
                            </div>
                            <p class="text-xs font-bold text-ink font-sans">SK Pengangkatan — Budi Santoso</p>
                            <p class="text-[10px] text-muted font-sans leading-normal">Masa berlaku dokumen penting pegawai segera berakhir.</p>
                        </div>
                    </a>
                    <a href="{{ route('pegawai.index') }}" class="group flex items-start gap-3 rounded-lg border border-border border-l-4 border-l-warning bg-surface p-4 shadow-sm transition-colors hover:bg-soft/40">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[9px] font-bold uppercase tracking-wider text-warning font-sans">Masa Pensiun (H-60)</span>
                                <span class="text-[9px] text-primary font-semibold group-hover:underline font-sans shrink-0">Tinjau</span>
                            </div>
                            <p class="text-xs font-bold text-ink font-sans">Siti Rahayu — Februari 2026</p>
                            <p class="text-[10px] text-muted font-sans leading-normal">Persiapan administrasi pensiun batas usia pensiun.</p>
                        </div>
                    </a>
                    <a href="{{ route('pegawai.index') }}" class="group flex items-start gap-3 rounded-lg border border-border border-l-4 border-l-info bg-surface p-4 shadow-sm transition-colors hover:bg-soft/40">
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
                                onclick="this.closest('div.flex').innerHTML = '<div class=\'w-full text-center py-1\'><span class=\'text-xs font-bold text-danger font-sans\'>✗ Ditolak</span></div>'"
                                class="text-xs font-semibold text-danger hover:underline transition-colors font-sans cursor-pointer focus:outline-none">
                                Tolak
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

            {{-- Komposisi Pegawai --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
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

        </div>{{-- end kolom kanan --}}
    </div>{{-- end row utama --}}

    {{-- ================================================================ --}}
    {{-- HARI LIBUR MENDATANG --}}
    {{-- ================================================================ --}}
    <div class="mt-6 overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans">Hari Libur Mendatang</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Garis waktu 3-4 bulan ke depan</p>
            </div>
            <a href="#" class="text-xs font-semibold text-primary hover:underline font-sans">Kelola</a>
        </div>
        <div class="grid grid-cols-2 divide-x divide-border sm:grid-cols-4 bg-surface">
            @php
            $hariLibur = [
                ['tgl' => '17', 'bln' => 'Jun', 'nama' => 'Idul Adha 1447 H',  'hari' => 'Selasa'],
                ['tgl' => '1',  'bln' => 'Jul', 'nama' => 'Hari Bhayangkara',   'hari' => 'Rabu'],
                ['tgl' => '17', 'bln' => 'Agt', 'nama' => 'HUT Kemerdekaan RI', 'hari' => 'Senin'],
                ['tgl' => '25', 'bln' => 'Des', 'nama' => 'Hari Natal',         'hari' => 'Jumat'],
            ];
            @endphp
            @foreach($hariLibur as $h)
            <div class="flex items-center gap-3 px-6 py-4 transition-colors hover:bg-soft/30 cursor-pointer">
                <div class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg bg-primary/10">
                    <span class="text-sm font-bold leading-tight text-primary font-sans">{{ $h['tgl'] }}</span>
                    <span class="text-[9px] font-bold uppercase leading-none text-primary font-sans mt-0.5">{{ $h['bln'] }}</span>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-xs font-bold text-ink font-sans">{{ $h['nama'] }}</p>
                    <p class="text-[10px] text-muted font-sans leading-none mt-1">{{ $h['hari'] }} · Nasional</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>

</x-layouts.app>
