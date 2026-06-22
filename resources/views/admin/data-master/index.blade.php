<x-layouts.app title="Data Master">

@php
$tabs = [
    'golongan' => 'Golongan',
    'jenis_jabatan' => 'Jenis Jabatan',
    'eselon' => 'Eselon',
    'jenis_cuti' => 'Jenis Cuti',
    'agama' => 'Agama',
    'jenis_kelamin' => 'Jenis Kelamin',
    'status_perkawinan' => 'Status Perkawinan',
    'jenjang_pendidikan' => 'Jenjang Pendidikan',
    'unit_kerja' => 'Unit Kerja',
    'bup' => 'Batas Usia Pensiun',
];

$dataGolongan = [
    ['kode' => 'I/a', 'nama' => 'Juru Muda'],
    ['kode' => 'I/b', 'nama' => 'Juru Muda Tingkat 1'],
    ['kode' => 'I/c', 'nama' => 'Juru'],
    ['kode' => 'I/d', 'nama' => 'Juru Tingkat 1'],
    ['kode' => 'II/a', 'nama' => 'Pengatur Muda'],
    ['kode' => 'II/b', 'nama' => 'Pengatur Muda Tingkat 1'],
    ['kode' => 'II/c', 'nama' => 'Pengatur'],
    ['kode' => 'II/d', 'nama' => 'Pengatur Tingkat 1'],
    ['kode' => 'III/a', 'nama' => 'Penata Muda'],
    ['kode' => 'III/b', 'nama' => 'Penata Muda Tingkat 1'],
    ['kode' => 'III/c', 'nama' => 'Penata'],
    ['kode' => 'III/d', 'nama' => 'Penata Tingkat 1'],
    ['kode' => 'IV/a', 'nama' => 'Pembina'],
    ['kode' => 'IV/b', 'nama' => 'Pembina Tingkat 1'],
    ['kode' => 'IV/c', 'nama' => 'Pembina Utama Muda'],
    ['kode' => 'IV/d', 'nama' => 'Pembina Utama Madya'],
    ['kode' => 'IV/e', 'nama' => 'Pembina Utama'],
];

$dataJenisJabatan = [
    ['id' => 1, 'nama' => 'Struktural', 'maks_usia' => 60, 'catatan' => 'Dapat disesuaikan berdasarkan jabatan detail'],
    ['id' => 2, 'nama' => 'Fungsional Tertentu', 'maks_usia' => '58 / 60', 'catatan' => 'Mengikuti jenjang atau jabatan detail'],
    ['id' => 3, 'nama' => 'Fungsional Umum / Pelaksana', 'maks_usia' => 58, 'catatan' => 'Default umum'],
];

$dataEselon = [
    ['kode' => 'I.a', 'nama' => 'Eselon I.a'],
    ['kode' => 'I.b', 'nama' => 'Eselon I.b'],
    ['kode' => 'II.a', 'nama' => 'Eselon II.a'],
    ['kode' => 'II.b', 'nama' => 'Eselon II.b'],
    ['kode' => 'III.a', 'nama' => 'Eselon III.a'],
    ['kode' => 'III.b', 'nama' => 'Eselon III.b'],
    ['kode' => 'IV.a', 'nama' => 'Eselon IV.a'],
    ['kode' => 'IV.b', 'nama' => 'Eselon IV.b'],
];

$dataJenisCuti = [
    ['id' => 1, 'nama' => 'Cuti Tahunan', 'khusus_pns' => 'Tidak'],
    ['id' => 2, 'nama' => 'Cuti Sakit', 'khusus_pns' => 'Tidak'],
    ['id' => 3, 'nama' => 'Cuti Melahirkan', 'khusus_pns' => 'Tidak'],
    ['id' => 4, 'nama' => 'Cuti Karena Alasan Penting', 'khusus_pns' => 'Tidak'],
    ['id' => 5, 'nama' => 'Cuti Besar', 'khusus_pns' => 'Ya'],
    ['id' => 6, 'nama' => 'Cuti Luar Tanggungan Negara (CLTN)', 'khusus_pns' => 'Ya'],
];

$dataAgama = [
    ['id' => 1, 'nama' => 'Islam'],
    ['id' => 2, 'nama' => 'Kristen Protestan'],
    ['id' => 3, 'nama' => 'Katolik'],
    ['id' => 4, 'nama' => 'Hindu'],
    ['id' => 5, 'nama' => 'Buddha'],
    ['id' => 6, 'nama' => 'Konghucu'],
];

$dataJenisKelamin = [
    ['id' => 1, 'kode' => 'L', 'nama' => 'Laki-laki'],
    ['id' => 2, 'kode' => 'P', 'nama' => 'Perempuan'],
];

$dataStatusPerkawinan = [
    ['id' => 1, 'nama' => 'Belum Menikah'],
    ['id' => 2, 'nama' => 'Menikah'],
    ['id' => 3, 'nama' => 'Duda / Janda'],
];

$dataPendidikan = [
    ['id' => 1, 'nama' => 'SD'],
    ['id' => 2, 'nama' => 'SMP'],
    ['id' => 3, 'nama' => 'SMA / SMK / Sederajat'],
    ['id' => 4, 'nama' => 'D1'],
    ['id' => 5, 'nama' => 'D2'],
    ['id' => 6, 'nama' => 'D3'],
    ['id' => 7, 'nama' => 'D4 / S1'],
    ['id' => 8, 'nama' => 'S2 / Profesi'],
    ['id' => 9, 'nama' => 'S3'],
];

