<x-layouts.app :title="$isPegawai ? 'Riwayat Pengajuan Cuti Saya' : 'Monitoring Cuti'">

    @php
        // Warna badge mengikuti ketetapan resmi: kuning menunggu, hijau disetujui, biru perubahan,
        // oranye ditangguhkan, merah tidak disetujui. Kunci memakai token runtime mentah dari Action.
        $statusVariant = [
            'menunggu_approval' => 'warning',
            'disetujui' => 'success',
            'ditangguhkan' => 'orange',
            'ditangguhkan_tugas_dinas' => 'orange',
            // Pengembalian karena rollover tidak termasuk lima status yang warnanya ditetapkan resmi;
            // dibuat netral agar tidak menyerupai salah satu keputusan approval.
            'dikembalikan_karena_rollover' => 'muted',
            'perlu_perubahan' => 'info',
            'tidak_disetujui' => 'danger',
        ];
        
        $statusLabel = [
            'menunggu_approval' => 'Menunggu',
            'disetujui' => 'Disetujui',
            'ditangguhkan' => 'Ditangguhkan',
            'ditangguhkan_tugas_dinas' => 'Ditangguhkan karena Tugas Dinas',
            'dikembalikan_karena_rollover' => 'Dikembalikan karena Rollover',
            'perlu_perubahan' => 'Perubahan',
            'tidak_disetujui' => 'Tidak Disetujui',
        ];
    @endphp

    <div class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">{{ $isPegawai ? 'Riwayat Pengajuan Cuti Saya' : 'Monitoring Cuti' }}</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => $isPegawai ? 'Pengajuan Cuti' : 'Monitoring Cuti']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <x-ui.button
                    href="{{ request()->fullUrl() }}"
                    variant="secondary"
                    size="md"
                    aria-label="{{ $isPegawai ? 'Refresh riwayat pengajuan cuti' : 'Refresh monitoring cuti' }}"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Refresh
                </x-ui.button>
                @if(auth()->user()->hasPermission('cuti.create') && ! auth()->user()->employee?->is_kepala_lembaga)
                <x-ui.button href="{{ route('cuti.create') }}" variant="primary" size="md">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajukan Cuti Baru
                </x-ui.button>
                @endif
            </div>
        </div>

        {{-- METRICS SUMMARY CARD (GLOBAL MONITORING) --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
            {{-- Total Cuti Active --}}
            <x-ui.stat-card label="{{ $isPegawai ? 'Total Pengajuan' : 'Total Staf Cuti' }}" value="{{ $totalPengajuan }}" variant="primary" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>{{ $isPegawai ? 'Seluruh pengajuan Anda' : 'Seluruh riwayat pengajuan' }}</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- Approved --}}
            <x-ui.stat-card label="Disetujui (Bulan Ini)" value="{{ $jumlahDisetujui }}" variant="success" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Disetujui di periode berjalan</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- Pending --}}
            <x-ui.stat-card label="Menunggu Persetujuan" value="{{ $jumlahMenunggu }}" variant="warning" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Pengajuan butuh validasi</span>
                </x-slot:meta>
            </x-ui.stat-card>

            {{-- Postponed --}}
            {{-- Warna mengikuti badge status: penangguhan menahan pengajuan, bukan menolaknya, sehingga tidak memakai merah. --}}
            <x-ui.stat-card label="Ditangguhkan" value="{{ $jumlahDitangguhkan }}" variant="orange" size="lg" accent>
                <x-slot:icon>
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                </x-slot:icon>
                <x-slot:meta>
                    <span>Cuti ditunda</span>
                </x-slot:meta>
            </x-ui.stat-card>
        </div>


        <form method="GET" action="{{ route('cuti') }}">
            <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
        <x-ui.filter-bar
            searchId="{{ $isPegawai ? null : 'search-cuti' }}"
            searchName="{{ $isPegawai ? null : 'search' }}"
            searchValue="{{ $search }}"
            searchPlaceholder="{{ $isPegawai ? null : 'Cari nama atau NIP' }}"
            gridClass="sm:grid-cols-2 {{ $isPegawai ? 'lg:grid-cols-3' : 'lg:grid-cols-5' }}"
        >
            {{-- Filter Status --}}
            <div class="relative">
                <x-form.select id="filter-status" name="status" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="menunggu" @selected($status === 'menunggu' || $status === 'pending')>Menunggu Keputusan</option>
                    <option value="disetujui" @selected($status === 'disetujui')>Disetujui</option>
                    <option value="ditunda" @selected($status === 'ditunda' || $status === 'ditangguhkan')>Ditangguhkan</option>
                    <option value="ditangguhkan_tugas_dinas" @selected($status === 'ditangguhkan_tugas_dinas')>Ditangguhkan karena Tugas Dinas</option>
                    <option value="dikembalikan_karena_rollover" @selected($status === 'dikembalikan_karena_rollover')>Dikembalikan karena Rollover</option>
                    <option value="perlu_perubahan" @selected($status === 'perlu_perubahan')>Perubahan</option>
                    <option value="tidak_disetujui" @selected($status === 'tidak_disetujui')>Tidak Disetujui</option>
                </x-form.select>
            </div>


            {{-- Filter Jenis Cuti --}}
            <div class="relative">
                <x-form.select id="filter-jenis" name="jenis" onchange="this.form.submit()">
                    <option value="">Semua Jenis Cuti</option>
                    @foreach($optJenisCutis as $namaJenis)
                        <option value="{{ $namaJenis }}" @selected($jenis === $namaJenis)>{{ $namaJenis }}</option>
                    @endforeach
                </x-form.select>
            </div>

            @unless($isPegawai)
            {{-- Filter Unit Kerja --}}
            <div class="relative">
                <x-form.select id="filter-unit" name="unit" onchange="this.form.submit()">
                    <option value="">Semua Unit Kerja</option>
                    @foreach($optUnits as $namaUnit)
                        <option value="{{ $namaUnit }}" @selected($unit === $namaUnit)>{{ $namaUnit }}</option>
                    @endforeach
                </x-form.select>
            </div>
            @endunless

            {{-- Filter Periode: setahun penuh atau satu bulan --}}
            <div class="relative">
                <x-form.select id="filter-periode" name="periode" onchange="this.form.submit()">
                    <option value="">Semua Periode</option>
                    <optgroup label="Tahun">
                        @foreach($optTahuns as $tahunOption)
                            <option value="{{ $tahunOption }}" @selected($periode === $tahunOption)>Tahun {{ $tahunOption }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Bulan">
                        @foreach($optPeriodes as $periodeOption)
                            <option value="{{ $periodeOption }}" @selected($periode === $periodeOption)>{{ \Carbon\Carbon::createFromFormat('Y-m', $periodeOption)->translatedFormat('F Y') }}</option>
                        @endforeach
                    </optgroup>
                </x-form.select>
            </div>
        </x-ui.filter-bar>
        </form>


        {{-- TABLE CARD --}}
        <x-ui.card padding="none" class="overflow-hidden">

            <div class="overflow-x-auto">

                <x-ui.table id="cuti-table">
                    <x-ui.table-head class="border-b border-border">
                        <x-ui.table-row>
                            @unless($isPegawai)
                                <x-ui.table-th class="select-none">Pegawai</x-ui.table-th>
                                <x-ui.table-th class="select-none">Unit Kerja</x-ui.table-th>
                            @endunless
                            <x-ui.table-th class="select-none">Detail Cuti</x-ui.table-th>
                            <x-ui.table-th class="select-none">Tanggal & Durasi</x-ui.table-th>
                            <x-ui.table-th class="select-none">Langkah Aktif</x-ui.table-th>
                            <x-ui.table-th class="select-none">Status Akhir</x-ui.table-th>
                            <x-ui.table-th align="right" class="select-none">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($riwayatCuti as $r)
                        <x-ui.table-row data-nama="{{ $r['nama'] }}" data-nip="{{ $r['nip'] }}" data-unit="{{ $r['unit'] }}" data-jenis="{{ $r['jenis'] }}" data-status="{{ $r['status'] }}" data-periode="{{ $r['periode'] }}" :interactive="true">
                            @unless($isPegawai)
                            <x-ui.table-td>

                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                        {{ strtoupper(substr($r['nama'], 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ $r['nama'] }}</p>
                                        <p class="text-xs text-muted">{{ $r['nip'] }}</p>
                                    </div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <span class="text-sm text-ink font-sans">{{ $r['unit'] }}</span>
                            </x-ui.table-td>
                            @endunless
                            <x-ui.table-td>
                                <p class="text-sm font-semibold text-ink font-sans">{{ $r['jenis'] }}</p>
                                <p class="text-xs text-muted font-sans mt-0.5 max-w-xs truncate" title="{{ $r['alasan'] }}">{{ $r['alasan'] }}</p>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <p class="text-sm text-ink">{{ \Carbon\Carbon::parse($r['mulai'])->translatedFormat('d M') }} - {{ \Carbon\Carbon::parse($r['selesai'])->translatedFormat('d M Y') }}</p>

                                <p class="text-xs text-primary font-semibold mt-0.5 leading-none">{{ $r['hari'] }} Hari Kerja</p>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <div class="text-[11px] font-medium text-ink font-sans">
                                    @if($r['status'] === 'menunggu_approval' && $r['current_step_label'])
                                        <span>Menunggu <strong>{{ $r['current_step_label'] }}</strong></span>
                                    @elseif($r['current_step_label'])
                                        <span>Langkah aktif: <strong>{{ $r['current_step_label'] }}</strong></span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <x-ui.badge :variant="$statusVariant[$r['status']] ?? 'muted'" size="md" dot>
                                    {{ $statusLabel[$r['status']] }}
                                </x-ui.badge>
                            </x-ui.table-td>
                            <x-ui.table-td>
                                <div class="flex items-center justify-end gap-1.5">

                                    <x-ui.button href="{{ route('cuti.show', $r['id']) }}" variant="secondary" size="icon" title="Detail" aria-label="Detail">

                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </x-ui.button>
                                </div>
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @empty
                        <x-ui.table-row>
                            <x-ui.table-td colspan="{{ $isPegawai ? 5 : 7 }}" align="center" class="px-6 py-8 text-muted">
                                {{ $isPegawai ? 'Belum ada pengajuan cuti Anda yang sesuai dengan filter.' : 'Belum ada pengajuan cuti yang sesuai dengan filter.' }}
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <div class="flex items-center gap-3 text-sm text-muted">
                    <form method="GET" action="{{ route('cuti') }}" class="flex items-center gap-2">
                        @if(!$isPegawai && $search)
                            <input type="hidden" name="search" value="{{ $search }}">
                        @endif
                        @if($status)
                            <input type="hidden" name="status" value="{{ $status }}">
                        @endif
                        @if($jenis)
                            <input type="hidden" name="jenis" value="{{ $jenis }}">
                        @endif
                        @if(!$isPegawai && $unit)
                            <input type="hidden" name="unit" value="{{ $unit }}">
                        @endif
                        @if($periode)
                            <input type="hidden" name="periode" value="{{ $periode }}">
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
                        Menampilkan <span class="font-medium text-ink">{{ $riwayatCuti->firstItem() ?? 0 }}</span>
                        - <span class="font-medium text-ink">{{ $riwayatCuti->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium text-ink">{{ $riwayatCuti->total() }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $riwayatCuti->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>

    </div>

</x-layouts.app>
