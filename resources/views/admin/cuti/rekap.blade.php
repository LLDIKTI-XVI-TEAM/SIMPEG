<x-layouts.app title="Rekap Cuti">
    @php


        $statusClass = [
            'Aman' => 'text-success',
            'Perhatian' => 'text-warning',
            'Kritis' => 'text-danger',
            'Menunggu' => 'text-warning',
            'Disetujui' => 'text-success',
            'Ditunda' => 'text-danger',
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

    <div class="space-y-6" x-data="{
            exportType: null,
            showConfirm: false,
            savedCorrection: false,
            exportType: null,
            applyExport(type) {
                this.exportType = type;
                const form = document.getElementById('filterForm');
                const url = new URL(form.action);
                
                if (type === 'excel') {
                    url.pathname = '/laporan/export-cuti/excel';
                }
                
                // Append query params
                const formData = new FormData(form);
                for (const [key, value] of formData) {
                    if (value) url.searchParams.append(key, value);
                }
                
                window.location.href = url.toString();
                setTimeout(() => this.exportType = null, 1500);
            }
        }">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Rekap Cuti Pegawai</h2>
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
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft disabled:cursor-not-allowed disabled:opacity-60">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    <span x-show="exportType !== 'excel'">Export Excel</span>
                    <span x-show="exportType === 'excel'" style="display: none;">Menyiapkan Excel...</span>
                </button>
                <button type="button" @click="applyExport('pdf')" :disabled="exportType !== null"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <span x-show="exportType !== 'pdf'">Export PDF</span>
                    <span x-show="exportType === 'pdf'" style="display: none;">Menyiapkan PDF...</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            @foreach($summary as $card)
                <div class="rounded-xl border border-border border-b-[3px] {{ $toneBorder[$card['tone']] }} bg-surface p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div class="overflow-hidden pr-2">
                            <p class="text-[10px] font-bold text-muted uppercase tracking-wider truncate">{{ $card['label'] }}</p>
                            <p class="mt-1 text-3xl font-extrabold {{ $toneText[$card['tone']] }} leading-none font-mono tracking-tight">{{ $card['value'] }}</p>
                            <p class="mt-1 text-xs text-muted truncate">{{ $card['caption'] }}</p>
                        </div>
                        <div class="rounded-xl {{ $toneBgLight[$card['tone']] }} p-3 shrink-0">
                            @if($card['label'] === 'Total Pegawai')
                                <svg class="w-6 h-6 {{ $toneText[$card['tone']] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                            @elseif($card['label'] === 'Cuti Terpakai')
                                <svg class="w-6 h-6 {{ $toneText[$card['tone']] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                            @elseif($card['label'] === 'Sisa Saldo')
                                <svg class="w-6 h-6 {{ $toneText[$card['tone']] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            @elseif($card['label'] === 'Saldo Kritis')
                                <svg class="w-6 h-6 {{ $toneText[$card['tone']] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                            @else
                                <svg class="w-6 h-6 {{ $toneText[$card['tone']] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" /></svg>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <form id="filterForm" action="{{ route('cuti.rekap') }}" method="GET" class="rounded-lg border border-border bg-surface p-4 shadow-sm" x-data="{ submit() { this.$el.submit(); } }">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <select name="periode" @change="submit()"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    @foreach($optPeriodes as $opt)
                        <option value="{{ $opt === 'Semua Periode' ? '' : $opt }}" {{ $periode === ($opt === 'Semua Periode' ? '' : $opt) ? 'selected' : '' }}>
                            {{ $opt }}
                        </option>
                    @endforeach
                </select>
                <select name="unit" @change="submit()"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">Semua Unit Kerja</option>
                    @foreach($optUnits as $opt)
                        <option value="{{ $opt }}" {{ $unit === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
                <select name="pegawai" @change="submit()"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">Semua Pegawai</option>
                    @foreach($optPegawais as $opt)
                        <option value="{{ $opt->id }}" {{ $pegawaiId == $opt->id ? 'selected' : '' }}>
                            {{ $opt->nama_lengkap }} ({{ $opt->nip }})
                        </option>
                    @endforeach
                </select>
                <select name="jenis" @change="submit()"
                    class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">Semua Jenis Cuti</option>
                    @foreach($optJenisCutis as $opt)
                        <option value="{{ $opt->id }}" {{ $jenisId == $opt->id ? 'selected' : '' }}>{{ $opt->nama }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        <div class="space-y-6">
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
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Pegawai</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Unit</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Jatah</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Carry</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Terpakai</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Sisa</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Tahunan</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Sakit</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                                    <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($leaveBalances as $row)
                                    <tr class="transition hover:bg-soft/40">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <p class="text-sm font-semibold text-ink">{{ $row['nama'] }}</p>
                                            <p class="font-mono text-xs text-muted">{{ $row['nip'] }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-ink">{{ $row['unit'] }}</td>
                                        <td class="px-4 py-3 text-left font-mono text-sm text-ink">{{ $row['jatah'] }}</td>
                                        <td class="px-4 py-3 text-left font-mono text-sm text-ink">{{ $row['carry'] }}</td>
                                        <td class="px-4 py-3 text-left font-mono text-sm text-ink">{{ $row['terpakai'] }}</td>
                                        <td class="px-4 py-3 text-left font-mono text-sm font-bold text-primary">{{ $row['sisa'] }}</td>
                                        <td class="px-4 py-3 text-left font-mono text-sm text-ink">{{ $row['tahunan'] }}</td>
                                        <td class="px-4 py-3 text-left font-mono text-sm text-ink">{{ $row['sakit'] }}</td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button
                                                    title="Koreksi"
                                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm">
                                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    {{-- TABLE FOOTER / PAGINATION --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row rounded-b-lg">
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
                            <p class="text-sm text-muted hidden sm:block">
                                Menampilkan <span class="font-semibold text-ink">1</span> hingga <span class="font-semibold text-ink">{{ count($leaveBalances) }}</span> dari <span class="font-semibold text-ink">{{ count($leaveBalances) }}</span> hasil
                            </p>
                        </div>
                        <div class="w-full sm:w-auto">
                            <nav role="navigation" aria-label="Pagination Navigation" class="flex justify-end w-full overflow-x-auto pb-1" style="scrollbar-width: none;">
                                <ul class="flex items-center gap-1.5 shrink-0">
                                    <li class="flex h-8 w-8 shrink-0 cursor-not-allowed items-center justify-center rounded-md border border-border bg-surface text-muted opacity-50" aria-hidden="true">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                                    </li>
                                    <li class="shrink-0" aria-current="page">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-primary bg-primary text-sm font-semibold text-white shadow-sm transition">1</span>
                                    </li>
                                    <li class="flex h-8 w-8 shrink-0 cursor-not-allowed items-center justify-center rounded-md border border-border bg-surface text-muted opacity-50" aria-hidden="true">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                    </li>
                                </ul>
                            </nav>
                        </div>
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
                                                class="text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    {{-- TABLE FOOTER / PAGINATION --}}
                    <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row rounded-b-lg">
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
                            <p class="text-sm text-muted hidden sm:block">
                                Menampilkan <span class="font-semibold text-ink">1</span> hingga <span class="font-semibold text-ink">{{ count($usageRows) }}</span> dari <span class="font-semibold text-ink">{{ count($usageRows) }}</span> hasil
                            </p>
                        </div>
                        <div class="w-full sm:w-auto">
                            <nav role="navigation" aria-label="Pagination Navigation" class="flex justify-end w-full overflow-x-auto pb-1" style="scrollbar-width: none;">
                                <ul class="flex items-center gap-1.5 shrink-0">
                                    <li class="flex h-8 w-8 shrink-0 cursor-not-allowed items-center justify-center rounded-md border border-border bg-surface text-muted opacity-50" aria-hidden="true">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                                    </li>
                                    <li class="shrink-0" aria-current="page">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-primary bg-primary text-sm font-semibold text-white shadow-sm transition">1</span>
                                    </li>
                                    <li class="flex h-8 w-8 shrink-0 cursor-not-allowed items-center justify-center rounded-md border border-border bg-surface text-muted opacity-50" aria-hidden="true">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-ink">Komposisi Jenis Cuti</h3>
                <p class="mt-1 text-xs text-muted">Statistik penggunaan hari cuti yang telah disetujui berdasarkan jenisnya.</p>
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
                        <div class="py-6 text-center text-xs text-muted">
                            <p>Belum ada data cuti yang disetujui.</p>
                        </div>
                    @endforelse
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
                        <option value="">Pilih Pegawai...</option>
                        @foreach($optPegawais as $opt)
                            <option value="{{ $opt->id }}">{{ $opt->nama_lengkap }} ({{ $opt->nip }})</option>
                        @endforeach
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
                        class="flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Simpan Koreksi
                    </button>
                </div>
            </div>


        </div>


        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
            <div class="flex flex-col items-center gap-1 border-b border-border pb-4 text-center">
                <div class="flex items-center justify-center gap-2">
                    <img src="{{ asset('img/dikti16-favicon-blue-150x150.png') }}" alt="Logo LLDIKTI" class="h-6 w-auto">
                    <p class="text-sm font-bold uppercase tracking-wide text-primary">LLDIKTI Wilayah XVI</p>
                </div>
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
                                        class="text-xs font-semibold {{ $statusClass[$row['status']] }}">{{ $row['status'] }}</span>
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
                        class="flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-muted hover:bg-soft">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Batal
                    </button>
                    <button type="button" @click="showConfirm = false; savedCorrection = true"
                        class="flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                        Konfirmasi Simpan
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
