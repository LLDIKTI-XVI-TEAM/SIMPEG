<x-layouts.app title="Detail Cuti">
    <div class="mx-auto max-w-3xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Detail Pengajuan Cuti</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Pengajuan</span>
            </nav>
        </div>

        {{-- Detail Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            
            {{-- Header info --}}
            <div class="border-b border-border pb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-bold text-primary">
                        {{ strtoupper(substr($c['nama'] ?? 'P', 0, 1)) }}
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-ink font-sans leading-tight">{{ $c['nama'] ?? 'Pegawai' }}</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Pengajuan: {{ \Carbon\Carbon::parse($c['tgl_pengajuan'])->translatedFormat('d F Y') }}</p>
                    </div>
                </div>
                <div>
                    @php
                    $statusClass = [
                        'menunggu' => 'bg-warning/10 text-warning border border-warning/20',
                        'disetujui' => 'bg-success/10 text-success border border-success/20',
                        'ditunda' => 'bg-danger/10 text-danger border border-danger/20'
                    ];
                    $statusLabel = [
                        'menunggu' => 'Menunggu Persetujuan',
                        'disetujui' => 'Disetujui',
                        'ditunda' => 'Ditunda'
                    ];
                    $stClass = $statusClass[$c['status']] ?? 'bg-soft text-muted';
                    $stLabel = $statusLabel[$c['status']] ?? $c['status'];
                    @endphp
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold {{ $stClass }} font-sans">
                        {{ $stLabel }}
                    </span>
                </div>
            </div>

            {{-- Metadata Grid --}}
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Jenis Cuti</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $c['jenis'] }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Durasi Cuti</span>
                    <p class="text-sm font-bold text-ink font-mono">{{ $c['hari'] }} Hari Kerja</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Mulai</span>
                    <p class="text-sm font-semibold text-ink font-mono">{{ \Carbon\Carbon::parse($c['mulai'])->translatedFormat('d F Y') }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Selesai</span>
                    <p class="text-sm font-semibold text-ink font-mono">{{ \Carbon\Carbon::parse($c['selesai'])->translatedFormat('d F Y') }}</p>
                </div>
                <div class="space-y-1 sm:col-span-2">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Alasan / Keterangan</span>
                    <p class="text-sm text-ink font-sans leading-relaxed bg-soft/50 rounded-lg p-3 border border-border">{{ $c['alasan'] }}</p>
                </div>
            </div>

            {{-- Approval Flow Status --}}
            <div class="border-t border-border pt-6 space-y-4">
                <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alur Persetujuan Cuti</h4>
                
                <div class="relative pl-6 space-y-6 before:absolute before:left-2 before:top-2 before:bottom-2 before:w-0.5 before:bg-border">
                    
                    {{-- Step 1 --}}
                    <div class="relative">
                        <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-success bg-surface flex items-center justify-center">
                            <div class="h-1 w-1 rounded-full bg-success"></div>
                        </div>
                        <div class="pl-3">
                            <p class="text-xs font-bold text-ink font-sans">Diajukan oleh Pegawai</p>
                            <p class="text-[10px] text-muted font-sans mt-0.5">{{ \Carbon\Carbon::parse($c['tgl_pengajuan'])->translatedFormat('d M Y, H:i') }}</p>
                        </div>
                    </div>

                    {{-- Step 2 --}}
                    <div class="relative">
                        @if($c['status'] === 'disetujui')
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-success bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-success"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-ink font-sans">Disetujui oleh Atasan Langsung</p>
                                <p class="text-[10px] text-muted font-sans mt-0.5">Siti Rahayu — Kepala Subbagian Keuangan</p>
                            </div>
                        @elseif($c['status'] === 'ditunda')
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-danger bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-danger"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-danger font-sans">Ditunda oleh Atasan Langsung</p>
                                <p class="text-[10px] text-muted font-sans mt-0.5">Siti Rahayu — Kepala Subbagian Keuangan</p>
                            </div>
                        @else
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-warning bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-warning animate-pulse"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-warning font-sans">Menunggu Persetujuan Atasan Langsung</p>
                                <p class="text-[10px] text-muted font-sans mt-0.5">Siti Rahayu — Kepala Subbagian Keuangan</p>
                            </div>
                        @endif
                    </div>

                    {{-- Step 3 --}}
                    <div class="relative">
                        @if($c['status'] === 'disetujui')
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-success bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-success"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-ink font-sans">Disahkan oleh Kepala Instansi</p>
                                <p class="text-[10px] text-muted font-sans mt-0.5">Munawir Sadzali Razak, S.I.P., M.A. — Kepala LLDIKTI XVI</p>
                            </div>
                        @else
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-border bg-surface flex items-center justify-center"></div>
                            <div class="pl-3">
                                <p class="text-xs font-medium text-muted font-sans">Verifikasi & Pengesahan SK Cuti</p>
                                <p class="text-[10px] text-muted font-sans mt-0.5">Munawir Sadzali Razak, S.I.P., M.A. — Kepala LLDIKTI XVI</p>
                            </div>
                        @endif
                    </div>

                </div>
            </div>

            {{-- Footer Action Buttons --}}
            <div class="border-t border-border pt-6 flex justify-end gap-3">
                <a href="{{ route('cuti') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                    Kembali ke Daftar
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
