<x-layouts.app title="Dashboard" subtitle="Ringkasan eksekutif dan pemantauan aktivitas bawahan hari ini.">

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
            <p class="text-[10px] font-bold uppercase tracking-widest text-white/70 mb-0.5">Selamat datang kembali</p>
            <h2 class="text-xl sm:text-2xl font-extrabold text-white leading-tight">
                {{ $namaKepalaBagian }}
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
    {{-- KPI CARDS --}}
    {{-- ================================================================ --}}
    <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-3">
        {{-- Stat: Total Bawahan Aktif --}}
        <x-ui.stat-card href="{{ route('kepala-bagian.bawahan.index') }}" label="Bawahan Aktif" value="{{ $totalBawahanAktif }}" variant="primary" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span>Bawahan langsung dalam bagian Anda</span>
            </x-slot:meta>
        </x-ui.stat-card>

        {{-- Stat: Cuti Pending --}}
        <x-ui.stat-card href="{{ route('kepala-bagian.cuti.index') }}" label="Cuti Menunggu Tindakan" value="{{ $cutiPending }}" variant="warning" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span class="font-medium text-ink">Menunggu tindakan Anda</span>
            </x-slot:meta>
        </x-ui.stat-card>

        {{-- Stat: Bawahan Sedang Cuti --}}
        <x-ui.stat-card href="{{ route('kepala-bagian.bawahan.index', ['status' => 'cuti']) }}" label="Bawahan Sedang Cuti" value="{{ $bawahanSedangCuti }}" variant="info" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span class="font-medium text-ink">Pengajuan berstatus Disetujui hari ini</span>
            </x-slot:meta>
        </x-ui.stat-card>
    </div>

    {{-- ================================================================ --}}
    {{-- WIDGETS ROW --}}
    {{-- ================================================================ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        
        {{-- WIDGET: Pengajuan Cuti Pending --}}
        <x-ui.card padding="none" class="overflow-hidden flex flex-col justify-between" x-data="{ confirmOpen: false, selectedLeaveId: '', selectedEmployeeName: '' }">
            <div>
                <div class="px-6 py-5 border-b border-border/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Cuti Bawahan</h3>
                        <p class="text-xs text-muted">Menunggu keputusan Anda</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('kepala-bagian.cuti.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                            Lihat Antrean
                        </a>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="lg">Keterangan</x-ui.table-th>
                                <x-ui.table-th align="right" padding="lg">Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse($pendingLeaves as $leave)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <x-ui.tooltip text="Buka detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}" position="right">
                                            <a href="{{ route('kepala-bagian.cuti.show', $leave) }}" class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-xs font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30" aria-label="Buka detail cuti">
                                                <span>{{ substr($leave->employee?->nama_lengkap ?? 'P', 0, 1) }}</span>
                                            </a>
                                        </x-ui.tooltip>
                                        <div class="min-w-0">
                                            <x-ui.tooltip text="Buka detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}" position="right">
                                                <a href="{{ route('kepala-bagian.cuti.show', $leave) }}" class="block truncate text-xs font-semibold text-ink transition hover:text-primary focus:outline-none rounded leading-tight">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</a>
                                            </x-ui.tooltip>
                                            <span class="block truncate text-xs text-muted">NIP. {{ $leave->employee?->nip ?? '-' }}</span>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td class="px-6 py-3.5">
                                    <p class="text-xs text-muted font-sans leading-none">{{ $leave->jenisCuti?->nama ?? '-' }} · {{ $leave->jumlah_hari_kerja }} hari kerja · {{ $leave->tanggal_mulai?->translatedFormat('d M') ?? '-' }}–{{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td align="right" class="px-6 py-3.5">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.tooltip text="Setujui Pengajuan" position="top-end">
                                            <button type="button" @click="selectedLeaveId = '{{ $leave->id }}'; selectedEmployeeName = '{{ addslashes($leave->employee?->nama_lengkap ?? '') }}'; confirmOpen = true" class="flex h-7 w-7 items-center justify-center rounded-md bg-success/10 text-success transition hover:bg-success/20 border border-success/20 focus:outline-none focus:ring-2 focus:ring-success/30" aria-label="Setujui pengajuan cuti {{ $leave->employee?->nama_lengkap }}">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                            </button>
                                        </x-ui.tooltip>
                                        <x-ui.tooltip text="Tinjau Detail & Opsi Lain" position="top-end">
                                            <a href="{{ route('kepala-bagian.cuti.show', $leave) }}" class="flex h-7 w-7 items-center justify-center rounded-md border border-border bg-surface text-primary transition hover:bg-soft shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/30" aria-label="Tinjau detail cuti">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                </svg>
                                            </a>
                                        </x-ui.tooltip>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                            @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="3" class="px-6 py-8 text-center text-sm text-muted">
                                    Tidak ada pengajuan cuti yang menunggu tindakan Anda.
                                </x-ui.table-td>
                            </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            <x-ui.modal show="confirmOpen" close-action="confirmOpen = false" title="Konfirmasi Persetujuan Cuti">
                <p class="text-sm text-ink">Setujui pengajuan cuti untuk <span class="font-bold text-ink" x-text="selectedEmployeeName"></span>? Pengajuan akan diteruskan ke approver berikutnya.</p>
                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" @click="confirmOpen = false" variant="secondary">Batal</x-ui.button>
                    <form method="POST" :action="'/kepala-bagian/cuti/' + selectedLeaveId + '/keputusan'">
                        @csrf
                        <input type="hidden" name="keputusan" value="DISETUJUI">
                        <x-ui.button type="submit" variant="success">Ya, Setujui</x-ui.button>
                    </form>
                </div>
            </x-ui.modal>
        </x-ui.card>

        {{-- WIDGET: EWS Bawahan --}}
        <x-ui.card padding="none" class="overflow-hidden flex flex-col justify-between">
            <div>
                <div class="px-6 py-5 border-b border-border/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">EWS Bawahan Aktif</h3>
                        <p class="text-xs text-muted">Peringatan aktif dengan target terdekat</p>
                    </div>
                    <a href="{{ route('kepala-bagian.ews.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                        Lihat Semua
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                                <x-ui.table-th align="right" padding="lg">Peringatan</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse($ewsBawahan as $alert)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <x-ui.tooltip text="Buka detail {{ $alert['nama'] }}" position="right">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-xs font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30" aria-label="Buka detail profil {{ $alert['nama'] }}">
                                                <span>{{ substr($alert['nama'], 0, 1) }}</span>
                                            </a>
                                        </x-ui.tooltip>
                                        <div class="min-w-0">
                                            <x-ui.tooltip text="Buka detail {{ $alert['nama'] }}" position="right">
                                                <a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="block truncate text-xs font-semibold text-ink transition hover:text-primary focus:outline-none rounded leading-tight">{{ $alert['nama'] }}</a>
                                            </x-ui.tooltip>
                                            <span class="block truncate text-xs text-muted">NIP. {{ $alert['nip'] ?? '-' }}</span>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td align="right" class="px-6 py-3.5">
                                    <p class="text-xs text-muted font-sans leading-none">{{ $alert['jenis_event'] }} · target {{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</p>
                                    <div class="flex items-center justify-end gap-2 mt-1">
                                        <x-ui.badge variant="{{ $alert['urgency'] }}" size="sm" :pill="false" dot>
                                            {{ $alert['sisa_hari'] < 0 ? 'Lewat '.abs($alert['sisa_hari']).' hari' : $alert['sisa_hari'].' hari' }}
                                        </x-ui.badge>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                            @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="2" class="px-6 py-8 text-center text-sm text-muted">
                                    Tidak ada peringatan EWS aktif untuk bawahan langsung.
                                </x-ui.table-td>
                            </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- ================================================================ --}}
    {{-- DAFTAR BAWAHAN RINGKAS --}}
    {{-- ================================================================ --}}
    <div class="mt-6">
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="px-6 py-5 border-b border-border/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-surface">
                <div>
                    <h3 class="text-sm font-bold text-ink font-sans">Daftar Bawahan</h3>
                    <p class="text-xs text-muted">Menampilkan lima bawahan aktif pertama berdasarkan nama</p>
                </div>
                <a href="{{ route('kepala-bagian.bawahan.index') }}" class="text-xs font-semibold text-primary hover:underline font-sans">
                    Buka Daftar Bawahan
                </a>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Nama & NIP</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jabatan Terakhir</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status</x-ui.table-th>
                            <x-ui.table-th align="right" padding="lg">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($bawahan as $employee)
                        @php($position = $employee->positionHistories->first())
                        <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                            <x-ui.table-td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <x-ui.tooltip text="Buka detail {{ $employee->nama_lengkap }}" position="right">
                                        <a href="{{ route('kepala-bagian.bawahan.show', $employee) }}" class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-xs font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30" aria-label="Buka detail profil">
                                            <span>{{ substr($employee->nama_lengkap, 0, 1) }}</span>
                                        </a>
                                    </x-ui.tooltip>
                                    <div class="min-w-0">
                                        <x-ui.tooltip text="Buka detail {{ $employee->nama_lengkap }}" position="right">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $employee) }}" class="block truncate text-xs font-semibold text-ink transition hover:text-primary focus:outline-none rounded leading-tight">{{ $employee->nama_lengkap }}</a>
                                        </x-ui.tooltip>
                                        <p class="text-xs text-muted">NIP. {{ $employee->nip }}</p>
                                    </div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td class="px-6 py-3.5 font-medium text-muted">
                                {{ $employee->jabatan_terakhir ?: '-' }}
                            </x-ui.table-td>
                            <x-ui.table-td class="px-6 py-3.5">
                                @if($employee->sedang_dinas_luar ?? false)
                                    <x-ui.badge variant="info" size="sm" dot>Dinas Luar</x-ui.badge>
                                @elseif($employee->sedang_cuti)
                                    <x-ui.badge variant="warning" size="sm" dot>Cuti</x-ui.badge>
                                @else
                                    <x-ui.badge variant="success" size="sm" dot>Aktif</x-ui.badge>
                                @endif
                            </x-ui.table-td>
                            <x-ui.table-td align="right" class="px-6 py-3.5">
                                <x-ui.button as="a" href="{{ route('kepala-bagian.bawahan.show', $employee) }}" variant="secondary" size="icon" title="Lihat Detail" tooltip-position="top-end">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </x-ui.button>
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @empty
                        <x-ui.table-row>
                            <x-ui.table-td colspan="4" class="px-6 py-8 text-center text-sm text-muted">
                                Belum ada bawahan langsung yang aktif.
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