$dataUnitKerja = [
    ['id' => 1, 'nama' => 'Bagian Umum', 'keterangan' => 'Pusat administrasi'],
    ['id' => 2, 'nama' => 'Kelompok Kerja Akademik dan Kemahasiswaan', 'keterangan' => 'Layanan akademik'],
    ['id' => 3, 'nama' => 'Kelompok Kerja Sumber Daya Perguruan Tinggi', 'keterangan' => 'Layanan SDM PT'],
    ['id' => 4, 'nama' => 'Kelompok Kerja Kelembagaan dan Sistem Informasi', 'keterangan' => 'Layanan kelembagaan'],
];

$dataBUP = [
    ['jenis' => 'Pelaksana / Fungsional Umum', 'bup' => 58],
    ['jenis' => 'Fungsional Ahli Pertama', 'bup' => 58],
    ['jenis' => 'Fungsional Ahli Muda', 'bup' => 58],
    ['jenis' => 'Fungsional Ahli Madya', 'bup' => 60],
    ['jenis' => 'Fungsional Ahli Utama', 'bup' => 65],
    ['jenis' => 'Struktural Eselon I & II', 'bup' => 60],
    ['jenis' => 'Struktural Eselon III & IV', 'bup' => 58],
];
@endphp

    {{-- Page Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Data Master / Reference Tables</h2>
            <p class="mt-1 text-sm text-muted">Kelola data referensi fundamental untuk sistem SIMPEG.</p>
        </div>
    </div>

    <div x-data="{ activeTab: 'golongan' }" class="flex flex-col lg:flex-row gap-6">
        
        {{-- Tab Sidebar Kiri --}}
        <aside class="w-full lg:w-64 shrink-0">
            <div class="rounded-lg border border-primary/10 bg-surface p-4 shadow-sm space-y-1">
                <p class="text-[10px] font-bold text-muted uppercase tracking-wide px-3 pb-2 border-b border-primary/10 mb-2 font-sans">Kategori Referensi</p>
                
                @foreach($tabs as $key => $label)
                <button
                    @click="activeTab = '{{ $key }}'"
                    :class="activeTab === '{{ $key }}' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'"
                    class="w-full text-left flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors"
                >
                    {{ $label }}
                    <svg x-show="activeTab === '{{ $key }}'" class="w-4 h-4 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                @endforeach
            </div>
        </aside>

        {{-- Konten Utama Kanan --}}
        <main class="flex-1 min-w-0">
            
            {{-- TAB: GOLONGAN --}}
            <div x-show="activeTab === 'golongan'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Golongan Pangkat</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi golongan kepangkatan PNS.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Kode</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Pangkat</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataGolongan as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['kode'] }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENIS JABATAN --}}
            <div x-show="activeTab === 'jenis_jabatan'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Jabatan</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis jabatan dan maksimal usia pensiun.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Jabatan</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">Maks Usia</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Catatan</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataJenisJabatan as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-sm text-center font-medium text-warning">{{ $item['maks_usia'] }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['catatan'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: ESELON --}}
            <div x-show="activeTab === 'eselon'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Eselon</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi kode eselon struktural.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Kode</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Eselon</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataEselon as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['kode'] }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENIS CUTI --}}
            <div x-show="activeTab === 'jenis_cuti'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Cuti</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis-jenis cuti dan aturannya.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Jenis Cuti</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">Khusus PNS</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataJenisCuti as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($item['khusus_pns'] === 'Ya')
                                        <span class="rounded-full bg-primary/10 text-primary px-3 py-1 text-[11px] font-semibold">Ya</span>
                                    @else
                                        <span class="rounded-full bg-soft text-muted px-3 py-1 text-[11px] font-semibold">Tidak</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: AGAMA --}}
            <div x-show="activeTab === 'agama'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Agama</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi agama resmi.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Agama</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataAgama as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENIS KELAMIN --}}
            <div x-show="activeTab === 'jenis_kelamin'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Kelamin</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis kelamin.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Kode</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataJenisKelamin as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['kode'] }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: STATUS PERKAWINAN --}}
            <div x-show="activeTab === 'status_perkawinan'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Status Perkawinan</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi status perkawinan.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataStatusPerkawinan as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENJANG PENDIDIKAN --}}
            <div x-show="activeTab === 'jenjang_pendidikan'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenjang Pendidikan</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenjang pendidikan formal.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Jenjang Pendidikan</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataPendidikan as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: UNIT KERJA --}}
            <div x-show="activeTab === 'unit_kerja'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Unit Kerja</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi struktur organisasi / unit kerja.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Unit Kerja</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Keterangan</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataUnitKerja as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $item['keterangan'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: BATAS USIA PENSIUN --}}
            <div x-show="activeTab === 'bup'" class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-primary/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Batas Usia Pensiun (BUP)</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi aturan usia pensiun berdasarkan jabatan.</p>
                    </div>
                    <button class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        + Tambah
                    </button>
                </div>
                <div class="rounded-lg border border-primary/10 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Jenis Jabatan BUP</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">BUP (Tahun)</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-primary/10">
                            @foreach($dataBUP as $item)
                            <tr class="hover:bg-soft/50 transition-colors">
                                <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['jenis'] }}</td>
                                <td class="px-4 py-3 text-sm text-center font-bold text-danger">{{ $item['bup'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-sm font-semibold text-primary hover:underline">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

</x-layouts.app>
