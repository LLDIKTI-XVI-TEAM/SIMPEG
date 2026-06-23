<x-layouts.app title="Data Nonaktif / Restore Pegawai">

    <div class="mx-auto max-w-7xl space-y-6">
        {{-- Header --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Pegawai Nonaktif & Restore</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Data Nonaktif</span>
            </nav>
        </div>

        {{-- Filter Bar --}}
        <section class="rounded-lg border border-border bg-surface p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex-1 space-y-1.5">
                    <label for="search-nonaktif"
                        class="text-xs font-bold uppercase tracking-wider text-muted font-sans">Pencarian</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input id="search-nonaktif" type="search" placeholder="Cari nama atau NIP"
                            oninput="filterNonaktifRows()"
                            class="h-11 w-full rounded-lg border border-border bg-surface pl-10 pr-4 text-sm text-ink shadow-sm outline-none transition placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/20 font-sans">
                    </div>
                </div>

                <div class="w-full space-y-1.5 lg:w-64">
                    <label for="filter-alasan"
                        class="text-xs font-bold uppercase tracking-wider text-muted font-sans">Alasan</label>
                    <select id="filter-alasan" onchange="filterNonaktifRows()"
                        class="h-11 w-full rounded-lg border border-border bg-surface px-3 text-sm font-semibold text-ink shadow-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua alasan</option>
                        <option value="pensiun">Pensiun</option>
                        <option value="mutasi">Mutasi</option>
                        <option value="non-aktif">Non-Aktif</option>
                    </select>
                </div>
            </div>
        </section>

        {{-- Table --}}
        <section class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-sm font-bold uppercase tracking-wider text-ink font-sans">Daftar Pegawai Non-Aktif</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="bg-soft/80">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">
                                Pegawai</th>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">
                                Jabatan & Unit</th>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">Gol.
                                / Jenis</th>
                            <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider text-muted">
                                Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-bold uppercase tracking-wider text-muted">
                                Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr class="nonaktif-record transition-colors hover:bg-soft/50" id="row-nonaktif-1"
                            data-record-id="nonaktif-1" data-nama="Yucna Dara, S.P., M.M." data-nip="19840120099 2 002"
                            data-alasan="pensiun">
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-sm font-bold text-danger">
                                        Y</div>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-ink font-sans">Yucna Dara, S.P., M.M.
                                        </p>
                                        <p class="mt-0.5 font-mono text-xs text-muted">NIP. 19840120099 2 002</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <p class="text-sm font-semibold text-ink font-sans">Analis Ahli Pertama</p>
                                <p class="mt-0.5 text-xs text-muted">Bag. Keuangan</p>
                            </td>
                            <td class="px-6 py-5">
                                <p class="text-sm font-bold leading-tight text-ink">III/b</p>
                                <p class="mt-0.5 text-xs font-bold leading-tight text-primary">PNS</p>
                            </td>
                            <td class="px-6 py-5">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">
                                    <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                                    Non-Aktif
                                </span>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="inline-flex items-center justify-end gap-2">
                                    <a href="{{ route('pegawai.show', ['id' => 7]) }}"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-ink shadow-sm transition hover:border-primary/20 hover:bg-soft hover:text-primary"
                                        title="Buka Detail Profil">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                    <button type="button"
                                        onclick="showNonaktifDetail('Yucna Dara, S.P., M.M.', 'Pensiun', 'BUP sudah tercapai', '2026-06-15', 'Admin Kepegawaian', '2026-06-15 09:30 WITA', 'Soft delete karena pensiun')"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-primary shadow-sm transition hover:border-primary/20 hover:bg-primary/5"
                                        title="Lihat Detail Non-Aktif">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12s-3.75 6.75-9.75 6.75S2.25 12 2.25 12Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </button>
                                    <button type="button"
                                        onclick="restorePegawai('Yucna Dara, S.P., M.M.', 'nonaktif-1')"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg border border-success/20 bg-surface px-3.5 text-xs font-bold text-success shadow-sm transition hover:bg-success/5"
                                        title="Aktifkan Kembali - Admin Kepegawaian/Super Admin">
                                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                        </svg>
                                        Aktifkan Kembali
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr class="nonaktif-record transition-colors hover:bg-soft/50" id="row-nonaktif-2"
                            data-record-id="nonaktif-2" data-nama="Nadia Kusuma" data-nip="19950822202001 2 002"
                            data-alasan="mutasi">
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-sm font-bold text-danger">
                                        N</div>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-ink font-sans">Nadia Kusuma</p>
                                        <p class="mt-0.5 font-mono text-xs text-muted">NIP. 19950822202001 2 002</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <p class="text-sm font-semibold text-ink font-sans">Pengelola Kepegawaian</p>
                                <p class="mt-0.5 text-xs text-muted">Bag. Kepegawaian</p>
                            </td>
                            <td class="px-6 py-5">
                                <p class="text-sm font-bold leading-tight text-ink">II/c</p>
                                <p class="mt-0.5 text-xs font-bold leading-tight text-primary">PPPK</p>
                            </td>
                            <td class="px-6 py-5">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">
                                    <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                                    Non-Aktif
                                </span>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="inline-flex items-center justify-end gap-2">
                                    <a href="{{ route('pegawai.show', ['id' => 6]) }}"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-ink shadow-sm transition hover:border-primary/20 hover:bg-soft hover:text-primary"
                                        title="Buka Detail Profil">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>
                                    <button type="button"
                                        onclick="showNonaktifDetail('Nadia Kusuma', 'Mutasi', 'Pindah unit kerja', '2026-05-28', 'Super Admin', '2026-05-28 14:10 WITA', 'Soft delete karena mutasi')"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-primary shadow-sm transition hover:border-primary/20 hover:bg-primary/5"
                                        title="Lihat Detail Non-Aktif">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12s-3.75 6.75-9.75 6.75S2.25 12 2.25 12Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </button>
                                    <button type="button" onclick="restorePegawai('Nadia Kusuma', 'nonaktif-2')"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg border border-success/20 bg-surface px-3.5 text-xs font-bold text-success shadow-sm transition hover:bg-success/5"
                                        title="Aktifkan Kembali - Admin Kepegawaian/Super Admin">
                                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                        </svg>
                                        Aktifkan Kembali
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr class="hidden" id="empty-state">
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-muted">
                                Tidak ada data pegawai non-aktif.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Modal Detail --}}
        <div id="nonaktif-detail-modal"
            class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/30 p-4 backdrop-blur-sm"
            onclick="closeNonaktifDetail(event)">
            <div id="nonaktif-detail-panel"
                class="w-full max-w-xl scale-95 rounded-lg border border-border bg-surface shadow-xl transition duration-150 ease-out"
                onclick="event.stopPropagation()">
                <div class="flex items-start justify-between gap-4 border-b border-border px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-wider text-muted">Detail Pegawai Non-Aktif</p>
                        <h3 id="detail-nama" class="mt-1 truncate text-lg font-bold text-ink font-sans"></h3>
                    </div>
                    <button type="button" onclick="closeNonaktifDetail()"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink"
                        title="Tutup">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-5 px-6 py-5">
                    <dl class="space-y-3 text-sm">
                        <div class="grid grid-cols-[minmax(110px,1fr)_minmax(0,1.4fr)] items-start gap-4">
                            <dt class="text-muted">Alasan Non-Aktif</dt>
                            <dd id="detail-alasan" class="text-right font-bold text-ink"></dd>
                        </div>
                        <div class="grid grid-cols-[minmax(110px,1fr)_minmax(0,1.4fr)] items-start gap-4">
                            <dt class="text-muted">Catatan</dt>
                            <dd id="detail-catatan" class="text-right font-semibold text-ink"></dd>
                        </div>
                        <div class="grid grid-cols-[minmax(110px,1fr)_minmax(0,1.4fr)] items-start gap-4">
                            <dt class="text-muted">Tanggal Non-Aktif</dt>
                            <dd class="text-right">
                                <p id="detail-tanggal" class="font-mono text-sm font-bold text-ink"></p>
                                <p class="mt-0.5 text-xs text-muted">deleted_at</p>
                            </dd>
                        </div>
                        <div class="grid grid-cols-[minmax(110px,1fr)_minmax(0,1.4fr)] items-center gap-4">
                            <dt class="text-muted">Status</dt>
                            <dd class="text-right">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">
                                    <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                                    Non-Aktif
                                </span>
                            </dd>
                        </div>
                        <div class="grid grid-cols-[minmax(110px,1fr)_minmax(0,1.4fr)] items-center gap-4">
                            <dt class="text-muted">Status EWS</dt>
                            <dd class="text-right">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-warning/10 px-3 py-1 text-xs font-bold text-warning">
                                    <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>
                                    EWS: Dikecualikan
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <div class="rounded-lg border border-border bg-soft/40 p-4">
                        <p class="mb-3 text-xs font-bold uppercase tracking-wider text-muted">Audit Log Ringkas</p>
                        <dl class="space-y-2.5 text-sm">
                            <div class="grid grid-cols-[minmax(120px,1fr)_minmax(0,1.3fr)] items-start gap-4">
                                <dt class="text-muted">Dinonaktifkan oleh</dt>
                                <dd id="detail-aktor" class="text-right font-semibold text-ink"></dd>
                            </div>
                            <div class="grid grid-cols-[minmax(120px,1fr)_minmax(0,1.3fr)] items-start gap-4">
                                <dt class="text-muted">Tanggal aksi</dt>
                                <dd id="detail-aksi-tanggal"
                                    class="text-right font-mono text-xs font-semibold text-ink"></dd>
                            </div>
                            <div class="grid grid-cols-[minmax(120px,1fr)_minmax(0,1.3fr)] items-start gap-4">
                                <dt class="text-muted">Aksi terakhir</dt>
                                <dd id="detail-aksi-terakhir" class="text-right font-semibold text-ink"></dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function showNonaktifDetail(nama, alasan, catatan, tanggal, aktor, tanggalAksi, aksiTerakhir) {
                document.getElementById('detail-nama').textContent = nama;
                document.getElementById('detail-alasan').textContent = alasan;
                document.getElementById('detail-catatan').textContent = catatan;
                document.getElementById('detail-tanggal').textContent = tanggal;
                document.getElementById('detail-aktor').textContent = aktor;
                document.getElementById('detail-aksi-tanggal').textContent = tanggalAksi;
                document.getElementById('detail-aksi-terakhir').textContent = aksiTerakhir;

                const modal = document.getElementById('nonaktif-detail-modal');
                const panel = document.getElementById('nonaktif-detail-panel');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                requestAnimationFrame(() => {
                    panel.classList.remove('scale-95');
                    panel.classList.add('scale-100');
                });
            }

            function closeNonaktifDetail() {
                const modal = document.getElementById('nonaktif-detail-modal');
                const panel = document.getElementById('nonaktif-detail-panel');
                panel.classList.add('scale-95');
                panel.classList.remove('scale-100');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            function filterNonaktifRows() {
                const query = document.getElementById('search-nonaktif').value.toLowerCase().trim();
                const alasan = document.getElementById('filter-alasan').value;
                const records = document.querySelectorAll('.nonaktif-record');
                const visibleRecordIds = new Set();

                records.forEach(record => {
                    if (record.dataset.restored === 'true') {
                        record.classList.add('hidden');
                        return;
                    }

                    const rowText = `${record.dataset.nama || ''} ${record.dataset.nip || ''}`.toLowerCase();
                    const matchesSearch = !query || rowText.includes(query);
                    const matchesAlasan = !alasan || record.dataset.alasan === alasan;
                    const isMatch = matchesSearch && matchesAlasan;

                    record.classList.toggle('hidden', !isMatch);
                    if (isMatch) {
                        visibleRecordIds.add(record.dataset.recordId);
                    }
                });

                document.getElementById('empty-state').classList.toggle('hidden', visibleRecordIds.size > 0);
            }

            function restorePegawai(nama, recordId = 'nonaktif-1') {
                const message = `Apakah Anda yakin ingin mengaktifkan kembali pegawai ${nama}?\n\nData pegawai akan dikembalikan ke daftar pegawai aktif dan dapat diproses kembali sesuai alur kepegawaian. Aksi restore ini akan dicatat di audit log.`;
                if (confirm(message)) {
                    document.querySelectorAll(`.nonaktif-record[data-record-id="${recordId}"]`).forEach(record => {
                        record.dataset.restored = 'true';
                        record.classList.add('hidden');
                    });

                    filterNonaktifRows();

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