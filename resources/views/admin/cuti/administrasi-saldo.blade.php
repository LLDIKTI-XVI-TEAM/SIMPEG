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
    @endphp

    <div
        class="space-y-6"
        x-data="{
            tabs: ['pendaftaran', 'koreksi'],
            activeTab: '{{ old('tab', 'pendaftaran') }}',
            selectTab(tab) {
                this.activeTab = tab;
                this.$nextTick(() => document.getElementById('tab-' + tab)?.focus());
            },
            focusTab(direction) {
                const current = this.tabs.indexOf(this.activeTab);
                const next = (current + direction + this.tabs.length) % this.tabs.length;
                this.selectTab(this.tabs[next]);
            },
        }"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Administrasi Saldo Cuti</h2>
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

        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-label="Pilih pegawai administrasi saldo">
            <x-cuti.employee-combobox
                id="saldo-admin-pegawai"
                :action="route('cuti.saldo.administrasi')"
                name="pegawai"
                query-name="search"
                :selected-id="$pegawaiId"
                :selected-label="$selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : null"
                :preserved="['periode' => $periode]"
                :clear-url="route('cuti.saldo.administrasi', array_filter(['periode' => $periode]))"
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
                    <p class="mb-2 border-b border-border px-3 pb-2 text-[10px] font-bold uppercase tracking-wide text-muted font-sans">
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
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-muted">{{ $label }}</p>
                                    <p class="mt-1 text-xl font-bold text-ink">{{ $value ?? 0 }}</p>
                                </div>
                            @endforeach
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
                                <input type="hidden" name="tab" value="pendaftaran">
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
                                <input type="hidden" name="tab" value="koreksi">
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
                                <p class="rounded-xl border border-dashed border-border p-4 text-xs text-muted">Belum ada riwayat rollover untuk pegawai ini.</p>
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
                                                Belum ada mutasi saldo untuk pegawai ini.
                                            </x-ui.table-td>
                                        </x-ui.table-row>
                                    @endforelse
                                </x-ui.table-body>
                            </x-ui.table>
                        </div>
                    </x-ui.card>
                </section>
            </main>
        </div>
    </div>
</x-layouts.app>
