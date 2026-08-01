<x-layouts.app title="Daftar Bawahan" subtitle="Pantau seluruh pegawai bawahan langsung Anda.">
    <div class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Daftar Bawahan</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                    ['label' => 'Daftar Bawahan']
                ]" />
            </div>
        </div>

        {{-- FILTER BAR --}}
        <form method="GET" action="{{ route('kepala-bagian.bawahan.index') }}" id="filter-form" class="mb-6">
            <input type="hidden" name="per_page" value="{{ request('per_page', 10) }}">
            <x-ui.filter-bar 
                searchId="search" 
                searchName="search" 
                :searchValue="$filters['search'] ?? ''"
                searchPlaceholder="Cari nama atau NIP"
                class="lg:grid-cols-5">
                
                {{-- Filter Golongan --}}
                <div>
                    <x-form.select id="golongan" name="golongan" size="md" onchange="this.form.submit()">
                        <option value="">Semua Golongan</option>
                        <option value="I" @selected(($filters['golongan'] ?? '') === 'I')>Golongan I</option>
                        <option value="II" @selected(($filters['golongan'] ?? '') === 'II')>Golongan II</option>
                        <option value="III" @selected(($filters['golongan'] ?? '') === 'III')>Golongan III</option>
                        <option value="IV" @selected(($filters['golongan'] ?? '') === 'IV')>Golongan IV</option>
                    </x-form.select>
                </div>

                {{-- Filter Unit --}}
                <div>
                    <x-form.select id="unit_kerja_id" name="unit_kerja_id" size="md" onchange="this.form.submit()">
                        <option value="">Semua Unit</option>
                        @foreach($unitKerjas as $unit)
                            <option value="{{ $unit->id }}" @selected(($filters['unit_kerja_id'] ?? '') == $unit->id)>{{ $unit->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                {{-- Filter Jenis --}}
                <div>
                    <x-form.select id="jenis_pegawai_id" name="jenis_pegawai_id" size="md" onchange="this.form.submit()">
                        <option value="">Semua Jenis</option>
                        @foreach($jenisPegawais as $jenis)
                            <option value="{{ $jenis->id }}" @selected(($filters['jenis_pegawai_id'] ?? '') == $jenis->id)>{{ $jenis->nama }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                {{-- Filter Status --}}
                <div>
                    <x-form.select id="status" name="status" size="md" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="aktif" @selected(($filters['status'] ?? '') === 'aktif')>Aktif</option>
                        <option value="cuti" @selected(($filters['status'] ?? '') === 'cuti')>Cuti</option>
                    </x-form.select>
                </div>
            </x-ui.filter-bar>
        </form>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="comfortable" align="center" class="w-14 text-xs text-muted font-bold">NO</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold">
                                <div class="flex items-center gap-1.5">Pegawai <svg class="w-3.5 h-3.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg></div>
                            </x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold">
                                <div class="flex items-center gap-1.5">Jabatan & Unit <svg class="w-3.5 h-3.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg></div>
                            </x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold">
                                <div class="flex items-center gap-1.5">Gol. / Jenis <svg class="w-3.5 h-3.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg></div>
                            </x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">TMT</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">STATUS</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider">DOKUMEN</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted font-bold uppercase tracking-wider text-right">AKSI</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="divide-y divide-border">
                        @forelse ($employees as $employee)
                            @php($position = $employee->positionHistories->first())
                            <x-ui.table-row class="hover:bg-soft transition-colors group">
                                <x-ui.table-td align="center" padding="comfortable" class="text-sm font-semibold text-muted">
                                    {{ ($employees->currentPage() - 1) * $employees->perPage() + $loop->iteration }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <div class="flex items-center gap-3">
                                        <x-ui.tooltip text="Buka detail {{ $employee->nama_lengkap }}" position="right">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $employee) }}"
                                                class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-xs font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                                aria-label="Buka detail profil {{ $employee->nama_lengkap }}">
                                                <span>{{ substr($employee->nama_lengkap, 0, 1) }}</span>
                                            </a>
                                        </x-ui.tooltip>
                                        <div class="min-w-0">
                                            <x-ui.tooltip text="Buka detail {{ $employee->nama_lengkap }}" position="right">
                                                <a href="{{ route('kepala-bagian.bawahan.show', $employee) }}"
                                                    class="block truncate text-sm font-semibold text-ink transition hover:text-primary focus:outline-none rounded leading-tight">{{ $employee->nama_lengkap }}</a>
                                            </x-ui.tooltip>
                                            <p class="text-xs text-muted">NIP. {{ $employee->nip }}</p>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <p class="text-sm font-medium text-ink leading-tight">{{ $employee->jabatan_terakhir ?: '-' }}</p>
                                    <p class="text-xs text-muted mt-1">{{ $position?->unitKerja?->nama ?? '-' }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <span class="text-sm font-medium text-ink">{{ $position?->golongan?->nama ?? '-' }} / {{ $employee->jenisPegawai?->nama ?? '-' }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <p class="text-sm text-ink">{{ $position?->tmt_jabatan ? \Carbon\Carbon::parse($position->tmt_jabatan)->translatedFormat('d M Y') : '-' }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <span class="inline-flex items-center gap-1.5 font-medium font-sans leading-none px-2.5 py-1 text-xs rounded-md {{ $employee->sedang_cuti ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success' }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $employee->sedang_cuti ? 'bg-warning' : 'bg-success' }}"></span>
                                        {{ $employee->sedang_cuti ? 'Cuti' : 'Aktif' }}
                                    </span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <button type="button" disabled class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap bg-muted/20 text-muted opacity-80 cursor-not-allowed">
                                        <span class="h-1.5 w-1.5 rounded-full bg-muted"></span>
                                        Belum Ada
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                    </button>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.tooltip text="Lihat Detail" position="top-end">
                                            <a href="{{ route('kepala-bagian.bawahan.show', $employee) }}"
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
                                <x-ui.table-td colspan="8" align="center" class="px-6 py-8 text-muted text-sm">
                                    Belum ada data bawahan langsung yang sesuai dengan filter.
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
                    @if($employees->total() > 0)
                    <p class="text-sm text-muted hidden sm:block">
                        Menampilkan <span class="font-semibold text-ink">{{ $employees->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $employees->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $employees->total() }}</span> hasil
                    </p>
                    @endif
                </div>

                <div class="w-full sm:w-auto flex justify-end">
                    {{ $employees->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>
    </div>

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
