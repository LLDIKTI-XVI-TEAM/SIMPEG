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
                            <p class="text-sm text-muted">Nomor tiket: <span class="font-mono">#CT-{{ strtoupper(substr($leave->id, 0, 8)) }}</span></p>
                        </div>
                        <x-ui.badge :variant="$status['variant']" size="md" dot>{{ $status['label'] }}</x-ui.badge>
                    </div>

                    <dl class="space-y-6 text-sm">
                        <div>
                            <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Data Pegawai</dt>
                            <dd class="grid grid-cols-1 gap-4 rounded-lg bg-soft p-4 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs text-muted">Nama</p>
                                    <p class="font-medium text-ink">{{ $leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted">NIP</p>
                                    <p class="font-mono text-ink">{{ $leave->employee?->nip ?? '-' }}</p>
                                </div>
                            </dd>
                        </div>

                        <div>
                            <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Detail Cuti</dt>
                            <dd class="space-y-4 rounded-lg bg-soft p-4">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <p class="text-xs text-muted">Jenis Cuti</p>
                                        <p class="font-medium text-ink">{{ $leave->jenisCuti?->nama ?? '-' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted">Lama Cuti</p>
                                        <p class="font-medium text-ink">{{ $leave->jumlah_hari_kerja }} hari kerja</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted">Tanggal Mulai</p>
                                        <p class="font-medium text-ink">{{ $leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted">Tanggal Selesai</p>
                                        <p class="font-medium text-ink">{{ $leave->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</p>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-muted">Alasan Cuti</p>
                                    <p class="text-ink">{{ $leave->alasan }}</p>
                                </div>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Lampiran Pendukung</dt>
                            <dd>
                                @if ($attachmentAvailable)
                                    <a href="{{ route('pimpinan.cuti.attachment.download', $leave) }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh Lampiran Pendukung</a>
                                @else
                                    <p class="rounded-lg bg-soft p-3 text-sm text-muted">Tidak ada lampiran pendukung.</p>
                                @endif
                            </dd>
                        </div>
                        @if ($leave->status === 'disetujui' && $leave->proof?->document_path)
                            <div>
                                <dt class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted">Dokumen Cuti</dt>
                                <dd class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <a href="{{ route('pimpinan.cuti.document.show', $leave) }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Lihat Formulir Cuti</a>
                                    <a href="{{ route('pimpinan.cuti.document.download', $leave) }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Unduh Dokumen Cuti</a>
                                    <a href="{{ route('cuti.verify', $leave->proof->token) }}" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Verifikasi QR</a>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="mb-4 border-b border-border pb-2 text-lg font-semibold text-ink">Riwayat Persetujuan</h2>
                    <ol class="space-y-4">
                        @forelse ($leave->steps->sortBy('step_order') as $step)
                            @php
                                $stepStatus = match ($step->status) {
                                    'approved' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'rejected' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    'active' => ['label' => 'Menunggu Tindakan', 'variant' => 'warning'],
                                    'skipped' => ['label' => 'Dilewati', 'variant' => 'muted'],
                                    default => ['label' => 'Menunggu Tahap', 'variant' => 'muted'],
                                };
                            @endphp
                            <li class="rounded-lg border border-border p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="font-medium text-ink">Tahap {{ $step->step_order }} · {{ $step->role_label }}</p>
                                        <p class="text-sm text-muted">{{ $step->approver?->nama_lengkap ?? 'Approver belum dipetakan' }}</p>
                                    </div>
                                    <x-ui.badge :variant="$stepStatus['variant']" size="sm" dot>{{ $stepStatus['label'] }}</x-ui.badge>
                                </div>
                                @if ($step->decision_note)
                                    <p class="mt-3 rounded bg-soft p-3 text-sm text-ink">{{ $step->decision_note }}</p>
                                @endif
                                @if ($step->acted_at)
                                    <p class="mt-2 text-xs text-muted">Diproses {{ $step->acted_at->translatedFormat('d M Y H:i') }}</p>
                                @endif
                            </li>
                        @empty
                            <li class="rounded-lg bg-soft p-4 text-sm text-muted">Snapshot approval belum tersedia untuk pengajuan ini.</li>
                        @endforelse
                    </ol>
                </x-ui.card>
                <x-ui.card>
                    <h2 class="mb-4 border-b border-border pb-2 text-lg font-semibold text-ink">Riwayat Tindakan Resmi</h2>
                    <ol class="space-y-4">
                        @forelse ($leave->approvals->sortBy('acted_at') as $approval)
                            @php
                                $action = match ($approval->action) {
                                    'APPROVE' => ['label' => 'Disetujui', 'variant' => 'success'],
                                    'REQUEST_CHANGES' => ['label' => 'Perubahan', 'variant' => 'info'],
                                    'POSTPONE' => ['label' => 'Ditangguhkan', 'variant' => 'warning'],
                                    'REJECT' => ['label' => 'Tidak Disetujui', 'variant' => 'danger'],
                                    default => ['label' => 'Dilewati', 'variant' => 'muted'],
                                };
                            @endphp
                            <li class="rounded-lg border border-border p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <p class="font-medium text-ink">Tahap {{ $approval->stage }} · {{ $approval->approver?->nama_lengkap ?? 'Approver tidak tersedia' }}</p>
                                    <x-ui.badge :variant="$action['variant']" size="sm" dot>{{ $action['label'] }}</x-ui.badge>
                                </div>
                                @if ($approval->komentar)
                                    <p class="mt-3 rounded bg-soft p-3 text-sm text-ink">{{ $approval->komentar }}</p>
                                @endif
                                @if ($approval->acted_at)
                                    <p class="mt-2 text-xs text-muted">Diproses {{ $approval->acted_at->translatedFormat('d M Y H:i') }}</p>
                                @endif
                            </li>
                        @empty
                            <li class="rounded-lg bg-soft p-4 text-sm text-muted">Belum ada tindakan resmi pada pengajuan ini.</li>
                        @endforelse
                    </ol>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card>
                    <h2 class="mb-4 text-sm font-semibold text-ink">Saldo Cuti Tahunan</h2>
                    <dl class="space-y-3 text-sm">
                        @foreach ([$currentYear, $currentYear - 1, $currentYear - 2] as $year)
                            <div class="flex items-center justify-between {{ $loop->first ? '' : 'border-t border-border pt-3' }}">
                                <dt class="text-muted">Tahun {{ $year }}</dt>
                                <dd class="font-semibold text-ink">{{ $balances->get($year)?->sisa ?? 0 }} Hari</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="mb-4 text-lg font-semibold text-ink">Keputusan Pejabat Berwenang</h2>
                    @if ($canDecide)
                        <form action="{{ route('pimpinan.cuti.decision', $leave) }}" method="POST" class="space-y-4" x-data="{ keputusan: '{{ old('keputusan', '') }}' }">
                            @csrf
                            <div>
                                <label for="keputusan" class="mb-2 block text-sm font-semibold text-ink">Ambil Keputusan</label>
                                <select id="keputusan" name="keputusan" x-model="keputusan" required aria-describedby="decision-note-help{{ $errors->has('keputusan') ? ' keputusan-error' : '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                                    <option value="">Pilih keputusan</option>
                                    <option value="DISETUJUI">1. Disetujui</option>
                                    <option value="PERUBAHAN">2. Perubahan</option>
                                    <option value="DITANGGUHKAN">3. Ditangguhkan</option>
                                    <option value="TIDAK_DISETUJUI">4. Tidak Disetujui</option>
                                </select>
                                @error('keputusan')
                                    <p id="keputusan-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="catatan" class="mb-2 block text-sm font-semibold text-ink">Catatan Keputusan</label>
                                <textarea id="catatan" name="catatan" rows="4" :required="keputusan !== '' && keputusan !== 'DISETUJUI'" aria-describedby="decision-note-help{{ $errors->has('catatan') ? ' catatan-error' : '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" placeholder="Tulis catatan keputusan bila diperlukan">{{ old('catatan') }}</textarea>
                                <p id="decision-note-help" class="mt-1 text-xs text-muted">Catatan wajib untuk Perubahan atau Ditangguhkan. Catatan juga wajib untuk Tidak Disetujui.</p>
                                @error('catatan')
                                    <p id="catatan-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                            <button id="submit-decision" type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">Simpan Keputusan</button>
                        </form>
                    @else
                        <p class="rounded-lg bg-soft p-4 text-sm text-muted">
                            @if ($activeStep)
                                Tahap aktif ditujukan kepada {{ $activeStep->approver?->nama_lengkap ?? 'approver yang dipetakan' }}.
                            @else
                                Keputusan tidak tersedia karena pengajuan tidak memiliki tahap approval aktif.
                            @endif
                        </p>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</x-layouts.app>
