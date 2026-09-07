<x-layouts.app title="Persetujuan Cuti">
    @php
        $statusVariant = [
            'menunggu_approval' => 'info',
            'disetujui' => 'success',
            'ditangguhkan' => 'warning',
            'ditangguhkan_tugas_dinas' => 'warning',
            'dikembalikan_karena_rollover' => 'warning',
            'menunggu_pembatalan' => 'warning',
            'dibatalkan' => 'danger',
            'perlu_perubahan' => 'danger',
            'tidak_disetujui' => 'danger',
        ];
        
        $statusLabel = [
            'menunggu_approval' => 'Menunggu',
            'disetujui' => 'Disetujui',
            'ditangguhkan' => 'Ditangguhkan',
            'ditangguhkan_tugas_dinas' => 'Ditangguhkan karena Tugas Dinas',
            'dikembalikan_karena_rollover' => 'Dikembalikan karena Rollover',
            'menunggu_pembatalan' => 'Menunggu Keputusan Pembatalan',
            'dibatalkan' => 'Dibatalkan',
            'perlu_perubahan' => 'Perubahan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink">Persetujuan Cuti</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                    ['label' => 'Persetujuan Cuti'],
                ]" />
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('pimpinan.cuti.index') }}" variant="secondary" size="md">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Refresh
                </x-ui.button>
                <x-ui.button href="{{ route('cuti.laporan') }}" variant="secondary" size="md">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    Laporan Cuti
                </x-ui.button>
            </div>
        </div>

        {{-- STAT CARDS --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
            <x-ui.stat-card label="Menunggu Keputusan Anda" value="{{ $menungguTindakanSaya }}" variant="primary" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                </x-slot:icon>
                <x-slot:meta><span>Butuh diproses segera</span></x-slot:meta>
            </x-ui.stat-card>

            <x-ui.stat-card label="Menunggu Total" value="{{ $totalMenunggu }}" variant="warning" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </x-slot:icon>
                <x-slot:meta><span>Antrean di sistem</span></x-slot:meta>
            </x-ui.stat-card>

            <x-ui.stat-card label="Disetujui" value="{{ $totalDisetujui }}" variant="success" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </x-slot:icon>
                <x-slot:meta><span>Telah dikonfirmasi</span></x-slot:meta>
            </x-ui.stat-card>

            <x-ui.stat-card label="Ditangguhkan" value="{{ $totalDitangguhkan }}" variant="danger" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                </x-slot:icon>
                <x-slot:meta><span>Cuti ditunda</span></x-slot:meta>
            </x-ui.stat-card>
        </div>

        {{-- FILTER BAR --}}
        <form method="GET" action="{{ route('pimpinan.cuti.index') }}">
            <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
            <x-ui.filter-bar 
                searchId="search" 
                searchName="search" 
                :searchValue="request('search')"
                searchPlaceholder="Cari nama atau NIP"
                gridClass="grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5"
            >
                <div class="relative">
                    <x-form.select id="status" name="status" class="w-full" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="menunggu_saya" @selected(($filters['status'] ?? '') === 'menunggu_saya')>Menunggu Tindakan Saya</option>
                        <option value="menunggu" @selected(($filters['status'] ?? '') === 'menunggu')>Menunggu Keputusan</option>
                        <option value="menunggu_pembatalan" @selected(($filters['status'] ?? '') === 'menunggu_pembatalan')>Menunggu Keputusan Pembatalan</option>
                        <option value="disetujui" @selected(($filters['status'] ?? '') === 'disetujui')>Disetujui</option>
                        <option value="ditangguhkan" @selected(($filters['status'] ?? '') === 'ditangguhkan')>Ditangguhkan</option>
                        <option value="ditangguhkan_tugas_dinas" @selected(($filters['status'] ?? '') === 'ditangguhkan_tugas_dinas')>Ditangguhkan karena Tugas Dinas</option>
                        <option value="dikembalikan_karena_rollover" @selected(($filters['status'] ?? '') === 'dikembalikan_karena_rollover')>Dikembalikan karena Rollover</option>
                        <option value="dibatalkan" @selected(($filters['status'] ?? '') === 'dibatalkan')>Dibatalkan</option>
                        <option value="perubahan" @selected(($filters['status'] ?? '') === 'perubahan')>Perubahan</option>
                        <option value="tidak_disetujui" @selected(($filters['status'] ?? '') === 'tidak_disetujui')>Tidak Disetujui</option>
                    </x-form.select>
                </div>
                <div class="relative">
                    <x-form.select id="unit_kerja_id" name="unit_kerja_id" class="w-full" onchange="this.form.submit()">
                        <option value="">Semua unit</option>
                        @foreach ($unitKerjaOptions as $unit)
                            <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') == $unit->id)>{{ $unit->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div class="relative">
                    <x-form.select id="jenis_cuti_id" name="jenis_cuti_id" class="w-full" onchange="this.form.submit()">
                        <option value="">Semua jenis cuti</option>
                        @foreach ($jenisCutiOptions as $jenisCuti)
                            <option value="{{ $jenisCuti->id }}" @selected(request('jenis_cuti_id') == $jenisCuti->id)>{{ $jenisCuti->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div class="relative">
                    <x-form.select id="periode" name="periode" class="w-full" onchange="this.form.submit()">
                        <option value="">Semua Periode</option>
                        @foreach ($optPeriodes as $periodeOption)
                            <option value="{{ $periodeOption }}" @selected(($filters['periode'] ?? '') === $periodeOption)>{{ \Carbon\Carbon::createFromFormat('Y-m', $periodeOption)->translatedFormat('F Y') }}</option>
                        @endforeach
                    </x-form.select>
                </div>
            </x-ui.filter-bar>
        </form>

        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table id="cuti-table">
                    <caption class="sr-only">Daftar pengajuan cuti pegawai</caption>
                    <x-ui.table-head class="border-b border-border">
                        <x-ui.table-row>
                            <x-ui.table-th class="select-none">Pegawai</x-ui.table-th>
                            <x-ui.table-th class="select-none">Detail Cuti</x-ui.table-th>
                            <x-ui.table-th class="select-none">Pelaksanaan</x-ui.table-th>
                            <x-ui.table-th class="select-none">Tahap Aktif</x-ui.table-th>
                            <x-ui.table-th class="select-none">Status Akhir</x-ui.table-th>
                            <x-ui.table-th align="right" class="select-none">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse ($leaves as $leave)
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group" :interactive="true">
                                <x-ui.table-td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                            {{ strtoupper(substr($leave->employee?->nama_lengkap ?? 'A', 0, 1)) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-ink">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p>
                                            <p class="text-xs text-muted">{{ $leave->employee?->nip ?? '-' }}</p>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <p class="text-sm font-semibold text-ink font-sans">{{ $leave->jenisCuti?->nama ?? '-' }}</p>
                                    <p class="text-xs text-muted font-sans mt-0.5 max-w-xs truncate" title="{{ $leave->alasan }}">{{ $leave->alasan }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <p class="text-sm text-ink">{{ $leave->tanggal_mulai?->translatedFormat('d M') ?? '-' }} - {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p>
                                    <p class="text-xs text-primary font-semibold mt-0.5 leading-none">{{ $leave->jumlah_hari_kerja }} Hari Kerja</p>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <div class="text-[11px] font-medium text-ink font-sans">
                                        @if($leave->status === 'menunggu_approval' && $leave->current_step_label)
                                            <span>Menunggu <strong>{{ $leave->current_step_label }}</strong></span>
                                        @elseif($leave->current_step_label)
                                            <span>Langkah aktif: <strong>{{ $leave->current_step_label }}</strong></span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <x-ui.badge :variant="$statusVariant[$leave->status] ?? 'muted'" size="md" dot>
                                        {{ $statusLabel[$leave->status] ?? $leave->status }}
                                    </x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.button href="{{ route('pimpinan.cuti.show', $leave) }}" variant="secondary" size="icon" title="Detail" aria-label="Detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? 'Pegawai' }}">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="6" align="center" class="px-6 py-8 text-muted">Belum ada pengajuan cuti yang sesuai dengan filter.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
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
                    @if($leaves->total() > 0)
                    <p class="text-sm text-muted hidden sm:block">
                        Menampilkan <span class="font-semibold text-ink">{{ $leaves->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $leaves->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $leaves->total() }}</span> hasil
                    </p>
                    @endif
                </div>

                <div class="w-full sm:w-auto flex justify-end">
                    {{ $leaves->appends(request()->query())->links('vendor.pagination.simpeg') }}
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
