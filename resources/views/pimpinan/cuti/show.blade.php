<x-layouts.app title="Detail Pengajuan Cuti">
    @php
        $status = match ($leave->status) {
            'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
            'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
            'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
            'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
            'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
            default => ['label' => $leave->status, 'variant' => 'muted'],
        };
        $currentYear = now()->year;
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div>
            <h1 class="text-2xl font-semibold text-ink">Detail Pengajuan Cuti</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Monitoring Cuti', 'url' => route('pimpinan.cuti.index')],
                ['label' => 'Detail Pengajuan'],
            ]" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card>
                    <div class="mb-4 flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-ink">Formulir Permintaan Cuti</h2>
                            <p class="text-sm text-muted">Nomor tiket: <span class="">#CT-{{ strtoupper(substr($leave->id, 0, 8)) }}</span></p>
                        </div>
                        <x-ui.badge :variant="$status['variant']" size="md" dot>{{ $status['label'] }}</x-ui.badge>
                    </div>

                    <dl class="space-y-6 text-sm">
                        <div>
                            <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Data Pegawai</dt>
                            <dd class="grid grid-cols-1 gap-4 rounded-lg bg-soft p-4 sm:grid-cols-2">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Nama</p>
                                    <p class="font-medium text-ink mt-1">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted">NIP</p>
                                    <p class="text-ink mt-1">{{ $leave->employee?->nip ?? '-' }}</p>
                                </div>
                            </dd>
                        </div>

                        <div>
                            <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Detail Cuti</dt>
                            <dd class="space-y-4 rounded-lg bg-soft p-4">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Jenis Cuti</p>
                                        <p class="font-medium text-ink mt-1">{{ $leave->jenisCuti?->nama ?? '-' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Lama Cuti</p>
                                        <p class="font-medium text-ink mt-1">{{ $leave->jumlah_hari_kerja }} hari kerja</p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Tanggal Mulai</p>
                                        <p class="font-medium text-ink mt-1">{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Tanggal Selesai</p>
                                        <p class="font-medium text-ink mt-1">{{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Alasan Cuti</p>
                                    <div class="mt-1 p-3 bg-surface rounded-lg border border-border">
                                        <p class="text-ink">{{ $leave->alasan }}</p>
                                    </div>
                                </div>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Lampiran Pendukung</dt>
                            <dd>
                                @if ($attachmentAvailable)
                                    <x-ui.button as="a" href="{{ route('pimpinan.cuti.attachment.download', $leave) }}" download variant="muted" size="md">
                                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                        Unduh Lampiran
                                    </x-ui.button>
                                @else
                                    <div class="mt-1 p-3 bg-soft rounded-lg border border-border">
                                        <p class="text-sm text-muted font-sans">Tidak ada lampiran pendukung.</p>
                                    </div>
                                @endif
                            </dd>
                        </div>
                        @if ($leave->status === 'disetujui' && $leave->proof?->document_path)
                            <div>
                                <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Dokumen Cuti</dt>
                                <dd class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <x-ui.button href="{{ route('pimpinan.cuti.document.show', $leave) }}" variant="secondary">Lihat Formulir Cuti</x-ui.button>
                                    <x-ui.button href="{{ route('pimpinan.cuti.document.download', $leave) }}" variant="secondary">Unduh Dokumen Cuti</x-ui.button>
                                    <x-ui.button href="{{ route('cuti.verify', $leave->proof->token) }}" variant="secondary">Verifikasi QR</x-ui.button>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </x-ui.card>

                <!-- Aksi Keputusan -->
                @if ($canDecide)
                    <x-ui.card padding="lg" class="border-primary/20 bg-primary/5">
                        <h3 class="text-sm font-bold text-primary-dark font-sans border-b border-primary/20 pb-3 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                            </svg>
                            Tindak Lanjut Anda
                        </h3>

                        <div x-data="{ 
                            decision: '{{ old('keputusan', 'DISETUJUI') }}', 
                            confirmOpen: false, 
                            submitting: false,
                            submitDecision() {
                                if (this.decision === 'DISETUJUI') {
                                    this.confirmOpen = true;
                                } else if (this.decision) {
                                    this.$refs.decisionForm.requestSubmit();
                                }
                            }
                        }">
                            <form x-ref="decisionForm" method="POST" action="{{ route('pimpinan.cuti.decision', $leave) }}" class="space-y-4" @submit="submitting = true">
                                @csrf
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keputusan Resmi</label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="DISETUJUI" x-model="decision" class="peer sr-only" />
                                            <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-success peer-checked:bg-success/10 peer-checked:text-success-dark">
                                                <span class="text-sm font-bold font-sans">Disetujui</span>
                                            </div>
                                        </label>
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="PERUBAHAN" x-model="decision" class="peer sr-only" />
                                            <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-warning peer-checked:bg-warning/10 peer-checked:text-warning-dark">
                                                <span class="text-sm font-bold font-sans">Perubahan</span>
                                            </div>
                                        </label>
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="DITANGGUHKAN" x-model="decision" class="peer sr-only" />
                                            <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-info peer-checked:bg-info/10 peer-checked:text-info-dark">
                                                <span class="text-sm font-bold font-sans">Ditangguhkan</span>
                                            </div>
                                        </label>
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="TIDAK_DISETUJUI" x-model="decision" class="peer sr-only" />
                                            <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-danger peer-checked:bg-danger/10 peer-checked:text-danger-dark">
                                                <span class="text-sm font-bold font-sans">Tidak Disetujui</span>
                                            </div>
                                        </label>
                                    </div>
                                    <p class="text-xs text-muted font-sans mt-2">Pilih salah satu tindakan. Keputusan Anda akan dicatat ke dalam timeline.</p>
                                    @error('keputusan')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                                </div>

                                <div x-show="decision !== 'DISETUJUI'" x-cloak>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keterangan / Catatan Tambahan <span class="text-danger">*</span></label>
                                    <textarea rows="3" name="catatan" :required="decision !== 'DISETUJUI'" minlength="5" maxlength="500" placeholder="Wajib diisi jika memilih Perubahan, Ditangguhkan, atau Tidak Disetujui..." class="w-full rounded-lg border border-border bg-white p-3 text-sm font-sans placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-sm @error('catatan') border-danger @enderror">{{ old('catatan') }}</textarea>
                                    @error('catatan')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                </div>

                                <div class="flex justify-end gap-3 pt-2">
                                    <x-ui.button type="button" @click="submitDecision()" x-bind:disabled="!decision || submitting" variant="primary" class="w-full sm:w-auto">
                                        <span x-show="!submitting" class="flex items-center gap-2">
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                            Simpan Keputusan
                                        </span>
                                        <span x-show="submitting" style="display: none;" class="flex items-center gap-2">
                                            <x-ui.loading size="sm" color="white" /> Menyimpan keputusan...
                                        </span>
                                    </x-ui.button>
                                </div>
                            </form>

                            <x-ui.modal show="confirmOpen" close-action="confirmOpen = false" title="Konfirmasi Persetujuan">
                                <p class="text-sm text-ink">Setujui pengajuan cuti ini? Pengajuan akan diteruskan ke approver berikutnya atau diselesaikan bila ini adalah tahap final.</p>
                                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <x-ui.button type="button" @click="confirmOpen = false" variant="secondary">Batal</x-ui.button>
                                    <form method="POST" action="{{ route('pimpinan.cuti.decision', $leave) }}" @submit="submitting = true">
                                        @csrf
                                        <input type="hidden" name="keputusan" value="DISETUJUI">
                                        <x-ui.button type="submit" x-bind:disabled="submitting" variant="success">
                                            <span x-show="!submitting">Ya, Setujui</span>
                                            <span x-show="submitting" style="display: none;" class="flex items-center gap-2">
                                                <x-ui.loading size="sm" color="white" /> Menyetujui...
                                            </span>
                                        </x-ui.button>
                                    </form>
                                </div>
                            </x-ui.modal>
                        </div>
                    </x-ui.card>
                @else
                    @if ($activeStep)
                        <x-ui.card padding="lg" class="border-warning/20 bg-warning/5">
                            <p class="text-sm text-warning-dark font-medium">Tahap aktif ditujukan kepada <span class="font-bold">{{ $activeStep->approver?->nama_lengkap ?? 'approver yang dipetakan' }}</span>.</p>
                        </x-ui.card>
                    @else
                        <x-ui.card padding="lg" class="border-muted/20 bg-soft">
                            <p class="text-sm text-muted font-medium">Keputusan tidak tersedia karena pengajuan tidak memiliki tahap approval aktif.</p>
                        </x-ui.card>
                    @endif
                @endif
            </div>

            <!-- KOLOM KANAN (Timeline & Saldo) -->
            <div class="space-y-6">
                <x-ui.card padding="lg">
                    <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Saldo Cuti Tahunan</h3>
                    <dl class="space-y-3 text-sm">
                        @foreach ([$currentYear, $currentYear - 1, $currentYear - 2] as $year)
                            <div class="flex items-center justify-between {{ $loop->first ? '' : 'border-t border-border pt-3' }}">
                                <dt class="text-muted font-medium">Tahun {{ $year }}</dt>
                                <dd class="font-bold text-ink">{{ $balances->get($year)?->sisa ?? 0 }} Hari</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>

                <x-ui.card padding="lg">
                    <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Timeline Persetujuan</h3>
                    <x-ui.timeline>
                        @forelse($leave->steps->sortBy('step_order') as $step)
                            @php
                                $stepStatus = match ($step->status) {
                                    'approved' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    // Status legacy tetap dapat dibaca, tetapi penulisan baru memakai tidak_disetujui.
                                    'tidak_disetujui', 'rejected' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    'active' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
                                    'skipped' => ['label' => 'Dilewati', 'variant' => 'muted'],
                                    default => ['label' => ucfirst($step->status), 'variant' => 'info'],
                                };
                            @endphp
                            <x-ui.timeline-item
                                variant="{{ $stepStatus['variant'] }}"
                                title="Tahap {{ $step->step_order }} · {{ $step->role_label }}"
                                description="{{ $step->approver?->nama_lengkap ?? 'Approver tidak tersedia' }}"
                                pulse="{{ $step->status == 'active' }}">

                                <div class="mt-2 bg-soft/50 rounded-lg p-3 border border-border">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-ink font-sans mb-1">{{ $stepStatus['label'] }}</p>
                                    @if($step->decision_note)
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">{{ $step->decision_note }}</p>
                                    @endif
                                    @if($step->skipped_reason)
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">{{ $step->skipped_reason }}</p>
                                    @endif
                                </div>

                                @if($step->acted_at)
                                    <p class="text-[9px] font-medium text-muted mt-1">{{ $step->acted_at->translatedFormat('d M Y H:i') }}</p>
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
                                    // REJECT hanya kompatibilitas baca untuk keputusan historis.
                                    'NOT_APPROVED', 'REJECT' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    default => ['label' => 'Dilewati', 'variant' => 'muted'],
                                };
                            @endphp
                            <x-ui.timeline-item
                                variant="{{ $action['variant'] }}"
                                title="Tahap {{ $approval->stage }} · {{ $approval->approver?->nama_lengkap ?? 'Approver tidak tersedia' }}"
                                description="{{ $action['label'] }}">

                                @if ($approval->komentar)
                                    <div class="mt-2 bg-soft/50 rounded-lg p-3 border border-border">
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">{{ $approval->komentar }}</p>
                                    </div>
                                @endif

                                @if($approval->acted_at)
                                    <p class="text-[9px] font-medium text-muted mt-1">{{ $approval->acted_at->translatedFormat('d M Y H:i') }}</p>
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
