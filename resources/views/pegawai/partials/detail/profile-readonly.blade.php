<div class="space-y-6">
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
                    <dd class="text-ink font-sans">@include('pegawai.partials.detail.sensitive-value')</dd>
                </div>
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">Nomor KK</dt>
                    <dd class="text-ink font-sans">@include('pegawai.partials.detail.sensitive-value')</dd>
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
                    <dd class="text-ink font-sans">@include('pegawai.partials.detail.date', ['value' => $employee->tanggal_pensiun])</dd>
                </div>
            </dl>
        </div>

        <div class="space-y-4 rounded-lg border border-border bg-soft/30 p-4 md:col-span-2">
            <h3 class="border-b border-border pb-1.5 text-xs font-bold uppercase tracking-wider text-ink font-sans">
                Kontak &amp; Rumah
            </h3>
            <dl class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4">
                @foreach([
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
        <dl class="grid grid-cols-2 gap-4 text-xs sm:grid-cols-3 lg:grid-cols-6">
            @foreach([
                'Jabatan' => $activePosition?->jabatan?->nama ?? $activePosition?->nama_jabatan ?? $employee->jabatan_terakhir,
                'Unit Kerja' => $activePosition?->unitKerja?->nama,
                'Golongan Saat Ini' => $employee->golongan_terakhir,
                'Kelas Jabatan' => $employee->kelas_jabatan_terakhir,
                'Pendidikan Terakhir' => $employee->pendidikan_terakhir,
                'Program Studi' => $employee->prodi_pendidikan_terakhir,
            ] as $label => $value)
                <div class="space-y-0.5">
                    <dt class="font-semibold text-muted font-sans">{{ $label }}</dt>
                    <dd class="font-bold text-ink font-sans">{{ $value ?: '-' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>
