<x-layouts.app title="Detail Cuti">
    <div class="mx-auto max-w-7xl space-y-6">

        @php
            // Status runtime Phase 4 memakai token snake_case; label dipisahkan agar UI tidak bergantung status lama.
            $status = $cuti->status;
            $statusVariant = match ($status) {
                'disetujui' => 'success',
                'ditangguhkan' => 'warning',
                'tidak_disetujui' => 'danger',
                'perlu_perubahan' => 'danger',
                default => 'warning',
            };
            $statusLabel = match ($status) {
                'menunggu_approval' => 'Menunggu Approval',
                'ditangguhkan' => 'Ditangguhkan',
                'perlu_perubahan' => 'Perlu Perubahan',
                'disetujui' => 'Disetujui',
                'tidak_disetujui' => 'Tidak Disetujui',
                default => $status,
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
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Jenis Cuti</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $cuti->jenisCuti?->nama ?? '-' }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Durasi Cuti</span>
                    <p class="text-sm font-bold text-ink font-mono">{{ $cuti->jumlah_hari_kerja }} Hari Kerja</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Mulai</span>
                    <p class="text-sm font-semibold text-ink font-mono">{{ $cuti->tanggal_mulai?->translatedFormat('d F Y') }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Selesai</span>
                    <p class="text-sm font-semibold text-ink font-mono">{{ $cuti->tanggal_selesai?->translatedFormat('d F Y') }}</p>
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
                                'rejected' => 'danger',
                                'active' => $status === 'ditangguhkan' ? 'warning' : 'warning',
                                'skipped' => 'muted',
                                default => 'muted',
                            };
                            $stepTitle = match ($step->status) {
                                'approved' => "Disetujui oleh {$step->role_label}",
                                'rejected' => "Tidak disetujui oleh {$step->role_label}",
                                'active' => $status === 'ditangguhkan' ? "Ditangguhkan oleh {$step->role_label}" : "Menunggu {$step->role_label}",
                                'skipped' => "Dilewati: {$step->role_label}",
                                default => "Menunggu {$step->role_label}",
                            };
                            $stepDescription = trim(($step->approver?->nama_lengkap ?? 'Approver').' '.($step->acted_at?->translatedFormat('d M Y, H:i') ?? ''));
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
                                        'REJECT', 'REQUEST_CHANGES' => 'danger',
                                        default => 'warning',
                                    };
                                    $actionLabel = match ($approval->action) {
                                        'APPROVE' => 'Setuju',
                                        'POSTPONE' => 'Tunda',
                                        'REQUEST_CHANGES' => 'Perlu Perubahan',
                                        'REJECT' => 'Tidak Disetujui',
                                        'SKIP' => 'Dilewati',
                                        default => $approval->action,
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
                                    <p class="text-[10px] text-muted font-sans mt-0.5">{{ $approval->acted_at?->translatedFormat('d M Y, H:i') }}</p>
                                    @if ($approval->komentar)
                                        <p class="text-xs text-ink font-sans mt-1.5 italic">{{ $approval->komentar }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="border-t border-border pt-6 space-y-4" x-data="{ decisionForm: null }">
                @if (session('success'))
                    <x-ui.alert variant="success" size="sm">{{ session('success') }}</x-ui.alert>
                @endif
                @error('komentar')
                    <x-ui.alert variant="danger" size="sm">{{ $message }}</x-ui.alert>
                @enderror

                @if ($canResubmit)
                    <div class="rounded-lg border border-warning/25 bg-warning/5 p-4">
                        <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kirim Ulang Perubahan</h4>
                        <p class="mt-1 text-xs text-muted font-sans">Perbaiki tanggal, alasan, atau lampiran. Jenis cuti tetap terkunci agar snapshot approval tidak berubah.</p>
                        <form action="{{ route('cuti.resubmit', $cuti->id) }}" method="POST" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="tanggal_mulai" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Mulai</label>
                                <input id="tanggal_mulai" name="tanggal_mulai" type="date" value="{{ old('tanggal_mulai', $cuti->tanggal_mulai?->toDateString()) }}" class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink" required>
                                @error('tanggal_mulai')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="tanggal_selesai" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Selesai</label>
                                <input id="tanggal_selesai" name="tanggal_selesai" type="date" value="{{ old('tanggal_selesai', $cuti->tanggal_selesai?->toDateString()) }}" class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink" required>
                                @error('tanggal_selesai')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="alasan" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alasan</label>
                                <textarea id="alasan" name="alasan" rows="3" class="mt-1 w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink" required>{{ old('alasan', $cuti->alasan) }}</textarea>
                                @error('alasan')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="lampiran" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Lampiran Baru <span class="font-normal text-muted">(opsional)</span></label>
                                <input id="lampiran" name="lampiran" type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 w-full rounded-lg border border-border bg-surface text-sm text-muted file:mr-4 file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary">
                                @error('lampiran')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="md:col-span-2 flex justify-end">
                                <button type="submit" class="{{ $buttonStyles['success'] }}">Kirim Ulang Pengajuan</button>
                            </div>
                        </form>
                    </div>
                @endif

                @if ($canAct)
                    {{-- Catatan keputusan wajib untuk tindakan selain setuju agar pemohon memahami dasar keputusan. --}}
                    @foreach ([
                        'postpone' => ['route' => 'cuti.postpone', 'label' => 'Alasan Penundaan', 'title' => 'Tunda Pengajuan', 'variant' => 'warning'],
                        'requestChanges' => ['route' => 'cuti.request-changes', 'label' => 'Catatan Perubahan', 'title' => 'Minta Perubahan', 'variant' => 'danger'],
                        'reject' => ['route' => 'cuti.reject', 'label' => 'Alasan Penolakan', 'title' => 'Tidak Setujui', 'variant' => 'danger'],
                    ] as $formKey => $form)
                    <div x-show="decisionForm === '{{ $formKey }}'" x-cloak class="rounded-lg border border-warning/25 bg-warning/5 p-4">
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
                                <button type="button" class="{{ $buttonStyles['muted'] }}" @click="decisionForm = null">Batal</button>
                                <button type="submit" class="{{ $buttonStyles[$form['variant']] }}">{{ $form['title'] }}</button>
                            </div>
                        </form>
                    </div>
                    @endforeach
                @endif

                <div class="flex justify-end gap-3">

                    <a href="{{ route('cuti') }}" class="{{ $buttonStyles['secondary'] }}">
                        Kembali ke Daftar
                    </a>

                    @if ($canAct)
                        <button type="button" class="{{ $buttonStyles['warning'] }}" @click="decisionForm = 'postpone'">
                            Tunda
                        </button>
                        <button type="button" class="{{ $buttonStyles['danger'] }}" @click="decisionForm = 'requestChanges'">
                            Perlu Perubahan
                        </button>
                        <button type="button" class="{{ $buttonStyles['danger'] }}" @click="decisionForm = 'reject'">
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
