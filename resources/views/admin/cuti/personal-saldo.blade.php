<x-layouts.app title="Saldo Cuti Saya" description="Informasi jatah, sisa, dan riwayat penggunaan cuti Anda">
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink">Saldo Cuti Saya</h1>
                <p class="text-sm text-muted">Transparansi data saldo cuti tahunan dan riwayat pengajuan cuti Anda.</p>
            </div>
            <div class="inline-flex items-center gap-2 rounded-lg bg-surface px-3 py-1.5 border border-border shadow-sm">
                <span class="h-2 w-2 rounded-full bg-success"></span>
                <span class="text-xs font-semibold text-muted">Periode Tahun {{ $balance->tahun }}</span>
            </div>
        </div>

        <!-- Metrics Grid -->
        <div class="grid gap-5 md:grid-cols-4">
            <!-- Jatah Awal -->
            <div class="relative overflow-hidden rounded-xl border border-border bg-surface p-5 shadow-sm transition-all duration-200 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-muted">Jatah Cuti</p>
                    <div class="rounded-lg bg-primary/10 p-2 text-primary">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-ink">{{ $balance->jatah_awal }}</span>
                    <span class="text-sm text-muted">hari kerja</span>
                </div>
            </div>

            <!-- Carry Over -->
            <div class="relative overflow-hidden rounded-xl border border-border bg-surface p-5 shadow-sm transition-all duration-200 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-muted">Carry Over ({{ $balance->tahun - 1 }})</p>
                    <div class="rounded-lg bg-info/10 p-2 text-info">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-ink">{{ $balance->carry_over }}</span>
                    <span class="text-sm text-muted">hari</span>
                </div>
            </div>

            <!-- Terpakai -->
            <div class="relative overflow-hidden rounded-xl border border-border bg-surface p-5 shadow-sm transition-all duration-200 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-muted">Cuti Terpakai</p>
                    <div class="rounded-lg bg-warning/10 p-2 text-warning">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-ink text-warning">{{ $balance->terpakai }}</span>
                    <span class="text-sm text-muted">hari</span>
                </div>
            </div>

            <!-- Sisa -->
            <div class="relative overflow-hidden rounded-xl border border-primary/20 bg-gradient-to-br from-surface to-primary/5 p-5 shadow-sm transition-all duration-200 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-primary">Sisa Saldo Cuti</p>
                    <div class="rounded-lg bg-primary p-2 text-white">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 flex items-baseline gap-2">
                    <span class="text-3xl font-extrabold text-primary">{{ $balance->sisa }}</span>
                    <span class="text-sm font-semibold text-primary/80">hari</span>
                </div>
            </div>
        </div>

        <!-- History -->
        <div class="rounded-xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-lg font-bold text-ink">Riwayat Pengajuan Cuti</h2>
                <p class="text-xs text-muted">Daftar semua pengajuan cuti Anda beserta status persetujuan saat ini.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-muted/5 text-xs font-semibold text-muted border-b border-border">
                            <th class="px-5 py-3">Jenis Cuti</th>
                            <th class="px-5 py-3">Tanggal Pelaksanaan</th>
                            <th class="px-5 py-3 text-center">Durasi</th>
                            <th class="px-5 py-3">Alasan</th>
                            <th class="px-5 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-sm text-ink">
                        @forelse($history as $r)
                            <tr class="hover:bg-muted/5 transition-colors">
                                <td class="px-5 py-3.5 font-medium">{{ $r->jenisCuti?->nama }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="font-semibold">{{ $r->tanggal_mulai->translatedFormat('d M Y') }}</div>
                                    <div class="text-xs text-muted">s/d {{ $r->tanggal_selesai->translatedFormat('d M Y') }}</div>
                                </td>
                                <td class="px-5 py-3.5 text-center font-medium">{{ $r->jumlah_hari_kerja }} hari</td>
                                <td class="px-5 py-3.5 max-w-xs truncate" title="{{ $r->alasan }}">{{ $r->alasan }}</td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold
                                        @if($r->status === 'Disetujui') bg-success/10 text-success
                                        @elseif($r->status === 'Ditunda') bg-warning/10 text-warning
                                        @elseif($r->status === 'Draft') bg-muted text-muted-foreground
                                        @else bg-primary/10 text-primary
                                        @endif
                                    ">
                                        <span class="h-1.5 w-1.5 rounded-full
                                            @if($r->status === 'Disetujui') bg-success
                                            @elseif($r->status === 'Ditunda') bg-warning
                                            @elseif($r->status === 'Draft') bg-muted-foreground
                                            @else bg-primary
                                            @endif
                                        "></span>
                                        {{ $r->status }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-muted">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="h-8 w-8 text-muted/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <p>Belum ada riwayat pengajuan cuti.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($history->hasPages())
                <div class="border-t border-border px-5 py-3">
                    {{ $history->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
