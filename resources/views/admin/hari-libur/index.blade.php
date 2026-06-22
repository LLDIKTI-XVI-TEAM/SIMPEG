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
        'libur_nasional' => 'bg-danger/10 text-danger',
        'cuti_bersama' => 'bg-warning/10 text-warning'
    ];
    @endphp

    <div x-data="{ showAddForm: false, activeYear: 2026 }" class="space-y-6">
        
        {{-- Header & Filters --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-surface border border-border rounded-lg p-6 shadow-sm">
            <div>
                <h2 class="text-lg font-semibold text-ink font-sans">Daftar Hari Libur Nasional & Cuti Bersama</h2>
                <p class="text-xs text-muted mt-0.5 font-sans">Kelola seluruh agenda libur institusi untuk akurasi kalkulasi cuti.</p>
            </div>
            <div class="flex items-center gap-6">
                {{-- Flat Tabs Filter Tahun --}}
                <div class="flex items-center gap-4">
                    <button @click="activeYear = 2026" :class="activeYear === 2026 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink'" class="text-sm pb-1 font-sans cursor-pointer focus:outline-none">2026</button>
                    <button @click="activeYear = 2025" :class="activeYear === 2025 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink'" class="text-sm pb-1 font-sans cursor-pointer focus:outline-none">2025</button>
                    <button @click="activeYear = 2024" :class="activeYear === 2024 ? 'text-primary font-semibold border-b-2 border-primary' : 'text-muted hover:text-ink'" class="text-sm pb-1 font-sans cursor-pointer focus:outline-none">2024</button>
                </div>
                <button
                    @click="showAddForm = !showAddForm"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Tambah Hari Libur
                </button>
            </div>
        </div>

        {{-- Form Tambah Hari Libur (Collapsible) --}}
        <div x-show="showAddForm" class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4" style="display: none;">
            <form action="{{ route('hari-libur.store') }}" method="POST" class="space-y-4">
                @csrf
                <h3 class="text-sm font-semibold text-ink font-sans">Tambah Hari Libur Baru</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Tanggal</label>
                        <input type="date" name="tanggal" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Nama Hari Libur</label>
                        <input type="text" name="nama" required placeholder="Contoh: Hari Raya Idul Fitri" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-ink font-sans">Jenis Libur</label>
                        <select name="tipe" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="libur_nasional">Libur Nasional</option>
                            <option value="cuti_bersama">Cuti Bersama</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showAddForm = false" class="text-xs font-semibold text-muted hover:text-ink font-sans">Batal</button>
                    <button type="submit" class="text-xs font-semibold text-primary hover:underline font-sans">Simpan</button>
                </div>
            </form>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Tanggal</th>
                            <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Hari</th>
                            <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nama Hari Libur</th>
                            <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Jenis Libur</th>
                            <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Tahun</th>
                            <th class="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach($hariLiburData as $h)
                        <tr x-show="activeYear === 2026" class="transition-colors hover:bg-soft/50">
                            <td class="px-6 py-4 text-sm font-medium text-ink font-sans">
                                {{ \Carbon\Carbon::parse($h['tanggal'])->translatedFormat('d M Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm text-ink font-sans">{{ $h['hari'] }}</td>
                            <td class="px-6 py-4 text-sm font-medium text-ink font-sans">{{ $h['nama'] }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-block rounded-md px-2.5 py-0.5 text-xs font-semibold {{ $tipeColor[$h['tipe']] }} font-sans">
                                    {{ $tipeLabel[$h['tipe']] }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-muted font-sans font-mono">2026</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    {{-- Edit --}}
                                    <a href="{{ route('hari-libur.edit', $h['id']) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                        </svg>
                                        Edit
                                    </a>
                                    <span class="text-border">|</span>
                                    {{-- Hapus --}}
                                    <form action="{{ route('hari-libur.destroy', $h['id']) }}" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus hari libur ini?')">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline font-sans">
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                        <tr x-show="activeYear !== 2026">
                            <td colspan="6" class="px-6 py-8 text-center text-xs text-muted font-sans">
                                Tidak ada data hari libur untuk tahun yang dipilih.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted">Menampilkan 1 - 10 dari 24 data</p>
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
                    @foreach([1, 2, 3, '…', 3] as $pg)
                        @if($pg === 1)
                        <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-primary bg-primary text-sm font-semibold text-white transition hover:opacity-90">{{ $pg }}</button>
                        @elseif($pg === '…')
                        <span class="flex h-8 w-8 items-center justify-center text-sm text-muted font-sans font-medium">{{ $pg }}</span>
                        @else
                        <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-sm text-muted transition hover:bg-soft hover:text-ink font-medium">{{ $pg }}</button>
                        @endif
                    @endforeach
                    {{-- Next --}}
                    <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-muted transition hover:bg-soft hover:text-ink">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

    </div>

</x-layouts.app>
