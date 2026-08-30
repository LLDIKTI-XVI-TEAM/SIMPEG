@php
    $reportFilters = array_filter([
        'periode' => $periode,
        'unit' => $unit,
        'pegawai' => $pegawaiId,
        'jenis' => $jenisId,
    ]);
@endphp

<x-layouts.app title="Laporan Cuti">
    <div class="space-y-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Laporan Cuti Pegawai</h2>
                <p class="mt-1 text-sm text-muted">Pratinjau data sesuai filter rekap aktif.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if(Route::has('cuti.laporan.pdf'))
                    <a href="{{ route('cuti.laporan.pdf', $reportFilters) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh PDF</a>
                @endif
                @if(Route::has('cuti.laporan.excel'))
                    <a href="{{ route('cuti.laporan.excel', $reportFilters) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh Excel</a>
                @endif
            </div>
        </header>

        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-label="Filter laporan cuti">
            <form id="laporan-filter" method="GET" action="{{ route('cuti.laporan') }}" class="space-y-5">
                <x-cuti.period-filter :period="$periode" id-prefix="laporan" />

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="space-y-1">
                        <label for="laporan-unit" class="text-xs font-bold uppercase tracking-wider text-ink">Unit Kerja</label>
                        <select id="laporan-unit" name="unit" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua unit kerja</option>
                            @foreach($unitOptions as $option)
                                <option value="{{ $option['id'] }}" @selected($unit === $option['id'])>{{ $option['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label for="laporan-jenis" class="text-xs font-bold uppercase tracking-wider text-ink">Jenis Cuti</label>
                        <select id="laporan-jenis" name="jenis" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua jenis cuti</option>
                            @foreach($jenisOptions as $option)
                                <option value="{{ $option['id'] }}" @selected($jenisId === $option['id'])>{{ $option['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-cuti.employee-combobox
                        id="laporan-pegawai"
                        :action="route('cuti.laporan')"
                        name="pegawai"
                        :selected-id="$pegawaiId"
                        :selected-label="$selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : null"
                        label="Pegawai"
                        help="Ketik minimal 2 karakter lalu pilih pegawai dari hasil pencarian."
                        :embedded="true"
                        :auto-submit="false"
                    />
                </div>

                <div class="flex flex-wrap gap-2 border-t border-border pt-4">
                    <x-ui.button type="submit">Terapkan Filter</x-ui.button>
                    <x-ui.button href="{{ route('cuti.laporan') }}" variant="secondary" data-filter-reset>Reset</x-ui.button>
                </div>
            </form>
        </section>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border">
                    <caption class="sr-only">Daftar pengajuan cuti sesuai filter laporan</caption>
                    <thead class="bg-soft/70">
                        <tr>
                            @foreach(['No', 'NIP', 'Nama', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Sumber', 'Status'] as $heading)
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-surface">
                        @forelse($rows as $row)
                            <tr>
                                <td class="px-4 py-3 text-sm text-muted">{{ $rows->firstItem() + $loop->index }}</td>
                                <td class="px-4 py-3 font-mono text-sm text-ink">{{ $row->nip }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-ink">{{ $row->nama }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $row->jenis }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $row->tanggalMulai->format('d-m-Y') }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $row->tanggalSelesai->format('d-m-Y') }}</td>
                                <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row->hari }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $row->sourceLabel }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $row->statusLabel }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-10 text-center text-sm text-muted">Tidak ada data cuti sesuai filter.</td>
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
