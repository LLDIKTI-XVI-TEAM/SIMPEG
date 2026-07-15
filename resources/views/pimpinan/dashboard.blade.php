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
            <!-- Small floating ring -->
            <div class="absolute bottom-1/4 right-[40%] w-3 h-3 border-[2px] border-white/20 rounded-full"></div>
        </div>

        <!-- Content Left -->
        <div class="relative z-10 w-full lg:w-[70%] flex flex-col justify-center">
            <p class="text-[10px] font-bold uppercase tracking-widest text-white/70 mb-0.5">Dashboard Pimpinan</p>
            <h2 class="text-xl sm:text-2xl font-extrabold text-white leading-tight">
                {{ auth()->user()->name }}
            </h2>
            
            <p class="mt-1 text-[12px] text-white/80 font-sans max-w-lg">
                Ringkasan ini diperbarui dari data SIMPEG saat halaman dibuka.
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

        <section aria-label="Ringkasan utama" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Total Pegawai Aktif" value="{{ $totalPegawai }}" variant="primary" size="lg">
                <x-slot:meta><span>{{ ($komposisi['PNS'] ?? 0) }} PNS · {{ ($komposisi['PPPK'] ?? 0) }} PPPK</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('pimpinan.ews.index', ['event' => 'Kenaikan Pangkat']) }}" label="Kenaikan Pangkat" value="{{ $naikPangkatBulanIni }}" variant="success" size="lg">
                <x-slot:meta><span>{{ $naikPangkatBulanIni }} bulan ini · {{ $naikPangkatTahunIni }} tahun ini</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu']) }}" label="Pengajuan Cuti Menunggu" value="{{ $cutiPending }}" variant="warning" size="lg">
                <x-slot:meta><span>{{ $cutiDisetujuiBulanIni }} disetujui bulan ini · {{ $cutiDitunda }} ditangguhkan</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('pimpinan.ews.index') }}" label="EWS Aktif" value="{{ count($ewsAktif) }}" variant="danger" size="lg">
                <x-slot:meta><span>5 peringatan paling mendesak ditampilkan di bawah</span></x-slot:meta>
            </x-ui.stat-card>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-1">
                <h3 class="text-lg font-semibold text-ink">Komposisi Kepegawaian</h3>
                <p class="mt-1 text-sm text-muted">Jumlah pegawai aktif menurut jenis pegawai.</p>
                <dl class="mt-4 space-y-3">
                    @forelse($komposisi as $type => $count)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-ink">{{ $type }}</dt>
                            <dd class="font-semibold text-ink">{{ $count }} <span class="text-muted">({{ round($count / $employeeTotal * 100) }}%)</span></dd>
                        </div>
                    @empty
                        <p class="text-sm text-muted">Belum ada data pegawai aktif.</p>
                    @endforelse
                </dl>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
                <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-ink">Kenaikan Pangkat Bulan Ini</h3>
                        <p class="mt-1 text-sm text-muted">Riwayat kepangkatan dengan TMT pada bulan berjalan.</p>
                    </div>
                    <a href="{{ route('pimpinan.laporan.kepangkatan') }}" class="text-sm font-semibold text-primary hover:underline">Buka laporan</a>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="lg">Golongan</x-ui.table-th>
                                <x-ui.table-th padding="lg">TMT</x-ui.table-th>
                                <x-ui.table-th padding="lg">Nomor SK</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse($promotionRows as $row)
                                <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                    <x-ui.table-td class="px-5 py-3"><span class="font-semibold text-ink">{{ $row['nama'] }}</span><span class="mt-1 block font-sans text-[10px] text-muted">NIP. {{ $row['nip'] }}</span></x-ui.table-td>
                                    <x-ui.table-td class="px-5 py-3 font-medium text-ink">{{ $row['golongan'] }}</x-ui.table-td>
                                    <x-ui.table-td class="px-5 py-3 font-medium text-ink">{{ \Carbon\Carbon::parse($row['tmt'])->translatedFormat('d M Y') }}</x-ui.table-td>
                                    <x-ui.table-td class="px-5 py-3 text-muted">{{ $row['no_sk'] }}</x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row><x-ui.table-td colspan="4" class="px-5 py-8 text-center text-muted">Tidak ada riwayat kenaikan pangkat pada bulan ini.</x-ui.table-td></x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
                <div class="border-b border-border px-5 py-4">
                    <h3 class="text-lg font-semibold text-ink">Aktivitas Terkini</h3>
                    <p class="mt-1 text-sm text-muted">Lima audit log terbaru yang tersedia untuk pemantauan.</p>
                </div>
                <ul class="divide-y divide-border" aria-label="Aktivitas audit terbaru">
                    @forelse($auditTerbaru as $audit)
                        <li class="px-5 py-4">
                            <p class="text-sm text-ink"><span class="font-semibold">{{ $audit['user'] }}</span> · {{ $audit['aksi'] }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $audit['target'] }} · {{ $audit['waktu'] }}</p>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Belum ada aktivitas yang tercatat.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-ink">Cuti Menunggu Tindakan</h3>
                        <p class="mt-1 text-sm text-muted">Hanya pengajuan pada tahap Pimpinan saat ini.</p>
                    </div>
                    <span class="text-sm font-semibold text-ink">{{ $pendingLeaves->count() }}</span>
                </div>
                <ul class="divide-y divide-border" aria-label="Pengajuan cuti menunggu tindakan">
                    @forelse($pendingLeaves as $leave)
                        <li class="px-5 py-4">
                            <a href="{{ route('pimpinan.cuti.show', $leave['id']) }}" class="block rounded focus:outline-none focus:ring-2 focus:ring-primary">
                                <span class="block font-semibold text-ink">{{ $leave['nama'] }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ $leave['jenis'] }} · {{ $leave['hari'] }} hari kerja · {{ \Carbon\Carbon::parse($leave['mulai'])->translatedFormat('d M') }}–{{ \Carbon\Carbon::parse($leave['selesai'])->translatedFormat('d M Y') }}</span>
                                <span class="mt-2 inline-block text-xs font-semibold text-primary">Buka detail untuk mengambil keputusan</span>
                            </a>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Tidak ada pengajuan cuti yang menunggu tindakan Anda.</li>
                    @endforelse
                </ul>
                <div class="border-t border-border bg-soft/30 px-5 py-3 text-center"><a href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu']) }}" class="text-sm font-semibold text-primary hover:underline">Buka antrean persetujuan cuti</a></div>
            </x-ui.card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-1">
                <h3 class="text-lg font-semibold text-ink">Distribusi Golongan</h3>
                <p class="mt-1 text-sm text-muted">Jumlah pegawai aktif menurut kelompok golongan.</p>
                <dl class="mt-4 space-y-3">
                    @forelse($distribusiGolongan as $rank => $count)
                        <div class="flex items-center justify-between gap-4 text-sm"><dt class="text-ink">Golongan {{ $rank }}</dt><dd class="font-semibold text-ink">{{ $count }}</dd></div>
                    @empty
                        <p class="text-sm text-muted">Belum ada golongan yang tercatat.</p>
                    @endforelse
                </dl>
            </x-ui.card>
            <x-ui.card class="lg:col-span-2">
                <h3 class="text-lg font-semibold text-ink">Tren Pegawai Aktif</h3>
                <p class="mt-1 text-sm text-muted">Jumlah pegawai aktif berdasarkan data yang sudah tersimpan per akhir bulan.</p>
                <div class="mt-4 overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Periode</x-ui.table-th>
                                <x-ui.table-th padding="lg">Pegawai Aktif</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach($trenPegawai as $trend)
                                <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                    <x-ui.table-td class="px-5 py-3 font-medium text-ink">{{ $trend['label'] }}</x-ui.table-td>
                                    <x-ui.table-td class="px-5 py-3 font-semibold text-ink">{{ $trend['jumlah'] }}</x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div><h3 class="text-lg font-semibold text-ink">Daftar EWS Aktif</h3><p class="mt-1 text-sm text-muted">Lima peringatan aktif dengan tanggal target terdekat.</p></div>
                <a href="{{ route('pimpinan.ews.index') }}" class="text-sm font-semibold text-primary hover:underline">Lihat semua EWS</a>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Pemicu</x-ui.table-th>
                            <x-ui.table-th padding="lg">Tanggal Target</x-ui.table-th>
                            <x-ui.table-th padding="lg">Sisa Hari</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status Tindak Lanjut</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($ewsAktif as $alert)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td class="px-5 py-3"><span class="font-semibold text-ink">{{ $alert['nama'] }}</span><span class="mt-1 block font-sans text-[10px] text-muted">NIP. {{ $alert['nip'] }}</span></x-ui.table-td>
                                <x-ui.table-td class="px-5 py-3 font-medium text-ink">{{ $alert['jenis_event'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-5 py-3 font-medium text-ink">{{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</x-ui.table-td>
                                <x-ui.table-td class="px-5 py-3"><x-ui.badge :variant="$alert['urgency']" size="sm">{{ $alert['sisa_hari'] }} hari</x-ui.badge></x-ui.table-td>
                                <x-ui.table-td class="px-5 py-3"><x-ui.badge variant="muted" size="sm">{{ $alert['followup_status_label'] }}</x-ui.badge></x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row><x-ui.table-td colspan="5" class="px-5 py-8 text-center text-muted">Tidak ada peringatan EWS aktif.</x-ui.table-td></x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
