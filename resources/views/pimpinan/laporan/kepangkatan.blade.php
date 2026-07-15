<x-layouts.app title="Laporan Kepangkatan">
    <div class="space-y-6">
        <div class="mb-6">
            <h2 class="text-2xl font-semibold text-ink">Laporan Riwayat Kepangkatan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Laporan', 'url' => route('pimpinan.laporan.index')],
                ['label' => 'Riwayat Kepangkatan']
            ]" />
        </div>

        <x-ui.card>
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-ink">Filter dan Export Laporan Kepangkatan</h3>
                <p id="rank-report-help" class="mt-1 text-sm text-muted">Laporan fixed tersedia dalam PDF dan Excel (.xlsx), menggunakan riwayat kepangkatan yang tersimpan.</p>
            </div>
            <form action="{{ route('pimpinan.laporan.kepangkatan') }}" method="GET" class="space-y-4" aria-describedby="rank-report-help">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="employee_id" class="mb-2 block text-sm font-semibold text-ink">Pegawai</label>
                        <x-form.select id="employee_id" name="employee_id" aria-describedby="rank-report-help" size="md">
                            <option value="">Semua Pegawai</option>
                            @foreach($filterOptions['employees'] as $employee)
                                <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') === $employee->id)>{{ $employee->nama_lengkap }} — {{ $employee->nip }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                    <div>
                        <label for="golongan_id" class="mb-2 block text-sm font-semibold text-ink">Golongan</label>
                        <x-form.select id="golongan_id" name="golongan_id" aria-describedby="rank-report-help" size="md">
                            <option value="">Semua Golongan</option>
                            @foreach($filterOptions['ranks'] as $rank)
                                <option value="{{ $rank->id }}" @selected(($filters['golongan_id'] ?? '') === $rank->id)>{{ $rank->kode }} — {{ $rank->nama }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                    <div>
                        <label for="tahun" class="mb-2 block text-sm font-semibold text-ink">Tahun TMT</label>
                        <x-form.input id="tahun" name="tahun" :value="$filters['tahun'] ?? ''" type="number" min="2000" max="2100" size="md" aria-describedby="rank-report-help tahun-error" />
                        @error('tahun')
                            <p id="tahun-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="submit" variant="secondary" size="md">Terapkan Filter</x-ui.button>
                    <x-ui.button type="submit" variant="secondary" size="md" formaction="{{ route('pimpinan.laporan.kepangkatan.pdf') }}" formtarget="_blank">Unduh PDF</x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="md" formaction="{{ route('pimpinan.laporan.kepangkatan.excel') }}" formtarget="_blank">Unduh Excel (.xlsx)</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-ink">Preview Riwayat Kepangkatan</h3>
                <p class="text-sm text-muted">Riwayat diurutkan dari TMT terbaru dan dibatasi 10 data.</p>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <caption class="sr-only">Preview riwayat kepangkatan pegawai sesuai filter</caption>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Nama Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Golongan</x-ui.table-th>
                            <x-ui.table-th padding="lg">TMT</x-ui.table-th>
                            <x-ui.table-th padding="lg">Nomor SK</x-ui.table-th>
                            <x-ui.table-th padding="lg">Tanggal SK</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($previewData as $row)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50">
                                <x-ui.table-td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-ink">{{ $row['golongan'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-muted">{{ \Carbon\Carbon::parse($row['tmt'])->translatedFormat('d M Y') }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-ink">{{ $row['no_sk'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-muted">{{ $row['tanggal_sk'] === '-' ? '-' : \Carbon\Carbon::parse($row['tanggal_sk'])->translatedFormat('d M Y') }}</x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="5" class="px-4 py-8 text-center text-muted">Tidak ada riwayat kepangkatan yang sesuai dengan filter.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
