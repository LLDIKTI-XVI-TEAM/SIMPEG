<x-layouts.app title="Persetujuan Cuti">
    <div class="space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5 bg-surface border border-border rounded-lg p-6 shadow-sm">
            <h2 class="text-2xl font-bold text-ink font-sans">Persetujuan Cuti Pegawai</h2>
            <p class="text-xs text-muted mt-0.5 font-sans">Tinjau dan lakukan keputusan setujui atau tunda atas permohonan cuti dari staf.</p>
            <nav class="mt-2 flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                <span>/</span>
                <span class="font-medium text-ink">Persetujuan</span>
            </nav>
        </div>

        {{-- Table Card --}}
        <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Daftar Permohonan Menunggu</h3>
                    <p class="text-[10px] text-muted font-sans">Menampilkan dokumen permohonan yang perlu otorisasi Anda segera.</p>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-soft border-b border-border">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Pegawai</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Jenis Cuti</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Durasi</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Alasan</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border">Aksi Keputusan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($pending as $r)
                        <tr class="transition-colors hover:bg-soft/30">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                        {{ strtoupper(substr($r->employee->nama_lengkap ?? 'P', 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-ink font-sans leading-tight">{{ $r->employee->nama_lengkap ?? 'Pegawai' }}</p>
                                        <p class="text-[10px] text-muted font-sans mt-0.5">Pengajuan: {{ $r->created_at?->translatedFormat('d M Y') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-ink font-sans">{{ $r->jenisCuti->nama ?? '-' }}</td>
                            <td class="px-6 py-4 text-sm text-ink font-mono">{{ $r->jumlah_hari_kerja }} Hari Kerja<br><span class="text-[10px] text-muted font-sans">{{ $r->tanggal_mulai?->translatedFormat('d M') }} - {{ $r->tanggal_selesai?->translatedFormat('d M Y') }}</span></td>
                            <td class="px-6 py-4 text-xs text-muted font-sans max-w-xs truncate" title="{{ $r->alasan }}">{{ $r->alasan }}</td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2.5">
                                    {{-- Detail --}}
                                    <a href="{{ route('cuti.show', $r->id) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Tinjau Detail" aria-label="Tinjau detail cuti {{ $r->jenisCuti->nama ?? 'pegawai' }}">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </a>

                                    {{-- Setuju --}}
                                    <form action="{{ route('cuti.approve', $r->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-success px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer">
                                            Setuju
                                        </button>
                                    </form>

                                    {{-- Tunda: butuh alasan, arahkan ke detail tempat form penundaan tersedia --}}
                                    <a href="{{ route('cuti.show', $r->id) }}" class="inline-flex items-center justify-center rounded-lg bg-warning px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer">
                                        Tunda
                                    </a>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-xs text-muted font-sans">
                                Tidak ada pengajuan cuti yang memerlukan otorisasi persetujuan saat ini.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
