<x-layouts.app title="Dashboard" subtitle="Ringkasan operasional kepegawaian dan pemantauan tindak lanjut.">
    @php
        $dashboardEwsAlerts = collect($dashboardEwsAlerts ?? [])->take(5);
        $dashboardEwsTotal = $dashboardEwsTotal ?? $dashboardEwsAlerts->count();
        $dashboardEwsUrgent = $dashboardEwsUrgent ?? $dashboardEwsAlerts->where('urgency', 'danger')->count();
        $dashboardEwsWarning = $dashboardEwsWarning ?? $dashboardEwsAlerts->where('urgency', 'warning')->count();
        $dashboardEwsInfo = $dashboardEwsInfo ?? $dashboardEwsAlerts->where('urgency', 'success')->count();
        $dashboardEwsLink = $dashboardEwsLink ?? route('ews');

        // K-3: variabel di bawah dikirim BuildAdminDashboardAction.
        // Nilai null berarti backend belum menyediakan payload, bukan angka nol.
        $totalPegawaiAktif = $totalPegawaiAktif ?? null;
        $komposisiPegawai = $komposisiPegawai ?? null;
        $kenaikanPangkatBulanIni = $kenaikanPangkatBulanIni ?? null;
        $kenaikanPangkatTahunIni = $kenaikanPangkatTahunIni ?? null;
        $daftarKenaikanPangkat = $daftarKenaikanPangkat ?? null;
        $cutiMenunggu = $cutiMenunggu ?? null;
        $cutiDisetujuiBulanIni = $cutiDisetujuiBulanIni ?? null;
        $cutiDitangguhkan = $cutiDitangguhkan ?? null;
        $distribusiGolongan = $distribusiGolongan ?? null;
        $auditTerbaru = $auditTerbaru ?? null;
        $trenPegawai = $trenPegawai ?? null;

        $formatNumber = static fn (int|float|string|null $value): string => number_format((int) $value, 0, ',', '.');

        $compositionCounts = collect($komposisiPegawai ?? [])
            ->mapWithKeys(fn ($jumlah, $jenis): array => [(string) $jenis => max(0, (int) $jumlah)]);
        $compositionOrder = collect(['PNS', 'PPPK', 'CPNS'])
            ->merge($compositionCounts->keys()->reject(fn (string $jenis): bool => in_array($jenis, ['PNS', 'PPPK', 'CPNS'], true)))
            ->unique()
            ->values();
        $compositionTotal = $compositionCounts->sum();
        $compositionDisplayTotal = $totalPegawaiAktif ?? $compositionTotal;
        $compositionOffset = 0;
        $compositionTones = [
            ['stroke' => 'text-primary', 'dot' => 'bg-primary', 'text' => 'text-ink'],
            ['stroke' => 'text-secondary', 'dot' => 'bg-secondary', 'text' => 'text-ink'],
            ['stroke' => 'text-info', 'dot' => 'bg-info', 'text' => 'text-ink'],
            ['stroke' => 'text-muted', 'dot' => 'bg-muted', 'text' => 'text-ink'],
        ];
        $compositionRows = $compositionOrder
            ->map(function (string $jenis, int $index) use ($compositionCounts, $compositionTotal, &$compositionOffset, $compositionTones): array {
                $jumlah = $compositionCounts->get($jenis, 0);
                $persen = $compositionTotal > 0 ? ($jumlah / $compositionTotal) * 100 : 0;
                $row = [
                    'jenis' => $jenis,
                    'jumlah' => $jumlah,
                    'persen' => $persen,
                    'offset' => $compositionOffset,
                    'tone' => $compositionTones[$index % count($compositionTones)],
                ];
                $compositionOffset += $persen;

                return $row;
            })
            ->filter(fn (array $row): bool => $row['jumlah'] > 0)
            ->values();

        $promotionRows = collect($daftarKenaikanPangkat ?? []);
        $rankOrder = [
            'I/a', 'I/b', 'I/c', 'I/d',
            'II/a', 'II/b', 'II/c', 'II/d',
            'III/a', 'III/b', 'III/c', 'III/d',
            'IV/a', 'IV/b', 'IV/c', 'IV/d', 'IV/e',
            'Belum Diisi',
        ];
        $rankCounts = collect($distribusiGolongan ?? [])
            ->mapWithKeys(fn ($jumlah, $golongan): array => [(string) $golongan => max(0, (int) $jumlah)]);
        $rankRows = collect($rankOrder)
            ->merge($rankCounts->keys()->reject(fn (string $golongan): bool => in_array($golongan, $rankOrder, true)))
            ->unique()
            ->map(fn (string $golongan): array => [
                'golongan' => $golongan,
                'jumlah' => $rankCounts->get($golongan, 0),
            ])
            ->values();
        $rankTotal = $rankRows->sum('jumlah');
        $rankMax = max(1, (int) $rankRows->max('jumlah'));

        $auditRows = collect($auditTerbaru ?? []);
        $trendRows = collect($trenPegawai ?? [])
            ->map(fn ($point): array => [
                'label' => (string) data_get($point, 'label', ''),
                'jumlah' => max(0, (int) data_get($point, 'jumlah', 0)),
            ])
            ->values();
        $trendMax = (int) ($trendRows->max('jumlah') ?? 0);
        $trendMin = (int) ($trendRows->min('jumlah') ?? 0);
        $trendRange = max(1, $trendMax - $trendMin);
        $trendChart = ['width' => 720, 'height' => 256, 'left' => 42, 'right' => 18, 'top' => 24, 'bottom' => 42];
        $trendPlotWidth = $trendChart['width'] - $trendChart['left'] - $trendChart['right'];
        $trendPlotHeight = $trendChart['height'] - $trendChart['top'] - $trendChart['bottom'];
        $trendPoints = $trendRows
            ->map(function (array $point, int $index) use ($trendRows, $trendChart, $trendPlotWidth, $trendPlotHeight, $trendMax, $trendRange): array {
                $count = max(1, $trendRows->count());
                $x = $count === 1
                    ? $trendChart['left'] + ($trendPlotWidth / 2)
                    : $trendChart['left'] + (($trendPlotWidth / ($count - 1)) * $index);
                $y = $trendChart['top'] + (($trendMax - $point['jumlah']) / $trendRange) * $trendPlotHeight;

                return [
                    ...$point,
                    'x' => round($x, 2),
                    'y' => round($y, 2),
                ];
            })
            ->values();
        $trendPolyline = $trendPoints->map(fn (array $point): string => "{$point['x']},{$point['y']}")->implode(' ');
        $trendBaselineY = $trendChart['height'] - $trendChart['bottom'];
        $trendAreaPath = $trendPoints->isNotEmpty()
            ? 'M '.$trendPoints->first()['x'].' '.$trendBaselineY
                .' L '.$trendPolyline
                .' L '.$trendPoints->last()['x'].' '.$trendBaselineY.' Z'
            : '';
        $trendMid = (int) round(($trendMax + $trendMin) / 2);
    @endphp

    <div class="relative w-full rounded-2xl bg-gradient-to-r from-[#173292] to-[#2143c2] p-6 sm:p-8 overflow-hidden shadow-lg border border-primary/20 flex flex-col lg:flex-row items-center justify-between mb-6" style="background: linear-gradient(90deg, #173292 0%, #2143c2 100%);">
        <!-- Abstract Background Pattern -->
        <div class="absolute inset-0 pointer-events-none overflow-hidden rounded-2xl" aria-hidden="true">
            <div class="absolute top-2 right-4 opacity-20">
                <svg width="40" height="30" fill="currentColor" class="text-white">
                    <pattern id="dashboard-dots-admin" x="0" y="0" width="8" height="8" patternUnits="userSpaceOnUse">
                        <circle cx="1.5" cy="1.5" r="1.5"></circle>
                    </pattern>
                    <rect width="40" height="30" fill="url(#dashboard-dots-admin)"></rect>
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

    <section aria-label="Ringkasan utama" class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card data-k3-metric="pegawai-aktif" href="{{ route('data-pegawai') }}" label="Total Pegawai Aktif" value="{{ $totalPegawaiAktif === null ? '—' : $formatNumber($totalPegawaiAktif) }}" variant="primary" size="lg" accent>
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
            </x-slot:icon>
            <x-slot:meta>
                @if ($totalPegawaiAktif === null)
                    <span>Data belum tersedia.</span>
                @else
                    <span class="font-medium text-ink">
                        PNS {{ $formatNumber($compositionCounts->get('PNS', 0)) }}
                        &middot; PPPK {{ $formatNumber($compositionCounts->get('PPPK', 0)) }}
                    </span>
                    <span>Per {{ now()->translatedFormat('d F Y') }}</span>
                @endif
            </x-slot:meta>
        </x-ui.stat-card>

        <x-ui.stat-card data-k3-metric="kenaikan-pangkat" href="{{ route('data-pegawai') }}" label="Kenaikan Pangkat" value="{{ $kenaikanPangkatBulanIni === null ? '—' : $formatNumber($kenaikanPangkatBulanIni) }}" variant="success" size="lg" accent>
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m2.25 18 9-6.75 4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l.38-.169m0 0-5.94-2.28m5.94 2.28-2.28 5.94" />
                </svg>
            </x-slot:icon>
            <x-slot:meta>
                @if ($kenaikanPangkatBulanIni === null)
                    <span>Data belum tersedia.</span>
                @else
                    <span class="font-medium text-ink">{{ $formatNumber($kenaikanPangkatTahunIni) }} sepanjang tahun ini</span>
                    <span>Jadwal bulan berjalan</span>
                @endif
            </x-slot:meta>
        </x-ui.stat-card>

        <x-ui.stat-card data-k3-metric="cuti-menunggu" href="{{ route('cuti') }}" label="Cuti Menunggu" value="{{ $cutiMenunggu === null ? '—' : $formatNumber($cutiMenunggu) }}" variant="warning" size="lg" accent>
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
            </x-slot:icon>
            <x-slot:meta>
                @if ($cutiMenunggu === null)
                    <span>Data belum tersedia.</span>
                @else
                    <span class="font-medium text-ink">{{ $formatNumber($cutiDisetujuiBulanIni) }} disetujui bulan ini</span>
                    <span>{{ $formatNumber($cutiDitangguhkan) }} ditangguhkan</span>
                @endif
            </x-slot:meta>
        </x-ui.stat-card>

        <x-ui.stat-card href="{{ $dashboardEwsLink }}" label="EWS Aktif" value="{{ $formatNumber($dashboardEwsTotal) }}" variant="danger" size="lg" accent>
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
            </x-slot:icon>
            <x-slot:meta>
                <span class="font-semibold text-danger">{{ $formatNumber($dashboardEwsUrgent) }} urgent &middot; {{ $formatNumber($dashboardEwsWarning) }} peringatan</span>
                <span>{{ $formatNumber($dashboardEwsInfo) }} informasi</span>
            </x-slot:meta>
        </x-ui.stat-card>
    </section>

    <section class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3" aria-label="Kenaikan pangkat dan komposisi pegawai">
        <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
            <div class="flex items-center justify-between border-b border-border bg-surface px-6 py-4">
                <div>
                    <h3 class="text-sm font-bold text-ink">Kenaikan Pangkat Terdekat</h3>
                    <p class="text-xs text-muted">Pegawai yang dijadwalkan naik pangkat bulan ini.</p>
                </div>
                <a href="{{ route('data-pegawai') }}" class="text-xs font-semibold text-primary transition-colors hover:underline focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                    Kelola
                </a>
            </div>

            @if ($daftarKenaikanPangkat === null)
                <x-ui.empty-state icon="none" title="Data kenaikan pangkat belum tersedia." />
            @elseif ($promotionRows->isEmpty())
                <x-ui.empty-state icon="none" title="Tidak ada kenaikan pangkat pada bulan ini." />
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="lg">Golongan Asal</x-ui.table-th>
                                <x-ui.table-th padding="lg">Golongan Tujuan</x-ui.table-th>
                                <x-ui.table-th padding="lg">TMT Kenaikan</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach ($promotionRows as $promotion)
                                @php
                                    $nama = (string) data_get($promotion, 'nama', '—');
                                    $nip = (string) data_get($promotion, 'nip', '—');
                                    $tanggal = data_get($promotion, 'tanggal');
                                    $golonganAwal = data_get($promotion, 'golongan_awal');
                                @endphp
                                <x-ui.table-row>
                                    <x-ui.table-td padding="xl">
                                        <div class="flex min-w-52 items-center gap-3">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary" aria-hidden="true">
                                                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($nama, 0, 1)) }}
                                            </div>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-ink">{{ $nama }}</p>
                                                <p class="mt-0.5 font-mono text-xs text-muted">NIP. {{ $nip }}</p>
                                            </div>
                                        </div>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="text-sm text-muted">
                                        {{ filled($golonganAwal) ? $golonganAwal : 'Belum ada riwayat sebelumnya' }}
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="text-sm font-semibold text-ink">
                                        {{ data_get($promotion, 'golongan_tujuan', '—') }}
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="whitespace-nowrap text-sm text-ink">
                                        {{ filled($tanggal) ? \Illuminate\Support\Carbon::parse($tanggal)->translatedFormat('d M Y') : '—' }}
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card padding="lg">
            <div>
                <h3 class="mb-1 text-sm font-bold text-ink">Komposisi Kepegawaian</h3>
                <p class="text-[10px] text-muted">Rasio pegawai aktif per jenis.</p>
            </div>

            @if ($komposisiPegawai === null)
                <x-ui.empty-state icon="none" title="Komposisi pegawai belum tersedia." />
            @elseif ($compositionTotal === 0)
                <x-ui.empty-state icon="none" title="Belum ada pegawai aktif untuk ditampilkan." />
            @else
                <div class="mt-5 flex justify-center">
                    <div class="relative h-40 w-40">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 36 36" role="img" aria-labelledby="composition-chart-title composition-chart-description">
                            <title id="composition-chart-title">Komposisi pegawai aktif</title>
                            <desc id="composition-chart-description">
                                @foreach ($compositionRows as $row)
                                    {{ $row['jenis'] }} {{ $formatNumber($row['jumlah']) }} pegawai{{ $loop->last ? '.' : ',' }}
                                @endforeach
                            </desc>
                            <circle class="text-soft" stroke="currentColor" stroke-width="4.5" fill="none" cx="18" cy="18" r="15.915"></circle>
                            @foreach ($compositionRows as $row)
                                <circle
                                    class="{{ $row['tone']['stroke'] }}"
                                    stroke="currentColor"
                                    stroke-width="4.5"
                                    stroke-dasharray="{{ number_format($row['persen'], 3, '.', '') }} 100"
                                    stroke-dashoffset="{{ number_format(-$row['offset'], 3, '.', '') }}"
                                    fill="none"
                                    cx="18"
                                    cy="18"
                                    r="15.915"
                                ></circle>
                            @endforeach
                        </svg>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                            <span class="text-2xl font-semibold text-ink">{{ $formatNumber($compositionDisplayTotal) }}</span>
                            <span class="mt-1 text-xs text-muted">pegawai aktif</span>
                        </div>
                    </div>
                </div>

                <dl class="mt-6 space-y-3">
                    @foreach ($compositionRows as $row)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="flex min-w-0 items-center gap-2 text-ink">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $row['tone']['dot'] }}" aria-hidden="true"></span>
                                <span class="truncate font-medium">{{ $row['jenis'] }}</span>
                            </dt>
                            <dd class="shrink-0 font-semibold {{ $row['tone']['text'] }}">
                                {{ $formatNumber($row['jumlah']) }}
                                <span class="font-medium text-muted">({{ number_format($row['persen'], 1, ',', '.') }}%)</span>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>
    </section>

    <section class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3" aria-label="Status cuti dan distribusi golongan">
        <x-ui.card padding="lg">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-bold text-ink">Status Cuti</h3>
                    <p class="text-xs text-muted">Ringkasan pengajuan yang perlu dipantau.</p>
                </div>
                <svg class="h-6 w-6 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 3v2.25m7.5-2.25v2.25M3.75 9.75h16.5M5.25 4.5h13.5A1.5 1.5 0 0 1 20.25 6v13.5A1.5 1.5 0 0 1 18.75 21h-13.5a1.5 1.5 0 0 1-1.5-1.5V6a1.5 1.5 0 0 1 1.5-1.5Z" />
                </svg>
            </div>

            @if ($cutiMenunggu === null)
                <x-ui.empty-state icon="none" title="Ringkasan cuti belum tersedia." />
            @else
                <dl class="mt-6 divide-y divide-border rounded-lg border border-border">
                    <div class="flex items-center justify-between gap-4 px-4 py-3">
                        <dt class="text-sm text-ink">Menunggu</dt>
                        <dd class="text-lg font-semibold text-warning">{{ $formatNumber($cutiMenunggu) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-4 py-3">
                        <dt class="text-sm text-ink">Disetujui bulan ini</dt>
                        <dd class="text-lg font-semibold text-success">{{ $formatNumber($cutiDisetujuiBulanIni) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-4 py-3">
                        <dt class="text-sm text-ink">Ditangguhkan</dt>
                        <dd class="text-lg font-semibold text-muted">{{ $formatNumber($cutiDitangguhkan) }}</dd>
                    </div>
                </dl>
                <a href="{{ route('cuti') }}" class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary transition-colors hover:text-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                    Buka monitoring cuti
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            @endif
        </x-ui.card>

        <x-ui.card padding="lg" class="lg:col-span-2">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div>
                    <h3 class="mb-1 text-sm font-bold text-ink">Distribusi Golongan</h3>
                    <p class="text-[10px] text-muted">Jumlah pegawai per golongan I/a sampai IV/e.</p>
                </div>
                @if ($rankTotal > 0)
                    <span class="text-sm font-semibold text-primary">{{ $formatNumber($rankTotal) }} pegawai</span>
                @endif
            </div>

            @if ($distribusiGolongan === null)
                <x-ui.empty-state icon="none" title="Distribusi golongan belum tersedia." />
            @elseif ($rankTotal === 0)
                <x-ui.empty-state icon="none" title="Belum ada distribusi golongan untuk ditampilkan." />
            @else
                <div class="mt-6 overflow-x-auto pb-1">
                    <div class="flex h-56 min-w-[760px] items-end gap-2" role="img" aria-label="Grafik distribusi golongan pegawai aktif">
                        @foreach ($rankRows as $row)
                            @php
                                $barHeight = (int) round(($row['jumlah'] / $rankMax) * 100);
                                $isUnfilledRank = $row['golongan'] === 'Belum Diisi';
                            @endphp
                            <div class="flex h-full min-w-9 flex-1 flex-col justify-end">
                                <span class="mb-2 text-center text-xs font-semibold text-ink">{{ $row['jumlah'] > 0 ? $formatNumber($row['jumlah']) : '' }}</span>
                                <div class="flex h-40 items-end rounded-t-md bg-soft" aria-hidden="true">
                                    <div
                                        class="w-full rounded-t-md {{ $isUnfilledRank ? 'bg-muted' : 'bg-primary' }}"
                                        style="height: {{ $barHeight }}%"
                                        title="{{ $row['golongan'] }}: {{ $formatNumber($row['jumlah']) }} pegawai"
                                    ></div>
                                </div>
                                <span class="mt-2 text-center text-xs font-medium text-muted">{{ $isUnfilledRank ? 'Belum' : $row['golongan'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="mt-4 text-[10px] text-muted">Geser ke samping pada layar kecil untuk melihat seluruh golongan.</p>
            @endif
        </x-ui.card>
    </section>

    <section class="mt-6" aria-labelledby="ews-title">
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between border-b border-border bg-surface px-6 py-4">
                <div>
                    <h3 id="ews-title" class="text-sm font-bold text-ink">Daftar EWS Aktif</h3>
                    <p class="text-xs text-muted">Peringatan otomatis masa berlaku dokumen dan kepegawaian.</p>
                </div>
                <a href="{{ $dashboardEwsLink }}" class="text-xs font-semibold text-primary transition-colors hover:underline focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                    Lihat Semua
                </a>
            </div>

            @if ($dashboardEwsAlerts->isEmpty())
                <x-ui.empty-state icon="none" title="Tidak ada peringatan EWS aktif." />
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="lg">Pemicu EWS</x-ui.table-th>
                                <x-ui.table-th padding="lg">Sisa Waktu</x-ui.table-th>
                                <x-ui.table-th padding="lg">Prioritas</x-ui.table-th>
                                <x-ui.table-th padding="lg">Tindak Lanjut</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach ($dashboardEwsAlerts as $alert)
                                @php
                                    $urgency = data_get($alert, 'urgency', 'success');
                                    $urgencyVariant = match ($urgency) {
                                        'danger' => 'danger',
                                        'warning' => 'warning',
                                        default => 'success',
                                    };
                                    $urgencyLabel = match ($urgency) {
                                        'danger' => 'Urgent',
                                        'warning' => 'Peringatan',
                                        default => 'Informasi',
                                    };
                                    $followupStatus = data_get($alert, 'followup_status', 'belum_ditangani');
                                    $followupVariant = match ($followupStatus) {
                                        'ditangani' => 'success',
                                        'tidak_perlu' => 'muted',
                                        'kedaluwarsa' => 'warning',
                                        default => 'primary',
                                    };
                                    $nama = (string) data_get($alert, 'nama', '—');
                                    $nip = (string) data_get($alert, 'nip', '—');
                                    $targetDate = data_get($alert, 'tanggal_target');
                                    $pegawaiId = data_get($alert, 'pegawai_id');
                                @endphp
                                <x-ui.table-row>
                                    <x-ui.table-td padding="xl">
                                        <div class="flex min-w-52 items-center gap-3">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary" aria-hidden="true">
                                                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($nama, 0, 1)) }}
                                            </div>
                                            <div class="min-w-0">
                                                @if (filled($pegawaiId))
                                                    <a href="{{ route('pegawai.show', $pegawaiId) }}" class="block truncate text-sm font-semibold text-ink transition-colors hover:text-primary">
                                                        {{ $nama }}
                                                    </a>
                                                @else
                                                    <p class="truncate text-sm font-semibold text-ink">{{ $nama }}</p>
                                                @endif
                                                <p class="mt-0.5 font-mono text-xs text-muted">NIP. {{ $nip }}</p>
                                            </div>
                                        </div>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl">
                                        <p class="text-sm font-medium text-ink">{{ data_get($alert, 'jenis_event', '—') }}</p>
                                        <p class="mt-1 text-xs text-muted">
                                            {{ data_get($alert, 'threshold_label', '—') }}
                                            @if (filled($targetDate))
                                                &middot; {{ \Illuminate\Support\Carbon::parse($targetDate)->translatedFormat('d M Y') }}
                                            @endif
                                        </p>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl" class="whitespace-nowrap text-sm font-medium text-ink">
                                        {{ $formatNumber(data_get($alert, 'sisa_hari', 0)) }} hari
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl">
                                        <x-ui.badge :variant="$urgencyVariant" size="md" :pill="false" dot>{{ $urgencyLabel }}</x-ui.badge>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="xl">
                                        <x-ui.badge :variant="$followupVariant" size="md" dot>
                                            {{ data_get($alert, 'followup_status_label', 'Belum ditangani') }}
                                        </x-ui.badge>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            @endif
        </x-ui.card>
    </section>

    <section class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3" aria-label="Tren pegawai dan aktivitas audit">
        <x-ui.card padding="lg" class="lg:col-span-2">
            <div>
                <h3 class="mb-1 text-sm font-bold text-ink">Tren Pegawai Aktif</h3>
                <p class="text-[10px] text-muted">Grafik jumlah pegawai aktif bulanan dalam 12 bulan terakhir.</p>
            </div>

            @if ($trenPegawai === null)
                <x-ui.empty-state icon="none" title="Tren pegawai belum tersedia." />
            @elseif ($trendRows->isEmpty())
                <x-ui.empty-state icon="none" title="Belum ada data tren pegawai aktif." />
            @else
                <div class="mt-6 h-64 w-full">
                    <svg class="h-full w-full overflow-visible" viewBox="0 0 {{ $trendChart['width'] }} {{ $trendChart['height'] }}" role="img" aria-labelledby="trend-chart-title trend-chart-description">
                        <title id="trend-chart-title">Tren pegawai aktif dua belas bulan terakhir</title>
                        <desc id="trend-chart-description">
                            @foreach ($trendRows as $row)
                                {{ $row['label'] }} {{ $formatNumber($row['jumlah']) }} pegawai{{ $loop->last ? '.' : ',' }}
                            @endforeach
                        </desc>
                        @foreach ([0, 0.5, 1] as $position)
                            @php $y = $trendChart['top'] + ($trendPlotHeight * $position); @endphp
                            <line x1="{{ $trendChart['left'] }}" y1="{{ $y }}" x2="{{ $trendChart['width'] - $trendChart['right'] }}" y2="{{ $y }}" stroke="var(--color-border)" stroke-width="1"></line>
                        @endforeach
                        <text x="0" y="{{ $trendChart['top'] + 4 }}" fill="var(--color-muted)" font-size="10">{{ $formatNumber($trendMax) }}</text>
                        <text x="0" y="{{ $trendChart['top'] + ($trendPlotHeight / 2) + 4 }}" fill="var(--color-muted)" font-size="10">{{ $formatNumber($trendMid) }}</text>
                        <text x="0" y="{{ $trendChart['height'] - $trendChart['bottom'] + 4 }}" fill="var(--color-muted)" font-size="10">{{ $formatNumber($trendMin) }}</text>

                        @if ($trendPoints->count() > 1)
                            <path d="{{ $trendAreaPath }}" fill="var(--color-primary)" opacity="0.08"></path>
                            <polyline points="{{ $trendPolyline }}" fill="none" stroke="var(--color-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
                        @endif

                        @foreach ($trendPoints as $point)
                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4" fill="var(--color-surface)" stroke="var(--color-primary)" stroke-width="2">
                                <title>{{ $point['label'] }}: {{ $formatNumber($point['jumlah']) }} pegawai</title>
                            </circle>
                            <text x="{{ $point['x'] }}" y="{{ $trendChart['height'] - 14 }}" text-anchor="middle" fill="var(--color-muted)" font-size="10">
                                {{ \Illuminate\Support\Str::before($point['label'], ' ') }}
                            </text>
                        @endforeach
                    </svg>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between border-b border-border bg-surface px-6 py-4">
                <div>
                    <h3 class="text-sm font-bold text-ink">Aktivitas Terkini</h3>
                    <p class="text-xs text-muted">Lima aktivitas terakhir tanpa detail perubahan sensitif.</p>
                </div>
                <a href="{{ route('audit-log') }}" class="text-xs font-semibold text-primary transition-colors hover:underline focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                    Audit Log
                </a>
            </div>

            @if ($auditTerbaru === null)
                <x-ui.empty-state icon="none" title="Aktivitas audit belum tersedia." />
            @elseif ($auditRows->isEmpty())
                <x-ui.empty-state icon="none" title="Belum ada aktivitas audit terbaru." />
            @else
                <div class="divide-y divide-border">
                    @foreach ($auditRows as $audit)
                        @php
                            $event = (string) data_get($audit, 'event', '');
                            $eventTone = match ($event) {
                                'created', 'create' => 'bg-success/10 text-success',
                                'deleted', 'delete' => 'bg-danger/10 text-danger',
                                'updated', 'update', 'config_update' => 'bg-info/10 text-info',
                                default => 'bg-soft text-muted',
                            };
                            $eventLabel = $event !== ''
                                ? \Illuminate\Support\Str::headline(str_replace(['.', '-'], ' ', $event))
                                : 'Aktivitas sistem';
                        @endphp
                        <div class="flex items-start gap-3 px-5 py-4 sm:px-6">
                            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $eventTone }}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-ink">
                                    <span class="font-semibold">{{ data_get($audit, 'user_name', 'Sistem') }}</span>
                                    <span>{{ $eventLabel }}</span>
                                </p>
                                <p class="mt-1 text-xs text-muted">{{ data_get($audit, 'modul', 'Modul tidak diketahui') }}</p>
                            </div>
                            <time class="shrink-0 text-xs text-muted">{{ data_get($audit, 'waktu', '—') }}</time>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </section>
</x-layouts.app>
