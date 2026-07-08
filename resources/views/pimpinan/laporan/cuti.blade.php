<x-layouts.app title="EWS Pimpinan">


<div class="max-w-7xl mx-auto px-6 py-6 space-y-6">

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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-semibold text-ink mb-2">Tahun</label>
                <select class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                    <option value="2026">2026</option>
                    <option value="2025">2025</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-ink mb-2">Bulan</label>
                <select class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                    <option value="ALL">Semua Bulan</option>
                    <option value="08">Agustus</option>
                </select>
            </div>
            <div class="flex items-end">
                <x-ui.button variant="secondary" class="w-full justify-center text-muted cursor-not-allowed" disabled>Fitur Belum Tersedia</x-ui.button>
            </div>
        </div>
        <p class="mt-4 text-xs text-muted italic">* Fitur export cuti saat ini sedang dalam pengembangan.</p>
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
