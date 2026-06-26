<x-layouts.app title="Data Nonaktif / Restore Pegawai">
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-1.5">
            <h2 class="font-sans text-2xl font-bold text-ink">Pegawai Nonaktif & Restore</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Data Nonaktif</span>
            </nav>
        </div>

        <section class="rounded-lg border border-border bg-surface p-5 shadow-sm">
            <form method="GET" action="{{ route('data-nonaktif') }}"
                class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex-1 space-y-1.5">
                    <label for="search-nonaktif" class="font-sans text-xs font-bold uppercase tracking-wider text-muted">Pencarian</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input id="search-nonaktif" name="search" type="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Cari nama atau NIP" oninput="filterNonaktifRows()"
                            class="h-11 w-full rounded-lg border border-border bg-surface pl-10 pr-4 font-sans text-sm text-ink shadow-sm outline-none transition placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/20">
                    </div>
                </div>

                <button type="submit"
                    class="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-5 text-sm font-bold text-white shadow-sm transition hover:bg-primary/90">
                    Terapkan Filter
                </button>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-6 py-4">
                <h3 class="font-sans text-sm font-bold uppercase tracking-wider text-ink">Daftar Pegawai Non-Aktif</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="bg-soft/80">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">Pegawai</th>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">Jabatan & Unit</th>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">Gol. / Jenis</th>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-bold uppercase tracking-wider text-muted">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($employees as $employee)
                            @php
                                $latestPosition = $employee->positionHistories->first();
                                $unitKerja = $latestPosition?->unitKerja?->nama ?? '-';
                                $initial = mb_substr($employee->nama_lengkap, 0, 1);
                            @endphp
                            <tr class="nonaktif-record transition-colors hover:bg-soft/50"
                                data-nama="{{ mb_strtolower($employee->nama_lengkap) }}"
                                data-nip="{{ mb_strtolower($employee->nip) }}">
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-sm font-bold text-danger">
                                            {{ $initial }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate font-sans text-sm font-bold text-ink">{{ $employee->nama_lengkap }}</p>
                                            <p class="mt-0.5 font-mono text-xs text-muted">NIP. {{ $employee->nip }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="font-sans text-sm font-semibold text-ink">{{ $employee->jabatan_terakhir ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs text-muted">{{ $unitKerja }}</p>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="text-sm font-bold leading-tight text-ink">{{ $employee->golongan_terakhir ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs font-bold leading-tight text-primary">{{ $employee->jenisPegawai?->nama ?? '-' }}</p>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-danger">
                                        <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                                        Non-Aktif
                                    </span>
                                    <p class="mt-1 text-xs text-muted">{{ optional($employee->deleted_at)->format('d/m/Y H:i') }}</p>
                                </td>
                                <td class="px-6 py-5 text-right">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <form method="POST" action="{{ route('pegawai.restore', $employee->id) }}"
                                            onsubmit="return confirm('Aktifkan kembali pegawai ini?')">
                                            @csrf
                                            <button type="submit"
                                                class="inline-flex h-9 items-center gap-2 rounded-lg border border-success/20 bg-surface px-3.5 text-xs font-bold text-success shadow-sm transition hover:bg-success/5"
                                                title="Aktifkan Kembali - Admin Kepegawaian/Super Admin">
                                                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                                </svg>
                                                Aktifkan Kembali
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr id="empty-state">
                                <td colspan="5" class="px-6 py-10 text-center text-sm text-muted">
                                    Tidak ada data pegawai non-aktif.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($employees->hasPages())
                <div class="border-t border-border px-6 py-4">
                    {{ $employees->links() }}
                </div>
            @endif
        </section>
    </div>

    <script>
        function filterNonaktifRows() {
            const input = document.getElementById('search-nonaktif');
            const query = input ? input.value.toLowerCase().trim() : '';
            const records = document.querySelectorAll('.nonaktif-record');
            let visibleCount = 0;

            records.forEach((record) => {
                const rowText = `${record.dataset.nama || ''} ${record.dataset.nip || ''}`.toLowerCase();
                const isMatch = !query || rowText.includes(query);
                record.classList.toggle('hidden', !isMatch);

                if (isMatch) {
                    visibleCount++;
                }
            });

            const emptyState = document.getElementById('empty-state');
            if (emptyState && records.length > 0) {
                emptyState.classList.toggle('hidden', visibleCount > 0);
            }
        }
    </script>
</x-layouts.app>
