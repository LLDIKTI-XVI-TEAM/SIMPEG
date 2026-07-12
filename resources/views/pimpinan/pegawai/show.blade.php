<x-layouts.app title="Detail Pegawai">
    @php
        $displayName = $employee->nama_dengan_gelar ?: $employee->nama_lengkap;
        $position = $employee->positionHistories->first();
        $status = $employee->statusPegawai?->nama ?? $employee->status_aktif ?? 'Belum tersedia';
        $statusVariant = match (strtolower($status)) {
            'aktif' => 'success',
            'mutasi' => 'warning',
            'tugas belajar', 'tugas-belajar' => 'info',
            'non-aktif', 'nonaktif', 'pensiun' => 'danger',
            default => 'muted',
        };
        $tabs = [
            'profil' => 'Profil & Kontak',
            'keluarga' => 'Data Keluarga',
            'supervisor' => 'Kepala Bagian/Supervisor',
            'kepangkatan' => 'Riwayat Kepangkatan',
            'jabatan' => 'Riwayat Jabatan',
            'kgb' => 'Riwayat KGB',
            'pendidikan' => 'Pendidikan',
            'disiplin' => 'Hukuman Disiplin',
            'dokumen' => 'Dokumen & SK',
            'pengangkatan' => 'Data Pengangkatan',
            'info' => 'Info Otomatis',
        ];
        $activeSupervisor = $employee->supervisorAssignments->first();
    @endphp

    <div class="space-y-6" x-data="{ activeTab: 'profil' }">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Detail Pegawai</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Data Pegawai', 'url' => route('pimpinan.pegawai.index')],
                ['label' => $displayName],
            ]" />
        </div>

        <x-ui.card>
            <div class="flex flex-col gap-5 md:flex-row md:items-start">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-primary/10 text-3xl font-bold text-primary" aria-hidden="true">{{ strtoupper(mb_substr($employee->nama_lengkap, 0, 1)) }}</div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-2xl font-semibold text-ink">{{ $displayName }}</h2>
                    <p class="font-mono text-sm text-primary">{{ $employee->nip }}</p>
                    <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs text-muted">Jabatan</dt><dd class="font-medium text-ink">{{ $position?->jabatan?->nama ?? $position?->nama_jabatan ?? $employee->jabatan_terakhir ?? '-' }}</dd></div>
                        <div><dt class="text-xs text-muted">Unit Kerja</dt><dd class="font-medium text-ink">{{ $position?->unitKerja?->nama ?? '-' }}</dd></div>
                        <div><dt class="text-xs text-muted">Status</dt><dd><x-ui.badge :variant="$statusVariant" size="sm" dot>{{ $status }}</x-ui.badge></dd></div>
                    </dl>
                </div>
                <p id="history-export-unavailable" class="max-w-xs rounded-lg bg-soft p-3 text-sm text-muted">Cetak riwayat belum tersedia dari halaman detail. Gunakan laporan kepangkatan yang telah disediakan.</p>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
            <nav class="lg:col-span-1" role="tablist" aria-label="Navigasi detail pegawai" aria-orientation="vertical">
                <div class="space-y-1">
                    @foreach ($tabs as $key => $label)
                        <button id="pimpinan-tab-{{ $key }}" type="button" role="tab" aria-controls="pimpinan-panel-{{ $key }}" x-bind:aria-selected="activeTab === '{{ $key }}'" x-bind:tabindex="activeTab === '{{ $key }}' ? 0 : -1" @click="activeTab = '{{ $key }}'" @keydown.down.prevent="($el.nextElementSibling ?? $el.parentElement.firstElementChild).click(); ($el.nextElementSibling ?? $el.parentElement.firstElementChild).focus()" @keydown.up.prevent="($el.previousElementSibling ?? $el.parentElement.lastElementChild).click(); ($el.previousElementSibling ?? $el.parentElement.lastElementChild).focus()" @keydown.home.prevent="$el.parentElement.firstElementChild.click(); $el.parentElement.firstElementChild.focus()" @keydown.end.prevent="$el.parentElement.lastElementChild.click(); $el.parentElement.lastElementChild.focus()" x-bind:class="activeTab === '{{ $key }}' ? 'bg-primary text-white font-semibold' : 'text-muted hover:bg-soft hover:text-ink font-medium'" class="w-full rounded-lg px-4 py-3 text-left text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-primary/30">{{ $label }}</button>
                    @endforeach
                </div>
            </nav>

            <div class="space-y-6 lg:col-span-3">
                <section id="pimpinan-panel-profil" role="tabpanel" aria-labelledby="pimpinan-tab-profil" x-show="activeTab === 'profil'" x-cloak class="space-y-6">
                    <x-ui.card>
                        <h2 class="mb-4 border-b border-border pb-2 text-lg font-semibold text-ink">Informasi Dasar</h2>
                        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs text-muted">Tempat, Tanggal Lahir</dt><dd class="font-medium text-ink">{{ $employee->tempat_lahir ?: '-' }}, {{ $employee->tanggal_lahir?->translatedFormat('d M Y') ?? '-' }}</dd></div>
                            <div><dt class="text-xs text-muted">Jenis Kelamin</dt><dd class="font-medium text-ink">{{ $employee->jenis_kelamin === 'L' ? 'Laki-laki' : ($employee->jenis_kelamin === 'P' ? 'Perempuan' : '-') }}</dd></div>
                            <div><dt class="text-xs text-muted">Agama</dt><dd class="font-medium text-ink">{{ $employee->agama?->nama ?? '-' }}</dd></div>
                            <div><dt class="text-xs text-muted">Pendidikan Terakhir</dt><dd class="font-medium text-ink">{{ collect([$employee->pendidikan_terakhir, $employee->prodi_pendidikan_terakhir])->filter()->join(' ') ?: '-' }}</dd></div>
                        </dl>
                    </x-ui.card>
                    <x-ui.card>
                        <h2 class="mb-4 border-b border-border pb-2 text-lg font-semibold text-ink">Kontak</h2>
                        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs text-muted">NIK</dt><dd class="font-mono font-medium text-ink">Tersamarkan</dd></div>
                            <div><dt class="text-xs text-muted">Telepon/No. HP</dt><dd class="font-medium text-ink">{{ $employee->no_hp ?: '-' }}</dd></div>
                            <div><dt class="text-xs text-muted">Email</dt><dd class="font-medium text-ink">{{ $employee->email ?: '-' }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-xs text-muted">Alamat</dt><dd class="font-medium text-ink">{{ $employee->alamat ?: '-' }}</dd></div>
                        </dl>
                    </x-ui.card>
                </section>

                <section id="pimpinan-panel-keluarga" role="tabpanel" aria-labelledby="pimpinan-tab-keluarga" x-show="activeTab === 'keluarga'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-lg font-semibold text-ink">Data Keluarga</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <caption class="sr-only">Data keluarga pegawai</caption>
                                <thead class="bg-soft">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Nama</th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Hubungan</th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Tempat, Tanggal Lahir</th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Jenis Kelamin</th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Status Tunjangan</th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Pekerjaan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($employee->families as $family)
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium text-ink">{{ $family->nama_anggota }}</td>
                                            <td class="px-4 py-3 text-sm text-ink">{{ $family->hubungan }}</td>
                                            <td class="px-4 py-3 text-sm text-ink">{{ $family->tempat_lahir ?: '-' }}, {{ $family->tanggal_lahir?->translatedFormat('d M Y') ?? '-' }}</td>
                                            <td class="px-4 py-3 text-sm text-ink">{{ $family->jenis_kelamin === 'L' ? 'Laki-laki' : ($family->jenis_kelamin === 'P' ? 'Perempuan' : '-') }}</td>
                                            <td class="px-4 py-3 text-sm text-ink">{{ $family->status_tunjangan ? 'Ditanggung' : 'Tidak ditanggung' }}</td>
                                            <td class="px-4 py-3 text-sm text-ink">{{ $family->pekerjaan ?: '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-muted">Belum ada data keluarga.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                </section>

                <section id="pimpinan-panel-supervisor" role="tabpanel" aria-labelledby="pimpinan-tab-supervisor" x-show="activeTab === 'supervisor'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-lg font-semibold text-ink">Kepala Bagian/Supervisor Aktif</h2>
                        @if ($activeSupervisor?->supervisor)
                            @php($supervisorPosition = $activeSupervisor->supervisor->positionHistories->first())
                            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                                <div><dt class="text-xs text-muted">Nama</dt><dd class="font-medium text-ink">{{ $activeSupervisor->supervisor->nama_lengkap }}</dd></div>
                                <div><dt class="text-xs text-muted">Jabatan</dt><dd class="font-medium text-ink">{{ $supervisorPosition?->nama_jabatan ?? $activeSupervisor->supervisor->jabatan_terakhir ?? '-' }}</dd></div>
                                <div><dt class="text-xs text-muted">Unit Kerja</dt><dd class="font-medium text-ink">{{ $supervisorPosition?->unitKerja?->nama ?? '-' }}</dd></div>
                                <div><dt class="text-xs text-muted">Mulai Penugasan</dt><dd class="font-medium text-ink">{{ $activeSupervisor->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}</dd></div>
                            </dl>
                        @else
                            <p class="rounded-lg bg-soft p-4 text-sm text-muted">Supervisor aktif belum ditetapkan.</p>
                        @endif
                    </x-ui.card>
                </section>

                <section id="pimpinan-panel-kepangkatan" role="tabpanel" aria-labelledby="pimpinan-tab-kepangkatan" x-show="activeTab === 'kepangkatan'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Riwayat Kepangkatan</h2><div class="overflow-x-auto"><table class="w-full"><caption class="sr-only">Riwayat kepangkatan pegawai</caption><thead class="bg-soft"><tr><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Golongan</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">TMT</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">No. SK</th></tr></thead><tbody class="divide-y divide-border">@forelse ($employee->rankHistories as $history)<tr><td class="px-4 py-3 text-sm text-ink">{{ $history->golongan?->nama ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->tmt_pangkat?->translatedFormat('d M Y') ?? '-' }}</td><td class="px-4 py-3 font-mono text-sm text-ink">{{ $history->no_sk ?: '-' }}</td></tr>@empty<tr><td colspan="3" class="px-4 py-8 text-center text-sm text-muted">Belum ada riwayat kepangkatan.</td></tr>@endforelse</tbody></table></div></x-ui.card></section>

                <section id="pimpinan-panel-jabatan" role="tabpanel" aria-labelledby="pimpinan-tab-jabatan" x-show="activeTab === 'jabatan'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Riwayat Jabatan</h2><div class="overflow-x-auto"><table class="w-full"><caption class="sr-only">Riwayat jabatan pegawai</caption><thead class="bg-soft"><tr><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Jabatan</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Unit Kerja</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">TMT</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Kelas</th></tr></thead><tbody class="divide-y divide-border">@forelse ($employee->positionHistories as $history)<tr><td class="px-4 py-3 text-sm text-ink">{{ $history->jabatan?->nama ?? $history->nama_jabatan ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->unitKerja?->nama ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->tmt_jabatan?->translatedFormat('d M Y') ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->kelas_jabatan ?: '-' }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-sm text-muted">Belum ada riwayat jabatan.</td></tr>@endforelse</tbody></table></div></x-ui.card></section>

                <section id="pimpinan-panel-kgb" role="tabpanel" aria-labelledby="pimpinan-tab-kgb" x-show="activeTab === 'kgb'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Kenaikan Gaji Berkala</h2><div class="overflow-x-auto"><table class="w-full"><caption class="sr-only">Riwayat kenaikan gaji berkala pegawai</caption><thead class="bg-soft"><tr><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">TMT KGB</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Gaji Pokok</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">No. SK</th></tr></thead><tbody class="divide-y divide-border">@forelse ($employee->salaryHistories as $history)<tr><td class="px-4 py-3 text-sm text-ink">{{ $history->tmt_kgb?->translatedFormat('d M Y') ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">Rp {{ number_format((float) $history->gaji_pokok, 0, ',', '.') }}</td><td class="px-4 py-3 font-mono text-sm text-ink">{{ $history->no_sk ?: '-' }}</td></tr>@empty<tr><td colspan="3" class="px-4 py-8 text-center text-sm text-muted">Belum ada riwayat KGB.</td></tr>@endforelse</tbody></table></div></x-ui.card></section>

                <section id="pimpinan-panel-pendidikan" role="tabpanel" aria-labelledby="pimpinan-tab-pendidikan" x-show="activeTab === 'pendidikan'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Riwayat Pendidikan</h2><div class="overflow-x-auto"><table class="w-full"><caption class="sr-only">Riwayat pendidikan pegawai</caption><thead class="bg-soft"><tr><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Tingkat</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Jurusan</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Institusi</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Tahun</th></tr></thead><tbody class="divide-y divide-border">@forelse ($employee->educationHistories as $history)<tr><td class="px-4 py-3 text-sm text-ink">{{ $history->jenjang?->nama ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->jurusan ?: '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->nama_institusi ?: '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $history->tahun_lulus ?: '-' }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-sm text-muted">Belum ada riwayat pendidikan.</td></tr>@endforelse</tbody></table></div></x-ui.card></section>

                <section id="pimpinan-panel-disiplin" role="tabpanel" aria-labelledby="pimpinan-tab-disiplin" x-show="activeTab === 'disiplin'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Hukuman Disiplin</h2><div class="overflow-x-auto"><table class="w-full"><caption class="sr-only">Riwayat hukuman disiplin pegawai</caption><thead class="bg-soft"><tr><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Tanggal</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Jenis</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Keterangan</th><th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase text-muted">Status</th></tr></thead><tbody class="divide-y divide-border">@forelse ($employee->disciplineRecords as $record)<tr><td class="px-4 py-3 text-sm text-ink">{{ $record->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}</td><td class="px-4 py-3 text-sm text-ink">{{ $record->jenis_hukuman }}</td><td class="px-4 py-3 text-sm text-ink">{{ $record->deskripsi }}</td><td class="px-4 py-3"><x-ui.badge :variant="$record->is_active ? 'danger' : 'success'" size="sm" dot>{{ $record->is_active ? 'Aktif' : 'Selesai' }}</x-ui.badge></td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-sm text-muted">Tidak ada hukuman disiplin.</td></tr>@endforelse</tbody></table></div></x-ui.card></section>

                <section id="pimpinan-panel-dokumen" role="tabpanel" aria-labelledby="pimpinan-tab-dokumen" x-show="activeTab === 'dokumen'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Dokumen & SK</h2><div class="grid grid-cols-1 gap-4 sm:grid-cols-2">@forelse ($employee->documents as $document)<article class="rounded-lg border border-border p-4"><p class="font-medium text-ink">{{ $document->nama_dokumen }}</p><p class="mt-1 text-sm text-muted">{{ $document->jenis_dokumen }} · {{ $document->tanggal_dokumen?->translatedFormat('d M Y') ?? 'Tanggal tidak tersedia' }}</p><p class="mt-2 text-xs text-muted">{{ $document->fileStatusLabel() }}</p></article>@empty<p class="text-sm text-muted">Belum ada dokumen tercatat.</p>@endforelse</div></x-ui.card></section>

                <section id="pimpinan-panel-pengangkatan" role="tabpanel" aria-labelledby="pimpinan-tab-pengangkatan" x-show="activeTab === 'pengangkatan'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Data Pengangkatan</h2><dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2"><div><dt class="text-xs text-muted">Jenis Pengangkatan</dt><dd class="font-medium text-ink">{{ $employee->appointment?->jenis_pengangkatan ?? $employee->jenisPegawai?->nama ?? '-' }}</dd></div><div><dt class="text-xs text-muted">TMT Pengangkatan</dt><dd class="font-medium text-ink">{{ $employee->appointment?->tmt_pengangkatan?->translatedFormat('d M Y') ?? '-' }}</dd></div><div><dt class="text-xs text-muted">No. SK</dt><dd class="font-mono font-medium text-ink">{{ $employee->appointment?->no_sk ?? '-' }}</dd></div><div><dt class="text-xs text-muted">Tanggal SK</dt><dd class="font-medium text-ink">{{ $employee->appointment?->tanggal_sk?->translatedFormat('d M Y') ?? '-' }}</dd></div></dl></x-ui.card></section>

                <section id="pimpinan-panel-info" role="tabpanel" aria-labelledby="pimpinan-tab-info" x-show="activeTab === 'info'" x-cloak><x-ui.card><h2 class="mb-4 text-lg font-semibold text-ink">Info Otomatis</h2><div class="grid grid-cols-1 gap-4 sm:grid-cols-3"><div class="rounded-lg border border-border p-4"><p class="text-xs font-medium uppercase tracking-wide text-muted">KGB Berikutnya</p><p class="mt-2 font-semibold text-ink">{{ $employee->tanggal_kgb_berikutnya?->translatedFormat('d M Y') ?? 'Belum tersedia' }}</p></div><div class="rounded-lg border border-border p-4"><p class="text-xs font-medium uppercase tracking-wide text-muted">Kenaikan Pangkat</p><p class="mt-2 font-semibold text-ink">{{ $employee->tanggal_kenaikan_pangkat_berikutnya?->translatedFormat('d M Y') ?? 'Belum tersedia' }}</p></div><div class="rounded-lg border border-border border-danger/20 bg-danger/5 p-4"><p class="text-xs font-medium uppercase tracking-wide text-danger">Pensiun</p><p class="mt-2 font-semibold text-danger">{{ $employee->tanggal_pensiun?->translatedFormat('d M Y') ?? 'Belum tersedia' }}</p></div></div></x-ui.card></section>
            </div>
        </div>
    </div>
</x-layouts.app>
