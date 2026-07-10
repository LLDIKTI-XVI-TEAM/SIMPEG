<x-layouts.app title="Laporan Pegawai">


<div class="space-y-6">

    @if(session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- PAGE HEADER --}}
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-ink">Laporan Data Pegawai</h2>
        <x-ui.breadcrumb :items="[
            ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
            ['label' => 'Laporan', 'url' => route('pimpinan.laporan.index')],
            ['label' => 'Data Pegawai']
        ]" />
    </div>

    <x-ui.card>
        <h3 class="text-lg font-semibold text-ink mb-4">Export Laporan Pegawai</h3>
        <form action="{{ route('pimpinan.laporan.pegawai.custom') }}" method="POST" target="_blank" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-ink mb-2">Pilih Golongan</label>
                    <select name="golongan" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none">
                        <option value="ALL">Semua Golongan</option>
                        <option value="IV">Golongan IV</option>
                        <option value="III">Golongan III</option>
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
                    <x-ui.button type="submit" variant="primary" class="w-full justify-center">Generate & Export</x-ui.button>
                </div>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <h3 class="text-lg font-semibold text-ink mb-4">Preview Data (3 Teratas)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-4 py-3">NIP</th>
                        <th class="px-4 py-3">Nama Pegawai</th>
                        <th class="px-4 py-3">Golongan</th>
                        <th class="px-4 py-3">Jabatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($previewData as $row)
                        <tr class="hover:bg-soft/50">
                            <td class="px-4 py-3 font-mono text-muted">{{ $row['nip'] }}</td>
                            <td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</td>
                            <td class="px-4 py-3 text-ink">{{ $row['golongan'] }}</td>
                            <td class="px-4 py-3 text-ink">{{ $row['jabatan'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

</div>
</x-layouts.app>
