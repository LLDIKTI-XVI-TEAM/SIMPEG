@php
    $reportFilters = array_filter([
        'periode' => $periode,
        'unit' => $unit,
        'pegawai' => $pegawaiId,
        'jenis' => $jenisId,
    ]);
@endphp

<x-layouts.app title="Laporan Cuti">
    <div class="space-y-6">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Laporan Cuti Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Laporan Cuti Pegawai'],
                ]" />
            </div>
            <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto sm:justify-end">
                @if(Route::has('cuti.laporan.pdf'))
                    <x-ui.button
                        as="a"
                        :href="route('cuti.laporan.pdf', $reportFilters)"
                        variant="primary"
                        size="md"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                        Unduh PDF
                    </x-ui.button>
                @endif
                @if(Route::has('cuti.laporan.excel'))
                    <x-ui.button
                        as="a"
                        :href="route('cuti.laporan.excel', $reportFilters)"
                        variant="primary"
                        size="md"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        Unduh Excel
                    </x-ui.button>
                @endif
            </div>
        </div>

        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-label="Filter laporan cuti">
            <form id="laporan-filter" method="GET" action="{{ route('cuti.laporan') }}" class="space-y-5">
                <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
                <x-cuti.period-filter :period="$periode" id-prefix="laporan" />

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="space-y-1">
                        <label for="laporan-unit" class="text-xs font-bold uppercase tracking-wider text-ink">Unit Kerja</label>
                        <select id="laporan-unit" name="unit" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua unit kerja</option>
                            @foreach($unitOptions as $option)
                                <option value="{{ $option['id'] }}" @selected($unit === $option['id'])>{{ $option['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label for="laporan-jenis" class="text-xs font-bold uppercase tracking-wider text-ink">Jenis Cuti</label>
                        <select id="laporan-jenis" name="jenis" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua jenis cuti</option>
                            @foreach($jenisOptions as $option)
                                <option value="{{ $option['id'] }}" @selected($jenisId === $option['id'])>{{ $option['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-cuti.employee-combobox
                        id="laporan-pegawai"
                        :action="route('cuti.laporan')"
                        name="pegawai"
                        :selected-id="$pegawaiId"
                        :selected-label="$selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : null"
                        label="Pegawai"
                        help="Ketik minimal 2 karakter lalu pilih pegawai dari hasil pencarian."
                        :embedded="true"
                        :auto-submit="false"
                    />
                </div>

                <div class="flex flex-wrap gap-2 border-t border-border pt-4">
                    <x-ui.button type="submit">Terapkan Filter</x-ui.button>
                    <x-ui.button href="{{ route('cuti.laporan') }}" variant="secondary" data-filter-reset>Reset</x-ui.button>
                </div>
            </form>
        </section>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th class="w-12">No</x-ui.table-th>
                            <x-ui.table-th>NIP</x-ui.table-th>
                            <x-ui.table-th>Nama</x-ui.table-th>
                            <x-ui.table-th>Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th>Tanggal Mulai</x-ui.table-th>
                            <x-ui.table-th>Tanggal Selesai</x-ui.table-th>
                            <x-ui.table-th align="right">Hari Kerja</x-ui.table-th>
                            <x-ui.table-th>Sumber</x-ui.table-th>
                            <x-ui.table-th>Status</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($rows as $row)
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $rows->firstItem() + $loop->index }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="font-mono text-sm text-ink whitespace-nowrap">{{ $row->nip }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-sm font-semibold text-ink">{{ $row->nama }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-sm text-ink">{{ $row->jenis }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-sm text-muted whitespace-nowrap">{{ $row->tanggalMulai->format('d-m-Y') }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-sm text-muted whitespace-nowrap">{{ $row->tanggalSelesai->format('d-m-Y') }}</x-ui.table-td>
                                <x-ui.table-td align="right" padding="sm" class="font-mono text-sm text-ink">{{ $row->hari }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-sm text-ink">{{ $row->sourceLabel }}</x-ui.table-td>
                                <x-ui.table-td padding="sm" class="text-sm text-ink whitespace-nowrap">{{ $row->statusLabel }}</x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="9" align="center" class="px-5 py-10 text-muted">
                                    Tidak ada data cuti sesuai filter.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <div class="flex items-center gap-3 text-sm text-muted">
                    <form method="GET" action="{{ route('cuti.laporan') }}" class="flex items-center gap-2">
                        @if($periode)
                            <input type="hidden" name="periode" value="{{ $periode }}">
                        @endif
                        @if($unit)
                            <input type="hidden" name="unit" value="{{ $unit }}">
                        @endif
                        @if($jenisId)
                            <input type="hidden" name="jenis" value="{{ $jenisId }}">
                        @endif
                        @if($pegawaiId)
                            <input type="hidden" name="pegawai" value="{{ $pegawaiId }}">
                        @endif

                        <span class="whitespace-nowrap">Tampilkan</span>
                        <label for="per_page" class="sr-only">Jumlah baris per halaman</label>
                        <select
                            id="per_page"
                            name="per_page"
                            onchange="this.form.submit()"
                            class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center"
                        >
                            @foreach ([10, 25, 50] as $opsi)
                                <option value="{{ $opsi }}" @selected((int) request('per_page', 10) === $opsi)>{{ $opsi }}</option>
                            @endforeach
                        </select>
                        <span class="hidden sm:inline">data</span>
                    </form>

                    {{-- Meta Info --}}
                    <div class="hidden md:block ml-2 border-l border-border pl-4">
                        Menampilkan <span class="font-medium text-ink">{{ $rows->firstItem() ?? 0 }}</span>
                        - <span class="font-medium text-ink">{{ $rows->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium text-ink">{{ $rows->total() }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $rows->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
