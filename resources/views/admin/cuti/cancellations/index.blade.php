<x-layouts.app title="Antrean Pembatalan Cuti">
    <div
        class="space-y-6"
        x-data="{
            confirmOpen: false,
            submitting: false,
            confirmation: { action: '', employee: '', period: '', decision: '' },
            openConfirmation(details, decision) {
                if (this.submitting) return;
                this.confirmation = {
                    action: details.cancellationAction,
                    employee: details.employee,
                    period: details.period,
                    decision,
                };
                this.confirmOpen = true;
            },
            closeConfirmation() {
                if (!this.submitting) this.confirmOpen = false;
            },
        }"
    >
        <div>
            <h2 class="text-2xl font-semibold text-ink font-sans">Antrean Pembatalan Cuti</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Antrean Pembatalan Cuti'],
            ]" />
            <p class="mt-2 text-sm text-muted">Tinjau alasan pegawai sebelum menyetujui atau menolak permohonan pembatalan.</p>
        </div>

        @error('decision')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror

        <form method="GET" action="{{ route('cuti.cancellations.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="status" class="mb-1 block text-sm font-medium text-ink">Status pembatalan</label>
                <select id="status" name="status" onchange="this.form.submit()" class="min-h-11 rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <option value="pending" @selected(($filters['status'] ?? 'pending') === 'pending')>Menunggu keputusan</option>
                    <option value="approved" @selected(($filters['status'] ?? null) === 'approved')>Disetujui</option>
                    <option value="rejected" @selected(($filters['status'] ?? null) === 'rejected')>Ditolak</option>
                    <option value="all" @selected(($filters['status'] ?? null) === 'all')>Semua status</option>
                </select>
            </div>
            <div>
                <label for="per-page" class="mb-1 block text-sm font-medium text-ink">Baris per halaman</label>
                <select id="per-page" name="per_page" onchange="this.form.submit()" class="min-h-11 rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink">
                    @foreach([10, 25, 50] as $pageSize)
                        <option value="{{ $pageSize }}" @selected((int) ($filters['per_page'] ?? 10) === $pageSize)>{{ $pageSize }} baris</option>
                    @endforeach
                </select>
            </div>
        </form>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="hidden overflow-x-auto md:block">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th>Pemohon</x-ui.table-th>
                            <x-ui.table-th>Jenis dan periode cuti</x-ui.table-th>
                            <x-ui.table-th>Alasan pembatalan</x-ui.table-th>
                            <x-ui.table-th>Diajukan</x-ui.table-th>
                            <x-ui.table-th>Status</x-ui.table-th>
                            <x-ui.table-th align="right">Keputusan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($cancellations as $cancellation)
                            @php
                                $leaveRequest = $cancellation->leaveRequest;
                                $statusLabel = match ($cancellation->status) {
                                    'pending' => 'Menunggu keputusan',
                                    'approved' => 'Disetujui',
                                    default => 'Ditolak',
                                };
                                $statusVariant = match ($cancellation->status) {
                                    'pending' => 'warning',
                                    'approved' => 'success',
                                    default => 'danger',
                                };
                            @endphp
                            <x-ui.table-row>
                                <x-ui.table-td>
                                    <p class="font-medium text-ink">{{ $leaveRequest?->employee?->nama ?? '-' }}</p>
                                    <p class="mt-1 text-xs text-muted">NIP {{ $leaveRequest?->employee?->nip ?? '-' }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <p class="font-medium text-ink">{{ $leaveRequest?->jenisCuti?->nama ?? '-' }}</p>
                                    <p class="mt-1 text-xs text-muted">
                                        {{ $leaveRequest?->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}
                                        –
                                        {{ $leaveRequest?->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}
                                    </p>
                                    @if($leaveRequest !== null)
                                        <a href="{{ route('cuti.show', $leaveRequest) }}" wire:navigate class="mt-2 inline-flex min-h-11 items-center text-xs font-semibold text-primary hover:underline">Lihat Pengajuan</a>
                                    @endif
                                </x-ui.table-td>
                                <x-ui.table-td class="max-w-sm break-words whitespace-normal">{{ $cancellation->reason }}</x-ui.table-td>
                                <x-ui.table-td>{{ $cancellation->created_at?->translatedFormat('d M Y H:i') }}</x-ui.table-td>
                                <x-ui.table-td><x-ui.badge :variant="$statusVariant">{{ $statusLabel }}</x-ui.badge></x-ui.table-td>
                                <x-ui.table-td align="right">
                                    @if($cancellation->status === 'pending')
                                        <div
                                            class="flex justify-end gap-2"
                                            data-cancellation-action="{{ route('cuti.cancellations.decide', $cancellation) }}"
                                            data-employee="{{ $leaveRequest?->employee?->nama ?? '-' }}"
                                            data-period="{{ $leaveRequest?->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }} – {{ $leaveRequest?->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}"
                                        >
                                            <x-ui.button type="button" variant="success" size="sm" class="min-h-11" x-bind:disabled="submitting" @click="openConfirmation($event.currentTarget.parentElement.dataset, 'DISETUJUI')">Setujui Pembatalan</x-ui.button>
                                            <x-ui.button type="button" variant="danger" size="sm" class="min-h-11" x-bind:disabled="submitting" @click="openConfirmation($event.currentTarget.parentElement.dataset, 'DITOLAK')">Tolak Pembatalan</x-ui.button>
                                        </div>
                                    @else
                                        <span class="text-sm text-muted">Sudah diputus</span>
                                    @endif
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="6" align="center" class="px-6 py-8 text-muted">Tidak ada permohonan pembatalan yang sesuai.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            <div class="divide-y divide-border md:hidden" aria-label="Daftar kartu pembatalan cuti">
                @forelse($cancellations as $cancellation)
                    @php
                        $leaveRequest = $cancellation->leaveRequest;
                        $statusLabel = match ($cancellation->status) {
                            'pending' => 'Menunggu keputusan',
                            'approved' => 'Disetujui',
                            default => 'Ditolak',
                        };
                        $statusVariant = match ($cancellation->status) {
                            'pending' => 'warning',
                            'approved' => 'success',
                            default => 'danger',
                        };
                    @endphp
                    <article class="space-y-4 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-ink">{{ $leaveRequest?->employee?->nama ?? '-' }}</h3>
                                <p class="mt-1 text-xs text-muted">NIP {{ $leaveRequest?->employee?->nip ?? '-' }}</p>
                            </div>
                            <x-ui.badge :variant="$statusVariant">{{ $statusLabel }}</x-ui.badge>
                        </div>
                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="font-medium text-ink">Jenis dan periode cuti</dt>
                                <dd class="mt-1 text-muted">
                                    {{ $leaveRequest?->jenisCuti?->nama ?? '-' }} ·
                                    {{ $leaveRequest?->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}–{{ $leaveRequest?->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}
                                </dd>
                                @if($leaveRequest !== null)
                                    <a href="{{ route('cuti.show', $leaveRequest) }}" wire:navigate class="mt-2 inline-flex min-h-11 items-center text-xs font-semibold text-primary hover:underline">Lihat Pengajuan</a>
                                @endif
                            </div>
                            <div>
                                <dt class="font-medium text-ink">Alasan pembatalan</dt>
                                <dd class="mt-1 break-words text-muted">{{ $cancellation->reason }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-ink">Diajukan</dt>
                                <dd class="mt-1 text-muted">{{ $cancellation->created_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                        </dl>
                        @if($cancellation->status === 'pending')
                            <div
                                class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                                data-cancellation-action="{{ route('cuti.cancellations.decide', $cancellation) }}"
                                data-employee="{{ $leaveRequest?->employee?->nama ?? '-' }}"
                                data-period="{{ $leaveRequest?->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }} – {{ $leaveRequest?->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}"
                            >
                                <x-ui.button type="button" variant="success" full-width class="min-h-11" x-bind:disabled="submitting" @click="openConfirmation($event.currentTarget.parentElement.dataset, 'DISETUJUI')">Setujui Pembatalan</x-ui.button>
                                <x-ui.button type="button" variant="danger" full-width class="min-h-11" x-bind:disabled="submitting" @click="openConfirmation($event.currentTarget.parentElement.dataset, 'DITOLAK')">Tolak Pembatalan</x-ui.button>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-muted">Tidak ada permohonan pembatalan yang sesuai.</p>
                @endforelse
            </div>

            <div class="border-t border-border px-6 py-4">
                {{ $cancellations->links('vendor.pagination.simpeg') }}
            </div>
        </x-ui.card>

        <template x-teleport="body">
            <x-ui.modal
                show="confirmOpen"
                close-action="closeConfirmation()"
                title="Konfirmasi Keputusan Pembatalan"
                description-id="cancellation-confirmation-description"
                header-class="[&_button]:min-h-11 [&_button]:min-w-11"
            >
                <div class="rounded-xl border border-border bg-soft p-4">
                    <p class="text-xs font-medium text-muted">Pemohon</p>
                    <p class="mt-1 break-words font-semibold text-ink" x-text="confirmation.employee"></p>
                    <p class="mt-2 text-sm text-muted" x-text="confirmation.period"></p>
                </div>
                <p
                    id="cancellation-confirmation-description"
                    class="mt-4 text-sm leading-relaxed text-ink"
                    x-text="confirmation.decision === 'DISETUJUI'
                        ? 'Setujui pembatalan ini? Pengajuan cuti akan dibatalkan dan reservasi saldo, jika ada, akan dilepas.'
                        : 'Tolak pembatalan ini? Proses persetujuan cuti akan dilanjutkan dari tahap sebelumnya.'"
                ></p>
                <form
                    method="POST"
                    x-bind:action="confirmation.action"
                    class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                    @submit="if (submitting) { $event.preventDefault() } else { submitting = true }"
                    x-bind:aria-busy="submitting.toString()"
                >
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="decision" x-bind:value="confirmation.decision">
                    <x-ui.button type="button" variant="secondary" class="min-h-11" @click="closeConfirmation()" x-bind:disabled="submitting" data-modal-initial-focus="true">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="success" class="min-h-11" x-show="confirmation.decision === 'DISETUJUI'" x-bind:disabled="submitting">
                        <span x-show="!submitting">Ya, Setujui Pembatalan</span>
                        <span x-show="submitting" role="status" x-cloak>Memproses…</span>
                    </x-ui.button>
                    <x-ui.button type="submit" variant="danger" class="min-h-11" x-show="confirmation.decision === 'DITOLAK'" x-bind:disabled="submitting">
                        <span x-show="!submitting">Ya, Tolak Pembatalan</span>
                        <span x-show="submitting" role="status" x-cloak>Memproses…</span>
                    </x-ui.button>
                </form>
            </x-ui.modal>
        </template>
    </div>
</x-layouts.app>
