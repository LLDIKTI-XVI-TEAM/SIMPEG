<x-layouts.app title="Dashboard Pimpinan" subtitle="Ringkasan data kepegawaian terkini.">
    @php
        $employeeTotal = max($totalPegawai, 1);
    @endphp

    <div class="space-y-6">
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
                    {{ preg_replace('/\s*\(.*?\)/', '', auth()->user()->name) }}
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
                        <div class="flex flex-col mt-0.5">
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
                        <div class="flex flex-col mt-0.5">
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

        {{-- ================================================================ --}}
        {{-- KPI CARDS — 4 KOLOM (W1 - W4) --}}
        {{-- ================================================================ --}}
        <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">

            {{-- W1: Total Pegawai Aktif --}}
            <x-ui.stat-card href="{{ route('pimpinan.pegawai.index') }}" label="Total Pegawai Aktif" value="{{ $totalPegawai }}" variant="primary" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span class="font-medium text-ink">{{ ($komposisi['PNS'] ?? 0) }} PNS · {{ ($komposisi['PPPK'] ?? 0) }} PPPK · {{ ($komposisi['CPNS'] ?? 0) }} CPNS</span>
                    <span>Per {{ now()->translatedFormat('d M Y') }}</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- W2: Kenaikan Pangkat --}}
            <x-ui.stat-card href="{{ route('pimpinan.ews.index', ['event' => 'Kenaikan Pangkat']) }}" label="Kenaikan Pangkat" value="{{ $naikPangkatBulanIni }}" variant="success" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l2.74-1.22m0 0-5.94-2.28m5.94 2.28-2.28 5.94" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span class="font-medium text-ink">{{ $naikPangkatBulanIni }} bln ini · {{ $naikPangkatTahunIni }} thn ini</span>
                    <span>Per {{ now()->translatedFormat('d M Y') }}</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- W3: Status Cuti --}}
            <x-ui.stat-card href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu']) }}" label="Cuti Menunggu" value="{{ $cutiPending }}" variant="warning" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span class="font-medium text-ink">{{ $cutiDisetujuiBulanIni }} disetujui bulan ini · {{ $cutiDitunda }} ditangguhkan (semua periode)</span>
                    <span>Bulan berjalan</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- W4: EWS Aktif --}}
            <x-ui.stat-card href="{{ route('pimpinan.ews.index') }}" label="EWS Aktif" value="{{ $totalEwsAktif ?? 0 }}" variant="danger" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span class="font-semibold text-danger">Peringatan</span>
                    <span>Tabel 5 teratas di bawah</span>
                </x-slot:meta>
            </x-ui.stat-card>
        </div>

        {{-- ================================================================ --}}
        {{-- ROW 1: KENAIKAN PANGKAT (W2) & KOMPOSISI PEGAWAI (W1 - PIE/DONUT) --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            
            {{-- W2: Kenaikan Pangkat Detail --}}
            <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Kenaikan Pangkat Bulan Ini</h3>
                        <p class="text-xs text-muted">Riwayat kepangkatan dengan TMT pada bulan berjalan</p>
                    </div>
                    <a href="{{ route('pimpinan.laporan.kepangkatan') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                        Buka laporan
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="lg">Golongan (Asal → Tujuan)</x-ui.table-th>
                                <x-ui.table-th padding="lg">TMT</x-ui.table-th>
                                <x-ui.table-th padding="lg">Nomor SK</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse($promotionRows as $row)
                                <x-ui.table-row :interactive="true" class="cursor-pointer border-b border-border/50 group">
                                    <x-ui.table-td padding="xl">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                                <span class="text-xs font-bold text-primary">{{ strtoupper(substr($row['nama'], 0, 1)) }}</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold text-ink font-sans leading-tight">{{ $row['nama'] }}</p>
                                                <p class="text-[10px] text-muted font-sans mt-0.5">NIP. {{ $row['nip'] }}</p>
                                            </div>
                                        </div>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="text-muted font-medium text-xs">
                                        @if(!empty($row['golongan_awal']) && !empty($row['golongan_tujuan']))
                                            <span class="text-ink font-semibold">{{ $row['golongan_awal'] }}</span>
                                            <span class="text-muted mx-1">→</span>
                                            <span class="text-primary font-bold">{{ $row['golongan_tujuan'] }}</span>
                                        @else
                                            <span>{{ $row['golongan'] }}</span>
                                        @endif
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="font-semibold text-xs">{{ \Carbon\Carbon::parse($row['tmt'])->translatedFormat('d M Y') }}</x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="text-muted text-xs">{{ $row['no_sk'] }}</x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row><x-ui.table-td colspan="4" align="center" class="py-8 text-xs text-muted">Tidak ada riwayat kenaikan pangkat pada bulan ini.</x-ui.table-td></x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>

            {{-- W1: Komposisi Pegawai PNS vs PPPK (SVG Pie/Donut Chart) --}}
            <x-ui.card padding="lg" class="flex flex-col justify-between">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Komposisi Kepegawaian</h3>
                    <p class="mt-0.5 text-xs text-muted font-sans mb-5">Rasio Pegawai PNS vs PPPK</p>
                    
                    @php
                        $pnsCount = $komposisi['PNS'] ?? 0;
                        $pppkCount = $komposisi['PPPK'] ?? 0;
                        $cpnsCount = $komposisi['CPNS'] ?? 0;
                        
                        $pnsPercent = $employeeTotal > 0 ? round(($pnsCount / $employeeTotal) * 100, 1) : 0;
                        $pppkPercent = $employeeTotal > 0 ? round(($pppkCount / $employeeTotal) * 100, 1) : 0;
                        $cpnsPercent = $employeeTotal > 0 ? round(($cpnsCount / $employeeTotal) * 100, 1) : 0;
                        
                        $pnsDash = $employeeTotal > 0 ? ($pnsCount / $employeeTotal) * 100 : 0;
                        $pppkDash = $employeeTotal > 0 ? ($pppkCount / $employeeTotal) * 100 : 0;
                        $cpnsDash = $employeeTotal > 0 ? ($cpnsCount / $employeeTotal) * 100 : 0;
                    @endphp

                    {{-- SVG Donut Chart --}}
                    <div class="flex items-center justify-center py-2">
                        <div class="relative flex items-center justify-center h-32 w-32">
                            <svg class="w-full h-full -rotate-90" viewBox="0 0 36 36">
                                {{-- Base background circle --}}
                                <circle class="text-soft" stroke="currentColor" stroke-width="4.5" fill="none" cx="18" cy="18" r="15.915"></circle>
                                {{-- PNS stroke --}}
                                <circle class="text-primary" stroke="currentColor" stroke-width="4.5" stroke-dasharray="{{ $pnsDash }} {{ 100 - $pnsDash }}" stroke-dashoffset="0" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                                {{-- PPPK stroke --}}
                                @if($pppkDash > 0)
                                <circle class="text-secondary" stroke="currentColor" stroke-width="4.5" stroke-dasharray="{{ $pppkDash }} {{ 100 - $pppkDash }}" stroke-dashoffset="-{{ $pnsDash }}" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                                @endif
                                {{-- CPNS stroke --}}
                                @if($cpnsDash > 0)
                                <circle class="text-warning" stroke="currentColor" stroke-width="4.5" stroke-dasharray="{{ $cpnsDash }} {{ 100 - $cpnsDash }}" stroke-dashoffset="-{{ $pnsDash + $pppkDash }}" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                                @endif
                            </svg>
                            <div class="absolute flex flex-col items-center justify-center">
                                <span class="text-xl font-extrabold text-ink leading-none">{{ $totalPegawai }}</span>
                                <span class="text-[9px] text-muted font-sans font-bold uppercase tracking-wider mt-1">Aktif</span>
                            </div>
                        </div>
                    </div>

                    {{-- Legend --}}
                    <div class="mt-6 space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full bg-primary shrink-0"></span>
                                <span class="font-medium text-ink">PNS</span>
                            </div>
                            <span class="font-bold text-primary">{{ $pnsCount }} ({{ $pnsPercent }}%)</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full bg-secondary shrink-0"></span>
                                <span class="font-medium text-ink">PPPK</span>
                            </div>
                            <span class="font-bold text-secondary">{{ $pppkCount }} ({{ $pppkPercent }}%)</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full bg-warning shrink-0"></span>
                                <span class="font-medium text-ink">CPNS</span>
                            </div>
                            <span class="font-bold text-warning">{{ $cpnsCount }} ({{ $cpnsPercent }}%)</span>
                        </div>
                    </div>
                </div>
            </x-ui.card>

        </div>

        {{-- ================================================================ --}}
        {{-- ROW 2: EWS AKTIF (W4) & STATUS CUTI / CUTI PENDING (W3)          --}}
        {{-- ================================================================ --}}
        <div id="ews-section" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            
            {{-- W4: EWS Aktif --}}
            <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Daftar EWS Aktif</h3>
                        <p class="text-xs text-muted">Lima peringatan aktif dengan tanggal target terdekat</p>
                    </div>
                    <a href="{{ route('pimpinan.ews.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                        Lihat Semua
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="lg">Pemicu EWS</x-ui.table-th>
                                <x-ui.table-th padding="lg">Sisa Waktu</x-ui.table-th>
                                <x-ui.table-th padding="lg">Prioritas</x-ui.table-th>
                                <x-ui.table-th align="right" padding="lg">Tindak Lanjut</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse($ewsAktif as $alert)
                                @php
                                    $urgencyVariant = match ($alert['urgency']) {
                                        'danger' => 'danger',
                                        'warning' => 'warning',
                                        default => 'success',
                                    };
                                    $urgencyLabel = match ($alert['urgency']) {
                                        'danger' => 'Urgent',
                                        'warning' => 'Warning',
                                        default => 'Informasi',
                                    };
                                    $statusVariant = match ($alert['followup_status'] ?? '') {
                                        'ditangani' => 'success',
                                        'tidak_perlu' => 'muted',
                                        'kedaluwarsa' => 'warning',
                                        default => 'primary',
                                    };
                                @endphp
                                <x-ui.table-row :interactive="false" class="border-b border-border/50 group">
                                    <x-ui.table-td class="px-6 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                                <span class="text-xs font-bold text-primary">{{ strtoupper(substr($alert['nama'], 0, 1)) }}</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold text-ink font-sans leading-tight">{{ $alert['nama'] }}</p>
                                                <p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. {{ $alert['nip'] }}</p>
                                            </div>
                                        </div>
                                    </x-ui.table-td>
                                    <x-ui.table-td class="px-6 py-3.5">
                                        <div class="text-xs font-semibold text-ink">{{ $alert['jenis_event'] }}</div>
                                        <div class="mt-1 text-[10px] text-muted">{{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</div>
                                    </x-ui.table-td>
                                    <x-ui.table-td class="px-6 py-3.5 font-medium text-xs">{{ $alert['sisa_hari'] }} Hari Lagi</x-ui.table-td>
                                    <x-ui.table-td class="px-6 py-3.5">
                                        <x-ui.badge :variant="$urgencyVariant" size="md" :pill="false" dot>
                                            {{ $urgencyLabel }}
                                        </x-ui.badge>
                                    </x-ui.table-td>
                                    <x-ui.table-td align="right" class="px-6 py-3.5">
                                        <x-ui.badge :variant="$statusVariant" size="md" dot>
                                            {{ $alert['followup_status_label'] ?? 'Menunggu' }}
                                        </x-ui.badge>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="5" align="center" class="px-6 py-10 text-sm text-muted">
                                        Tidak ada peringatan EWS aktif.
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>

            {{-- W3: Status Cuti & Cuti Pending List --}}
            <x-ui.card padding="none" class="overflow-hidden flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Cuti Menunggu Tindakan</h3>
                            <p class="text-xs text-muted">Hanya pengajuan pada tahap Pimpinan</p>
                        </div>
                        <span class="text-xs font-bold font-sans text-warning">{{ $pendingLeaves->count() }}</span>
                    </div>
                    <div class="divide-y divide-border">
                        @forelse($pendingLeaves as $leave)
                        <div class="flex items-center justify-between px-6 py-4 transition-colors hover:bg-soft/30">
                            <div class="min-w-0 flex-1 pr-4">
                                <p class="text-xs font-bold text-ink font-sans">{{ $leave['nama'] }}</p>
                                <p class="text-[10px] text-muted mt-0.5 font-sans leading-none">{{ $leave['jenis'] }} · {{ $leave['hari'] }} hari kerja · {{ \Carbon\Carbon::parse($leave['mulai'])->translatedFormat('d M') }}–{{ \Carbon\Carbon::parse($leave['selesai'])->translatedFormat('d M Y') }}</p>
                            </div>
                            <div class="ml-3 flex shrink-0 items-center gap-3">
                                <a href="{{ route('pimpinan.cuti.show', $leave['id']) }}" class="text-xs font-semibold text-primary hover:underline transition-colors font-sans focus:outline-none">
                                    Buka Detail
                                </a>
                            </div>
                        </div>
                        @empty
                        <div class="px-6 py-10 text-center text-xs text-muted">
                            Tidak ada pengajuan cuti yang menunggu tindakan Anda.
                        </div>
                        @endforelse
                    </div>
                </div>
                <div class="border-t border-border px-6 py-4 bg-soft/20 text-center">
                    <a href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu']) }}" class="text-xs font-bold text-primary hover:underline transition-all font-sans">
                        Buka antrean persetujuan cuti
                    </a>
                </div>
            </x-ui.card>

        </div>

        {{-- ================================================================ --}}
        {{-- ROW 3: DISTRIBUSI GOLONGAN (W5) & TREN PEGAWAI (W7) --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            
            {{-- W5: Distribusi Golongan (Horizontal Bar Chart) --}}
            <x-ui.card padding="lg" class="flex flex-col justify-between">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Distribusi Golongan</h3>
                    <p class="mt-0.5 text-xs text-muted font-sans mb-5">Statistik jumlah pegawai per tingkat golongan</p>
                    
                    <div class="space-y-4">
                        @forelse($distribusiGolongan as $rank => $count)
                            @php
                                $percent = $employeeTotal > 0 ? round(($count / $employeeTotal) * 100, 1) : 0;
                            @endphp
                            <div>
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="font-medium text-ink">Golongan {{ $rank }}</span>
                                    <span class="font-bold text-primary">{{ $count }} ({{ $percent }}%)</span>
                                </div>
                                <div class="h-2 w-full bg-soft rounded-full overflow-hidden">
                                    <div class="h-2 rounded-full bg-primary" style="width: {{ $percent }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">Belum ada golongan yang tercatat.</p>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>

            {{-- W7: Tren Pegawai Aktif (SVG Line Chart) --}}
            <x-ui.card padding="lg" class="lg:col-span-2 flex flex-col justify-between">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Tren Pegawai Aktif</h3>
                    <p class="mt-0.5 text-xs text-muted font-sans mb-4">Grafik jumlah pegawai aktif bulanan dari data yang tersimpan</p>
                    
                    @php
                        // Menghitung poin-poin SVG secara dinamis berdasarkan data trend ($trenPegawai).
                        $points = $trendPoints ?? [];
                        $pathD = $trendPathD ?? '';
                        $maxVal = $trendMaxVal ?? 10;
                        $count = count($points);
                    @endphp

                    {{-- SVG Line Chart --}}
                    <div class="w-full h-44 py-2 relative">
                        @if($count > 1)
                        <svg class="w-full h-full" viewBox="0 0 500 150" preserveAspectRatio="none">
                            {{-- Grids --}}
                            <line x1="40" y1="25" x2="480" y2="25" stroke="#F3F4F6" stroke-width="1"></line>
                            <line x1="40" y1="58" x2="480" y2="58" stroke="#F3F4F6" stroke-width="1"></line>
                            <line x1="40" y1="91" x2="480" y2="91" stroke="#F3F4F6" stroke-width="1"></line>
                            <line x1="40" y1="125" x2="480" y2="125" stroke="#E5E7EB" stroke-width="1.5"></line>

                            {{-- Chart Path with smooth curves --}}
                            <path d="{{ $pathD }}" fill="none" stroke="#122E92" stroke-width="3.5" stroke-linecap="round"></path>
                            
                            {{-- Area under path with gradient --}}
                            <path d="{{ $pathD }} L {{ end($points)['x'] }} 125 L {{ $points[0]['x'] }} 125 Z" fill="url(#chart-grad)" opacity="0.08"></path>

                            {{-- Defs for linear gradient --}}
                            <defs>
                                <linearGradient id="chart-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                    <stop offset="0%" stop-color="#122E92"></stop>
                                    <stop offset="100%" stop-color="#122E92" stop-opacity="0"></stop>
                                </linearGradient>
                            </defs>

                            {{-- Dots on peaks and Labels --}}
                            @foreach($points as $idx => $pt)
                                @if($idx === count($points) - 1)
                                    <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="4.5" fill="#122E92" stroke="#FFFFFF" stroke-width="1.5"></circle>
                                @else
                                    <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                                @endif
                                <text x="{{ $pt['x'] - 8 }}" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">{{ $pt['label'] }}</text>
                            @endforeach

                            {{-- Left Axis values --}}
                            <text x="10" y="30" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">{{ round($maxVal) }}</text>
                            <text x="10" y="78" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">{{ round($maxVal/2) }}</text>
                            <text x="10" y="128" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">0</text>
                        </svg>
                        @else
                            <div class="absolute inset-0 flex items-center justify-center text-sm text-muted">Data trend belum cukup untuk digambar.</div>
                        @endif
                    </div>
                </div>
            </x-ui.card>

        </div>

        {{-- ================================================================ --}}
        {{-- ROW 4: AKTIVITAS TERKINI (W6)                  --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            
            {{-- W6: Statistik Audit Log (5 Terbaru) --}}
            <x-ui.card padding="none" id="audit-log" class="overflow-hidden lg:col-span-3">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Aktivitas Terkini</h3>
                        <p class="text-xs text-muted">Lima audit log terbaru yang tersedia untuk pemantauan</p>
                    </div>
                </div>
                <div class="divide-y divide-border">
                    @forelse($auditTerbaru as $audit)
                    <div class="flex items-start gap-4 px-6 py-4 transition-colors hover:bg-soft/30">
                        <div class="mt-0.5 shrink-0 text-primary">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-ink font-sans"><span class="font-bold">{{ $audit['user'] }}</span> &middot; {{ $audit['aksi'] }}</p>
                            <div class="mt-1 flex items-center gap-2 text-[10px] text-muted font-sans">
                                <span>{{ $audit['target'] }}</span>
                                <span>&bull;</span>
                                <span>{{ $audit['waktu'] }}</span>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="px-6 py-10 text-center text-sm text-muted">
                        Belum ada aktivitas yang tercatat.
                    </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

    </div>
</x-layouts.app>
