<x-layouts.app title="Cuti Pegawai">

    @php
        $statusVariant = [
            'menunggu'  => 'warning',
            'disetujui' => 'success',
            'ditunda'   => 'danger',
            'perlu_perubahan' => 'danger',
            'tidak_disetujui' => 'danger',
        ];

        $statusLabel = [
            'menunggu'  => 'Menunggu Keputusan',
            'disetujui' => 'Disetujui',
            'ditunda'   => 'Ditangguhkan',
            'perlu_perubahan' => 'Perubahan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];
    @endphp

    <div class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Monitoring Cuti Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Cuti']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                @can('cuti.create')
                <a href="{{ route('cuti.create') }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajukan Cuti Baru
                </a>
                @endcan
            </div>
        </div>

        {{-- METRICS SUMMARY CARD (GLOBAL MONITORING) --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
            {{-- Total Cuti Active --}}
            <x-ui.stat-card label="Total Staf Cuti" value="{{ $totalPengajuan }}" variant="primary" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Seluruh riwayat pengajuan</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- Approved --}}
            <x-ui.stat-card label="Disetujui (Bulan Ini)" value="{{ $jumlahDisetujui }}" variant="success" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Disetujui di periode berjalan</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- Pending --}}
            <x-ui.stat-card label="Menunggu Persetujuan" value="{{ $jumlahMenunggu }}" variant="warning" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Pengajuan butuh validasi</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- Postponed --}}
            <x-ui.stat-card label="Ditangguhkan" value="{{ $jumlahDitangguhkan }}" variant="danger" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Cuti ditunda</span>
                </x-slot:meta>
            </x-ui.stat-card>
        </div>


        <form method="GET" action="{{ route('cuti') }}">
            <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
        <x-ui.filter-bar
            searchId="search-cuti"
            searchName="search"
            searchValue="{{ $search }}"
            searchPlaceholder="Cari nama atau NIP..."
            class="sm:grid-cols-2 lg:grid-cols-5"
        >
            {{-- Filter Status --}}
            <div class="relative">
                <select id="filter-status" name="status" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Status</option>
                    <option value="menunggu" @selected($status === 'menunggu' || $status === 'pending')>Menunggu Keputusan</option>
                    <option value="disetujui" @selected($status === 'disetujui')>Disetujui</option>
                    <option value="ditunda" @selected($status === 'ditunda' || $status === 'ditangguhkan')>Ditangguhkan</option>
                    <option value="perlu_perubahan" @selected($status === 'perlu_perubahan')>Perubahan</option>
                    <option value="tidak_disetujui" @selected($status === 'tidak_disetujui')>Tidak Disetujui</option>
                </select>
            </div>


            {{-- Filter Jenis Cuti --}}
            <div class="relative">
                <select id="filter-jenis" name="jenis" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Jenis Cuti</option>
                    @foreach($optJenisCutis as $namaJenis)
                        <option value="{{ $namaJenis }}" @selected($jenis === $namaJenis)>{{ $namaJenis }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Unit Kerja --}}
            <div class="relative">
                <select id="filter-unit" name="unit" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Unit Kerja</option>
                    @foreach($optUnits as $namaUnit)
                        <option value="{{ $namaUnit }}" @selected($unit === $namaUnit)>{{ $namaUnit }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filter Periode Bulan --}}
            <div class="relative">
                <select id="filter-periode" name="periode" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Periode</option>
                    @foreach($optPeriodes as $periodeOption)
                        <option value="{{ $periodeOption }}" @selected($periode === $periodeOption)>{{ \Carbon\Carbon::createFromFormat('Y-m', $periodeOption)->translatedFormat('F Y') }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">Terapkan</button>
                <a href="{{ route('cuti') }}" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink shadow-sm transition hover:bg-soft">Reset</a>
            </div>
        </x-ui.filter-bar>
        </form>


        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden">

            <div class="overflow-x-auto">

                <x-ui.table id="cuti-table">
                    <x-ui.table-head class="border-b border-border">
                        <x-ui.table-row>
                            <x-ui.table-th class="select-none">Pegawai</x-ui.table-th>
                            <x-ui.table-th class="select-none">Unit Kerja</x-ui.table-th>
                            <x-ui.table-th class="select-none">Detail Cuti</x-ui.table-th>
                            <x-ui.table-th class="select-none">Tanggal & Durasi</x-ui.table-th>
                            <x-ui.table-th class="select-none">Stage Approval</x-ui.table-th>
                            <x-ui.table-th class="select-none">Status Akhir</x-ui.table-th>
                            <x-ui.table-th align="right" class="select-none">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @foreach($riwayatCuti as $r)
                        <x-ui.table-row data-nama="{{ $r['nama'] }}" data-nip="{{ $r['nip'] }}" data-unit="{{ $r['unit'] }}" data-jenis="{{ $r['jenis'] }}" data-status="{{ $r['status'] }}" data-periode="{{ $r['periode'] }}" :interactive="true">
                            <x-ui.table-td>

                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                        {{ strtoupper(substr($r['nama'], 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ $r['nama'] }}</p>
                                        <p class="text-xs text-muted">{{ $r['nip'] }}</p>
                                    </div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <span class="text-sm text-ink font-sans">{{ $r['unit'] }}</span>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <p class="text-sm font-semibold text-ink font-sans">{{ $r['jenis'] }}</p>
                                <p class="text-xs text-muted font-sans mt-0.5 max-w-xs truncate" title="{{ $r['alasan'] }}">{{ $r['alasan'] }}</p>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <p class="text-sm text-ink">{{ \Carbon\Carbon::parse($r['mulai'])->translatedFormat('d M') }} - {{ \Carbon\Carbon::parse($r['selesai'])->translatedFormat('d M Y') }}</p>

                                <p class="text-xs text-primary font-semibold mt-0.5 leading-none">{{ $r['hari'] }} Hari Kerja</p>
                            </x-ui.table-td>
                            <x-ui.table-td>

                                <div class="flex flex-col gap-1 text-[11px] font-medium text-ink font-sans">
                                    <div>
                                        <span>Atasan: <strong class="capitalize">{{ $r['stage_atasan'] }}</strong></span>
                                    </div>
                                    <div>
                                        <span>Kepala: <strong class="capitalize">{{ $r['stage_kepala'] }}</strong></span>
                                    </div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <x-ui.badge :variant="$statusVariant[$r['status']] ?? 'muted'" size="md" dot>
                                    {{ $statusLabel[$r['status']] }}
                                </x-ui.badge>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <div class="flex items-center justify-end gap-1.5">

                                    <x-ui.button href="{{ route('cuti.show', $r['id']) }}" variant="secondary" size="icon" title="Detail" aria-label="Detail">

                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </x-ui.button>
                                </div>
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @endforeach
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-muted">Tampilkan</span>
                        <select onchange="updatePerPage(this.value)" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                            <option value="10" {{ request('per_page', 10) == 10 ? 'selected' : '' }}>10</option>
                            <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                        </select>
                        <span class="text-sm text-muted">data per halaman</span>
                    </div>
                    @if($riwayatCuti->total() > 0)
                    <p class="text-sm text-muted hidden sm:block">
                        Menampilkan <span class="font-semibold text-ink">{{ $riwayatCuti->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $riwayatCuti->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $riwayatCuti->total() }}</span> hasil
                    </p>
                    @endif
                </div>

                <div class="w-full sm:w-auto">
                    {{ $riwayatCuti->onEachSide(1)->links('vendor.pagination.simpeg') }}

                </div>
            </div>
        </x-ui.card>

    </div>

    @push('scripts')
    <script>
    function updatePerPage(val) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', val);
        url.searchParams.delete('page');
        window.location.assign(url.href);
    }
    </script>
    @endpush

</x-layouts.app>
