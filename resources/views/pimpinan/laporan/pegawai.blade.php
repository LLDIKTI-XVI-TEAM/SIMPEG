<x-layouts.app title="Laporan Pegawai">
    @php
        $selectedColumns = old('columns', $selectedColumns ?? ['nip', 'nama', 'status', 'jabatan', 'unit', 'golongan', 'jenis_pegawai']);
        $availableColumns = [
            'nip' => 'NIP',
            'nama' => 'Nama Pegawai',
            'status' => 'Status Pegawai',
            'jabatan' => 'Jabatan',
            'unit' => 'Unit/Tim Kerja',
            'golongan' => 'Golongan',
            'jenis_pegawai' => 'Jenis Pegawai',
            'pendidikan' => 'Pendidikan Terakhir',
            'tanggal_pensiun' => 'Tanggal Pensiun',
        ];
    @endphp

    <div class="space-y-6">
        <div class="mb-6">
            <h2 class="text-2xl font-semibold text-ink">Laporan Data Pegawai</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Laporan', 'url' => route('pimpinan.laporan.index')],
                ['label' => 'Data Pegawai']
            ]" />
        </div>

        <x-ui.card>
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-ink">Export Nominatif Custom</h3>
                <p id="employee-report-help" class="mt-1 text-sm text-muted">Pilih kolom dan filter. Laporan custom tersedia dalam Excel (.xlsx) saja.</p>
            </div>

            <form action="{{ route('pimpinan.laporan.pegawai') }}" method="GET" class="space-y-6" aria-describedby="employee-report-help">
                <fieldset>
                    <legend class="text-sm font-semibold text-ink">Kolom laporan</legend>
                    <p id="employee-column-help" class="mt-1 text-xs text-muted">Kolom sensitif seperti NIK dan nomor KK tidak tersedia dalam laporan.</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($availableColumns as $column => $label)
                            <label for="column-{{ $column }}" class="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink">
                                <input id="column-{{ $column }}" name="columns[]" value="{{ $column }}" type="checkbox" @checked(in_array($column, $selectedColumns, true)) aria-describedby="employee-column-help" class="rounded border-border text-primary focus:ring-primary">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('columns')
                        <p id="columns-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                    @enderror
                </fieldset>

                <fieldset>
                    <legend class="text-sm font-semibold text-ink">Filter baris</legend>
                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label for="status_pegawai_id" class="mb-2 block text-sm font-semibold text-ink">Status Pegawai</label>
                            <x-form.select id="status_pegawai_id" name="status_pegawai_id" aria-describedby="employee-report-help" size="md">
                                <option value="">Semua Status</option>
                                @foreach($filterOptions['statuses'] as $status)
                                    <option value="{{ $status->id }}" @selected(($filters['status_pegawai_id'] ?? '') === $status->id)>{{ $status->nama }}</option>
                                @endforeach
                            </x-form.select>
                        </div>
                        <div>
                            <label for="unit_kerja_id" class="mb-2 block text-sm font-semibold text-ink">Unit/Tim Kerja</label>
                            <x-form.select id="unit_kerja_id" name="unit_kerja_id" aria-describedby="employee-report-help" size="md">
                                <option value="">Semua Unit/Tim</option>
                                @foreach($filterOptions['units'] as $unit)
                                    <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') === $unit->id)>{{ $unit->nama }}</option>
                                @endforeach
                            </x-form.select>
                        </div>
                        <div>
                            <label for="jenis_pegawai_id" class="mb-2 block text-sm font-semibold text-ink">Jenis Pegawai</label>
                            <x-form.select id="jenis_pegawai_id" name="jenis_pegawai_id" aria-describedby="employee-report-help" size="md">
                                <option value="">Semua Jenis</option>
                                @foreach($filterOptions['employeeTypes'] as $employeeType)
                                    <option value="{{ $employeeType->id }}" @selected(($filters['jenis_pegawai_id'] ?? '') === $employeeType->id)>{{ $employeeType->nama }}</option>
                                @endforeach
                            </x-form.select>
                        </div>
                        <div>
                            <label for="golongan" class="mb-2 block text-sm font-semibold text-ink">Golongan</label>
                            <x-form.select id="golongan" name="golongan" aria-describedby="employee-report-help" size="md">
                                <option value="">Semua Golongan</option>
                                @foreach($filterOptions['ranks'] as $rank)
                                    <option value="{{ strtok($rank, '/') }}" @selected(($filters['golongan'] ?? '') === strtok($rank, '/'))>{{ $rank }}</option>
                                @endforeach
                            </x-form.select>
                        </div>
                        <div>
                            <label for="jabatan" class="mb-2 block text-sm font-semibold text-ink">Jabatan</label>
                            <x-form.select id="jabatan" name="jabatan" aria-describedby="employee-report-help" size="md">
                                <option value="">Semua Jabatan</option>
                                @foreach($filterOptions['positions'] as $position)
                                    <option value="{{ $position }}" @selected(($filters['jabatan'] ?? '') === $position)>{{ $position }}</option>
                                @endforeach
                            </x-form.select>
                        </div>
                        <div>
                            <label for="pensiun_dari" class="mb-2 block text-sm font-semibold text-ink">Pensiun dari</label>
                            <x-form.input id="pensiun_dari" name="pensiun_dari" :value="$filters['pensiun_dari'] ?? ''" type="date" aria-describedby="employee-report-help" size="md" />
                        </div>
                        <div>
                            <label for="pensiun_sampai" class="mb-2 block text-sm font-semibold text-ink">Pensiun sampai</label>
                            <x-form.input id="pensiun_sampai" name="pensiun_sampai" :value="$filters['pensiun_sampai'] ?? ''" type="date" aria-describedby="employee-report-help pensiun-error" size="md" />
                            @error('pensiun_sampai')
                                <p id="pensiun-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </fieldset>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="submit" variant="secondary" size="md">Terapkan Filter</x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="md" formaction="{{ route('pimpinan.laporan.pegawai.custom') }}" formtarget="_blank">Unduh Excel (.xlsx)</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-ink">Preview Data</h3>
                <p class="text-sm text-muted">Menampilkan maksimal 10 data sesuai filter aktif.</p>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <caption class="sr-only">Preview data pegawai sesuai filter laporan aktif</caption>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            @foreach($selectedColumns as $column)
                                <x-ui.table-th padding="lg">{{ $availableColumns[$column] ?? $column }}</x-ui.table-th>
                            @endforeach
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($previewData as $row)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50">
                                @foreach($selectedColumns as $column)
                                    <x-ui.table-td class="px-4 py-3 {{ $column === 'nama' ? 'font-medium text-ink' : 'text-ink' }}">
                                        {{ $row[$column] ?? '-' }}
                                    </x-ui.table-td>
                                @endforeach
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="{{ count($selectedColumns) }}" class="px-4 py-8 text-center text-muted">Tidak ada pegawai yang sesuai dengan filter.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
