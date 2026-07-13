<x-layouts.app title="Dashboard Kepala Bagian" subtitle="Ringkasan bawahan langsung dan pengajuan cuti yang memerlukan tindakan.">
    <div class="space-y-6">
        <section aria-labelledby="dashboard-heading" class="rounded-xl bg-primary px-5 py-5 text-white shadow-md">
            <p class="text-xs font-semibold uppercase tracking-wide text-white/80">Dashboard Kepala Bagian</p>
            <h1 id="dashboard-heading" class="mt-1 text-2xl font-semibold">{{ $namaKepalaBagian }}</h1>
            <p class="mt-2 text-sm text-white/90">Data bawahan langsung dan antrean cuti diperbarui saat halaman dibuka.</p>
        </section>

        <section aria-label="Ringkasan bawahan" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat-card href="{{ route('kepala-bagian.bawahan.index') }}" label="Bawahan Aktif" value="{{ $totalBawahanAktif }}" variant="primary" size="lg">
                <x-slot:meta><span>Bawahan langsung yang aktif</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('kepala-bagian.cuti.index') }}" label="Cuti Menunggu Tindakan" value="{{ $cutiPending }}" variant="warning" size="lg">
                <x-slot:meta><span>Hanya pengajuan pada tahap Anda</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('kepala-bagian.bawahan.index', ['status' => 'cuti']) }}" label="Bawahan Sedang Cuti" value="{{ $bawahanSedangCuti }}" variant="info" size="lg">
                <x-slot:meta><span>Pengajuan berstatus Disetujui hari ini</span></x-slot:meta>
            </x-ui.stat-card>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-ink">Pengajuan Cuti Bawahan</h2>
                        <p class="mt-1 text-sm text-muted">Pengajuan yang menunggu keputusan Anda.</p>
                    </div>
                    <a href="{{ route('kepala-bagian.cuti.index') }}" class="text-sm font-semibold text-primary hover:underline">Lihat antrean</a>
                </div>
                <ul class="divide-y divide-border" aria-label="Pengajuan cuti menunggu tindakan">
                    @forelse ($pendingLeaves as $leave)
                        <li class="px-5 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <span class="block font-semibold text-ink">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</span>
                                    <span class="mt-1 block text-xs text-muted">
                                        {{ $leave->jenisCuti?->nama ?? '-' }} · {{ $leave->jumlah_hari_kerja }} hari kerja ·
                                        {{ $leave->tanggal_mulai?->translatedFormat('d M') ?? '-' }}–{{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}
                                    </span>
                                </div>
                                <a href="{{ route('kepala-bagian.cuti.show', $leave) }}" aria-label="Tinjau dan ambil keputusan untuk pengajuan cuti {{ $leave->employee?->nama_lengkap ?? $leave->id }}" class="inline-flex shrink-0 items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-2 text-xs font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Tinjau &amp; Ambil Keputusan</a>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Tidak ada pengajuan cuti yang menunggu tindakan Anda.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-ink">EWS Bawahan</h2>
                        <p class="mt-1 text-sm text-muted">Lima peringatan aktif dengan target terdekat.</p>
                    </div>
                    <a href="{{ route('kepala-bagian.ews.index') }}" class="text-sm font-semibold text-primary hover:underline">Lihat semua</a>
                </div>
                <ul class="divide-y divide-border" aria-label="Peringatan dini bawahan">
                    @forelse ($ewsBawahan as $alert)
                        <li class="px-5 py-4">
                            <a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="block rounded focus:outline-none focus:ring-2 focus:ring-primary">
                                <span class="block font-semibold text-ink">{{ $alert['nama'] }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ $alert['jenis_event'] }} · target {{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</span>
                                <x-ui.badge :variant="$alert['urgency']" size="sm" dot class="mt-2">{{ $alert['sisa_hari'] < 0 ? 'Lewat '.abs($alert['sisa_hari']).' hari' : $alert['sisa_hari'].' hari' }}</x-ui.badge>
                            </a>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Tidak ada peringatan EWS aktif untuk bawahan langsung.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-ink">Daftar Bawahan Langsung</h2>
                    <p class="mt-1 text-sm text-muted">Menampilkan lima bawahan aktif pertama berdasarkan nama.</p>
                </div>
                <a href="{{ route('kepala-bagian.bawahan.index') }}" class="text-sm font-semibold text-primary hover:underline">Buka daftar bawahan</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Daftar bawahan langsung aktif</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3">Pegawai</th>
                            <th scope="col" class="px-5 py-3">Jabatan</th>
                            <th scope="col" class="px-5 py-3">Unit Kerja</th>
                            <th scope="col" class="px-5 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($bawahan as $employee)
                            @php($position = $employee->positionHistories->first())
                            <tr class="transition-colors hover:bg-soft/60">
                                <th scope="row" class="px-5 py-3 text-left">
                                    <a href="{{ route('kepala-bagian.bawahan.show', $employee) }}" class="font-semibold text-ink hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/30">{{ $employee->nama_lengkap }}</a>
                                    <span class="mt-1 block font-mono text-xs font-normal text-muted">{{ $employee->nip }}</span>
                                </th>
                                <td class="px-5 py-3 text-ink">{{ $employee->jabatan_terakhir ?: '-' }}</td>
                                <td class="px-5 py-3 text-ink">{{ $position?->unitKerja?->nama ?? '-' }}</td>
                                <td class="px-5 py-3"><x-ui.badge :variant="$employee->sedang_cuti ? 'info' : 'success'" size="sm" dot>{{ $employee->sedang_cuti ? 'Cuti' : 'Aktif' }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-8 text-center text-muted">Belum ada bawahan langsung yang aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
