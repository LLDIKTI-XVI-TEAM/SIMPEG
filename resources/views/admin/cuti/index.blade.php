<x-layouts.app title="Cuti">

    @php
    $riwayatCuti = [
        ['id' => 1, 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-06-20', 'selesai' => '2026-06-24', 'hari' => 5, 'status' => 'menunggu', 'tgl_pengajuan' => '2026-06-18', 'alasan' => 'Acara keluarga di luar kota'],
        ['id' => 2, 'jenis' => 'Cuti Sakit', 'mulai' => '2026-04-10', 'selesai' => '2026-04-12', 'hari' => 3, 'status' => 'disetujui', 'tgl_pengajuan' => '2026-04-09', 'alasan' => 'Sakit demam berdarah'],
        ['id' => 3, 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-02-01', 'selesai' => '2026-02-05', 'hari' => 5, 'status' => 'disetujui', 'tgl_pengajuan' => '2026-01-28', 'alasan' => 'Urusan keluarga mendesak'],
        ['id' => 4, 'jenis' => 'Cuti Melahirkan', 'mulai' => '2025-10-01', 'selesai' => '2025-12-29', 'hari' => 90, 'status' => 'disetujui', 'tgl_pengajuan' => '2025-09-15', 'alasan' => 'Persalinan anak pertama'],
    ];

    $statusFilter = request()->query('status');
    if ($statusFilter === 'pending') {
        $riwayatCuti = array_filter($riwayatCuti, function($item) {
            return $item['status'] === 'menunggu';
        });
    }

    $statusClass = [
        'menunggu' => 'bg-warning/10 text-warning',
        'disetujui' => 'bg-success/10 text-success',
        'ditolak' => 'bg-danger/10 text-danger'
    ];

    $statusDot = [
        'menunggu' => 'bg-warning',
        'disetujui' => 'bg-success',
        'ditolak' => 'bg-danger'
    ];

    $statusLabel = [
        'menunggu' => 'Menunggu Persetujuan',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak'
    ];
    @endphp

    <div x-data="{ activeTab: 'history' }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Cuti Pegawai</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Cuti</span>
                </nav>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                {{-- Button to switch to Form Pengajuan --}}
                <button
                    @click="activeTab = 'form'"
                    x-show="activeTab === 'history'"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Ajukan Cuti
                </button>
                {{-- Button to switch to Riwayat --}}
                <button
                    @click="activeTab = 'history'"
                    x-show="activeTab === 'form'"
                    style="display: none;"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                    </svg>
                    Kembali ke Riwayat
                </button>
            </div>
        </div>

        {{-- FILTER / NAVIGATION TABS --}}
        <div class="rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between shadow-sm">
            <div class="flex items-center gap-6 overflow-x-auto shrink-0">
                <button
                    @click="activeTab = 'history'"
                    :class="activeTab === 'history' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    Riwayat Cuti & Saldo
                </button>
                <button
                    @click="activeTab = 'form'"
                    :class="activeTab === 'form' ? 'text-primary font-semibold border-b-2 border-primary pb-1' : 'text-muted hover:text-ink font-medium pb-1'"
                    class="text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
                >
                    Form Pengajuan Cuti Baru
                </button>
            </div>

            {{-- Inputs (Only shown when activeTab is history) --}}
            <div x-show="activeTab === 'history'" class="flex flex-1 flex-wrap items-center justify-end gap-3 lg:flex-initial" x-transition>
                {{-- Search input --}}
                <div class="flex min-w-64 flex-1 items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 lg:flex-initial">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input id="search-cuti" type="text" placeholder="Cari alasan cuti..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>

                {{-- Filter Jenis Cuti --}}
                <div class="relative min-w-36 flex-1 lg:flex-initial">
                    <select id="filter-jenis-cuti" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Jenis Cuti</option>
                        <option>Cuti Tahunan</option>
                        <option>Cuti Sakit</option>
                        <option>Cuti Melahirkan</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab 1: History --}}
        <div x-show="activeTab === 'history'" class="space-y-6" x-transition:enter="transition ease-out duration-150">
            
            {{-- Informasi Saldo Cuti --}}
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Jatah Dasar --}}
                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Jatah Dasar</p>
                            <p class="mt-1.5 text-2xl font-extrabold text-ink leading-none font-mono">12 <span class="text-sm font-sans font-medium text-muted">Hari</span></p>
                        </div>
                        <div class="rounded-lg bg-primary/10 p-2.5 shrink-0">
                            <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                        </div>
                    </div>
                </div>

                {{-- Carry-Over --}}
                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Carry-Over</p>
                            <p class="mt-1.5 text-2xl font-extrabold text-ink leading-none font-mono">2 <span class="text-sm font-sans font-medium text-muted">Hari</span></p>
                        </div>
                        <div class="rounded-lg bg-info/10 p-2.5 shrink-0">
                            <svg class="w-6 h-6 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        </div>
                    </div>
                </div>

                {{-- Terpakai --}}
                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Terpakai</p>
                            <p class="mt-1.5 text-2xl font-extrabold text-danger leading-none font-mono">10 <span class="text-sm font-sans font-medium text-muted">Hari</span></p>
                        </div>
                        <div class="rounded-lg bg-danger/10 p-2.5 shrink-0">
                            <svg class="w-6 h-6 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        </div>
                    </div>
                </div>

                {{-- Sisa Saldo --}}
                <div class="rounded-lg border border-border bg-surface p-5 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sisa Saldo</p>
                            <p class="mt-1.5 text-2xl font-extrabold text-success leading-none font-mono">4 <span class="text-sm font-sans font-medium text-muted">Hari</span></p>
                        </div>
                        <div class="rounded-lg bg-success/10 p-2.5 shrink-0">
                            <svg class="w-6 h-6 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Table Card --}}
            <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                    <div>
                        <h3 class="text-sm font-semibold text-ink font-sans">Daftar Riwayat Cuti</h3>
                        <p class="text-[10px] text-muted font-sans">Tabel rincian cuti yang pernah diajukan</p>
                    </div>
                    <button @click="activeTab = 'form'" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer font-sans">
                        Ajukan Cuti Baru
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Jenis Cuti</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Mulai - Selesai</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Durasi</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($riwayatCuti as $r)
                            <tr class="transition-colors hover:bg-soft/50" data-jenis="{{ $r['jenis'] }}" data-alasan="{{ $r['alasan'] }}">
                                <td class="px-4 py-3.5">
                                    <p class="text-sm font-semibold text-ink font-sans">{{ $r['jenis'] }}</p>
                                    <p class="text-xs text-muted font-sans mt-0.5">{{ $r['alasan'] }}</p>
                                </td>
                                <td class="px-4 py-3.5">
                                    <p class="text-sm text-ink font-mono">{{ \Carbon\Carbon::parse($r['mulai'])->translatedFormat('d M') }} - {{ \Carbon\Carbon::parse($r['selesai'])->translatedFormat('d M Y') }}</p>
                                    <p class="text-xs text-muted font-sans mt-0.5">Diajukan: {{ \Carbon\Carbon::parse($r['tgl_pengajuan'])->translatedFormat('d M Y') }}</p>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-ink font-mono font-semibold">{{ $r['hari'] }} Hari Kerja</td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusClass[$r['status']] }} font-sans">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $statusDot[$r['status']] }}"></span>
                                        {{ $statusLabel[$r['status']] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-1.5">
                                        <a href="{{ route('cuti.show', $r['id']) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- TABLE FOOTER --}}
                <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                    <div class="flex items-center gap-3">
                        <p id="cuti-count-text" class="text-sm text-muted font-sans">Menampilkan 1 - 4 dari 4 data</p>
                        <div class="relative">
                            <select id="per-page" class="appearance-none rounded-lg border border-border bg-surface pl-3 pr-8 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                                <option>10 / halaman</option>
                                <option>25 / halaman</option>
                                <option>50 / halaman</option>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-muted">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        {{-- Prev --}}
                        <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                            </svg>
                        </button>
                        <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-primary bg-primary text-sm font-semibold text-white transition hover:opacity-90 font-sans">1</button>
                        {{-- Next --}}
                        <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink font-sans">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

        </div>

        {{-- Tab 2: Full-Width Form --}}
        <div x-show="activeTab === 'form'" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6" x-transition:enter="transition ease-out duration-150" style="display: none;">
            <div>
                <h2 class="text-lg font-semibold text-ink font-sans">Formulir Pengajuan Cuti Baru</h2>
                <p class="text-xs text-muted mt-0.5 font-sans">Pastikan atasan langsung Anda sudah sesuai sebelum mengisi form ini.</p>
            </div>

            <form action="{{ route('cuti.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-semibold text-ink font-sans">Jenis Cuti</label>
                        <select name="jenis_cuti" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="Cuti Tahunan">Cuti Tahunan</option>
                            <option value="Cuti Sakit">Cuti Sakit</option>
                            <option value="Cuti Melahirkan">Cuti Melahirkan</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-semibold text-ink font-sans">Atasan Langsung (Verifikator)</label>
                        <input type="text" disabled value="Abd Rahim Har (Kabag. Umum)" class="w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-sm text-muted shadow-sm focus:outline-none font-sans">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-semibold text-ink font-sans">Tanggal Mulai Cuti</label>
                        <input type="date" name="tanggal_mulai" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-semibold text-ink font-sans">Tanggal Selesai Cuti</label>
                        <input type="date" name="tanggal_selesai" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-semibold text-ink font-sans">Alasan Pengajuan</label>
                    <textarea name="alasan" required rows="4" placeholder="Tuliskan keterangan lengkap alasan pengajuan cuti Anda..." class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-y font-sans"></textarea>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-semibold text-ink font-sans">Unggah Berkas Lampiran</label>
                    <input type="file" name="berkas" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    <p class="text-xs text-muted font-sans mt-1">Format PDF/JPG, maksimal ukuran 2MB (wajib untuk cuti sakit > 2 hari/cuti melahirkan).</p>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-border">
                    <button type="button" @click="activeTab = 'history'" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none font-sans">
                        Batal
                    </button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 cursor-pointer focus:outline-none font-sans">
                        Kirim Pengajuan Cuti
                    </button>
                </div>
            </form>
        </div>
        </div>

    </div>

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('search-cuti');
        const filterJenis = document.getElementById('filter-jenis-cuti');

        function applyCutiFilters() {
            const query = searchInput.value.toLowerCase();
            const jenis = filterJenis.value;

            const rows = document.querySelectorAll('tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const rJenis = row.getAttribute('data-jenis');
                if (!rJenis) return; // skip templates/empty
                const rAlasan = row.getAttribute('data-alasan').toLowerCase();

                const matchesSearch = rAlasan.includes(query) || rJenis.toLowerCase().includes(query);
                const matchesJenis = !jenis || rJenis === jenis;

                if (matchesSearch && matchesJenis) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update showing count text
            const countText = document.getElementById('cuti-count-text');
            if (countText) {
                countText.textContent = `Menampilkan ${visibleCount} dari ${rows.length} data`;
            }
        }

        if (searchInput) searchInput.addEventListener('input', applyCutiFilters);
        if (filterJenis) filterJenis.addEventListener('change', applyCutiFilters);
    });
    </script>
    @endpush

</x-layouts.app>
