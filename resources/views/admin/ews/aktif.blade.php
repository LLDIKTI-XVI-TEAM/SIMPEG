<x-layouts.app title="Daftar EWS">
    @php
        $currentStatus = $filterStatus !== '' ? $filterStatus : 'aktif';
        $statusHeading = match ($currentStatus) {
            'ditangani' => 'Ditangani',
            'tidak_perlu' => 'Tidak Perlu',
            'kedaluwarsa' => 'Kedaluwarsa',
            'semua' => 'Semua Status',
            default => 'Aktif',
        };
        $canFollowup = in_array(auth()->user()?->getEffectiveRole(), ['super_admin', 'admin_kepegawaian'], true);
        $ewsRoute = function (array $overrides = []) use ($filterEvent, $filterStatus, $filterSearch, $alerts) {
            $query = array_merge([
                'event' => $filterEvent,
                'status' => $filterStatus,
                'search' => $filterSearch,
                'per_page' => $alerts->perPage(),
            ], $overrides);
            $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

            return route('ews', $query);
        };
        $statusVariant = fn (string $status): string => match ($status) {
            'ditangani' => 'success',
            'tidak_perlu' => 'muted',
            'kedaluwarsa' => 'warning',
            default => 'primary',
        };
    @endphp

    <div class="space-y-6" x-data="{
        followup: { open: false, action: '', status: '', label: '', employee: '', type: '', note: '' },
        openFollowupFromButton(event) {
            const button = event.currentTarget;

            this.followup = {
                open: true,
                action: button.dataset.followupAction,
                status: button.dataset.followupStatus,
                label: button.dataset.followupLabel,
                employee: button.dataset.followupEmployee,
                type: button.dataset.followupType,
                note: '',
            };
        },
        closeFollowup() {
            this.followup.open = false;
        },
    }">
        {{-- PAGE HEADER --}}
        {{-- ================================================================ --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Daftar EWS {{ $statusHeading }}</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'EWS & Notifikasi'],
                    ['label' => 'EWS ' . $statusHeading]
                ]" />
            </div>
            
        </div>

        {{-- SUMMARY CARDS (EWS Compact Operational Metrics) --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $countMerah = $summary['urgent'];
                $countKuning = $summary['warning'];
                $countHijau = $summary['info'];
                $countTotal = $summary['total'];
            @endphp

            <x-ui.stat-card label="Total Peringatan" value="{{ $countTotal }}" unit="Kasus {{ $followupStatusLabels[$currentStatus] ?? 'Aktif' }}" variant="primary" accent>
                <x-slot:icon>
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                    </svg>
                </x-slot:icon>
            </x-ui.stat-card>

            <x-ui.stat-card label="Sangat Mendesak" value="{{ $countMerah }}" unit="Kurang dari 30 Hari" variant="danger" accent>
                <x-slot:icon>
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2.25m0 1.5h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </x-slot:icon>
            </x-ui.stat-card>

            <x-ui.stat-card label="Perlu Perhatian" value="{{ $countKuning }}" unit="30–90 Hari" variant="warning" accent>
                <x-slot:icon>
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </x-slot:icon>
            </x-ui.stat-card>

            <x-ui.stat-card label="Pemantauan Rutin" value="{{ $countHijau }}" unit="Lebih dari 90 Hari" variant="success" accent>
                <x-slot:icon>
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </x-slot:icon>
            </x-ui.stat-card>
        </div>



        {{-- FILTER & SEARCH AREA --}}
        {{-- ================================================================ --}}
        <x-ui.card padding="none" class="overflow-hidden">
            {{-- Toolbar / Header Tabel --}}
            <div class="px-6 py-4 border-b border-border flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Daftar Peringatan Dini</h3>
                    <p class="text-xs text-muted">Pantau dan tindak lanjuti peringatan dini kepegawaian berdasarkan ambang batas waktu.</p>
                </div>
            </div>

            <div class="border-b border-border bg-soft/30 px-6 py-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                
                {{-- Event & Status Filter Links --}}
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-1 bg-soft p-1 rounded-lg">
                        <a href="{{ $ewsRoute(['event' => '', 'page' => null]) }}" class="rounded-md px-3.5 py-1.5 text-xs font-semibold transition-colors duration-200 {{ $filterEvent === '' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                            Semua Event
                        </a>
                        @foreach($typeLabels as $eventLabel)
                            <a href="{{ $ewsRoute(['event' => $eventLabel, 'page' => null]) }}" class="rounded-md px-3.5 py-1.5 text-xs font-semibold transition-colors duration-200 {{ $filterEvent === $eventLabel ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                                {{ $eventLabel }}
                            </a>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-1 bg-soft p-1 rounded-lg">
                        @foreach($followupStatusLabels as $status => $label)
                            <a href="{{ $ewsRoute(['status' => $status, 'page' => null]) }}" class="rounded-md px-3.5 py-1.5 text-xs font-semibold transition-colors duration-200 {{ $currentStatus === $status ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                {{-- Search Box --}}
                <form method="GET" action="{{ route('ews') }}" class="flex w-full shrink-0 items-center gap-2 sm:w-96 lg:self-start">
                    <input type="hidden" name="event" value="{{ $filterEvent }}">
                    <input type="hidden" name="status" value="{{ $filterStatus }}">
                    <input type="hidden" name="per_page" value="{{ $alerts->perPage() }}">
                    <label for="ews-search" class="sr-only">Cari nama atau NIP pegawai</label>
                    <div class="relative min-w-0 flex-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.603 10.603Z" />
                            </svg>
                        </span>
                        <input
                            id="ews-search"
                            type="search"
                            name="search"
                            value="{{ $filterSearch }}"
                            placeholder="Cari nama atau NIP"
                            class="w-full rounded-lg border border-border bg-surface py-2 pl-9 pr-4 text-sm text-ink placeholder-muted shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                    </div>
                    <x-ui.button type="submit" variant="secondary" size="md" class="shrink-0" aria-label="Terapkan pencarian EWS">
                        Cari
                    </x-ui.button>
                </form>
            </div>

            {{-- TABLE --}}
            {{-- ================================================================ --}}
            <div class="overflow-x-auto">
                <x-ui.table class="min-w-[1280px] table-fixed">
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th align="center" padding="wide" class="w-14">No</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[260px]">Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[260px]">Event & Ambang</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[150px]">Target</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[140px]">Urgensi</x-ui.table-th>
                            <x-ui.table-th padding="wide">Eligibility</x-ui.table-th>
                            <x-ui.table-th padding="wide" class="w-[220px]">Status / Tindakan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
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
                            <x-ui.table-row :interactive="true" class="align-top {{ $rowColorClass }}">
                                <x-ui.table-td align="center" padding="lg" class="text-sm font-semibold text-muted">{{ ($alerts->firstItem() ?? 1) + $index }}</x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="font-semibold leading-snug text-ink transition-colors hover:text-primary">
                                        @php $ewsDetailUrl = auth()->user()?->getEffectiveRole() === 'super_admin' ? route('pegawai.show', $alert['pegawai_id']) : route('rbac.pegawai.show', $alert['pegawai_id']); @endphp
                                        <a href="{{ $ewsDetailUrl }}">{{ $alert['nama'] }}</a>
                                    </div>
                                    <div class="mt-1 text-xs text-muted">{{ $alert['nip'] }}</div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="space-y-1.5">
                                        <div class="font-semibold leading-snug text-ink">{{ $alert['jenis_event'] }}</div>
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
                                    <div class="text-sm font-semibold text-ink">{{ date('d M Y', strtotime($alert['tanggal_target'])) }}</div>
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
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm">
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.badge :variant="$statusVariant($alert['followup_status'])" size="md" dot>
                                                {{ $alert['followup_status_label'] }}
                                            </x-ui.badge>

                                            @if($alert['followup_status'] === 'aktif' && $canFollowup)
                                                <div class="flex items-center gap-1.5">
                                                    <x-ui.tooltip text="Tandai Ditangani" position="top">
                                                        <x-ui.button
                                                            type="button"
                                                            variant="success"
                                                            size="icon"
                                                            @click="openFollowupFromButton($event)"
                                                            data-alert-id="{{ $alert['alert_id'] }}"
                                                            data-followup-action="{{ route('ews.followup.update', $alert['alert_id']) }}"
                                                            data-followup-status="ditangani"
                                                            data-followup-label="Ditangani"
                                                            data-followup-employee="{{ $alert['nama'] }}"
                                                            data-followup-type="{{ $alert['type'] }}"
                                                            aria-label="Tandai Ditangani untuk {{ $alert['nama'] }}"
                                                        >
                                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                            </svg>
                                                        </x-ui.button>
                                                    </x-ui.tooltip>

                                                    <x-ui.tooltip text="Tandai Tidak Perlu" position="top">
                                                        <x-ui.button
                                                            type="button"
                                                            variant="danger"
                                                            size="icon"
                                                            @click="openFollowupFromButton($event)"
                                                            data-alert-id="{{ $alert['alert_id'] }}"
                                                            data-followup-action="{{ route('ews.followup.update', $alert['alert_id']) }}"
                                                            data-followup-status="tidak_perlu"
                                                            data-followup-label="Tidak Perlu"
                                                            data-followup-employee="{{ $alert['nama'] }}"
                                                            data-followup-type="{{ $alert['type'] }}"
                                                            aria-label="Tandai Tidak Perlu untuk {{ $alert['nama'] }}"
                                                        >
                                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                            </svg>
                                                        </x-ui.button>
                                                    </x-ui.tooltip>
                                                </div>
                                            @endif
                                        </div>
                                        @if($alert['followup_status'] !== 'aktif')
                                            <div class="space-y-1 text-[11px] text-muted">
                                                @if($alert['handled_at'])
                                                    <p class="font-medium text-ink">{{ date('d M Y H:i', strtotime($alert['handled_at'])) }}</p>
                                                @endif
                                                @if($alert['handled_by_name'])
                                                    <p>Oleh {{ $alert['handled_by_name'] }}</p>
                                                @endif
                                                @if($alert['handled_note'])
                                                    <p class="line-clamp-3 rounded-lg bg-soft/70 px-2 py-1.5 text-ink" title="{{ $alert['handled_note'] }}">
                                                        {{ $alert['handled_note'] }}
                                                    </p>
                                                @endif
                                            </div>
                                        @else
                                            <p class="text-xs text-muted">Menunggu tindak lanjut admin.</p>
                                        @endif
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="7" align="center" class="px-6 py-12 text-muted text-sm">
                                    Tidak ada data EWS untuk filter yang dipilih.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
            <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-soft/20 px-6 py-4 sm:flex-row">
                <div class="flex flex-wrap items-center justify-center gap-4 sm:justify-start text-sm text-muted">
                    <form method="GET" action="{{ route('ews') }}" class="flex items-center gap-2">
                        <input type="hidden" name="event" value="{{ $filterEvent }}">
                        <input type="hidden" name="status" value="{{ $filterStatus }}">
                        <input type="hidden" name="search" value="{{ $filterSearch }}">

                        <span class="whitespace-nowrap">Tampilkan</span>
                        <label for="ews-per-page" class="sr-only">Jumlah peringatan per halaman</label>
                        <select
                            id="ews-per-page"
                            name="per_page"
                            onchange="this.form.submit()"
                            class="appearance-none bg-none cursor-pointer rounded-md border border-border bg-surface px-2.5 py-1 text-center font-sans text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                            @foreach([10, 25, 50] as $option)
                                <option value="{{ $option }}" @selected($alerts->perPage() === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        <span class="hidden sm:inline">data</span>
                    </form>

                    {{-- Meta Info --}}
                    <div class="hidden md:block ml-2 border-l border-border pl-4">
                        Menampilkan <span class="font-medium text-ink">{{ $alerts->firstItem() ?? 0 }}</span>
                        - <span class="font-medium text-ink">{{ $alerts->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium text-ink">{{ $alerts->total() }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $alerts->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>

        <x-ui.modal
            show="followup.open"
            title="Catatan Tindak Lanjut EWS"
            closeAction="closeFollowup()"
            maxWidth="lg"
        >
            <form method="POST" :action="followup.action" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PATCH')

                <input type="hidden" name="followup_status" :value="followup.status">

                <div class="rounded-lg border border-border bg-soft/60 px-4 py-3 text-sm">
                    <p class="font-semibold text-ink" x-text="followup.label"></p>
                    <p class="mt-1 text-xs text-muted" x-text="followup.employee"></p>
                </div>

                <template x-if="followup.status === 'ditangani' && followup.type === 'KENAIKAN_PANGKAT'">
                    <div class="space-y-4 rounded-lg border border-primary/20 bg-primary/5 p-4">
                        <div>
                            <h4 class="text-sm font-semibold text-ink">SK Pangkat Baru</h4>
                            <p class="mt-1 text-xs text-muted">Riwayat pangkat baru akan dibuat dan target EWS dihitung ulang dari TMT Pangkat + konfigurasi masa berlaku.</p>
                        </div>
                        <div>
                            <label for="ews-golongan-id" class="mb-1.5 block text-sm font-medium text-ink">Golongan Baru <span class="text-danger">*</span></label>
                            <select id="ews-golongan-id" name="golongan_id" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="">Pilih golongan</option>
                                @foreach($golonganOptions as $golongan)
                                    <option value="{{ $golongan->id }}">{{ $golongan->kode }} — {{ $golongan->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="ews-tmt-pangkat" class="mb-1.5 block text-sm font-medium text-ink">TMT Pangkat <span class="text-danger">*</span></label>
                                <input id="ews-tmt-pangkat" type="date" name="tmt_pangkat" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                            <div>
                                <label for="ews-tanggal-sk-pangkat" class="mb-1.5 block text-sm font-medium text-ink">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="ews-tanggal-sk-pangkat" type="date" name="tanggal_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                        </div>
                        <div>
                            <label for="ews-no-sk-pangkat" class="mb-1.5 block text-sm font-medium text-ink">Nomor SK <span class="text-danger">*</span></label>
                            <input id="ews-no-sk-pangkat" type="text" name="no_sk" required maxlength="100" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                            <label for="ews-file-sk-pangkat" class="mb-1.5 block text-sm font-medium text-ink">File SK Baru <span class="text-danger">*</span></label>
                            <input id="ews-file-sk-pangkat" type="file" name="file_sk" required accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-primary hover:file:bg-primary/20">
                            <p class="mt-1 text-xs text-muted">PDF/JPG/PNG, maksimal 10 MB.</p>
                        </div>
                    </div>
                </template>

                <template x-if="followup.status === 'ditangani' && followup.type === 'KGB'">
                    <div class="space-y-4 rounded-lg border border-primary/20 bg-primary/5 p-4">
                        <div>
                            <h4 class="text-sm font-semibold text-ink">SK KGB Baru</h4>
                            <p class="mt-1 text-xs text-muted">Riwayat KGB baru akan dibuat dan target EWS dihitung ulang dari TMT KGB + konfigurasi masa berlaku.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="ews-tmt-kgb" class="mb-1.5 block text-sm font-medium text-ink">TMT KGB <span class="text-danger">*</span></label>
                                <input id="ews-tmt-kgb" type="date" name="tmt_kgb" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                            <div>
                                <label for="ews-gaji-pokok" class="mb-1.5 block text-sm font-medium text-ink">Gaji Pokok <span class="text-danger">*</span></label>
                                <input id="ews-gaji-pokok" type="number" name="gaji_pokok" required min="0" step="0.01" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="ews-tanggal-sk-kgb" class="mb-1.5 block text-sm font-medium text-ink">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="ews-tanggal-sk-kgb" type="date" name="tanggal_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                            <div>
                                <label for="ews-no-sk-kgb" class="mb-1.5 block text-sm font-medium text-ink">Nomor SK <span class="text-danger">*</span></label>
                                <input id="ews-no-sk-kgb" type="text" name="no_sk" required maxlength="100" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                        </div>
                        <div>
                            <label for="ews-file-sk-kgb" class="mb-1.5 block text-sm font-medium text-ink">File SK Baru <span class="text-danger">*</span></label>
                            <input id="ews-file-sk-kgb" type="file" name="file_sk" required accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-primary hover:file:bg-primary/20">
                            <p class="mt-1 text-xs text-muted">PDF/JPG/PNG, maksimal 10 MB.</p>
                        </div>
                    </div>
                </template>

                <template x-if="followup.status === 'ditangani' && followup.type === 'PENSIUN'">
                    <div class="space-y-4 rounded-lg border border-danger/20 bg-danger/5 p-4">
                        <div>
                            <h4 class="text-sm font-semibold text-ink">SK Pensiun</h4>
                            <p class="mt-1 text-xs text-muted">SK akan diarsipkan. Status langsung berubah menjadi Pensiun bila tanggal SK sudah berlaku; untuk tanggal mendatang, perubahan dijadwalkan pada tanggal tersebut.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="ews-tanggal-sk-pensiun" class="mb-1.5 block text-sm font-medium text-ink">Tanggal SK <span class="text-danger">*</span></label>
                                <input id="ews-tanggal-sk-pensiun" type="date" name="tanggal_sk" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                            <div>
                                <label for="ews-no-sk-pensiun" class="mb-1.5 block text-sm font-medium text-ink">Nomor SK <span class="text-danger">*</span></label>
                                <input id="ews-no-sk-pensiun" type="text" name="no_sk" required maxlength="100" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                        </div>
                        <div>
                            <label for="ews-file-sk-pensiun" class="mb-1.5 block text-sm font-medium text-ink">File SK Pensiun <span class="text-danger">*</span></label>
                            <input id="ews-file-sk-pensiun" type="file" name="file_sk" required accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-danger/10 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-danger hover:file:bg-danger/20">
                            <p class="mt-1 text-xs text-muted">PDF/JPG/PNG, maksimal 10 MB.</p>
                        </div>
                    </div>
                </template>

                <x-form.textarea
                    name="handled_note"
                    id="ews-followup-note"
                    label="Catatan"
                    rows="4"
                    required
                    placeholder="Tulis catatan tindak lanjut EWS..."
                    x-model="followup.note"
                />

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" @click="closeFollowup()">
                        Batal
                    </x-ui.button>
                    <x-ui.button
                        type="submit"
                        ::disabled="followup.note.trim() === ''"
                    >
                        Simpan Tindak Lanjut
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </div>
</x-layouts.app>
