<x-layouts.app title="Rekap Cuti">
    @php
        $statusClass = [
            'Aman' => 'text-success',
            'Perhatian' => 'text-warning',
            'Kritis' => 'text-danger',
            'Menunggu' => 'text-warning',
            'menunggu_approval' => 'text-warning',
            'disetujui' => 'text-success',
            'ditangguhkan' => 'text-warning',
            'ditangguhkan_tugas_dinas' => 'text-warning',
            'dikembalikan_karena_rollover' => 'text-warning',
            'perlu_perubahan' => 'text-danger',
            'tidak_disetujui' => 'text-danger',
        ];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Rekap Cuti Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Cuti', 'url' => route('cuti')],
                    ['label' => 'Rekap Cuti']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('cuti.laporan', array_filter(['periode' => $periode, 'unit' => $unit, 'pegawai' => $pegawaiId, 'jenis' => $jenisId])) }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    Buka Laporan & Export
                </a>
            </div>
        </div>

        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-label="Filter rekap cuti">
            <form id="rekap-filter" method="GET" action="{{ route('cuti.rekap') }}" class="space-y-5">
                <x-cuti.period-filter :period="$periode" id-prefix="rekap" />

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="space-y-1">
                        <label for="rekap-unit" class="text-xs font-bold uppercase tracking-wider text-ink">Unit Kerja</label>
                        <select id="rekap-unit" name="unit" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua unit kerja</option>
                            @foreach($unitOptions as $option)
                                <option value="{{ $option['id'] }}" @selected($unit === $option['id'])>{{ $option['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label for="rekap-jenis" class="text-xs font-bold uppercase tracking-wider text-ink">Jenis Cuti</label>
                        <select id="rekap-jenis" name="jenis" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua jenis cuti</option>
                            @foreach($jenisOptions as $option)
                                <option value="{{ $option['id'] }}" @selected($jenisId === $option['id'])>{{ $option['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-cuti.employee-combobox
                        id="rekap-pegawai"
                        :action="route('cuti.rekap')"
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
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button>
                    <a data-filter-reset href="{{ route('cuti.rekap') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-border px-5 py-2 text-sm font-semibold text-ink hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Reset</a>
                </div>
            </form>
        </section>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            @foreach($summary as $card)
                <x-ui.stat-card label="{{ $card['label'] }}" value="{{ $card['value'] }}" variant="{{ $card['tone'] }}" size="lg" accent>
                    <x-slot:icon>
                        @if($card['tone'] === 'primary')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        @elseif($card['tone'] === 'success')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        @elseif($card['tone'] === 'warning')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        @elseif($card['tone'] === 'danger')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                        @else
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                        @endif
                    </x-slot:icon>
                    <x-slot:meta>
                        <span>{{ $card['caption'] }}</span>
                    </x-slot:meta>
                </x-ui.stat-card>
            @endforeach
        </div>

        <div class="flex flex-col gap-4">
            <div class="space-y-4">
                <x-ui.card padding="none" class="overflow-hidden">
                    <div class="border-b border-border px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink">Rekap Saldo Per Pegawai</h3>
                    </div>
                    <div class="overflow-x-auto">

                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>
                                        Pegawai</x-ui.table-th>
                                    <x-ui.table-th>
                                        Unit</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Jatah</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Carry</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Terpakai</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Sisa</x-ui.table-th>
                                    <x-ui.table-th>
                                        Status</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Aksi</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($leaveBalances as $row)
                                    <x-ui.table-row :interactive="true">
                                        <x-ui.table-td padding="sm">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="text-xs text-muted">{{ $row['nip'] }}</p>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row['unit'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['jatah'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['carry'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['terpakai'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm font-bold text-primary">
                                            {{ $row['sisa'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] ?? 'text-muted' }}">{{ $row['status_label'] }}</span>
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm">
                                            @if($canAdministerBalance)
                                                <a href="{{ route('cuti.saldo.administrasi', array_filter(['pegawai' => $row['employee_id'], 'periode' => $row['tahun']])) }}"
                                                    class="text-xs font-semibold text-primary hover:underline">Administrasi Pemakaian</a>
                                            @endif
                                        </x-ui.table-td>
                                    </x-ui.table-row>

                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                    {{-- TABLE FOOTER --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                        <div>
                            @if($leaveBalances->total() > 0)
                            <p class="text-sm text-muted">
                                Menampilkan <span class="font-semibold text-ink">{{ $leaveBalances->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $leaveBalances->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $leaveBalances->total() }}</span> hasil
                            </p>
                            @endif
                        </div>
                        <div class="w-full sm:w-auto">
                            {{ $leaveBalances->onEachSide(1)->links('vendor.pagination.simpeg') }}
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card padding="none" class="overflow-hidden">
                    <div class="border-b border-border px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink">Detail Penggunaan Cuti</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>
                                        No</x-ui.table-th>
                                    <x-ui.table-th>
                                        Pegawai</x-ui.table-th>
                                    <x-ui.table-th>
                                        Jenis Cuti</x-ui.table-th>
                                    <x-ui.table-th>
                                        Tanggal Mulai</x-ui.table-th>
                                    <x-ui.table-th>
                                        Tanggal Selesai</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Hari</x-ui.table-th>
                                    <x-ui.table-th>
                                        Sumber</x-ui.table-th>
                                    <x-ui.table-th>
                                        Status</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($usageRows as $row)
                                    <x-ui.table-row :interactive="true">
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $loop->iteration }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <p class="text-sm font-semibold text-ink">{{ $row->nama }}</p>
                                            <p class="text-xs text-muted">{{ $row->nip }}</p>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row->jenis }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $row->tanggalMulai->translatedFormat('d M Y') }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $row->tanggalSelesai->translatedFormat('d M Y') }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row->hari }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row->sourceLabel }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row->status] ?? 'text-muted' }}">{{ $row->statusLabel }}</span>
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                    {{-- TABLE FOOTER --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                        <div>
                            @if($usageRows->total() > 0)
                            <p class="text-sm text-muted">
                                Menampilkan <span class="font-semibold text-ink">{{ $usageRows->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $usageRows->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $usageRows->total() }}</span> hasil
                            </p>
                            @endif
                        </div>
                        <div class="w-full sm:w-auto">
                            {{ $usageRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                        </div>
                    </div>
                </x-ui.card>
            </div>

        </div>

    </div>
</x-layouts.app>
