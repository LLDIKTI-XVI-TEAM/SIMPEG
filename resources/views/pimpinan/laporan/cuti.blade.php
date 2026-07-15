<x-layouts.app title="Laporan Cuti">
    <div class="space-y-6">
        <div class="mb-6">
            <h2 class="text-2xl font-semibold text-ink">Laporan Rekapitulasi Cuti</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Laporan', 'url' => route('pimpinan.laporan.index')],
                ['label' => 'Rekapitulasi Cuti']
            ]" />
        </div>

        <x-ui.card>
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-ink">Filter dan Export Laporan Cuti</h3>
                <p id="leave-report-help" class="mt-1 text-sm text-muted">Preview dan Excel menggunakan data pengajuan cuti aktual dengan filter yang sama.</p>
            </div>
            <form action="{{ route('pimpinan.laporan.cuti') }}" method="GET" class="space-y-4" aria-describedby="leave-report-help">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="tahun" class="mb-2 block text-sm font-semibold text-ink">Tahun</label>
                        <x-form.input id="tahun" name="tahun" :value="$filters['tahun'] ?? now()->year" type="number" min="2000" max="2100" size="md" aria-describedby="leave-report-help tahun-error" />
                        @error('tahun')
                            <p id="tahun-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="bulan" class="mb-2 block text-sm font-semibold text-ink">Bulan</label>
                        <x-form.select id="bulan" name="bulan" aria-describedby="leave-report-help" size="md">
                            <option value="">Semua Bulan</option>
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}" @selected((string) ($filters['bulan'] ?? '') === (string) $month)>{{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                    <div>
                        <label for="employee_id" class="mb-2 block text-sm font-semibold text-ink">Pegawai</label>
                        <x-form.select id="employee_id" name="employee_id" aria-describedby="leave-report-help" size="md">
                            <option value="">Semua Pegawai</option>
                            @foreach($filterOptions['employees'] as $employee)
                                <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') === $employee->id)>{{ $employee->nama_lengkap }} — {{ $employee->nip }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                    <div>
                        <label for="unit_kerja_id" class="mb-2 block text-sm font-semibold text-ink">Unit/Tim Kerja</label>
                        <x-form.select id="unit_kerja_id" name="unit_kerja_id" aria-describedby="leave-report-help" size="md">
                            <option value="">Semua Unit/Tim</option>
                            @foreach($filterOptions['units'] as $unit)
                                <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') === $unit->id)>{{ $unit->nama }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                    <div>
                        <label for="jenis_cuti_id" class="mb-2 block text-sm font-semibold text-ink">Jenis Cuti</label>
                        <x-form.select id="jenis_cuti_id" name="jenis_cuti_id" aria-describedby="leave-report-help" size="md">
                            <option value="">Semua Jenis Cuti</option>
                            @foreach($filterOptions['leaveTypes'] as $leaveType)
                                <option value="{{ $leaveType->id }}" @selected(($filters['jenis_cuti_id'] ?? '') === $leaveType->id)>{{ $leaveType->nama }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="submit" variant="secondary" size="md">Terapkan Filter</x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="md" formaction="{{ route('pimpinan.laporan.cuti.excel') }}" formtarget="_blank">Unduh Excel</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-ink">Preview Data Cuti</h3>
                <p class="text-sm text-muted">Menampilkan maksimal 10 pengajuan sesuai filter aktif.</p>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <caption class="sr-only">Preview pengajuan cuti sesuai filter laporan aktif</caption>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Nama Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th padding="lg">Tanggal Pelaksanaan</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jumlah Hari</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($previewData as $row)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50">
                                <x-ui.table-td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-ink">{{ $row['jenis'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-muted">{{ \Carbon\Carbon::parse($row['mulai'])->translatedFormat('d M Y') }} — {{ \Carbon\Carbon::parse($row['selesai'])->translatedFormat('d M Y') }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 text-ink">{{ $row['hari'] }} hari kerja</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3 font-semibold text-ink">{{ $row['status'] }}</x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="5" class="px-4 py-8 text-center text-muted">Tidak ada pengajuan cuti pada periode ini.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
