<x-layouts.app title="Rekap Cuti">
    @php


        $statusClass = [
            'Aman' => 'text-success',
            'Perhatian' => 'text-warning',
            'Kritis' => 'text-danger',
            'Menunggu' => 'text-warning',
            'disetujui' => 'text-success',
            'ditangguhkan' => 'text-warning',
            'perlu_perubahan' => 'text-danger',
            'tidak_disetujui' => 'text-danger',
        ];

        $toneText = [
            'primary' => 'text-primary',
            'info' => 'text-info',
            'success' => 'text-success',
            'danger' => 'text-danger',
            'secondary' => 'text-secondary',
            'muted' => 'text-muted',
        ];

        $toneBg = [
            'primary' => 'bg-primary',
            'info' => 'bg-info',
            'secondary' => 'bg-secondary',
            'muted' => 'bg-muted',
        ];

        $toneBorder = [
            'primary' => 'border-b-primary',
            'info' => 'border-b-info',
            'success' => 'border-b-success',
            'danger' => 'border-b-danger',
            'secondary' => 'border-b-secondary',
            'muted' => 'border-b-border',
        ];

        $toneBgLight = [
            'primary' => 'bg-primary/10',
            'info' => 'bg-info/10',
            'success' => 'bg-success/10',
            'danger' => 'bg-danger/10',
            'secondary' => 'bg-secondary/10',
            'muted' => 'bg-muted/10',
        ];
    @endphp

    <div class="space-y-6" @confirm-rekap.window="savedCorrection = true" x-data="{
            exportType: null,
            savedCorrection: false,
            activeFilters: { periode: 'Semua Periode' },
            applyExport(type) {
                this.exportType = type;
                
                let targetUrl = '';
                if (type === 'excel') {
                    targetUrl = '/laporan/export-cuti/excel';
                } else if (type === 'pdf') {
                    // Beralih ke halaman Laporan Export (Preview PDF)
                    targetUrl = '/laporan/export-cuti';
                }
                
                if (targetUrl) {
                    window.location.href = targetUrl;
                }
                
                setTimeout(() => this.exportType = null, 1500);
            }
        }">
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
                <a href="/laporan/export-cuti"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    Buka Laporan & Export
                </a>
            </div>
        </div>


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
                                    <x-ui.table-th align="right">
                                        Tahunan</x-ui.table-th>
                                    <x-ui.table-th align="right">
                                        Sakit</x-ui.table-th>
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
                                            <p class="font-mono text-xs text-muted">{{ $row['nip'] }}</p>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row['unit'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm">{{ $row['jatah'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm">{{ $row['carry'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm">{{ $row['terpakai'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm font-bold text-primary">
                                            {{ $row['sisa'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm">{{ $row['tahunan'] }}
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm">{{ $row['sakit'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                        </x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm">
                                            <button
                                                class="text-xs font-semibold text-primary hover:underline">Koreksi</button>
                                        </x-ui.table-td>
                                    </x-ui.table-row>

                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                    {{-- TABLE FOOTER --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-muted">Tampilkan</span>
                                <select class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                </select>
                                <span class="text-sm text-muted">data per halaman</span>
                            </div>
                            @if($leaveBalances->total() > 0)
                            <p class="text-sm text-muted hidden sm:block">
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
                                        <x-ui.table-td padding="sm" class="font-mono text-sm text-muted">{{ $loop->iteration }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="font-mono text-xs text-muted">{{ $row['nip'] }}</p>
                                        </x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm">{{ $row['jenis'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $row['mulai'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $row['selesai'] }}</x-ui.table-td>
                                        <x-ui.table-td align="right" padding="sm" class="font-mono text-sm">{{ $row['hari'] }}</x-ui.table-td>
                                        <x-ui.table-td padding="sm">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                    {{-- TABLE FOOTER --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-muted">Tampilkan</span>
                                <select class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                </select>
                                <span class="text-sm text-muted">data per halaman</span>
                            </div>
                            @if($usageRows->total() > 0)
                            <p class="text-sm text-muted hidden sm:block">
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

            <aside class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-ui.card class="h-full">
                    <h3 class="text-sm font-semibold text-ink">Komposisi Jenis Cuti</h3>
                    <p class="mt-1 text-xs text-muted">Persentase penggunaan cuti berdasarkan jenisnya pada periode ini.</p>
                    <div class="mt-4 space-y-4">
                        @forelse($jenisStats as $item)
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-ink">{{ $item['label'] }}</span>
                                    <span class="font-mono text-muted">{{ $item['hari'] }} hari</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-soft">
                                    <div class="h-full rounded-full {{ $toneBg[$item['tone']] }}"
                                        style="width: {{ $item['percent'] }}%"></div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center py-6 text-center">
                                <p class="text-xs text-muted">Belum ada data penggunaan cuti<br>pada periode ini.</p>
                            </div>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card class="h-full">
                    <h3 class="text-sm font-semibold text-ink">Koreksi Saldo</h3>
                    <p class="mt-1 text-xs text-muted">Alasan wajib diisi dan koreksi akan masuk audit log.</p>
                    <div x-show="savedCorrection"
                        class="mt-3 rounded-lg bg-success/10 px-3 py-2 text-xs font-semibold text-success"
                        style="display: none;">
                        Koreksi saldo tersimpan sebagai draft dan siap dicatat ke audit log saat integrasi.
                    </div>
                    <div class="mt-4 space-y-3">
                        <select
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">-- Pilih Pegawai --</option>
                            @foreach($optPegawais as $peg)
                                <option value="{{ $peg->id }}">{{ $peg->nama_lengkap }}</option>
                            @endforeach
                        </select>
                        <select
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option>Tambah Carry-Over</option>
                            <option>Kurangi Carry-Over</option>
                        </select>
                        <input type="number" value="1"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">

                        <x-form.textarea
                            rows="3"
                            placeholder="Alasan koreksi wajib diisi"
                        />
                        <button type="button" @click="$dispatch('open-confirm-rekap')"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 3h6.75a.75.75 0 01.53.22l4.5 4.5a.75.75 0 01.22.53V19.5a2.25 2.25 0 01-2.25 2.25H4.5A2.25 2.25 0 012.25 19.5V5.25A2.25 2.25 0 014.5 3zM9 3v4.5A1.5 1.5 0 0010.5 9h3a1.5 1.5 0 001.5-1.5V3m-6 18v-4.5a1.5 1.5 0 011.5-1.5h6a1.5 1.5 0 011.5 1.5V21" />
                            </svg>
                            Simpan
                        </button>
                    </div>
                </x-ui.card>
            </aside>
        </div>

        <x-ui.card padding="lg">
            <div class="flex flex-col items-center gap-1 border-b border-border pb-4 text-center">
                <div class="flex items-center justify-center gap-2">
                    <p class="text-sm font-bold uppercase tracking-wide text-primary">LLDIKTI Wilayah XVI</p>
                </div>
                <h3 class="text-xl font-bold text-ink">Rekap Cuti Pegawai</h3>
                <p class="text-sm text-muted">Periode Laporan: <span x-text="activeFilters.periode"></span></p>
            </div>

            <div class="mt-5 overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th class="px-3 py-3">No
                            </x-ui.table-th>
                            <x-ui.table-th class="px-3 py-3">NIP
                            </x-ui.table-th>
                            <x-ui.table-th class="px-3 py-3">
                                Nama</x-ui.table-th>
                            <x-ui.table-th class="px-3 py-3">
                                Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th class="px-3 py-3">
                                Tanggal Mulai</x-ui.table-th>
                            <x-ui.table-th class="px-3 py-3">
                                Tanggal Selesai</x-ui.table-th>
                            <x-ui.table-th align="right" class="px-3 py-3">
                                Hari</x-ui.table-th>
                            <x-ui.table-th class="px-3 py-3">
                                Status</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @foreach($usageRows as $row)
                            <x-ui.table-row>
                                <x-ui.table-td class="px-3 py-3 font-mono text-sm text-muted">{{ $loop->iteration }}</x-ui.table-td>
                                <x-ui.table-td class="px-3 py-3 font-mono text-muted">{{ $row['nip'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-3 py-3 text-sm font-semibold">{{ $row['nama'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-3 py-3 text-sm">{{ $row['jenis'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-3 py-3 text-sm text-muted">{{ $row['mulai'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-3 py-3 text-sm text-muted">{{ $row['selesai'] }}</x-ui.table-td>
                                <x-ui.table-td align="right" class="px-3 py-3 font-mono text-sm">{{ $row['hari'] }}</x-ui.table-td>
                                <x-ui.table-td class="px-3 py-3">
                                    <span
                                        class="text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="rounded-lg border border-border p-4">
                    <p class="text-xs font-semibold text-muted">Pembuat Laporan</p>
                    <div class="mt-12 border-t border-border pt-2">
                        <p class="text-sm font-semibold text-ink">Admin Kepegawaian</p>
                        <p class="text-xs text-muted">SIMPEG LLDIKTI XVI</p>
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <p class="text-xs font-semibold text-muted">Mengetahui</p>
                    <div class="mt-12 border-t border-border pt-2">
                        <p class="text-sm font-semibold text-ink">Pimpinan / PYBMC</p>
                        <p class="text-xs text-muted">LLDIKTI Wilayah XVI</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 border-t border-border pt-3 text-center text-[10px] text-muted">
                Preview PDF resmi. Halaman 1 dari 1.
            </div>
        </x-ui.card>


        <x-ui.confirm-dialog
            id="rekap"
            title="Konfirmasi Koreksi Saldo"
            message="Pastikan nilai koreksi dan alasan sudah benar. Koreksi saldo akan dicatat sebagai aktivitas audit saat integrasi backend aktif."
            confirm-text="Konfirmasi Simpan"
            variant="primary"
        />

    </div>
</x-layouts.app>
