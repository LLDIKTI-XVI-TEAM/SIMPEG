<x-layouts.app title="Detail Cuti">
    <div class="mx-auto max-w-7xl space-y-6">

        @php
            // Status tersimpan berupa enum panjang; dipetakan ke token tampilan agar warna/label konsisten.
            $status = $cuti->status;
            $statusVariant = match ($status) {
                'Disetujui' => 'success',
                'Ditunda' => 'danger',
                default => 'warning',
            };

            // Tahap atasan langsung dianggap lewat bila pengajuan sudah melaju ke tahap verifikator/pimpinan atau disetujui.
            $atasanSelesai = in_array($status, ['Menunggu Verifikator', 'Menunggu Pimpinan', 'Disetujui'], true);
            $atasanDitunda = $status === 'Ditunda';
            $finalDisetujui = $status === 'Disetujui';

            $pemohon = $cuti->employee?->nama_lengkap ?? 'Pegawai';
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
                        {{ $status }}
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
                    {{-- Step 1: pengajuan oleh pegawai selalu sudah terjadi --}}
                    <x-ui.timeline-item
                        title="Diajukan oleh Pegawai"
                        :description="$cuti->created_at?->translatedFormat('d M Y, H:i')"
                    />

                    {{-- Step 2: persetujuan atasan langsung --}}
                    @if ($atasanSelesai)
                        <x-ui.timeline-item title="Disetujui oleh Atasan Langsung" />
                    @elseif ($atasanDitunda)
                        <x-ui.timeline-item variant="danger" title="Ditunda oleh Atasan Langsung" />
                    @else
                        <x-ui.timeline-item variant="warning" title="Menunggu Persetujuan Atasan Langsung" pulse />
                    @endif

                    {{-- Step 3: pengesahan pimpinan/kepala lembaga --}}
                    @if ($finalDisetujui)
                        <x-ui.timeline-item title="Disahkan oleh Pimpinan" />
                    @else
                        <x-ui.timeline-item variant="muted" title="Verifikasi & Pengesahan Cuti" />
                    @endif
                </x-ui.timeline>
            </div>

            {{-- Riwayat tindakan approval nyata: tiap entri merekam siapa, tahap berapa, aksi, waktu, dan komentar. --}}
            @if ($cuti->approvals->isNotEmpty())
                <div class="border-t border-border pt-6 space-y-4">
                    <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Riwayat Tindakan Approval</h4>
                    <div class="space-y-3">
                        @php
                            $stageLabel = [1 => 'Atasan Langsung', 2 => 'Verifikator', 3 => 'Pimpinan'];
                        @endphp
                        @foreach ($cuti->approvals->sortBy('acted_at') as $approval)
                            <div class="flex items-start gap-3 rounded-lg border border-border bg-soft/30 p-3">
                                <x-ui.badge
                                    :variant="$approval->action === 'APPROVE' ? 'success' : 'warning'"
                                    size="sm"
                                    :pill="false"
                                    uppercase
                                    class="mt-0.5"
                                >
                                    {{ $approval->action === 'APPROVE' ? 'Setuju' : 'Tunda' }}
                                </x-ui.badge>
                                <div class="flex-1">
                                    <p class="text-xs font-semibold text-ink font-sans">
                                        {{ $approval->approver?->nama_lengkap ?? 'Approver' }}
                                        <span class="text-muted font-normal">- Tahap {{ $approval->stage }} ({{ $stageLabel[$approval->stage] ?? '-' }})</span>
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
            <div class="border-t border-border pt-6 space-y-4" x-data="{ showTunda: false }">
                @if (session('success'))
                    <x-ui.alert variant="success" size="sm">{{ session('success') }}</x-ui.alert>
                @endif
                @error('komentar')
                    <x-ui.alert variant="danger" size="sm">{{ $message }}</x-ui.alert>
                @enderror

                @if ($canAct)
                    {{-- Form penundaan: alasan wajib, jadi ditampilkan terpisah saat approver memilih Tunda. --}}
                    <div x-show="showTunda" x-cloak class="rounded-lg border border-warning/25 bg-warning/5 p-4">
                        <form action="{{ route('cuti.postpone', $cuti->id) }}" method="POST" class="space-y-3">
                            @csrf
                            <x-form.textarea
                                name="komentar"
                                label="Alasan Penundaan"
                                id="komentar-tunda"
                                rows="3"
                                minlength="5"
                                placeholder="Jelaskan alasan penundaan agar pemohon dapat menindaklanjuti."
                                class="resize-y"
                                required
                            />
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" variant="muted" size="md" @click="showTunda = false">Batal</x-ui.button>
                                <x-ui.button type="submit" variant="warning" size="md">Tunda Pengajuan</x-ui.button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="flex justify-end gap-3">

                    <x-ui.button href="{{ route('cuti') }}" variant="secondary">
                        Kembali ke Daftar
                    </x-ui.button>

                    @if ($canAct)
                        <x-ui.button type="button" variant="warning" @click="showTunda = true">
                            Tunda
                        </x-ui.button>
                        <form action="{{ route('cuti.approve', $cuti->id) }}" method="POST" class="inline">
                            @csrf
                            <x-ui.button type="submit" variant="success">
                                Setujui
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
