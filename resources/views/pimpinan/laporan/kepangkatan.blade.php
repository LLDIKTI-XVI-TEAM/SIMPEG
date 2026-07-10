<x-layouts.app title="Laporan Kepangkatan">


<div class="space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-ink">Laporan Riwayat Kepangkatan</h2>
        <x-ui.breadcrumb :items="[
            ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
            ['label' => 'Laporan', 'url' => route('pimpinan.laporan.index')],
            ['label' => 'Riwayat Kepangkatan']
        ]" />
    </div>

    <x-ui.card>
        <h3 class="text-lg font-semibold text-ink mb-4">Export Laporan Kepangkatan</h3>
        <form action="#" method="GET" class="space-y-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-ink mb-2">Tahun</label>
                    <select name="tahun" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                        <option value="2026">2026</option>
                        <option value="2025">2025</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-ink mb-2">Format Export</label>
                    <select name="format" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                        <option value="PDF">PDF Document</option>
                        <option value="EXCEL">Ms. Excel (.xlsx)</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <x-ui.button type="button" variant="primary" class="w-full justify-center" onclick="alert('Fitur export kepangkatan sedang dalam pengembangan backend.')">Generate & Export</x-ui.button>
                </div>
            </div>
        </form>
        
        <h3 class="text-lg font-semibold text-ink mb-4 border-t border-border pt-4">Preview Data Kepangkatan</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm mt-2">
                <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-4 py-3">Nama Pegawai</th>
                        <th class="px-4 py-3">Perubahan Golongan</th>
                        <th class="px-4 py-3">TMT</th>
                        <th class="px-4 py-3">Nomor SK</th>
                        <th class="px-4 py-3">Status Usulan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($previewData as $row)
                        <tr class="hover:bg-soft/50">
                            <td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</td>
                            <td class="px-4 py-3 text-ink">
                                <span class="text-muted">{{ $row['golongan_lama'] }}</span>
                                <span class="mx-1">&rarr;</span>
                                <span class="font-bold">{{ $row['golongan_baru'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-muted">{{ \Carbon\Carbon::parse($row['tmt'])->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-ink">{{ $row['sk'] }}</td>
                            <td class="px-4 py-3 text-success font-semibold">{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

</div>
</x-layouts.app>
