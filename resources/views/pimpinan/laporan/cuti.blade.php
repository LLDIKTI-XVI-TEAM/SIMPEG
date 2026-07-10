<x-layouts.app title="Laporan Cuti">


<div class="space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-ink">Laporan Rekapitulasi Cuti</h2>
        <x-ui.breadcrumb :items="[
            ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
            ['label' => 'Laporan', 'url' => route('pimpinan.laporan.index')],
            ['label' => 'Rekapitulasi Cuti']
        ]" />
    </div>

    <x-ui.card>
        <h3 class="text-lg font-semibold text-ink mb-4">Export Laporan Cuti</h3>
        <form action="{{ route('laporan.cuti.excel') }}" method="GET" target="_blank" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-ink mb-2">Tahun</label>
                    <select name="tahun" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                        <option value="2026">2026</option>
                        <option value="2025">2025</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-ink mb-2">Bulan</label>
                    <select name="bulan" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                        <option value="">Semua Bulan</option>
                        @foreach(range(1, 12) as $m)
                            <option value="{{ $m }}">{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <x-ui.button type="submit" variant="primary" class="w-full justify-center">Generate & Export</x-ui.button>
                </div>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <h3 class="text-lg font-semibold text-ink mb-4">Preview Data Cuti</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-4 py-3">Nama Pegawai</th>
                        <th class="px-4 py-3">Jenis Cuti</th>
                        <th class="px-4 py-3">Tanggal Pelaksanaan</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($previewData as $row)
                        <tr class="hover:bg-soft/50">
                            <td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</td>
                            <td class="px-4 py-3 text-ink">{{ $row['jenis'] }}</td>
                            <td class="px-4 py-3 text-muted">
                                {{ \Carbon\Carbon::parse($row['mulai'])->format('d M') }} - {{ \Carbon\Carbon::parse($row['selesai'])->format('d M Y') }} ({{ $row['lama'] }})
                            </td>
                            <td class="px-4 py-3 text-ink">{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

</div>
</x-layouts.app>
