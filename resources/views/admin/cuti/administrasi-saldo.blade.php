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
                <h1 class="text-2xl font-semibold text-ink font-sans">Administrasi Pemakaian Cuti</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Monitoring Cuti', 'url' => route('cuti')],
                    ['label' => 'Administrasi Pemakaian Cuti'],
                ]" />
                <p class="mt-2 max-w-2xl text-xs leading-relaxed text-muted">
                    Saldo merupakan hasil perhitungan baca-saja berdasarkan fakta pemakaian dari SIMPEG dan Cuti di Luar SIMPEG. Koreksi hanya dilakukan pada fakta Cuti di Luar SIMPEG secara append-only.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('cuti.rekap', array_filter(['pegawai' => $pegawaiId])) }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink shadow-sm transition hover:bg-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2">
                    Lihat Rekap Cuti
                </a>
            </div>
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

        @if (! $selectedEmployee)
        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-labelledby="filter-antrian-title">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h3 id="filter-antrian-title" class="text-sm font-semibold text-ink">Cari pegawai</h3>
                    <p class="mt-1 text-xs text-muted">Kelola saldo cuti tahun {{ $tahunAcuan }}. Cari pegawai berdasarkan nama atau NIP.</p>
                </div>
                <form
                    method="GET"
                    action="{{ route('cuti.saldo.administrasi') }}"
                    class="grid w-full gap-3 sm:grid-cols-[minmax(14rem,1fr)_auto] sm:items-end lg:max-w-3xl"
                >
                    <input type="hidden" name="status" value="{{ $status }}">
                    <x-form.input
                        name="search"
                        label="Cari pegawai"
                        type="search"
                        maxlength="150"
                        :value="$search"
                        placeholder="Masukkan nama atau NIP"
                    />
                    <div class="flex items-center gap-2">
                        <x-ui.button type="submit" class="min-h-11">Cari</x-ui.button>
                        @if ($search !== '')
                            <a href="{{ route('cuti.saldo.administrasi', array_filter(['status' => $status])) }}"
                                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">
                                Reset pencarian
                            </a>
                        @endif
                    </div>
                </form>
            </div>
        </section>

        <section aria-labelledby="antrian-pegawai-title">
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-5 py-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 id="antrian-pegawai-title" class="text-sm font-semibold text-ink">Antrian Administrasi Pemakaian</h3>
                            <p class="mt-1 text-xs text-muted">Pilih satu pegawai untuk membuka workspace administrasi.</p>
                        </div>
                        <nav class="flex flex-wrap gap-2" aria-label="Status antrian pegawai">
                            @foreach ([
                                'perlu_tindakan' => 'Belum Ada Fakta',
                                'sudah_terdaftar' => 'Memiliki Fakta',
                                'semua_pegawai' => 'Semua Pegawai',
                            ] as $statusValue => $statusLabel)
                                <a
                                    href="{{ route('cuti.saldo.administrasi', array_filter([
                                        'status' => $statusValue,
                                        'search' => $search,
                                    ])) }}"
                                    @if ($status === $statusValue) aria-current="page" @endif
                                    @class([
                                        'inline-flex min-h-11 items-center justify-center rounded-xl border px-3.5 py-2 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-primary/20',
                                        'border-primary bg-primary text-white' => $status === $statusValue,
                                        'border-border bg-surface text-muted hover:bg-soft hover:text-ink' => $status !== $statusValue,
                                    ])
                                >
                                    {{ $statusLabel }} ({{ $statusCounts[$statusValue] }})
                                </a>
                            @endforeach
                        </nav>
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <x-ui.table caption="Antrian administrasi pemakaian cuti pegawai">
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th padding="sm">Pegawai</x-ui.table-th>
                                <x-ui.table-th padding="sm">NIP</x-ui.table-th>
                                <x-ui.table-th padding="sm">Status</x-ui.table-th>
                                <x-ui.table-th padding="sm" align="right">Tindakan</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @forelse ($employeeRows as $row)
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
                                    <x-ui.table-td padding="sm" align="right">
                                        <div class="flex flex-wrap items-center justify-end gap-1">
                                            <a
                                                href="{{ route('cuti.saldo.administrasi', array_filter([
                                                    'status' => $status,
                                                    'search' => $search,
                                                    'pegawai' => $row['employee_id'],
                                                    'tab' => 'pendaftaran',
                                                    'page_pegawai' => request('page_pegawai'),
                                                ])) }}"
                                                x-bind:href="withActiveTab($el.getAttribute('href'), 'pendaftaran')"
                                                class="inline-flex min-h-11 items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
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
                                                    ])) }}"
                                                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-xs font-semibold text-ink transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                >
                                                    Catat cuti eksternal
                                                </a>
                                            @endif
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="4" class="py-8 text-center text-sm text-muted">
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

                @if ($employeeRows->hasPages())
                    <div class="border-t border-border px-5 py-3">
                        {{ $employeeRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                    </div>
                @endif
            </x-ui.card>
        </section>
        @else
        <a href="{{ $queueUrl }}"
            class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">
            Kembali ke antrian pegawai
        </a>
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
                            <span>Ringkasan Pemakaian Tahunan</span>
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
                            active="activeTab === 'riwayat'"
                            click="selectTab('riwayat')"
                            id="tab-riwayat"
                            aria-controls="panel-riwayat"
                            x-bind:tabindex="activeTab === 'riwayat' ? 0 : -1"
                            class="min-h-11 focus-visible:ring-2 focus-visible:ring-primary/40"
                        >
                            <span>Ledger &amp; Rollover</span>
                        </x-ui.tab>

                    </x-ui.tabs>
                </x-ui.card>
            </aside>

            <div class="min-w-0 flex-1 space-y-6">
                <x-ui.card>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">Hasil Perhitungan Hak Cuti Tahunan</h3>
                            <p class="mt-1 text-xs text-muted">Tahun acuan: {{ $tahunAcuan }}</p>
                            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                                Nilai ini dihitung sistem dari pengajuan yang disetujui dan fakta Cuti di Luar SIMPEG yang tercatat.
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
                            Perhitungan saldo belum tersedia. Saldo akan dihitung dari pengajuan SIMPEG yang disetujui dan fakta Cuti di Luar SIMPEG yang tercatat.
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
                        <h3 class="text-sm font-semibold text-ink">Ringkasan Pemakaian Tahunan</h3>
                        <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
                            Ringkasan ini hanya membaca fakta pemakaian aktif dari pengajuan yang disetujui di SIMPEG dan Cuti di Luar SIMPEG. Tidak ada input total tahunan langsung.
                        </p>
                        <dl class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Ringkasan pemakaian tahunan baca-saja">
                            @foreach ([
                                sprintf('Pemakaian tahun %d', $tahunAcuan - 2) => $usage['n2'],
                                sprintf('Pemakaian tahun %d', $tahunAcuan - 1) => $usage['n1'],
                                sprintf('Pemakaian tahun %d', $tahunAcuan) => $usage['current'],
                            ] as $label => $value)
                                <div class="rounded-xl border border-border bg-surface p-4">
                                    <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ $label }}</dt>
                                    <dd class="mt-1 text-xl font-bold text-ink">{{ $value }} hari</dd>
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
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-ink">Riwayat Saldo</h3>
                        <p class="mt-1 text-xs leading-relaxed text-muted">
                            Koreksi atau pembatalan dilakukan per fakta Cuti di Luar SIMPEG secara append-only pada tab tersebut. Ringkasan tahunan tidak dapat diedit langsung.
                        </p>
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
