<x-layouts.app title="Detail Bawahan" subtitle="Informasi ringkas read-only bawahan langsung.">
    @php($position = $employee->positionHistories->first())
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ $employee->nama_lengkap }}</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                    ['label' => 'Daftar Bawahan', 'url' => route('kepala-bagian.bawahan.index')],
                    ['label' => 'Detail Bawahan'],
                ]" />
            </div>
            <a href="{{ route('kepala-bagian.bawahan.index') }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Kembali ke Daftar</a>
        </div>

        <x-ui.card>
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary/10 text-xl font-bold text-primary">
                    @if ($employee->foto_url)
                        <img src="{{ $employee->foto_url }}" alt="Foto {{ $employee->nama_lengkap }}" class="h-full w-full object-cover">
                    @else
                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($employee->nama_lengkap, 0, 1)) }}
                    @endif
                </div>
                <div class="min-w-0">
                    <h2 class="text-xl font-semibold text-ink">{{ $employee->nama_lengkap }}</h2>
                    <p class="mt-1 font-mono text-sm text-muted">{{ $employee->nip }}</p>
                    <x-ui.badge variant="success" size="sm" dot class="mt-2">{{ $employee->statusPegawai?->nama ?? $employee->status_aktif ?? 'Status tidak tersedia' }}</x-ui.badge>
                </div>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card>
                <h2 class="text-lg font-semibold text-ink">Informasi Kepegawaian</h2>
                <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted">Jabatan</dt><dd class="mt-1 font-medium text-ink">{{ $employee->jabatan_terakhir ?: '-' }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted">Golongan</dt><dd class="mt-1 font-medium text-ink">{{ $employee->golongan_terakhir ?: '-' }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted">Unit Kerja</dt><dd class="mt-1 font-medium text-ink">{{ $position?->unitKerja?->nama ?? '-' }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted">Jenis Pegawai</dt><dd class="mt-1 font-medium text-ink">{{ $employee->jenisPegawai?->nama ?? '-' }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-5 py-4"><h2 class="text-lg font-semibold text-ink">Pengajuan Cuti Terbaru</h2></div>
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
                        <li class="px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div><p class="font-semibold text-ink">{{ $leave->jenisCuti?->nama ?? '-' }}</p><p class="mt-1 text-xs text-muted">{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}–{{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }} · {{ $leave->jumlah_hari_kerja }} hari kerja</p></div>
                                <x-ui.badge :variant="$status['variant']" size="sm" dot>{{ $status['label'] }}</x-ui.badge>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Belum ada pengajuan cuti tercatat.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-border px-5 py-4"><h2 class="text-lg font-semibold text-ink">Peringatan EWS Aktif</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Peringatan EWS aktif untuk bawahan ini</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="px-5 py-3">Jenis Event</th><th scope="col" class="px-5 py-3">Tanggal Target</th><th scope="col" class="px-5 py-3">Status</th></tr></thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($employee->ewsAlerts as $alert)
                            <tr><td class="px-5 py-3 text-ink">{{ str_replace('_', ' ', $alert->type) }}</td><td class="px-5 py-3 text-ink">{{ $alert->target_date?->translatedFormat('d M Y') ?? '-' }}</td><td class="px-5 py-3"><x-ui.badge variant="warning" size="sm" dot>Aktif</x-ui.badge></td></tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-8 text-center text-sm text-muted">Tidak ada peringatan EWS aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
