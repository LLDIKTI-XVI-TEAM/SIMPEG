<x-layouts.app title="Daftar Bawahan" subtitle="Kelola dan pantau seluruh pegawai di bawah naungan Anda.">
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
        <form method="GET" action="{{ route('kepala-bagian.bawahan.index') }}" id="filter-form">
            <x-ui.filter-bar 
                searchId="search" 
                searchName="search" 
                :searchValue="$filters['search'] ?? ''"
                searchPlaceholder="Cari nama atau NIP..."
                class="lg:grid-cols-4">
                
                {{-- Filter Status --}}
                <div>
                    <label for="status" class="mb-1.5 block text-sm font-semibold text-ink">Status</label>
                    <x-form.select id="status" name="status" size="md">
                        <option value="">Semua status</option>
                        <option value="aktif" @selected(($filters['status'] ?? '') === 'aktif')>Aktif</option>
                        <option value="cuti" @selected(($filters['status'] ?? '') === 'cuti')>Cuti</option>
                    </x-form.select>
                </div>
                
                {{-- Per Page --}}
                <div>
                    <label for="per_page" class="mb-1.5 block text-sm font-semibold text-ink">Data per halaman</label>
                    <x-form.select id="per_page" name="per_page" size="md">
                        @foreach ([10, 25, 50] as $perPage)
                            <option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 10) === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                {{-- Action Buttons --}}
                <div class="flex items-end gap-2 lg:col-span-1">
                    <x-ui.button type="submit" variant="primary" size="md" class="w-full">Terapkan</x-ui.button>
                    <x-ui.button as="a" href="{{ route('kepala-bagian.bawahan.index') }}" variant="secondary" size="md" class="w-full text-center">Reset</x-ui.button>
                </div>
            </x-ui.filter-bar>
        </form>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">JABATAN & UNIT</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">JENIS PEGAWAI</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">STATUS</x-ui.table-th>
                            <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold text-right">AKSI</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body class="divide-y divide-border">
                        @forelse ($employees as $employee)
                            @php($position = $employee->positionHistories->first())
                            <x-ui.table-row class="hover:bg-soft transition-colors group">
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
                                                    class="block truncate text-sm font-semibold text-ink transition hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 rounded leading-tight">{{ $employee->nama_lengkap }}</a>
                                            </x-ui.tooltip>
                                            <p class="text-[11px] text-muted font-sans leading-none mt-1 font-mono">NIP. {{ $employee->nip }}</p>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <p class="text-sm font-medium text-ink leading-tight">{{ $employee->jabatan_terakhir ?: '-' }}</p>
                                    <p class="text-xs text-muted mt-1">{{ $position?->unitKerja?->nama ?? '-' }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <span class="text-sm font-medium text-ink">{{ $employee->jenisPegawai?->nama ?? '-' }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <x-ui.badge :variant="$employee->sedang_cuti ? 'info' : 'success'" size="sm" dot>{{ $employee->sedang_cuti ? 'Cuti' : ($employee->status_aktif ?: 'Tidak diketahui') }}</x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button as="a"
                                            href="{{ route('kepala-bagian.bawahan.show', $employee) }}"
                                            variant="secondary" size="icon" title="Lihat Detail">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                    d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z">
                                                </path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                        </x-ui.button>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td padding="comfortable" colspan="5" class="text-center text-sm text-muted py-10">
                                    Tidak ada bawahan langsung yang sesuai dengan filter.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            <div class="border-t border-border px-4 py-3 bg-soft/20">
                {{ $employees->links() }}
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>

