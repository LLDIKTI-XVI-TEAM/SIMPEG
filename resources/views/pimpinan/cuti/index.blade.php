<x-layouts.app title="Monitoring Cuti">
    @php
        $statusOptions = [
            'menunggu' => 'Menunggu Keputusan',
            'disetujui' => 'Disetujui',
            'perubahan' => 'Perubahan',
            'ditangguhkan' => 'Ditangguhkan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink">Monitoring Cuti</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                    ['label' => 'Monitoring Cuti'],
                ]" />
            </div>
            <x-ui.button href="{{ route('pimpinan.laporan.cuti') }}" variant="primary" size="md">Laporan Cuti</x-ui.button>
        </div>

        <form method="GET" action="{{ route('pimpinan.cuti.index') }}" id="filter-form" class="mb-6">
            <x-ui.filter-bar 
                searchId="search" 
                searchName="search" 
                :searchValue="request('search')"
                searchPlaceholder="Cari nama atau NIP..."
                gridClass="grid-cols-1 sm:grid-cols-2 lg:grid-cols-6"
            >
                <div>
                    <x-form.select id="status" name="status" size="md" onchange="this.form.submit()">
                        <option value="">Semua status</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div>
                    <x-form.select id="unit_kerja_id" name="unit_kerja_id" size="md" onchange="this.form.submit()">
                        <option value="">Semua unit</option>
                        @foreach ($unitKerjaOptions as $unit)
                            <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') == $unit->id)>{{ $unit->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div>
                    <x-form.select id="jenis_cuti_id" name="jenis_cuti_id" size="md" onchange="this.form.submit()">
                        <option value="">Semua jenis cuti</option>
                        @foreach ($jenisCutiOptions as $jenisCuti)
                            <option value="{{ $jenisCuti->id }}" @selected(request('jenis_cuti_id') == $jenisCuti->id)>{{ $jenisCuti->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div>
                    <x-form.select id="bulan" name="bulan" size="md" onchange="this.form.submit()">
                        <option value="">Semua bulan</option>
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((string) request('bulan') === (string) $month)>{{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div>
                    <label class="sr-only" for="tahun">Filter tahun</label>
                    <x-form.input id="tahun" name="tahun" :value="$filters['tahun'] ?? ''" type="number" min="2000" max="2100" size="md" placeholder="Tahun" onchange="this.form.submit()" />
                </div>
            </x-ui.filter-bar>
        </form>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <caption class="sr-only">Daftar pengajuan cuti pegawai</caption>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th padding="lg">Pelaksanaan</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status</x-ui.table-th>
                            <x-ui.table-th padding="lg">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse ($leaves as $leave)
                            @php
                                $status = match ($leave->status) {
                                    'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
                                    'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
                                    'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
                                    'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    default => ['label' => $leave->status, 'variant' => 'muted'],
                                };
                            @endphp
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td class="px-5 py-3">
                                    <span class="font-semibold text-ink">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</span>
                                    <span class="mt-1 block font-sans text-[10px] text-muted">NIP. {{ $leave->employee?->nip ?? '-' }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5 text-sm text-ink">{{ $leave->jenisCuti?->nama ?? '-' }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5 text-sm text-ink">
                                    {{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}<br>
                                    <span class="text-xs text-muted">s.d. {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5"><x-ui.badge :variant="$status['variant']" size="sm" dot>{{ $status['label'] }}</x-ui.badge></x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5 text-right"><a href="{{ route('pimpinan.cuti.show', $leave) }}" aria-label="Detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? $leave->id }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Detail</a></x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="5" class="px-4 py-10 text-center text-sm text-muted">Tidak ada pengajuan cuti yang sesuai dengan filter.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
            
            {{-- TABLE FOOTER --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <form method="GET" action="{{ route('pimpinan.cuti.index') }}" class="flex items-center gap-3 text-sm text-muted">
                    <input type="hidden" name="search" value="{{ request('search') }}">
                    <input type="hidden" name="status" value="{{ request('status') }}">
                    <input type="hidden" name="unit_kerja_id" value="{{ request('unit_kerja_id') }}">
                    <input type="hidden" name="jenis_cuti_id" value="{{ request('jenis_cuti_id') }}">
                    <input type="hidden" name="bulan" value="{{ request('bulan') }}">
                    <input type="hidden" name="tahun" value="{{ request('tahun') }}">
                    <span class="whitespace-nowrap">Tampilkan</span>
                    <select name="per_page" onchange="this.form.submit()" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        @foreach ([10, 25, 50, 100] as $optPerPage)
                            <option value="{{ $optPerPage }}" @selected((int) request('per_page', 10) === $optPerPage)>{{ $optPerPage }}</option>
                        @endforeach
                    </select>
                    <span class="hidden sm:inline">data</span>

                    {{-- Meta Info --}}
                    @if($leaves->total() > 0)
                        <div class="hidden md:block ml-2 border-l border-border pl-4">
                            Menampilkan <span class="font-medium text-ink">{{ $leaves->firstItem() }}</span>
                            - <span class="font-medium text-ink">{{ $leaves->lastItem() }}</span>
                            dari <span class="font-medium text-ink">{{ $leaves->total() }}</span>
                        </div>
                    @endif
                </form>

                <div class="w-full sm:w-auto flex justify-end">
                    {{ $leaves->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
