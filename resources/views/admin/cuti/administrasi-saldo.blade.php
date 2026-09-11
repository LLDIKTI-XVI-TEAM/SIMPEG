@pushOnce('head')
    @vite('resources/js/pages/manual-external-approval.js')
@endPushOnce

<x-layouts.app title="Administrasi Pemakaian Cuti">
    @php
        $tahunAcuan = (int) $periode;
        $bucketCards = [
            sprintf('Hak N-2 (%d)', $tahunAcuan - 2) => $selectedBalance?->sisa_n2,
            sprintf('Hak N-1 (%d)', $tahunAcuan - 1) => $selectedBalance?->sisa_n1,
            sprintf('Hak tahun berjalan (%d)', $tahunAcuan) => $selectedBalance?->sisa_tahun_berjalan,
            'Terpakai' => $selectedBalance?->terpakai,
            'Hangus' => $selectedBalance?->hangus,
        ];
        $availabilityMetrics = [
            ['key' => 'total_hak', 'label' => 'Total hak'],
            ['key' => 'saldo_aktual', 'label' => 'Saldo aktual'],
            ['key' => 'dialokasikan_aktif', 'label' => 'Dialokasikan aktif'],
            ['key' => 'dilindungi_penangguhan_dinas', 'label' => 'Dilindungi penangguhan dinas'],
            ['key' => 'saldo_dapat_diajukan', 'label' => 'Saldo dapat diajukan'],
        ];
        $statusLabels = [
            'belum_ada_fakta' => 'Belum ada fakta pemakaian',
            'fakta_aktif' => 'Memiliki fakta pemakaian',
        ];
        $sourceLabels = [
            'approved_request' => 'Melalui SIMPEG',
            'manual_external' => 'Di luar SIMPEG',
        ];
        $recordStatusLabels = [
            'active' => 'Aktif',
            'superseded' => 'Digantikan',
            'cancelled' => 'Dibatalkan',
        ];
        // Tautan kembali menjaga posisi antrian, tetapi membuang state yang hanya berlaku di workspace pegawai.
        $queueUrl = route('cuti.saldo.administrasi', array_filter([
            'status' => $status,
            'search' => $search,
            'page_pegawai' => request('page_pegawai'),
            'per_page' => request('per_page'),
        ], static fn ($value) => $value !== null && $value !== ''));
        $requestedTab = old('tab', $tab);
        $manualEditorActive = (bool) ($canManageManual && $editableUsage);
        $availableTabs = ['pendaftaran', 'manual', 'riwayat'];
        $initialTab = $manualEditorActive
            ? 'manual'
            : (in_array($requestedTab, $availableTabs, true) ? $requestedTab : 'pendaftaran');
        $usage = $usageSummary;
        $manualTabUrl = route('cuti.saldo.administrasi', array_filter([
            'pegawai' => $selectedEmployee?->id,
            'status' => $status,
            'search' => $search,
            'tab' => 'manual',
            'page_pegawai' => request('page_pegawai'),
            'per_page' => request('per_page'),
        ], static fn ($value) => $value !== null && $value !== ''));
        $manualTabHref = $canManageManual ? $manualTabUrl : null;
        $manualTabClick = $canManageManual ? null : "selectTab('manual')";
    @endphp

    <div
        class="space-y-6"
        data-leave-usage-workspace="{{ $selectedEmployee ? 'true' : 'false' }}"
        x-data="{
            tabs: @js($availableTabs),
            activeTab: @js($initialTab),
            withActiveTab(url, tab = this.activeTab) {
                const nextUrl = new URL(url, window.location.origin);
                nextUrl.searchParams.set('tab', tab);

                return `${nextUrl.pathname}${nextUrl.search}${nextUrl.hash}`;
            },
            syncLedgerPaginatorTab() {
                this.$refs.ledgerPaginator?.querySelectorAll('a[href]').forEach((link) => {
                    link.href = this.withActiveTab(link.href);
                });
            },
            syncUsagePaginatorTab() {
                this.$refs.usagePaginator?.querySelectorAll('a[href]').forEach((link) => {
                    link.href = this.withActiveTab(link.href);
                });
            },
            selectTab(tab) {
                this.activeTab = tab;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url);
                this.$nextTick(() => {
                    this.syncLedgerPaginatorTab();
                    this.syncUsagePaginatorTab();
                    document.getElementById('tab-' + tab)?.focus();
                });
            },
            activateTab(tab) {
                document.getElementById('tab-' + tab)?.click();
            },
            focusTab(direction) {
                const current = this.tabs.indexOf(this.activeTab);
                const next = (current + direction + this.tabs.length) % this.tabs.length;
                this.activateTab(this.tabs[next]);
            },
        }"
        x-init="$nextTick(() => { syncLedgerPaginatorTab(); syncUsagePaginatorTab(); })"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink font-sans">Administrasi Pemakaian Cuti</h1>
                <x-ui.breadcrumb :items="array_merge(
                    [
                        ['label' => 'Dashboard', 'url' => route('dashboard')],
                        ['label' => 'Administrasi Pemakaian Cuti', 'url' => $selectedEmployee ? $queueUrl : null],
                    ],
                    $selectedEmployee ? [['label' => $selectedEmployee->nama_lengkap]] : []
                )" />
            </div>

            @if ($selectedEmployee)
                <div class="flex shrink-0 items-center gap-3">
                    <x-ui.button
                        as="a"
                        href="{{ $queueUrl }}"
                        variant="secondary"
                    >
                        <svg class="h-4 w-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        Kembali
                    </x-ui.button>
                </div>
            @endif
        </div>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <p class="font-bold">Periksa kembali isian Anda:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.alert variant="info" title="Informasi Saldo">
            Saldo merupakan hasil perhitungan baca-saja berdasarkan fakta pemakaian dari SIMPEG dan Cuti di Luar SIMPEG. Koreksi hanya dilakukan pada fakta Cuti di Luar SIMPEG secara append-only.
        </x-ui.alert>

        @if (! $selectedEmployee)
        <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="mb-4">
            <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
            <x-ui.filter-bar
                searchId="search-pegawai"
                searchName="search"
                :searchValue="$search"
                searchPlaceholder="Cari nama atau NIP"
                gridClass="grid-cols-1 sm:grid-cols-[16rem_18rem]"
                searchCols="col-span-1"
            >
                {{-- Filter Status Antrian --}}
                <div class="relative">
                    <x-form.select id="filter-status" name="status" onchange="this.form.submit()" size="md" aria-label="Filter status antrian">
                        <option value="semua_pegawai" @selected($status === 'semua_pegawai')>Semua Pegawai ({{ $statusCounts['semua_pegawai'] }})</option>
                        <option value="perlu_tindakan" @selected($status === 'perlu_tindakan')>Belum Ada Fakta ({{ $statusCounts['perlu_tindakan'] }})</option>
                        <option value="sudah_terdaftar" @selected($status === 'sudah_terdaftar')>Memiliki Fakta ({{ $statusCounts['sudah_terdaftar'] }})</option>
                    </x-form.select>
                </div>
            </x-ui.filter-bar>
        </form>

        <section aria-label="Daftar pegawai administrasi pemakaian cuti">
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="hidden overflow-x-auto md:block">
                    <x-ui.table caption="Antrian administrasi pemakaian cuti pegawai">
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="sm">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="sm">NIP</x-ui.table-th>
                                <x-ui.table-th padding="sm">Status</x-ui.table-th>
                                <x-ui.table-th padding="sm">Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse ($employeeRows as $row)
                                @php
                                    $workspaceTab = 'pendaftaran';
                                    $workspaceUrl = route('cuti.saldo.administrasi', array_filter([
                                        'status' => $status,
                                        'search' => $search,
                                        'pegawai' => $row['employee_id'],
                                        'tab' => $workspaceTab,
                                        'page_pegawai' => request('page_pegawai'),
                                        'per_page' => request('per_page'),
                                    ]));
                                    $manualUsageUrl = route('cuti.saldo.administrasi', array_filter([
                                        'status' => $status,
                                        'search' => $search,
                                        'pegawai' => $row['employee_id'],
                                        'tab' => 'manual',
                                        'page_pegawai' => request('page_pegawai'),
                                        'per_page' => request('per_page'),
                                    ]));
                                @endphp
                                <x-ui.table-row>
                                    <x-ui.table-td padding="sm" class="text-sm font-semibold text-ink">
                                        {{ $row['nama_lengkap'] }}
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-sm text-muted">
                                        {{ $row['nip'] }}
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <span class="text-xs font-semibold text-ink">{{ $statusLabels[$row['status_code']] }}</span>
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <div class="flex items-center justify-start gap-1.5">
                                            <x-ui.button
                                                as="a"
                                                :href="$workspaceUrl"
                                                variant="secondary"
                                                size="compact-icon"
                                                tooltip-position="top-end"
                                                title="Lihat ringkasan pemakaian"
                                                aria-label="Lihat ringkasan pemakaian"
                                            >
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                            </x-ui.button>
                                            @if ($canManageManual)
                                                <x-ui.button
                                                    as="a"
                                                    :href="$manualUsageUrl"
                                                    variant="secondary"
                                                    size="compact-icon"
                                                    tooltip-position="top-end"
                                                    title="Catat cuti eksternal"
                                                    aria-label="Catat cuti eksternal"
                                                >
                                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                                    </svg>
                                                </x-ui.button>
                                            @endif
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="4" align="center" padding="comfortable" class="py-8 text-muted">
                                        Tidak ada pegawai yang cocok dengan filter ini.
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>

                <div class="divide-y divide-border md:hidden">
                    @forelse ($employeeRows as $row)
                        <article class="space-y-3 px-5 py-4">
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h4 class="break-words text-sm font-semibold text-ink">{{ $row['nama_lengkap'] }}</h4>
                                    <p class="mt-1 text-xs text-muted">NIP {{ $row['nip'] }}</p>
                                </div>
                                <span class="shrink-0 text-right text-xs font-semibold text-ink">{{ $statusLabels[$row['status_code']] }}</span>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <a
                                    href="{{ route('cuti.saldo.administrasi', array_filter([
                                        'status' => $status,
                                        'search' => $search,
                                        'pegawai' => $row['employee_id'],
                                        'tab' => 'pendaftaran',
                                        'page_pegawai' => request('page_pegawai'),
                                        'per_page' => request('per_page'),
                                    ])) }}"
                                    x-bind:href="withActiveTab($el.getAttribute('href'), 'pendaftaran')"
                                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2 text-center text-sm font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                >
                                    Lihat ringkasan pemakaian
                                </a>
                                @if ($canManageManual)
                                    <a
                                        href="{{ route('cuti.saldo.administrasi', array_filter([
                                            'status' => $status,
                                            'search' => $search,
                                            'pegawai' => $row['employee_id'],
                                            'tab' => 'manual',
                                            'page_pegawai' => request('page_pegawai'),
                                            'per_page' => request('per_page'),
                                        ])) }}"
                                        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2 text-center text-sm font-semibold text-ink transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    >
                                        Catat cuti eksternal
                                    </a>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-muted">Tidak ada pegawai yang cocok dengan filter ini.</p>
                    @endforelse
                </div>

                {{-- TABLE FOOTER --}}
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                    <div class="flex items-center gap-3 text-sm text-muted">
                        <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="flex items-center gap-2">
                            @if($search)
                                <input type="hidden" name="search" value="{{ $search }}">
                            @endif
                            @if($status)
                                <input type="hidden" name="status" value="{{ $status }}">
                            @endif

                            <span class="whitespace-nowrap">Tampilkan</span>
                            <label for="per_page" class="sr-only">Jumlah baris per halaman</label>
                            <select
                                id="per_page"
                                name="per_page"
                                onchange="this.form.submit()"
                                class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center"
                            >
                                @foreach ([10, 25, 50] as $opsi)
                                    <option value="{{ $opsi }}" @selected((int) request('per_page', 10) === $opsi)>{{ $opsi }}</option>
                                @endforeach
                            </select>
                            <span class="hidden sm:inline">data</span>
                        </form>

                        {{-- Meta Info --}}
                        <div class="hidden md:block ml-2 border-l border-border pl-4">
                            Menampilkan <span class="font-medium text-ink">{{ $employeeRows->firstItem() ?? 0 }}</span>
                            - <span class="font-medium text-ink">{{ $employeeRows->lastItem() ?? 0 }}</span>
                            dari <span class="font-medium text-ink">{{ $employeeRows->total() }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5">
                        {{ $employeeRows->appends(request()->query())->links('vendor.pagination.simpeg') }}
                    </div>
                </div>
            </x-ui.card>
        </section>
        @else
        <div class="space-y-6">
            {{-- Card 1: Hasil Perhitungan Hak Cuti Tahunan (Full Width) --}}
            <x-ui.card>
                <div class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h3 class="text-base font-semibold text-ink font-sans">Hasil Perhitungan Hak Cuti Tahunan</h3>
                            <span class="inline-flex items-center rounded-md border border-border bg-soft px-2 py-0.5 text-xs font-semibold text-muted font-sans">
                                Tahun acuan: {{ $tahunAcuan }}
                            </span>
                        </div>
                        <p class="max-w-2xl text-xs leading-relaxed text-muted">
                            Nilai ini dihitung sistem dari pengajuan yang disetujui dan fakta Cuti di Luar SIMPEG yang tercatat.
                        </p>
                    </div>
                    <div class="flex items-center gap-2.5 rounded-md border border-border bg-soft py-1 pl-1.5 pr-3.5 shrink-0 self-start sm:self-center">
                        <div class="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-xs">
                            {{ mb_strtoupper(mb_substr($selectedEmployee->nama_lengkap, 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-ink leading-tight truncate max-w-[180px] sm:max-w-[260px]" title="{{ $selectedEmployee->nama_lengkap }}">{{ $selectedEmployee->nama_lengkap }}</p>
                            <p class="mt-0.5 text-xs font-mono text-muted leading-tight">NIP {{ $selectedEmployee->nip }}</p>
                        </div>
                    </div>
                </div>

                @if ($rule5Active)
                    <x-ui.alert variant="warning" class="mt-4">
                        <p class="font-semibold">Bucket saldo tercatat untuk riwayat administratif</p>
                        <p>Hak efektif Cuti Tahunan tahun ini adalah 0 karena Cuti Besar telah disetujui.</p>
                    </x-ui.alert>
                @endif

                @if ($selectedBalance)
                    <section class="mt-5" aria-labelledby="ringkasan-ketersediaan-title">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                            <h4 id="ringkasan-ketersediaan-title" class="text-xs font-bold uppercase tracking-wider text-muted font-sans">
                                Ringkasan ketersediaan
                            </h4>
                            <p class="text-xs text-muted">Seluruh angka bersifat baca-saja dan dihitung oleh sistem.</p>
                        </div>
                        <dl class="mt-2.5 grid grid-cols-1 overflow-hidden rounded-xl border border-border bg-surface sm:grid-cols-2 lg:grid-cols-5 divide-y divide-border sm:divide-y-0 sm:divide-x">
                            @foreach ($availabilityMetrics as $metric)
                                <div @class([
                                    'p-4 transition-colors',
                                    'bg-primary/[0.04] ring-1 ring-inset ring-primary/20 relative' => $metric['key'] === 'saldo_dapat_diajukan',
                                ])>
                                    <dt @class([
                                        'text-xs font-medium leading-snug',
                                        'text-primary font-semibold' => $metric['key'] === 'saldo_dapat_diajukan',
                                        'text-muted' => $metric['key'] !== 'saldo_dapat_diajukan',
                                    ])>{{ $metric['label'] }}</dt>
                                    <dd @class([
                                        'mt-2 text-xl font-extrabold tracking-tight',
                                        'text-primary' => $metric['key'] === 'saldo_dapat_diajukan',
                                        'text-ink' => $metric['key'] !== 'saldo_dapat_diajukan',
                                    ]) data-balance-value="{{ $metric['key'] }}">{{ $balanceSummary[$metric['key']] }} hari</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="mt-5" aria-labelledby="rincian-bucket-title">
                        <h4 id="rincian-bucket-title" class="text-xs font-bold uppercase tracking-wider text-muted font-sans">
                            Rincian Sisa Hak per Tahun
                        </h4>
                        <dl class="mt-2.5 grid grid-cols-1 overflow-hidden rounded-xl border border-border bg-soft/30 sm:grid-cols-2 lg:grid-cols-5 divide-y divide-border sm:divide-y-0 sm:divide-x">
                            @foreach ($bucketCards as $label => $value)
                                <div class="p-3.5">
                                    <dt class="text-xs font-medium leading-snug text-muted">{{ $label }}</dt>
                                    <dd class="mt-1.5 text-lg font-bold text-ink tracking-tight">{{ (int) $value }} hari</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @else
                    <x-ui.alert variant="info" class="mt-4">
                        Perhitungan saldo belum tersedia. Saldo akan dihitung dari pengajuan SIMPEG yang disetujui dan fakta Cuti di Luar SIMPEG yang tercatat.
                    </x-ui.alert>
                @endif
            </x-ui.card>

            {{-- Filter Kategori Administrasi (Bar Filter Horizontal) --}}
            <div class="rounded-xl border border-border bg-surface p-3 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2 px-1 text-muted">
                        <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                        </svg>
                        <span class="text-xs font-bold uppercase tracking-wider font-sans">Kategori Administrasi</span>
                    </div>

                    <x-ui.tabs
                        variant="pills"
                        label="Kategori administrasi pemakaian cuti"
                        aria-orientation="horizontal"
                        class="flex flex-wrap items-center gap-2"
                        x-on:keydown.arrow-right.prevent="focusTab(1)"
                        x-on:keydown.arrow-left.prevent="focusTab(-1)"
                        x-on:keydown.home.prevent="activateTab(tabs[0])"
                        x-on:keydown.end.prevent="activateTab(tabs[tabs.length - 1])"
                    >
                        <x-ui.tab
                            variant="pills"
                            active="activeTab === 'pendaftaran'"
                            click="selectTab('pendaftaran')"
                            id="tab-pendaftaran"
                            aria-controls="panel-pendaftaran"
                            x-bind:tabindex="activeTab === 'pendaftaran' ? 0 : -1"
                            class="inline-flex items-center gap-2 font-medium"
                        >
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                            </svg>
                            <span>Ringkasan Pemakaian Tahunan</span>
                        </x-ui.tab>

                        <x-ui.tab
                            variant="pills"
                            active="activeTab === 'manual'"
                            :href="$manualTabHref"
                            :click="$manualTabClick"
                            id="tab-manual"
                            aria-controls="panel-manual"
                            x-bind:tabindex="activeTab === 'manual' ? 0 : -1"
                            class="inline-flex items-center gap-2 font-medium"
                        >
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                            </svg>
                            <span>Cuti di Luar SIMPEG</span>
                        </x-ui.tab>

                        <x-ui.tab
                            variant="pills"
                            active="activeTab === 'riwayat'"
                            click="selectTab('riwayat')"
                            id="tab-riwayat"
                            aria-controls="panel-riwayat"
                            x-bind:tabindex="activeTab === 'riwayat' ? 0 : -1"
                            class="inline-flex items-center gap-2 font-medium"
                        >
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                            </svg>
                            <span>Ledger &amp; Rollover</span>
                        </x-ui.tab>
                    </x-ui.tabs>
                </div>
            </div>

                <section
                    x-show="activeTab === 'pendaftaran'"
                    id="panel-pendaftaran"
                    role="tabpanel"
                    aria-labelledby="tab-pendaftaran"
                    tabindex="0"
                    class="space-y-4 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                >
                    <x-ui.card>
                        <div class="border-b border-border pb-4">
                            <h3 class="text-base font-semibold text-ink font-sans">Ringkasan Pemakaian Tahunan</h3>
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                Ringkasan ini hanya membaca fakta pemakaian aktif dari pengajuan yang disetujui di SIMPEG dan Cuti di Luar SIMPEG. Tidak ada input total tahunan langsung.
                            </p>
                        </div>

                        <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="Ringkasan pemakaian tahunan baca-saja">
                            @foreach ([
                                ['label' => sprintf('Pemakaian tahun %d', $tahunAcuan - 2), 'badge' => 'N-2', 'val' => $usage['n2']],
                                ['label' => sprintf('Pemakaian tahun %d', $tahunAcuan - 1), 'badge' => 'N-1', 'val' => $usage['n1']],
                                ['label' => sprintf('Pemakaian tahun %d', $tahunAcuan), 'badge' => 'Tahun Berjalan', 'val' => $usage['current']],
                            ] as $item)
                                <div class="rounded-xl border border-border bg-surface p-4 shadow-sm transition hover:border-primary/30">
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-xs font-bold uppercase tracking-wide text-muted font-sans">{{ $item['label'] }}</dt>
                                        <span class="rounded-md border border-border bg-soft px-1.5 py-0.5 text-xs font-semibold text-muted font-mono">
                                            {{ $item['badge'] }}
                                        </span>
                                    </div>
                                    <dd class="mt-3 flex items-baseline gap-1.5">
                                        <span class="text-2xl font-extrabold tracking-tight text-ink">{{ $item['val'] }}</span>
                                        <span class="text-xs font-medium text-muted">hari</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-ui.card>
                </section>

                <section
                    x-show="activeTab === 'riwayat'"
                    style="display: none;"
                    id="panel-riwayat"
                    role="tabpanel"
                    aria-labelledby="tab-riwayat"
                    tabindex="0"
                    class="space-y-4 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                >
                    <x-ui.alert variant="info">
                        <div>
                            <p class="font-semibold text-sm">Riwayat Saldo</p>
                            <p class="mt-0.5 text-xs text-muted">
                                Koreksi atau pembatalan dilakukan per fakta Cuti di Luar SIMPEG secara append-only pada tab tersebut. Ringkasan tahunan tidak dapat diedit langsung.
                            </p>
                        </div>
                    </x-ui.alert>

                    <x-ui.card>
                        <div class="flex items-center justify-between border-b border-border pb-3">
                            <div>
                                <h3 class="text-base font-semibold text-ink font-sans">Status Rollover</h3>
                                <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">Riwayat rollover bersifat baca-saja dan dijalankan oleh scheduler.</p>
                            </div>
                            <span class="rounded-md border border-border bg-soft px-2 py-0.5 text-xs font-semibold text-muted font-mono">{{ $periode }}</span>
                        </div>
                        <div class="mt-4 space-y-3">
                            @forelse ($rolloverRows as $rollover)
                                <div class="rounded-xl border border-border bg-soft/20 p-3.5 transition hover:bg-soft/40">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-xs font-bold text-ink">{{ $rollover->event_type }}</p>
                                        <span class="rounded bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary font-mono">{{ $rollover->tahun }}</span>
                                    </div>
                                    <p class="mt-1.5 text-xs text-muted leading-relaxed">{{ $rollover->reason }}</p>
                                </div>
                            @empty
                                <div class="flex items-center gap-3 rounded-xl border border-dashed border-border p-4 text-xs text-muted">
                                    <svg class="h-4 w-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    <span>Belum ada riwayat rollover untuk pegawai ini pada {{ $periode }}.</span>
                                </div>
                            @endforelse
                        </div>
                    </x-ui.card>

                    <x-ui.card padding="none" class="overflow-hidden">
                        <div class="border-b border-border px-5 py-4">
                            <h3 class="text-base font-semibold text-ink font-sans">Ledger Saldo</h3>
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">Buku besar append-only untuk saldo pegawai terpilih.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <x-ui.table>
                                <x-ui.table-head>
                                    <x-ui.table-row>
                                        <x-ui.table-th padding="sm">Tanggal</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Event</x-ui.table-th>
                                        <x-ui.table-th padding="sm" align="right">Delta</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Tahun sumber</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Alasan</x-ui.table-th>
                                    </x-ui.table-row>
                                </x-ui.table-head>
                                <x-ui.table-body>
                                    @forelse ($ledgerRows as $ledger)
                                        <x-ui.table-row>
                                            <x-ui.table-td padding="sm" class="text-xs text-muted font-mono">
                                                {{ optional($ledger->occurred_at)->format('d/m/Y H:i') }}
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="text-xs font-semibold text-ink">{{ $ledger->event_type }}</x-ui.table-td>
                                            <x-ui.table-td align="right" padding="sm" class="text-xs font-bold font-mono text-primary">{{ $ledger->amount }}</x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="text-xs text-muted font-mono">{{ $ledger->source_year }}</x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="text-xs text-muted">{{ $ledger->reason }}</x-ui.table-td>
                                        </x-ui.table-row>
                                    @empty
                                        <x-ui.table-row>
                                            <x-ui.table-td padding="sm" align="center" colspan="5" class="py-8 text-xs text-muted">
                                                Belum ada mutasi saldo untuk pegawai ini pada {{ $periode }}.
                                            </x-ui.table-td>
                                        </x-ui.table-row>
                                    @endforelse
                                </x-ui.table-body>
                            </x-ui.table>
                        </div>
                        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-soft/20 px-6 py-4 sm:flex-row">
                            <div class="flex items-center gap-3 text-sm text-muted">
                                <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="flex items-center gap-2">
                                    <input type="hidden" name="pegawai" value="{{ $selectedEmployee->id }}">
                                    <input type="hidden" name="status" value="{{ $status }}">
                                    <input type="hidden" name="search" value="{{ $search }}">
                                    <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                                    <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                                    <input type="hidden" name="page_pegawai" value="{{ request('page_pegawai') }}">
                                    <input type="hidden" name="source_type" value="{{ $usageFilters['source_type'] }}">
                                    <input type="hidden" name="record_status" value="{{ $usageFilters['record_status'] }}">
                                    <input type="hidden" name="usage_year" value="{{ $usageFilters['usage_year'] }}">
                                    <input type="hidden" name="leave_type" value="{{ $usageFilters['leave_type'] }}">
                                    <input type="hidden" name="sort" value="{{ $usageFilters['sort'] }}">
                                    <input type="hidden" name="direction" value="{{ $usageFilters['direction'] }}">
                                    <input type="hidden" name="per_page_usage" value="{{ $usageFilters['per_page_usage'] }}">
                                    <input type="hidden" name="page_usage" value="{{ request('page_usage') }}">

                                    <span class="whitespace-nowrap">Tampilkan</span>
                                    <label for="ledger-per-page" class="sr-only">Jumlah mutasi saldo per halaman</label>
                                    <select
                                        id="ledger-per-page"
                                        name="per_page_ledger"
                                        onchange="this.form.submit()"
                                        class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-center text-sm text-ink font-sans cursor-pointer focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    >
                                        @foreach ([10, 25, 50] as $value)
                                            <option value="{{ $value }}" @selected($ledgerPerPage === $value)>{{ $value }}</option>
                                        @endforeach
                                    </select>
                                    <span class="hidden sm:inline">data</span>
                                </form>

                                <div class="hidden border-l border-border pl-4 md:block">
                                    Menampilkan <span class="font-medium text-ink">{{ $ledgerRows->firstItem() ?? 0 }}</span>
                                    - <span class="font-medium text-ink">{{ $ledgerRows->lastItem() ?? 0 }}</span>
                                    dari <span class="font-medium text-ink">{{ $ledgerRows->total() }}</span>
                                </div>
                            </div>

                            @if ($ledgerRows->hasPages())
                                <div x-ref="ledgerPaginator" class="flex items-center gap-1.5">
                                    {{ $ledgerRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                </section>

                <section
                    x-show="activeTab === 'manual'"
                    style="display: none;"
                    id="panel-manual"
                    role="tabpanel"
                    aria-labelledby="tab-manual"
                    tabindex="0"
                    class="rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                >
                    @if (! $manualEditorActive)
                    <x-ui.card>
                        <div class="border-b border-border pb-4">
                            <h3 id="manual-usage-title" class="text-base font-semibold text-ink font-sans">Catat Cuti di Luar SIMPEG</h3>
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                Catat keputusan cuti yang sudah berlaku di luar sistem. Jumlah hari kerja dihitung ulang oleh server; dokumen pendukung dapat dilampirkan secara privat bila tersedia.
                            </p>
                        </div>

                        @if (! $canManageManual)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Anda tidak memiliki hak untuk mengelola cuti yang diproses di luar SIMPEG.
                            </div>
                        @elseif ($manualWorkspaceActive)
                            <form
                                method="POST"
                                action="{{ route('cuti.manual.store', $selectedEmployee->id) }}"
                                enctype="multipart/form-data"
                                class="mt-5 space-y-4"
                                x-data="{ submitting: false }"
                                x-on:submit="submitting = true"
                            >
                                @csrf
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label for="manual-leave-type" class="mb-1 block text-sm font-medium text-ink">Jenis cuti <span class="text-danger">*</span></label>
                                        <x-form.select id="manual-leave-type" name="leave_type_id" class="min-h-11" required>
                                            <option value="">Pilih jenis cuti</option>
                                            @foreach ($leaveTypeOptions as $option)
                                                <option value="{{ $option['id'] }}" data-code="{{ $option['code'] }}" @selected(old('leave_type_id') === $option['id'])>
                                                    {{ $option['nama'] }}
                                                </option>
                                            @endforeach
                                        </x-form.select>
                                        @error('leave_type_id')
                                            <p class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="manual-leave-case" class="mb-1 block text-sm font-medium text-ink">Kelompok Pengajuan Cuti (opsional)</label>
                                        <x-form.select id="manual-leave-case" name="leave_request_case_id" :value="old('leave_request_case_id')" class="min-h-11">
                                            <option value="">Buat baru atau pilih kelompok pengajuan cuti</option>
                                            @foreach ($manualLeaveCaseOptions as $option)
                                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </x-form.select>
                                        <p class="mt-1 text-xs text-muted">Hanya kelompok pengajuan cuti aktif milik pegawai ini yang dapat dipilih.</p>
                                    </div>
                                    <x-form.input
                                        name="tanggal_mulai"
                                        label="Tanggal mulai"
                                        type="date"
                                        :value="old('tanggal_mulai')"
                                        class="min-h-11"
                                        required
                                    />
                                    <x-form.input
                                        name="tanggal_selesai"
                                        label="Tanggal selesai"
                                        type="date"
                                        :value="old('tanggal_selesai')"
                                        class="min-h-11"
                                        required
                                    />
                                </div>

                                <x-form.textarea
                                    name="alasan"
                                    label="Alasan atau keterangan administratif"
                                    rows="3"
                                    :value="old('alasan')"
                                    maxlength="2000"
                                    required
                                />
                                <x-form.input
                                    id="manual-approval-document-number"
                                    name="approval_document_number"
                                    label="Nomor dokumen persetujuan (opsional)"
                                    :value="old('approval_document_number')"
                                    maxlength="255"
                                    help="Contoh: nomor SK atau surat keputusan. Boleh dikosongkan bila tidak tersedia."
                                    class="min-h-11"
                                />
                                @include('admin.cuti.partials.manual-external-form', [
                                    'editorId' => 'manual-approval',
                                    'allowCurrentPreview' => true,
                                ])
                                <x-form.input
                                    name="dokumen"
                                    label="Dokumen pendukung privat (opsional)"
                                    type="file"
                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                    help="Bila ada: PDF, DOC, DOCX, JPG, JPEG, atau PNG; maksimum 10 MB."
                                    class="min-h-11 max-w-md"
                                />

                                <x-ui.button
                                    type="submit"
                                    variant="primary"
                                    ::disabled="submitting"
                                    class="w-full sm:w-auto"
                                >
                                    <span x-show="!submitting">Simpan Cuti Eksternal</span>
                                    <span x-cloak x-show="submitting" role="status">Menyimpan…</span>
                                </x-ui.button>
                            </form>
                        @endif
                    </x-ui.card>
                    @else
                        <x-ui.card>
                            <p class="text-sm text-muted" role="status">
                                Editor versi cuti eksternal aktif dibuka pada bagian bawah halaman.
                            </p>
                        </x-ui.card>
                    @endif
                </section>

                <section aria-labelledby="usage-history-title">
                    <x-ui.card padding="none" class="overflow-hidden">
                        <div class="border-b border-border px-5 py-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2.5">
                                        <h3 id="usage-history-title" class="text-base font-semibold text-ink font-sans">Riwayat Fakta Pemakaian</h3>
                                    </div>
                                    <p class="text-xs leading-relaxed text-muted">Semua versi fakta ditampilkan agar catatan pemakaian tahunan, pengajuan SIMPEG, dan keputusan eksternal dapat ditelusuri.</p>
                                </div>
                            </div>

                            @if ($manualEditorActive)
                                <p class="mt-4 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-xs text-ink" role="status">
                                    Editor versi aktif dibuka di bawah. Tutup editor untuk kembali ke filter dan tabel histori.
                                </p>
                            @else
                            <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2 xl:grid-cols-4">
                                <input type="hidden" name="pegawai" value="{{ $selectedEmployee->id }}">
                                <input type="hidden" name="status" value="{{ $status }}">
                                <input type="hidden" name="search" value="{{ $search }}">
                                <input type="hidden" name="tab" value="{{ $tab }}">
                                <input type="hidden" name="page_pegawai" value="{{ request('page_pegawai') }}">
                                <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">

                                <div>
                                    <label for="usage-source-filter" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Sumber fakta</label>
                                    <x-form.select id="usage-source-filter" name="source_type" class="min-h-11">
                                        <option value="">Semua sumber</option>
                                        @foreach ($sourceLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($usageFilters['source_type'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-status-filter" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Status versi</label>
                                    <x-form.select id="usage-status-filter" name="record_status" class="min-h-11">
                                        <option value="">Semua status</option>
                                        @foreach ($recordStatusLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($usageFilters['record_status'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-year-filter" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Tahun pemakaian</label>
                                    <input
                                        id="usage-year-filter"
                                        name="usage_year"
                                        type="number"
                                        min="1900"
                                        max="2100"
                                        value="{{ $usageFilters['usage_year'] }}"
                                        inputmode="numeric"
                                        class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    >
                                </div>
                                <div>
                                    <label for="usage-type-filter" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Jenis cuti</label>
                                    <x-form.select id="usage-type-filter" name="leave_type" class="min-h-11">
                                        <option value="">Semua jenis</option>
                                        @foreach ($leaveTypeOptions as $option)
                                            <option value="{{ $option['id'] }}" @selected($usageFilters['leave_type'] === $option['id'])>{{ $option['nama'] }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-sort-filter" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Urutkan</label>
                                    <x-form.select id="usage-sort-filter" name="sort" class="min-h-11">
                                        @foreach ([
                                            'effective_date' => 'Tanggal efektif',
                                            'created_at' => 'Waktu pencatatan',
                                            'usage_year' => 'Tahun pemakaian',
                                            'workdays' => 'Jumlah hari',
                                        ] as $value => $label)
                                            <option value="{{ $value }}" @selected($usageFilters['sort'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-direction-filter" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Arah urutan</label>
                                    <x-form.select id="usage-direction-filter" name="direction" class="min-h-11">
                                        <option value="desc" @selected($usageFilters['direction'] === 'desc')>Terbaru/terbesar dulu</option>
                                        <option value="asc" @selected($usageFilters['direction'] === 'asc')>Terlama/terkecil dulu</option>
                                    </x-form.select>
                                </div>
                                <div class="flex items-end gap-2">
                                    <x-ui.button type="submit" variant="primary" class="min-h-11">Terapkan</x-ui.button>
                                    <x-ui.button
                                        as="a"
                                        variant="ghost"
                                        class="min-h-11"
                                        href="{{ route('cuti.saldo.administrasi', array_filter([
                                            'pegawai' => $selectedEmployee->id,
                                            'status' => $status,
                                            'search' => $search,
                                            'tab' => $tab,
                                            'page_pegawai' => request('page_pegawai'),
                                        ], static fn ($value) => $value !== null && $value !== '')) }}"
                                    >
                                        Reset
                                    </x-ui.button>
                                </div>
                            </form>
                            @endif
                        </div>

                        @if (! $manualEditorActive)
                        <div id="usage-history" class="overflow-x-auto">
                            <x-ui.table caption="Riwayat fakta pemakaian cuti pegawai terpilih">
                                <x-ui.table-head>
                                    <x-ui.table-row>
                                        <x-ui.table-th padding="sm">Tanggal / tahun</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Jenis dan sumber</x-ui.table-th>
                                        <x-ui.table-th padding="sm" align="right">Hari</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Status</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Keterangan</x-ui.table-th>
                                        <x-ui.table-th padding="sm">Dokumen / tindakan</x-ui.table-th>
                                    </x-ui.table-row>
                                </x-ui.table-head>
                                <x-ui.table-body :divided="false">
                                    @forelse ($usageRows as $usageRow)
                                        @php
                                            $isManual = $usageRow->source_type === 'manual_external';
                                            $isActiveManual = $isManual && $usageRow->record_status === 'active';
                                            $statusVariant = match ($usageRow->record_status) {
                                                'active' => 'success',
                                                'superseded' => 'muted',
                                                'cancelled' => 'danger',
                                                default => 'muted',
                                            };
                                        @endphp
                                        <x-ui.table-row>
                                            <x-ui.table-td padding="sm">
                                                @if ($usageRow->start_date && $usageRow->end_date)
                                                    <p class="font-semibold text-ink">{{ $usageRow->start_date->format('d/m/Y') }}</p>
                                                    <p class="mt-0.5 text-xs text-muted">s.d. {{ $usageRow->end_date->format('d/m/Y') }}</p>
                                                @else
                                                    <p class="font-semibold text-ink">Tahun {{ $usageRow->usage_year }}</p>
                                                    <p class="mt-0.5 text-xs text-muted">Cutoff {{ $usageRow->effective_date->format('d/m/Y') }}</p>
                                                @endif
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm">
                                                <p class="font-semibold text-ink">{{ $usageRowsUseScalarType ? ($usageRow->getAttribute('workspace_usage_type_name') ?? 'Jenis tidak tersedia') : ($usageRow->jenisCuti?->nama ?? 'Jenis tidak tersedia') }}</p>
                                                <p class="mt-0.5 text-xs text-muted">{{ $sourceLabels[$usageRow->source_type] ?? $usageRow->source_type }}</p>
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" align="right" class="text-sm font-bold font-mono text-ink">
                                                <span>{{ $usageRow->workdays }}</span>
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm">
                                                <span class="text-xs font-semibold text-ink">{{ $recordStatusLabels[$usageRow->record_status] ?? $usageRow->record_status }}</span>
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="max-w-xs text-muted">
                                                <p class="whitespace-normal break-words text-xs text-ink leading-relaxed">{{ $usageRow->administrative_note }}</p>
                                                @if ($usageRow->correction_reason)
                                                    <p class="mt-1 whitespace-normal break-words text-xs text-muted"><span class="font-semibold text-ink">Alasan perubahan:</span> {{ $usageRow->correction_reason }}</p>
                                                @endif
                                                @include('admin.cuti.partials.manual-external-chain-history', ['usageRow' => $usageRow])
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="min-w-60">
                                                @if ($canManageManual && $isManual)
                                                    <div class="space-y-1">
                                                        @forelse ($usageRow->documents as $document)
                                                            <a
                                                                href="{{ route('cuti.manual.download', [$usageRow->id, $document->id]) }}"
                                                                class="block break-words text-xs font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                                                            >
                                                                Unduh {{ $document->original_name }}
                                                            </a>
                                                        @empty
                                                            <span class="text-xs text-muted">Dokumen tidak tersedia.</span>
                                                        @endforelse
                                                    </div>
                                                @elseif ($isManual)
                                                    <span class="text-xs text-muted">Dokumen dibatasi untuk pengelola cuti manual.</span>
                                                @else
                                                    <span class="text-xs text-muted">Tidak ada dokumen manual.</span>
                                                @endif

                                                @if ($canManageManual && $isActiveManual)
                                                    <a
                                                        href="{{ route('cuti.saldo.administrasi', array_merge(request()->query(), ['tab' => 'manual', 'edit_usage' => $usageRow->id, 'manual_action' => 'correct'])) }}#manual-version-form"
                                                        class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 font-semibold text-primary transition hover:bg-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                                                    >
                                                        Kelola versi
                                                    </a>
                                                @endif
                                            </x-ui.table-td>
                                        </x-ui.table-row>
                                    @empty
                                        <x-ui.table-row>
                                            <x-ui.table-td colspan="6" align="center" class="py-8 text-sm text-muted">
                                                Belum ada fakta pemakaian yang cocok dengan filter ini.
                                            </x-ui.table-td>
                                        </x-ui.table-row>
                                    @endforelse
                                </x-ui.table-body>
                            </x-ui.table>
                        </div>

                        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-soft/20 px-6 py-4 sm:flex-row">
                            <div class="flex items-center gap-3 text-sm text-muted">
                                <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="flex items-center gap-2">
                                    <input type="hidden" name="pegawai" value="{{ $selectedEmployee->id }}">
                                    <input type="hidden" name="status" value="{{ $status }}">
                                    <input type="hidden" name="search" value="{{ $search }}">
                                    <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                                    <input type="hidden" name="page_pegawai" value="{{ request('page_pegawai') }}">
                                    <input type="hidden" name="source_type" value="{{ $usageFilters['source_type'] }}">
                                    <input type="hidden" name="record_status" value="{{ $usageFilters['record_status'] }}">
                                    <input type="hidden" name="usage_year" value="{{ $usageFilters['usage_year'] }}">
                                    <input type="hidden" name="leave_type" value="{{ $usageFilters['leave_type'] }}">
                                    <input type="hidden" name="sort" value="{{ $usageFilters['sort'] }}">
                                    <input type="hidden" name="direction" value="{{ $usageFilters['direction'] }}">

                                    <span class="whitespace-nowrap">Tampilkan</span>
                                    <label for="usage-per-page" class="sr-only">Jumlah fakta per halaman</label>
                                    <select
                                        id="usage-per-page"
                                        name="per_page_usage"
                                        onchange="this.form.submit()"
                                        class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-center text-sm text-ink font-sans cursor-pointer focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    >
                                        @foreach ([10, 25, 50] as $value)
                                            <option value="{{ $value }}" @selected($usageFilters['per_page_usage'] === $value)>{{ $value }}</option>
                                        @endforeach
                                    </select>
                                    <span class="hidden sm:inline">data</span>
                                </form>

                                <div class="hidden border-l border-border pl-4 md:block">
                                    Menampilkan <span class="font-medium text-ink">{{ $usageRows->firstItem() ?? 0 }}</span>
                                    - <span class="font-medium text-ink">{{ $usageRows->lastItem() ?? 0 }}</span>
                                    dari <span class="font-medium text-ink">{{ $usageRows->total() }}</span>
                                </div>
                            </div>

                            @if ($usageRows->hasPages())
                                <div x-ref="usagePaginator" class="flex items-center gap-1.5">
                                    {{ $usageRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                                </div>
                            @endif
                        </div>
                        @endif
                    </x-ui.card>
                </section>

                @if ($manualEditorActive)
                    @php
                        $editableTypeIncluded = $leaveTypeOptions->contains(
                            fn (array $option): bool => $option['id'] === $editableUsage->leave_type_id,
                        );
                    @endphp
                    <section
                        x-show="activeTab === 'manual'"
                        id="manual-version-form"
                        aria-labelledby="manual-version-title"
                        class="scroll-mt-6"
                    >
                        <x-ui.card>
                            <div class="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">Versi manual aktif</p>
                                    <h3 id="manual-version-title" class="mt-1 text-sm font-semibold text-ink">
                                        {{ $manualAction === 'cancel' ? 'Batalkan fakta pemakaian' : 'Perbaiki fakta pemakaian' }}
                                    </h3>
                                    <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                        @if ($manualAction === 'correct')
                                            Perbaikan membuat jejak versi baru. Versi lama tetap tersimpan; dokumen pendukung baru dapat dilampirkan bila tersedia.
                                        @else
                                            Pembatalan hanya mengubah status fakta aktif. Snapshot persetujuan dan dokumen historis tetap utuh.
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a
                                        href="{{ route('cuti.saldo.administrasi', array_merge(request()->query(), ['tab' => 'manual', 'manual_action' => $manualAction === 'correct' ? 'cancel' : 'correct'])) }}#manual-version-form"
                                        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    >
                                        {{ $manualAction === 'correct' ? 'Buka pembatalan' : 'Buka perbaikan' }}
                                    </a>
                                    <a
                                        href="{{ route('cuti.saldo.administrasi', array_merge(request()->except(['edit_usage', 'manual_action']), ['tab' => 'manual'])) }}#usage-history-title"
                                        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-xs font-semibold text-ink transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    >
                                        Tutup editor
                                    </a>
                                </div>
                            </div>

                            <div class="mt-4">
                                @if ($manualAction === 'correct')
                                <form
                                    method="POST"
                                    action="{{ route('cuti.manual.correct', $editableUsage->id) }}"
                                    enctype="multipart/form-data"
                                    class="space-y-4"
                                    x-data="{ submitting: false }"
                                    x-on:submit="submitting = true"
                                >
                                    @csrf
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label for="correction-leave-type" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink">Jenis cuti <span class="text-danger">*</span></label>
                                            <x-form.select
                                                id="correction-leave-type"
                                                name="leave_type_id"
                                                :value="$editableUsage->leave_type_id"
                                                class="min-h-11"
                                                required
                                            >
                                                @if (! $editableTypeIncluded)
                                                    <option value="{{ $editableUsage->leave_type_id }}" data-code="{{ $editableUsage->jenisCuti?->code }}">
                                                        {{ $editableUsage->jenisCuti?->nama ?? 'Jenis cuti tersimpan' }}
                                                    </option>
                                                @endif
                                                @foreach ($leaveTypeOptions as $option)
                                                    <option value="{{ $option['id'] }}" data-code="{{ $option['code'] }}">{{ $option['nama'] }}</option>
                                                @endforeach
                                            </x-form.select>
                                        </div>

                                        <div>
                                            <label for="correction-case-id" class="mb-1 block text-xs font-bold uppercase tracking-wider text-ink">Kelompok Pengajuan Cuti (opsional)</label>
                                            <x-form.select id="correction-case-id" name="leave_request_case_id" :value="$editableUsage->leave_request_case_id" class="min-h-11">
                                                <option value="">Buat baru atau pilih kelompok pengajuan cuti</option>
                                                @foreach ($manualLeaveCaseOptions as $option)
                                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                @endforeach
                                            </x-form.select>
                                            <p class="mt-1 text-xs text-muted">Hanya kelompok pengajuan cuti aktif milik pegawai ini yang dapat dipilih.</p>
                                        </div>
                                        <x-form.input
                                            id="correction-start-date"
                                            name="tanggal_mulai"
                                            label="Tanggal mulai"
                                            type="date"
                                            :value="$editableUsage->start_date?->format('Y-m-d')"
                                            class="min-h-11"
                                            required
                                        />
                                        <x-form.input
                                            id="correction-end-date"
                                            name="tanggal_selesai"
                                            label="Tanggal selesai"
                                            type="date"
                                            :value="$editableUsage->end_date?->format('Y-m-d')"
                                            class="min-h-11"
                                            required
                                        />
                                    </div>

                                    <x-form.textarea
                                        id="correction-administrative-note"
                                        name="alasan"
                                        label="Alasan atau keterangan administratif"
                                        rows="3"
                                        :value="$editableUsage->administrative_note"
                                        maxlength="2000"
                                        required
                                    />
                                    <x-form.input
                                        id="correction-approval-document-number"
                                        name="approval_document_number"
                                        label="Nomor dokumen persetujuan (opsional)"
                                        :value="old('approval_document_number', $editableUsage->approval_document_number)"
                                        maxlength="255"
                                        help="Contoh: nomor SK atau surat keputusan. Boleh dikosongkan bila tidak tersedia."
                                        class="min-h-11"
                                    />
                                    @include('admin.cuti.partials.manual-external-form', [
                                        'editorId' => 'correction-approval',
                                        'allowCurrentPreview' => false,
                                    ])
                                    <x-form.textarea
                                        id="correction-reason"
                                        name="correction_reason"
                                        label="Alasan perbaikan"
                                        rows="3"
                                        maxlength="2000"
                                        help="Jelaskan mengapa fakta aktif harus digantikan."
                                        required
                                    />
                                    <x-form.input
                                        id="correction-document"
                                        name="dokumen"
                                        label="Dokumen bukti perbaikan (opsional)"
                                        type="file"
                                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                        help="Bila ada, dokumen baru disimpan privat; maksimum 10 MB."
                                        class="min-h-11"
                                    />

                                    <button
                                        type="submit"
                                        x-bind:disabled="submitting"
                                        class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                                    >
                                        <span x-show="!submitting">Simpan Perbaikan</span>
                                        <span x-cloak x-show="submitting" role="status">Menyimpan perbaikan…</span>
                                    </button>
                                </form>
                                @else
                                <div class="rounded-xl border border-danger/30 bg-danger/5 p-4">
                                    <h4 class="text-sm font-semibold text-ink font-sans">Batalkan versi aktif</h4>
                                    <p class="mt-1 text-xs leading-relaxed text-muted">
                                        Pembatalan tidak menghapus histori. Fakta ini akan diberi status dibatalkan dan tidak lagi mengurangi saldo.
                                    </p>
                                    <form
                                        method="POST"
                                        action="{{ route('cuti.manual.cancel', $editableUsage->id) }}"
                                        class="mt-4 space-y-4"
                                        x-data="{ submitting: false }"
                                        x-on:submit="submitting = true"
                                    >
                                        @csrf
                                        <x-form.textarea
                                            id="cancellation-reason"
                                            name="correction_reason"
                                            label="Alasan pembatalan"
                                            rows="4"
                                            maxlength="2000"
                                            required
                                        />
                                        <button
                                            type="submit"
                                            x-bind:disabled="submitting"
                                            class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-danger px-4 py-2.5 text-sm font-semibold text-danger transition hover:bg-danger/10 focus:outline-none focus:ring-2 focus:ring-danger/20 disabled:cursor-wait disabled:opacity-60"
                                        >
                                            <span x-show="!submitting">Batalkan Fakta</span>
                                            <span x-cloak x-show="submitting" role="status">Membatalkan…</span>
                                        </button>
                                    </form>
                                </div>
                                @endif
                            </div>
                        </x-ui.card>
                    </section>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>
