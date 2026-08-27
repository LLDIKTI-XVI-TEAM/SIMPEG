<x-layouts.app title="Saldo Cuti Saya" description="Informasi jatah, sisa, dan riwayat penggunaan cuti Anda">
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink">Saldo Cuti Saya</h1>
                <p class="text-sm text-muted">Saldo dihitung dari pemakaian cuti yang tercatat dan tidak dapat diubah langsung.</p>
            </div>
            <div class="inline-flex items-center gap-2 rounded-lg bg-surface px-3 py-1.5 border border-border shadow-sm">
                <span class="h-2 w-2 rounded-full bg-success"></span>
                <span class="text-xs font-semibold text-muted">Periode Tahun {{ $balance?->tahun ?? now()->year }}</span>
            </div>
        </div>

        @if ($rule5Active)
            <x-ui.alert variant="warning">
                Saldo tercatat, tidak dapat digunakan pada tahun Cuti Besar. Saldo cuti tahunan tetap tercatat sebagai riwayat. Hak efektif tahun ini adalah 0 karena Cuti Besar telah disetujui.
            </x-ui.alert>
        @endif

        <!-- Metrics Grid -->
        @if($balance)
            <div class="grid gap-5 md:grid-cols-4">
                <!-- Jatah Awal -->
                <x-ui.stat-card label="Jatah Cuti" value="{{ $balance->jatah_awal }}" variant="primary" size="lg" label-class="normal-case tracking-normal text-sm font-medium" value-class="text-ink">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </x-slot:icon>
                <span class="text-sm text-muted normal-case tracking-normal">hari kerja</span>
                </x-ui.stat-card>

                <!-- Carry Over -->
                <x-ui.stat-card label="Carry Over ({{ $balance->tahun - 1 }})" value="{{ $balance->carry_over }}" variant="info" size="lg" label-class="normal-case tracking-normal text-sm font-medium" value-class="text-ink">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </x-slot:icon>
                <span class="text-sm text-muted normal-case tracking-normal">hari</span>
                </x-ui.stat-card>

                <!-- Terpakai -->
                <x-ui.stat-card label="Cuti Terpakai" value="{{ $balance->terpakai }}" variant="warning" size="lg" label-class="normal-case tracking-normal text-sm font-medium">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </x-slot:icon>
                <span class="text-sm text-muted normal-case tracking-normal">hari</span>
                </x-ui.stat-card>

                <!-- Sisa -->
                <x-ui.stat-card label="Saldo Tercatat" value="{{ $balance->sisa }}" unit="hari tercatat" variant="primary" size="lg" surface="soft" label-class="normal-case tracking-normal text-sm font-semibold text-primary" role="group" aria-label="{{ $balance->sisa }} hari tercatat">
                <x-slot:icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </x-slot:icon>
                </x-ui.stat-card>
            </div>
        @else
            <x-ui.card>
                <p class="text-sm font-semibold text-ink">Saldo cuti tahunan belum tersedia</p>
                <p class="mt-1 text-sm text-muted">Saldo akan tampil setelah data pemakaian tiga tahun dicatat dan hak cuti tahunan selesai dihitung.</p>
            </x-ui.card>
        @endif

        <!-- History -->
        <div class="rounded-xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-lg font-bold text-ink">Riwayat Pengajuan Cuti</h2>
                <p class="text-xs text-muted">Daftar semua pengajuan cuti Anda beserta status persetujuan saat ini.</p>
            </div>

            <div class="overflow-x-auto">
                <x-ui.table class="text-left border-collapse">
                    <x-ui.table-head>
                        <x-ui.table-row class="bg-muted/5 border-b border-border">
                            <x-ui.table-th class="px-5 py-3">Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Tanggal Pelaksanaan</x-ui.table-th>
                            <x-ui.table-th align="center" class="px-5 py-3">Durasi</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Alasan</x-ui.table-th>
                            <x-ui.table-th align="center" class="px-5 py-3">Status</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="text-sm text-ink">
                        @forelse($history as $r)
                            <x-ui.table-row class="!cursor-default hover:!bg-transparent">
                                <x-ui.table-td padding="wide" class="font-medium">{{ $r->jenisCuti?->nama }}</x-ui.table-td>
                                <x-ui.table-td padding="wide">
                                    <div class="font-semibold">{{ $r->tanggal_mulai->translatedFormat('d M Y') }}</div>
                                    <div class="text-xs text-muted">s/d {{ $r->tanggal_selesai->translatedFormat('d M Y') }}</div>
                                </x-ui.table-td>
                                <x-ui.table-td align="center" padding="wide" class="font-medium">{{ $r->jumlah_hari_kerja }} hari</x-ui.table-td>
                                <x-ui.table-td padding="wide" class="min-w-64 whitespace-normal break-words leading-relaxed">
                                    {{ $r->alasan }}
                                </x-ui.table-td>
                                <x-ui.table-td align="center" padding="wide">
                                    <x-ui.badge
                                        :variant="match ($r->status) {
                                            'disetujui' => 'success',
                                            'ditangguhkan' => 'warning',
                                            'ditangguhkan_tugas_dinas' => 'warning',
                                            'dikembalikan_karena_rollover' => 'warning',
                                            'perlu_perubahan', 'tidak_disetujui' => 'danger',
                                            default => 'primary',
                                        }"
                                        size="md"
                                        dot
                                    >
                                        {{ match ($r->status) {
                                            'menunggu_approval' => 'Menunggu Keputusan',
                                            'ditangguhkan' => 'Ditangguhkan',
                                            'ditangguhkan_tugas_dinas' => 'Ditangguhkan karena Tugas Dinas',
                                            'dikembalikan_karena_rollover' => 'Dikembalikan karena Rollover',
                                            'perlu_perubahan' => 'Perubahan',
                                            'disetujui' => 'Disetujui',
                                            'tidak_disetujui' => 'Tidak Disetujui',
                                            default => $r->status,
                                        } }}
                                    </x-ui.badge>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="5" align="center" class="px-5 py-10 text-muted">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="h-8 w-8 text-muted/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <p>Belum ada riwayat pengajuan cuti.</p>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            @if($history->hasPages())
                <div class="border-t border-border px-5 py-3">
                    {{ $history->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
