<x-layouts.app title="Daftar Bawahan" subtitle="Data read-only bawahan langsung yang ditetapkan kepada Anda.">
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Daftar Bawahan</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'Daftar Bawahan'],
            ]" />
        </div>

        <x-ui.card>
            <form method="GET" action="{{ route('kepala-bagian.bawahan.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <label for="search" class="mb-1 block text-sm font-medium text-ink">Cari bawahan</label>
                    <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" type="search" placeholder="Nama atau NIP" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                </div>
                <div>
                    <label for="status" class="mb-1 block text-sm font-medium text-ink">Status saat ini</label>
                    <select id="status" name="status" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua status</option>
                        <option value="aktif" @selected(($filters['status'] ?? '') === 'aktif')>Aktif</option>
                        <option value="cuti" @selected(($filters['status'] ?? '') === 'cuti')>Cuti</option>
                    </select>
                </div>
                <div>
                    <label for="per_page" class="mb-1 block text-sm font-medium text-ink">Data per halaman</label>
                    <select id="per_page" name="per_page" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        @foreach ([10, 25, 50] as $perPage)
                            <option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 10) === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button>
                    <a href="{{ route('kepala-bagian.bawahan.index') }}" class="ml-2 inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Reset</a>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Daftar bawahan langsung</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3">Pegawai</th>
                            <th scope="col" class="px-5 py-3">Jabatan</th>
                            <th scope="col" class="px-5 py-3">Unit Kerja</th>
                            <th scope="col" class="px-5 py-3">Jenis Pegawai</th>
                            <th scope="col" class="px-5 py-3">Status</th>
                            <th scope="col" class="px-5 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($employees as $employee)
                            @php($position = $employee->positionHistories->first())
                            <tr class="transition-colors hover:bg-soft/60">
                                <th scope="row" class="px-5 py-3 text-left">
                                    <p class="font-semibold text-ink">{{ $employee->nama_lengkap }}</p>
                                    <p class="mt-1 font-mono text-xs font-normal text-muted">{{ $employee->nip }}</p>
                                </th>
                                <td class="px-5 py-3 text-ink">{{ $employee->jabatan_terakhir ?: '-' }}</td>
                                <td class="px-5 py-3 text-ink">{{ $position?->unitKerja?->nama ?? '-' }}</td>
                                <td class="px-5 py-3 text-ink">{{ $employee->jenisPegawai?->nama ?? '-' }}</td>
                                <td class="px-5 py-3"><x-ui.badge :variant="$employee->sedang_cuti ? 'info' : 'success'" size="sm" dot>{{ $employee->sedang_cuti ? 'Cuti' : ($employee->status_aktif ?: 'Tidak diketahui') }}</x-ui.badge></td>
                                <td class="px-5 py-3 text-right"><a href="{{ route('kepala-bagian.bawahan.show', $employee) }}" aria-label="Detail ringkas {{ $employee->nama_lengkap }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-muted">Tidak ada bawahan langsung yang sesuai dengan filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-4 py-3">{{ $employees->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
