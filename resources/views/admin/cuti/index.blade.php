<x-layouts.app title="Cuti">

    @php
    $riwayatCuti = [
        ['id' => 1, 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-06-20', 'selesai' => '2026-06-24', 'hari' => 5, 'status' => 'menunggu', 'tgl_pengajuan' => '2026-06-18', 'alasan' => 'Acara keluarga di luar kota'],
        ['id' => 2, 'jenis' => 'Cuti Sakit', 'mulai' => '2026-04-10', 'selesai' => '2026-04-12', 'hari' => 3, 'status' => 'disetujui', 'tgl_pengajuan' => '2026-04-09', 'alasan' => 'Sakit demam berdarah'],
        ['id' => 3, 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-02-01', 'selesai' => '2026-02-05', 'hari' => 5, 'status' => 'disetujui', 'tgl_pengajuan' => '2026-01-28', 'alasan' => 'Urusan keluarga mendesak'],
        ['id' => 4, 'jenis' => 'Cuti Melahirkan', 'mulai' => '2025-10-01', 'selesai' => '2025-12-29', 'hari' => 90, 'status' => 'disetujui', 'tgl_pengajuan' => '2025-09-15', 'alasan' => 'Persalinan anak pertama'],
    ];

    $statusClass = [
        'menunggu' => 'text-warning',
        'disetujui' => 'text-success',
        'ditolak' => 'text-danger'
    ];

    $statusLabel = [
        'menunggu' => 'Menunggu Persetujuan',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak'
    ];
    @endphp

    <div x-data="{ activeTab: 'history' }" class="space-y-6">

        {{-- Navigation Flat Tabs --}}
        <div class="flex items-center gap-6 px-6 py-4 bg-surface border border-border rounded-lg shadow-sm">
            <button
                @click="activeTab = 'history'"
                :class="activeTab === 'history' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                Riwayat Cuti & Saldo
            </button>
            <button
                @click="activeTab = 'form'"
                :class="activeTab === 'form' ? 'text-primary font-semibold border-b-2 border-primary -mb-[18px]' : 'text-muted hover:text-ink font-medium'"
                class="pb-3 text-sm transition-colors relative font-sans cursor-pointer focus:outline-none"
            >
                Form Pengajuan Cuti Baru
            </button>
        </div>

        {{-- Tab 1: History --}}
        <div x-show="activeTab === 'history'" class="space-y-6" x-transition:enter="transition ease-out duration-150">
            
            {{-- Saldo Card Panel --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
                <h3 class="text-sm font-semibold text-ink font-sans border-b border-border pb-3 mb-4">Informasi Saldo Cuti Tahunan</h3>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="text-center sm:text-left border-r border-border pr-2">
                        <p class="text-xs text-muted font-sans">Jatah Dasar</p>
                        <p class="text-lg font-bold text-ink mt-1 font-mono">12 Hari</p>
                    </div>
                    <div class="text-center sm:text-left sm:border-r border-border pr-2">
                        <p class="text-xs text-muted font-sans">Carry-Over</p>
                        <p class="text-lg font-bold text-ink mt-1 font-mono">2 Hari</p>
                    </div>
                    <div class="text-center sm:text-left border-r border-border pr-2">
                        <p class="text-xs text-muted font-sans">Terpakai</p>
                        <p class="text-lg font-bold text-danger mt-1 font-mono">10 Hari</p>
                    </div>
                    <div class="text-center sm:text-left">
                        <p class="text-xs text-muted font-sans">Sisa Saldo</p>
                        <p class="text-lg font-bold text-success mt-1 font-mono">4 Hari</p>
                    </div>
                </div>
            </div>

            {{-- Table --}}
            <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="px-6 py-5 border-b border-border flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-ink font-sans">Daftar Riwayat Cuti</h2>
                        <p class="text-xs text-muted mt-0.5 font-sans">Tabel rincian cuti yang pernah diajukan.</p>
                    </div>
                    <button @click="activeTab = 'form'" class="text-xs font-semibold text-primary hover:underline font-sans cursor-pointer focus:outline-none">
                        Ajukan Cuti Baru
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Jenis Cuti</th>
                                <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Mulai - Selesai</th>
                                <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Durasi</th>
                                <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Status</th>
                                <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($riwayatCuti as $r)
                            <tr class="transition-colors hover:bg-soft/50">
                                <td class="px-6 py-4">
                                    <p class="text-sm font-semibold text-ink font-sans">{{ $r['jenis'] }}</p>
                                    <p class="text-xs text-muted font-sans mt-0.5">{{ $r['alasan'] }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-sm text-ink font-mono">{{ \Carbon\Carbon::parse($r['mulai'])->translatedFormat('d M') }} - {{ \Carbon\Carbon::parse($r['selesai'])->translatedFormat('d M Y') }}</p>
                                    <p class="text-xs text-muted font-sans mt-0.5">Diajukan: {{ \Carbon\Carbon::parse($r['tgl_pengajuan'])->translatedFormat('d M Y') }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-ink font-mono font-semibold">{{ $r['hari'] }} Hari Kerja</td>
                                <td class="px-6 py-4 text-xs font-bold {{ $statusClass[$r['status']] }} font-sans">{{ $statusLabel[$r['status']] }}</td>
                                <td class="px-6 py-4">
                                    <a href="#" class="text-xs font-semibold text-primary hover:underline font-sans">Detail</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        {{-- Tab 2: Full-Width Form --}}
        <div x-show="activeTab === 'form'" class="rounded-lg border border-border bg-surface p-8 shadow-sm space-y-6" x-transition:enter="transition ease-out duration-150" style="display: none;">
            <div>
                <h2 class="text-lg font-semibold text-ink font-sans">Formulir Pengajuan Cuti Baru</h2>
                <p class="text-xs text-muted mt-0.5 font-sans">Pastikan atasan langsung Anda sudah sesuai sebelum mengisi form ini.</p>
            </div>

            <form class="space-y-6" @submit.prevent>
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-semibold text-ink font-sans">Jenis Cuti</label>
                        <select class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="tahunan">Cuti Tahunan</option>
                            <option value="sakit">Cuti Sakit</option>
                            <option value="melahirkan">Cuti Melahirkan</option>
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
                        <input type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-semibold text-ink font-sans">Tanggal Selesai Cuti</label>
                        <input type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-semibold text-ink font-sans">Alasan Pengajuan</label>
                    <textarea rows="4" placeholder="Tuliskan keterangan lengkap alasan pengajuan cuti Anda..." class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-y font-sans"></textarea>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-semibold text-ink font-sans">Unggah Berkas Lampiran</label>
                    <input type="file" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    <p class="text-xs text-muted font-sans mt-1">Format PDF/JPG, maksimal ukuran 2MB (wajib untuk cuti sakit > 2 hari/cuti melahirkan).</p>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-border">
                    <button type="button" @click="activeTab = 'history'" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft cursor-pointer focus:outline-none">
                        Batal
                    </button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 cursor-pointer focus:outline-none">
                        Kirim Pengajuan Cuti
                    </button>
                </div>
            </form>
        </div>

    </div>

</x-layouts.app>
