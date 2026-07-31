<x-layouts.app title="Administrasi Saldo Cuti">
    @php
        // Tahun acuan mutasi diambil dari filter periode agar saldo awal dan koreksi menulis ke tahun yang sedang dilihat.
        $tahunAcuan = is_numeric($periode) ? (int) $periode : ($selectedBalance?->tahun ?? now()->year);
        $canAdjust = auth()->user()?->hasPermission('cuti.balance.adjust');
        $bucketCards = [
            'N-2' => $selectedBalance?->sisa_n2,
            'N-1' => $selectedBalance?->sisa_n1,
            'Tahun berjalan' => $selectedBalance?->sisa_tahun_berjalan,
            'Terpakai' => $selectedBalance?->terpakai,
            'Hangus' => $selectedBalance?->hangus,
        ];
        $statusLabels = [
            'saldo_belum_tersedia' => 'Saldo belum tersedia',
            'pembukaan_belum_tercatat' => 'Pembukaan belum tercatat',
            'saldo_awal_tercatat' => 'Saldo awal tercatat',
        ];
        $queueState = array_filter([
            'periode' => $periode,
            'status' => $status,
            'search' => $search,
            'pegawai' => $pegawaiId,
            'tab' => $tab,
            'page_pegawai' => request('page_pegawai'),
            'page_ledger' => request('page_ledger'),
        ], static fn ($value) => $value !== null && $value !== '');
        $requestedTab = old('tab', $tab);
        $initialTab = in_array($requestedTab, ['pendaftaran', 'koreksi'], true) ? $requestedTab : $tab;
    @endphp

    <div
        class="space-y-6"
        x-data="{
            tabs: ['pendaftaran', 'koreksi'],
            activeTab: @js($initialTab),
            withActiveTab(url, tab = this.activeTab) {
                const nextUrl = new URL(url, window.location.origin);
                nextUrl.searchParams.set('tab', tab);

                return `${nextUrl.pathname}${nextUrl.search}${nextUrl.hash}`;
            },
            syncEmployeeComboboxTab() {
                const wrapper = this.$refs.employeeCombobox;
                const tabInput = wrapper?.querySelector('form > input[name=tab]');
                const clearLink = wrapper?.querySelector('a[href]');

                if (tabInput) tabInput.value = this.activeTab;
                if (clearLink) clearLink.href = this.withActiveTab(clearLink.href);
            },
            syncPaginatorTabs() {
                [this.$refs.queuePaginator, this.$refs.ledgerPaginator].forEach((wrapper) => {
                    wrapper?.querySelectorAll('a[href]').forEach((link) => {
                        link.href = this.withActiveTab(link.href);
                    });
                });
            },
            selectTab(tab) {
                this.activeTab = tab;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url);
                this.$nextTick(() => {
                    this.syncEmployeeComboboxTab();
                    this.syncPaginatorTabs();
                    document.getElementById('tab-' + tab)?.focus();
                });
            },
            focusTab(direction) {
                const current = this.tabs.indexOf(this.activeTab);
                const next = (current + direction + this.tabs.length) % this.tabs.length;
                this.selectTab(this.tabs[next]);
            },
        }"
        x-init="$nextTick(() => { syncEmployeeComboboxTab(); syncPaginatorTabs(); })"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink font-sans">Administrasi Saldo Cuti</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Cuti', 'url' => route('cuti')],
                    ['label' => 'Administrasi Saldo Cuti'],
                ]" />
                <p class="mt-2 max-w-2xl text-xs leading-relaxed text-muted">
                    Pendaftaran saldo awal dipakai saat pegawai baru belum memiliki saldo. Koreksi dipakai setelah saldo berjalan dan wajib mencantumkan alasan.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('cuti.rekap', array_filter(['periode' => $periode, 'pegawai' => $pegawaiId])) }}"
                    class="inline-flex items-center justify-center rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink shadow-sm transition hover:bg-soft">
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

        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-labelledby="filter-antrian-title">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h3 id="filter-antrian-title" class="text-sm font-semibold text-ink">Filter Antrian Pegawai</h3>
                    <p class="mt-1 text-xs text-muted">Saring pegawai berdasarkan periode, nama, atau NIP.</p>
                </div>
                <form
                    method="GET"
                    action="{{ route('cuti.saldo.administrasi') }}"
                    x-data="{ initialPeriod: @js((string) $periode) }"
                    x-on:submit="if ($refs.periode.value !== initialPeriod) { $refs.pageLedger.disabled = true }"
                    class="grid w-full gap-3 sm:grid-cols-[minmax(9rem,0.4fr)_minmax(14rem,1fr)_auto] sm:items-end lg:max-w-3xl"
                >
                    <input type="hidden" name="status" value="{{ $status }}">
                    <input type="hidden" name="pegawai" value="{{ $pegawaiId }}">
                    <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                    <input x-ref="pageLedger" type="hidden" name="page_ledger" value="{{ request('page_ledger') }}">
                    <x-form.input
                        x-ref="periode"
                        name="periode"
                        label="Periode"
                        type="number"
                        min="2000"
                        max="2100"
                        :value="$periode"
                        required
                    />
                    <x-form.input
                        name="search"
                        label="Cari nama atau NIP"
                        type="search"
                        maxlength="150"
                        :value="$search"
                    />
                    <x-ui.button type="submit" class="min-h-11">Terapkan Filter</x-ui.button>
                </form>
            </div>
        </section>

        <section aria-labelledby="antrian-pegawai-title">
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-5 py-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 id="antrian-pegawai-title" class="text-sm font-semibold text-ink">Antrian Administrasi Saldo</h3>
                            <p class="mt-1 text-xs text-muted">Pilih satu pegawai untuk membuka workspace administrasi.</p>
                        </div>
                        <nav class="flex flex-wrap gap-2" aria-label="Status antrian pegawai">
                            @foreach ([
                                'perlu_tindakan' => 'Perlu Tindakan',
                                'sudah_terdaftar' => 'Sudah Terdaftar',
                                'semua_pegawai' => 'Semua Pegawai',
                            ] as $statusValue => $statusLabel)
                                <a
                                    href="{{ route('cuti.saldo.administrasi', array_filter([
                                        'periode' => $periode,
                                        'status' => $statusValue,
                                        'search' => $search,
                                        'pegawai' => $pegawaiId,
                                        'tab' => $tab,
                                        'page_ledger' => request('page_ledger'),
                                    ])) }}"
                                    x-bind:href="withActiveTab($el.getAttribute('href'))"
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
                    <x-ui.table caption="Antrian administrasi saldo cuti pegawai">
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
                                        <a
                                            href="{{ route('cuti.saldo.administrasi', array_filter([
                                                'periode' => $periode,
                                                'status' => $status,
                                                'search' => $search,
                                                'pegawai' => $row['employee_id'],
                                                'tab' => $row['status_code'] === 'saldo_awal_tercatat' ? $tab : 'pendaftaran',
                                                'page_pegawai' => request('page_pegawai'),
                                            ])) }}"
                                            x-bind:href="withActiveTab($el.getAttribute('href'), @js($row['status_code'] === 'saldo_awal_tercatat' ? null : 'pendaftaran') ?? activeTab)"
                                            class="inline-flex min-h-11 items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        >
                                            {{ $row['status_code'] === 'saldo_awal_tercatat' ? 'Lihat atau koreksi saldo' : 'Daftarkan saldo awal' }}
                                        </a>
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
                            <a
                                href="{{ route('cuti.saldo.administrasi', array_filter([
                                    'periode' => $periode,
                                    'status' => $status,
                                    'search' => $search,
                                    'pegawai' => $row['employee_id'],
                                    'tab' => $row['status_code'] === 'saldo_awal_tercatat' ? $tab : 'pendaftaran',
                                    'page_pegawai' => request('page_pegawai'),
                                ])) }}"
                                x-bind:href="withActiveTab($el.getAttribute('href'), @js($row['status_code'] === 'saldo_awal_tercatat' ? null : 'pendaftaran') ?? activeTab)"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                {{ $row['status_code'] === 'saldo_awal_tercatat' ? 'Lihat atau koreksi saldo' : 'Daftarkan saldo awal' }}
                            </a>
                        </article>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-muted">Tidak ada pegawai yang cocok dengan filter ini.</p>
                    @endforelse
                </div>

                @if ($employeeRows->hasPages())
                    <div x-ref="queuePaginator" class="border-t border-border px-5 py-3">
                        {{ $employeeRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                    </div>
                @endif
            </x-ui.card>
        </section>

        <section x-ref="employeeCombobox" class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-label="Pilih pegawai administrasi saldo">
            <x-cuti.employee-combobox
                id="saldo-admin-pegawai"
                :action="route('cuti.saldo.administrasi')"
                name="pegawai"
                query-name="search"
                :selected-id="$pegawaiId"
                :selected-label="$selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : null"
                :preserved="array_intersect_key($queueState, array_flip(['periode', 'status', 'search', 'tab', 'page_pegawai']))"
                :clear-url="route('cuti.saldo.administrasi', array_filter([
                    'periode' => $periode,
                    'status' => $status,
                    'search' => $search,
                    'tab' => $tab,
                    'page_pegawai' => request('page_pegawai'),
                ]))"
                :fallback-options="$selectedEmployee ? collect([$selectedEmployee]) : collect()"
                fallback-name="pegawai"
                fallback-label="ID Pegawai"
                fallback-placeholder="Masukkan UUID pegawai"
                label="Pilih Pegawai"
                help="Ketik minimal 2 karakter nama atau NIP pegawai yang saldonya akan dikelola."
                submit-label="Terapkan"
            />
        </section>

        <div class="flex flex-col gap-6 lg:flex-row">
            <aside class="w-full shrink-0 lg:w-64">
                <x-ui.card padding="sm" class="space-y-1">
                    <p class="mb-2 border-b border-border px-3 pb-2 text-xs font-bold uppercase tracking-wide text-muted font-sans">
                        Kategori Administrasi
                    </p>

                    <x-ui.tabs
                        variant="sidebar"
                        label="Kategori administrasi saldo cuti"
                        x-on:keydown.arrow-down.prevent="focusTab(1)"
                        x-on:keydown.arrow-up.prevent="focusTab(-1)"
                        x-on:keydown.home.prevent="selectTab('pendaftaran')"
                        x-on:keydown.end.prevent="selectTab('koreksi')"
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
                            <span>Pendaftaran Saldo Awal</span>
                        </x-ui.tab>

                        <x-ui.tab
                            variant="sidebar"
                            active="activeTab === 'koreksi'"
                            click="selectTab('koreksi')"
                            id="tab-koreksi"
                            aria-controls="panel-koreksi"
                            x-bind:tabindex="activeTab === 'koreksi' ? 0 : -1"
                            class="min-h-11 focus-visible:ring-2 focus-visible:ring-primary/40"
                        >
                            <span>Koreksi Saldo</span>
                        </x-ui.tab>
                    </x-ui.tabs>
                </x-ui.card>
            </aside>

            <main class="min-w-0 flex-1 space-y-6">
                <x-ui.card>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">Ringkasan Saldo Pegawai</h3>
                            <p class="mt-1 text-xs text-muted">Tahun acuan: {{ $tahunAcuan }}</p>
                        </div>
                        @if ($selectedEmployee)
                            <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">{{ $selectedEmployee->nama_lengkap }}</span>
                        @endif
                    </div>

                    @if ($selectedBalance)
                        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                            @foreach ($bucketCards as $label => $value)
                                <div class="rounded-xl border border-border bg-soft/40 p-3">
                                    <p class="text-xs font-bold uppercase tracking-wide text-muted">{{ $label }}</p>
                                    <p class="mt-1 text-xl font-bold text-ink">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                    @elseif (! $selectedEmployee)
                        <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                            Pilih satu pegawai dari antrian atau pencarian untuk membuka workspace administrasi saldo.
                        </div>
                    @else
                        <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                            Belum ada saldo untuk pegawai dan tahun ini. Gunakan tab Pendaftaran Saldo Awal untuk mendaftarkan saldo N-2, N-1, dan tahun berjalan.
                        </div>
                    @endif
                </x-ui.card>

                <section
                    x-show="activeTab === 'pendaftaran'"
                    id="panel-pendaftaran"
                    role="tabpanel"
                    aria-labelledby="tab-pendaftaran"
                    tabindex="0"
                    class="space-y-4 focus:outline-none"
                >
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-ink">Pendaftaran Saldo Awal</h3>
                        <p class="mt-1 text-xs leading-relaxed text-muted">
                            Isi sisa hak cuti N-2, N-1, dan tahun berjalan. Saldo awal hanya dapat didaftarkan atau diperbarui sebelum ada pemotongan cuti tahunan pada tahun tersebut.
                        </p>

                        @if (! $selectedEmployee)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Pilih pegawai terlebih dahulu untuk mendaftarkan saldo awal.
                            </div>
                        @elseif (! $canAdjust)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Anda tidak memiliki hak untuk mengubah saldo cuti.
                            </div>
                        @else
                            <form method="POST" action="{{ route('cuti.saldo.opening-balance', $selectedEmployee) }}" class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <input type="hidden" name="search" value="{{ $search }}">
                                <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                                <input type="hidden" name="tahun" value="{{ $tahunAcuan }}">

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <x-form.input
                                        name="sisa_n2"
                                        label="Sisa N-2"
                                        type="number"
                                        min="0"
                                        max="24"
                                        size="lg"
                                        :value="$selectedBalance?->sisa_n2 ?? 0"
                                        help="Sisa hak dari {{ $tahunAcuan - 2 }}."
                                        required
                                    />
                                    <x-form.input
                                        name="sisa_n1"
                                        label="Sisa N-1"
                                        type="number"
                                        min="0"
                                        max="24"
                                        size="lg"
                                        :value="$selectedBalance?->sisa_n1 ?? 0"
                                        help="Sisa hak dari {{ $tahunAcuan - 1 }}."
                                        required
                                    />
                                    <x-form.input
                                        name="sisa_tahun_berjalan"
                                        label="Sisa Tahun Berjalan"
                                        type="number"
                                        min="0"
                                        max="24"
                                        size="lg"
                                        :value="$selectedBalance?->sisa_tahun_berjalan ?? 12"
                                        help="Sisa hak tahun {{ $tahunAcuan }}."
                                        required
                                    />
                                </div>

                                <x-form.textarea
                                    name="reason"
                                    label="Alasan pendaftaran"
                                    rows="3"
                                    placeholder="Contoh: Pendaftaran saldo awal pegawai baru berdasarkan rekap kepegawaian."
                                    help="Alasan wajib diisi dan tercatat pada audit log."
                                    required
                                />

                                <button type="submit"
                                    class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30 sm:w-auto">
                                    Simpan Saldo Awal
                                </button>
                            </form>
                        @endif
                    </x-ui.card>
                </section>

                <section
                    x-show="activeTab === 'koreksi'"
                    style="display: none;"
                    id="panel-koreksi"
                    role="tabpanel"
                    aria-labelledby="tab-koreksi"
                    tabindex="0"
                    class="space-y-4 focus:outline-none"
                >
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-ink">Koreksi Saldo</h3>
                        <p class="mt-1 text-xs leading-relaxed text-muted">
                            Koreksi mengubah satu bucket saja. Gunakan angka negatif untuk mengurangi; debit otomatis dibatasi agar saldo tidak negatif.
                        </p>

                        @if (! $selectedEmployee)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Pilih pegawai terlebih dahulu untuk melakukan koreksi saldo.
                            </div>
                        @elseif (! $canAdjust)
                            <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                                Anda tidak memiliki hak untuk mengubah saldo cuti.
                            </div>
                        @else
                            <form method="POST" action="{{ route('cuti.saldo.adjust', $selectedEmployee) }}" class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <input type="hidden" name="search" value="{{ $search }}">
                                <input type="hidden" name="tab" value="{{ $tab }}" x-bind:value="activeTab">
                                <input type="hidden" name="tahun" value="{{ $tahunAcuan }}">

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <x-form.select name="bucket" label="Bucket" size="lg" required>
                                        <option value="current">Tahun berjalan</option>
                                        <option value="n1">N-1</option>
                                        <option value="n2">N-2</option>
                                    </x-form.select>

                                    <x-form.input
                                        name="amount"
                                        label="Jumlah koreksi"
                                        type="number"
                                        min="-24"
                                        max="24"
                                        size="lg"
                                        value="1"
                                        help="Rentang -24 sampai 24 dan tidak boleh 0."
                                        required
                                    />
                                </div>

                                <x-form.textarea
                                    name="reason"
                                    label="Alasan koreksi"
                                    rows="3"
                                    placeholder="Contoh: Koreksi saldo N-1 sesuai berita acara rekonsiliasi."
                                    help="Alasan wajib diisi dan tercatat pada audit log."
                                    required
                                />

                                <button type="submit"
                                    class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30 sm:w-auto">
                                    Simpan Koreksi
                                </button>
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
            </main>
        </div>
    </div>
</x-layouts.app>
