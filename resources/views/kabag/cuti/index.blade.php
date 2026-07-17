<x-layouts.app title="Pengajuan Cuti Bawahan" subtitle="Daftar seluruh permohonan cuti dari bawahan langsung Anda.">
    
    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Pengajuan Cuti Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'Cuti Bawahan']
            ]" />
        </div>
    </div>

    <x-ui.alert variant="info" title="Informasi Cuti" class="mb-6">
        Daftar ini memuat seluruh riwayat dan pengajuan cuti dari bawahan langsung Anda.
    </x-ui.alert>

    <!-- FILTER & PENCARIAN -->
    <form method="GET" action="{{ route('kepala-bagian.cuti.index') }}">
        <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
        <x-ui.filter-bar class="!grid-cols-1 sm:!grid-cols-2 lg:!grid-cols-5" searchId="search" searchName="search" searchValue="{{ $filters['search'] ?? '' }}" searchPlaceholder="Cari nama pegawai atau NIP..." searchCols="lg:col-span-1">
            
            <!-- Filter Status -->
            <div>
                <x-form.select size="md" name="status" id="status" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="menunggu_approval" @selected(($filters['status'] ?? '') === 'menunggu_approval')>Menunggu Keputusan</option>
                    <option value="disetujui" @selected(($filters['status'] ?? '') === 'disetujui')>Disetujui</option>
                    <option value="perlu_perubahan" @selected(($filters['status'] ?? '') === 'perlu_perubahan')>Perubahan</option>
                    <option value="ditangguhkan" @selected(($filters['status'] ?? '') === 'ditangguhkan')>Ditangguhkan</option>
                    <option value="tidak_disetujui" @selected(($filters['status'] ?? '') === 'tidak_disetujui')>Tidak Disetujui</option>
                </x-form.select>
            </div>
            
            <!-- Filter Jenis Cuti -->
            <div>
                <x-form.select size="md" name="jenis_cuti_id" id="jenis_cuti_id" onchange="this.form.submit()">
                    <option value="">Semua Jenis Cuti</option>
                    @foreach ($jenisCutiOptions as $jenisCuti)
                        <option value="{{ $jenisCuti->id }}" @selected(($filters['jenis_cuti_id'] ?? '') == $jenisCuti->id)>{{ $jenisCuti->nama }}</option>
                    @endforeach
                </x-form.select>
            </div>
            
            <!-- Filter Tahun -->
            <div>
                <x-form.select size="md" name="tahun" id="tahun" onchange="this.form.submit()">
                    <option value="">Semua Tahun</option>
                    @for ($y = date('Y'); $y >= date('Y') - 5; $y--)
                        <option value="{{ $y }}" @selected(($filters['tahun'] ?? '') == $y)>{{ $y }}</option>
                    @endfor
                </x-form.select>
            </div>

            <!-- Filter Bulan -->
            <div>
                <x-form.select size="md" name="bulan" id="bulan" onchange="this.form.submit()">
                    <option value="">Semua Bulan</option>
                    @foreach (range(1, 12) as $month)
                        <option value="{{ $month }}" @selected((string) ($filters['bulan'] ?? '') === (string) $month)>{{ \Carbon\Carbon::create()->month($month)->translatedFormat('F') }}</option>
                    @endforeach
                </x-form.select>
            </div>
        </x-ui.filter-bar>
    </form>

    <!-- MAIN TABLE -->
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="border-b border-border px-6 py-4 bg-surface">
            <h3 class="text-sm font-semibold text-ink font-sans">Daftar Permohonan Cuti Bawahan</h3>
            <p class="text-xs text-muted">Menampilkan seluruh data pengajuan cuti bawahan.</p>
        </div>
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head class="border-b border-border">
                    <x-ui.table-row>
                        <x-ui.table-th align="center" class="px-6 py-3.5 w-14">NO</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Pegawai</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Jenis Cuti</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Durasi & Tanggal</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Status</x-ui.table-th>
                        <x-ui.table-th align="right" class="px-6 py-3.5">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @forelse($leaves as $index => $leave)
                    @php
                        $statusProps = match ($leave->status) {
                            'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
                            'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                            'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
                            'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
                            default => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
                        };
                    @endphp
                    <x-ui.table-row class="hover:bg-soft transition-colors group">
                        <!-- NOMOR -->
                        <x-ui.table-td align="center" padding="comfortable" class="text-sm font-semibold text-muted">
                            {{ ($leaves->currentPage() - 1) * $leaves->perPage() + $loop->iteration }}
                        </x-ui.table-td>
                        
                        <!-- NAMA PEGAWAI -->
                        <x-ui.table-td padding="comfortable">
                            <x-ui.tooltip text="Buka detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}" position="right">
                                <a href="{{ route('kepala-bagian.cuti.show', $leave) }}" class="block truncate text-sm font-semibold text-ink transition-colors hover:text-primary focus:outline-none rounded leading-tight">
                                    {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}
                                </a>
                            </x-ui.tooltip>
                            <p class="text-xs text-muted">NIP. {{ $leave->employee?->nip ?? '-' }}</p>
                        </x-ui.table-td>
                        
                        <!-- JENIS CUTI & TGL AJUKAN -->
                        <x-ui.table-td padding="comfortable" class="text-sm font-medium text-ink">
                            {{ $leave->jenisCuti?->nama ?? '-' }}
                            <div class="mt-1 text-[10px] font-semibold text-muted font-sans">
                                Ajukan: {{ $leave->created_at?->translatedFormat('d M Y') ?? '-' }}
                            </div>
                        </x-ui.table-td>

                        <!-- DURASI & TANGGAL -->
                        <x-ui.table-td padding="comfortable" class="text-sm">
                            {{ $leave->jumlah_hari_kerja }} hari
                            <br>
                            <span class="text-[10px] text-muted font-sans">{{ $leave->tanggal_mulai?->translatedFormat('d M') ?? '-' }} - {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</span>
                        </x-ui.table-td>

                        <!-- STATUS -->
                        <x-ui.table-td padding="comfortable">
                            <x-ui.badge :variant="$statusProps['variant']" size="sm" dot>{{ $statusProps['label'] }}</x-ui.badge>
                        </x-ui.table-td>

                        <!-- AKSI -->
                        <x-ui.table-td align="right" padding="comfortable">
                            <x-ui.button as="a" href="{{ route('kepala-bagian.cuti.show', $leave) }}" variant="secondary" size="icon" title="Lihat Detail" tooltip-position="top-end" aria-label="Lihat Detail">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </x-ui.button>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @empty
                    <x-ui.table-row>
                        <x-ui.table-td colspan="6" class="px-6 py-10 text-center text-sm text-muted">
                            Tidak ada data cuti bawahan yang ditemukan.
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
            <form method="GET" action="{{ route('kepala-bagian.cuti.index') }}" class="flex items-center gap-3 text-sm text-muted">
                <input type="hidden" name="search" value="{{ request('search') }}">
                <input type="hidden" name="status" value="{{ request('status') }}">
                <input type="hidden" name="jenis_cuti_id" value="{{ request('jenis_cuti_id') }}">
                <input type="hidden" name="tahun" value="{{ request('tahun') }}">
                <input type="hidden" name="bulan" value="{{ request('bulan') }}">
                <span class="whitespace-nowrap">Tampilkan</span>
                <select name="per_page" onchange="this.form.submit()" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                    @foreach ([10, 25, 50, 100] as $perPage)
                        <option value="{{ $perPage }}" @selected((int) request('per_page', 10) === $perPage)>{{ $perPage }}</option>
                    @endforeach
                </select>
                <span class="hidden sm:inline">data</span>

                {{-- Meta Info --}}
                @if($leaves->total() > 0)
                    <div class="hidden md:block ml-2 border-l border-border pl-4">
                        Menampilkan <span class="font-medium text-ink">{{ $leaves->firstItem() }}</span>
                        - <span class="font-medium text-ink">{{ $leaves->lastItem() }}</span>
                        dari <span class="font-medium text-ink">{{ $leaves->total() }}</span>
                    </div>
                @endif
            </form>

            <div class="w-full sm:w-auto flex justify-end">
                {{ $leaves->appends(request()->query())->links('vendor.pagination.simpeg') }}
            </div>
        </div>
    </x-ui.card>
</x-layouts.app>
