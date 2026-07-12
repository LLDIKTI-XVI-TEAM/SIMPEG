<x-layouts.app title="Monitoring Cuti">
    @php
        $statusOptions = [
            'menunggu' => 'Menunggu Keputusan',
            'disetujui' => 'Disetujui',
            'perubahan' => 'Perubahan',
            'ditangguhkan' => 'Ditangguhkan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink">Monitoring Cuti</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                    ['label' => 'Monitoring Cuti'],
                ]" />
            </div>
            <a href="{{ route('pimpinan.laporan.cuti') }}" class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">Laporan Cuti</a>
        </div>

        <x-ui.card padding="none">
            <form method="GET" action="{{ route('pimpinan.cuti.index') }}" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="search" class="mb-1 block text-sm font-medium text-ink">Cari pegawai</label>
                    <input id="search" name="search" value="{{ request('search') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" placeholder="Nama atau NIP">
                </div>
                <div>
                    <label for="status" class="mb-1 block text-sm font-medium text-ink">Status</label>
                    <select id="status" name="status" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua status</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="unit_kerja_id" class="mb-1 block text-sm font-medium text-ink">Unit/Tim Kerja</label>
                    <select id="unit_kerja_id" name="unit_kerja_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua unit</option>
                        @foreach ($unitKerjaOptions as $unit)
                            <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') === $unit->id)>{{ $unit->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tahun" class="mb-1 block text-sm font-medium text-ink">Tahun</label>
                    <input id="tahun" name="tahun" value="{{ $filters['tahun'] ?? '' }}" type="number" min="2000" max="2100" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" placeholder="Semua tahun">
                </div>
                <div>
                    <label for="jenis_cuti_id" class="mb-1 block text-sm font-medium text-ink">Jenis cuti</label>
                    <select id="jenis_cuti_id" name="jenis_cuti_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua jenis cuti</option>
                        @foreach ($jenisCutiOptions as $jenisCuti)
                            <option value="{{ $jenisCuti->id }}" @selected(request('jenis_cuti_id') === $jenisCuti->id)>{{ $jenisCuti->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulan" class="mb-1 block text-sm font-medium text-ink">Bulan mulai</label>
                    <select id="bulan" name="bulan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua bulan</option>
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((string) request('bulan') === (string) $month)>{{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="none">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <caption class="sr-only">Daftar pengajuan cuti pegawai</caption>
                    <thead class="bg-soft">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Pegawai</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Jenis Cuti</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Pelaksanaan</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($leaves as $leave)
                            @php
                                $status = match ($leave->status) {
                                    'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
                                    'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
                                    'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
                                    'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    default => ['label' => $leave->status, 'variant' => 'muted'],
                                };
                            @endphp
                            <tr class="transition-colors hover:bg-soft/60">
                                <th scope="row" class="px-4 py-3.5 text-left text-sm text-ink">
                                    <p class="font-semibold">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p>
                                    <p class="font-mono text-xs text-muted">{{ $leave->employee?->nip ?? '-' }}</p>
                                </th>
                                <td class="px-4 py-3.5 text-sm text-ink">{{ $leave->jenisCuti?->nama ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-sm text-ink">
                                    {{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}<br>
                                    <span class="text-xs text-muted">s.d. {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</span>
                                </td>
                                <td class="px-4 py-3.5"><x-ui.badge :variant="$status['variant']" size="sm" dot>{{ $status['label'] }}</x-ui.badge></td>
                                <td class="px-4 py-3.5 text-right"><a href="{{ route('pimpinan.cuti.show', $leave) }}" aria-label="Detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? $leave->id }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Detail</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-muted">Tidak ada pengajuan cuti yang sesuai dengan filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-4 py-3">
                {{ $leaves->links() }}
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
