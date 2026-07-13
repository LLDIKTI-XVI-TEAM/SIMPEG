<x-layouts.app title="Pengajuan Cuti Bawahan" subtitle="Antrean pengajuan bawahan langsung yang menunggu tindakan Anda.">
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Pengajuan Cuti Bawahan</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'Pengajuan Cuti Bawahan'],
            ]" />
        </div>

        <x-ui.alert variant="info" title="Antrean tindakan">
            Daftar ini hanya memuat pengajuan bawahan langsung pada step approval aktif Anda.
        </x-ui.alert>

        <x-ui.card>
            <form method="GET" action="{{ route('kepala-bagian.cuti.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <label for="search" class="mb-1 block text-sm font-medium text-ink">Cari pengajuan</label>
                    <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" type="search" placeholder="Nama atau NIP pegawai" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                </div>
                <div>
                    <label for="jenis_cuti_id" class="mb-1 block text-sm font-medium text-ink">Jenis cuti</label>
                    <select id="jenis_cuti_id" name="jenis_cuti_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua jenis cuti</option>
                        @foreach ($jenisCutiOptions as $jenisCuti)
                            <option value="{{ $jenisCuti->id }}" @selected(($filters['jenis_cuti_id'] ?? '') === $jenisCuti->id)>{{ $jenisCuti->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tahun" class="mb-1 block text-sm font-medium text-ink">Tahun mulai</label>
                    <input id="tahun" name="tahun" value="{{ $filters['tahun'] ?? '' }}" type="number" min="2000" max="2100" placeholder="Semua tahun" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                </div>
                <div>
                    <label for="bulan" class="mb-1 block text-sm font-medium text-ink">Bulan mulai</label>
                    <select id="bulan" name="bulan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua bulan</option>
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((string) ($filters['bulan'] ?? '') === (string) $month)>{{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3">
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button>
                    <a href="{{ route('kepala-bagian.cuti.index') }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Reset</a>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Daftar pengajuan cuti bawahan yang menunggu tindakan Kepala Bagian</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3">Pegawai</th>
                            <th scope="col" class="px-5 py-3">Jenis Cuti</th>
                            <th scope="col" class="px-5 py-3">Pelaksanaan</th>
                            <th scope="col" class="px-5 py-3">Lama</th>
                            <th scope="col" class="px-5 py-3">Status</th>
                            <th scope="col" class="px-5 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($leaves as $leave)
                            @php($status = $leave->status === 'ditangguhkan' ? ['label' => 'Ditangguhkan', 'variant' => 'warning'] : ['label' => 'Menunggu Keputusan', 'variant' => 'warning'])
                            <tr class="transition-colors hover:bg-soft/60">
                                <th scope="row" class="px-5 py-3 text-left"><p class="font-semibold text-ink">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p><p class="mt-1 font-mono text-xs font-normal text-muted">{{ $leave->employee?->nip ?? '-' }}</p></th>
                                <td class="px-5 py-3 text-ink">{{ $leave->jenisCuti?->nama ?? '-' }}</td>
                                <td class="px-5 py-3 text-ink">{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}<span class="block text-xs text-muted">s.d. {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</span></td>
                                <td class="px-5 py-3 text-ink">{{ $leave->jumlah_hari_kerja }} hari kerja</td>
                                <td class="px-5 py-3"><x-ui.badge :variant="$status['variant']" size="sm" dot>{{ $status['label'] }}</x-ui.badge></td>
                                <td class="px-5 py-3 text-right"><a href="{{ route('kepala-bagian.cuti.show', $leave) }}" aria-label="Detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? $leave->id }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-muted">Tidak ada pengajuan cuti yang menunggu tindakan Anda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-4 py-3">{{ $leaves->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
