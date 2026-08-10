<x-layouts.app title="Detail Cuti">
    <div class="mx-auto max-w-7xl space-y-6">

        @php
            // Status runtime memakai token snake_case; label dipisahkan agar UI tidak bergantung pada status lama.
            $status = $cuti->status;
            $statusVariant = match ($status) {
                'disetujui' => 'success',
                'ditangguhkan', 'ditangguhkan_tugas_dinas' => 'warning',
                'dikembalikan_karena_rollover' => 'warning',
                'tidak_disetujui' => 'danger',
                'perlu_perubahan' => 'danger',
                default => 'warning',
            };
            $statusLabel = match ($status) {
                'menunggu_approval' => 'Menunggu Keputusan',
                'ditangguhkan' => 'Ditangguhkan',
                'ditangguhkan_tugas_dinas' => 'Ditangguhkan karena Tugas Dinas',
                'dikembalikan_karena_rollover' => 'Dikembalikan karena Rollover',
                'perlu_perubahan' => 'Perubahan',
                'disetujui' => 'Disetujui',
                'tidak_disetujui' => 'Tidak Disetujui',
                default => 'Status tidak tersedia',
            };

            $pemohon = $cuti->employee?->nama_lengkap ?? 'Pegawai';

            $buttonBase = 'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold font-sans transition-all duration-200 active:scale-95 focus:outline-none focus:ring-2';
            $buttonStyles = [
                'secondary' => $buttonBase.' border border-border bg-surface text-primary shadow-sm hover:bg-soft hover:border-primary/30 focus:ring-primary/30',
                'muted' => $buttonBase.' border border-border bg-surface text-ink shadow-sm hover:bg-soft focus:ring-primary/20',
                'danger' => $buttonBase.' border border-danger/20 bg-surface text-danger shadow-sm hover:bg-danger/5 focus:ring-danger/20',
                'success' => $buttonBase.' border border-success bg-success text-white shadow-sm hover:opacity-90 focus:ring-success/30',
                'warning' => $buttonBase.' border border-warning bg-warning text-white shadow-sm hover:opacity-90 focus:ring-warning/30',
            ];
        @endphp

        <x-admin.page-header title="Detail Pengajuan Cuti">
            <x-slot:breadcrumb>
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Pengajuan</span>
            </x-slot:breadcrumb>
        </x-admin.page-header>

        {{-- Detail Card --}}
        <x-ui.card padding="lg" class="space-y-6">

            {{-- Header info --}}
            <div class="border-b border-border pb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-bold text-primary">
                        {{ strtoupper(substr($pemohon, 0, 1)) }}
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-ink font-sans leading-tight">{{ $pemohon }}</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Pengajuan: {{ $cuti->created_at?->translatedFormat('d F Y') }}</p>
                    </div>
                </div>
                <div>
                    <x-ui.badge :variant="$statusVariant" size="md" dot>
                        {{ $statusLabel }}
                    </x-ui.badge>
                </div>
            </div>

            {{-- Metadata Grid --}}
            @if ($isRolloverReturn)
                <section class="rounded-lg border border-warning/25 bg-warning/5 p-4" aria-labelledby="rollover-return-title">
                    <h4 id="rollover-return-title" class="text-sm font-bold text-ink font-sans">Pengajuan Dikembalikan karena Rollover</h4>
                    <p class="mt-1 text-sm text-muted font-sans">Tanggal pengajuan tahun {{ $cuti->rollover_source_year }} sudah tidak dapat diproses. Perbaiki tanggal dan ajukan kembali pada tahun {{ $cuti->rollover_target_year }}.</p>
                    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Tahun Sumber</dt><dd class="mt-1 font-semibold text-ink">{{ $cuti->rollover_source_year }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Tahun Target</dt><dd class="mt-1 font-semibold text-ink">{{ $cuti->rollover_target_year }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Saldo Target Dapat Diajukan</dt><dd class="mt-1 font-semibold text-ink">{{ isset($targetBalance['saldo_dapat_diajukan']) ? $targetBalance['saldo_dapat_diajukan'].' hari' : 'Saldo target belum tersedia' }}</dd></div>
                    </dl>
                </section>
            @endif
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Jenis Cuti</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $cuti->jenisCuti?->nama ?? '-' }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Durasi Cuti</span>
                    <p class="text-sm font-bold text-ink">{{ $cuti->jumlah_hari_kerja }} Hari Kerja</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Mulai</span>
                    <p class="text-sm font-semibold text-ink">{{ $cuti->tanggal_mulai?->translatedFormat('d F Y') }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Selesai</span>
                    <p class="text-sm font-semibold text-ink">{{ $cuti->tanggal_selesai?->translatedFormat('d F Y') }}</p>
                </div>
                <div class="space-y-1 sm:col-span-2">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Alasan / Keterangan</span>
                    <p class="text-sm text-ink font-sans leading-relaxed bg-soft/50 rounded-lg p-3 border border-border">{{ $cuti->alasan }}</p>
                </div>
                @if ($cuti->lampiran_path)
                    <div class="space-y-1 sm:col-span-2">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Lampiran</span>
                        <a href="{{ asset('storage/' . $cuti->lampiran_path) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                            </svg>
                            Lihat lampiran
                        </a>
                    </div>
                @endif
                @if ($cuti->proof !== null)
                    <div class="space-y-1 sm:col-span-2">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Bukti Persetujuan Cuti</span>
                        <p class="text-sm text-muted font-sans">Buka bukti persetujuan untuk memeriksa status pengajuan.</p>
                        <a href="{{ route('cuti.verify', ['token' => $cuti->proof->token]) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
                            Lihat bukti persetujuan
                        </a>
                    </div>
                @endif
            </div>

            {{-- Approval Flow Status --}}
            <div class="border-t border-border pt-6 space-y-4">
                <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alur Persetujuan Cuti</h4>

                <x-ui.timeline>
                    <x-ui.timeline-item
                        title="Diajukan oleh Pegawai"
                        :description="$cuti->created_at?->translatedFormat('d M Y, H:i')"
                    />

                    @foreach ($cuti->steps->sortBy('step_order') as $step)
                        @php
                            $stepVariant = match ($step->status) {
                                'approved' => 'success',
                                'tidak_disetujui' => 'danger',
                                'ditangguhkan_tugas_dinas' => 'warning',
                                'active' => $status === 'ditangguhkan' ? 'warning' : 'warning',
                                'skipped' => 'muted',
                                default => 'muted',
                            };
                            $stepTitle = match ($step->status) {
                                'approved' => "Disetujui oleh {$step->role_label}",
                                'tidak_disetujui' => "Tidak Disetujui oleh {$step->role_label}",
                                 'ditangguhkan_tugas_dinas' => "Ditangguhkan karena Tugas Dinas oleh {$step->role_label}",
                                 'active' => $status === 'ditangguhkan' ? "Ditangguhkan oleh {$step->role_label}" : "Menunggu {$step->role_label}",
                                 'pending' => "Menunggu {$step->role_label}",
                                 'skipped' => "Dilewati: {$step->role_label}",
                                default => 'Status tidak tersedia',
                            };
                            $skippedReason = match ($step->skipped_reason) {
                                'duty_postponement_terminal' => 'Dilewati karena penangguhan tugas dinas menutup pengajuan.',
                                'duplicate_approver' => 'Dilewati karena approver yang sama sudah tercakup pada tahap lain.',
                                'request_not_approved' => 'Dilewati karena pengajuan telah diputus tidak disetujui.',
                                null => null,
                                default => 'Dilewati karena alur persetujuan telah ditutup.',
                            };
                            $stepDescription = trim(implode(' ', array_filter([
                                $step->approver?->nama_lengkap ?? 'Approver',
                                $step->acted_at?->translatedFormat('d M Y, H:i'),
                                $skippedReason,
                            ])));
                        @endphp
                        <x-ui.timeline-item
                            :variant="$stepVariant"
                            :title="$stepTitle"
                            :description="$stepDescription"
                            :pulse="$step->status === 'active' && $status !== 'ditangguhkan'"
                        />
                    @endforeach
                </x-ui.timeline>
            </div>

            {{-- Riwayat tindakan approval nyata: tiap entri merekam siapa, tahap berapa, aksi, waktu, dan komentar. --}}
            @if ($cuti->approvals->isNotEmpty())
                <div class="border-t border-border pt-6 space-y-4">
                    <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Riwayat Tindakan Approval</h4>
                    <div class="space-y-3">
                        @php
                            $stepLabels = $cuti->steps->keyBy('step_order');
                        @endphp
                        @foreach ($cuti->approvals->sortBy('acted_at') as $approval)
                            <div class="flex items-start gap-3 rounded-lg border border-border bg-soft/30 p-3">
                                @php
                                    $actionVariant = match ($approval->action) {
                                        'APPROVE' => 'success',
                                        'NOT_APPROVED', 'REQUEST_CHANGES' => 'danger',
                                        'POSTPONE', 'DUTY_POSTPONEMENT' => 'warning',
                                        default => 'muted',
                                    };
                                    $actionLabel = match ($approval->action) {
                                        'APPROVE' => 'Setuju',
                                        'POSTPONE' => 'Ditangguhkan',
                                        'DUTY_POSTPONEMENT' => 'Ditangguhkan karena Tugas Dinas',
                                        'REQUEST_CHANGES' => 'Perubahan',
                                        'NOT_APPROVED' => 'Tidak Disetujui',
                                        'SKIP' => 'Dilewati',
                                        default => 'Tindakan tidak dikenal',
                                    };
                                @endphp
                                <x-ui.badge
                                    :variant="$actionVariant"
                                    size="sm"
                                    :pill="false"
                                    uppercase
                                    class="mt-0.5"
                                >
                                    {{ $actionLabel }}
                                </x-ui.badge>
                                <div class="flex-1">
                                    <p class="text-xs font-semibold text-ink font-sans">
                                        {{ $approval->approver?->nama_lengkap ?? 'Approver' }}
                                        <span class="text-muted font-normal">- Tahap {{ $approval->stage }} ({{ $stepLabels[$approval->stage]?->role_label ?? '-' }})</span>
                                    </p>
                                    <p class="text-xs text-muted">{{ $approval->acted_at?->translatedFormat('d M Y, H:i') }}</p>
                                    @if ($approval->komentar)
                                        <p class="text-xs text-ink font-sans mt-1.5 italic">{{ $approval->komentar }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="border-t border-border pt-6 space-y-4"
                   x-data="{ decisionForm: {{ $errors->dutyPostponement->has('alasan') ? "'dutyPostponement'" : 'null' }}, lastTrigger: null,
                     open(key, ev) { this.lastTrigger = ev?.currentTarget ?? null; this.decisionForm = key; this.$nextTick(() => document.getElementById(key === 'dutyPostponement' ? 'alasan-duty-postponement' : `komentar-${key}`)?.focus()); },
                     close() { this.decisionForm = null; this.$nextTick(() => this.lastTrigger?.focus()); } }"
                 @if ($errors->dutyPostponement->has('alasan')) x-init="$nextTick(() => document.getElementById('alasan-duty-postponement')?.focus())" @endif
                 @keydown.escape.window="close()">
                @if (session('success'))
                    <x-ui.alert variant="success" size="sm">{{ session('success') }}</x-ui.alert>
                @endif
                @error('komentar')
                    <x-ui.alert variant="danger" size="sm">{{ $message }}</x-ui.alert>
                @enderror

                @if ($canResubmit)
                    <div class="rounded-lg border border-warning/25 bg-warning/5 p-4">
                        <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">{{ $isRolloverReturn ? 'Perbaiki dan Ajukan Kembali' : 'Kirim Ulang Perubahan' }}</h4>
                        <p id="rollover-target-year-hint" class="mt-1 text-xs text-muted font-sans">{{ $isRolloverReturn ? "Pilih tanggal dalam tahun target {$cuti->rollover_target_year}." : 'Perbaiki tanggal, alasan, atau lampiran.' }} Jenis cuti tetap terkunci agar snapshot approval tidak berubah.</p>
                        <form action="{{ route('cuti.resubmit', $cuti->id) }}" method="POST" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="tanggal_mulai" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Mulai</label>
                                <input id="tanggal_mulai" name="tanggal_mulai" type="date" value="{{ old('tanggal_mulai', $isRolloverReturn ? null : $cuti->tanggal_mulai?->toDateString()) }}" @if ($isRolloverReturn) min="{{ $cuti->rollover_target_year }}-01-01" max="{{ $cuti->rollover_target_year }}-12-31" aria-describedby="rollover-target-year-hint" @endif class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink" required>
                                @error('tanggal_mulai')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="tanggal_selesai" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Selesai</label>
                                <input id="tanggal_selesai" name="tanggal_selesai" type="date" value="{{ old('tanggal_selesai', $isRolloverReturn ? null : $cuti->tanggal_selesai?->toDateString()) }}" @if ($isRolloverReturn) min="{{ $cuti->rollover_target_year }}-01-01" max="{{ $cuti->rollover_target_year }}-12-31" aria-describedby="rollover-target-year-hint" @endif class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink" required>
                                @error('tanggal_selesai')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="alasan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alasan</label>
                                <textarea id="alasan" name="alasan" rows="3" class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink" required>{{ old('alasan', $cuti->alasan) }}</textarea>
                                @error('alasan')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="alamat_selama_cuti" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alamat Selama Cuti <span class="text-danger">*</span></label>
                                <textarea id="alamat_selama_cuti" name="alamat_selama_cuti" rows="2" maxlength="1000" required autocomplete="street-address" aria-describedby="{{ $errors->has('alamat_selama_cuti') ? 'alamat_selama_cuti-help alamat_selama_cuti-error' : 'alamat_selama_cuti-help' }}" @if ($errors->has('alamat_selama_cuti')) aria-invalid="true" @endif class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink">{{ old('alamat_selama_cuti', $cuti->alamat_selama_cuti) }}</textarea>
                                <p id="alamat_selama_cuti-help" class="mt-1 text-xs text-muted font-sans">Digunakan pada formulir Cuti resmi dan untuk menghubungi Anda selama cuti.</p>
                                @error('alamat_selama_cuti')<p id="alamat_selama_cuti-error" class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="nomor_telepon" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Telepon <span class="text-danger">*</span></label>
                                <input id="nomor_telepon" name="nomor_telepon" type="tel" inputmode="tel" maxlength="20" required autocomplete="tel" aria-describedby="{{ $errors->has('nomor_telepon') ? 'nomor_telepon-help nomor_telepon-error' : 'nomor_telepon-help' }}" @if ($errors->has('nomor_telepon')) aria-invalid="true" @endif value="{{ old('nomor_telepon', $cuti->nomor_telepon) }}" class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink">
                                <p id="nomor_telepon-help" class="mt-1 text-xs text-muted font-sans">Digunakan pada formulir Cuti resmi dan untuk menghubungi Anda selama cuti.</p>
                                @error('nomor_telepon')<p id="nomor_telepon-error" class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="lampiran" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Lampiran Baru <span class="font-normal text-muted">(opsional)</span></label>
                                <input id="lampiran" name="lampiran" type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 w-full rounded-lg border border-border bg-surface text-sm text-muted file:mr-4 file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary">
                                @error('lampiran')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2 flex justify-end">
                                <button type="submit" class="{{ $buttonStyles['success'] }}">{{ $isRolloverReturn ? 'Perbaiki dan Ajukan Kembali' : 'Kirim Ulang Pengajuan' }}</button>
                            </div>
                        </form>
                    </div>
                @endif

            {{-- Konteks keputusan verifikator: saldo berjalan, sisa hak N-1/N-2, cuti bersama,
                 dan riwayat cuti tahunan pemohon wajib terlihat sebelum keputusan diambil. --}}
            @if (($isVerifierContext ?? false) && ($verifierContext ?? null) !== null)
                @php($saldoPemohon = $verifierContext['balance'])
                <div class="border-t border-border pt-6 space-y-4">
                    <x-ui.card padding="md" class="border-primary/20 bg-primary/5 space-y-4">
                        <div class="flex items-center justify-between border-b border-border pb-3">
                            <div>
                                <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                                    Informasi Saldo & Riwayat Cuti Pemohon
                                </h4>
                                <p class="text-xs text-muted font-sans mt-0.5">Saldo hak cuti tahun berjalan (N), sisa hak N-1/N-2, cuti bersama, dan riwayat cuti tahunan pemohon.</p>
                            </div>
                            <x-ui.badge variant="{{ $saldoPemohon['eligible'] ? 'success' : 'danger' }}" size="sm">
                                {{ $saldoPemohon['eligible'] ? 'Hak Cuti Aktif' : 'Tidak Eligible' }}
                            </x-ui.badge>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="p-3 bg-surface rounded-lg border border-border space-y-1">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Saldo Dapat Diajukan (N={{ $saldoPemohon['tahun'] }})</span>
                                <p class="text-lg font-bold text-primary font-sans">{{ $saldoPemohon['saldo_dapat_diajukan'] }} Hari</p>
                                <p class="text-[10px] text-muted">Saldo aktual {{ $saldoPemohon['saldo_aktual'] }} · {{ $saldoPemohon['dialokasikan_aktif'] }} dialokasikan · {{ $saldoPemohon['terpakai_final'] }} terpakai</p>
                            </div>

                            <div class="p-3 bg-surface rounded-lg border border-border space-y-1">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sisa Hak N-1 ({{ $saldoPemohon['tahun'] - 1 }})</span>
                                <p class="text-lg font-bold text-warning font-sans">{{ $saldoPemohon['bucket']['n1'] }} Hari</p>
                                <p class="text-[10px] text-muted">Terpakai pada N-1: {{ $saldoPemohon['used_n1'] }} hari</p>
                            </div>

                            <div class="p-3 bg-surface rounded-lg border border-border space-y-1">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sisa Hak N-2 ({{ $saldoPemohon['tahun'] - 2 }})</span>
                                <p class="text-lg font-bold text-ink font-sans">{{ $saldoPemohon['bucket']['n2'] }} Hari</p>
                                <p class="text-[10px] text-muted">Terpakai pada N-2: {{ $saldoPemohon['used_n2'] }} hari</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-3 bg-surface rounded-lg border border-border space-y-1.5">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Cuti Bersama {{ $saldoPemohon['tahun'] }}</span>
                                @if ($verifierContext['cutiBersama']->isEmpty())
                                    <p class="text-xs text-muted font-sans">Tidak ada cuti bersama terdaftar pada tahun ini.</p>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($verifierContext['cutiBersama'] as $hariBersama)
                                            <li class="text-xs text-ink font-sans">{{ $hariBersama->tanggal?->translatedFormat('d M Y') }} — {{ $hariBersama->nama }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            <div class="p-3 bg-surface rounded-lg border border-border space-y-1.5">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Riwayat Cuti Tahunan Disetujui</span>
                                @if ($verifierContext['riwayatTahunan']->isEmpty())
                                    <p class="text-xs text-muted font-sans">Belum ada riwayat cuti tahunan yang disetujui.</p>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($verifierContext['riwayatTahunan'] as $riwayat)
                                            <li class="text-xs text-ink font-sans">{{ $riwayat->tanggal_mulai?->translatedFormat('d M Y') }} – {{ $riwayat->tanggal_selesai?->translatedFormat('d M Y') }} · {{ $riwayat->jumlah_hari_kerja }} hari kerja</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </x-ui.card>
                </div>
            @endif

                @if ($canAct)
                    {{-- Catatan keputusan wajib untuk tindakan selain setuju agar pemohon memahami dasar keputusan. --}}
                    @foreach ([
                        'postpone' => ['route' => 'cuti.postpone', 'label' => 'Alasan Penundaan', 'title' => 'Tunda Sementara', 'variant' => 'warning'],
                        'requestChanges' => ['route' => 'cuti.request-changes', 'label' => 'Catatan Perubahan', 'title' => 'Minta Perubahan', 'variant' => 'danger'],
                        'decline' => ['route' => 'cuti.decline', 'label' => 'Alasan Tidak Disetujui', 'title' => 'Tidak Disetujui', 'variant' => 'danger'],
                    ] as $formKey => $form)
                    <div x-show="decisionForm === '{{ $formKey }}'" x-cloak
                        role="dialog" aria-modal="true" aria-labelledby="decision-title-{{ $formKey }}"
                        x-effect="if (decisionForm === '{{ $formKey }}') $nextTick(() => document.getElementById('komentar-{{ $formKey }}')?.focus())"
                        class="rounded-lg border border-warning/25 bg-warning/5 p-4">
                        <h4 id="decision-title-{{ $formKey }}" class="mb-2 text-sm font-semibold text-ink">{{ $form['title'] }}</h4>
                        <form action="{{ route($form['route'], $cuti->id) }}" method="POST" class="space-y-3">
                            @csrf
                            <x-form.textarea
                                name="komentar"
                                label="{{ $form['label'] }}"
                                id="komentar-{{ $formKey }}"
                                rows="3"
                                minlength="5"
                                placeholder="Tuliskan catatan keputusan agar pemohon memahami tindak lanjut."
                                class="resize-y"
                                required
                            />
                            <div class="flex justify-end gap-2">
                                <button type="button" class="{{ $buttonStyles['muted'] }}" @click="close()">Batal</button>
                                <button type="submit" class="{{ $buttonStyles[$form['variant']] }}">{{ $form['title'] }}</button>
                            </div>
                        </form>
                    </div>
                    @endforeach

                    @if ($cuti->jenisCuti?->code === 'tahunan')
                        <x-ui.modal show="decisionForm === 'dutyPostponement'" close-action="close()" title="Tangguhkan karena Tugas Dinas" description-id="decision-description-duty-postponement">
                            <p id="decision-description-duty-postponement" class="text-sm text-ink">Tindakan ini bersifat terminal: pengajuan lama ditutup, reservasi dilepas, hak dilindungi paling lama satu tahun, dan pegawai membuat pengajuan baru pada tahun berikutnya.</p>
                            <form action="{{ route('cuti.penangguhan-tugas-dinas', $cuti->id) }}" method="POST" class="mt-4 space-y-4">
                                @csrf
                                <div>
                                    <label for="alasan-duty-postponement" class="block text-xs font-bold uppercase tracking-wider text-ink">Alasan Tugas Dinas <span class="text-danger">*</span></label>
                                    <textarea id="alasan-duty-postponement" name="alasan" rows="3" required minlength="5" maxlength="500" aria-invalid="{{ $errors->dutyPostponement->has('alasan') ? 'true' : 'false' }}" data-error-autofocus="{{ $errors->dutyPostponement->has('alasan') ? 'true' : 'false' }}" aria-describedby="decision-description-duty-postponement alasan-duty-postponement-help @error('alasan', 'dutyPostponement') alasan-duty-postponement-error @enderror" class="mt-1 w-full resize-y rounded-lg border border-border bg-surface p-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary @error('alasan', 'dutyPostponement') border-danger @enderror">{{ old('alasan') }}</textarea>
                                    <p id="alasan-duty-postponement-help" class="mt-1 text-xs text-muted">Jelaskan tugas dinas mendesak yang menjadi dasar penangguhan.</p>
                                    @error('alasan', 'dutyPostponement')<p id="alasan-duty-postponement-error" class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <x-ui.button type="button" @click="close()" variant="secondary" class="w-full sm:w-auto">Batal</x-ui.button>
                                    <x-ui.button type="submit" variant="warning" class="w-full sm:w-auto">Konfirmasi Penangguhan Tugas Dinas</x-ui.button>
                                </div>
                            </form>
                        </x-ui.modal>
                    @endif
                @endif

                <div class="flex flex-wrap items-end justify-end gap-3">

                    <a href="{{ route('cuti') }}" class="{{ $buttonStyles['secondary'] }}">
                        Kembali ke Daftar
                    </a>

                    @if ($canDownloadFormulir)
                        <a href="{{ route('cuti.formulir-pdf', $cuti) }}" aria-label="Unduh Formulir Cuti (PDF)" class="{{ $buttonStyles['secondary'] }}">
                            Unduh Formulir Cuti (PDF)
                        </a>
                    @endif

                    @if ($canAct)
                        <x-ui.button type="button" variant="secondary" data-action-visual="temporary-secondary" @click="open('postpone', $event)">
                            Tunda Sementara
                        </x-ui.button>
                        @if ($cuti->jenisCuti?->code === 'tahunan')
                            <div class="w-full sm:w-auto">
                                <p id="duty-postponement-risk" class="mb-2 max-w-md text-xs text-muted">Penangguhan karena tugas dinas menutup pengajuan lama dan melindungi hak sesuai ketentuan.</p>
                                <x-ui.button type="button" variant="warning" data-action-visual="terminal-warning" aria-describedby="duty-postponement-risk" @click="open('dutyPostponement', $event)" class="w-full sm:w-auto">
                                    Tangguhkan karena Tugas Dinas
                                </x-ui.button>
                            </div>
                        @endif
                        <button type="button" class="{{ $buttonStyles['danger'] }}" @click="open('requestChanges', $event)">
                            Perubahan
                        </button>
                        <button type="button" class="{{ $buttonStyles['danger'] }}" @click="open('decline', $event)">
                            Tidak Setujui
                        </button>
                        <form action="{{ route('cuti.approve', $cuti->id) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="{{ $buttonStyles['success'] }}">
                                Setujui
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
