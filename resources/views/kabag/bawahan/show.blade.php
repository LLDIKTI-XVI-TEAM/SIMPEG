<x-layouts.app title="Detail Bawahan" subtitle="Informasi detail profil dan kepegawaian bawahan.">
    @php($position = $employee->positionHistories->first())
    
    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Detail Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'Daftar Bawahan', 'url' => route('kepala-bagian.bawahan.index')],
                ['label' => 'Detail Bawahan']
            ]" />
        </div>
        <div>
            <x-ui.button href="{{ route('kepala-bagian.bawahan.index') }}" variant="secondary" size="md">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Kembali
            </x-ui.button>
        </div>
    </div>

    <!-- HEADER PROFIL -->
    <div class="mb-6 bg-surface rounded-xl border border-border shadow-sm overflow-hidden relative">
        <div class="bg-gradient-to-r from-primary to-[#2143c2] px-6 py-8 sm:p-10 relative overflow-hidden">
            <!-- Decorative elements -->
            <div class="absolute top-0 right-0 -mr-16 -mt-16 w-64 h-64 rounded-full bg-white opacity-5 blur-3xl pointer-events-none"></div>
            <div class="absolute bottom-0 right-1/4 w-32 h-32 rounded-full bg-white opacity-10 blur-2xl pointer-events-none"></div>
            
            <div class="relative z-10 flex flex-col sm:flex-row sm:items-center gap-6">
                <div class="h-24 w-24 rounded-full bg-white p-1 shadow-lg shrink-0">
                    <div class="h-full w-full rounded-full bg-primary/5 flex items-center justify-center border border-gray-100 overflow-hidden">
                        @if ($employee->foto_url)
                            <img src="{{ $employee->foto_url }}" alt="Foto {{ $employee->nama_lengkap }}" class="h-full w-full object-cover">
                        @else
                            <span class="text-3xl font-bold text-primary">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($employee->nama_lengkap, 0, 1)) }}</span>
                        @endif
                    </div>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white font-sans leading-tight drop-shadow-sm">{{ $employee->nama_lengkap }}</h1>
                    <p class="text-sm sm:text-base font-medium text-white/80 font-sans mt-2">NIP. {{ $employee->nip }} &middot; {{ $employee->jabatan_terakhir ?: '-' }}</p>
                </div>
                <div class="sm:ml-auto mt-2 sm:mt-0 bg-white rounded-full p-1 shadow-sm shrink-0 flex items-center justify-center w-max h-max">
                    @if($employee->sedang_cuti ?? false)
                        <x-ui.badge variant="warning" size="md" pill dot="true">Cuti</x-ui.badge>
                    @else
                        <x-ui.badge variant="success" size="md" pill dot="true">
                            {{ $employee->statusPegawai?->nama ?? $employee->status_aktif ?? 'Aktif' }}
                        </x-ui.badge>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- KOLOM KIRI (Informasi Utama & Cuti) -->
        <div class="lg:col-span-2 space-y-6">
            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Informasi Kepegawaian</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Unit Kerja</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $position?->unitKerja?->nama ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Golongan</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $employee->golongan_terakhir ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Jabatan Terakhir</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $employee->jabatan_terakhir ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Jenis Pegawai</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $employee->jenisPegawai?->nama ?? '-' }}</p>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-5 py-4"><h2 class="text-sm font-bold text-ink font-sans">Pengajuan Cuti Terbaru</h2></div>
                <ul class="divide-y divide-border" aria-label="Pengajuan cuti terbaru">
                    @forelse ($employee->leaveRequests as $leave)
                        @php($status = match ($leave->status) {
                            'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
                            'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
                            'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
                            'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
                            'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                            default => ['label' => $leave->status, 'variant' => 'muted'],
                        })
                        <li class="px-5 py-4 hover:bg-soft transition-colors">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-semibold text-ink text-sm font-sans">{{ $leave->jenisCuti?->nama ?? '-' }}</p>
                                    <p class="mt-1 text-xs text-muted font-sans">{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }} – {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }} &middot; {{ $leave->jumlah_hari_kerja }} hari kerja</p>
                                </div>
                                <x-ui.badge :variant="$status['variant']" size="sm" dot>{{ $status['label'] }}</x-ui.badge>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Belum ada pengajuan cuti tercatat.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <!-- KOLOM KANAN (EWS) -->
        <div class="space-y-6">
            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Peringatan EWS Aktif</h3>
                @if(count($employee->ewsAlerts) > 0)
                    <div class="space-y-3">
                        @foreach($employee->ewsAlerts as $alert)
                        <div class="flex items-center justify-between p-3 rounded-lg border border-warning/30 bg-warning/5">
                            <div>
                                <p class="text-[11px] font-bold text-ink font-sans leading-tight capitalize">{{ str_replace('_', ' ', $alert->type) }}</p>
                                <p class="text-[9px] font-medium text-muted font-sans mt-1">Target: {{ $alert->target_date?->translatedFormat('d M Y') ?? '-' }}</p>
                            </div>
                            <x-ui.badge variant="warning" size="sm" dot>
                                Aktif
                            </x-ui.badge>
                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-4">
                        <p class="text-xs text-muted font-sans">Tidak ada peringatan EWS aktif untuk bawahan ini.</p>
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>

