@push('head')
    @vite('resources/js/pages/employee-statistics.js')
@endpush

<x-layouts.app title="Statistik Kepegawaian">
    @php
        $sections = [
            'jenis_pegawai' => [
                'title' => 'Jenis Pegawai',
                'desc' => 'Proporsi kepegawaian berdasarkan status PNS, PPPK, dan CPNS.',
                'type' => 'donut',
            ],
            'jenis_kelamin' => [
                'title' => 'Jenis Kelamin',
                'desc' => 'Komposisi pegawai berdasarkan jenis kelamin.',
                'type' => 'donut',
            ],
            'golongan' => [
                'title' => 'Golongan',
                'desc' => 'Distribusi jumlah pegawai menurut golongan ruang kepangkatan.',
                'type' => 'bar',
            ],
            'pendidikan' => [
                'title' => 'Jenjang Pendidikan',
                'desc' => 'Komposisi pegawai menurut kualifikasi pendidikan terakhir.',
                'type' => 'bar',
            ],
            'jenis_jabatan' => [
                'title' => 'Jenis Jabatan',
                'desc' => 'Distribusi pegawai menurut kategori jenis jabatan.',
                'type' => 'bar',
            ],
            'status_pegawai' => [
                'title' => 'Status Kepegawaian',
                'desc' => 'Distribusi status operasional kepegawaian aktif.',
                'type' => 'donut',
            ],
            'unit_kerja' => [
                'title' => 'Unit Kerja',
                'desc' => 'Distribusi penempatan pegawai pada unit kerja.',
                'type' => 'horizontalBar',
                'fullWidth' => true,
            ],
            'jabatan' => [
                'title' => 'Jabatan',
                'desc' => 'Distribusi pegawai berdasarkan formasi nama jabatan.',
                'type' => 'horizontalBar',
                'fullWidth' => true,
            ],
        ];

        $chartTones = ['primary', 'secondary', 'info', 'success', 'warning', 'orange', 'danger', 'muted'];
    @endphp

    <div 
        class="space-y-6" 
        x-data="employeeStatisticsPage({
            total: {{ (int) $total }},
            summary: @js($summary ?? []),
            dimensions: @js($dimensions ?? [])
        })"
    >
        {{-- PAGE HEADER --}}
        <div>
            <h1 class="text-2xl font-semibold text-ink">Statistik Kepegawaian</h1>
            <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('dashboard')], ['label' => 'Statistik Kepegawaian']]" />
        </div>

        {{-- VISUALIZATION SECTIONS GRID --}}
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            @foreach ($sections as $key => $meta)
                @php
                    $rows = $dimensions[$key] ?? [];
                    $totalInDim = array_sum(array_column($rows, 'total'));
                    $maxInDim = !empty($rows) ? max(array_column($rows, 'total')) : 0;
                    $isFullWidth = !empty($meta['fullWidth']);
                @endphp

                <x-ui.card 
                    padding="none" 
                    class="flex flex-col justify-between overflow-hidden {{ $isFullWidth ? 'xl:col-span-2' : '' }}"
                >
                    {{-- Header with Tab Switcher (Icon Only) --}}
                    <div class="flex items-start justify-between gap-3 border-b border-border bg-surface px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">{{ $meta['title'] }}</h2>
                                @if (count($rows) > 0)
                                    <x-ui.badge variant="muted" size="sm">
                                        {{ count($rows) }} kategori
                                    </x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-muted">{{ $meta['desc'] }}</p>
                        </div>

                        {{-- View Switcher Buttons (Icon Only) --}}
                        @if (!empty($rows))
                            <div class="shrink-0 self-start rounded-lg border border-border bg-soft p-0.5 print:hidden" role="group" aria-label="Pilihan tampilan {{ strtolower($meta['title']) }}">
                                <button
                                    type="button"
                                    @click="toggleView('{{ $key }}', 'chart')"
                                    :class="activeViews['{{ $key }}'] === 'chart' ? 'bg-surface text-ink shadow-xs' : 'text-muted hover:text-ink'"
                                    :aria-pressed="activeViews['{{ $key }}'] === 'chart'"
                                    class="flex h-10 w-10 items-center justify-center rounded-md transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                    title="Tampilan Grafik"
                                    aria-label="Tampilan Grafik"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    @click="toggleView('{{ $key }}', 'table')"
                                    :class="activeViews['{{ $key }}'] === 'table' ? 'bg-surface text-ink shadow-xs' : 'text-muted hover:text-ink'"
                                    :aria-pressed="activeViews['{{ $key }}'] === 'table'"
                                    class="flex h-10 w-10 items-center justify-center rounded-md transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                    title="Tampilan Tabel"
                                    aria-label="Tampilan Tabel"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                                    </svg>
                                </button>
                            </div>
                        @endif
                    </div>

                    {{-- Content Area --}}
                    <div class="p-5 flex-1 flex flex-col justify-center">
                        @if (empty($rows))
                            <div class="py-8">
                                <x-ui.empty-state 
                                    icon="none" 
                                    title="Belum ada data untuk kategori {{ $meta['title'] }}" 
                                    message="Data pegawai aktif belum memiliki entri pada dimensi ini." 
                                />
                            </div>
                        @else
                            <div class="sr-only" x-bind:aria-hidden="activeViews['{{ $key }}'] === 'table'">
                                <h3>Data {{ $meta['title'] }}</h3>
                                <ul>
                                    @foreach ($rows as $row)
                                        <li>{{ $row['label'] }}: {{ $row['total'] }} pegawai.</li>
                                    @endforeach
                                </ul>
                            </div>

                            {{-- 1. CHART VIEW --}}
                            <div x-show="activeViews['{{ $key }}'] === 'chart'" class="w-full">
                                @if ($meta['type'] === 'donut')
                                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                                        <div class="md:col-span-6 flex justify-center py-2">
                                            <div class="relative h-48 w-48">
                                                <canvas id="chart-{{ $key }}" aria-hidden="true"></canvas>
                                                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                                                    <span class="text-2xl font-bold text-ink">{{ $totalInDim }}</span>
                                                    <span class="text-[10px] uppercase tracking-wider text-muted font-medium">Pegawai</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="md:col-span-6">
                                            <ul class="space-y-2.5">
                                                @foreach ($rows as $index => $row)
                                                    @php
                                                        $pct = $totalInDim > 0 ? ($row['total'] / $totalInDim) * 100 : 0;
                                                        $tone = in_array($row['tone'] ?? null, $chartTones, true) ? $row['tone'] : 'primary';
                                                    @endphp
                                                    <li class="flex items-center justify-between text-xs">
                                                        <div class="flex items-center gap-2 min-w-0 pr-2">
                                                            <span class="statistics-chart-tone-{{ $tone }} h-2.5 w-2.5 rounded-full shrink-0" aria-hidden="true"></span>
                                                            <span class="font-medium text-ink truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                                                        </div>
                                                        <div class="shrink-0 flex items-center justify-end gap-2 text-right tabular-nums">
                                                            <span class="w-8 text-right font-bold text-ink">{{ $row['total'] }}</span>
                                                            <span class="w-14 text-right text-muted">({{ number_format($pct, 1) }}%)</span>
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @else
                                    {{-- BAR CHART --}}
                                    <div class="relative {{ $isFullWidth ? 'h-64' : 'h-56' }} w-full">
                                        <canvas id="chart-{{ $key }}" aria-hidden="true"></canvas>
                                    </div>
                                @endif
                            </div>

                            {{-- 2. TABLE / PROGRESS VIEW --}}
                            <div x-show="activeViews['{{ $key }}'] === 'table'" style="display: none;">
                                <ul class="divide-y divide-border -mx-5 -my-5" aria-label="Statistik {{ strtolower($meta['title']) }}">
                                    @foreach ($rows as $row)
                                        @php
                                            $percent = $totalInDim > 0 ? ($row['total'] / $totalInDim) * 100 : 0;
                                            $barWidth = $maxInDim > 0 ? ($row['total'] / $maxInDim) * 100 : 0;
                                            $tone = in_array($row['tone'] ?? null, $chartTones, true) ? $row['tone'] : 'primary';
                                        @endphp
                                        <li class="px-5 py-3 hover:bg-soft/40 transition-colors">
                                            <div class="flex items-center justify-between gap-4">
                                                <span class="min-w-0 break-words text-sm font-medium text-ink">
                                                    {{ $row['label'] }}
                                                </span>
                                                <div class="shrink-0 flex items-center justify-end gap-2 text-right tabular-nums">
                                                    <span class="text-sm font-semibold text-ink">{{ $row['total'] }} pegawai</span>
                                                    <span class="w-14 text-right text-xs text-muted">({{ number_format($percent, 1) }}%)</span>
                                                </div>
                                            </div>
                                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-soft" aria-hidden="true">
                                                <div 
                                                    class="statistics-chart-tone-{{ $tone }} statistics-progress-bar h-full rounded-full"
                                                    data-statistics-progress="{{ $barWidth }}"
                                                ></div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    </div>
</x-layouts.app>
