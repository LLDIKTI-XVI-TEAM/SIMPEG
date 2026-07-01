<x-layouts.app title="EWS Aktif">
    <div class="space-y-6" x-data="{ search: '' }">
        {{-- PAGE HEADER --}}
        {{-- ================================================================ --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Daftar EWS Aktif</h2>
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="text-muted">EWS & Notifikasi</span>
                    <span>/</span>
                    <span class="font-medium text-ink">EWS Aktif</span>
                </nav>
            </div>
            
            @if(auth()->user()?->role === 'super_admin')
            <a href="{{ route('ews.config') }}" class="inline-flex h-fit items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-soft shadow-sm shrink-0">
                <svg class="h-4 w-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
                Konfigurasi EWS
            </a>
            @endif
        </div>

        {{-- SUMMARY CARDS --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $countMerah = collect($alerts)->filter(fn($a) => $a['sisa_hari'] < 30)->count();
                $countKuning = collect($alerts)->filter(fn($a) => $a['sisa_hari'] >= 30 && $a['sisa_hari'] <= 90)->count();
                $countHijau = collect($alerts)->filter(fn($a) => $a['sisa_hari'] > 90)->count();
                $countTotal = count($alerts);
            @endphp
            
            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-soft text-ink font-bold text-lg">
                    {{ $countTotal }}
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Total Peringatan</p>
                    <h3 class="text-base font-bold text-ink">{{ $countTotal }} Kasus Aktif</h3>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-danger/10 text-danger font-bold text-lg">
                    {{ $countMerah }}
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Sangat Mendesak</p>
                    <h3 class="text-base font-bold text-danger">{{ $countMerah }} (&lt; 30 Hari)</h3>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning font-bold text-lg">
                    {{ $countKuning }}
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Perlu Perhatian</p>
                    <h3 class="text-base font-bold text-warning">{{ $countKuning }} (30-90 Hari)</h3>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-success/10 text-success font-bold text-lg">
                    {{ $countHijau }}
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted font-sans">Pemantauan Rutin</p>
                    <h3 class="text-base font-bold text-success">{{ $countHijau }} (&gt; 90 Hari)</h3>
                </div>
            </div>
        </div>



        {{-- FILTER & SEARCH AREA --}}
        {{-- ================================================================ --}}
        <div class="rounded-lg border border-border bg-surface shadow-sm overflow-hidden">
            <div class="border-b border-border bg-soft/30 px-6 py-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                
                {{-- Event Filter Links --}}
                <div class="flex flex-wrap gap-1 bg-soft p-1 rounded-lg">
                    <a href="{{ route('ews') }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === '' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Semua
                    </a>
                    <a href="{{ route('ews', ['event' => 'Kenaikan Pangkat']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Kenaikan Pangkat' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Kenaikan Pangkat
                    </a>
                    <a href="{{ route('ews', ['event' => 'KGB']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'KGB' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        KGB
                    </a>
                    <a href="{{ route('ews', ['event' => 'Pensiun']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Pensiun' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Pensiun
                    </a>
                    <a href="{{ route('ews', ['event' => 'Kontrak PPPK']) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ $filterEvent === 'Kontrak PPPK' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Kontrak PPPK
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
                <table class="w-full min-w-[1040px] table-fixed">
                    <thead class="bg-soft">
                        <tr>
                            <th class="w-14 px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-muted font-sans">No</th>
                            <th class="w-[260px] px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted font-sans">Pegawai</th>
                            <th class="w-[260px] px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted font-sans">Event & Ambang</th>
                            <th class="w-[150px] px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted font-sans">Target</th>
                            <th class="w-[140px] px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted font-sans">Urgensi</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted font-sans">Eligibility</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($alerts as $index => $alert)
                            @php
                                // Assign colors dynamically based on sisa_hari (AC-3)
                                $rowColorClass = '';
                                $sisaBadgeClass = '';
                                if ($alert['sisa_hari'] < 30) {
                                    $rowColorClass = 'hover:bg-danger/[0.01]';
                                    $sisaBadgeClass = 'text-danger';
                                } elseif ($alert['sisa_hari'] <= 90) {
                                    $rowColorClass = 'hover:bg-warning/[0.01]';
                                    $sisaBadgeClass = 'text-warning';
                                } else {
                                    $rowColorClass = 'hover:bg-success/[0.01]';
                                    $sisaBadgeClass = 'text-success';
                                }
                            @endphp
                            <tr 
                                class="align-top transition-colors {{ $rowColorClass }}"
                                x-show="search === '' || '{{ strtolower($alert['nama']) }}'.includes(search.toLowerCase()) || '{{ str_replace(' ', '', $alert['nip']) }}'.includes(search.replace(/\s+/g, ''))"
                            >
                                <td class="px-5 py-4 text-center font-mono text-sm font-semibold text-muted">{{ $index + 1 }}</td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="font-semibold leading-snug text-ink transition-colors hover:text-primary">
                                        <a href="{{ route('pegawai.show', $alert['pegawai_id']) }}">{{ $alert['nama'] }}</a>
                                    </div>
                                    <div class="mt-1 font-mono text-xs text-muted">{{ $alert['nip'] }}</div>
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="space-y-1.5">
                                        <span class="inline-flex items-center gap-1.5 rounded-md border border-border bg-soft px-2.5 py-1 text-xs font-semibold text-ink">
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
                                            @else
                                                <svg class="w-3.5 h-3.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                                </svg>
                                            @endif
                                            {{ $alert['jenis_event'] }}
                                        </span>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary">
                                                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                                                {{ $alert['threshold_label'] }}
                                            </span>
                                            @foreach($alert['threshold_schedule'] as $threshold)
                                                <span class="rounded-full border border-border bg-surface px-2 py-0.5 text-[10px] font-semibold text-muted">
                                                    {{ $threshold }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="font-mono text-sm font-semibold text-ink">{{ date('d M Y', strtotime($alert['tanggal_target'])) }}</div>
                                    <div class="mt-1 text-[11px] font-medium text-muted">Tanggal target</div>
                                </td>
                                <td class="px-5 py-4 text-sm">
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
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    @if($alert['jenis_event'] === 'Kenaikan Pangkat')
                                        <div class="max-w-[240px] rounded-lg border {{ $alert['is_eligible'] ? 'border-success/20 bg-success/5' : 'border-danger/20 bg-danger/5' }} p-2.5">
                                            <div class="mb-2 flex flex-wrap items-center gap-2 text-xs font-semibold {{ $alert['is_eligible'] ? 'text-success' : 'text-danger' }}">
                                                <span class="inline-flex rounded-full {{ $alert['is_eligible'] ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger' }} px-2.5 py-1 text-xs font-semibold">
                                                    {{ $alert['is_eligible'] ? 'Eligible' : 'Tidak Eligible' }}
                                                </span>
                                                <span class="inline-flex items-center gap-1.5">
                                                @if($alert['is_eligible'])
                                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                    </svg>
                                                @else
                                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                                    </svg>
                                                @endif
                                                {{ $alert['eligibility_reason'] }}
                                                </span>
                                            </div>
                                            <div class="space-y-1">
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
                                    @elseif($alert['is_eligible'])
                                        <div class="inline-flex items-center gap-1.5 text-xs font-semibold text-success">
                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                            {{ $alert['eligibility_reason'] }}
                                        </div>
                                    @else
                                        <div class="inline-flex items-center gap-1.5 text-xs font-semibold text-danger" title="Kinerja pegawai kurang baik untuk syarat usulan pangkat">
                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                            </svg>
                                            {{ $alert['eligibility_reason'] }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-muted text-sm">
                                    Tidak ada peringatan EWS aktif untuk kategori ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
