<x-layouts.app title="Dashboard" subtitle="Ringkasan eksekutif dan pemantauan aktivitas kepegawaian hari ini.">

    {{-- Welcome Toast Notification --}}
    @if(session('login_success'))
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 5000)"
            x-show="show"
            x-transition:enter="transition ease-out duration-300 transform"
            x-transition:enter-start="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
            x-transition:enter-end="translate-y-0 opacity-100 sm:translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed bottom-5 right-5 z-50 max-w-sm w-full bg-surface/95 backdrop-blur-md border border-success/20 rounded-xl shadow-2xl p-4 flex items-start gap-4 transition-all duration-300 hover:scale-[1.02]"
            style="display: none;"
        >
            <!-- Icon Container with Gradient -->
            <div class="shrink-0 w-10 h-10 rounded-lg bg-gradient-to-br from-emerald-400 to-success flex items-center justify-center shadow-md">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <h4 class="text-sm font-bold text-ink font-sans">Masuk Berhasil!</h4>
                <p class="text-xs text-muted font-sans mt-1 leading-normal">
                    {{ session('login_success') }}
                </p>
                <p class="text-[10px] text-primary/70 font-semibold font-sans mt-1.5 flex items-center gap-1">
                    <span>Selamat datang, {{ auth()->user()->name }}</span>
                    <span class="animate-bounce">👋</span>
                </p>
            </div>

            <!-- Close Button -->
            <button
                @click="show = false"
                class="shrink-0 text-muted hover:text-ink transition-colors cursor-pointer focus:outline-none"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    @endif

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
            <!-- Small floating ring -->
            <div class="absolute bottom-1/4 right-[40%] w-3 h-3 border-[2px] border-white/20 rounded-full"></div>
        </div>

        <!-- Content Left -->
        <div class="relative z-10 w-full lg:w-[70%] flex flex-col justify-center">
            <p class="text-[10px] font-bold uppercase tracking-widest text-white/70 mb-0.5">Selamat datang kembali</p>
            <h2 class="text-xl sm:text-2xl font-extrabold text-white leading-tight">
                {{ auth()->user()->name }}
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

    {{-- ================================================================ --}}
    {{-- KPI CARDS — 4 KOLOM (W1 - W4) --}}
    {{-- ================================================================ --}}
    <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">

        {{-- W1: Total Pegawai Aktif --}}
        <a href="{{ route('data-pegawai') }}" class="rounded-xl border border-border border-b-[3px] border-b-primary bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-1.5">
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Pegawai Aktif</p>
                        <svg class="w-3.5 h-3.5 text-muted/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                    </div>
                    <p class="mt-1 text-3xl font-extrabold text-primary leading-none font-mono tracking-tight">228</p>
                </div>
                <div class="rounded-xl bg-primary/10 p-3 shrink-0">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex flex-col gap-1.5 text-[10px] text-muted font-sans">
                <span class="font-medium text-ink">186 PNS · 42 PPPK</span>
                <span>Per {{ now()->translatedFormat('d F Y') }}</span>
            </div>
        </a>

        {{-- W2: Kenaikan Pangkat --}}
        <a href="{{ route('data-pegawai', ['filter' => 'pangkat']) }}" class="rounded-xl border border-border border-b-[3px] border-b-success bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-1.5">
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Kenaikan Pangkat</p>
                        <svg class="w-3.5 h-3.5 text-muted/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                    </div>
                    <p class="mt-1 text-3xl font-extrabold text-success leading-none font-mono tracking-tight">2</p>
                </div>
                <div class="rounded-xl bg-success/10 p-3 shrink-0">
                    <svg class="w-6 h-6 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l2.74-1.22m0 0-5.94-2.28m5.94 2.28-2.28 5.94" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex flex-col gap-1.5 text-[10px] text-muted font-sans">
                <span class="font-medium text-ink">2 Bulan Ini · 8 Tahun Ini</span>
                <span>Per {{ now()->translatedFormat('d F Y') }}</span>
            </div>
        </a>

        {{-- W3: Status Cuti --}}
        <a href="{{ route('cuti') }}" class="rounded-xl border border-border border-b-[3px] border-b-warning bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-1.5">
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Status Cuti</p>
                        <svg class="w-3.5 h-3.5 text-muted/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                    </div>
                    <p class="mt-1 text-3xl font-extrabold text-warning leading-none font-mono tracking-tight">3</p>
                </div>
                <div class="rounded-xl bg-warning/10 p-3 shrink-0">
                    <svg class="w-6 h-6 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex flex-col gap-1.5 text-[10px] text-muted font-sans">
                <span class="font-medium text-ink">3 Pending · 15 Disetujui · 2 Ditunda</span>
                <span>Per {{ now()->translatedFormat('d F Y') }}</span>
            </div>
        </a>

        {{-- W4: EWS Aktif --}}
        <a href="#ews-section" class="rounded-xl border border-border border-b-[3px] border-b-danger bg-surface p-5 shadow-sm flex flex-col justify-between cursor-pointer hover:bg-soft/40 transition-colors">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-1.5">
                        <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">EWS Aktif</p>
                        <svg class="w-3.5 h-3.5 text-muted/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                    </div>
                    <p class="mt-1 text-3xl font-extrabold text-danger leading-none font-mono tracking-tight">5</p>
                </div>
                <div class="rounded-xl bg-danger/10 p-3 shrink-0">
                    <svg class="w-6 h-6 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-border flex flex-col gap-1.5 text-[10px] text-muted font-sans">
                <span class="font-semibold text-danger">1 Urgent · 3 Warning · 1 Info</span>
                <span>Per {{ now()->translatedFormat('d F Y') }}</span>
            </div>
        </a>
    </div>

    {{-- ================================================================ --}}
    {{-- ROW 1: KENAIKAN PANGKAT (W2) & KOMPOSISI PEGAWAI (W1 - PIE/DONUT) --}}
    {{-- ================================================================ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        
        {{-- W2: Kenaikan Pangkat Detail --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Kenaikan Pangkat Terdekat</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Pegawai yang dijadwalkan naik pangkat bulan ini</p>
                </div>
                <a href="{{ route('data-pegawai', ['filter' => 'pangkat']) }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                    Kelola
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft">
                        <tr>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Pegawai</th>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Golongan Asal</th>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Golongan Baru</th>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">TMT Kenaikan</th>
                            <th class="px-6 py-3 text-right text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">A</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Ahmad Fauzi</p>
                                        <p class="text-[10px] text-muted font-sans leading-none mt-0.5">NIP. 19850312 201001 1 001</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-xs text-muted font-medium font-sans">III/c (Penata)</td>
                            <td class="px-6 py-5 text-xs text-muted font-medium font-sans">III/d (Penata Tingkat 1)</td>
                            <td class="px-6 py-5 text-xs text-ink font-semibold font-mono">01-07-2026</td>
                            <td class="px-6 py-5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('pegawai.show', ['id' => 1]) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">S</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Siti Rahayu</p>
                                        <p class="text-[10px] text-muted font-sans leading-none mt-0.5">NIP. 19901120 201501 2 003</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-xs text-muted font-medium font-sans">II/d (Pengatur Tkt. 1)</td>
                            <td class="px-6 py-5 text-xs text-muted font-medium font-sans">III/a (Penata Muda)</td>
                            <td class="px-6 py-5 text-xs text-ink font-semibold font-mono">01-07-2026</td>
                            <td class="px-6 py-5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('pegawai.show', ['id' => 2]) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- W1: Komposisi Pegawai PNS vs PPPK (SVG Pie/Donut Chart) --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm flex flex-col justify-between">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans mb-1">Komposisi Kepegawaian</h3>
                <p class="text-[10px] text-muted font-sans mb-5">Rasio Pegawai PNS vs PPPK</p>
                
                {{-- SVG Donut Chart --}}
                <div class="flex items-center justify-center py-2">
                    <div class="relative flex items-center justify-center h-32 w-32">
                        <svg class="w-full h-full -rotate-90" viewBox="0 0 36 36">
                            {{-- Base background circle --}}
                            <circle class="text-soft" stroke="currentColor" stroke-width="4.5" fill="none" cx="18" cy="18" r="15.915"></circle>
                            {{-- PNS stroke: 81.6% (stroke-dasharray="81.6 18.4") --}}
                            <circle class="text-primary" stroke="currentColor" stroke-width="4.5" stroke-dasharray="81.6 18.4" stroke-dashoffset="0" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                            {{-- PPPK stroke: 18.4% (starts at offset 81.6) --}}
                            <circle class="text-secondary" stroke="currentColor" stroke-width="4.5" stroke-dasharray="18.4 81.6" stroke-dashoffset="-81.6" fill="none" cx="18" cy="18" r="15.915" stroke-linecap="round"></circle>
                        </svg>
                        <div class="absolute flex flex-col items-center justify-center">
                            <span class="text-xl font-mono font-extrabold text-ink leading-none">228</span>
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
                        <span class="font-bold text-primary font-mono">186 (81.6%)</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-secondary shrink-0"></span>
                            <span class="font-medium text-ink">PPPK</span>
                        </div>
                        <span class="font-bold text-secondary font-mono">42 (18.4%)</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- ================================================================ --}}
    {{-- ROW 2: EWS AKTIF (W4) & STATUS CUTI / CUTI PENDING (W3)          --}}
    {{-- ================================================================ --}}
    <div id="ews-section" class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        
        {{-- W4: EWS Aktif --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Daftar EWS Aktif</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Peringatan otomatis masa berlaku dokumen & kepegawaian</p>
                </div>
                <a href="{{ route('ews') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                    Lihat Semua
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft">
                        <tr>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Pegawai</th>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Pemicu EWS</th>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Sisa Waktu</th>
                            <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Prioritas</th>
                            <th class="px-6 py-3 text-right text-[10px] font-bold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        {{-- EWS Item 1: Merah (<30 Hari) --}}
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">B</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Budi Santoso</p>
                                        <p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. 19780601 200312 1 002</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-ink font-medium font-sans">Masa Berlaku SK Pengangkatan</td>
                            <td class="px-6 py-3.5 text-xs text-ink font-sans font-medium">12 Hari Lagi</td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center gap-1.5 rounded border border-danger/20 bg-danger/5 px-2 py-0.5 text-xs font-semibold text-danger">
                                    <svg class="w-3 h-3 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                    </svg>
                                    Urgent
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('dokumen') }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Tinjau">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        {{-- EWS Item 2: Kuning (30-90 Hari) --}}
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">S</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Siti Rahayu</p>
                                        <p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. 19901120 201501 2 003</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-ink font-medium font-sans">Persiapan Administrasi Pensiun</td>
                            <td class="px-6 py-3.5 text-xs text-ink font-sans font-medium">45 Hari Lagi</td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center gap-1.5 rounded border border-warning/35 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                    <svg class="w-3 h-3 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                    </svg>
                                    Warning
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('data-pegawai', ['filter' => 'pensiun']) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Tinjau">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        {{-- EWS Item 3: Kuning --}}
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">A</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Ahmad Fauzi</p>
                                        <p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. 19850312 201001 1 001</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-ink font-medium font-sans">Kenaikan Gaji Berkala (KGB)</td>
                            <td class="px-6 py-3.5 text-xs text-ink font-sans font-medium">55 Hari Lagi</td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center gap-1.5 rounded border border-warning/35 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                    <svg class="w-3 h-3 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                    </svg>
                                    Warning
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('data-pegawai') }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Tinjau">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        {{-- EWS Item 4: Kuning --}}
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">D</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Dewi Pertiwi</p>
                                        <p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. 19931205 201901 2 001</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-ink font-medium font-sans">Peninjauan Kontrak PPPK</td>
                            <td class="px-6 py-3.5 text-xs text-ink font-sans font-medium">80 Hari Lagi</td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center gap-1.5 rounded border border-warning/35 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                    <svg class="w-3 h-3 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                    </svg>
                                    Warning
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('data-pegawai') }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Tinjau">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        {{-- EWS Item 5: Hijau (>90 Hari) --}}
                        <tr class="transition-colors hover:bg-soft/30 cursor-pointer">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <span class="text-xs font-bold text-primary">R</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-ink font-sans leading-tight">Rudi Hermawan</p>
                                        <p class="text-[9px] text-muted font-sans leading-none mt-0.5">NIP. 19751010 199903 1 004</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-ink font-medium font-sans">Pembaruan SK Jabatan Struktural</td>
                            <td class="px-6 py-3.5 text-xs text-ink font-sans font-medium">110 Hari Lagi</td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center gap-1.5 rounded border border-info/35 bg-info/10 px-2 py-0.5 text-xs font-semibold text-info">
                                    <svg class="w-3 h-3 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 11.25v5m0-7.5h.008v.008H12V8.75Zm9 3.25a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    Informasi
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-right">
                                <div class="flex items-center justify-end">
                                    <a href="{{ route('dokumen') }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Tinjau">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- W3: Status Cuti & Cuti Pending List --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Otorisasi Cuti Pending</h3>
                        <p class="text-[10px] text-muted font-sans mt-0.5">Menunggu keputusan persetujuan</p>
                    </div>
                    <span class="text-xs font-bold font-sans text-warning">3</span>
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
            </div>
            <div class="border-t border-border px-6 py-4 bg-soft/20 text-center">
                <a href="{{ route('cuti') }}" class="text-xs font-bold text-primary hover:underline transition-all font-sans">
                    Kelola Seluruh Pengajuan Cuti
                </a>
            </div>
        </div>

    </div>

    {{-- ================================================================ --}}
    {{-- ROW 3: DISTRIBUSI GOLONGAN (W5) & TREN PEGAWAI (W7) --}}
    {{-- ================================================================ --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        
        {{-- W5: Distribusi Golongan (Horizontal Bar Chart) --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm flex flex-col justify-between">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans mb-1">Distribusi Golongan</h3>
                <p class="text-[10px] text-muted font-sans mb-5">Statistik jumlah pegawai per tingkat golongan</p>
                
                <div class="space-y-4">
                    @php
                    $distribusiGolongan = [
                        ['gol' => 'Golongan IV (Pembina)', 'jumlah' => 12, 'persen' => '5.2%',  'color' => 'bg-primary'],
                        ['gol' => 'Golongan III (Penata)',  'jumlah' => 148, 'persen' => '64.9%', 'color' => 'bg-primary'],
                        ['gol' => 'Golongan II (Pengatur)', 'jumlah' => 58, 'persen' => '25.4%', 'color' => 'bg-primary'],
                        ['gol' => 'Golongan I (Juru)',     'jumlah' => 10, 'persen' => '4.5%',  'color' => 'bg-primary'],
                    ];
                    @endphp
                    @foreach($distribusiGolongan as $dg)
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-medium text-ink">{{ $dg['gol'] }}</span>
                            <span class="font-bold text-primary font-mono">{{ $dg['jumlah'] }} ({{ $dg['persen'] }})</span>
                        </div>
                        <div class="h-2 w-full bg-soft rounded-full overflow-hidden">
                            <div class="h-2 rounded-full {{ $dg['color'] }}" style="width: {{ $dg['persen'] }}"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- W7: Tren Pegawai Aktif (SVG Line Chart) --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm lg:col-span-2 flex flex-col justify-between">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans mb-1">Tren Pegawai Aktif</h3>
                <p class="text-[10px] text-muted font-sans mb-4">Grafik jumlah pegawai aktif bulanan (12 Bulan Terakhir)</p>
                
                {{-- SVG Line Chart --}}
                <div class="w-full h-44 py-2">
                    <svg class="w-full h-full" viewBox="0 0 500 150">
                        {{-- Grids --}}
                        <line x1="40" y1="20" x2="480" y2="20" stroke="#F3F4F6" stroke-width="1"></line>
                        <line x1="40" y1="55" x2="480" y2="55" stroke="#F3F4F6" stroke-width="1"></line>
                        <line x1="40" y1="90" x2="480" y2="90" stroke="#F3F4F6" stroke-width="1"></line>
                        <line x1="40" y1="125" x2="480" y2="125" stroke="#E5E7EB" stroke-width="1.5"></line>

                        {{-- Chart Path with smooth curves --}}
                        <path d="M 40 110 C 80 112, 120 108, 160 95 C 200 82, 240 75, 280 62 C 320 49, 360 40, 400 38 C 440 36, 460 30, 480 25" fill="none" stroke="#122E92" stroke-width="3.5" stroke-linecap="round"></path>
                        
                        {{-- Area under path with gradient --}}
                        <path d="M 40 110 C 80 112, 120 108, 160 95 C 200 82, 240 75, 280 62 C 320 49, 360 40, 400 38 C 440 36, 460 30, 480 25 L 480 125 L 40 125 Z" fill="url(#chart-grad)" opacity="0.08"></path>

                        {{-- Defs for linear gradient --}}
                        <defs>
                            <linearGradient id="chart-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#122E92"></stop>
                                <stop offset="100%" stop-color="#122E92" stop-opacity="0"></stop>
                            </linearGradient>
                        </defs>

                        {{-- Dots on peaks --}}
                        <circle cx="40" cy="110" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="160" cy="95" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="280" cy="62" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="400" cy="38" r="4.5" fill="#FFFFFF" stroke="#122E92" stroke-width="2.5"></circle>
                        <circle cx="480" cy="25" r="4.5" fill="#122E92" stroke="#FFFFFF" stroke-width="1.5"></circle>

                        {{-- Labels --}}
                        <text x="35" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Jul</text>
                        <text x="75" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Agt</text>
                        <text x="115" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Sep</text>
                        <text x="155" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Okt</text>
                        <text x="195" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Nov</text>
                        <text x="235" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Des</text>
                        <text x="275" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Jan</text>
                        <text x="315" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Feb</text>
                        <text x="355" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Mar</text>
                        <text x="395" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Apr</text>
                        <text x="435" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Mei</text>
                        <text x="473" y="142" fill="#9CA3AF" font-size="9" font-family="Poppins" font-weight="bold">Jun</text>

                        {{-- Left Axis values --}}
                        <text x="10" y="23" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">230</text>
                        <text x="10" y="58" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">200</text>
                        <text x="10" y="93" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">170</text>
                        <text x="10" y="128" fill="#9CA3AF" font-size="9" font-family="mono" font-weight="bold">140</text>
                    </svg>
                </div>
            </div>
        </div>

    </div>

    {{-- ================================================================ --}}
    {{-- ROW 4: PEGAWAI TERBARU & AKTIVITAS TERKINI (W6)                  --}}
    {{-- ================================================================ --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        
        {{-- Pegawai Terbaru --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Pegawai Terbaru</h3>
                    <p class="text-[10px] text-muted font-sans mt-0.5">Penambahan data kepegawaian terakhir</p>
                </div>
                <a href="{{ route('data-pegawai') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                    Lihat Semua
                </a>
            </div>
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
                        @php
                        $pegawaiList = [
                            ['id' => 1, 'nama' => 'Ahmad Fauzi',                  'nip' => '19850312 201001 1 001', 'jabatan' => 'Analis Kepegawaian',             'unit' => 'Bag. Umum',     'jenis' => 'PNS',   'golongan' => 'III/c', 'status' => 'aktif'],
                            ['id' => 2, 'nama' => 'Siti Rahayu',                  'nip' => '19901120 201501 2 003', 'jabatan' => 'Analis Ahli Madya',              'unit' => 'Bag. Keuangan', 'jenis' => 'PNS',   'golongan' => 'II/d',  'status' => 'aktif'],
                            ['id' => 3, 'nama' => 'Sabrina Rossa Adriani Wibowo', 'nip' => '20261210 820500 0 04',  'jabatan' => 'Analis SDM Aparatur Ahli Pertama', 'unit' => 'Bag. SDM',      'jenis' => 'PNS',  'golongan' => 'III/a', 'status' => 'aktif'],
                            ['id' => 4, 'nama' => 'Cimma Sari Oktariani Di Silapu', 'nip' => '26110820 520600 0 04',  'jabatan' => 'Pranata SDM Terampil',           'unit' => 'Bag. IT',       'jenis' => 'PNS',  'golongan' => 'III/c', 'status' => 'aktif'],
                            ['id' => 5, 'nama' => 'Nurarningsih Dumbea, S.P.',    'nip' => '19930315 201903 2 002', 'jabatan' => 'Pejabat Lelang Operational',     'unit' => 'Bag. Umum',     'jenis' => 'PPPK',  'golongan' => 'II/b',  'status' => 'aktif'],
                        ];
                        @endphp
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
                                <span class="inline-flex items-center gap-1.5 rounded border border-success/20 bg-success/5 px-2 py-0.5 text-xs font-semibold text-success">
                                    <svg class="w-3 h-3 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    Aktif
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

        {{-- W6: Statistik Audit Log (5 Terbaru) --}}
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
                    ['user' => 'super_admin', 'aksi' => 'Mengubah batas cuti tahunan sistem',     'target' => 'Konfigurasi Cuti',            'waktu' => '5 jam lalu',    'type' => 'perbarui'],
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
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m4.5 12.75 6 6 9-13.5" /></svg>
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

    </div>

    {{-- ================================================================ --}}
    {{-- ROW 5: HARI LIBUR MENDATANG                                      --}}
    {{-- ================================================================ --}}
    <div class="mt-6 rounded-lg border border-border bg-surface p-6 shadow-sm flex flex-col">
        <div class="flex items-center justify-between border-b border-border pb-4 mb-5">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans">Hari Libur Mendatang</h3>
                <p class="text-[10px] text-muted font-sans mt-0.5">Garis waktu 3-4 bulan ke depan</p>
            </div>
            @if(auth()->user()?->role === 'super_admin')
                <a href="{{ route('hari-libur') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Kelola</a>
            @endif
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

</x-layouts.app>
