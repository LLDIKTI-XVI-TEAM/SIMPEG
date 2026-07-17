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
                <p id="rank-report-help" class="mt-1 text-sm text-muted">Laporan fixed tersedia dalam PDF dan Excel, menggunakan riwayat kepangkatan yang tersimpan.</p>
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
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end pt-4 border-t border-border mt-4">
                    <x-ui.button type="submit" variant="secondary" size="md">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" /></svg>
                        Terapkan Filter
                    </x-ui.button>
                    <x-ui.button type="submit" variant="secondary" size="md" formaction="{{ route('pimpinan.laporan.kepangkatan.pdf') }}" formtarget="_blank">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        Unduh PDF
                    </x-ui.button>
                    <x-ui.button type="submit" variant="secondary" size="md" formaction="{{ route('pimpinan.laporan.kepangkatan.excel') }}" formtarget="_blank">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        Unduh Excel
                    </x-ui.button>
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
