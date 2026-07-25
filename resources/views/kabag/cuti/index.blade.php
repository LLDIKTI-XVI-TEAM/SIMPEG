<x-layouts.app title="Cuti Bawahan" subtitle="Daftar seluruh permohonan cuti dari bawahan langsung Anda.">
    
    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Cuti Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'Cuti Bawahan']
            ]" />
        </div>
    </div>

    <x-ui.alert variant="info" title="Informasi Cuti" class="mb-6">
        Daftar ini memuat pengajuan cuti dari bawahan langsung Anda. Gunakan filter di bawah untuk menyaring data.
    </x-ui.alert>

    <!-- FILTER & PENCARIAN -->
    <form method="GET" action="{{ route('kepala-bagian.cuti.index') }}" id="filter-form" class="mb-6">
        <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
        <x-ui.filter-bar searchId="search" searchName="search" :searchValue="$filters['search'] ?? ''" searchPlaceholder="Cari nama atau NIP" class="lg:grid-cols-5">
            
            <!-- Filter Status -->
            <div>
                <x-form.select size="md" name="status" id="status" onchange="this.form.submit()">
                    <option value="menunggu_approval" @selected(($filters['status'] ?? '') === 'menunggu_approval')>Menunggu Keputusan</option>
                    <option value="all" @selected(($filters['status'] ?? '') === 'all' || (request()->has('status') && is_null($filters['status'] ?? null)))>Semua Status</option>
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
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th padding="comfortable" align="center" class="w-14 text-xs text-muted font-bold uppercase tracking-wider">NO</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">
                            <div class="flex items-center gap-1.5">PEGAWAI <svg class="w-3.5 h-3.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg></div>
                        </x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">
                            <div class="flex items-center gap-1.5">JENIS CUTI <svg class="w-3.5 h-3.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg></div>
                        </x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">DURASI & TANGGAL</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">STATUS</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider text-right">AKSI</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body class="divide-y divide-border">
                    @forelse($leaves as $leave)
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
                        <x-ui.table-td align="center" padding="comfortable" class="text-sm font-semibold text-muted">
                            {{ ($leaves->currentPage() - 1) * $leaves->perPage() + $loop->iteration }}
                        </x-ui.table-td>
                        
                        <!-- NAMA PEGAWAI -->
                        <x-ui.table-td padding="comfortable">
                            <div class="flex items-center gap-3">
                                <x-ui.tooltip text="Buka detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}" position="right">
                                    <a href="{{ route('kepala-bagian.cuti.show', $leave) }}"
                                        class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-xs font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                        aria-label="Buka detail profil {{ $leave->employee?->nama_lengkap ?? '' }}">
                                        <span>{{ substr($leave->employee?->nama_lengkap ?? '?', 0, 1) }}</span>
                                    </a>
                                </x-ui.tooltip>
                                <div class="min-w-0">
                                    <x-ui.tooltip text="Buka detail pengajuan cuti {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}" position="right">
                                        <a href="{{ route('kepala-bagian.cuti.show', $leave) }}" class="block truncate text-sm font-semibold text-ink transition-colors hover:text-primary focus:outline-none rounded leading-tight">
                                            {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}
                                        </a>
                                    </x-ui.tooltip>
                                    <p class="text-xs text-muted">NIP. {{ $leave->employee?->nip ?? '-' }}</p>
                                </div>
                            </div>
                        </x-ui.table-td>
                        
                        <!-- JENIS CUTI & TGL AJUKAN -->
                        <x-ui.table-td padding="comfortable">
                            <p class="text-sm font-medium text-ink leading-tight">{{ $leave->jenisCuti?->nama ?? '-' }}</p>
                            <p class="text-xs text-muted mt-1">Ajukan: {{ $leave->created_at?->translatedFormat('d M Y') ?? '-' }}</p>
                        </x-ui.table-td>

                        <!-- DURASI & TANGGAL -->
                        <x-ui.table-td padding="comfortable">
                            <p class="text-sm font-medium text-ink leading-tight">{{ $leave->jumlah_hari_kerja }} hari</p>
                            <p class="text-xs text-muted mt-1">{{ $leave->tanggal_mulai?->translatedFormat('d M') ?? '-' }} s/d {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p>
                        </x-ui.table-td>

                        <!-- STATUS -->
                        <x-ui.table-td padding="comfortable">
                            <span class="inline-flex items-center gap-1.5 font-medium font-sans leading-none px-2.5 py-1 text-xs rounded-md {{ $statusProps['variant'] === 'success' ? 'bg-success/10 text-success' : ($statusProps['variant'] === 'danger' ? 'bg-danger/10 text-danger' : ($statusProps['variant'] === 'info' ? 'bg-info/10 text-info' : 'bg-warning/10 text-warning')) }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $statusProps['variant'] === 'success' ? 'bg-success' : ($statusProps['variant'] === 'danger' ? 'bg-danger' : ($statusProps['variant'] === 'info' ? 'bg-info' : 'bg-warning')) }}"></span>
                                {{ $statusProps['label'] }}
                            </span>
                        </x-ui.table-td>

                        <!-- AKSI -->
                        <x-ui.table-td align="right" padding="comfortable">
                            <div class="flex items-center justify-end gap-1.5">
                                <x-ui.tooltip text="Lihat Detail" position="top-end">
                                    <a href="{{ route('kepala-bagian.cuti.show', $leave) }}"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                    </a>
                                </x-ui.tooltip>
                                <x-ui.tooltip text="Akses Edit Dibatasi" position="top-end">
                                    <button type="button" disabled class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition opacity-50 cursor-not-allowed shadow-sm">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125"></path>
                                        </svg>
                                    </button>
                                </x-ui.tooltip>
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @empty
                    <x-ui.table-row>
                        <x-ui.table-td colspan="6" align="center" class="px-6 py-8 text-muted text-sm">
                            @if(($filters['status'] ?? '') === 'menunggu_approval')
                                Tidak ada pengajuan Cuti yang menunggu tindakan Anda.
                            @else
                                Belum ada pengajuan cuti bawahan yang sesuai dengan filter.
                            @endif
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        {{-- TABLE FOOTER --}}
        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted">Tampilkan</span>
                    <select onchange="updatePerPage(this.value)" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        <option value="10" {{ request('per_page', 10) == 10 ? 'selected' : '' }}>10</option>
                        <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                    </select>
                    <span class="text-sm text-muted">data per halaman</span>
                </div>
                @if($leaves->total() > 0)
                <p class="text-sm text-muted hidden sm:block">
                    Menampilkan <span class="font-semibold text-ink">{{ $leaves->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $leaves->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $leaves->total() }}</span> hasil
                </p>
                @endif
            </div>

            <div class="w-full sm:w-auto flex justify-end">
                {{ $leaves->appends(request()->query())->links('vendor.pagination.simpeg') }}
            </div>
        </div>
    </x-ui.card>
    
    @push('scripts')
    <script>
    function updatePerPage(val) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', val);
        url.searchParams.delete('page');
        window.location.assign(url.href);
    }
    </script>
    @endpush
</x-layouts.app>
