<x-layouts.app title="Detail Pengajuan Cuti" subtitle="Tinjau dan berikan keputusan atas permohonan cuti bawahan.">
    @php
        $status = match ($leave->status) {
            'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
            'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
            'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
            'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
            'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
            default => ['label' => $leave->status, 'variant' => 'muted'],
        };
        $attachmentAvailable = $leave->lampiran && \Illuminate\Support\Facades\Storage::disk('public')->exists($leave->lampiran);
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Keputusan belum dapat disimpan" class="mb-6">
                Periksa kembali data keputusan di bawah ini.
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
                            submitting: false,
                            submitDecision() {
                                if (this.decision === 'DISETUJUI') {
                                    this.confirmOpen = true;
                                } else if (this.decision) {
                                    this.$refs.decisionForm.requestSubmit();
                                }
                            }
                        }">
                            <form x-ref="decisionForm" method="POST"
                                action="{{ route('kepala-bagian.cuti.decision', $leave) }}" class="space-y-4"
                                @submit="submitting = true">
                                @csrf
                                <div>
                                    <label
                                        class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keputusan
                                        Resmi</label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="DISETUJUI" x-model="decision"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-success peer-checked:bg-success/10 peer-checked:text-success-dark">
                                                <span class="text-sm font-bold font-sans">Disetujui</span>
                                            </div>
                                        </label>
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="PERUBAHAN" x-model="decision"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-warning peer-checked:bg-warning/10 peer-checked:text-warning-dark">
                                                <span class="text-sm font-bold font-sans">Perubahan</span>
                                            </div>
                                        </label>
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="DITANGGUHKAN" x-model="decision"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-info peer-checked:bg-info/10 peer-checked:text-info-dark">
                                                <span class="text-sm font-bold font-sans">Ditangguhkan</span>
                                            </div>
                                        </label>
                                        <label class="cursor-pointer relative block">
                                            <input type="radio" name="keputusan" value="TIDAK_DISETUJUI" x-model="decision"
                                                class="peer sr-only" />
                                            <div
                                                class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-danger peer-checked:bg-danger/10 peer-checked:text-danger-dark">
                                                <span class="text-sm font-bold font-sans">Tidak Disetujui</span>
                                            </div>
                                        </label>
                                    </div>
                                    <p class="text-xs text-muted font-sans mt-2">Pilih salah satu tindakan. Keputusan Anda
                                        akan dicatat ke dalam timeline.</p>
                                    @error('keputusan')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                                </div>

                                <div x-show="decision !== 'DISETUJUI'" x-cloak>
                                    <label
                                        class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keterangan
                                        / Catatan Tambahan <span class="text-danger">*</span></label>
                                    <textarea rows="3" name="catatan" required minlength="5" maxlength="500"
                                        placeholder="Wajib diisi jika memilih Perubahan, Ditangguhkan, atau Tidak Disetujui..."
                                        class="w-full rounded-lg border border-border bg-white p-3 text-sm font-sans placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-sm @error('catatan') border-danger @enderror">{{ old('catatan') }}</textarea>
                                    @error('catatan')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
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

                            <x-ui.modal show="confirmOpen" close-action="confirmOpen = false"
                                title="Konfirmasi Persetujuan">
                                <p class="text-sm text-ink">Setujui pengajuan cuti ini? Pengajuan akan diteruskan ke
                                    approver berikutnya atau diselesaikan bila ini adalah tahap final.</p>
                                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <x-ui.button type="button" @click="confirmOpen = false"
                                        variant="secondary">Batal</x-ui.button>
                                    <form method="POST" action="{{ route('kepala-bagian.cuti.decision', $leave) }}"
                                        @submit="submitting = true">
                                        @csrf
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
                                $stepStatus = match ($step->status) {
                                    'approved' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
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
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-ink font-sans mb-1">
                                        {{ $stepStatus['label'] }}</p>
                                    @if($step->decision_note)
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">
                                            {{ $step->decision_note }}</p>
                                    @endif
                                    @if($step->skipped_reason)
                                        <p class="text-xs font-medium text-ink/80 font-sans whitespace-pre-line">
                                            {{ $step->skipped_reason }}</p>
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
            </div>
        </div>
    </div>
</x-layouts.app>
