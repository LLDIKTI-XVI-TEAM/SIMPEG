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
            'hari_libur' => 'Hari Libur / Cuti Bersama',
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
            ['id' => 2, 'nama' => 'Fungsional Tertentu', 'maks_usia' => '58 / 60 / 65', 'catatan' => 'Mengikuti jenjang atau jabatan detail'],
            ['id' => 3, 'nama' => 'Fungsional Umum / Pelaksana', 'maks_usia' => 58, 'catatan' => 'Default umum'],
            ['id' => 4, 'nama' => 'Pimpinan Tinggi', 'maks_usia' => 60, 'catatan' => 'Jabatan pimpinan tinggi madya dan pratama'],
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
            ['id' => 1, 'nama' => 'Belum Kawin'],
            ['id' => 2, 'nama' => 'Kawin'],
            ['id' => 3, 'nama' => 'Cerai Hidup'],
            ['id' => 4, 'nama' => 'Cerai Mati'],
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

        $dataHariLibur = [
            ['tanggal' => '2026-01-01', 'nama' => 'Tahun Baru Masehi', 'jenis' => 'Hari Libur Nasional'],
            ['tanggal' => '2026-05-24', 'nama' => 'Hari Raya Idul Fitri 1447 H', 'jenis' => 'Hari Libur Nasional'],
            ['tanggal' => '2026-05-25', 'nama' => 'Cuti Bersama Idul Fitri', 'jenis' => 'Cuti Bersama'],
            ['tanggal' => '2026-08-17', 'nama' => 'Hari Kemerdekaan RI', 'jenis' => 'Hari Libur Nasional'],
            ['tanggal' => '2026-12-25', 'nama' => 'Hari Raya Natal', 'jenis' => 'Hari Libur Nasional'],
        ];
    @endphp

    {{-- Page Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Data Master / Reference Tables</h2>
            </div>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Data Master']
            ]" />
            <div class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                <span>•</span>
                <span class="text-muted italic">Akses: Khusus Super Admin</span>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('audit-log') }}" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-muted shadow-sm transition hover:bg-soft hover:text-ink font-sans">
                <svg class="w-4 h-4 mr-1.5 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                </svg>
                Lihat Audit Log Master
            </a>
        </div>
    </div>

    <div x-data="{ 
        activeTab: 'golongan', 
        isModalOpen: false, 
        modalMode: 'tambah',
        isDeleteModalOpen: false,
        deleteItemName: '',
        tabs: {{ json_encode($tabs) }},
        openModal(mode) { this.modalMode = mode; this.isModalOpen = true; }, 
        closeModal() { this.isModalOpen = false; },
        openDeleteConfirm(item) { this.deleteItemName = item.name || item.nama || item.kode || item.jenis; $dispatch('open-confirm-delete-master'); },
        closeDeleteModal() { this.isDeleteModalOpen = false; }
    }" class="flex flex-col lg:flex-row gap-6 relative">

        {{-- Tab Sidebar Kiri --}}
        <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-6 lg:self-start">
            <div class="rounded-lg bg-surface p-4 shadow-sm space-y-1">
                <p
                    class="text-[10px] font-bold text-muted uppercase tracking-wide px-3 pb-2 mb-2 font-sans">
                    Kategori Referensi</p>

                @foreach($tabs as $key => $label)
                    <button @click="activeTab = '{{ $key }}'"
                        :class="activeTab === '{{ $key }}' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'"
                        class="w-full text-left flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors">
                        {{ $label }}
                        <svg x-show="activeTab === '{{ $key }}'" class="w-4 h-4 ml-auto" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                @endforeach
            </div>
        </aside>

        {{-- Konten Utama Kanan --}}
        <main class="flex-1 min-w-0 space-y-4">

            {{-- UNIVERSAL WARNING DATA SEED & AUDIT LOG CARD --}}
            <div class="rounded-lg border border-info/20 bg-info/5 p-4 shadow-sm flex flex-col gap-2.5">
                <div class="flex gap-2.5 text-xs text-info items-start">
                    <svg class="w-5 h-5 shrink-0 text-info mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                    </svg>
                    <div>
                        <span class="font-bold">Informasi Integritas Data Master & Audit Log:</span> 
                        <ul class="list-disc pl-4 mt-1 space-y-1 font-sans">
                            <li><strong>Ketentuan Data Seed:</strong> Seluruh data master bawaan (seperti golongan PNS, jenis cuti utama, dan status dasar) dikategorikan sebagai data seed fundamental. Modifikasi atau penghapusan disarankan hanya untuk keperluan penyesuaian khusus.</li>
                            <li><strong>Pencatatan Audit Trail:</strong> Setiap aktivitas penambahan, pembaruan, dan penghapusan data master akan dicatat secara otomatis dalam sistem Audit Log SIMPEG demi menjaga akuntabilitas.</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- TAB: GOLONGAN --}}
            <div x-show="activeTab === 'golongan'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Golongan Pangkat</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi golongan
                            kepangkatan PNS.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Kode</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama Pangkat</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataGolongan as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['kode'] }}</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['kode'] }} - {{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENIS JABATAN --}}
            <div x-show="activeTab === 'jenis_jabatan'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Jabatan</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis jabatan
                            dan maksimal usia pensiun.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama Jabatan</th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">
                                    Maks Usia</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Catatan</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataJenisJabatan as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-sm text-center font-medium text-warning">
                                        {{ $item['maks_usia'] }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['catatan'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: ESELON --}}
            <div x-show="activeTab === 'eselon'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Eselon</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi kode eselon
                            struktural.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Kode</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama Eselon</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataEselon as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['kode'] }}</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENIS CUTI --}}
            <div x-show="activeTab === 'jenis_cuti'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Cuti</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis-jenis
                            cuti dan aturannya.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama Jenis Cuti</th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">
                                    Khusus PNS</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataJenisCuti as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-center">
                                        @if($item['khusus_pns'] === 'Ya')
                                            <span
                                                class="text-[11px] font-semibold text-primary">Ya</span>
                                        @else
                                            <span
                                                class="text-[11px] font-semibold text-muted">Tidak</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: AGAMA --}}
            <div x-show="activeTab === 'agama'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Agama</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi agama resmi.
                        </p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama Agama</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataAgama as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENIS KELAMIN --}}
            <div x-show="activeTab === 'jenis_kelamin'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Kelamin</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis kelamin.
                        </p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Kode</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataJenisKelamin as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['kode'] }}</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: STATUS PERKAWINAN --}}
            <div x-show="activeTab === 'status_perkawinan'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Status Perkawinan</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi status
                            perkawinan.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Status</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataStatusPerkawinan as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: JENJANG PENDIDIKAN --}}
            <div x-show="activeTab === 'jenjang_pendidikan'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenjang Pendidikan</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenjang
                            pendidikan formal.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Jenjang Pendidikan</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataPendidikan as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: UNIT KERJA --}}
            <div x-show="activeTab === 'unit_kerja'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Unit Kerja</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi struktur
                            organisasi / unit kerja.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    ID</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Nama Unit Kerja</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Keterangan</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataUnitKerja as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['id'] }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['keterangan'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: BATAS USIA PENSIUN --}}
            <div x-show="activeTab === 'bup'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Batas Usia Pensiun (BUP)
                        </h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi aturan usia
                            pensiun berdasarkan jabatan.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">
                                    Jenis Jabatan BUP</th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">
                                    BUP (Tahun)</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataBUP as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ $item['jenis'] }}</td>
                                    <td class="px-4 py-3 text-sm text-center font-bold text-danger">{{ $item['bup'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['jenis'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- TAB: HARI LIBUR / CUTI BERSAMA --}}
            <div x-show="activeTab === 'hari_libur'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Hari Libur / Cuti Bersama</h2>
                        <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi kalender hari libur nasional dan cuti bersama.</p>
                    </div>
                    <button @click="openModal('tambah')"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah
                    </button>
                </div>
                <div class="rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-soft">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Tanggal</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Nama Hari Libur / Cuti Bersama</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted">Jenis</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted select-none">
                                    <div class="flex justify-end">
                                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.991a6.936 6.936 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="">
                            @foreach($dataHariLibur as $item)
                                <tr class="hover:bg-soft/50 transition-colors">
                                    <td class="px-4 py-3 text-sm font-medium text-ink">{{ \Carbon\Carbon::parse($item['tanggal'])->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-sm text-muted">{{ $item['nama'] }}</td>
                                    <td class="px-4 py-3 text-sm text-center">
                                        @if($item['jenis'] === 'Cuti Bersama')
                                            <span class="text-[11px] font-semibold text-secondary">Cuti Bersama</span>
                                        @else
                                            <span class="text-[11px] font-semibold text-danger">Libur Nasional</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button @click="openModal('edit')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <button @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Hapus">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

        {{-- MODAL OVERLAY --}}
        <div x-show="isModalOpen" style="display: none;"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 backdrop-blur-sm transition-opacity"
            x-transition.opacity>
            {{-- MODAL CONTENT --}}
            <div @click.away="closeModal()"
                class="w-full max-w-lg rounded-xl bg-surface p-6 shadow-xl" x-transition>

                {{-- HEADER --}}
                <div class="mb-6 flex items-center justify-between pb-4">
                    <h3 class="text-xl font-bold text-primary font-sans"
                        x-text="(modalMode === 'tambah' ? 'Tambah ' : 'Edit ') + tabs[activeTab]"></h3>
                    <button @click="closeModal()" class="text-muted hover:text-danger transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- BODY: Dynamic Forms based on activeTab --}}
                <div class="space-y-4">

                    {{-- Form Golongan --}}
                    <div x-show="activeTab === 'golongan'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Kode Pangkat</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: III/a">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Pangkat</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Penata Muda">
                        </div>
                    </div>

                    {{-- Form Jenis Jabatan --}}
                    <div x-show="activeTab === 'jenis_jabatan'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Jabatan</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Masukkan nama jabatan">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-ink">Maks Usia Pensiun</label>
                                <input type="number"
                                    class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                    placeholder="Contoh: 60">
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Catatan</label>
                            <textarea
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                rows="2" placeholder="Catatan opsional..."></textarea>
                        </div>
                    </div>

                    {{-- Form Eselon --}}
                    <div x-show="activeTab === 'eselon'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Kode Eselon</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: I.a">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Eselon</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Eselon I.a">
                        </div>
                    </div>

                    {{-- Form Jenis Cuti --}}
                    <div x-show="activeTab === 'jenis_cuti'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Jenis Cuti</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Cuti Tahunan">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Khusus PNS?</label>
                            <select
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30">
                                <option value="Tidak">Tidak</option>
                                <option value="Ya">Ya</option>
                            </select>
                        </div>
                    </div>

                    {{-- Form Agama --}}
                    <div x-show="activeTab === 'agama'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Agama</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Islam">
                        </div>
                    </div>

                    {{-- Form Jenis Kelamin --}}
                    <div x-show="activeTab === 'jenis_kelamin'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Kode</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: L">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Laki-laki">
                        </div>
                    </div>

                    {{-- Form Status Perkawinan --}}
                    <div x-show="activeTab === 'status_perkawinan'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Status Perkawinan</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Menikah">
                        </div>
                    </div>

                    {{-- Form Jenjang Pendidikan --}}
                    <div x-show="activeTab === 'jenjang_pendidikan'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Jenjang Pendidikan</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: S1">
                        </div>
                    </div>

                    {{-- Form Unit Kerja --}}
                    <div x-show="activeTab === 'unit_kerja'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Unit Kerja</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Masukkan nama unit">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Keterangan</label>
                            <textarea
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                rows="2" placeholder="Penjelasan unit kerja..."></textarea>
                        </div>
                    </div>

                    {{-- Form BUP --}}
                    <div x-show="activeTab === 'bup'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Jenis Jabatan BUP</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Fungsional Ahli Muda">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">BUP (Tahun)</label>
                            <input type="number"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: 58">
                        </div>
                    </div>

                    {{-- Form Hari Libur --}}
                    <div x-show="activeTab === 'hari_libur'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Tanggal</label>
                            <input type="date"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Hari Libur / Cuti Bersama</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Hari Raya Idul Fitri">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Jenis</label>
                            <select
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30">
                                <option value="Hari Libur Nasional">Hari Libur Nasional</option>
                                <option value="Cuti Bersama">Cuti Bersama</option>
                            </select>
                        </div>
                    </div>

                </div>

                {{-- FOOTER --}}
                <div class="mt-8 flex justify-end gap-3 pt-5">
                    <button @click="closeModal()"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-muted hover:bg-soft transition-colors">
                        Batal
                    </button>
                    <button @click="closeModal()"
                        class="rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 transition-colors">
                        Simpan
                    </button>
                </div>

            </div>
        </div>

        {{-- DELETE CONFIRMATION MODAL --}}
        <x-ui.confirm-dialog
            id="delete-master"
            title="Konfirmasi Hapus Data Master"
            message=""
            confirm-text="Hapus (Soft Delete)"
            variant="danger"
        >
            <div class="space-y-4">
                <p class="text-sm text-ink leading-relaxed font-sans font-medium">
                    Apakah Anda yakin ingin menghapus data referensi <strong class="text-danger" x-text="deleteItemName"></strong> ini?
                </p>
                <div class="rounded-lg border border-danger/20 bg-danger/5 p-3 text-xs text-danger flex gap-2 font-sans">
                    <svg class="w-4.5 h-4.5 shrink-0 mt-0.5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <span class="font-bold">⚠️ RESTRIKSI INTEGRITAS:</span> Data master yang saat ini aktif digunakan oleh data pegawai <strong>tidak diperkenankan untuk dihapus</strong> dari sistem.
                    </div>
                </div>
                <p class="text-xs text-muted leading-relaxed font-sans">
                    Metode penghapusan akan menerapkan **Soft Delete** guna menjaga integritas data riwayat historis kepegawaian (audit trail).
                </p>
            </div>
        </x-ui.confirm-dialog>

    </div>

</x-layouts.app>
