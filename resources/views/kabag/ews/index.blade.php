<x-layouts.app title="EWS Bawahan" subtitle="Pemantauan Early Warning System (EWS) khusus bawahan langsung Anda.">

    @php
        // DUMMY DATA UNTUK UI
        $listEws = [
            [
                'id' => 1,
                'pegawai_id' => '9b6574f2-959c-4876-880f-90e822e11fa3',
                'nama' => 'Budi Santoso', 
                'nip' => '199012345678910000',
                'jenis_event' => 'KGB', 
                'tanggal_target' => '2026-08-01',
                'sisa_hari' => 24, 
                'threshold_label' => 'H-30',
                'threshold_schedule' => ['H-14'],
                'is_eligible' => true,
                'eligibility_reason' => 'Memenuhi syarat',
                'eligibility_checks' => [
                    ['label' => 'Kinerja Minimal Baik', 'passed' => true],
                    ['label' => 'Masa Kerja 2 Tahun', 'passed' => true]
                ],
                'status_tindak_lanjut' => 'Ditangani'
            ],
            [
                'id' => 2,
                'pegawai_id' => '9b6574f2-959c-4876-880f-90e822e11fa2',
                'nama' => 'Siti Rahayu', 
                'nip' => '198512345678910000',
                'jenis_event' => 'Kenaikan Pangkat', 
                'tanggal_target' => '2026-09-15',
                'sisa_hari' => 69, 
                'threshold_label' => 'H-90',
                'threshold_schedule' => ['H-60', 'H-30'],
                'is_eligible' => false,
                'eligibility_reason' => 'Ujian Dinas Belum Selesai',
                'eligibility_checks' => [
                    ['label' => 'Kinerja Minimal Baik', 'passed' => true],
                    ['label' => 'Ujian Dinas', 'passed' => false]
                ],
                'status_tindak_lanjut' => 'Aktif'
            ],
            [
                'id' => 3,
                'pegawai_id' => '9b6574f2-959c-4876-880f-90e822e11fa1',
                'nama' => 'Ahmad Fauzi', 
                'nip' => '198123456789100000',
                'jenis_event' => 'Pensiun', 
                'tanggal_target' => '2027-01-01',
                'sisa_hari' => 177, 
                'threshold_label' => 'H-6 bulan',
                'threshold_schedule' => ['H-3 bulan'],
                'is_eligible' => true,
                'eligibility_reason' => 'Perlu tindak lanjut',
                'eligibility_checks' => [],
                'status_tindak_lanjut' => 'Aktif'
            ],
            [
                'id' => 4,
                'pegawai_id' => '9b6574f2-959c-4876-880f-90e822e11fa4',
                'nama' => 'Dewi Pertiwi', 
                'nip' => '737741487614535936',
                'jenis_event' => 'Kontrak PPPK', 
                'tanggal_target' => '2026-12-31',
                'sisa_hari' => 176, 
                'threshold_label' => 'H-6 bulan',
                'threshold_schedule' => ['H-3 bulan', 'H-1 bulan'],
                'is_eligible' => true,
                'eligibility_reason' => 'Perlu tindak lanjut',
                'eligibility_checks' => [],
                'status_tindak_lanjut' => 'Tidak Perlu'
            ],
            [
                'id' => 5,
                'pegawai_id' => '9b6574f2-959c-4876-880f-90e822e11fa5',
                'nama' => 'Rudi Hermawan', 
                'nip' => '198812345678910000',
                'jenis_event' => 'Satyalancana', 
                'tanggal_target' => '2026-08-03',
                'sisa_hari' => 25, 
                'threshold_label' => 'H-30',
                'threshold_schedule' => ['H-14'],
                'is_eligible' => true,
                'eligibility_reason' => '10 Tahun Mengabdi',
                'eligibility_checks' => [
                    ['label' => 'Masa Kerja 10 Tahun', 'passed' => true]
                ],
                'status_tindak_lanjut' => 'Aktif'
            ],
        ];
        $filterEvent = request('event', '');
        
        if ($filterEvent !== '') {
            $listEws = array_filter($listEws, fn($a) => $a['jenis_event'] === $filterEvent);
        }
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

    {{-- SUMMARY CARDS --}}
    {{-- ================================================================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        @php
            $countMerah = collect($listEws)->filter(fn($a) => $a['sisa_hari'] < 30)->count();
            $countKuning = collect($listEws)->filter(fn($a) => $a['sisa_hari'] >= 30 && $a['sisa_hari'] <= 90)->count();
            $countHijau = collect($listEws)->filter(fn($a) => $a['sisa_hari'] > 90)->count();
            $countTotal = count($listEws);
        @endphp
        
        <x-ui.card class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-soft text-ink font-bold text-lg">
                {{ $countTotal }}
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Total Peringatan</p>
                <h3 class="text-base font-bold text-ink">{{ $countTotal }} Kasus Aktif</h3>
            </div>
        </x-ui.card>

        <x-ui.card class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-danger/10 text-danger font-bold text-lg">
                {{ $countMerah }}
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Sangat Mendesak</p>
                <h3 class="text-base font-bold text-danger">{{ $countMerah }} (&lt; 30 Hari)</h3>
            </div>
        </x-ui.card>

        <x-ui.card class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning font-bold text-lg">
                {{ $countKuning }}
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Perlu Perhatian</p>
                <h3 class="text-base font-bold text-warning">{{ $countKuning }} (30-90 Hari)</h3>
            </div>
        </x-ui.card>

        <x-ui.card class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-success/10 text-success font-bold text-lg">
                {{ $countHijau }}
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Pemantauan Rutin</p>
                <h3 class="text-base font-bold text-success">{{ $countHijau }} (&gt; 90 Hari)</h3>
            </div>
        </x-ui.card>
    </div>

    {{-- FILTER & SEARCH AREA --}}
    {{-- ================================================================ --}}
    <div x-data="{ search: '' }">
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-border bg-soft/30 px-6 py-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                
                {{-- Event Filter Links --}}
                <div class="flex flex-wrap gap-1 bg-soft p-1 rounded-lg">
                    <a href="{{ route('kepala-bagian.ews.index') }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === '' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Semua
                    </a>
                    <a href="{{ route('kepala-bagian.ews.index', ['event' => 'Kenaikan Pangkat']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Kenaikan Pangkat' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Kenaikan Pangkat
                    </a>
                    <a href="{{ route('kepala-bagian.ews.index', ['event' => 'KGB']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'KGB' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        KGB
                    </a>
                    <a href="{{ route('kepala-bagian.ews.index', ['event' => 'Pensiun']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Pensiun' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Pensiun
                    </a>
                    <a href="{{ route('kepala-bagian.ews.index', ['event' => 'Kontrak PPPK']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Kontrak PPPK' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Kontrak PPPK
                    </a>
                    <a href="{{ route('kepala-bagian.ews.index', ['event' => 'Satyalancana']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Satyalancana' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Satyalancana
                    </a>
                </div>

                {{-- Search Box --}}
                <div class="relative w-full sm:w-72">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.603 10.603Z" />
                        </svg>
                    </span>
                    <input 
                        type="text" 
                        x-model="search"
                        placeholder="Cari nama atau NIP..." 
                        class="w-full rounded-lg border border-border bg-surface py-2 pl-9 pr-4 text-sm text-ink placeholder-muted shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    />
                </div>
            </div>

            {{-- TABLE --}}
            {{-- ================================================================ --}}
            <div class="overflow-x-auto">
                <x-ui.table class="min-w-[1040px] table-fixed">
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th align="center" padding="wide" class="w-14">NO</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[260px]">PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[260px]">EVENT & AMBANG</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[150px]">TARGET</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[140px]">URGENSI</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[160px]">STATUS TINDAKAN</x-ui.table-th>
                            <x-ui.table-th padding="wide">ELIGIBILITY</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($listEws as $index => $alert)
                            @php
                                $sisaBadgeClass = '';
                                if ($alert['sisa_hari'] < 30) {
                                    $sisaBadgeClass = 'text-danger';
                                } elseif ($alert['sisa_hari'] <= 90) {
                                    $sisaBadgeClass = 'text-warning';
                                } else {
                                    $sisaBadgeClass = 'text-success';
                                }
                            @endphp
                            <x-ui.table-row x-show="search === '' || '{{ strtolower($alert['nama']) }}'.includes(search.toLowerCase()) || '{{ str_replace(' ', '', $alert['nip']) }}'.includes(search.replace(/\s+/g, ''))" class="align-middle hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td align="center" padding="lg" class="font-mono text-sm font-semibold text-muted">{{ $index + 1 }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="w-full min-w-0">
                                        <x-ui.tooltip text="Buka detail {{ $alert['nama'] }}" position="right">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="block truncate text-sm font-semibold text-ink transition-colors hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 rounded leading-tight">{{ $alert['nama'] }}</a>
                                        </x-ui.tooltip>
                                        <p class="text-[11px] text-muted font-sans leading-none mt-1 font-mono">NIP. {{ $alert['nip'] }}</p>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="space-y-1.5">
                                        <x-ui.badge variant="ink" size="md" :pill="false">
                                            @if($alert['jenis_event'] === 'Kenaikan Pangkat')
                                                <svg class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                                                </svg>
                                            @elseif($alert['jenis_event'] === 'KGB')
                                                <svg class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5h16.5M5.25 7.5h13.5m-12 3h10.5m-9 3h7.5m-6 3h4.5m-3.75 3h3" />
                                                </svg>
                                            @elseif($alert['jenis_event'] === 'Pensiun')
                                                <svg class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M12 13.489v6.527c0 1.229-.926 2.274-2.14 2.417a4.347 4.347 0 0 1-2.911-1.013L6.47 20.25a2.247 2.247 0 0 1-.72-1.667v-5.094" />
                                                </svg>
                                            @elseif($alert['jenis_event'] === 'Satyalancana')
                                                <svg class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5z" />
                                                </svg>
                                            @else
                                                <svg class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                                </svg>
                                            @endif
                                            {{ $alert['jenis_event'] }}
                                        </x-ui.badge>
                                        <div class="flex flex-wrap gap-1.5">
                                            <x-ui.badge variant="primary" size="sm" dot>
                                                {{ $alert['threshold_label'] }}
                                            </x-ui.badge>
                                            @foreach($alert['threshold_schedule'] as $threshold)
                                                <x-ui.badge variant="muted" size="sm">
                                                    {{ $threshold }}
                                                </x-ui.badge>
                                            @endforeach
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="font-sans text-sm font-semibold text-ink">{{ date('d M Y', strtotime($alert['tanggal_target'])) }}</div>
                                    <div class="mt-1 text-[11px] font-medium text-muted">Tanggal target</div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <span class="inline-flex items-center text-xs font-semibold {{ $sisaBadgeClass }}">
                                        {{ $alert['sisa_hari'] }} Hari
                                    </span>
                                    <div class="mt-1 text-[11px] font-medium text-muted">
                                        @if($alert['sisa_hari'] < 30)
                                            Sangat mendesak
                                        @elseif($alert['sisa_hari'] <= 90)
                                            Perlu perhatian
                                        @else
                                            Pemantauan rutin
                                        @endif
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    @php
                                        $tindakLanjutColor = 'ink';
                                        if ($alert['status_tindak_lanjut'] === 'Aktif') $tindakLanjutColor = 'warning';
                                        elseif ($alert['status_tindak_lanjut'] === 'Ditangani') $tindakLanjutColor = 'success';
                                        elseif ($alert['status_tindak_lanjut'] === 'Kedaluwarsa') $tindakLanjutColor = 'danger';
                                    @endphp
                                    <x-ui.badge variant="{{ $tindakLanjutColor }}" size="md" dot>
                                        {{ $alert['status_tindak_lanjut'] }}
                                    </x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    @if($alert['jenis_event'] === 'Kenaikan Pangkat')
                                        <div x-data="{ open: false }" class="max-w-[240px]">
                                            <div class="flex items-center gap-1.5 cursor-pointer w-max" @click="open = !open">
                                                <span class="inline-flex rounded-full {{ $alert['is_eligible'] ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger' }} px-2.5 py-1 text-xs font-semibold">
                                                    {{ $alert['is_eligible'] ? 'Eligible' : 'Tidak Eligible' }}
                                                </span>
                                                <button type="button" class="p-0.5 rounded-full hover:bg-black/5 focus:outline-none transition-colors {{ $alert['is_eligible'] ? 'text-success' : 'text-danger' }}" aria-label="Toggle Detail">
                                                    <svg class="w-4 h-4 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                    </svg>
                                                </button>
                                            </div>
                                            <div x-show="open" style="display: none;" x-transition class="mt-3 border-t {{ $alert['is_eligible'] ? 'border-success/10' : 'border-danger/10' }} pt-2.5">
                                                <div class="mb-2 flex items-start gap-1.5 text-xs font-semibold {{ $alert['is_eligible'] ? 'text-success' : 'text-danger' }}">
                                                    @if($alert['is_eligible'])
                                                        <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                        </svg>
                                                    @else
                                                        <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                                        </svg>
                                                    @endif
                                                    <span class="leading-snug">{{ $alert['eligibility_reason'] }}</span>
                                                </div>
                                                <div class="space-y-1.5">
                                                    @foreach($alert['eligibility_checks'] as $check)
                                                        <div class="flex items-center gap-1.5 text-[11px] font-medium {{ $check['passed'] ? 'text-success' : 'text-danger' }}">
                                                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $check['passed'] ? 'bg-success/10' : 'bg-danger/10' }}">
                                                                @if($check['passed'])
                                                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                                    </svg>
                                                                @else
                                                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                                    </svg>
                                                                @endif
                                                            </span>
                                                            <span>{{ $check['label'] }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-xs font-semibold text-success">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12.75 3 3m0 0 3-3m-3 3v-7.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            Otomatis diproses
                                        </div>
                                    @endif
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="7" class="py-12">
                                    <x-ui.empty-state 
                                        icon="exclamation-triangle" 
                                        title="Tidak Ada EWS Aktif" 
                                        description="Bawahan Anda saat ini tidak memiliki peringatan sistem atau kriteria pencarian tidak cocok."
                                    />
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
            
            {{-- Pagination Mock --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20" x-data="{ currentPage: 1, totalPages: 1, perPage: 10 }">
                <div class="flex items-center gap-3 text-sm text-muted">
                    <span class="whitespace-nowrap">Tampilkan</span>
                    <select x-model="perPage" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="hidden sm:inline">data</span>

                    {{-- Meta Info --}}
                    <div class="hidden md:block ml-2 border-l border-border pl-4">
                        Menampilkan <span class="font-medium text-ink">{{ count($listEws) > 0 ? 1 : 0 }}</span>
                        - <span class="font-medium text-ink">{{ count($listEws) }}</span>
                        dari <span class="font-medium text-ink">{{ count($listEws) }}</span>
                    </div>
                </div>

                <div class="w-full sm:w-auto" x-show="totalPages > 1">
                    <x-ui.pagination />
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
