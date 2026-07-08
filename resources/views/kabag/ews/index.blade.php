<x-layouts.app title="EWS Bawahan" subtitle="Pemantauan Early Warning System (EWS) khusus bawahan langsung Anda.">

    @php
        // DUMMY DATA UNTUK UI
        $listEws = [
            [
                'id' => '9b6574f2-959c-4876-880f-90e822e11fa3', 
                'nama' => 'Budi Santoso', 
                'nip' => '199012345678910000',
                'event' => 'Kenaikan Gaji Berkala (KGB)', 
                'tanggal_target' => '2026-08-01',
                'sisa_hari' => 24, 
                'urgency' => 'danger', 
                'eligibility' => 'Eligible',
                'status' => 'Belum Ditangani'
            ],
            [
                'id' => '9b6574f2-959c-4876-880f-90e822e11fa2', 
                'nama' => 'Siti Rahayu', 
                'nip' => '198512345678910000',
                'event' => 'Masa Berlaku SK Pangkat', 
                'tanggal_target' => '2026-09-15',
                'sisa_hari' => 69, 
                'urgency' => 'warning', 
                'eligibility' => 'Eligible',
                'status' => 'Diproses'
            ],
            [
                'id' => '9b6574f2-959c-4876-880f-90e822e11fa1', 
                'nama' => 'Ahmad Fauzi', 
                'nip' => '198123456789100000',
                'event' => 'Batas Usia Pensiun (BUP)', 
                'tanggal_target' => '2027-01-01',
                'sisa_hari' => 177, 
                'urgency' => 'info', 
                'eligibility' => 'Eligible',
                'status' => 'Belum Waktunya'
            ],
            [
                'id' => '9b6574f2-959c-4876-880f-90e822e11fa4', 
                'nama' => 'Dewi Pertiwi', 
                'nip' => '737741487614535936',
                'event' => 'Perpanjangan Kontrak PPPK', 
                'tanggal_target' => '2026-12-31',
                'sisa_hari' => 176, 
                'urgency' => 'info', 
                'eligibility' => 'Evaluasi',
                'status' => 'Belum Waktunya'
            ],
        ];

        $urgencyClasses = [
            'danger' => 'bg-danger/10 text-danger',
            'warning' => 'bg-warning/10 text-warning',
            'info' => 'bg-info/10 text-info',
            'success' => 'bg-success/10 text-success',
        ];
    @endphp

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">EWS Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'EWS Bawahan']
            ]" />
        </div>
    </div>

    {{-- FILTER BAR --}}
    <form id="filter-form" method="GET" action="{{ route('kabag.ews.index') }}">
        <x-ui.filter-bar 
            searchId="search-input"
            searchName="search"
            searchValue=""
            searchPlaceholder="Cari nama atau NIP..." 
            class="lg:grid-cols-4"
        >
            {{-- Filter Urgency --}}
            <div class="relative">
                <select id="filter-urgency" name="urgency" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Urgency</option>
                    <option value="danger">Danger (H-30)</option>
                    <option value="warning">Warning (H-60)</option>
                    <option value="info">Info (H-90)</option>
                </select>
            </div>

            {{-- Filter Event --}}
            <div class="relative">
                <select id="filter-event" name="event" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Event</option>
                    <option value="kgb">Kenaikan Gaji Berkala (KGB)</option>
                    <option value="pangkat">Kenaikan Pangkat</option>
                    <option value="bup">Batas Usia Pensiun (BUP)</option>
                </select>
            </div>
            
            {{-- Filter Status --}}
            <div class="relative">
                <select id="filter-status" name="status" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Status</option>
                    <option value="belum">Belum Ditangani</option>
                    <option value="proses">Diproses</option>
                    <option value="selesai">Selesai</option>
                </select>
            </div>
        </x-ui.filter-bar>
    </form>

    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">PEGAWAI</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">EVENT</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">TANGGAL TARGET</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">URGENCY</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">STATUS / ELIGIBILITY</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold text-center">AKSI</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse($listEws as $ews)
                    <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                        <x-ui.table-td padding="comfortable">
                            <div class="flex flex-col min-w-[150px]">
                                <a href="{{ route('pegawai.show', ['id' => $ews['id']]) }}" class="text-sm font-semibold text-ink hover:text-primary transition-colors line-clamp-1">
                                    {{ $ews['nama'] }}
                                </a>
                                <span class="text-xs text-muted font-mono mt-0.5">{{ $ews['nip'] }}</span>
                            </div>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <span class="text-sm font-medium text-ink">{{ $ews['event'] }}</span>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <span class="text-sm font-medium text-ink">{{ \Carbon\Carbon::parse($ews['tanggal_target'])->translatedFormat('d F Y') }}</span>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $urgencyClasses[$ews['urgency']] }}">
                                    {{ $ews['sisa_hari'] }} Hari
                                </span>
                            </div>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <div class="flex flex-col gap-1 items-start">
                                <span class="text-sm font-medium text-ink">{{ $ews['status'] }}</span>
                                <span class="text-[11px] text-muted">{{ $ews['eligibility'] }}</span>
                            </div>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable" class="text-center">
                            <x-ui.button as="a" href="{{ route('pegawai.show', ['id' => $ews['id']]) }}" variant="ghost" size="icon" title="Lihat Profil Pegawai" tooltip-position="left">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                            </x-ui.button>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @empty
                    <x-ui.table-row>
                        <x-ui.table-td colspan="6" class="py-8">
                            <x-ui.empty-state 
                                icon="exclamation-triangle" 
                                title="Tidak Ada EWS" 
                                description="Bawahan Anda saat ini tidak memiliki peringatan sistem."
                            />
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        {{-- Pagination Mock --}}
        <div class="border-t border-border px-4 py-4 sm:px-6" x-data="{ currentPage: 1, totalPages: 1 }">
            <x-ui.pagination />
        </div>
    </x-ui.card>
</x-layouts.app>
