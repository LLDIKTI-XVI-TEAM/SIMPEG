<x-layouts.app title="Dashboard" subtitle="Ringkasan eksekutif dan pemantauan aktivitas kepegawaian hari ini.">
    @php
        $dashboardEwsAlerts = $dashboardEwsAlerts ?? [];
        $dashboardEwsTotal = $dashboardEwsTotal ?? 0;
        $dashboardEwsUrgent = $dashboardEwsUrgent ?? 0;
        $dashboardEwsWarning = $dashboardEwsWarning ?? 0;
        $dashboardEwsInfo = $dashboardEwsInfo ?? 0;
        $dashboardEwsLink = $dashboardEwsLink ?? route('ews');
        $saldoCuti = $saldoCuti ?? null;
        $cutiAktif = $cutiAktif ?? collect([]);
        $notifikasi = $notifikasi ?? collect([]);
    @endphp

    {{-- ================================================================ --}}
    {{-- WELCOME BANNER --}}
    {{-- ================================================================ --}}
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
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-5">
                <!-- Avatar / Foto -->
                <div class="shrink-0">
                    @if($employee?->foto_url)
                        <img src="{{ $employee->foto_url }}" alt="Foto Profil" class="w-16 h-16 sm:w-20 sm:h-20 rounded-full border-2 border-white/30 object-cover shadow-lg bg-white/10 backdrop-blur-sm">
                    @else
                        <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-full border-2 border-white/30 bg-white/10 flex items-center justify-center text-2xl sm:text-3xl font-bold text-white backdrop-blur-sm shadow-lg">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                    @endif
                </div>
                
                <!-- Profil Info -->
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70 mb-0.5">Selamat datang kembali</p>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-white leading-tight drop-shadow-sm mb-2">
                        {{ preg_replace('/\s*\(.*?\)/', '', auth()->user()->name) }}
                    </h2>
                    
                    <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-[11px] sm:text-xs text-white/90 font-sans">
                        <div class="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-full backdrop-blur-md border border-white/20 shadow-sm hover:bg-white/20 transition-colors">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>
                            <span class="font-medium tracking-wide">NIP. {{ $employee?->nip ?? '-' }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-full backdrop-blur-md border border-white/20 shadow-sm hover:bg-white/20 transition-colors">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.42 15.17L17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.492-3.396c.339-.464.146-1.144-.384-1.332l-3.302-1.168c-.34-.12-.66-.12-1-.12l-1.167-3.302c-.188-.53-.868-.723-1.332-.384L3 12m8.42 3.17c1.23.844 2.375 2.052 3.23 3.332" /></svg>
                            <span class="font-medium tracking-wide">{{ $employee?->jabatan_terakhir ?? '-' }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-full backdrop-blur-md border border-white/20 shadow-sm hover:bg-white/20 transition-colors">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" /></svg>
                            <span class="font-medium tracking-wide">Gol. {{ $employee?->golongan_terakhir ?? '-' }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-full backdrop-blur-md border border-white/20 shadow-sm hover:bg-white/20 transition-colors">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                            <span class="line-clamp-1 max-w-[150px] sm:max-w-[200px] font-medium tracking-wide">{{ $employee?->positionHistories?->first()?->unitKerja?->nama ?? '-' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2.5">
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
                <path d="M 150 260 L 250 260 Q 260 260 255 250 L 235 200 L 165 200 L 145 250 Q 140 260 150 260 Z" fill="#142C80"/>
                <rect x="185" y="200" width="30" height="20" fill="#142C80"/>
                <rect x="30" y="40" width="340" height="180" rx="14" fill="#E2E8F0" stroke="#FFFFFF" stroke-width="6"/>
                <rect x="40" y="50" width="320" height="160" rx="8" fill="#FFFFFF"/>
                <circle cx="80" cy="90" r="22" fill="#E2E8F0"/>
                <circle cx="80" cy="85" r="9" fill="#94A3B8"/>
                <path d="M 62 107 Q 80 85 98 107 Z" fill="#94A3B8"/>
                <rect x="135" y="145" width="18" height="45" rx="4" fill="#3B82F6"/>
                <rect x="165" y="120" width="18" height="70" rx="4" fill="#60A5FA"/>
                <rect x="195" y="85" width="18" height="105" rx="4" fill="#2563EB"/>
                <circle cx="300" cy="115" r="40" fill="#E2E8F0"/>
                <path d="M 300 115 L 300 75 A 40 40 0 0 1 340 115 Z" fill="#2563EB"/>
                <circle cx="300" cy="115" r="16" fill="#FFFFFF"/>
                <rect x="100" y="180" width="100" height="90" rx="10" fill="#F8FAFC" stroke="#FFFFFF" stroke-width="4"/>
                <rect x="100" y="180" width="100" height="28" rx="8" fill="#3B82F6"/>
                <rect x="100" y="198" width="100" height="10" fill="#3B82F6"/>
                <rect x="115" y="168" width="8" height="24" rx="4" fill="#1E40AF"/>
                <rect x="177" y="168" width="8" height="24" rx="4" fill="#1E40AF"/>
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
                <path d="M 335 260 L 385 260 L 375 215 L 345 215 Z" fill="#F8FAFC" stroke="#E2E8F0" stroke-width="2"/>
                <path d="M 360 215 Q 330 180 345 135 Q 370 170 360 215" fill="#64748B" opacity="0.3"/>
                <path d="M 360 215 Q 380 175 395 155 Q 405 195 360 215" fill="#64748B" opacity="0.4"/>
                <path d="M 360 215 Q 360 160 375 115 Q 385 160 360 215" fill="#64748B" opacity="0.5"/>
            </svg>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- GRID KONTEN --}}
    {{-- ================================================================ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        
        {{-- Kiri: Saldo Cuti & Daftar Cuti Aktif --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Card Saldo Cuti --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.stat-card label="Jatah Tahunan ({{ date('Y') }})" value="{{ $saldoCuti['jatah_dasar'] ?? '-' }}" description="{{ !empty($saldoCuti) ? 'Jatah cuti tahunan berjalan.' : 'Data belum tersedia.' }}" variant="primary" size="md" accent>
                    <x-slot:icon>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </x-slot:icon>
                </x-ui.stat-card>
                <x-ui.stat-card label="Carry Over (N-1)" value="{{ $saldoCuti['carry_over'] ?? '-' }}" description="{{ !empty($saldoCuti) ? 'Sisa cuti tahun ' . (date('Y') - 1) . '.' : 'Data belum tersedia.' }}" variant="info" size="md" accent>
                    <x-slot:icon>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    </x-slot:icon>
                </x-ui.stat-card>
                <x-ui.stat-card label="Hak Efektif Tahun Ini" value="{{ $saldoCuti['saldo_dapat_diajukan'] ?? '-' }}" description="{{ !empty($saldoCuti) ? 'Total sisa saldo cuti aktif.' : 'Saldo belum diinput.' }}" unit="Hari" variant="success" size="md" accent role="group" aria-label="{{ $saldoCuti['saldo_dapat_diajukan'] ?? '-' }} Hari">
                    <x-slot:icon>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </x-slot:icon>
                </x-ui.stat-card>
            </div>
            @if ($rule5Active)
                <x-ui.alert variant="warning" size="sm" class="mt-4">
                    Saldo tercatat, tidak dapat digunakan pada tahun Cuti Besar. Saldo cuti tahunan tetap tercatat sebagai riwayat. Hak efektif tahun ini adalah 0 karena Cuti Besar telah disetujui.
                </x-ui.alert>
            @endif
            
            {{-- Daftar Cuti Aktif --}}
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Pengajuan Cuti Aktif</h3>
                        <p class="text-xs text-muted">Pengajuan cuti yang sedang diproses</p>
                    </div>
                    <a href="{{ route('cuti') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Jenis Cuti</x-ui.table-th>
                                <x-ui.table-th padding="lg">Tanggal</x-ui.table-th>
                                <x-ui.table-th padding="lg">Status</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse($cutiAktif as $cuti)
                                @php
                                    $statusVariant = match ($cuti->status) {
                                         'menunggu_approval' => 'info',
                                         'ditangguhkan' => 'warning',
                                         'menunggu_pembatalan' => 'warning',
                                         'dikembalikan_karena_rollover' => 'warning',
                                         'perlu_perubahan' => 'danger',
                                         default => 'primary',
                                     };
                                     $statusLabel = match ($cuti->status) {
                                         'menunggu_approval' => 'Menunggu Keputusan',
                                         'menunggu_pembatalan' => 'Menunggu Keputusan Pembatalan',
                                         'dikembalikan_karena_rollover' => 'Dikembalikan karena Rollover',
                                         'perlu_perubahan' => 'Perubahan',
                                         default => ucwords(str_replace('_', ' ', $cuti->status)),
                                     };
                                @endphp
                                <x-ui.table-row>
                                    <x-ui.table-td class="px-6 py-3.5">
                                        <p class="text-xs font-semibold text-ink font-sans">{{ $cuti->jenisCuti?->nama ?? 'Cuti Tahunan' }}</p>
                                        <p class="text-[10px] text-muted line-clamp-1">{{ $cuti->alasan }}</p>
                                    </x-ui.table-td>
                                    <x-ui.table-td class="px-6 py-3.5 font-medium text-xs text-ink">
                                        {{ $cuti->tanggal_mulai?->translatedFormat('d M') }} - {{ $cuti->tanggal_selesai?->translatedFormat('d M Y') }}
                                        <div class="text-[10px] text-muted">{{ $cuti->lama_hari }} Hari Kerja</div>
                                    </x-ui.table-td>
                                    <x-ui.table-td class="px-6 py-3.5">
                                        <x-ui.badge :variant="$statusVariant" size="sm" dot>
                                            {{ $statusLabel }}
                                        </x-ui.badge>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="3" align="center" class="px-6 py-8 text-sm text-muted">
                                        Tidak ada pengajuan cuti aktif.
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </x-ui.card>
        </div>
        
        {{-- Kanan: Notifikasi --}}
        <div class="space-y-6">
            <x-ui.card padding="none" class="overflow-hidden h-full flex flex-col">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface shrink-0">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Notifikasi Terbaru</h3>
                    </div>
                    <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Semua</a>
                </div>
                <div class="flex-1 overflow-y-auto">
                    @forelse($notifikasi as $notif)
                        <div class="px-6 py-4 border-b border-border/50 hover:bg-soft transition-colors {{ $notif->read_at ? 'opacity-70' : '' }}">
                            <p class="text-xs font-semibold text-ink mb-1">{{ $notif->title ?? 'Pemberitahuan' }}</p>
                            <p class="text-[11px] text-muted line-clamp-2">{{ $notif->body ?? '' }}</p>
                            <p class="text-[9px] text-muted/70 mt-2">{{ $notif->created_at->diffForHumans() }}</p>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-center text-sm text-muted flex flex-col items-center justify-center">
                            <svg class="w-8 h-8 text-muted/40 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                            Belum ada notifikasi
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- EWS AKTIF (W4) --}}
    {{-- ================================================================ --}}
    <div id="ews-section" class="mb-6">
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Daftar Peringatan Dini (EWS)</h3>
                    <p class="text-xs text-muted">Peringatan otomatis masa berlaku dokumen & kepegawaian Anda</p>
                </div>
                <a href="{{ $dashboardEwsLink }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                    Lihat Semua
                </a>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Pemicu EWS</x-ui.table-th>
                            <x-ui.table-th padding="lg">Tanggal Target</x-ui.table-th>
                            <x-ui.table-th padding="lg">Sisa Waktu</x-ui.table-th>
                            <x-ui.table-th padding="lg">Prioritas</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($dashboardEwsAlerts as $alert)
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
                            @endphp
                            <x-ui.table-row :interactive="false">
                                <x-ui.table-td class="px-6 py-3.5">
                                    <div class="text-xs font-bold text-ink">{{ $alert['jenis_event'] }}</div>
                                    <div class="mt-1 text-[10px] text-muted">{{ $alert['threshold_label'] }}</div>
                                </x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5 font-medium text-xs text-ink">{{ date('d M Y', strtotime($alert['tanggal_target'])) }}</x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5 font-medium text-xs text-ink">{{ $alert['sisa_hari'] }} Hari Lagi</x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5">
                                    <x-ui.badge :variant="$urgencyVariant" size="md" :pill="false" dot>
                                        {{ $urgencyLabel }}
                                    </x-ui.badge>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="4" align="center" class="px-6 py-10 text-sm text-muted">
                                    Tidak ada peringatan EWS aktif.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>

</x-layouts.app>
