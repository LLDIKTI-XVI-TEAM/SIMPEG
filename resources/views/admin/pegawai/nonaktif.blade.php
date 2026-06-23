<x-layouts.app title="Data Nonaktif / Restore Pegawai">

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Pegawai Nonaktif & Restore</h2>
            <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Data Nonaktif</span>
            </nav>
        </div>
    </div>

    {{-- TABLE --}}
    <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-soft">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Pegawai</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Jabatan & Unit</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Gol. / Jenis</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr class="transition-colors hover:bg-soft/50" id="row-nonaktif-1">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-danger/10 text-sm font-bold text-danger">
                                    S
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink">Siraajuddin Laluv, OE., M.</p>
                                    <p class="font-mono text-xs text-muted">NIP. 19721231984 0 1062</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-sm font-medium text-ink">Pengolah Data dan Informasi</p>
                            <p class="text-xs text-muted">Bag. IT</p>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-sm font-bold text-ink leading-tight">IV/a</p>
                            <p class="text-xs font-bold text-primary mt-0.5 leading-tight">PNS</p>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold bg-danger/10 text-danger">
                                <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                                Nonaktif
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button 
                                onclick="restorePegawai('Siraajuddin Laluv, OE., M.')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-success/20 bg-surface px-3 py-1.5 text-xs font-bold text-success transition hover:bg-success/5 shadow-sm cursor-pointer"
                                title="Aktifkan Kembali"
                            >
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                </svg>
                                Aktifkan Kembali
                            </button>
                        </td>
                    </tr>
                    <tr class="hidden" id="empty-state">
                        <td colspan="5" class="px-6 py-8 text-center text-sm text-muted">
                            Tidak ada data pegawai nonaktif.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @push('scripts')
    <script>
        function restorePegawai(nama) {
            if (confirm(`Apakah Anda yakin ingin mengaktifkan kembali pegawai ${nama}?`)) {
                // Sembunyikan baris
                document.getElementById('row-nonaktif-1').classList.add('hidden');
                document.getElementById('empty-state').classList.remove('hidden');

                // Tampilkan banner notifikasi sukses
                const flashContainer = document.createElement('div');
                flashContainer.className = 'fixed top-4 right-4 z-50 max-w-md rounded-lg border border-success/20 bg-success p-4 text-white shadow-lg transition-opacity duration-300';
                flashContainer.innerHTML = `
                    <div class="flex items-center gap-3">
                        <svg class="h-5 w-5 shrink-0 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <div>
                            <p class="text-sm font-semibold">Berhasil!</p>
                            <p class="text-xs text-white/90">Pegawai ${nama} telah diaktifkan kembali dan dikembalikan ke daftar pegawai aktif.</p>
                        </div>
                    </div>
                `;
                document.body.appendChild(flashContainer);

                setTimeout(() => {
                    flashContainer.classList.add('opacity-0');
                    setTimeout(() => flashContainer.remove(), 300);
                }, 4000);
            }
        }
    </script>
    @endpush

</x-layouts.app>
