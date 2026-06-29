<x-layouts.app title="Detail Cuti">
    <div class="mx-auto max-w-3xl space-y-6">

        @php
            // Status tersimpan berupa enum panjang; dipetakan ke token tampilan agar warna/label konsisten.
            $status = $cuti->status;
            $statusClass = match ($status) {
                'Disetujui' => 'text-success',
                'Ditunda' => 'text-danger',
                default => 'text-warning',
            };

            // Tahap atasan langsung dianggap lewat bila pengajuan sudah melaju ke tahap verifikator/pimpinan atau disetujui.
            $atasanSelesai = in_array($status, ['Menunggu Verifikator', 'Menunggu Pimpinan', 'Disetujui'], true);
            $atasanDitunda = $status === 'Ditunda';
            $finalDisetujui = $status === 'Disetujui';

            $pemohon = $cuti->employee?->nama_lengkap ?? 'Pegawai';
        @endphp

        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Detail Pengajuan Cuti</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Pengajuan</span>
            </nav>
        </div>

        {{-- Detail Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">

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
                    <span class="inline-flex items-center text-xs font-bold {{ $statusClass }} font-sans">
                        {{ $status }}
                    </span>
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

                <div class="relative pl-6 space-y-6 before:absolute before:left-2 before:top-2 before:bottom-2 before:w-0.5 before:bg-border">

                    {{-- Step 1: pengajuan oleh pegawai selalu sudah terjadi --}}
                    <div class="relative">
                        <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-success bg-surface flex items-center justify-center">
                            <div class="h-1 w-1 rounded-full bg-success"></div>
                        </div>
                        <div class="pl-3">
                            <p class="text-xs font-bold text-ink font-sans">Diajukan oleh Pegawai</p>
                            <p class="text-[10px] text-muted font-sans mt-0.5">{{ $cuti->created_at?->translatedFormat('d M Y, H:i') }}</p>
                        </div>
                    </div>

                    {{-- Step 2: persetujuan atasan langsung --}}
                    <div class="relative">
                        @if ($atasanSelesai)
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-success bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-success"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-ink font-sans">Disetujui oleh Atasan Langsung</p>
                            </div>
                        @elseif ($atasanDitunda)
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-danger bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-danger"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-danger font-sans">Ditunda oleh Atasan Langsung</p>
                            </div>
                        @else
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-warning bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-warning animate-pulse"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-warning font-sans">Menunggu Persetujuan Atasan Langsung</p>
                            </div>
                        @endif
                    </div>

                    {{-- Step 3: pengesahan pimpinan/kepala lembaga --}}
                    <div class="relative">
                        @if ($finalDisetujui)
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-success bg-surface flex items-center justify-center">
                                <div class="h-1 w-1 rounded-full bg-success"></div>
                            </div>
                            <div class="pl-3">
                                <p class="text-xs font-bold text-ink font-sans">Disahkan oleh Pimpinan</p>
                            </div>
                        @else
                            <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 border-border bg-surface flex items-center justify-center"></div>
                            <div class="pl-3">
                                <p class="text-xs font-medium text-muted font-sans">Verifikasi & Pengesahan Cuti</p>
                            </div>
                        @endif
                    </div>

                </div>
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
                                <span class="mt-0.5 inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold {{ $approval->action === 'APPROVE' ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning' }}">
                                    {{ $approval->action === 'APPROVE' ? 'Setuju' : 'Tunda' }}
                                </span>
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
                    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-2.5 text-sm text-success">{{ session('success') }}</div>
                @endif
                @error('komentar')
                    <div class="rounded-lg bg-danger/10 border border-danger/20 px-4 py-2.5 text-sm text-danger">{{ $message }}</div>
                @enderror

                @if ($canAct)
                    {{-- Form penundaan: alasan wajib, jadi ditampilkan terpisah saat approver memilih Tunda. --}}
                    <div x-show="showTunda" x-cloak class="rounded-lg border border-warning/25 bg-warning/5 p-4">
                        <form action="{{ route('cuti.postpone', $cuti->id) }}" method="POST" class="space-y-3">
                            @csrf
                            <label for="komentar-tunda" class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Alasan Penundaan <span class="text-danger">*</span></label>
                            <textarea id="komentar-tunda" name="komentar" rows="3" required minlength="5"
                                class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                placeholder="Jelaskan alasan penundaan agar pemohon dapat menindaklanjuti."></textarea>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="showTunda = false" class="rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-soft cursor-pointer">Batal</button>
                                <button type="submit" class="rounded-lg bg-warning px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer">Tunda Pengajuan</button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <a href="{{ route('cuti') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                        Kembali ke Daftar
                    </a>
                    @if ($canAct)
                        <button type="button" @click="showTunda = true" class="inline-flex items-center justify-center rounded-lg bg-warning px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer">
                            Tunda
                        </button>
                        <form action="{{ route('cuti.approve', $cuti->id) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-success px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer">
                                Setujui
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
