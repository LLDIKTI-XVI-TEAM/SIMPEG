<x-layouts.app title="EWS Pimpinan">


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
        <h3 class="text-lg font-semibold text-ink mb-4">Laporan Kepangkatan Pegawai</h3>
        <p class="text-sm text-muted mb-4">Fitur ini merupakan bagian dari target PRD L3 dan masih dalam tahap dummy untuk keperluan tampilan awal (Frontend-Only).</p>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-t border-border mt-2">
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
