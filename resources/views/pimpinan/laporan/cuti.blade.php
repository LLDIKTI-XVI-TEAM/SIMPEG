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
                        <input id="tahun" name="tahun" value="{{ $filters['tahun'] ?? now()->year }}" type="number" min="2000" max="2100" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" aria-describedby="leave-report-help tahun-error">
                        @error('tahun')
                            <p id="tahun-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="bulan" class="mb-2 block text-sm font-semibold text-ink">Bulan</label>
                        <select id="bulan" name="bulan" aria-describedby="leave-report-help" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Bulan</option>
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}" @selected((string) ($filters['bulan'] ?? '') === (string) $month)>{{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="employee_id" class="mb-2 block text-sm font-semibold text-ink">Pegawai</label>
                        <select id="employee_id" name="employee_id" aria-describedby="leave-report-help" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Pegawai</option>
                            @foreach($filterOptions['employees'] as $employee)
                                <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') === $employee->id)>{{ $employee->nama_lengkap }} — {{ $employee->nip }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="unit_kerja_id" class="mb-2 block text-sm font-semibold text-ink">Unit/Tim Kerja</label>
                        <select id="unit_kerja_id" name="unit_kerja_id" aria-describedby="leave-report-help" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Unit/Tim</option>
                            @foreach($filterOptions['units'] as $unit)
                                <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') === $unit->id)>{{ $unit->nama }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="jenis_cuti_id" class="mb-2 block text-sm font-semibold text-ink">Jenis Cuti</label>
                        <select id="jenis_cuti_id" name="jenis_cuti_id" aria-describedby="leave-report-help" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua Jenis Cuti</option>
                            @foreach($filterOptions['leaveTypes'] as $leaveType)
                                <option value="{{ $leaveType->id }}" @selected(($filters['jenis_cuti_id'] ?? '') === $leaveType->id)>{{ $leaveType->nama }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-[background-color,border-color,box-shadow,opacity] duration-200 hover:border-primary/30 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button>
                    <button type="submit" formaction="{{ route('pimpinan.laporan.cuti.excel') }}" formtarget="_blank" class="inline-flex items-center justify-center gap-2 rounded-xl border border-transparent bg-gradient-to-r from-primary to-primary-hover px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-primary/20 transition-[background-color,border-color,box-shadow,opacity] duration-200 hover:opacity-90 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh Excel (.xlsx)</button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-ink">Preview Data Cuti</h3>
                <p class="text-sm text-muted">Menampilkan maksimal 10 pengajuan sesuai filter aktif.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Preview pengajuan cuti sesuai filter laporan aktif</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3">Nama Pegawai</th>
                            <th scope="col" class="px-4 py-3">Jenis Cuti</th>
                            <th scope="col" class="px-4 py-3">Tanggal Pelaksanaan</th>
                            <th scope="col" class="px-4 py-3">Jumlah Hari</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($previewData as $row)
                            <tr class="hover:bg-soft/50">
                                <td class="px-4 py-3 font-medium text-ink">{{ $row['nama'] }}</td>
                                <td class="px-4 py-3 text-ink">{{ $row['jenis'] }}</td>
                                <td class="px-4 py-3 text-muted">{{ \Carbon\Carbon::parse($row['mulai'])->translatedFormat('d M Y') }} — {{ \Carbon\Carbon::parse($row['selesai'])->translatedFormat('d M Y') }}</td>
                                <td class="px-4 py-3 text-ink">{{ $row['hari'] }} hari kerja</td>
                                <td class="px-4 py-3 font-semibold text-ink">{{ $row['status'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-muted">Tidak ada pengajuan cuti pada periode ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
