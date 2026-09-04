<x-layouts.app title="Detail Pengajuan Cuti" subtitle="Tinjau dan berikan keputusan atas permohonan cuti bawahan.">
    @php
        $status = match ($leave->status) {
            'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
            'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
            'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
            'ditangguhkan_tugas_dinas' => ['label' => 'Ditangguhkan karena Tugas Dinas', 'variant' => 'warning'],
            'dikembalikan_karena_rollover' => ['label' => 'Dikembalikan karena Rollover', 'variant' => 'warning'],
            'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
            'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
            default => ['label' => 'Status tidak tersedia', 'variant' => 'muted'],
        };
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Keputusan belum dapat disimpan" class="mb-6">
                @error('active_step_id')
                    {{ $message }}
                @else
                    Periksa kembali data keputusan di bawah ini.
                @enderror
            </x-ui.alert>
        @endif

        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Detail Pengajuan Cuti</h2>
                <x-ui.breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
        ['label' => 'Cuti Bawahan', 'url' => route('kepala-bagian.cuti.index')],
        ['label' => 'Detail Cuti']
    ]" />
            </div>
            @if($canDecide)
                <div class="flex items-center gap-2 bg-warning/10 px-4 py-2 rounded-lg border border-warning/20">
                    <div class="w-2 h-2 rounded-full bg-warning animate-pulse"></div>
                    <span class="text-sm font-semibold text-warning-dark font-sans">Menunggu Keputusan Anda</span>
                </div>
            @else
                @php
                    $badgeClasses = match ($status['variant']) {
                        'success' => 'bg-success/10 border-success/20 text-success',
                        'danger' => 'bg-danger/10 border-danger/20 text-danger',
                        'warning' => 'bg-warning/10 border-warning/20 text-warning-dark',
                        'info' => 'bg-info/10 border-info/20 text-info',
                        default => 'bg-muted/10 border-muted/20 text-muted',
                    };
                @endphp
                <div class="flex items-center gap-2 px-4 py-2 rounded-lg border {{ $badgeClasses }}">
                    <span class="text-sm font-semibold font-sans uppercase tracking-wider">{{ $status['label'] }}</span>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- KOLOM KIRI (Informasi Utama) -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Data Pemohon -->
                <x-ui.card padding="lg">
                    <h3
                        class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        Data Pemohon
                    </h3>
                    <div class="flex items-start gap-4">
                        <div class="h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 flex">
                            <span
                                class="text-lg font-bold text-primary">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($leave->employee?->nama_lengkap ?? 'A', 0, 1)) }}</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8 flex-1">
                            <div>
                                <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Nama &
                                    NIP</p>
                                <p class="text-sm font-bold text-ink font-sans mt-0.5">
                                    {{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p>
                                <p class="text-xs text-muted font-sans mt-0.5">{{ $leave->employee?->nip ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Jabatan &
                                    Golongan</p>
                                <p class="text-sm font-medium text-ink font-sans mt-0.5">
                                    {{ $leave->employee?->jabatan_terakhir ?? '-' }}</p>
                                <p class="text-xs text-muted font-sans mt-0.5">
                                    {{ $leave->employee?->golongan_terakhir ?? '-' }}</p>
                            </div>
                        </div>
                    </div>
                </x-ui.card>

                <!-- Data Cuti & Lampiran -->
                <x-ui.card padding="lg">
                    <h3
                        class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                        Informasi Pengajuan
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-6 gap-x-8">
                        <div>
                            <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Jenis Cuti
                            </p>
                            <p class="text-sm font-medium text-ink font-sans mt-1">
                                <x-ui.badge variant="info" size="md">{{ $leave->jenisCuti?->nama ?? '-' }}</x-ui.badge>
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Lama Cuti</p>
                            <p class="text-sm font-bold text-ink font-sans mt-1">{{ $leave->jumlah_hari_kerja }} Hari
                                Kerja</p>
                            <p class="text-xs text-muted font-sans mt-0.5">
                                {{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }} s.d.
                                {{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Alasan Cuti
                            </p>
                            <div class="mt-1 p-3 bg-surface rounded-lg border border-border">
                                <p class="text-sm font-medium text-ink font-sans whitespace-pre-line">
                                    {{ $leave->alasan }}</p>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Lampiran
                                Pendukung</p>
                            @if ($attachmentAvailable)
                                <a href="{{ route('kepala-bagian.cuti.attachment.download', $leave) }}" download
                                    class="mt-1 flex items-center gap-3 p-3 rounded-lg border border-border bg-white cursor-pointer hover:bg-soft transition-colors w-max">
                                    <div class="text-primary">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-ink">Unduh Lampiran</p>
                                    </div>
                                </a>
                            @else
                                <div class="mt-1 p-3 bg-surface rounded-lg border border-border">
                                    <p class="text-sm text-muted font-sans">Tidak ada lampiran pendukung.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-ui.card>

                <!-- Aksi Kepala Bagian -->
                @if($canDecide)
                    <x-ui.card padding="lg" class="border-primary/20 bg-primary/5">
                        <h3
                            class="text-sm font-bold text-primary-dark font-sans border-b border-primary/20 pb-3 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                            </svg>
                            Tindak Lanjut Anda
                        </h3>

                        <div x-data="{ 
                            decision: '{{ old('keputusan', 'DISETUJUI') }}', 
                            confirmOpen: false,
                            dutyPostponementOpen: {{ $errors->dutyPostponement->hasAny(['alasan', 'active_step_id']) ? 'true' : 'false' }},
                            dutyPostponementTrigger: null,
                            submitting: false,
                            openDutyPostponement(event) {
                                this.dutyPostponementTrigger = event.currentTarget;
                                this.dutyPostponementOpen = true;
                                this.$nextTick(() => document.getElementById('kabag-duty-postponement-reason')?.focus());
                            },
                            closeDutyPostponement() {
                                this.dutyPostponementOpen = false;
                                this.$nextTick(() => this.dutyPostponementTrigger?.focus());
                            },
                            submitDecision() {
                                if (this.decision === 'DISETUJUI') {
                                    this.confirmOpen = true;
                                } else if (this.decision) {
                                    this.$refs.decisionForm.requestSubmit();
                                }
                            }
                        }" @if ($errors->dutyPostponement->has('alasan')) x-init="$nextTick(() => document.getElementById('kabag-duty-postponement-reason')?.focus())" @endif>
                            <form x-ref="decisionForm" method="POST"
                                action="{{ route('kepala-bagian.cuti.decision', $leave) }}" class="space-y-4"
                                @submit="submitting = true">
                                @csrf
                                <input type="hidden" name="active_step_id" value="{{ $activeStep?->id }}">
                                <fieldset aria-describedby="{{ $errors->has('keputusan') ? 'kabag-decision-help kabag-decision-error' : 'kabag-decision-help' }}">
                                    <legend
                                        class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-ink font-sans">Keputusan
                                        Resmi</legend>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                        <label for="kabag-decision-approved" class="cursor-pointer relative block">
                                            <input id="kabag-decision-approved" type="radio" name="keputusan" value="DISETUJUI" x-model="decision"
                                                aria-invalid="{{ $errors->has('keputusan') ? 'true' : 'false' }}"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-success peer-checked:bg-success/10 peer-checked:text-success-dark peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2">
                                                <span class="text-sm font-bold font-sans">Disetujui</span>
                                            </div>
                                        </label>
                                        <label for="kabag-decision-changes" class="cursor-pointer relative block">
                                            <input id="kabag-decision-changes" type="radio" name="keputusan" value="PERUBAHAN" x-model="decision"
                                                aria-invalid="{{ $errors->has('keputusan') ? 'true' : 'false' }}"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-warning peer-checked:bg-warning/10 peer-checked:text-warning-dark peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2">
                                                <span class="text-sm font-bold font-sans">Perubahan</span>
                                            </div>
                                        </label>
                                        <label for="kabag-decision-postponed" class="cursor-pointer relative block">
                                            <input id="kabag-decision-postponed" type="radio" name="keputusan" value="DITANGGUHKAN" x-model="decision"
                                                aria-invalid="{{ $errors->has('keputusan') ? 'true' : 'false' }}"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-info peer-checked:bg-info/10 peer-checked:text-info-dark peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2">
                                                <span class="text-sm font-bold font-sans">Ditangguhkan</span>
                                            </div>
                                        </label>
                                        <label for="kabag-decision-not-approved" class="cursor-pointer relative block">
                                            <input id="kabag-decision-not-approved" type="radio" name="keputusan" value="TIDAK_DISETUJUI" x-model="decision"
                                                aria-invalid="{{ $errors->has('keputusan') ? 'true' : 'false' }}"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-danger peer-checked:bg-danger/10 peer-checked:text-danger-dark peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2">
                                                <span class="text-sm font-bold font-sans">Tidak Disetujui</span>
                                            </div>
                                        </label>
                                    </div>
                                    <p id="kabag-decision-help" class="text-xs text-muted font-sans mt-2">Pilih salah satu tindakan. Keputusan Anda
                                        akan dicatat ke dalam timeline.</p>
                                    @error('keputusan')<p id="kabag-decision-error" class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                                </fieldset>

                                <div x-show="decision !== 'DISETUJUI'" x-cloak>
                                    <label for="kabag-decision-note"
                                        class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keterangan
                                        / Catatan Tambahan <span class="text-danger">*</span></label>
                                    <textarea id="kabag-decision-note" rows="3" name="catatan" required minlength="5" maxlength="500"
                                        aria-invalid="{{ $errors->has('catatan') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('catatan') ? 'kabag-decision-note-help kabag-decision-note-error' : 'kabag-decision-note-help' }}"
                                        placeholder="Wajib diisi jika memilih Perubahan, Ditangguhkan, atau Tidak Disetujui..."
                                        class="w-full rounded-lg border border-border bg-white p-3 text-sm font-sans placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary focus-visible:ring-2 focus-visible:ring-primary/30 shadow-sm @error('catatan') border-danger @enderror">{{ old('catatan') }}</textarea>
                                    <p id="kabag-decision-note-help" class="mt-1 text-xs text-muted">Wajib untuk Perubahan, Ditangguhkan, dan Tidak Disetujui.</p>
                                    @error('catatan')<p id="kabag-decision-note-error" class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                                </div>

                                <div class="flex justify-end gap-3 pt-2">
                                    <x-ui.button type="button" @click="submitDecision()"
                                        x-bind:disabled="!decision || submitting" variant="primary"
                                        class="w-full sm:w-auto">
                                        <span x-show="!submitting" class="flex items-center gap-2">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                            Simpan Keputusan
                                        </span>
                                        <span x-show="submitting" style="display: none;" class="flex items-center gap-2">
                                            <x-ui.loading size="sm" color="white" /> Menyimpan keputusan...
                                        </span>
                                    </x-ui.button>
                                </div>
                            </form>

                            @if ($leave->jenisCuti?->code === 'tahunan')
                                <div class="mt-4 border-t border-primary/20 pt-4">
                                    <p class="text-xs text-muted">Penangguhan karena tugas dinas menutup pengajuan lama dan melindungi hak sesuai ketentuan.</p>
                                    <x-ui.button type="button" @click="openDutyPostponement($event)" variant="warning" class="mt-3 w-full sm:w-auto">Tangguhkan karena Tugas Dinas</x-ui.button>
                                </div>

                                <x-ui.modal show="dutyPostponementOpen" close-action="closeDutyPostponement()" title="Tangguhkan karena Tugas Dinas" description-id="kabag-duty-postponement-description">
                                    <p id="kabag-duty-postponement-description" class="text-sm text-ink">Tindakan ini bersifat terminal: pengajuan lama ditutup, reservasi dilepas, hak dilindungi paling lama satu tahun, dan pegawai membuat pengajuan baru pada tahun berikutnya.</p>
                                    <form method="POST" action="{{ route('kepala-bagian.cuti.penangguhan-tugas-dinas', $leave) }}" class="mt-4 space-y-4">
                                        @csrf
                                        <input type="hidden" name="active_step_id" value="{{ $activeStep?->id }}">
                                        @error('active_step_id', 'dutyPostponement')
                                            <p class="text-sm text-danger" role="alert">{{ $message }}</p>
                                        @enderror
                                        <div>
                                            <label for="kabag-duty-postponement-reason" class="block text-xs font-bold uppercase tracking-wider text-ink">Alasan Tugas Dinas <span class="text-danger">*</span></label>
                                            <textarea id="kabag-duty-postponement-reason" name="alasan" rows="3" required minlength="5" maxlength="500" aria-invalid="{{ $errors->dutyPostponement->has('alasan') ? 'true' : 'false' }}" data-error-autofocus="{{ $errors->dutyPostponement->has('alasan') ? 'true' : 'false' }}" data-modal-initial-focus="true" aria-describedby="kabag-duty-postponement-description kabag-duty-postponement-help @error('alasan', 'dutyPostponement') kabag-duty-postponement-error @enderror" class="mt-1 w-full resize-y rounded-lg border border-border bg-surface p-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary @error('alasan', 'dutyPostponement') border-danger @enderror">{{ old('alasan') }}</textarea>
                                            <p id="kabag-duty-postponement-help" class="mt-1 text-xs text-muted">Jelaskan tugas dinas mendesak yang menjadi dasar penangguhan.</p>
                                            @error('alasan', 'dutyPostponement')<p id="kabag-duty-postponement-error" class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                                        </div>
                                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                            <x-ui.button type="button" @click="closeDutyPostponement()" variant="secondary">Batal</x-ui.button>
                                            <x-ui.button type="submit" variant="warning">Konfirmasi Penangguhan Tugas Dinas</x-ui.button>
                                        </div>
                                    </form>
                                </x-ui.modal>
                            @endif

                            <x-ui.modal show="confirmOpen" close-action="confirmOpen = false"
                                title="Konfirmasi Persetujuan" description-id="kabag-approval-confirmation-description">
                                <p id="kabag-approval-confirmation-description" class="text-sm text-ink">Setujui pengajuan cuti ini? Pengajuan akan diteruskan ke
                                    approver berikutnya atau diselesaikan bila ini adalah tahap final.</p>
                                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <x-ui.button id="kabag-approval-confirmation-cancel" type="button" @click="confirmOpen = false"
                                        data-modal-initial-focus="true"
                                        variant="secondary">Batal</x-ui.button>
                                    <form method="POST" action="{{ route('kepala-bagian.cuti.decision', $leave) }}"
                                        @submit="submitting = true">
                                        @csrf
                                        <input type="hidden" name="active_step_id" value="{{ $activeStep?->id }}">
                                        <input type="hidden" name="keputusan" value="DISETUJUI">
                                        <x-ui.button type="submit" x-bind:disabled="submitting" variant="success">
                                            <span x-show="!submitting">Ya, Setujui</span>
                                            <span x-show="submitting" style="display: none;"
                                                class="flex items-center gap-2">
                                                <x-ui.loading size="sm" color="white" /> Menyetujui...
                                            </span>
                                        </x-ui.button>
                                    </form>
                                </div>
                            </x-ui.modal>
                        </div>
                    </x-ui.card>
                @endif
            </div>

            <!-- KOLOM KANAN (Timeline) -->
            <div class="space-y-6">
                <x-ui.card padding="lg">
                    <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Timeline
                        Persetujuan</h3>
                    <x-ui.timeline>
                        @forelse($leave->steps->sortBy('step_order') as $step)
                            @php
                                $stepRoleLabel = \App\Support\Cuti\ApprovalStepLabel::display($step->step_type, $step->role_label);
                                $stepStatus = match ($step->status) {
                                    'approved' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                     'ditangguhkan_tugas_dinas' => ['label' => 'Ditangguhkan karena Tugas Dinas', 'variant' => 'warning'],
                                     'active' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
                                     'pending' => ['label' => "Menunggu {$stepRoleLabel}", 'variant' => 'muted'],
                                     'skipped' => ['label' => 'Dilewati', 'variant' => 'muted'],
                                    default => ['label' => 'Status tidak tersedia', 'variant' => 'muted'],
                                };
                                $skippedReason = match ($step->skipped_reason) {
                                    'duty_postponement_terminal' => 'Dilewati karena penangguhan tugas dinas menutup pengajuan.',
                                    'request_not_approved' => 'Dilewati karena pengajuan telah diputus tidak disetujui.',
                                    null => null,
                                    default => 'Dilewati karena alur persetujuan telah ditutup.',
                                };
                            @endphp
                            <x-ui.timeline-item
                                variant="{{ $stepStatus['variant'] }}"
                                title="Tahap {{ $step->step_order }} · {{ $stepRoleLabel }}"
                                description="{{ $step->approver?->nama_lengkap ?? 'Approver tidak tersedia' }}"
                                pulse="{{ $step->status == 'active' }}">

                                <div class="mt-2 bg-soft/50 rounded-lg p-3 border border-border">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-ink font-sans mb-1">
                                        {{ $stepStatus['label'] }}</p>
                                    @if($step->decision_note)
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">
                                            {{ $step->decision_note }}</p>
                                    @endif
                                    @if($skippedReason)
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">
                                            {{ $skippedReason }}</p>
                                    @endif
                                </div>

                                @if($step->acted_at)
                                    <p class="text-[9px] font-medium text-muted mt-1">
                                        {{ $step->acted_at->translatedFormat('d M Y H:i') }}</p>
                                @endif
                            </x-ui.timeline-item>
                        @empty
                            <p class="text-sm text-muted">Timeline approval belum tersedia.</p>
                        @endforelse
                    </x-ui.timeline>
                </x-ui.card>

                <x-ui.card padding="lg">
                    <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Riwayat Tindakan Resmi</h3>
                    <x-ui.timeline>
                        @forelse($leave->approvals->sortBy('acted_at') as $approval)
                            @php
                                $action = match ($approval->action) {
                                    'APPROVE' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'REQUEST_CHANGES' => ['label' => 'Perubahan', 'variant' => 'info'],
                                    'POSTPONE' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
                                    'DUTY_POSTPONEMENT' => ['label' => 'Ditangguhkan karena Tugas Dinas', 'variant' => 'warning'],
                                    'NOT_APPROVED' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    'SKIP' => ['label' => 'Dilewati', 'variant' => 'muted'],
                                    default => ['label' => 'Tindakan tidak dikenal', 'variant' => 'muted'],
                                };
                            @endphp
                            <x-ui.timeline-item variant="{{ $action['variant'] }}" title="Tahap {{ $approval->stage }} · {{ $approval->approver?->nama_lengkap ?? 'Approver tidak tersedia' }}" description="{{ $action['label'] }}">
                                @if ($approval->komentar)
                                    <div class="mt-2 rounded-lg border border-border bg-soft/50 p-3">
                                        <p class="whitespace-pre-line text-xs font-medium text-ink/80">{{ $approval->komentar }}</p>
                                    </div>
                                @endif
                            </x-ui.timeline-item>
                        @empty
                            <p class="text-sm text-muted">Belum ada tindakan resmi.</p>
                        @endforelse
                    </x-ui.timeline>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-layouts.app>
