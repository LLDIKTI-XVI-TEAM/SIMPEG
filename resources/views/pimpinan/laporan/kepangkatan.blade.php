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
                        <select id="employee_id" name="employee_id" aria-describedby="rank-report-help" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Pegawai</option>
                            @foreach($filterOptions['employees'] as $employee)
                                <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') === $employee->id)>{{ $employee->nama_lengkap }} — {{ $employee->nip }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="golongan_id" class="mb-2 block text-sm font-semibold text-ink">Golongan</label>
                        <select id="golongan_id" name="golongan_id" aria-describedby="rank-report-help" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Golongan</option>
                            @foreach($filterOptions['ranks'] as $rank)
                                <option value="{{ $rank->id }}" @selected(($filters['golongan_id'] ?? '') === $rank->id)>{{ $rank->kode }} — {{ $rank->nama }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="tahun" class="mb-2 block text-sm font-semibold text-ink">Tahun TMT</label>
                        <input id="tahun" name="tahun" value="{{ $filters['tahun'] ?? '' }}" type="number" min="2000" max="2100" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" aria-describedby="rank-report-help tahun-error">
                        @error('tahun')
                            <p id="tahun-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-[background-color,border-color,box-shadow,opacity] duration-200 hover:border-primary/30 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button>
                    <button type="submit" formaction="{{ route('pimpinan.laporan.kepangkatan.pdf') }}" formtarget="_blank" class="inline-flex items-center justify-center gap-2 rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-[background-color,border-color,box-shadow,opacity] duration-200 hover:border-primary/30 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh PDF</button>
                    <button type="submit" formaction="{{ route('pimpinan.laporan.kepangkatan.excel') }}" formtarget="_blank" class="inline-flex items-center justify-center gap-2 rounded-xl border border-transparent bg-gradient-to-r from-primary to-primary-hover px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-primary/20 transition-[background-color,border-color,box-shadow,opacity] duration-200 hover:opacity-90 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh Excel (.xlsx)</button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-ink">Preview Riwayat Kepangkatan</h3>
                <p class="text-sm text-muted">Riwayat diurutkan dari TMT terbaru dan dibatasi 10 data.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Preview riwayat kepangkatan pegawai sesuai filter</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3">Nama Pegawai</th>
                            <th scope="col" class="px-4 py-3">Golongan</th>
                            <th scope="col" class="px-4 py-3">TMT</th>
                            <th scope="col" class="px-4 py-3">Nomor SK</th>
                            <th scope="col" class="px-4 py-3">Tanggal SK</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($previewData as $row)
                            <tr class="hover:bg-soft/50">
                                <td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</td>
                                <td class="px-4 py-3 text-ink">{{ $row['golongan'] }}</td>
                                <td class="px-4 py-3 text-muted">{{ \Carbon\Carbon::parse($row['tmt'])->translatedFormat('d M Y') }}</td>
                                <td class="px-4 py-3 text-ink">{{ $row['no_sk'] }}</td>
                                <td class="px-4 py-3 text-muted">{{ $row['tanggal_sk'] === '-' ? '-' : \Carbon\Carbon::parse($row['tanggal_sk'])->translatedFormat('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-muted">Tidak ada riwayat kepangkatan yang sesuai dengan filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
