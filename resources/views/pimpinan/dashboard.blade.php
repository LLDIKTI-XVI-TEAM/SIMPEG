<x-layouts.app title="Dashboard Pimpinan" subtitle="Ringkasan data kepegawaian terkini.">
    @php
        $employeeTotal = max($totalPegawai, 1);
    @endphp

    <div class="space-y-6">
        <section aria-labelledby="dashboard-heading" class="rounded-xl bg-primary px-5 py-5 text-white shadow-md">
            <p class="text-xs font-semibold uppercase tracking-wide text-white/80">Dashboard Pimpinan</p>
            <h2 id="dashboard-heading" class="mt-1 text-2xl font-semibold">{{ auth()->user()->name }}</h2>
            <p class="mt-2 text-sm text-white/90">Ringkasan ini diperbarui dari data SIMPEG saat halaman dibuka.</p>
        </section>

        <section aria-label="Ringkasan utama" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Total Pegawai Aktif" value="{{ $totalPegawai }}" variant="primary" size="lg">
                <x-slot:meta><span>{{ ($komposisi['PNS'] ?? 0) }} PNS · {{ ($komposisi['PPPK'] ?? 0) }} PPPK</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('pimpinan.ews.index', ['event' => 'Kenaikan Pangkat']) }}" label="Kenaikan Pangkat" value="{{ $naikPangkatBulanIni }}" variant="success" size="lg">
                <x-slot:meta><span>{{ $naikPangkatBulanIni }} bulan ini · {{ $naikPangkatTahunIni }} tahun ini</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu']) }}" label="Pengajuan Cuti Menunggu" value="{{ $cutiPending }}" variant="warning" size="lg">
                <x-slot:meta><span>{{ $cutiDisetujuiBulanIni }} disetujui bulan ini · {{ $cutiDitunda }} ditangguhkan</span></x-slot:meta>
            </x-ui.stat-card>
            <x-ui.stat-card href="{{ route('pimpinan.ews.index') }}" label="EWS Aktif" value="{{ count($ewsAktif) }}" variant="danger" size="lg">
                <x-slot:meta><span>5 peringatan paling mendesak ditampilkan di bawah</span></x-slot:meta>
            </x-ui.stat-card>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-1">
                <h3 class="text-lg font-semibold text-ink">Komposisi Kepegawaian</h3>
                <p class="mt-1 text-sm text-muted">Jumlah pegawai aktif menurut jenis pegawai.</p>
                <dl class="mt-4 space-y-3">
                    @forelse($komposisi as $type => $count)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-ink">{{ $type }}</dt>
                            <dd class="font-semibold text-ink">{{ $count }} <span class="text-muted">({{ round($count / $employeeTotal * 100) }}%)</span></dd>
                        </div>
                    @empty
                        <p class="text-sm text-muted">Belum ada data pegawai aktif.</p>
                    @endforelse
                </dl>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
                <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-ink">Kenaikan Pangkat Bulan Ini</h3>
                        <p class="mt-1 text-sm text-muted">Riwayat kepangkatan dengan TMT pada bulan berjalan.</p>
                    </div>
                    <a href="{{ route('pimpinan.laporan.kepangkatan') }}" class="text-sm font-semibold text-primary hover:underline">Buka laporan</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Riwayat kenaikan pangkat pada bulan berjalan</caption>
                        <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                            <tr>
                                <th scope="col" class="px-5 py-3">Pegawai</th>
                                <th scope="col" class="px-5 py-3">Golongan</th>
                                <th scope="col" class="px-5 py-3">TMT</th>
                                <th scope="col" class="px-5 py-3">Nomor SK</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse($promotionRows as $row)
                                <tr>
                                    <td class="px-5 py-3"><span class="font-semibold text-ink">{{ $row['nama'] }}</span><span class="mt-1 block font-mono text-xs text-muted">{{ $row['nip'] }}</span></td>
                                    <td class="px-5 py-3 text-ink">{{ $row['golongan'] }}</td>
                                    <td class="px-5 py-3 text-ink">{{ \Carbon\Carbon::parse($row['tmt'])->translatedFormat('d M Y') }}</td>
                                    <td class="px-5 py-3 text-ink">{{ $row['no_sk'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-8 text-center text-muted">Tidak ada riwayat kenaikan pangkat pada bulan ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card padding="none" class="overflow-hidden lg:col-span-2">
                <div class="border-b border-border px-5 py-4">
                    <h3 class="text-lg font-semibold text-ink">Aktivitas Terkini</h3>
                    <p class="mt-1 text-sm text-muted">Lima audit log terbaru yang tersedia untuk pemantauan.</p>
                </div>
                <ul class="divide-y divide-border" aria-label="Aktivitas audit terbaru">
                    @forelse($auditTerbaru as $audit)
                        <li class="px-5 py-4">
                            <p class="text-sm text-ink"><span class="font-semibold">{{ $audit['user'] }}</span> · {{ $audit['aksi'] }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $audit['target'] }} · {{ $audit['waktu'] }}</p>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Belum ada aktivitas yang tercatat.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-ink">Cuti Menunggu Tindakan</h3>
                        <p class="mt-1 text-sm text-muted">Hanya pengajuan pada tahap Pimpinan saat ini.</p>
                    </div>
                    <span class="text-sm font-semibold text-ink">{{ $pendingLeaves->count() }}</span>
                </div>
                <ul class="divide-y divide-border" aria-label="Pengajuan cuti menunggu tindakan">
                    @forelse($pendingLeaves as $leave)
                        <li class="px-5 py-4">
                            <a href="{{ route('pimpinan.cuti.show', $leave['id']) }}" class="block rounded focus:outline-none focus:ring-2 focus:ring-primary">
                                <span class="block font-semibold text-ink">{{ $leave['nama'] }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ $leave['jenis'] }} · {{ $leave['hari'] }} hari kerja · {{ \Carbon\Carbon::parse($leave['mulai'])->translatedFormat('d M') }}–{{ \Carbon\Carbon::parse($leave['selesai'])->translatedFormat('d M Y') }}</span>
                                <span class="mt-2 inline-block text-xs font-semibold text-primary">Buka detail untuk mengambil keputusan</span>
                            </a>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-muted">Tidak ada pengajuan cuti yang menunggu tindakan Anda.</li>
                    @endforelse
                </ul>
                <div class="border-t border-border bg-soft/30 px-5 py-3 text-center"><a href="{{ route('pimpinan.cuti.index', ['status' => 'menunggu']) }}" class="text-sm font-semibold text-primary hover:underline">Buka antrean persetujuan cuti</a></div>
            </x-ui.card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-1">
                <h3 class="text-lg font-semibold text-ink">Distribusi Golongan</h3>
                <p class="mt-1 text-sm text-muted">Jumlah pegawai aktif menurut kelompok golongan.</p>
                <dl class="mt-4 space-y-3">
                    @forelse($distribusiGolongan as $rank => $count)
                        <div class="flex items-center justify-between gap-4 text-sm"><dt class="text-ink">Golongan {{ $rank }}</dt><dd class="font-semibold text-ink">{{ $count }}</dd></div>
                    @empty
                        <p class="text-sm text-muted">Belum ada golongan yang tercatat.</p>
                    @endforelse
                </dl>
            </x-ui.card>
            <x-ui.card class="lg:col-span-2">
                <h3 class="text-lg font-semibold text-ink">Tren Pegawai Aktif</h3>
                <p class="mt-1 text-sm text-muted">Jumlah pegawai aktif berdasarkan data yang sudah tersimpan per akhir bulan.</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Tren pegawai aktif dua belas bulan terakhir</caption>
                        <thead class="bg-soft text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="px-4 py-3">Periode</th><th scope="col" class="px-4 py-3">Pegawai Aktif</th></tr></thead>
                        <tbody class="divide-y divide-border">
                            @foreach($trenPegawai as $trend)
                                <tr><td class="px-4 py-3 text-ink">{{ $trend['label'] }}</td><td class="px-4 py-3 font-semibold text-ink">{{ $trend['jumlah'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div><h3 class="text-lg font-semibold text-ink">Daftar EWS Aktif</h3><p class="mt-1 text-sm text-muted">Lima peringatan aktif dengan tanggal target terdekat.</p></div>
                <a href="{{ route('pimpinan.ews.index') }}" class="text-sm font-semibold text-primary hover:underline">Lihat semua EWS</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Lima peringatan EWS aktif paling mendesak</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="px-5 py-3">Pegawai</th><th scope="col" class="px-5 py-3">Pemicu</th><th scope="col" class="px-5 py-3">Tanggal Target</th><th scope="col" class="px-5 py-3">Sisa Hari</th><th scope="col" class="px-5 py-3">Status Tindak Lanjut</th></tr></thead>
                    <tbody class="divide-y divide-border">
                        @forelse($ewsAktif as $alert)
                            <tr>
                                <td class="px-5 py-3"><span class="font-semibold text-ink">{{ $alert['nama'] }}</span><span class="mt-1 block font-mono text-xs text-muted">{{ $alert['nip'] }}</span></td>
                                <td class="px-5 py-3 text-ink">{{ $alert['jenis_event'] }}</td>
                                <td class="px-5 py-3 text-ink">{{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</td>
                                <td class="px-5 py-3"><x-ui.badge :variant="$alert['urgency']" size="sm">{{ $alert['sisa_hari'] }} hari</x-ui.badge></td>
                                <td class="px-5 py-3"><x-ui.badge variant="muted" size="sm">{{ $alert['followup_status_label'] }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-8 text-center text-muted">Tidak ada peringatan EWS aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
