<x-layouts.app title="Pengajuan Cuti Bawahan" subtitle="Kelola dan berikan persetujuan untuk cuti bawahan Anda.">

    @php
        // DUMMY DATA UNTUK UI
        $cutiList = [
            [
                'id' => 1,
                'nama' => 'Ahmad Fauzi',
                'nip' => '198123456789100000',
                'jenis_cuti' => 'Cuti Tahunan',
                'tgl_mulai' => '20 Jun 2026',
                'tgl_selesai' => '24 Jun 2026',
                'jml_hari' => '5 Hari',
                'alasan' => 'Acara keluarga di kampung halaman...',
                'status' => 'Menunggu Tindakan Saya',
                'tgl_ajukan' => '15 Jun 2026',
            ],
            [
                'id' => 2,
                'nama' => 'Nadia Kusuma',
                'nip' => '198512345678910000',
                'jenis_cuti' => 'Cuti Sakit',
                'tgl_mulai' => '19 Jun 2026',
                'tgl_selesai' => '21 Jun 2026',
                'jml_hari' => '3 Hari',
                'alasan' => 'Sakit demam berdarah (surat dokter terlampir)...',
                'status' => 'Menunggu Tindakan Saya',
                'tgl_ajukan' => '19 Jun 2026',
            ],
            [
                'id' => 3,
                'nama' => 'Budi Santoso',
                'nip' => '199012345678910000',
                'jenis_cuti' => 'Cuti Alasan Penting',
                'tgl_mulai' => '10 Jul 2026',
                'tgl_selesai' => '15 Jul 2026',
                'jml_hari' => '4 Hari',
                'alasan' => 'Menemani istri melahirkan...',
                'status' => 'Ditangguhkan',
                'tgl_ajukan' => '10 Jun 2026',
            ],
            [
                'id' => 4,
                'nama' => 'Siti Rahayu',
                'nip' => '198812345678910000',
                'jenis_cuti' => 'Cuti Tahunan',
                'tgl_mulai' => '01 Agu 2026',
                'tgl_selesai' => '05 Agu 2026',
                'jml_hari' => '5 Hari',
                'alasan' => 'Liburan akhir tahun bersama keluarga...',
                'status' => 'Perubahan',
                'tgl_ajukan' => '05 Jun 2026',
            ],
            [
                'id' => 5,
                'nama' => 'Teguh Wibowo',
                'nip' => '197512345678910000',
                'jenis_cuti' => 'Cuti Besar',
                'tgl_mulai' => '01 Sep 2026',
                'tgl_selesai' => '30 Sep 2026',
                'jml_hari' => '22 Hari',
                'alasan' => 'Ibadah Haji (surat pengantar dari biro terlampir)...',
                'status' => 'Selesai',
                'tgl_ajukan' => '01 Jun 2026',
            ],
        ];
    @endphp

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Pengajuan Cuti Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Cuti Bawahan']
            ]" />
        </div>
    </div>

    <!-- FILTER & PENCARIAN -->
    <x-ui.filter-bar searchId="search" searchName="search" searchPlaceholder="Cari nama pegawai..." searchCols="lg:col-span-1">
        <!-- Filter Status -->
        <div>
            <x-form.select size="md">
                <option value="">Semua Status</option>
                <option value="menunggu" selected>Menunggu Tindakan Saya</option>
                <option value="perubahan">Perubahan</option>
                <option value="ditangguhkan">Ditangguhkan</option>
                <option value="selesai">Selesai</option>
            </x-form.select>
        </div>

        <!-- Filter Jenis Cuti -->
        <div>
            <x-form.select size="md">
                <option value="">Semua Jenis Cuti</option>
                <option value="tahunan">Cuti Tahunan</option>
                <option value="sakit">Cuti Sakit</option>
                <option value="penting">Cuti Alasan Penting</option>
                <option value="melahirkan">Cuti Melahirkan</option>
                <option value="besar">Cuti Besar</option>
                <option value="cltn">Cuti di Luar Tanggungan Negara</option>
            </x-form.select>
        </div>

        <!-- Filter Tahun/Periode -->
        <div>
            <x-form.select size="md">
                <option value="2026">Tahun 2026</option>
                <option value="2025">Tahun 2025</option>
            </x-form.select>
        </div>
    </x-ui.filter-bar>

    <!-- MAIN TABLE -->
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="border-b border-border px-6 py-4 bg-surface">
            <h3 class="text-sm font-semibold text-ink font-sans">Daftar Permohonan Cuti Bawahan</h3>
            <p class="text-[10px] text-muted font-sans">Menampilkan pengajuan cuti dari bawahan langsung yang memerlukan persetujuan atau sekadar riwayat.</p>
        </div>
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head class="border-b border-border">
                    <x-ui.table-row>
                        <x-ui.table-th class="px-6 py-3.5">Pegawai</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Jenis Cuti</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Durasi & Tanggal</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Alasan</x-ui.table-th>
                        <x-ui.table-th class="px-6 py-3.5">Status</x-ui.table-th>
                        <x-ui.table-th align="right" class="px-6 py-3.5">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @foreach($cutiList as $cuti)
                    <x-ui.table-row class="hover:bg-soft transition-colors group" :interactive="true">
                        <!-- NAMA PEGAWAI -->
                        <x-ui.table-td padding="comfortable">
                            <div>
                                <p class="text-sm font-semibold text-ink font-sans leading-tight">{{ $cuti['nama'] }}</p>
                                <p class="text-[10px] text-muted font-sans mt-0.5">NIP. {{ $cuti['nip'] }}</p>
                            </div>
                        </x-ui.table-td>
                        
                        <!-- JENIS CUTI & TGL AJUKAN -->
                        <x-ui.table-td padding="comfortable" class="text-sm font-medium text-ink">
                            {{ $cuti['jenis_cuti'] }}
                            <div class="mt-1 text-[10px] font-semibold text-muted font-sans">
                                Ajukan: {{ $cuti['tgl_ajukan'] }}
                            </div>
                        </x-ui.table-td>

                        <!-- DURASI & TANGGAL -->
                        <x-ui.table-td padding="comfortable" class="text-sm font-mono">
                            {{ $cuti['jml_hari'] }}
                            <br>
                            <span class="text-[10px] text-muted font-sans">{{ $cuti['tgl_mulai'] }} - {{ $cuti['tgl_selesai'] }}</span>
                        </x-ui.table-td>

                        <!-- ALASAN SINGKAT -->
                        <x-ui.table-td title="{{ $cuti['alasan'] }}" padding="comfortable" class="text-xs text-muted max-w-[200px] truncate">
                            {{ $cuti['alasan'] }}
                        </x-ui.table-td>

                        <!-- STATUS -->
                        <x-ui.table-td padding="comfortable">
                            @if($cuti['status'] === 'Menunggu Tindakan Saya')
                                <x-ui.badge variant="warning" size="sm" dot>Menunggu Tindakan</x-ui.badge>
                            @elseif($cuti['status'] === 'Selesai')
                                <x-ui.badge variant="success" size="sm" dot>{{ $cuti['status'] }}</x-ui.badge>
                            @elseif($cuti['status'] === 'Perubahan')
                                <x-ui.badge variant="info" size="sm" dot>{{ $cuti['status'] }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="danger" size="sm" dot>{{ $cuti['status'] }}</x-ui.badge>
                            @endif
                        </x-ui.table-td>

                        <!-- AKSI -->
                        <x-ui.table-td align="right" padding="comfortable">
                            <x-ui.button as="a" href="{{ route('kepala-bagian.cuti.show', ['id' => $cuti['id']]) }}" variant="secondary" size="icon" title="Lihat Detail" tooltip-position="top-end" aria-label="Lihat Detail">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </x-ui.button>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforeach
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row" x-data="{ currentPage: 1, totalPages: 1 }">
            <div class="flex items-center gap-4">
                <p class="text-sm text-muted hidden sm:block">
                    Menampilkan <span class="font-semibold text-ink">1</span> hingga <span class="font-semibold text-ink">5</span> dari <span class="font-semibold text-ink">5</span> hasil
                </p>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.pagination />
            </div>
        </div>
    </x-ui.card>

</x-layouts.app>
