<x-layouts.app title="Detail Pengajuan Cuti" subtitle="Tinjau pengajuan bawahan langsung dan ambil keputusan pada tahap Anda.">
    @php
        $status = match ($leave->status) {
            'menunggu_approval' => ['label' => 'Menunggu Keputusan', 'variant' => 'warning'],
            'disetujui' => ['label' => 'Disetujui', 'variant' => 'success'],
            'ditangguhkan' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
            'perlu_perubahan' => ['label' => 'Perubahan', 'variant' => 'info'],
            'tidak_disetujui' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
            default => ['label' => $leave->status, 'variant' => 'muted'],
        };
    @endphp

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Keputusan belum dapat disimpan">
                Periksa kembali data keputusan di bawah ini.
            </x-ui.alert>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink">Detail Pengajuan Cuti</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                    ['label' => 'Pengajuan Cuti Bawahan', 'url' => route('kepala-bagian.cuti.index')],
                    ['label' => 'Detail Pengajuan'],
                ]" />
            </div>
            <a href="{{ route('kepala-bagian.cuti.index') }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Kembali ke Antrean</a>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-ui.card>
                    <div class="mb-5 flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-xl font-semibold text-ink">Formulir Permintaan Cuti</h2><p class="mt-1 text-sm text-muted">Nomor tiket: <span class="font-mono">#CT-{{ strtoupper(substr($leave->id, 0, 8)) }}</span></p></div>
                        <x-ui.badge :variant="$status['variant']" size="md" dot>{{ $status['label'] }}</x-ui.badge>
                    </div>
                    <dl class="space-y-6 text-sm">
                        <div><dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Data Pegawai</dt><dd class="grid grid-cols-1 gap-4 rounded-lg bg-soft p-4 sm:grid-cols-2"><div><p class="text-xs text-muted">Nama</p><p class="font-medium text-ink">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p></div><div><p class="text-xs text-muted">NIP</p><p class="font-mono text-ink">{{ $leave->employee?->nip ?? '-' }}</p></div><div><p class="text-xs text-muted">Jabatan</p><p class="font-medium text-ink">{{ $leave->employee?->jabatan_terakhir ?? '-' }}</p></div><div><p class="text-xs text-muted">Golongan</p><p class="font-medium text-ink">{{ $leave->employee?->golongan_terakhir ?? '-' }}</p></div></dd></div>
                        <div><dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Detail Cuti</dt><dd class="space-y-4 rounded-lg bg-soft p-4"><div class="grid grid-cols-1 gap-4 sm:grid-cols-3"><div><p class="text-xs text-muted">Jenis Cuti</p><p class="font-medium text-ink">{{ $leave->jenisCuti?->nama ?? '-' }}</p></div><div><p class="text-xs text-muted">Tanggal Mulai</p><p class="font-medium text-ink">{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}</p></div><div><p class="text-xs text-muted">Tanggal Selesai</p><p class="font-medium text-ink">{{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p></div></div><div><p class="text-xs text-muted">Jumlah Hari Kerja</p><p class="font-medium text-ink">{{ $leave->jumlah_hari_kerja }} hari kerja</p></div><div><p class="text-xs text-muted">Alasan Cuti</p><p class="whitespace-pre-line text-ink">{{ $leave->alasan }}</p></div></dd></div>
                        <div><dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Lampiran Pendukung</dt><dd>@if ($attachmentAvailable)<a href="{{ route('kepala-bagian.cuti.attachment.download', $leave) }}" download aria-label="Unduh lampiran pendukung pengajuan cuti" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh Lampiran Pendukung</a>@else<p class="rounded-lg bg-soft p-3 text-sm text-muted">Tidak ada lampiran pendukung.</p>@endif</dd></div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-lg font-semibold text-ink">Timeline Approval</h2>
                    <ol class="mt-5 space-y-4 border-l border-border pl-5" aria-label="Timeline approval cuti">
                        @forelse ($leave->steps->sortBy('step_order') as $step)
                            @php($stepStatus = match ($step->status) {
                                'approved' => ['label' => 'Disetujui', 'variant' => 'success'],
                                'active' => ['label' => 'Menunggu tindakan', 'variant' => 'warning'],
                                'skipped' => ['label' => 'Dilewati', 'variant' => 'muted'],
                                default => ['label' => ucfirst($step->status), 'variant' => 'info'],
                            })
                            <li class="relative"><span class="absolute -left-[1.8rem] top-1.5 h-3 w-3 rounded-full border-2 border-surface bg-primary"></span><div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><p class="font-semibold text-ink">Tahap {{ $step->step_order }} · {{ $step->role_label }}</p><p class="mt-1 text-sm text-muted">{{ $step->approver?->nama_lengkap ?? 'Approver tidak tersedia' }}</p>@if ($step->decision_note)<p class="mt-2 whitespace-pre-line rounded bg-soft p-2 text-sm text-ink">{{ $step->decision_note }}</p>@endif @if ($step->skipped_reason)<p class="mt-2 text-sm text-muted">{{ $step->skipped_reason }}</p>@endif</div><div class="text-left sm:text-right"><x-ui.badge :variant="$stepStatus['variant']" size="sm" dot>{{ $stepStatus['label'] }}</x-ui.badge>@if ($step->acted_at)<p class="mt-1 text-xs text-muted">{{ $step->acted_at->translatedFormat('d M Y H:i') }}</p>@endif</div></div></li>
                        @empty
                            <li class="text-sm text-muted">Timeline approval belum tersedia.</li>
                        @endforelse
                    </ol>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                @if ($canDecide)
                    <x-ui.card>
                        <div x-data="{ decision: @js(old('keputusan', '')), confirmOpen: false, submitting: false }">
                            <h2 class="text-lg font-semibold text-ink">Keputusan Kepala Bagian</h2>
                            <p class="mt-1 text-sm text-muted">Pilih salah satu label keputusan resmi. Keputusan selain Disetujui wajib menyertakan catatan.</p>

                            <form x-ref="decisionForm" method="POST" action="{{ route('kepala-bagian.cuti.decision', $leave) }}" class="mt-5 space-y-4" @submit="submitting = true">
                                @csrf
                                <fieldset>
                                    <legend class="sr-only">Pilih keputusan cuti</legend>
                                    <div class="space-y-2">
                                        @foreach (['DISETUJUI' => 'Disetujui', 'PERUBAHAN' => 'Perubahan', 'DITANGGUHKAN' => 'Ditangguhkan', 'TIDAK_DISETUJUI' => 'Tidak Disetujui'] as $value => $label)
                                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-border p-3 text-sm text-ink transition hover:bg-soft has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                                <input x-model="decision" type="radio" name="keputusan" value="{{ $value }}" class="h-4 w-4 border-border text-primary focus:ring-primary" aria-describedby="keputusan-error">
                                                <span class="font-medium">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('keputusan')<p id="keputusan-error" class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                                </fieldset>

                                <div x-show="decision && decision !== 'DISETUJUI'" x-cloak>
                                    <label for="catatan" class="mb-1 block text-sm font-medium text-ink">Catatan keputusan <span class="text-danger" aria-hidden="true">*</span></label>
                                    <textarea required id="catatan" name="catatan" rows="5" minlength="5" maxlength="500" aria-describedby="catatan-hint @error('catatan') catatan-error @enderror" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 @error('catatan') border-danger @enderror">{{ old('catatan') }}</textarea>
                                    <p id="catatan-hint" class="mt-1 text-xs text-muted">Minimal 5 dan maksimal 500 karakter. Catatan dikirim ke pegawai.</p>
                                    @error('catatan')<p id="catatan-error" class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                </div>

                                <button type="button" @click="if (decision === 'DISETUJUI') { confirmOpen = true } else if (decision) { $refs.decisionForm.requestSubmit() }" x-bind:disabled="!decision || submitting" class="inline-flex w-full items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30 disabled:cursor-not-allowed disabled:opacity-60">
                                    <span x-show="!submitting">Simpan Keputusan</span><span x-show="submitting" style="display: none;">Menyimpan keputusan…</span>
                                </button>
                            </form>

                            <x-ui.modal show="confirmOpen" close-action="confirmOpen = false" title="Konfirmasi Persetujuan">
                                <p class="text-sm text-ink">Setujui pengajuan cuti ini? Pengajuan akan diteruskan ke approver berikutnya atau diselesaikan bila ini adalah tahap final.</p>
                                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <button type="button" @click="confirmOpen = false" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Batal</button>
                                    <form method="POST" action="{{ route('kepala-bagian.cuti.decision', $leave) }}" @submit="submitting = true">@csrf<input type="hidden" name="keputusan" value="DISETUJUI"><button type="submit" x-bind:disabled="submitting" class="inline-flex items-center justify-center rounded-xl bg-success px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-success/30 disabled:cursor-wait disabled:opacity-60"><span x-show="!submitting">Ya, Setujui</span><span x-show="submitting" style="display: none;">Menyetujui…</span></button></form>
                                </div>
                            </x-ui.modal>
                        </div>
                    </x-ui.card>
                @else
                    <x-ui.card><h2 class="text-lg font-semibold text-ink">Keputusan Cuti</h2><p class="mt-2 text-sm text-muted">Pengajuan ini tidak berada pada step approval aktif Anda atau sudah selesai diproses.</p></x-ui.card>
                @endif

                @if ($activeStep)
                    <x-ui.card><h2 class="text-lg font-semibold text-ink">Tahap Aktif</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-xs text-muted">Tahap</dt><dd class="font-medium text-ink">{{ $activeStep->step_order }} · {{ $activeStep->role_label }}</dd></div><div><dt class="text-xs text-muted">Approver</dt><dd class="font-medium text-ink">{{ $activeStep->approver?->nama_lengkap ?? '-' }}</dd></div></dl></x-ui.card>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
