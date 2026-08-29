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
            $countMerah = $summary['urgent'];
            $countKuning = $summary['warning'];
            $countHijau = $summary['info'];
            $countTotal = $summary['total'];
        @endphp
        
        <x-ui.stat-card label="Total Peringatan" value="{{ $countTotal }}" variant="primary" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span>{{ $countTotal }} Kasus Aktif</span>
            </x-slot:meta>
        </x-ui.stat-card>

        <x-ui.stat-card label="Sangat Mendesak" value="{{ $countMerah }}" variant="danger" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2.25m0 1.5h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span>&lt; 30 Hari</span>
            </x-slot:meta>
        </x-ui.stat-card>

        <x-ui.stat-card label="Perlu Perhatian" value="{{ $countKuning }}" variant="warning" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span>30-90 Hari</span>
            </x-slot:meta>
        </x-ui.stat-card>

        <x-ui.stat-card label="Pemantauan Rutin" value="{{ $countHijau }}" variant="success" size="lg" accent>
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </x-slot:icon>
            <x-slot:meta>
                <span>&gt; 90 Hari</span>
            </x-slot:meta>
        </x-ui.stat-card>
    </div>

    {{-- FILTER & SEARCH AREA --}}
    <div>
        <form method="GET" action="{{ route('kepala-bagian.ews.index') }}" class="mb-6">
            <input type="hidden" name="status" value="{{ request('status') }}">
            <x-ui.filter-bar
                searchId="kabag-ews-search"
                searchName="search"
                :searchValue="$filterSearch"
                searchPlaceholder="Cari nama atau NIP"
                searchLabel="Cari nama atau NIP pegawai"
                gridClass="sm:grid-cols-3 lg:grid-cols-5"
            >
                <div class="relative">
                    <x-form.select name="event" onchange="this.form.submit()">
                        <option value="">Semua Event</option>
                        @foreach($type_labels as $label)
                            <option value="{{ $label }}" @selected(request('event') === $label)>{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30">
                        Cari
                    </button>
                </div>
            </x-ui.filter-bar>
        </form>

        <x-ui.card padding="none" class="overflow-hidden">
            {{-- TABLE --}}
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th align="center" padding="comfortable" class="w-14 text-xs text-muted font-bold uppercase tracking-wider">NO</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">EVENT & AMBANG</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">TARGET</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">URGENSI</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">STATUS TINDAKAN</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">ELIGIBILITY</x-ui.table-th>
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
                            <x-ui.table-row class="align-middle hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td align="center" padding="lg" class="text-sm font-semibold text-muted">{{ ($alerts->firstItem() ?? 1) + $index }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="w-full min-w-0">
                                        <x-ui.tooltip text="Buka detail {{ $alert['nama'] }}" position="right">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="block truncate text-sm font-semibold text-ink transition-colors hover:text-primary focus:outline-none rounded leading-tight">{{ $alert['nama'] }}</a>
                                        </x-ui.tooltip>
                                        <p class="text-xs text-muted">NIP. {{ $alert['nip'] }}</p>
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
                                            <button type="button" class="p-0.5 rounded-full hover:bg-black/5 focus:outline-none transition-colors {{ $alert['is_eligible'] ? 'text-success' : 'text-danger' }}" :aria-label="open ? 'Sembunyikan Detail' : 'Tampilkan Detail'">
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
            
            @if($alerts->hasPages())
                <div class="border-t border-border bg-surface px-6 py-4">
                    {{ $alerts->links() }}
                </div>
            @elseif($alerts->total() > 0)
                <div class="border-t border-border bg-surface px-6 py-3">
                    <p class="text-sm text-muted font-sans">
                        Menampilkan <span class="font-semibold text-ink">{{ $alerts->total() }}</span> peringatan EWS aktif untuk bawahan langsung Anda.
                    </p>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
