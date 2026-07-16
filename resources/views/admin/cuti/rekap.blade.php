<x-layouts.app title="Rekap Cuti">
    @php
        $statusClass = [
            'Aman' => 'text-success',
            'Perhatian' => 'text-warning',
            'Kritis' => 'text-danger',
            'Menunggu' => 'text-warning',
            'menunggu_approval' => 'text-warning',
            'disetujui' => 'text-success',
            'ditangguhkan' => 'text-warning',
            'perlu_perubahan' => 'text-danger',
            'tidak_disetujui' => 'text-danger',
        ];

    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Rekap Cuti Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Cuti', 'url' => route('cuti')],
                    ['label' => 'Rekap Cuti']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('cuti.laporan', array_filter(['periode' => $periode, 'unit' => $unit, 'pegawai' => $pegawaiId, 'jenis' => $jenisId])) }}"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    Buka Laporan & Export
                </a>
            </div>
        </div>

        <section class="rounded-xl border border-border bg-surface px-5 py-4 shadow-sm" aria-label="Filter rekap cuti">
            <x-cuti.employee-combobox
                id="rekap-pegawai"
                :action="route('cuti.rekap')"
                name="pegawai"
                query-name="search"
                :selected-id="$pegawaiId"
                :selected-label="$selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : null"
                :preserved="['periode' => $periode, 'unit' => $unit, 'jenis' => $jenisId]"
                :clear-url="route('cuti.rekap', array_filter(['periode' => $periode, 'unit' => $unit, 'jenis' => $jenisId]))"
                :fallback-options="$selectedEmployee ? collect([$selectedEmployee]) : collect()"
                fallback-name="pegawai"
                fallback-label="ID Pegawai"
                fallback-placeholder="Masukkan UUID pegawai"
                label="Filter Pegawai"
                help="Ketik minimal 2 karakter untuk memantau saldo dan penggunaan cuti pegawai."
                submit-label="Terapkan Filter"
            />
        </section>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            @foreach($summary as $card)
                <x-ui.stat-card label="{{ $card['label'] }}" value="{{ $card['value'] }}" variant="{{ $card['tone'] }}" size="lg" accent>
                    <x-slot:icon>
                        @if($card['tone'] === 'primary')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        @elseif($card['tone'] === 'success')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        @elseif($card['tone'] === 'warning')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        @elseif($card['tone'] === 'danger')
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                        @else
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                        @endif
                    </x-slot:icon>
                    <x-slot:meta>
                        <span>{{ $card['caption'] }}</span>
                    </x-slot:meta>
                </x-ui.stat-card>
            @endforeach
        </div>

        <div class="flex flex-col gap-4">
            <div class="space-y-4">
                <x-ui.card padding="none" class="overflow-hidden">
                    <div class="border-b border-border px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink">Rekap Saldo Per Pegawai</h3>
                    </div>
                    <div class="overflow-x-auto">

                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>
                                        Pegawai</x-ui.table-th>
                                    <x-ui.table-th>
                                        Unit</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Jatah</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Carry</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Terpakai</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Sisa</x-ui.table-th>
                                    <x-ui.table-th>
                                        Status</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Aksi</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($leaveBalances as $row)
                                    <x-ui.table-row :interactive="true">
                                        <x-ui.table-td padding="sm">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="text-xs text-muted">{{ $row['nip'] }}</p>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row['unit'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['jatah'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['carry'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['terpakai'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm font-bold text-primary">
                                            {{ $row['sisa'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] ?? 'text-muted' }}">{{ $row['status_label'] }}</span>
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm">
                                            <a href="{{ route('cuti.rekap', array_filter(['pegawai' => $row['employee_id'], 'periode' => $row['tahun']])) }}#admin-saldo-cuti"
                                                class="text-xs font-semibold text-primary hover:underline">Koreksi</a>
                                        </x-ui.table-td>
                                    </x-ui.table-row>

                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                    {{-- TABLE FOOTER --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                        <div>
                            @if($leaveBalances->total() > 0)
                            <p class="text-sm text-muted">
                                Menampilkan <span class="font-semibold text-ink">{{ $leaveBalances->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $leaveBalances->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $leaveBalances->total() }}</span> hasil
                            </p>
                            @endif
                        </div>
                        <div class="w-full sm:w-auto">
                            {{ $leaveBalances->onEachSide(1)->links('vendor.pagination.simpeg') }}
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card padding="none" class="overflow-hidden">
                    <div class="border-b border-border px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink">Detail Penggunaan Cuti</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>
                                        No</x-ui.table-th>
                                    <x-ui.table-th>
                                        Pegawai</x-ui.table-th>
                                    <x-ui.table-th>
                                        Jenis Cuti</x-ui.table-th>
                                    <x-ui.table-th>
                                        Tanggal Mulai</x-ui.table-th>
                                    <x-ui.table-th>
                                        Tanggal Selesai</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Hari</x-ui.table-th>
                                    <x-ui.table-th>
                                        Status</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($usageRows as $row)
                                    <x-ui.table-row :interactive="true">
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $loop->iteration }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="text-xs text-muted">{{ $row['nip'] }}</p>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row['jenis'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $row['mulai'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $row['selesai'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="text-sm">{{ $row['hari'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] ?? 'text-muted' }}">{{ $row['status_label'] }}</span>
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                    {{-- TABLE FOOTER --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                        <div>
                            @if($usageRows->total() > 0)
                            <p class="text-sm text-muted">
                                Menampilkan <span class="font-semibold text-ink">{{ $usageRows->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $usageRows->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $usageRows->total() }}</span> hasil
                            </p>
                            @endif
                        </div>
                        <div class="w-full sm:w-auto">
                            {{ $usageRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                        </div>
                    </div>
                </x-ui.card>
            </div>

            <aside id="admin-saldo-cuti" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <x-ui.card class="h-full xl:col-span-2">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">Admin Saldo Cuti</h3>
                            <p class="mt-1 text-xs text-muted">Klik Koreksi pada tabel rekap untuk membuka bucket, formulir koreksi, dan ledger saldo pegawai.</p>
                        </div>
                        @if($selectedEmployee)
                            <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">{{ $selectedEmployee->nama_lengkap }}</span>
                        @endif
                    </div>

                    @if($selectedBalance)
                        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                            @foreach([
                                'N-2' => $selectedBalance->sisa_n2,
                                'N-1' => $selectedBalance->sisa_n1,
                                'Tahun berjalan' => $selectedBalance->sisa_tahun_berjalan,
                                'Terpakai' => $selectedBalance->terpakai,
                                'Hangus' => $selectedBalance->hangus,
                            ] as $label => $value)
                                <div class="rounded-xl border border-border bg-soft/40 p-3">
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-muted">{{ $label }}</p>
                                    <p class="mt-1 text-xl font-bold text-ink">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-4 rounded-xl border border-dashed border-border p-5 text-sm text-muted">
                            Belum ada saldo untuk filter pegawai dan periode ini. Admin dapat mengisi saldo awal jika memiliki izin koreksi.
                        </div>
                    @endif

                    @if(auth()->user()?->hasPermission('cuti.balance.adjust') && $selectedEmployee)
                        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <form method="POST" action="{{ route('cuti.saldo.opening-balance', $selectedEmployee) }}" class="space-y-3 rounded-xl border border-border p-4">
                                @csrf
                                <h4 class="text-sm font-semibold text-ink">Input Saldo Awal</h4>
                                <input type="hidden" name="tahun" value="{{ is_numeric($periode) ? $periode : now()->year }}">
                                <div class="grid grid-cols-3 gap-2">
                                    <x-form.input name="sisa_n2" label="N-2" type="number" value="{{ $selectedBalance?->sisa_n2 ?? 0 }}" min="0" required />
                                    <x-form.input name="sisa_n1" label="N-1" type="number" value="{{ $selectedBalance?->sisa_n1 ?? 0 }}" min="0" required />
                                    <x-form.input name="sisa_tahun_berjalan" label="Berjalan" type="number" value="{{ $selectedBalance?->sisa_tahun_berjalan ?? 12 }}" min="0" required />
                                </div>
                                <x-form.textarea name="reason" label="Alasan saldo awal" rows="3" placeholder="Contoh: Input saldo awal hasil rekonsiliasi." required />
                                <button type="submit" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">Simpan Saldo Awal</button>
                            </form>

                            <form method="POST" action="{{ route('cuti.saldo.adjust', $selectedEmployee) }}" class="space-y-3 rounded-xl border border-border p-4">
                                @csrf
                                <h4 class="text-sm font-semibold text-ink">Koreksi Saldo</h4>
                                <input type="hidden" name="tahun" value="{{ is_numeric($periode) ? $periode : ($selectedBalance?->tahun ?? now()->year) }}">
                                <x-form.select name="bucket" label="Bucket" required>
                                    <option value="current">Tahun berjalan</option>
                                    <option value="n1">N-1</option>
                                    <option value="n2">N-2</option>
                                </x-form.select>
                                <x-form.input name="amount" label="Jumlah koreksi" type="number" value="1" required help="Gunakan angka negatif untuk debit. Debit otomatis diclamp agar saldo tidak negatif." />
                                <x-form.textarea name="reason" label="Alasan koreksi" rows="3" placeholder="Alasan koreksi wajib diisi." required />
                                <button type="submit" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">Simpan Koreksi</button>
                            </form>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card class="h-full">
                    <h3 class="text-sm font-semibold text-ink">Status Rollover</h3>
                    <p class="mt-1 text-xs text-muted">Riwayat rollover bersifat baca-saja. Eksekusi hanya lewat command CLI ops.</p>
                    <div class="mt-4 space-y-3">
                        @forelse($rolloverRows as $rollover)
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
            </aside>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink">Ledger Saldo</h3>
                    <p class="mt-1 text-xs text-muted">Buku besar append-only untuk saldo cuti pegawai terpilih.</p>
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
                            @forelse($ledgerRows as $ledger)
                                <x-ui.table-row>
                                    <x-ui.table-td padding="sm" class="text-xs text-muted">{{ optional($ledger->occurred_at)->translatedFormat('d M Y H:i') }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-xs text-ink">{{ $ledger->event_type }}</x-ui.table-td>
                                    <x-ui.table-td align="right" padding="sm" class="text-sm font-semibold {{ $ledger->amount < 0 ? 'text-danger' : 'text-success' }}">{{ $ledger->amount }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-xs text-muted">{{ $ledger->source_year ?? '-' }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="max-w-md text-sm text-muted">{{ $ledger->reason ?? '-' }}</x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="5" align="center" class="px-5 py-8 text-muted">Pilih pegawai untuk melihat ledger saldo.</x-ui.table-td>
                                </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
                @if($ledgerRows->hasPages())
                    <div class="border-t border-border px-5 py-3">
                        {{ $ledgerRows->onEachSide(1)->links('vendor.pagination.simpeg') }}
                    </div>
                @endif
            </x-ui.card>
        </div>

    </div>
</x-layouts.app>
