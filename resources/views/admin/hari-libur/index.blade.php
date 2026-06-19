<x-layouts.app title="Hari Libur">

    @php
    $hariLiburData = [
        ['id' => 1, 'tanggal' => '2026-01-01', 'nama' => 'Tahun Baru 2026 Masehi', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 2, 'tanggal' => '2026-02-17', 'nama' => 'Isra Mikraj Nabi Muhammad SAW', 'tipe' => 'libur_nasional', 'hari' => 'Selasa'],
        ['id' => 3, 'tanggal' => '2026-03-19', 'nama' => 'Hari Suci Nyepi Saka 1948', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 4, 'tanggal' => '2026-03-20', 'nama' => 'Cuti Bersama Nyepi', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
        ['id' => 5, 'tanggal' => '2026-04-03', 'nama' => 'Wafat Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 6, 'tanggal' => '2026-04-05', 'nama' => 'Hari Raya Paskah', 'tipe' => 'libur_nasional', 'hari' => 'Minggu'],
        ['id' => 7, 'tanggal' => '2026-05-01', 'nama' => 'Hari Buruh Internasional', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 8, 'tanggal' => '2026-05-13', 'nama' => 'Hari Raya Waisak 2570 BE', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 9, 'tanggal' => '2026-05-14', 'nama' => 'Kenaikan Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 10, 'tanggal' => '2026-05-15', 'nama' => 'Cuti Bersama Kenaikan Yesus', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
        ['id' => 11, 'tanggal' => '2026-06-01', 'nama' => 'Hari Lahir Pancasila', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 12, 'tanggal' => '2026-06-17', 'nama' => 'Hari Raya Idul Adha 1447 H', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 13, 'tanggal' => '2026-08-17', 'nama' => 'HUT Kemerdekaan RI', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 14, 'tanggal' => '2026-12-25', 'nama' => 'Hari Raya Natal', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
    ];

    $tipeLabel = [
        'libur_nasional' => 'Libur Nasional',
        'cuti_bersama' => 'Cuti Bersama'
    ];

    $tipeColor = [
        'libur_nasional' => 'text-danger',
        'cuti_bersama' => 'text-warning'
    ];
    @endphp

    <div x-data="{ showAddForm: false }" class="space-y-6">
        
        {{-- Header & Filters --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface border border-border rounded-lg p-6 shadow-sm">
            <div>
                <h2 class="text-lg font-semibold text-ink font-sans">Daftar Hari Libur Nasional & Cuti Bersama</h2>
                <p class="text-xs text-muted mt-0.5 font-sans">Kelola seluruh agenda libur institusi untuk akurasi kalkulasi cuti.</p>
            </div>
            <div class="flex items-center gap-4">
                {{-- Flat Tabs Filter Tahun --}}
                <div class="flex items-center gap-4 border-r border-border pr-4">
                    <button class="text-sm font-semibold text-primary border-b-2 border-primary pb-1 -mb-[6px] font-sans">2026</button>
                    <button class="text-sm font-medium text-muted hover:text-ink pb-1 font-sans">2025</button>
                    <button class="text-sm font-medium text-muted hover:text-ink pb-1 font-sans">2024</button>
                </div>
                <button
                    @click="showAddForm = !showAddForm"
                    class="text-sm font-semibold text-primary hover:underline font-sans"
                >
                    Tambah Hari Libur
                </button>
            </div>
        </div>

        {{-- Form Tambah Hari Libur (Collapsible) --}}
        <div x-show="showAddForm" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4" style="display: none;">
            <h3 class="text-sm font-semibold text-ink font-sans">Tambah Hari Libur Baru</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Tanggal</label>
                    <input type="date" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Nama Hari Libur</label>
                    <input type="text" placeholder="Contoh: Hari Raya Idul Fitri" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-ink font-sans">Tipe</label>
                    <select class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                        <option value="libur_nasional">Libur Nasional</option>
                        <option value="cuti_bersama">Cuti Bersama</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button @click="showAddForm = false" class="text-xs font-semibold text-muted hover:text-ink font-sans">Batal</button>
                <button class="text-xs font-semibold text-primary hover:underline font-sans">Simpan</button>
            </div>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <table class="w-full">
                <thead class="bg-soft">
                    <tr>
                        <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Tanggal</th>
                        <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Hari</th>
                        <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Nama Hari Libur</th>
                        <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Tipe</th>
                        <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans">Tahun</th>
                        <th class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($hariLiburData as $h)
                    <tr class="transition-colors hover:bg-soft/50">
                        <td class="px-6 py-4 text-sm font-semibold text-ink font-mono">{{ \Carbon\Carbon::parse($h['tanggal'])->translatedFormat('d M Y') }}</td>
                        <td class="px-6 py-4 text-sm text-ink font-sans">{{ $h['hari'] }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-ink font-sans">{{ $h['nama'] }}</td>
                        <td class="px-6 py-4 text-xs font-semibold {{ $tipeColor[$h['tipe']] }} font-sans">{{ $tipeLabel[$h['tipe']] }}</td>
                        <td class="px-6 py-4 text-sm text-muted font-sans font-mono">2026</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-4">
                                <button class="text-xs font-semibold text-primary hover:underline font-sans">Edit</button>
                                <button class="text-xs font-semibold text-danger hover:underline font-sans">Hapus</button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>

</x-layouts.app>
