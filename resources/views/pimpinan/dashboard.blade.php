<x-layouts.app title="Dashboard Pimpinan" subtitle="Ringkasan eksekutif dan pemantauan aktivitas kepegawaian hari ini.">
    @php
        $dashboardEwsAlerts = $ewsAktif ?? [];
        $dashboardEwsTotal = count($dashboardEwsAlerts);
        $dashboardEwsUrgent = collect($dashboardEwsAlerts)->where('indikator', 'merah')->count();
        $dashboardEwsWarning = collect($dashboardEwsAlerts)->where('indikator', 'kuning')->count();
        $dashboardEwsInfo = collect($dashboardEwsAlerts)->where('indikator', 'hijau')->count();
        $dashboardEwsLink = route('pimpinan.ews.index');
        
        $tot = ($totalPegawai ?? 0) > 0 ? $totalPegawai : 1;
        $pnsPct = round(($komposisi['PNS'] ?? 0) / $tot * 100, 1);
        $pppkPct = round(($komposisi['PPPK'] ?? 0) / $tot * 100, 1);
        $cpnsPct = round(($komposisi['CPNS'] ?? 0) / $tot * 100, 1);
    @endphp

    {{-- WELCOME BANNER --}}
    <div class="mb-6 relative overflow-hidden rounded-xl bg-gradient-to-r from-[#173292] to-[#2143c2] px-5 py-4 shadow-md w-full flex flex-col lg:flex-row lg:items-center min-h-[140px]">
        <!-- Abstract Background Shapes -->
        <div class="absolute inset-0 pointer-events-none overflow-hidden rounded-xl">
            <!-- Dots pattern top right -->
            <div class="absolute top-2 right-4 opacity-20">
                <svg width="40" height="30" fill="currentColor" class="text-white">
                    <pattern id="dots" x="0" y="0" width="8" height="8" patternUnits="userSpaceOnUse">
                        <circle cx="1.5" cy="1.5" r="1.5"></circle>
                    </pattern>
                    <rect width="40" height="30" fill="url(#dots)"></rect>
                </svg>
            </div>
            <!-- Abstract arcs -->
            <svg class="absolute top-0 right-[20%] h-full text-white/5" viewBox="0 0 200 400" preserveAspectRatio="none">
                <path d="M 200 -50 Q 50 200 200 450" fill="none" stroke="currentColor" stroke-width="40"/>
                <path d="M 280 -50 Q 130 200 280 450" fill="none" stroke="currentColor" stroke-width="20"/>
            </svg>

        </div>

        <!-- Content Left -->
        <div class="relative z-10 w-full lg:w-[70%] flex flex-col justify-center">
            <p class="text-[10px] font-bold uppercase tracking-widest text-white/70 mb-0.5">Selamat datang kembali</p>
            <h2 class="text-xl sm:text-2xl font-extrabold text-white leading-tight">
                {{ str_replace(['(', ')'], '', auth()->user()->name) }}
            </h2>
            
            <p class="mt-1 text-[12px] text-white/80 font-sans max-w-lg">
                Semangat menjalankan tugas hari ini. Tetap produktif dan berikan pelayanan terbaik.
            </p>

            <div class="mt-3 flex flex-wrap items-center gap-2.5">
                <!-- Box 1 -->
                <div class="flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2 backdrop-blur-md border border-white/10 hover:bg-white/15 transition-colors cursor-default">
                    <div class="shrink-0 text-white/90">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>
                    </div>
                    <div class="w-px h-6 bg-white/20"></div>
                    <div class="flex flex-col">
                        <span class="text-[12px] font-medium text-white/90 leading-none">{{ now()->translatedFormat('l, d F Y') }}</span>
                        <span class="text-[10px] text-white/70 mt-0.5">Hari ini</span>
                    </div>
                </div>

                <!-- Box 2 -->
                <div class="flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2 backdrop-blur-md border border-white/10 hover:bg-white/15 transition-colors cursor-default">
                    <div class="shrink-0 text-white/90">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" /></svg>
                    </div>
                    <div class="w-px h-6 bg-white/20"></div>
                    <div class="flex flex-col">
                        <span class="text-[12px] font-medium text-white/90 leading-none">Sistem Informasi Kepegawaian</span>
                        <span class="text-[10px] text-white/70 mt-0.5">LLDIKTI Wilayah XVI</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Image Right (Pure SVG Illustration) -->
        <div class="hidden lg:block absolute right-4 bottom-0 z-10 w-[180px] pointer-events-none">
            <svg class="w-full h-auto drop-shadow-xl" viewBox="0 0 400 300" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Computer Monitor Base -->
                <path d="M 150 260 L 250 260 Q 260 260 255 250 L 235 200 L 165 200 L 145 250 Q 140 260 150 260 Z" fill="#142C80"/>
                <rect x="185" y="200" width="30" height="20" fill="#142C80"/>
                <!-- Monitor -->
                <rect x="30" y="40" width="340" height="180" rx="14" fill="#E2E8F0" stroke="#FFFFFF" stroke-width="6"/>
                <rect x="40" y="50" width="320" height="160" rx="8" fill="#FFFFFF"/>
                
                <!-- Dashboard UI inside Monitor -->
                <!-- Profile -->
                <circle cx="80" cy="90" r="22" fill="#E2E8F0"/>
                <circle cx="80" cy="85" r="9" fill="#94A3B8"/>
                <path d="M 62 107 Q 80 85 98 107 Z" fill="#94A3B8"/>
                
                <!-- Bar Chart -->
                <rect x="135" y="145" width="18" height="45" rx="4" fill="#3B82F6"/>
                <rect x="165" y="120" width="18" height="70" rx="4" fill="#60A5FA"/>
                <rect x="195" y="85" width="18" height="105" rx="4" fill="#2563EB"/>
                
                <!-- Pie Chart -->
                <circle cx="300" cy="115" r="40" fill="#E2E8F0"/>
                <path d="M 300 115 L 300 75 A 40 40 0 0 1 340 115 Z" fill="#2563EB"/>
                <circle cx="300" cy="115" r="16" fill="#FFFFFF"/>

                <!-- Calendar Front -->
                <rect x="100" y="180" width="100" height="90" rx="10" fill="#F8FAFC" stroke="#FFFFFF" stroke-width="4"/>
                <rect x="100" y="180" width="100" height="28" rx="8" fill="#3B82F6"/>
                <rect x="100" y="198" width="100" height="10" fill="#3B82F6"/>
                <rect x="115" y="168" width="8" height="24" rx="4" fill="#1E40AF"/>
                <rect x="177" y="168" width="8" height="24" rx="4" fill="#1E40AF"/>
                <!-- Grid dots in calendar -->
                <circle cx="118" cy="225" r="4" fill="#CBD5E1"/>
                <circle cx="134" cy="225" r="4" fill="#CBD5E1"/>
                <circle cx="150" cy="225" r="4" fill="#CBD5E1"/>
                <circle cx="166" cy="225" r="4" fill="#CBD5E1"/>
                <circle cx="182" cy="225" r="4" fill="#CBD5E1"/>
                <circle cx="118" cy="242" r="4" fill="#CBD5E1"/>
                <circle cx="134" cy="242" r="4" fill="#CBD5E1"/>
                <circle cx="150" cy="242" r="4" fill="#CBD5E1"/>
                <circle cx="166" cy="242" r="4" fill="#CBD5E1"/>
                <circle cx="182" cy="242" r="4" fill="#CBD5E1"/>
                <circle cx="118" cy="259" r="4" fill="#CBD5E1"/>
                <circle cx="134" cy="259" r="4" fill="#3B82F6"/>

                <!-- Plant Right Side -->
                <path d="M 335 260 L 385 260 L 375 215 L 345 215 Z" fill="#F8FAFC" stroke="#E2E8F0" stroke-width="2"/>
                <path d="M 360 215 Q 330 180 345 135 Q 370 170 360 215" fill="#64748B" opacity="0.3"/>
                <path d="M 360 215 Q 380 175 395 155 Q 405 195 360 215" fill="#64748B" opacity="0.4"/>
                <path d="M 360 215 Q 360 160 375 115 Q 385 160 360 215" fill="#64748B" opacity="0.5"/>
            </svg>
        </div>
    </div>

    {{-- KPI CARDS --}}
    <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
        {{-- W1 KPI --}}
        <x-ui.stat-card href="{{ route('pimpinan.pegawai.index') }}" label="Total Pegawai Aktif" value="{{ $totalPegawai ?? 0 }}" variant="primary" size="lg" accent>
            <x-slot:icon><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg></x-slot:icon>
            <x-slot:meta><span class="font-medium text-ink">{{ $komposisi['PNS'] ?? 0 }} PNS · {{ $komposisi['PPPK'] ?? 0 }} PPPK</span><span>Per {{ now()->translatedFormat('d F Y') }}</span></x-slot:meta>
        </x-ui.stat-card>
        {{-- W2 KPI --}}
        <x-ui.stat-card href="{{ route('pimpinan.ews.index', ['jenis' => 'KENAIKAN_PANGKAT']) }}" label="Kenaikan Pangkat" value="{{ $naikPangkatBulanIni ?? 0 }}" variant="success" size="lg" accent>
            <x-slot:icon><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l2.74-1.22m0 0-5.94-2.28m5.94 2.28-2.28 5.94" /></svg></x-slot:icon>
            <x-slot:meta><span class="font-medium text-ink">{{ $naikPangkatBulanIni ?? 0 }} Bulan Ini · {{ $naikPangkatTahunIni ?? 0 }} Tahun Ini</span><span>Per {{ now()->translatedFormat('d F Y') }}</span></x-slot:meta>
        </x-ui.stat-card>
        {{-- W3 KPI --}}
        <x-ui.stat-card href="{{ route('pimpinan.cuti.index', ['status' => 'pending']) }}" label="Status Cuti" value="{{ $cutiPending ?? 0 }}" variant="warning" size="lg" accent>
            <x-slot:icon><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg></x-slot:icon>
            <x-slot:meta><span class="font-medium text-ink">{{ $cutiPending ?? 0 }} Pending · {{ $cutiDisetujuiBulanIni ?? 0 }} Disetujui</span><span>Per {{ now()->translatedFormat('d F Y') }}</span></x-slot:meta>
        </x-ui.stat-card>
        {{-- W4 KPI --}}
        <x-ui.stat-card href="#ews-section" label="EWS Aktif" value="{{ $dashboardEwsTotal }}" variant="danger" size="lg" accent>
            <x-slot:icon><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg></x-slot:icon>
            <x-slot:meta><span class="font-semibold text-danger">{{ $dashboardEwsUrgent }} Urgent &middot; {{ $dashboardEwsWarning }} Warning</span><span>Per {{ now()->translatedFormat('d F Y') }}</span></x-slot:meta>
        </x-ui.stat-card>
    </div>

    {{-- ROW 1: W1 PIE CHART & W2 TABLE --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card padding="lg" class="flex flex-col justify-between lg:col-span-1">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans mb-1">Komposisi Kepegawaian</h3>
                <p class="text-[10px] text-muted font-sans mb-5">Rasio Pegawai Berdasarkan Jenis</p>
                <div class="flex items-center justify-center py-2">
                    <div class="relative flex items-center justify-center h-32 w-32">
                        <svg class="w-full h-full -rotate-90" viewBox="0 0 36 36">
                            <circle class="text-soft" stroke="currentColor" stroke-width="4.5" fill="none" cx="18" cy="18" r="15.915"></circle>
                            @if($pnsPct > 0)
                            <circle class="text-primary" stroke="currentColor" stroke-width="4.5" stroke-dasharray="{{ $pnsPct }} {{ 100 - $pnsPct }}" stroke-dashoffset="0" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                            @endif
                            @if($pppkPct > 0)
                            <circle class="text-secondary" stroke="currentColor" stroke-width="4.5" stroke-dasharray="{{ $pppkPct }} {{ 100 - $pppkPct }}" stroke-dashoffset="{{ - $pnsPct }}" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                            @endif
                            @if($cpnsPct > 0)
                            <circle class="text-warning" stroke="currentColor" stroke-width="4.5" stroke-dasharray="{{ $cpnsPct }} {{ 100 - $cpnsPct }}" stroke-dashoffset="{{ - ($pnsPct + $pppkPct) }}" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                            @endif
                        </svg>
                        <div class="absolute flex flex-col items-center justify-center">
                            <span class="text-xl font-mono font-extrabold text-ink leading-none">{{ $totalPegawai ?? 0 }}</span>
                            <span class="text-[9px] text-muted font-sans font-bold uppercase tracking-wider mt-1">Aktif</span>
                        </div>
                    </div>
                </div>
                <div class="mt-6 space-y-2">
                    <a href="{{ route('pimpinan.pegawai.index', ['jenis' => 'PNS']) }}" class="flex items-center justify-between text-xs group hover:bg-soft/50 p-1 -mx-1 rounded transition-colors">
                        <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-primary shrink-0"></span><span class="font-medium text-ink group-hover:text-primary transition-colors">PNS</span></div>
                        <span class="font-bold text-primary font-mono">{{ $komposisi['PNS'] ?? 0 }} ({{ $pnsPct }}%)</span>
                    </a>
                    <a href="{{ route('pimpinan.pegawai.index', ['jenis' => 'PPPK']) }}" class="flex items-center justify-between text-xs group hover:bg-soft/50 p-1 -mx-1 rounded transition-colors">
                        <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-secondary shrink-0"></span><span class="font-medium text-ink group-hover:text-secondary transition-colors">PPPK</span></div>
                        <span class="font-bold text-secondary font-mono">{{ $komposisi['PPPK'] ?? 0 }} ({{ $pppkPct }}%)</span>
                    </a>
                    @if($cpnsPct > 0)
                    <a href="{{ route('pimpinan.pegawai.index', ['jenis' => 'CPNS']) }}" class="flex items-center justify-between text-xs group hover:bg-soft/50 p-1 -mx-1 rounded transition-colors">
                        <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-warning shrink-0"></span><span class="font-medium text-ink group-hover:text-warning transition-colors">CPNS</span></div>
                        <span class="font-bold text-warning font-mono">{{ $komposisi['CPNS'] ?? 0 }} ({{ $cpnsPct }}%)</span>
                    </a>
                    @endif
                </div>
            </div>
        </x-ui.card>
        <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Kenaikan Pangkat Terdekat</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Pegawai yang dijadwalkan naik pangkat bulan ini</p>
                </div>
                <a href="{{ route('pimpinan.ews.index', ['jenis' => 'KENAIKAN_PANGKAT']) }}" class="text-xs font-semibold text-primary hover:underline font-sans">Kelola</a>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Golongan Asal</x-ui.table-th>
                            <x-ui.table-th padding="lg">Golongan Baru</x-ui.table-th>
                            <x-ui.table-th padding="lg">TMT Kenaikan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        <x-ui.table-row>
                            <x-ui.table-td padding="xl">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10"><span class="text-xs font-bold text-primary">A</span></div>
                                    <div class="min-w-0"><p class="text-xs font-bold text-ink font-sans leading-tight">Ahmad Fauzi</p><p class="text-[10px] text-muted font-sans leading-none mt-0.5">NIP. 19850312 201001 1 001</p></div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td padding="xl" class="text-muted font-medium">III/c (Penata)</x-ui.table-td>
                            <x-ui.table-td padding="xl" class="text-muted font-medium">III/d (Penata Tingkat 1)</x-ui.table-td>
                            <x-ui.table-td padding="xl" class="font-semibold font-mono">01-07-2026</x-ui.table-td>
                        </x-ui.table-row>
                        <x-ui.table-row>
                            <x-ui.table-td padding="xl">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10"><span class="text-xs font-bold text-primary">S</span></div>
                                    <div class="min-w-0"><p class="text-xs font-bold text-ink font-sans leading-tight">Siti Rahayu</p><p class="text-[10px] text-muted font-sans leading-none mt-0.5">NIP. 19901120 201501 2 003</p></div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td padding="xl" class="text-muted font-medium">II/d (Pengatur Tkt. 1)</x-ui.table-td>
                            <x-ui.table-td padding="xl" class="text-muted font-medium">III/a (Penata Muda)</x-ui.table-td>
                            <x-ui.table-td padding="xl" class="font-semibold font-mono">01-07-2026</x-ui.table-td>
                        </x-ui.table-row>
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>

    {{-- ROW 2: AKTIVITAS TERKINI (W6) & STATUS CUTI (W3) --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- W6: Statistik Audit Log --}}
        <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Aktivitas Terkini</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Log perubahan sistem kepegawaian hari ini</p>
                </div>
            </div>
            <div class="divide-y divide-border">
                @php
                $auditLog = [
                    ['user' => 'Admin HR',   'aksi' => 'Menambahkan data pegawai baru',           'target' => 'Sabrina Rossa Adriani Wibowo', 'waktu' => '2 menit lalu',  'type' => 'tambah'],
                    ['user' => 'Admin HR',   'aksi' => 'Menyetujui pengajuan cuti',               'target' => 'Siti Rahayu',                 'waktu' => '15 menit lalu', 'type' => 'setujui'],
                    ['user' => 'Supervisor', 'aksi' => 'Mengunggah dokumen SK Pengangkatan',      'target' => 'Ahmad Fauzi',                 'waktu' => '1 jam lalu',    'type' => 'unggah'],
                ];
                $auditColorMap = ['tambah' => 'bg-success/10 text-success', 'setujui' => 'bg-info/10 text-info', 'unggah' => 'bg-primary/10 text-primary'];
                @endphp
                @foreach($auditLog as $log)
                <div class="flex items-center gap-4 px-6 py-4 hover:bg-soft/30 cursor-default">
                    <div class="shrink-0 rounded-full {{ $auditColorMap[$log['type']] ?? 'bg-soft' }} p-2">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-ink font-sans leading-tight"><span class="font-bold">{{ $log['user'] }}</span> {{ $log['aksi'] }}</p>
                        <p class="text-[10px] text-muted mt-0.5 font-sans leading-none">{{ $log['target'] }}</p>
                    </div>
                    <span class="shrink-0 text-[10px] text-muted font-sans">{{ $log['waktu'] }}</span>
                </div>
                @endforeach
            </div>
        </x-ui.card>
        
        {{-- W3: Status Cuti & Cuti Pending List --}}
        <x-ui.card padding="none" class="overflow-hidden flex flex-col justify-between lg:col-span-1">
            <div>
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Otorisasi Cuti Pending</h3>
                        <p class="text-[10px] text-muted font-sans mt-0.5">Menunggu keputusan persetujuan</p>
                    </div>
                    <span class="text-xs font-bold font-sans text-warning">{{ $cutiPending ?? 0 }}</span>
                </div>
                <div class="divide-y divide-border">
                    @php
                    $cutiList = [
                        ['id' => 1, 'nama' => 'Ahmad Fauzi',  'info' => 'Tahunan · 5 hari · 20–24 Jun'],
                        ['id' => 2, 'nama' => 'Nadia Kusuma', 'info' => 'Sakit · 3 hari · 19–21 Jun'],
                        ['id' => 3, 'nama' => 'Teguh Wibowo', 'info' => 'Melahirkan · 90 hari · 1 Jul'],
                    ];
                    @endphp
                    @foreach($cutiList as $c)
                    <div class="flex items-center justify-between px-6 py-4 hover:bg-soft/30">
                        <div class="min-w-0 flex-1 pr-4">
                            <p class="text-xs font-bold text-ink font-sans">{{ $c['nama'] }}</p>
                            <p class="text-[10px] text-muted mt-0.5 font-sans leading-none">{{ $c['info'] }}</p>
                        </div>
                        <div class="ml-3 flex shrink-0 items-center gap-3">
                            <button class="text-xs font-semibold text-success hover:underline font-sans">Setuju</button>
                            <button class="text-xs font-semibold text-warning hover:underline font-sans">Tunda</button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="border-t border-border px-6 py-4 bg-soft/20 text-center">
                <a href="{{ route('pimpinan.cuti.index', ['status' => 'pending']) }}" class="text-xs font-bold text-primary hover:underline transition-all font-sans">Kelola Seluruh Pengajuan</a>
            </div>
        </x-ui.card>
    </div>

    {{-- ROW 3: DISTRIBUSI GOLONGAN (W5) & TREN PEGAWAI (W7) --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card padding="lg" class="flex flex-col justify-between lg:col-span-1">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans mb-1">Distribusi Golongan</h3>
                <p class="text-[10px] text-muted font-sans mb-5">Statistik jumlah pegawai per tingkat golongan</p>
                <div class="space-y-4">
                    @php
                    $d_gol = $distribusiGolongan ?? ['IV' => 0, 'III' => 0, 'II' => 0, 'I' => 0];
                    $distribusiMapped = [
                        ['gol' => 'Golongan IV (Pembina)', 'key' => 'IV', 'jumlah' => $d_gol['IV'] ?? 0, 'persen' => round(($d_gol['IV'] ?? 0) / $tot * 100, 1).'%'],
                        ['gol' => 'Golongan III (Penata)', 'key' => 'III', 'jumlah' => $d_gol['III'] ?? 0, 'persen' => round(($d_gol['III'] ?? 0) / $tot * 100, 1).'%'],
                        ['gol' => 'Golongan II (Pengatur)', 'key' => 'II', 'jumlah' => $d_gol['II'] ?? 0, 'persen' => round(($d_gol['II'] ?? 0) / $tot * 100, 1).'%'],
                        ['gol' => 'Golongan I (Juru)',     'key' => 'I', 'jumlah' => $d_gol['I'] ?? 0, 'persen' => round(($d_gol['I'] ?? 0) / $tot * 100, 1).'%'],
                    ];
                    @endphp
                    @foreach($distribusiMapped as $dg)
                    <div class="block group">
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-medium text-ink">{{ $dg['gol'] }}</span>
                            <span class="font-bold text-primary font-mono">{{ $dg['jumlah'] }} ({{ $dg['persen'] }})</span>
                        </div>
                        <div class="h-2 w-full bg-soft rounded-full overflow-hidden">
                            <div class="h-2 rounded-full bg-primary" style="width: {{ $dg['persen'] }}"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
        
        <x-ui.card padding="lg" class="lg:col-span-2 flex flex-col justify-between">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans mb-1">Tren Pegawai Aktif</h3>
                <p class="text-[10px] text-muted font-sans mb-4">Grafik jumlah pegawai aktif bulanan (12 Bulan Terakhir)</p>
                <div class="w-full h-44 py-2">
                    <svg class="w-full h-full" viewBox="0 0 500 150">
                        <line x1="40" y1="20" x2="480" y2="20" stroke="#F3F4F6" stroke-width="1"></line>
                        <line x1="40" y1="55" x2="480" y2="55" stroke="#F3F4F6" stroke-width="1"></line>
                        <line x1="40" y1="90" x2="480" y2="90" stroke="#F3F4F6" stroke-width="1"></line>
                        <line x1="40" y1="125" x2="480" y2="125" stroke="#E5E7EB" stroke-width="1.5"></line>
                        <path d="M 40 110 C 80 112, 120 108, 160 95 C 200 82, 240 75, 280 62 C 320 49, 360 40, 400 38 C 440 36, 460 30, 480 25" fill="none" stroke="#122E92" stroke-width="3.5" stroke-linecap="round"></path>
                        <path d="M 40 110 C 80 112, 120 108, 160 95 C 200 82, 240 75, 280 62 C 320 49, 360 40, 400 38 C 440 36, 460 30, 480 25 L 480 125 L 40 125 Z" fill="url(#chart-grad)" opacity="0.08"></path>
                        <defs>
                            <linearGradient id="chart-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#122E92"></stop>
                                <stop offset="100%" stop-color="#122E92" stop-opacity="0"></stop>
                            </linearGradient>
                        </defs>
                        <circle cx="40" cy="110" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="160" cy="95" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="280" cy="62" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="400" cy="38" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="480" cy="25" r="4.5" fill="#122E92" stroke="#FFFFFF" stroke-width="1.5"></circle>
                        <text x="35" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Jul</text>
                        <text x="155" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Okt</text>
                        <text x="275" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Jan</text>
                        <text x="395" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Apr</text>
                        <text x="473" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Jun</text>
                        <text x="10" y="23" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">230</text>
                        <text x="10" y="128" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">140</text>
                    </svg>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- ROW 4: EWS AKTIF (W4) TABLE --}}
    <div id="ews-section" class="mt-6">
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Daftar EWS Aktif</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Peringatan otomatis masa berlaku dokumen & kepegawaian</p>
                </div>
                <a href="{{ $dashboardEwsLink }}" class="text-xs font-semibold text-primary hover:underline font-sans">Lihat Semua</a>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Pemicu EWS</x-ui.table-th>
                            <x-ui.table-th padding="lg">Sisa Waktu</x-ui.table-th>
                            <x-ui.table-th padding="lg">Prioritas</x-ui.table-th>
                            <x-ui.table-th align="right" padding="lg">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($dashboardEwsAlerts as $alert)
                            @php
                                $urgencyVariant = match ($alert['indikator'] ?? 'hijau') { 'merah' => 'danger', 'kuning' => 'warning', default => 'success' };
                                $urgencyLabel = match ($alert['indikator'] ?? 'hijau') { 'merah' => 'Urgent', 'kuning' => 'Warning', default => 'Informasi' };
                                $statusVariant = match ($alert['status'] ?? '') { 'Aman' => 'success', 'Belum Diproses' => 'warning', default => 'primary' };
                            @endphp
                            <x-ui.table-row>
                                <x-ui.table-td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10"><span class="text-xs font-bold text-primary">{{ strtoupper(substr($alert['nama'], 0, 1)) }}</span></div>
                                        <div class="min-w-0"><p class="text-xs font-bold text-ink font-sans leading-tight">{{ $alert['nama'] }}</p><p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. {{ $alert['nip'] }}</p></div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5"><div class="text-xs font-semibold text-ink">{{ $alert['jenis'] ?? '-' }}</div></x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5 font-medium">{{ $alert['sisa_hari'] }} Hari Lagi</x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5"><x-ui.badge :variant="$urgencyVariant" size="md" :pill="false" dot>{{ $urgencyLabel }}</x-ui.badge></x-ui.table-td>
                                <x-ui.table-td align="right" class="px-6 py-3.5"><x-ui.badge :variant="$statusVariant" size="md" dot>{{ $alert['status'] ?? '-' }}</x-ui.badge></x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row><x-ui.table-td colspan="5" align="center" class="px-6 py-10 text-sm text-muted">Tidak ada peringatan EWS aktif.</x-ui.table-td></x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>

</x-layouts.app>
