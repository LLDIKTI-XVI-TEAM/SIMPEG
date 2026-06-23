<x-layouts.app title="Rekap Cuti">
    @php
        $summary = [
            ['label' => 'Total Pegawai', 'value' => '128', 'caption' => 'Pegawai aktif', 'tone' => 'primary'],
            ['label' => 'Cuti Terpakai', 'value' => '342', 'caption' => 'Hari kerja tahun ini', 'tone' => 'info'],
            ['label' => 'Sisa Saldo', 'value' => '1.194', 'caption' => 'Akumulasi hari', 'tone' => 'success'],
            ['label' => 'Saldo Kritis', 'value' => '9', 'caption' => 'Sisa <= 3 hari', 'tone' => 'danger'],
        ];

        $leaveBalances = [
            ['nama' => 'Ahmad Fauzi', 'nip' => '19850312201001 1 001', 'unit' => 'Bag. Umum', 'jatah' => 12, 'carry' => 2, 'terpakai' => 5, 'sisa' => 9, 'tahunan' => 5, 'sakit' => 0, 'lain' => 0, 'status' => 'Aman'],
            ['nama' => 'Siti Rahayu', 'nip' => '19901120201501 2 003', 'unit' => 'Bag. Keuangan', 'jatah' => 12, 'carry' => 0, 'terpakai' => 8, 'sisa' => 4, 'tahunan' => 4, 'sakit' => 3, 'lain' => 1, 'status' => 'Perhatian'],
            ['nama' => 'Nadia Kusuma', 'nip' => '19950822202001 2 002', 'unit' => 'Bag. SDM', 'jatah' => 12, 'carry' => 1, 'terpakai' => 11, 'sisa' => 2, 'tahunan' => 6, 'sakit' => 5, 'lain' => 0, 'status' => 'Kritis'],
            ['nama' => 'Cimma Sari Oktariani Di Silapu', 'nip' => '26110820520600 0 04', 'unit' => 'Bag. IT', 'jatah' => 12, 'carry' => 3, 'terpakai' => 6, 'sisa' => 9, 'tahunan' => 5, 'sakit' => 1, 'lain' => 0, 'status' => 'Aman'],
            ['nama' => 'Yucna Dara, S.P., M.M.', 'nip' => '19840120099 2 002', 'unit' => 'Bag. Keuangan', 'jatah' => 12, 'carry' => 0, 'terpakai' => 10, 'sisa' => 2, 'tahunan' => 7, 'sakit' => 2, 'lain' => 1, 'status' => 'Kritis'],
        ];

        $usageRows = [
            ['nama' => 'Ahmad Fauzi', 'nip' => '19850312201001 1 001', 'jenis' => 'Cuti Tahunan', 'mulai' => '20 Jun 2026', 'selesai' => '24 Jun 2026', 'hari' => 5, 'status' => 'Menunggu'],
            ['nama' => 'Siti Rahayu', 'nip' => '19901120201501 2 003', 'jenis' => 'Cuti Sakit', 'mulai' => '10 Apr 2026', 'selesai' => '12 Apr 2026', 'hari' => 3, 'status' => 'Disetujui'],
            ['nama' => 'Nadia Kusuma', 'nip' => '19950822202001 2 002', 'jenis' => 'Cuti Sakit', 'mulai' => '25 Jun 2026', 'selesai' => '27 Jun 2026', 'hari' => 3, 'status' => 'Menunggu'],
            ['nama' => 'Yucna Dara, S.P., M.M.', 'nip' => '19840120099 2 002', 'jenis' => 'Cuti Tahunan', 'mulai' => '27 Jun 2026', 'selesai' => '01 Jul 2026', 'hari' => 5, 'status' => 'Ditunda'],
        ];

        $jenisStats = [
            ['label' => 'Cuti Tahunan', 'hari' => 186, 'percent' => 72, 'tone' => 'primary'],
            ['label' => 'Cuti Sakit', 'hari' => 84, 'percent' => 38, 'tone' => 'info'],
            ['label' => 'Cuti Melahirkan', 'hari' => 90, 'percent' => 46, 'tone' => 'secondary'],
            ['label' => 'Cuti Lainnya', 'hari' => 22, 'percent' => 18, 'tone' => 'muted'],
        ];

        $statusClass = [
            'Aman' => 'bg-success/10 text-success',
            'Perhatian' => 'bg-warning/10 text-warning',
            'Kritis' => 'bg-danger/10 text-danger',
            'Menunggu' => 'bg-warning/10 text-warning',
            'Disetujui' => 'bg-success/10 text-success',
            'Ditunda' => 'bg-danger/10 text-danger',
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
    @endphp

    <div class="space-y-6" x-data="{
            exportType: null,
            showConfirm: false,
            savedCorrection: false,
            activeFilters: {
                periode: 'Juni 2026',
                unit: 'Semua Unit Kerja',
                pegawai: 'Semua Pegawai',
                jenis: 'Semua Jenis Cuti'
            },
            applyExport(type) {
                this.exportType = type;
                setTimeout(() => this.exportType = null, 1200);
            }
        }">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Rekap Cuti Pegawai</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Rekap Cuti</span>
                </nav>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" @click="applyExport('excel')" :disabled="exportType !== null"
                    class="rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="exportType !== 'excel'">Export Excel</span>
                    <span x-show="exportType === 'excel'" style="display: none;">Menyiapkan Excel...</span>
                </button>
                <button type="button" @click="applyExport('pdf')" :disabled="exportType !== null"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="exportType !== 'pdf'">Export PDF</span>
                    <span x-show="exportType === 'pdf'" style="display: none;">Menyiapkan PDF...</span>
                </button>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-5">
                <select x-model="activeFilters.periode"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    <option>Juni 2026</option>
                    <option>Mei 2026</option>
                    <option>April 2026</option>
                </select>
                <select x-model="activeFilters.unit"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    <option>Semua Unit Kerja</option>
                    <option>Bag. Umum</option>
                    <option>Bag. Keuangan</option>
                    <option>Bag. SDM</option>
                    <option>Bag. IT</option>
                </select>
                <select x-model="activeFilters.pegawai"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    <option>Semua Pegawai</option>
                    <option>Ahmad Fauzi</option>
                    <option>Siti Rahayu</option>
                </select>
                <select x-model="activeFilters.jenis"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    <option>Semua Jenis Cuti</option>
                    <option>Cuti Tahunan</option>
                    <option>Cuti Sakit</option>
                    <option>Cuti Melahirkan</option>
                </select>
                <button
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">
                    Terapkan Filter
                </button>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Filter Aktif</span>
                <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Periode: <span
                        x-text="activeFilters.periode"></span></span>
                <span class="rounded-full bg-info/10 px-3 py-1 text-xs font-semibold text-info">Unit: <span
                        x-text="activeFilters.unit"></span></span>
                <span class="rounded-full bg-success/10 px-3 py-1 text-xs font-semibold text-success">Pegawai: <span
                        x-text="activeFilters.pegawai"></span></span>
                <span class="rounded-full bg-warning/10 px-3 py-1 text-xs font-semibold text-warning">Jenis: <span
                        x-text="activeFilters.jenis"></span></span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            @foreach($summary as $card)
                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-muted">{{ $card['label'] }}</p>
                    <p class="mt-2 font-mono text-3xl font-extrabold {{ $toneText[$card['tone']] }}">{{ $card['value'] }}
                    </p>
                    <p class="mt-1 text-xs text-muted">{{ $card['caption'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-4">
                <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                    <div class="border-b border-border px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink">Rekap Saldo Per Pegawai</h3>
                        <p class="text-[10px] text-muted">Nama, NIP, jatah, carry-over, terpakai, sisa, total per jenis
                            cuti, dan status saldo.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-soft">
                                <tr>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Pegawai</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Unit</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Jatah</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Carry</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Terpakai</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Sisa</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Tahunan</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Sakit</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Status</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($leaveBalances as $row)
                                    <tr class="transition hover:bg-soft/40">
                                        <td class="px-4 py-3">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="font-mono text-xs text-muted">{{ $row['nip'] }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-ink">{{ $row['unit'] }}</td>
                                        <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row['jatah'] }}</td>
                                        <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row['carry'] }}</td>
                                        <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row['terpakai'] }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-sm font-bold text-primary">
                                            {{ $row['sisa'] }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row['tahunan'] }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row['sakit'] }}</td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <button
                                                class="text-xs font-semibold text-primary hover:underline">Koreksi</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                    <div class="border-b border-border px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink">Detail Penggunaan Cuti</h3>
                        <p class="text-[10px] text-muted">Data detail untuk kebutuhan export Excel: No, NIP, Nama, Jenis
                            Cuti, Tanggal Mulai, Tanggal Selesai, Jumlah Hari, Status.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-soft">
                                <tr>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        No</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Pegawai</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Jenis Cuti</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Tanggal Mulai</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Tanggal Selesai</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                        Hari</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                        Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($usageRows as $row)
                                    <tr class="transition hover:bg-soft/40">
                                        <td class="px-4 py-3 font-mono text-sm text-muted">{{ $loop->iteration }}</td>
                                        <td class="px-4 py-3">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="font-mono text-xs text-muted">{{ $row['nip'] }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-ink">{{ $row['jenis'] }}</td>
                                        <td class="px-4 py-3 text-sm text-muted">{{ $row['mulai'] }}</td>
                                        <td class="px-4 py-3 text-sm text-muted">{{ $row['selesai'] }}</td>
                                        <td class="px-4 py-3 text-right font-mono text-sm text-ink">{{ $row['hari'] }}</td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-ink">Komposisi Jenis Cuti</h3>
                    <div class="mt-4 space-y-4">
                        @foreach($jenisStats as $item)
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
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm">
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
                            <option>Nadia Kusuma</option>
                            <option>Yucna Dara, S.P., M.M.</option>
                        </select>
                        <select
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option>Tambah Carry-Over</option>
                            <option>Kurangi Carry-Over</option>
                        </select>
                        <input type="number" value="1"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <textarea rows="3"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            placeholder="Alasan koreksi wajib diisi"></textarea>
                        <button type="button" @click="showConfirm = true"
                            class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">Simpan
                            Koreksi</button>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-ink">Format Laporan</h3>
                    <div class="mt-3 space-y-2 text-xs text-muted">
                        <p>Excel: `Rekap_Cuti_Juni_2026_2026-06-22.xlsx`</p>
                        <p>PDF memuat header institusi, periode laporan, tabel rekap per pegawai, tanda tangan, dan
                            footer halaman.</p>
                    </div>
                </div>
            </aside>
        </div>

        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
            <div class="flex flex-col gap-1 border-b border-border pb-4 text-center">
                <p class="text-sm font-bold uppercase tracking-wide text-primary">LLDIKTI Wilayah XVI</p>
                <h3 class="text-xl font-bold text-ink">Rekap Cuti Pegawai</h3>
                <p class="text-sm text-muted">Periode Laporan: <span x-text="activeFilters.periode"></span></p>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">No
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">NIP
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                Nama</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                Jenis Cuti</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                Tanggal Mulai</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                Tanggal Selesai</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">
                                Hari</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach($usageRows as $row)
                            <tr>
                                <td class="px-3 py-3 font-mono text-sm text-muted">{{ $loop->iteration }}</td>
                                <td class="px-3 py-3 font-mono text-xs text-muted">{{ $row['nip'] }}</td>
                                <td class="px-3 py-3 text-sm font-semibold text-ink">{{ $row['nama'] }}</td>
                                <td class="px-3 py-3 text-sm text-ink">{{ $row['jenis'] }}</td>
                                <td class="px-3 py-3 text-sm text-muted">{{ $row['mulai'] }}</td>
                                <td class="px-3 py-3 text-sm text-muted">{{ $row['selesai'] }}</td>
                                <td class="px-3 py-3 text-right font-mono text-sm text-ink">{{ $row['hari'] }}</td>
                                <td class="px-3 py-3">
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
        </div>

        <div x-show="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4"
            style="display: none;">
            <div class="w-full max-w-md rounded-lg border border-border bg-surface p-6 shadow-sm">
                <h3 class="text-base font-semibold text-ink">Konfirmasi Koreksi Saldo</h3>
                <p class="mt-2 text-sm text-muted">
                    Pastikan nilai koreksi dan alasan sudah benar. Koreksi saldo akan dicatat sebagai aktivitas audit
                    saat integrasi backend aktif.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="showConfirm = false"
                        class="rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-muted hover:bg-soft">
                        Batal
                    </button>
                    <button type="button" @click="showConfirm = false; savedCorrection = true"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                        Konfirmasi Simpan
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>