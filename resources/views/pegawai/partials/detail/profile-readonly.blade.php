@php
    $remainingRetirement = null;
    if ($retirementDate) {
        $retirementMoment = \Illuminate\Support\Carbon::parse($retirementDate);
        if ($retirementMoment->isFuture()) {
            $difference = now()->diff($retirementMoment);
            $remainingRetirement = $difference->y.' Tahun, '.$difference->m.' Bulan lagi';
        } else {
            $remainingRetirement = 'Memasuki Usia Pensiun';
        }
    }
@endphp

<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 rounded-lg border border-border bg-soft/40 p-4 md:grid-cols-2">
        <div class="space-y-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Status Kinerja</h3>
            <p class="text-xs text-muted font-sans">Flag kinerja tampil sebagai informasi read-only.</p>
            <p class="font-bold text-ink font-sans">{{ $employee->is_kinerja_baik ? 'Kinerja Baik' : 'Kinerja Tidak Baik' }}</p>
        </div>
        <div class="space-y-2 border-border md:border-l md:pl-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Kelayakan Satyalancana</h3>
            <p class="font-bold text-ink font-sans">{{ $employee->is_satyalancana_eligible ? 'Layak' : 'Tidak Layak' }}</p>
            <p class="text-xs text-muted font-sans">{{ $employee->satyalancana_note ?: 'Tidak ada catatan manual.' }}</p>
        </div>
    </div>

    <div class="space-y-3 rounded-lg border border-border bg-soft/40 p-4">
        <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
            Kepala Bagian/Supervisor Aktif
        </h3>

        @forelse($activeSupervisorAssignments as $assignment)
            @if($assignment->supervisor)
                <div class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-3">
                    <div class="space-y-0.5">
                        <span class="font-semibold text-muted font-sans">Nama Kepala Bagian</span>
                        <p class="font-bold text-ink font-sans">{{ $assignment->supervisor->nama_lengkap }}</p>
                        <p class="text-muted font-sans">NIP. {{ $assignment->supervisor->nip ?? '-' }}</p>
                    </div>
                    <div class="space-y-0.5">
                        <span class="font-semibold text-muted font-sans">Jabatan / Unit Kerja</span>
                        <p class="text-ink font-sans">{{ $assignment->supervisor->jabatan_terakhir ?? '-' }}</p>
                        <p class="text-muted font-sans">{{ $assignment->supervisor->positionHistories->first()?->unitKerja?->nama ?? '-' }}</p>
                    </div>
                    <div class="space-y-0.5">
                        <span class="font-semibold text-muted font-sans">Tanggal Mulai Penugasan</span>
                        <p class="text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $assignment->tanggal_mulai])</p>
                    </div>
                </div>
            @endif
        @empty
            <p class="text-xs font-semibold text-muted font-sans">Belum ada data supervisor aktif.</p>
        @endforelse
    </div>

    <div class="space-y-3">
        <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
            Estimasi Jadwal Kepegawaian
        </h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach([
                ['label' => 'Kenaikan Pangkat Terdekat', 'date' => $employee->tanggal_kenaikan_pangkat_berikutnya, 'note' => '(Estimasi 4 tahun sejak TMT)'],
                ['label' => 'KGB Terdekat', 'date' => $employee->tanggal_kgb_berikutnya, 'note' => '(Estimasi 2 tahun sejak TMT)'],
                ['label' => 'Estimasi Tanggal Pensiun', 'date' => $retirementDate, 'note' => $remainingRetirement ? 'Sisa: '.$remainingRetirement : null],
            ] as $schedule)
                <div class="rounded-lg border border-border bg-surface p-3 text-center shadow-sm">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-muted font-sans">{{ $schedule['label'] }}</span>
                    <p class="mt-1 text-sm font-bold text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $schedule['date']])</p>
                    @if($schedule['note'])
                        <p class="mt-0.5 text-[9px] {{ $schedule['label'] === 'Estimasi Tanggal Pensiun' ? 'font-semibold text-danger' : 'text-muted' }} font-sans">{{ $schedule['note'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="space-y-4 rounded-lg border border-border bg-soft/30 p-4">
            <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
                Identitas &amp; Data Pribadi
            </h3>
            <dl class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
                @foreach([
                    'Nama Lengkap' => $employee->nama_lengkap,
                    'Nama dengan Gelar' => $employee->nama_dengan_gelar,
                    'NIP' => $employee->nip,
                    'Tempat Lahir' => $employee->tempat_lahir,
                    'Jenis Kelamin' => $employee->jenis_kelamin === 'L' ? 'Laki-laki' : ($employee->jenis_kelamin === 'P' ? 'Perempuan' : '-'),
                    'Agama' => $employee->agama?->nama,
                    'Status Kawin' => $employee->statusKawin?->nama,
                    'Golongan Darah' => $employee->golongan_darah,
                    'Jenis Pegawai' => $employee->jenisPegawai?->nama,
                    'Kepala Lembaga' => $employee->is_kepala_lembaga ? 'Ya' : 'Tidak',
                ] as $label => $value)
                    <div class="space-y-0.5">
                        <dt class="font-semibold text-muted font-sans">{{ $label }}</dt>
                        <dd class="text-ink font-sans">{{ $value ?: '-' }}</dd>
                    </div>
                @endforeach
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">Tanggal Lahir</dt>
                    <dd class="text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $employee->tanggal_lahir])</dd>
                </div>
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">NIK</dt>
                    <dd class="text-ink font-sans">
                        @if($maskSensitive)
                            @include('pegawai.partials.detail.sensitive-value')
                        @else
                            {{ $employee->nik ?: '-' }}
                        @endif
                    </dd>
                </div>
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">Nomor KK</dt>
                    <dd class="text-ink font-sans">
                        @if($maskSensitive)
                            @include('pegawai.partials.detail.sensitive-value')
                        @else
                            {{ $employee->no_kk ?: '-' }}
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <div class="space-y-4 rounded-lg border border-border bg-soft/30 p-4">
            <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
                Status Kepegawaian
            </h3>
            <dl class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">Status Saat Ini</dt>
                    <dd>
                        <span class="inline-flex items-center rounded-md px-2 py-1 font-bold {{ $statusPresentation['badge'] }}">
                            {{ $statusPresentation['label'] }}
                        </span>
                    </dd>
                </div>
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">Tanggal Efektif Status Kepegawaian</dt>
                    <dd class="text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $statusPresentation['effectiveDate']])</dd>
                </div>
                <div class="space-y-0.5 sm:col-span-2">
                    <dt class="font-semibold text-muted font-sans">Keterangan Status</dt>
                    <dd class="text-ink font-sans">{{ $employee->status_keterangan ?: '-' }}</dd>
                </div>
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">Tanggal Pensiun</dt>
                    <dd class="text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $retirementDate])</dd>
                </div>
            </dl>
        </div>

        <div class="space-y-4 rounded-lg border border-border bg-soft/30 p-4 md:col-span-2">
            <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
                Kontak &amp; Rumah
            </h3>
            <dl class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4">
                @foreach([
                    'Email Dinas' => $employee->getRawOriginal('email'),
                    'Email' => $employee->email_pribadi,
                    'Nomor HP' => $employee->no_hp,
                    'Telepon Rumah' => $employee->no_telepon_rumah,
                    'Alamat' => $employee->alamat,
                ] as $label => $value)
                    <div class="space-y-0.5">
                        <dt class="font-semibold text-muted font-sans">{{ $label }}</dt>
                        <dd class="break-words text-ink font-sans">{{ $value ?: '-' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="space-y-4 rounded-lg border border-border bg-soft/30 p-4">
        <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
            Informasi Pekerjaan Utama
        </h3>
        <dl class="grid grid-cols-2 gap-4 text-xs sm:grid-cols-3 lg:grid-cols-7">
            @foreach([
                'Jabatan' => $activePosition?->jabatan?->nama ?? $activePosition?->nama_jabatan ?? $employee->jabatan_terakhir,
                'Unit Kerja' => $activePosition?->unitKerja?->nama,
                'Pangkat' => $latestRank?->golongan?->nama ?? $employee->pangkat_terakhir,
                'Golongan Saat Ini' => $latestRank?->golongan?->kode ?? $employee->golongan_terakhir,
                'Kelas Jabatan' => $employee->kelas_jabatan_terakhir,
                'Pendidikan Terakhir' => $employee->pendidikan_terakhir,
                'Program Studi' => $employee->prodi_pendidikan_terakhir,
            ] as $label => $value)
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">{{ $label }}</dt>
                    <dd class="font-bold text-ink font-sans">{{ $value ?: '-' }}</dd>
                </div>
            @endforeach
            <div class="space-y-0.5">
                <dt class="font-semibold text-muted font-sans">TMT Golongan</dt>
                <dd class="font-bold text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $latestRank?->tmt_pangkat])</dd>
            </div>
        </dl>
    </div>

    <div class="space-y-4 rounded-lg border border-border bg-soft/30 p-4">
        <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
            Riwayat Perubahan Status Kepegawaian
        </h3>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @forelse($employee->statusHistories as $history)
                <article class="space-y-2 rounded-lg border {{ $history->is_latest ? 'border-primary bg-primary/5' : 'border-border bg-surface' }} p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-bold text-ink font-sans">{{ $history->status_nama }}</p>
                        @if($history->is_latest)
                            <x-ui.badge variant="primary" size="sm">Aktif</x-ui.badge>
                        @endif
                    </div>
                    <p class="text-xs text-muted font-sans">@include('pegawai.partials.detail.date', ['value' => $history->tanggal_efektif])</p>
                    @if($history->keterangan)
                        <p class="text-xs text-ink font-sans">{{ $history->keterangan }}</p>
                    @endif
                    @php
                        $statusAttachmentUrl = $downloadSurface === 'admin'
                            ? (($history->file_sk || $history->has_legacy_status_document)
                                ? route('pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'status', 'history' => $history])
                                : null)
                            : $history->pimpinan_attachment_download_url;
                    @endphp
                    @if($statusAttachmentUrl)
                        <a
                            href="{{ $statusAttachmentUrl }}"
                            target="_blank"
                            class="inline-flex text-xs font-semibold text-primary hover:underline"
                        >
                            {{ $history->nomor_berkas ?: 'Lihat SK' }}
                        </a>
                    @endif
                </article>
            @empty
                @php
                    $statusSnapshotUrl = $downloadSurface === 'admin'
                        ? ($employee->status_berkas_path
                            ? route('pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'status-snapshot', 'history' => $employee])
                            : null)
                        : $employee->pimpinan_status_attachment_download_url;
                @endphp
                @if($statusSnapshotUrl)
                    <article class="space-y-2 rounded-lg border border-border bg-surface p-4">
                        <p class="text-sm font-bold text-ink font-sans">Snapshot Status Pegawai</p>
                        <a
                            href="{{ $statusSnapshotUrl }}"
                            target="_blank"
                            class="inline-flex text-xs font-semibold text-primary hover:underline"
                        >
                            {{ $employee->status_nomor_berkas ?: 'Lihat SK' }}
                        </a>
                    </article>
                @else
                    <p class="text-xs font-semibold text-muted font-sans">Belum ada riwayat perubahan status kepegawaian.</p>
                @endif
            @endforelse
        </div>
    </div>
</div>
