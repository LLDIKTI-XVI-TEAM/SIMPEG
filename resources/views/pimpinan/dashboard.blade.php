<x-layouts.app title="Dashboard Pimpinan">


<div class="max-w-7xl mx-auto px-6 py-6 space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Dashboard Pimpinan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard Pimpinan']
            ]" />
        </div>
    </div>

    {{-- Top Stats Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        
        {{-- W1: Komposisi Pegawai --}}
        <x-ui.stat-card 
            href="{{ route('pimpinan.pegawai.index') }}"
            label="Total Pegawai"
            value="{{ $totalPegawai }}"
            variant="primary"
        >
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </x-slot:icon>
            <x-slot:description>
                <div class="flex justify-between w-full">
                    <span>PNS: <span class="font-semibold text-ink">{{ $komposisi['PNS'] }}</span></span>
                    <span>PPPK: <span class="font-semibold text-ink">{{ $komposisi['PPPK'] }}</span></span>
                    <span>CPNS: <span class="font-semibold text-ink">{{ $komposisi['CPNS'] }}</span></span>
                </div>
            </x-slot:description>
        </x-ui.stat-card>

        {{-- W2: Kenaikan Pangkat --}}
        <x-ui.stat-card 
            href="{{ route('pimpinan.ews.index', ['jenis' => 'KENAIKAN_PANGKAT']) }}"
            label="Kenaikan Pangkat"
            value="{{ $naikPangkatBulanIni }}"
            variant="info"
        >
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 11l7-7 7 7M5 19l7-7 7 7"></path></svg>
            </x-slot:icon>
            <x-slot:description>
                Bulan ini (Total tahun ini: <span class="font-semibold text-ink">{{ $naikPangkatTahunIni }}</span>)
            </x-slot:description>
        </x-ui.stat-card>

        {{-- W3: Status Cuti --}}
        <x-ui.stat-card 
            href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu-final']) }}"
            label="Cuti Pending"
            value="{{ $cutiPending }}"
            variant="warning"
        >
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </x-slot:icon>
            <x-slot:description>
                <div class="flex justify-between w-full">
                    <span>Disetujui: <span class="font-semibold text-success">{{ $cutiDisetujuiBulanIni }}</span></span>
                    <span>Ditunda: <span class="font-semibold text-danger">{{ $cutiDitunda }}</span></span>
                </div>
            </x-slot:description>
        </x-ui.stat-card>

        {{-- W4: EWS Aktif --}}
        <x-ui.stat-card 
            href="{{ route('pimpinan.ews.index') }}"
            label="EWS Urgent"
            value="{{ count(array_filter($ewsAktif, fn($e) => $e['indikator'] === 'merah')) }}"
            variant="danger"
        >
            <x-slot:icon>
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </x-slot:icon>
            <x-slot:description>
                Perlu perhatian segera (< 30 hari)
            </x-slot:description>
        </x-ui.stat-card>

    </div>

    {{-- Main Content Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- W5: Distribusi Golongan --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-ink">Distribusi Golongan</h2>
                <a href="{{ route('pimpinan.pegawai.index') }}" class="text-sm text-primary hover:underline font-medium">Lihat Pegawai</a>
            </div>
            <div class="space-y-4">
                @foreach(['IV', 'III', 'II', 'I'] as $gol)
                    @php 
                        $val = $distribusiGolongan[$gol] ?? 0;
                        $pct = $totalPegawai > 0 ? round(($val / $totalPegawai) * 100) : 0;
                    @endphp
                    <div>
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="font-medium text-ink">Golongan {{ $gol }}</span>
                            <span class="text-muted">{{ $val }} Pegawai ({{ $pct }}%)</span>
                        </div>
                        <div class="w-full bg-soft rounded-full h-2">
                            <div class="bg-primary h-2 rounded-full" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- W7: Tren Pegawai --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-ink">Tren Pertumbuhan Pegawai</h2>
                <span class="text-xs text-muted">12 Bulan Terakhir</span>
            </div>
            <div class="flex h-48 items-end gap-2 border-b border-border pb-2">
                @php $max = max($trenPegawai) > 0 ? max($trenPegawai) : 1; @endphp
                @foreach($trenPegawai as $bulan => $jumlah)
                    @php $height = round(($jumlah / $max) * 100); @endphp
                    <div class="group relative flex flex-1 flex-col justify-end items-center">
                        <div class="w-full bg-secondary/80 hover:bg-secondary transition-colors rounded-t-sm" style="height: {{ $height }}%"></div>
                        <div class="absolute bottom-full mb-2 hidden group-hover:block rounded bg-ink px-2 py-1 text-xs text-white">
                            {{ $jumlah }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between mt-2 px-1">
                @foreach(array_keys($trenPegawai) as $bulan)
                    <span class="text-[10px] text-muted">{{ $bulan }}</span>
                @endforeach
            </div>
        </x-ui.card>

        {{-- W4: List EWS --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-ink">Top 5 Early Warning System</h2>
                <a href="{{ route('pimpinan.ews.index') }}" class="text-sm text-primary hover:underline font-medium">Lihat Semua</a>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th>Pegawai</x-ui.table-th>
                            <x-ui.table-th>Jenis Event</x-ui.table-th>
                            <x-ui.table-th>Sisa Hari</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @foreach($ewsAktif as $ews)
                            @php
                                $variant = match($ews['indikator']) {
                                    'merah' => 'danger',
                                    'kuning' => 'warning',
                                    'hijau' => 'info',
                                    default => 'muted'
                                };
                            @endphp
                            <x-ui.table-row>
                                <x-ui.table-td>
                                    <p class="font-medium text-ink">{{ $ews['nama'] }}</p>
                                    <p class="text-xs text-muted">{{ $ews['nip'] }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td>{{ $ews['jenis'] }}</x-ui.table-td>
                                <x-ui.table-td>
                                    <x-ui.badge :variant="$variant" pill>{{ $ews['sisa_hari'] }} Hari</x-ui.badge>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>

        {{-- W6: Audit Terbaru --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-ink">Aktivitas Sistem Terbaru</h2>
                <x-ui.badge variant="muted">Read-Only</x-ui.badge>
            </div>
            <div class="space-y-4">
                @foreach($auditTerbaru as $audit)
                    <div class="flex items-start gap-3">
                        <div class="mt-1 h-2 w-2 rounded-full bg-primary shrink-0"></div>
                        <div>
                            <p class="text-sm font-medium text-ink">{{ $audit['aksi'] }}</p>
                            <div class="flex items-center gap-2 mt-0.5 text-xs text-muted">
                                <span>{{ $audit['user'] }}</span>
                                <span>&bull;</span>
                                <span>{{ $audit['waktu'] }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

    </div>
</div>
</x-layouts.app>
