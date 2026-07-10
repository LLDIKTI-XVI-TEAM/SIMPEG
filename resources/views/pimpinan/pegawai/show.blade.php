<x-layouts.app title="Detail Pegawai">

<div class="space-y-6" x-data="{ activeTab: 'profil', visiblePanels: ['profil', 'info'] }">

    {{-- PAGE HEADER --}}
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-ink">Detail Pegawai</h2>
        <x-ui.breadcrumb :items="[
            ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
            ['label' => 'Data Pegawai', 'url' => route('pimpinan.pegawai.index')],
            ['label' => $employeeData['nama']]
        ]" />
    </div>

    {{-- Profile Header Card --}}
    <x-ui.card>
        <div class="flex flex-col md:flex-row gap-6 items-start">
            <div class="h-24 w-24 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                <span class="text-3xl font-bold text-primary">{{ substr($employeeData['nama'], 0, 1) }}</span>
            </div>
            
            <div class="flex-1 space-y-2">
                <div>
                    <h2 class="text-2xl font-bold text-ink">{{ $employeeData['nama'] }}</h2>
                    <p class="text-primary font-medium">{{ $employeeData['nip'] }}</p>
                </div>
                
                <div class="flex flex-wrap gap-4 text-sm text-muted">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        {{ $employeeData['jabatan'] }}
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                        {{ $employeeData['unit_kerja'] }}
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-semibold text-success uppercase">
                            {{ $employeeData['status'] }}
                        </span>
                    </div>
                </div>
            </div>
            
            <div>
                <form action="{{ route('pimpinan.laporan.pegawai.custom') }}" method="POST" target="_blank" class="inline-flex">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $employeeData['id'] ?? '' }}">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink shadow-sm transition hover:bg-soft">
                        Cetak Riwayat
                    </button>
                </form>
            </div>
        </div>
    </x-ui.card>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        
        {{-- Sidebar Tabs --}}
        <div class="lg:col-span-1">
            <x-ui.tabs variant="sidebar">
                <x-ui.tab active="activeTab === 'profil'" click="activeTab = 'profil'" variant="sidebar">
                    Profil & Kontak
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'kepangkatan'" click="activeTab = 'kepangkatan'" variant="sidebar">
                    Riwayat Kepangkatan
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'jabatan'" click="activeTab = 'jabatan'" variant="sidebar">
                    Riwayat Jabatan
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'kgb'" click="activeTab = 'kgb'" variant="sidebar">
                    Riwayat KGB
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'pendidikan'" click="activeTab = 'pendidikan'" variant="sidebar">
                    Pendidikan
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'disiplin'" click="activeTab = 'disiplin'" variant="sidebar">
                    Hukuman Disiplin
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'dokumen'" click="activeTab = 'dokumen'" variant="sidebar">
                    Dokumen & SK
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'pengangkatan'" click="activeTab = 'pengangkatan'" variant="sidebar">
                    Data Pengangkatan
                </x-ui.tab>
                <x-ui.tab active="activeTab === 'info'" click="activeTab = 'info'" variant="sidebar">
                    Info Otomatis
                </x-ui.tab>
            </x-ui.tabs>
        </div>

        {{-- Tab Content --}}
        <div class="lg:col-span-3">
            
            {{-- Profil --}}
            <div x-show="activeTab === 'profil'" x-cloak class="space-y-6">
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4 border-b border-border pb-2">Informasi Dasar</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-muted text-xs">Tempat, Tanggal Lahir</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['tempat_lahir'] }}, {{ \Carbon\Carbon::parse($employeeData['tanggal_lahir'])->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted text-xs">Jenis Kelamin</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['jenis_kelamin'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted text-xs">Agama</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['agama'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted text-xs">Pendidikan Terakhir</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['pendidikan_terakhir'] }}</dd>
                        </div>
                    </div>
                </x-ui.card>
                
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4 border-b border-border pb-2">Data Sensitif & Kontak</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-muted text-xs">NIK</dt>
                            <dd class="font-mono font-medium text-ink">3174********{{ substr($employeeData['nik'], -4) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted text-xs">NPWP</dt>
                            <dd class="font-mono font-medium text-ink">12.345.***.*-***.000</dd>
                        </div>
                        <div>
                            <dt class="text-muted text-xs">Telepon/No. HP</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['telepon'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted text-xs">Email</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['email'] }}</dd>
                        </div>
                        <div class="md:col-span-2">
                            <dt class="text-muted text-xs">Alamat Lengkap</dt>
                            <dd class="font-medium text-ink">{{ $employeeData['alamat'] }}</dd>
                        </div>
                    </div>
                    <div class="mt-4 pt-2">
                        <p class="text-[10px] text-danger/80 italic">* Catatan Keamanan: NIK dan NPWP di-masking secara default sesuai pengaturan hak akses Pimpinan (Read-Only).</p>
                    </div>
                </x-ui.card>
            </div>

            {{-- Kepangkatan --}}
            <div x-show="activeTab === 'kepangkatan'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Riwayat Kepangkatan</h3>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Golongan/Ruang</x-ui.table-th>
                                    <x-ui.table-th>TMT Pangkat</x-ui.table-th>
                                    <x-ui.table-th>No. SK</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($riwayatKepangkatan as $rk)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-medium text-ink">{{ $rk['golongan'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rk['tmt'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rk['sk'] }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </x-ui.card>
            </div>

            {{-- Jabatan --}}
            <div x-show="activeTab === 'jabatan'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Riwayat Jabatan</h3>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Jabatan</x-ui.table-th>
                                    <x-ui.table-th>Unit Kerja</x-ui.table-th>
                                    <x-ui.table-th>TMT</x-ui.table-th>
                                    <x-ui.table-th>Kelas</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($riwayatJabatan as $rj)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-medium text-ink">{{ $rj['jabatan'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rj['unit'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rj['tmt'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rj['kelas'] }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </x-ui.card>
            </div>

            {{-- KGB --}}
            <div x-show="activeTab === 'kgb'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Kenaikan Gaji Berkala (KGB)</h3>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>TMT KGB</x-ui.table-th>
                                    <x-ui.table-th>Gaji Pokok</x-ui.table-th>
                                    <x-ui.table-th>No. SK</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($riwayatKgb as $kgb)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-medium text-ink">{{ $kgb['tmt'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $kgb['gaji'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $kgb['sk'] }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </x-ui.card>
            </div>

            {{-- Pendidikan --}}
            <div x-show="activeTab === 'pendidikan'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Riwayat Pendidikan</h3>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Tingkat</x-ui.table-th>
                                    <x-ui.table-th>Jurusan</x-ui.table-th>
                                    <x-ui.table-th>Institusi</x-ui.table-th>
                                    <x-ui.table-th>Tahun</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($riwayatPendidikan as $rp)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-medium text-ink">{{ $rp['tingkat'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rp['jurusan'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rp['institusi'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $rp['tahun'] }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </x-ui.card>
            </div>

            {{-- Disiplin --}}
            <div x-show="activeTab === 'disiplin'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Hukuman Disiplin</h3>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Tanggal</x-ui.table-th>
                                    <x-ui.table-th>Jenis Hukuman</x-ui.table-th>
                                    <x-ui.table-th>Keterangan</x-ui.table-th>
                                    <x-ui.table-th>Status</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @foreach($hukumanDisiplin as $hd)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-medium text-ink">{{ $hd['tanggal'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $hd['jenis'] }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $hd['keterangan'] }}</x-ui.table-td>
                                        <x-ui.table-td><x-ui.badge variant="success">{{ $hd['status'] }}</x-ui.badge></x-ui.table-td>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </x-ui.card>
            </div>

            {{-- Dokumen --}}
            <div x-show="activeTab === 'dokumen'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Dokumen & SK</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($dokumen as $dok)
                        <div class="border border-border rounded-lg p-4 flex items-center justify-between hover:bg-soft transition">
                            <div class="flex items-center gap-3">
                                <svg class="w-8 h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"></path></svg>
                                <div>
                                    <p class="font-medium text-ink text-sm">{{ $dok['nama'] }}</p>
                                    <p class="text-xs text-muted">{{ $dok['tanggal'] }}</p>
                                </div>
                            </div>
                            <x-ui.button variant="secondary" size="sm">Lihat</x-ui.button>
                        </div>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>

            {{-- Pengangkatan --}}
            <div x-show="activeTab === 'pengangkatan'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Data Pengangkatan (CPNS/PNS/PPPK)</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div class="p-3 bg-soft rounded-lg">
                            <dt class="text-muted text-xs mb-1">Jenis Pegawai</dt>
                            <dd class="font-bold text-ink">{{ $dataPengangkatan['jenis'] }}</dd>
                        </div>
                        <div class="p-3 bg-soft rounded-lg">
                            <dt class="text-muted text-xs mb-1">TMT CPNS</dt>
                            <dd class="font-medium text-ink">{{ $dataPengangkatan['tmt_cpns'] }}</dd>
                        </div>
                        <div class="p-3 bg-soft rounded-lg">
                            <dt class="text-muted text-xs mb-1">No. SK CPNS</dt>
                            <dd class="font-medium text-ink">{{ $dataPengangkatan['sk_cpns'] }}</dd>
                        </div>
                        <div class="p-3 bg-soft rounded-lg">
                            <dt class="text-muted text-xs mb-1">TMT PNS</dt>
                            <dd class="font-medium text-ink">{{ $dataPengangkatan['tmt_pns'] }}</dd>
                        </div>
                        <div class="p-3 bg-soft rounded-lg">
                            <dt class="text-muted text-xs mb-1">No. SK PNS</dt>
                            <dd class="font-medium text-ink">{{ $dataPengangkatan['sk_pns'] }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>

            {{-- Info Otomatis --}}
            <div x-show="activeTab === 'info'" x-cloak>
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-ink mb-4">Info Otomatis (Proyeksi Masa Depan)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="border border-border rounded-xl p-4 text-center">
                            <p class="text-xs text-muted mb-2 font-medium uppercase tracking-wider">KGB Berikutnya</p>
                            <p class="text-xl font-bold text-ink">{{ \Carbon\Carbon::parse($infoOtomatis['kgb_berikutnya'])->format('d M Y') }}</p>
                        </div>
                        <div class="border border-border rounded-xl p-4 text-center">
                            <p class="text-xs text-muted mb-2 font-medium uppercase tracking-wider">Kenaikan Pangkat (KP)</p>
                            <p class="text-xl font-bold text-ink">{{ \Carbon\Carbon::parse($infoOtomatis['kp_berikutnya'])->format('d M Y') }}</p>
                        </div>
                        <div class="border border-border rounded-xl p-4 text-center bg-danger/5 border-danger/20">
                            <p class="text-xs text-danger mb-2 font-medium uppercase tracking-wider">Pensiun (BUP)</p>
                            <p class="text-xl font-bold text-danger">{{ \Carbon\Carbon::parse($infoOtomatis['pensiun'])->format('Y') }}</p>
                        </div>
                    </div>
                </x-ui.card>
            </div>

        </div>
    </div>
</div>

</x-layouts.app>
