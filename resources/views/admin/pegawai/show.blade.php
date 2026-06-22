<x-layouts.app title="Detail Pegawai">

    @php
    // Mapping dokumen dummy khusus berdasarkan ID pegawai untuk mensimulasikan relasi data
    $riwayatDokumen = [];
    if ($p['id'] == 1) {
        $riwayatDokumen = [
            [
                'id' => 1,
                'jenis' => 'SK Kenaikan Pangkat',
                'nama' => 'SK Kenaikan Pangkat Penata Tkt. I',
                'nomor' => 'SK-882-KP-2024',
                'tanggal' => '2024-04-01',
                'kategori_label' => 'SK Kenaikan Pangkat',
                'file_size' => '1.2 MB',
                'deskripsi' => 'SK Kenaikan Pangkat Penata Tingkat I Golongan Ruang III/d atas nama Ahmad Fauzi.'
            ],
            [
                'id' => 2,
                'jenis' => 'SK Kenaikan Jabatan',
                'nama' => 'SK Pengangkatan Jabatan Analis Kepegawaian',
                'nomor' => 'SK-104-JAB-2022',
                'tanggal' => '2022-08-15',
                'kategori_label' => 'SK Kenaikan Jabatan',
                'file_size' => '850 KB',
                'deskripsi' => 'SK Pengangkatan Pertama kali dalam Jabatan Fungsional Analis Kepegawaian Ahli Pertama.'
            ],
            [
                'id' => 3,
                'jenis' => 'SK KGB',
                'nama' => 'SK Kenaikan Gaji Berkala 2025',
                'nomor' => 'KGB-334-VII-2025',
                'tanggal' => '2025-07-01',
                'kategori_label' => 'SK KGB',
                'file_size' => '420 KB',
                'deskripsi' => 'Surat Keterangan Kenaikan Gaji Berkala Reguler tahun berjalan 2025.'
            ],
            [
                'id' => 4,
                'jenis' => 'Ijazah',
                'nama' => 'Ijazah Sarjana (S1) Manajemen',
                'nomor' => 'IJZ-S1-MAN-2007',
                'tanggal' => '2007-09-20',
                'kategori_label' => 'Ijazah / Pendidikan',
                'file_size' => '2.1 MB',
                'deskripsi' => 'Ijazah Sarjana S1 Program Studi Manajemen dari Universitas Sam Ratulangi.'
            ]
        ];
    } elseif ($p['id'] == 2) {
        $riwayatDokumen = [
            [
                'id' => 5,
                'jenis' => 'KTP',
                'nama' => 'Kartu Tanda Penduduk (KTP)',
                'nomor' => '3171-7403-8803-0001',
                'tanggal' => '2021-05-10',
                'kategori_label' => 'Identitas Diri (KTP & KK)',
                'file_size' => '620 KB',
                'deskripsi' => 'KTP atas nama Siti Rahayu.'
            ],
            [
                'id' => 6,
                'jenis' => 'KK',
                'nama' => 'Kartu Keluarga (KK)',
                'nomor' => '3171-7403-8803-0002',
                'tanggal' => '2021-05-10',
                'kategori_label' => 'Identitas Diri (KTP & KK)',
                'file_size' => '580 KB',
                'deskripsi' => 'Kartu Keluarga terbaru atas nama kepala keluarga Siti Rahayu.'
            ]
        ];
    } elseif ($p['id'] == 3) {
        $riwayatDokumen = [
            [
                'id' => 7,
                'jenis' => 'SK Pengangkatan',
                'nama' => 'SK Pengangkatan PNS 2026',
                'nomor' => 'SK-220-PNS-2026',
                'tanggal' => '2026-01-01',
                'kategori_label' => 'SK Pengangkatan',
                'file_size' => '1.5 MB',
                'deskripsi' => 'SK Pengangkatan PNS atas nama Sabrina Rossa.'
            ]
        ];
    }
    @endphp

    <div x-data="{ activeTab: 'profile' }" class="mx-auto max-w-4xl space-y-6">
        
        {{-- BREADCRUMBS --}}
        <div class="mb-2">
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Pegawai</span>
            </nav>
        </div>

        {{-- MAIN DETAIL CARD --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            
            {{-- Header info --}}
            <div class="border-b border-border pb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-xl font-bold text-primary shrink-0">
                        {{ strtoupper(substr($p['nama'], 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ $p['nama'] }}</h2>
                        <p class="text-xs text-muted font-sans font-mono mt-0.5">NIP. {{ $p['nip'] }}</p>
                        <span class="inline-block mt-1.5 rounded-full bg-primary/10 text-primary px-2.5 py-0.5 text-xs font-semibold font-sans uppercase">{{ $p['jenis'] }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft">
                        Kembali
                    </a>
                </div>
            </div>

            {{-- TAB NAVIGATION --}}
            <div class="border-b border-border flex gap-6">
                <button 
                    @click="activeTab = 'profile'" 
                    :class="activeTab === 'profile' ? 'border-b-2 border-primary text-primary font-semibold pb-2' : 'text-muted hover:text-ink font-medium pb-2'" 
                    class="text-sm transition-colors cursor-pointer focus:outline-none"
                >
                    Profil & Jabatan
                </button>
                <button 
                    @click="activeTab = 'docs'" 
                    :class="activeTab === 'docs' ? 'border-b-2 border-primary text-primary font-semibold pb-2' : 'text-muted hover:text-ink font-medium pb-2'" 
                    class="text-sm transition-colors cursor-pointer focus:outline-none"
                >
                    Dokumen & SK Kepegawaian
                </button>
            </div>

            {{-- TAB 1: PROFILE & JABATAN --}}
            <div x-show="activeTab === 'profile'" class="grid grid-cols-1 md:grid-cols-2 gap-6" x-transition>
                <div class="space-y-4">
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Informasi Jabatan</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Jabatan</span>
                            <p class="text-sm font-medium text-ink font-sans">{{ $p['jabatan'] }}</p>
                        </div>
                        <div class="space-y-1">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Unit Kerja</span>
                            <p class="text-sm font-medium text-ink font-sans">{{ $p['unit'] }}</p>
                        </div>
                        <div class="space-y-1">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Golongan</span>
                            <p class="text-sm font-medium text-ink font-sans">{{ $p['golongan'] }}</p>
                        </div>
                        <div class="space-y-1">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Status Kerja</span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 text-success px-2.5 py-0.5 text-xs font-semibold font-sans mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                                Aktif
                            </span>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Hubungan & Kontak</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Alamat Email</span>
                            <p class="text-sm font-medium text-ink font-sans font-mono">{{ $p['email'] ?? '-' }}</p>
                        </div>
                        <div class="space-y-1">
                            <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Nomor Telepon</span>
                            <p class="text-sm font-medium text-ink font-sans font-mono">{{ $p['telepon'] ?? '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 2: DOKUMEN & SK KEPEGAWAIAN --}}
            <div x-show="activeTab === 'docs'" style="display: none;" class="space-y-4" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Daftar Berkas Fisik Kepegawaian</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Daftar arsip dokumen legalitas pendukung karir staf.</p>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full">
                        <thead class="bg-soft border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nama Dokumen</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Kategori</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Nomor Dokumen</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Tanggal Terbit</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Ukuran</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse($riwayatDokumen as $doc)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 max-w-xs">
                                    <div class="flex items-start gap-2.5">
                                        <div class="flex h-8 w-6 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-0.5 shadow-sm relative">
                                            <div class="w-full bg-primary/10 text-primary text-[5px] font-bold text-center py-0.5 uppercase tracking-wide">
                                                PDF
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-ink font-sans truncate">{{ $doc['nama'] }}</p>
                                            <p class="text-[10px] text-muted font-sans mt-0.5 truncate">{{ $doc['deskripsi'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted font-sans">{{ $doc['kategori_label'] }}</td>
                                <td class="px-4 py-3 text-xs font-mono text-muted">{{ $doc['nomor'] }}</td>
                                <td class="px-4 py-3 text-xs font-mono text-muted">{{ $doc['tanggal'] }}</td>
                                <td class="px-4 py-3 text-xs font-mono text-muted">{{ $doc['file_size'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <a href="/dashboard/dokumen/{{ $doc['id'] }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                            Detail
                                        </a>
                                        <span class="text-border">|</span>
                                        <a href="/dashboard/dokumen/{{ $doc['id'] }}/download" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline font-sans">
                                            Unduh
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-xs text-muted font-sans">
                                    Belum ada dokumen atau SK kepegawaian yang diunggah untuk staf ini.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-layouts.app>
