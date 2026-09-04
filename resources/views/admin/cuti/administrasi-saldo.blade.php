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
            'rekonsiliasi_belum_tercatat' => 'Pemakaian tahunan belum dicatat',
            'rekonsiliasi_aktif' => 'Pemakaian tahunan sudah dicatat',
        ];
        $sourceLabels = [
            'annual_reconciliation' => 'Catatan pemakaian tahunan',
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
        $hasActiveReconciliation = (bool) $balanceReconciliation['reconciled'];
        $isReconciled = (bool) $balanceReconciliation['current_year_reconciled'];
        $correctionYear = (int) ($balanceReconciliation['balance_year'] ?? $tahunAcuan);
        $manualEditorActive = (bool) ($canManageManual && $editableUsage);
        $availableTabs = $hasActiveReconciliation
            ? ['pendaftaran', 'manual', 'riwayat']
            : ['pendaftaran', 'manual'];
        $initialTab = $manualEditorActive
            ? 'manual'
            : (in_array($requestedTab, $availableTabs, true) ? $requestedTab : 'pendaftaran');
        $usage = $balanceReconciliation['usage'];
        $historyTabActive = $hasActiveReconciliation ? "activeTab === 'riwayat'" : 'false';
        $historyTabClick = $hasActiveReconciliation ? "selectTab('riwayat')" : null;
        $historyTabIndex = $hasActiveReconciliation ? "activeTab === 'riwayat' ? 0 : -1" : '-1';
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
            selectTab(tab) {
                this.activeTab = tab;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url);
                this.$nextTick(() => {
                    this.syncLedgerPaginatorTab();
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
        x-init="$nextTick(() => syncLedgerPaginatorTab())"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Administrasi Pemakaian Cuti</h2>
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

        <x-ui.alert variant="info">
            Saldo merupakan hasil perhitungan baca-saja berdasarkan fakta pemakaian. Perbaiki data pemakaian tahunan atau entri cuti manual bila sumber datanya berubah.
        </x-ui.alert>

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

        @if (! $selectedEmployee)
        <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="mb-4">
            <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
            <x-ui.filter-bar
                searchId="search-pegawai"
                searchName="search"
                :searchValue="$search"
                searchPlaceholder="Cari nama atau NIP"
                gridClass="grid-cols-1 sm:grid-cols-2"
                searchCols="col-span-1"
            >
                {{-- Filter Status Antrian --}}
                <div class="relative">
                    <x-form.select id="filter-status" name="status" onchange="this.form.submit()" size="md" aria-label="Filter status antrian">
                        <option value="semua_pegawai" @selected($status === 'semua_pegawai')>Semua Pegawai ({{ $statusCounts['semua_pegawai'] }})</option>
                        <option value="perlu_tindakan" @selected($status === 'perlu_tindakan')>Perlu Tindakan ({{ $statusCounts['perlu_tindakan'] }})</option>
                        <option value="sudah_terdaftar" @selected($status === 'sudah_terdaftar')>Sudah Terdaftar ({{ $statusCounts['sudah_terdaftar'] }})</option>
                    </x-form.select>
                </div>
            </x-ui.filter-bar>
        </form>

        <section aria-labelledby="antrian-pegawai-title">
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-5 py-4">
                    <h3 id="antrian-pegawai-title" class="text-sm font-semibold text-ink">Antrian Administrasi Pemakaian</h3>
                    <p class="mt-1 text-xs text-muted">Pilih satu pegawai untuk membuka workspace administrasi.</p>
                </div>

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
                                    $workspaceTab = $row['status_code'] === 'rekonsiliasi_aktif' ? $tab : 'pendaftaran';
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
                                                x-bind:href="withActiveTab(@js($workspaceUrl), @js($row['status_code'] === 'rekonsiliasi_aktif' ? null : 'pendaftaran') ?? activeTab)"
                                                variant="secondary"
                                                size="icon"
                                                tooltip-position="top-end"
                                                :title="$row['status_code'] === 'rekonsiliasi_aktif' ? 'Lihat data pemakaian' : 'Catat pemakaian tahunan'"
                                                :aria-label="$row['status_code'] === 'rekonsiliasi_aktif' ? 'Lihat data pemakaian' : 'Catat pemakaian tahunan'"
                                            >
                                                @if ($row['status_code'] === 'rekonsiliasi_aktif')
                                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                    </svg>
                                                @else
                                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                                    </svg>
                                                @endif
                                            </x-ui.button>
                                            @if ($canManageManual)
                                                <x-ui.button
                                                    as="a"
                                                    :href="$manualUsageUrl"
                                                    variant="secondary"
                                                    size="icon"
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
                                        'tab' => $row['status_code'] === 'rekonsiliasi_aktif' ? $tab : 'pendaftaran',
                                        'page_pegawai' => request('page_pegawai'),
                                        'per_page' => request('per_page'),
                                    ])) }}"
                                    x-bind:href="withActiveTab($el.getAttribute('href'), @js($row['status_code'] === 'rekonsiliasi_aktif' ? null : 'pendaftaran') ?? activeTab)"
                                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2 text-center text-sm font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                >
                                    {{ $row['status_code'] === 'rekonsiliasi_aktif' ? 'Lihat data pemakaian' : 'Catat pemakaian tahunan' }}
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
        <div class="flex flex-col gap-6 lg:flex-row">
            <aside class="w-full shrink-0 lg:w-64">
                <x-ui.card padding="sm" class="space-y-1">
                    <p class="mb-2 border-b border-border px-3 pb-2 text-xs font-bold uppercase tracking-wide text-muted font-sans">
                        Kategori Administrasi
                    </p>

                    <x-ui.tabs
                        variant="sidebar"
                        label="Kategori administrasi pemakaian cuti"
                        aria-orientation="vertical"
                        x-on:keydown.arrow-down.prevent="focusTab(1)"
                        x-on:keydown.arrow-up.prevent="focusTab(-1)"
                        x-on:keydown.home.prevent="activateTab(tabs[0])"
                        x-on:keydown.end.prevent="activateTab(tabs[tabs.length - 1])"
                    >
                        <x-ui.tab
                            variant="sidebar"
                            active="activeTab === 'pendaftaran'"
                            click="selectTab('pendaftaran')"
                            id="tab-pendaftaran"
                            aria-controls="panel-pendaftaran"
                            x-bind:tabindex="activeTab === 'pendaftaran' ? 0 : -1"
                            class="min-h-11 focus-visible:ring-2 focus-visible:ring-primary/40"
                        >
                            <span>Catat Pemakaian Tahunan</span>
                        </x-ui.tab>

                        <x-ui.tab
                            variant="sidebar"
                            active="activeTab === 'manual'"
                            :href="$manualTabHref"
                            :click="$manualTabClick"
                            id="tab-manual"
                            aria-controls="panel-manual"
                            x-bind:tabindex="activeTab === 'manual' ? 0 : -1"
                            class="min-h-11 focus-visible:ring-2 focus-visible:ring-primary/40"
                        >
                            <span>Cuti di Luar SIMPEG</span>
                        </x-ui.tab>

                        <x-ui.tab
                            variant="sidebar"
                            :active="$historyTabActive"
                            :click="$historyTabClick"
                            id="tab-riwayat"
                            aria-controls="panel-riwayat"
                            aria-disabled="{{ $hasActiveReconciliation ? 'false' : 'true' }}"
                            aria-describedby="{{ $hasActiveReconciliation ? '' : 'riwayat-locked-message' }}"
                            x-on:click.prevent="{{ $hasActiveReconciliation ? 'false' : 'true' }}"
                            x-bind:tabindex="{{ $historyTabIndex }}"
                            class="min-h-11 focus-visible:ring-2 focus-visible:ring-primary/40 {{ $hasActiveReconciliation ? '' : 'cursor-not-allowed opacity-50 hover:bg-transparent hover:text-muted' }}"
                        >
                            <span>Perbaiki Data Pemakaian</span>
                        </x-ui.tab>
                    </x-ui.tabs>
                    @if (! $hasActiveReconciliation)
                        <p id="riwayat-locked-message" class="mt-3 px-3 text-xs leading-relaxed text-muted">
                            Catat pemakaian tahunan pegawai terlebih dahulu sebelum membuka riwayat data pemakaian.
                        </p>
                    @endif
                </x-ui.card>
            </aside>

            <div class="min-w-0 flex-1 space-y-6">
                <x-ui.card>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">Hasil Perhitungan Hak Cuti Tahunan</h3>
                            <p class="mt-1 text-xs text-muted">Tahun acuan: {{ $tahunAcuan }}</p>
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                Nilai ini dihitung sistem dari catatan pemakaian tahunan, pengajuan yang disetujui, dan cuti yang dicatat di luar SIMPEG.
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-semibold text-primary">{{ $selectedEmployee->nama_lengkap }}</p>
                            <p class="mt-1 text-xs text-muted">NIP {{ $selectedEmployee->nip }}</p>
                        </div>
                    </div>

                    @if ($rule5Active)
                        <x-ui.alert variant="warning" class="mt-4">
                            <p class="font-semibold">Bucket saldo tercatat untuk riwayat administratif</p>
                            <p>Hak efektif Cuti Tahunan tahun ini adalah 0 karena Cuti Besar telah disetujui.</p>
                        </x-ui.alert>
                    @endif

                    @if ($selectedBalance)
                        <section class="mt-4" aria-labelledby="ringkasan-ketersediaan-title">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                <h4 id="ringkasan-ketersediaan-title" class="text-xs font-bold uppercase tracking-wide text-muted">
                                    Ringkasan ketersediaan
                                </h4>
                                <p class="text-xs text-muted">Seluruh angka bersifat baca-saja dan dihitung oleh sistem.</p>
                            </div>
                            <dl class="mt-2 grid overflow-hidden rounded-xl border border-border bg-surface sm:grid-cols-2 lg:grid-cols-5">
                                @foreach ($availabilityMetrics as $metric)
                                    <div @class([
                                        'border-b border-border p-3 last:border-b-0 sm:border-r lg:border-b-0 lg:last:border-r-0',
                                        'bg-primary/5 ring-1 ring-inset ring-primary/20' => $metric['key'] === 'saldo_dapat_diajukan',
                                    ])>
                                        <dt @class([
                                            'text-xs font-semibold leading-snug',
                                            'text-primary' => $metric['key'] === 'saldo_dapat_diajukan',
                                            'text-muted' => $metric['key'] !== 'saldo_dapat_diajukan',
                                        ])>{{ $metric['label'] }}</dt>
                                        <dd @class([
                                            'mt-1 text-lg font-bold',
                                            'text-primary' => $metric['key'] === 'saldo_dapat_diajukan',
                                            'text-ink' => $metric['key'] !== 'saldo_dapat_diajukan',
                                        ]) data-balance-value="{{ $metric['key'] }}">{{ $balanceSummary[$metric['key']] }} hari</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section class="mt-4" aria-labelledby="rincian-bucket-title">
                            <h4 id="rincian-bucket-title" class="text-xs font-bold uppercase tracking-wide text-muted">
                                Rincian Sisa Hak per Tahun
                            </h4>
                            <dl class="mt-2 grid overflow-hidden rounded-xl border border-border bg-soft/40 sm:grid-cols-2 lg:grid-cols-5">
                                @foreach ($bucketCards as $label => $value)
                                    <div class="border-b border-border p-3 last:border-b-0 sm:border-r lg:border-b-0 lg:last:border-r-0">
                                        <dt class="text-xs font-semibold leading-snug text-muted">{{ $label }}</dt>
                                        <dd class="mt-1 text-lg font-bold text-ink">{{ (int) $value }} hari</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @else
                        <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                            Perhitungan saldo belum tersedia. Catat pemakaian tiga tahun agar saldo dapat dihitung dari fakta pemakaian yang tercatat.
                        </div>
                    @endif
                </x-ui.card>

                <section
                    x-show="activeTab === 'pendaftaran'"
                    id="panel-pendaftaran"
                    role="tabpanel"
                    aria-labelledby="tab-pendaftaran"
                    tabindex="0"
                    class="space-y-4 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                >
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-ink">Catat Pemakaian Tahunan</h3>
                        @if ($isReconciled)
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                Snapshot pemakaian aktif sudah tercatat. Buka Perbaiki Data Pemakaian untuk mengganti tiga fakta secara utuh dan auditabel.
                            </p>
                        @else
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                Isi total hari cuti tahunan terpakai pada N-2, N-1, dan tahun berjalan berdasarkan dokumen administrasi.
                            </p>
                        @endif

                        @if (! $canReconcile)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Anda tidak memiliki hak untuk mencatat pemakaian cuti tahunan.
                            </div>
                        @elseif (! $isReconciled)
                            <form method="POST" action="{{ route('cuti.reconciliation.store', $selectedEmployee) }}" class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <input type="hidden" name="search" value="{{ $search }}">
                                <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                                <input type="hidden" name="page_pegawai" value="{{ request('page_pegawai') }}">
                                <input type="hidden" name="balance_year" value="{{ $tahunAcuan }}">

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <x-form.input
                                        name="usage_n2"
                                        label="Fakta pemakaian tahun {{ $tahunAcuan - 2 }}"
                                        type="number"
                                        min="0"
                                        size="lg"
                                        value="0"
                                        help="Total hari terpakai pada N-2."
                                        required
                                    />
                                    <x-form.input
                                        name="usage_n1"
                                        label="Fakta pemakaian tahun {{ $tahunAcuan - 1 }}"
                                        type="number"
                                        min="0"
                                        size="lg"
                                        value="0"
                                        help="Total hari terpakai pada N-1."
                                        required
                                    />
                                    <x-form.input
                                        name="usage_current"
                                        label="Fakta pemakaian tahun {{ $tahunAcuan }}"
                                        type="number"
                                        min="0"
                                        size="lg"
                                        value="0"
                                        help="Total hari terpakai tahun berjalan."
                                        required
                                    />
                                </div>

                                <x-form.textarea
                                    name="administrative_note"
                                    label="Keterangan atau sumber data"
                                    rows="3"
                                    placeholder="Contoh: Berdasarkan rekap pemakaian cuti tahunan."
                                    help="Keterangan wajib diisi dan tercatat pada audit log."
                                    required
                                />

                                <x-ui.button type="submit" class="w-full sm:w-auto">
                                    Simpan Pemakaian Tahunan
                                </x-ui.button>
                            </form>
                        @else
                            <div class="mt-5 space-y-4" aria-label="Fakta pemakaian tahunan baca-saja">
                                <div class="flex flex-col gap-3 rounded-xl border border-border bg-soft/40 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-muted">Status data pemakaian</p>
                                        <p class="mt-1 text-sm font-semibold text-ink">Aktif dan auditabel</p>
                                    </div>
                                    <div class="sm:text-right">
                                        <p class="text-xs font-bold uppercase tracking-wide text-muted">Sumber data</p>
                                        <p class="mt-1 text-sm font-semibold text-primary">Catatan pemakaian tahunan aktif</p>
                                    </div>
                                </div>

                                @if ($usage)
                                    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        @foreach ([
                                            sprintf('Fakta pemakaian tahun %d', $tahunAcuan - 2) => $usage['n2'],
                                            sprintf('Fakta pemakaian tahun %d', $tahunAcuan - 1) => $usage['n1'],
                                            sprintf('Fakta pemakaian tahun %d', $tahunAcuan) => $usage['current'],
                                        ] as $label => $value)
                                            <div class="rounded-xl border border-border bg-surface p-4">
                                                <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ $label }}</dt>
                                                <dd class="mt-1 text-xl font-bold text-ink">{{ $value ?? 'Tidak tersedia' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif

                                <dl class="grid gap-3 text-xs sm:grid-cols-2">
                                    <div>
                                        <dt class="font-semibold text-muted">Tercatat pada</dt>
                                        <dd class="mt-1 text-ink">{{ optional($balanceReconciliation['reconciled_at'])->format('d/m/Y') ?? 'Tidak tersedia' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-muted">Alasan atau sumber</dt>
                                        <dd class="mt-1 text-ink">{{ $balanceReconciliation['note'] ?? 'Catatan pemakaian cuti tahunan.' }}</dd>
                                    </div>
                                </dl>
                            </div>
                        @endif
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
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-ink">Perbaiki Data Pemakaian</h3>
                        <p class="mt-1 text-xs leading-relaxed text-muted">
                            Perbaikan mengganti data pemakaian tiga tahun dan memicu hitung ulang.
                        </p>

                        @if (! $hasActiveReconciliation)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Catat pemakaian tahunan pegawai sebelum memperbaiki data pemakaian.
                            </div>
                        @elseif (! $canReconcile)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Anda tidak memiliki hak untuk memperbaiki data pemakaian cuti.
                            </div>
                        @else
                            @if (count($balanceReconciliation['history']) > 0)
                                <div class="mt-4 space-y-2" aria-label="Riwayat dokumen perubahan privat">
                                    <p class="text-xs font-bold uppercase tracking-wide text-muted">Riwayat perubahan dan bukti</p>
                                    @foreach ($balanceReconciliation['history'] as $historyItem)
                                        <div class="rounded-lg border border-border p-3">
                                            <p class="text-xs font-semibold text-muted">
                                                Tahun {{ $historyItem['balance_year'] }} · {{ optional($historyItem['reconciled_at'])->format('d/m/Y') ?? 'Tanggal tidak tersedia' }}
                                            </p>
                                            @forelse ($historyItem['documents'] as $document)
                                                <a class="mt-1 block text-sm font-semibold text-primary hover:underline" href="{{ route('cuti.reconciliation.document.download', [$historyItem['id'], $document['id']]) }}">
                                                    Unduh bukti {{ $document['original_name'] }}
                                                </a>
                                            @empty
                                                <p class="mt-1 text-xs text-muted">Tidak ada dokumen pendukung.</p>
                                            @endforelse
                                        </div>
                                    @endforeach
                                    @if ($balanceReconciliation['history'] instanceof \Illuminate\Contracts\Pagination\Paginator)
                                        <div class="pt-2">
                                            {{ $balanceReconciliation['history']->links() }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                            <form method="POST" action="{{ route('cuti.reconciliation.correct', $balanceReconciliation['set_id']) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <input type="hidden" name="search" value="{{ $search }}">
                                <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                                <input type="hidden" name="page_pegawai" value="{{ request('page_pegawai') }}">
                                <input type="hidden" name="balance_year" value="{{ $correctionYear }}">

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <x-form.input
                                        name="usage_n2"
                                        label="Fakta pemakaian tahun {{ $correctionYear - 2 }}"
                                        type="number"
                                        min="0"
                                        size="lg"
                                        :value="$usage['n2']"
                                        required
                                    />
                                    <x-form.input
                                        name="usage_n1"
                                        label="Fakta pemakaian tahun {{ $correctionYear - 1 }}"
                                        type="number"
                                        min="0"
                                        size="lg"
                                        :value="$usage['n1']"
                                        required
                                    />
                                    <x-form.input
                                        name="usage_current"
                                        label="Fakta pemakaian tahun {{ $correctionYear }}"
                                        type="number"
                                        min="0"
                                        size="lg"
                                        :value="$usage['current']"
                                        required
                                    />
                                </div>

                                <x-form.textarea
                                    name="administrative_note"
                                    label="Keterangan atau sumber data"
                                    rows="3"
                                    :value="$balanceReconciliation['note']"
                                    required
                                />

                                <x-form.textarea
                                    name="correction_reason"
                                    label="Alasan perbaikan"
                                    rows="3"
                                    placeholder="Jelaskan alasan penggantian snapshot tiga tahun."
                                    required
                                />

                                <x-form.input
                                    name="dokumen"
                                    label="Dokumen bukti"
                                    type="file"
                                    size="lg"
                                    required
                                />

                                <x-ui.button type="submit" class="w-full sm:w-auto">
                                    Simpan Perbaikan
                                </x-ui.button>
                            </form>
                        @endif
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-ink">Status Rollover</h3>
                        <p class="mt-1 text-xs text-muted">Riwayat rollover bersifat baca-saja dan dijalankan oleh scheduler.</p>
                        <div class="mt-4 space-y-3">
                            @forelse ($rolloverRows as $rollover)
                                <div class="rounded-xl border border-border p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-xs font-semibold text-ink">{{ $rollover->event_type }}</p>
                                        <span class="text-xs text-muted">{{ $rollover->tahun }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-muted">{{ $rollover->reason }}</p>
                                </div>
                            @empty
                                <p class="rounded-xl border border-dashed border-border p-4 text-xs text-muted">Belum ada riwayat rollover untuk pegawai ini pada {{ $periode }}.</p>
                            @endforelse
                        </div>
                    </x-ui.card>

                    <x-ui.card padding="none" class="overflow-hidden">
                        <div class="border-b border-border px-5 py-4">
                            <h3 class="text-sm font-semibold text-ink">Ledger Saldo</h3>
                            <p class="mt-1 text-xs text-muted">Buku besar append-only untuk saldo pegawai terpilih.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <x-ui.table>
                                <x-ui.table-head>
                                    <x-ui.table-row>
                                        <x-ui.table-th>Tanggal</x-ui.table-th>
                                        <x-ui.table-th>Event</x-ui.table-th>
                                        <x-ui.table-th align="right">Delta</x-ui.table-th>
                                        <x-ui.table-th>Tahun sumber</x-ui.table-th>
                                        <x-ui.table-th>Alasan</x-ui.table-th>
                                    </x-ui.table-row>
                                </x-ui.table-head>
                                <x-ui.table-body>
                                    @forelse ($ledgerRows as $ledger)
                                        <x-ui.table-row>
                                            <x-ui.table-td padding="sm" class="text-xs text-muted">
                                                {{ optional($ledger->occurred_at)->format('d/m/Y H:i') }}
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="text-xs font-semibold text-ink">{{ $ledger->event_type }}</x-ui.table-td>
                                            <x-ui.table-td align="right" padding="sm" class="text-xs font-bold text-primary">{{ $ledger->amount }}</x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="text-xs text-muted">{{ $ledger->source_year }}</x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="text-xs text-muted">{{ $ledger->reason }}</x-ui.table-td>
                                        </x-ui.table-row>
                                    @empty
                                        <x-ui.table-row>
                                            <x-ui.table-td padding="sm" colspan="5" class="text-xs text-muted">
                                                Belum ada mutasi saldo untuk pegawai ini pada {{ $periode }}.
                                            </x-ui.table-td>
                                        </x-ui.table-row>
                                    @endforelse
                                </x-ui.table-body>
                            </x-ui.table>
                        </div>
                        @if ($ledgerRows->hasPages())
                            <div x-ref="ledgerPaginator" class="border-t border-border px-5 py-3">
                                {{ $ledgerRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                            </div>
                        @endif
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
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 id="manual-usage-title" class="text-sm font-semibold text-ink">Catat Cuti di Luar SIMPEG</h3>
                                <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                    Catat keputusan cuti yang sudah berlaku di luar sistem. Jumlah hari kerja dihitung ulang oleh server; dokumen pendukung dapat dilampirkan secara privat bila tersedia.
                                </p>
                            </div>
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
                                class="mt-4 space-y-4"
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
                                    class="min-h-11"
                                />

                                <x-ui.button
                                    type="submit"
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
                                <div>
                                    <h3 id="usage-history-title" class="text-sm font-semibold text-ink">Riwayat Fakta Pemakaian</h3>
                                    <p class="mt-1 text-xs text-muted">Semua versi fakta ditampilkan agar catatan pemakaian tahunan, pengajuan SIMPEG, dan keputusan eksternal dapat ditelusuri.</p>
                                </div>
                                <span class="text-xs font-semibold text-muted">{{ $usageRows->total() }} fakta</span>
                            </div>

                            @if ($manualEditorActive)
                                <p class="mt-4 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-xs text-ink" role="status">
                                    Editor versi aktif dibuka di bawah. Tutup editor untuk kembali ke filter dan tabel histori.
                                </p>
                            @else
                            <form method="GET" action="{{ route('cuti.saldo.administrasi') }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <input type="hidden" name="pegawai" value="{{ $selectedEmployee->id }}">
                                <input type="hidden" name="status" value="{{ $status }}">
                                <input type="hidden" name="search" value="{{ $search }}">
                                <input type="hidden" name="tab" value="{{ $tab }}">
                                <input type="hidden" name="page_pegawai" value="{{ request('page_pegawai') }}">
                                <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">

                                <div>
                                    <label for="usage-source-filter" class="mb-1 block text-xs font-semibold text-muted">Sumber fakta</label>
                                    <x-form.select id="usage-source-filter" name="source_type">
                                        <option value="">Semua sumber</option>
                                        @foreach ($sourceLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($usageFilters['source_type'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-status-filter" class="mb-1 block text-xs font-semibold text-muted">Status versi</label>
                                    <x-form.select id="usage-status-filter" name="record_status">
                                        <option value="">Semua status</option>
                                        @foreach ($recordStatusLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($usageFilters['record_status'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <x-form.input
                                    name="usage_year"
                                    label="Tahun pemakaian"
                                    type="number"
                                    min="1900"
                                    max="2100"
                                    :value="$usageFilters['usage_year']"
                                />
                                <div>
                                    <label for="usage-type-filter" class="mb-1 block text-xs font-semibold text-muted">Jenis cuti</label>
                                    <x-form.select id="usage-type-filter" name="leave_type">
                                        <option value="">Semua jenis</option>
                                        @foreach ($leaveTypeOptions as $option)
                                            <option value="{{ $option['id'] }}" @selected($usageFilters['leave_type'] === $option['id'])>{{ $option['nama'] }}</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-sort-filter" class="mb-1 block text-xs font-semibold text-muted">Urutkan</label>
                                    <x-form.select id="usage-sort-filter" name="sort">
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
                                    <label for="usage-direction-filter" class="mb-1 block text-xs font-semibold text-muted">Arah urutan</label>
                                    <x-form.select id="usage-direction-filter" name="direction">
                                        <option value="desc" @selected($usageFilters['direction'] === 'desc')>Terbaru/terbesar dulu</option>
                                        <option value="asc" @selected($usageFilters['direction'] === 'asc')>Terlama/terkecil dulu</option>
                                    </x-form.select>
                                </div>
                                <div>
                                    <label for="usage-per-page-filter" class="mb-1 block text-xs font-semibold text-muted">Baris per halaman</label>
                                    <x-form.select id="usage-per-page-filter" name="per_page_usage">
                                        @foreach ([10, 25, 50] as $value)
                                            <option value="{{ $value }}" @selected($usageFilters['per_page_usage'] === $value)>{{ $value }} baris</option>
                                        @endforeach
                                    </x-form.select>
                                </div>
                                <div class="flex items-end gap-2">
                                    <x-ui.button type="submit" class="min-h-11">Terapkan</x-ui.button>
                                    <a
                                        href="{{ route('cuti.saldo.administrasi', array_filter([
                                            'pegawai' => $selectedEmployee->id,
                                            'status' => $status,
                                            'search' => $search,
                                            'tab' => $tab,
                                            'page_pegawai' => request('page_pegawai'),
                                        ], static fn ($value) => $value !== null && $value !== '')) }}"
                                        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    >
                                        Reset
                                    </a>
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
                                        @endphp
                                        <x-ui.table-row>
                                            <x-ui.table-td padding="sm">
                                                @if ($usageRow->start_date && $usageRow->end_date)
                                                    <p class="font-semibold">{{ $usageRow->start_date->format('d/m/Y') }}</p>
                                                    <p class="mt-1 text-muted">s.d. {{ $usageRow->end_date->format('d/m/Y') }}</p>
                                                @else
                                                    <p class="font-semibold">Tahun {{ $usageRow->usage_year }}</p>
                                                    <p class="mt-1 text-muted">Cutoff {{ $usageRow->effective_date->format('d/m/Y') }}</p>
                                                @endif
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm">
                                                <p class="font-semibold text-ink">{{ $usageRowsUseScalarType ? ($usageRow->getAttribute('workspace_usage_type_name') ?? 'Jenis tidak tersedia') : ($usageRow->jenisCuti?->nama ?? 'Jenis tidak tersedia') }}</p>
                                                <p class="mt-1 text-muted">{{ $sourceLabels[$usageRow->source_type] ?? $usageRow->source_type }}</p>
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" align="right" class="text-sm font-bold">
                                                <span>{{ $usageRow->workdays }}</span>
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm">
                                                <span class="text-xs font-semibold text-ink">{{ $recordStatusLabels[$usageRow->record_status] ?? $usageRow->record_status }}</span>
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="max-w-xs text-muted">
                                                <p class="whitespace-normal break-words">{{ $usageRow->administrative_note }}</p>
                                                @if ($usageRow->correction_reason)
                                                    <p class="mt-1 whitespace-normal break-words"><span class="font-semibold text-ink">Alasan perubahan:</span> {{ $usageRow->correction_reason }}</p>
                                                @endif
                                                @include('admin.cuti.partials.manual-external-chain-history', ['usageRow' => $usageRow])
                                            </x-ui.table-td>
                                            <x-ui.table-td padding="sm" class="min-w-60">
                                                @if ($canManageManual && $isManual)
                                                    <div class="space-y-1">
                                                        @forelse ($usageRow->documents as $document)
                                                            <a
                                                                href="{{ route('cuti.manual.download', [$usageRow->id, $document->id]) }}"
                                                                class="block break-words font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                                                            >
                                                                Unduh {{ $document->original_name }}
                                                            </a>
                                                        @empty
                                                            <span class="text-muted">Dokumen tidak tersedia.</span>
                                                        @endforelse
                                                    </div>
                                                @elseif ($isManual)
                                                    <span class="text-muted">Dokumen dibatasi untuk pengelola cuti manual.</span>
                                                @else
                                                    <span class="text-muted">Tidak ada dokumen manual.</span>
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
                                            <x-ui.table-td colspan="6" class="py-8 text-center text-sm text-muted">
                                                Belum ada fakta pemakaian yang cocok dengan filter ini.
                                            </x-ui.table-td>
                                        </x-ui.table-row>
                                    @endforelse
                                </x-ui.table-body>
                            </x-ui.table>
                        </div>

                        @if ($usageRows->hasPages())
                            <div class="border-t border-border px-5 py-3">
                                {{ $usageRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                            </div>
                        @endif
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
                                    <h4 class="text-sm font-semibold text-ink">Batalkan versi aktif</h4>
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
        </div>
        @endif
    </div>
</x-layouts.app>
