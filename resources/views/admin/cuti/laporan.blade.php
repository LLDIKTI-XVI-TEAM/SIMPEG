<x-layouts.app title="Laporan Cuti">
    <div class="space-y-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Laporan Cuti Pegawai</h2>
                <p class="mt-1 text-sm text-muted">Pratinjau data sesuai filter rekap aktif.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if(Route::has('cuti.laporan.pdf'))
                    <a href="{{ route('cuti.laporan.pdf', $filters) }}" class="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink hover:bg-soft">Unduh PDF</a>
                @endif
                @if(Route::has('cuti.laporan.excel'))
                    <a href="{{ route('cuti.laporan.excel', $filters) }}" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90">Unduh Excel</a>
                @endif
            </div>
        </header>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border">
                    <caption class="sr-only">Daftar pengajuan cuti sesuai filter laporan</caption>
                    <thead class="bg-soft/70">
                        <tr>
                            @foreach(['No', 'NIP', 'Nama', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Status'] as $heading)
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-surface">
                        @forelse($rows as $row)
                            <tr>
                                <td class="px-4 py-3 text-sm text-muted">{{ $rows->firstItem() + $loop->index }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-ink">{{ $row->employee?->nip ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-ink">{{ $row->employee?->nama_lengkap ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $row->jenisCuti?->nama ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $row->tanggal_mulai?->format('d-m-Y') ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $row->tanggal_selesai?->format('d-m-Y') ?? '-' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row->jumlah_hari_kerja }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $row->report_status }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-sm text-muted">Tidak ada data cuti sesuai filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($rows->hasPages())
                <div class="border-t border-border px-5 py-4">
                    {{ $rows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
