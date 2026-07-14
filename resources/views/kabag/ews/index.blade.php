<x-layouts.app title="EWS Bawahan" subtitle="Pemantauan Early Warning System (EWS) khusus bawahan langsung Anda.">
    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">EWS Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'EWS Bawahan']
            ]" />
        </div>
    </div>

    <x-ui.alert variant="info" title="Akses terbatas" class="mb-6">
        Peringatan di halaman ini hanya berasal dari bawahan langsung. Tindak lanjut EWS dilakukan oleh Admin Kepegawaian sesuai kewenangannya.
    </x-ui.alert>

    {{-- SUMMARY CARDS --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        @php
            $countMerah = collect($alerts)->filter(fn($a) => $a['sisa_hari'] < 30)->count();
            $countKuning = collect($alerts)->filter(fn($a) => $a['sisa_hari'] >= 30 && $a['sisa_hari'] <= 90)->count();
            $countHijau = collect($alerts)->filter(fn($a) => $a['sisa_hari'] > 90)->count();
            $countTotal = collect($alerts)->count();
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
    <div x-data="{ search: '' }">
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-border bg-soft/30 px-6 py-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                
                {{-- Event Filter Links --}}
                <div class="flex flex-wrap gap-1 bg-soft p-1 rounded-lg">
                    <a href="{{ route('kepala-bagian.ews.index', ['status' => request('status')]) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ request('event') === null ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                        Semua
                    </a>
                    @foreach($type_labels as $label)
                        <a href="{{ route('kepala-bagian.ews.index', ['event' => $label, 'status' => request('status')]) }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-md transition-all {{ request('event') === $label ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
                    {{-- Status Select Form --}}


                    {{-- Search Box (Frontend Only) --}}
                    <div class="relative w-full sm:w-64">
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
            </div>

            {{-- TABLE --}}
            <div class="overflow-x-auto">
                <x-ui.table class="min-w-[1040px] table-fixed">
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th align="center" padding="wide" class="w-14">NO</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[260px]">PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[240px]">EVENT & AMBANG</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[150px]">TARGET</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[140px]">URGENSI</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[160px]">STATUS TINDAKAN</x-ui.table-th>
                            <x-ui.table-th padding="wide">ELIGIBILITY</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($alerts as $index => $alert)
                            @php
                                $sisaBadgeClass = '';
                                if ($alert['sisa_hari'] < 30) {
                                    $sisaBadgeClass = 'text-danger';
                                } elseif ($alert['sisa_hari'] <= 90) {
                                    $sisaBadgeClass = 'text-warning';
                                } else {
                                    $sisaBadgeClass = 'text-success';
                                }
                                $remaining = $alert['sisa_hari'] < 0 ? 'Lewat '.abs($alert['sisa_hari']).' hari' : $alert['sisa_hari'].' hari';
                            @endphp
                            <x-ui.table-row x-show="search === '' || '{{ strtolower($alert['nama']) }}'.includes(search.toLowerCase()) || '{{ str_replace(' ', '', $alert['nip']) }}'.includes(search.replace(/\s+/g, ''))" class="align-middle hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td align="center" padding="lg" class="font-mono text-sm font-semibold text-muted">{{ $index + 1 }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="w-full min-w-0">
                                        <x-ui.tooltip text="Buka detail {{ $alert['nama'] }}" position="right">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="block truncate text-sm font-semibold text-ink transition-colors hover:text-primary focus:outline-none rounded leading-tight">{{ $alert['nama'] }}</a>
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
                                            @elseif($alert['jenis_event'] === 'Kenaikan Gaji Berkala' || $alert['jenis_event'] === 'KGB')
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
                                        </x-ui.badge>
                                        <div class="flex flex-wrap gap-1.5">
                                            <x-ui.badge variant="primary" size="sm" dot>
                                                {{ $alert['threshold_label'] }}
                                            </x-ui.badge>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="font-sans text-sm font-semibold text-ink">{{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</div>
                                    <div class="mt-1 text-[11px] font-medium text-muted">Tanggal target</div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <span class="inline-flex items-center text-xs font-semibold {{ $sisaBadgeClass }}">
                                        {{ $remaining }}
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
                                        if (str_contains(strtolower($alert['followup_status_label']), 'aktif')) $tindakLanjutColor = 'warning';
                                        elseif (str_contains(strtolower($alert['followup_status_label']), 'selesai')) $tindakLanjutColor = 'success';
                                    @endphp
                                    <x-ui.badge variant="{{ $tindakLanjutColor }}" size="md" dot>
                                        {{ $alert['followup_status_label'] }}
                                    </x-ui.badge>
                                    @if ($alert['handled_note'])<p class="mt-1 max-w-xs text-[11px] text-muted">{{ $alert['handled_note'] }}</p>@endif
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div x-data="{ open: false }" class="max-w-[240px]">
                                        <div class="flex items-center gap-1.5 cursor-pointer w-max" @click="open = !open">
                                            <span class="inline-flex rounded-full {{ $alert['is_eligible'] ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger' }} px-2.5 py-1 text-xs font-semibold">
                                                {{ $alert['is_eligible'] ? 'Layak' : 'Tidak Layak' }}
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
                                        </div>
                                    </div>
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
            
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20" x-data="{ currentPage: 1, totalPages: 1 }">
                <form class="flex items-center gap-3 text-sm text-muted">
                    <span class="whitespace-nowrap">Tampilkan</span>
                    <select class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="hidden sm:inline">data</span>

                    {{-- Meta Info --}}
                    @if(count($alerts) > 0)
                        <div class="hidden md:block ml-2 border-l border-border pl-4">
                            Menampilkan <span class="font-medium text-ink">1</span>
                            - <span class="font-medium text-ink">{{ count($alerts) }}</span>
                            dari <span class="font-medium text-ink">{{ count($alerts) }}</span>
                        </div>
                    @endif
                </form>
                
                <div class="w-full sm:w-auto" x-show="totalPages > 1">
                    <x-ui.pagination />
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
