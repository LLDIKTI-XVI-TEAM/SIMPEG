<x-layouts.app title="Pengajuan Cuti Bawahan" subtitle="Kelola dan berikan persetujuan untuk cuti bawahan Anda.">

    @php
        // DUMMY DATA UNTUK UI
        $cutiList = [
            [
                'id' => 1,
                'nama' => 'Ahmad Fauzi',
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
    <x-ui.filter-bar searchPlaceholder="Cari nama pegawai..." searchCols="lg:col-span-2">
        <!-- Filter Status -->
        <div>
            <select class="w-full rounded-lg border border-border bg-white py-2.5 px-3 text-sm font-sans focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-sm text-ink appearance-none">
                <option value="">Semua Status</option>
                <option value="menunggu" selected>Menunggu Tindakan Saya</option>
                <option value="perubahan">Perubahan</option>
                <option value="ditangguhkan">Ditangguhkan</option>
                <option value="selesai">Selesai</option>
            </select>
        </div>

        <!-- Filter Jenis Cuti -->
        <div>
            <select class="w-full rounded-lg border border-border bg-white py-2.5 px-3 text-sm font-sans focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-sm text-ink appearance-none">
                <option value="">Semua Jenis Cuti</option>
                <option value="tahunan">Cuti Tahunan</option>
                <option value="sakit">Cuti Sakit</option>
                <option value="penting">Cuti Alasan Penting</option>
                <option value="melahirkan">Cuti Melahirkan</option>
                <option value="besar">Cuti Besar</option>
                <option value="cltn">Cuti di Luar Tanggungan Negara</option>
            </select>
        </div>

        <!-- Filter Tahun/Periode -->
        <div>
            <select class="w-full rounded-lg border border-border bg-white py-2.5 px-3 text-sm font-sans focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-sm text-ink appearance-none">
                <option value="2026">Tahun 2026</option>
                <option value="2025">Tahun 2025</option>
            </select>
        </div>
    </x-ui.filter-bar>

    <!-- MAIN TABLE -->
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th padding="lg">Nama Pegawai</x-ui.table-th>
                        <x-ui.table-th padding="lg">Jenis Cuti</x-ui.table-th>
                        <x-ui.table-th padding="lg">Tanggal Cuti</x-ui.table-th>
                        <x-ui.table-th padding="lg">Alasan Singkat</x-ui.table-th>
                        <x-ui.table-th padding="lg">Status</x-ui.table-th>
                        <x-ui.table-th padding="lg">Tgl Ajukan</x-ui.table-th>
                        <x-ui.table-th align="right" padding="lg">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @foreach($cutiList as $cuti)
                    <x-ui.table-row class="hover:bg-soft transition-colors group">
                        <!-- NAMA PEGAWAI -->
                        <x-ui.table-td class="px-6 py-4">
                            <p class="text-sm font-bold text-ink font-sans">{{ $cuti['nama'] }}</p>
                            <p class="text-[11px] text-muted font-sans mt-0.5">Bawahan Langsung</p>
                        </x-ui.table-td>
                        
                        <!-- JENIS CUTI -->
                        <x-ui.table-td class="px-6 py-4 font-medium text-ink text-sm">
                            {{ $cuti['jenis_cuti'] }}
                        </x-ui.table-td>

                        <!-- TANGGAL CUTI & JML HARI -->
                        <x-ui.table-td class="px-6 py-4">
                            <p class="text-sm font-medium text-ink font-sans">{{ $cuti['tgl_mulai'] }} - {{ $cuti['tgl_selesai'] }}</p>
                            <p class="text-xs text-muted font-sans mt-0.5 bg-surface inline-block px-1.5 py-0.5 rounded">{{ $cuti['jml_hari'] }} Kerja</p>
                        </x-ui.table-td>

                        <!-- ALASAN SINGKAT -->
                        <x-ui.table-td class="px-6 py-4 text-xs text-muted max-w-[200px]">
                            <x-ui.tooltip text="{{ $cuti['alasan'] }}" position="top">
                                <div class="truncate">{{ $cuti['alasan'] }}</div>
                            </x-ui.tooltip>
                        </x-ui.table-td>

                        <!-- STATUS -->
                        <x-ui.table-td class="px-6 py-4">
                                @if($cuti['status'] === 'Menunggu Tindakan Saya')
                                    <x-ui.badge variant="warning" size="md" dot>Menunggu Tindakan</x-ui.badge>
                                @elseif($cuti['status'] === 'Selesai')
                                    <x-ui.badge variant="success" size="md" dot>{{ $cuti['status'] }}</x-ui.badge>
                                @elseif($cuti['status'] === 'Perubahan')
                                    <x-ui.badge variant="info" size="md" dot>{{ $cuti['status'] }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="danger" size="md" dot>{{ $cuti['status'] }}</x-ui.badge>
                                @endif
                        </x-ui.table-td>

                        <!-- TANGGAL AJUKAN -->
                        <x-ui.table-td class="px-6 py-4 text-xs text-muted">
                            {{ $cuti['tgl_ajukan'] }}
                        </x-ui.table-td>

                        <!-- AKSI -->
                        <x-ui.table-td align="right" class="px-6 py-4">
                            <x-ui.button href="{{ route('kabag.cuti.show', ['id' => $cuti['id']]) }}" variant="primary" size="sm" class="{{ $cuti['status'] === 'Menunggu Tindakan Saya' ? '' : 'hidden' }}">
                                Tindak Lanjuti
                            </x-ui.button>
                            <x-ui.button href="{{ route('kabag.cuti.show', ['id' => $cuti['id']]) }}" variant="secondary" size="sm" class="{{ $cuti['status'] !== 'Menunggu Tindakan Saya' ? '' : 'hidden' }}">
                                Detail
                            </x-ui.button>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforeach
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        <div class="border-t border-border px-6 py-4 bg-surface flex items-center justify-between">
            <p class="text-xs text-muted font-sans">Menampilkan 5 pengajuan terbaru</p>
            <x-ui.pagination current="1" total="1" />
        </div>
    </x-ui.card>

</x-layouts.app>
