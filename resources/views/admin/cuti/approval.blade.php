<x-layouts.app title="Persetujuan Cuti">
    <div class="space-y-6">
        
        <x-ui.card padding="lg">
            <x-admin.page-header
                title="Persetujuan Cuti Pegawai"
                description="Tinjau dan lakukan keputusan setujui atau tunda atas permohonan cuti dari staf."
            >
                <x-slot:breadcrumb>
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Persetujuan</span>
                </x-slot:breadcrumb>
            </x-admin.page-header>
        </x-ui.card>

        {{-- Table Card --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-surface">
                <div>
                    <h3 class="text-sm font-semibold text-ink font-sans">Daftar Permohonan Menunggu</h3>
                    <p class="text-[10px] text-muted font-sans">Menampilkan dokumen permohonan yang perlu otorisasi Anda segera.</p>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head class="border-b border-border">
                        <x-ui.table-row>
                            <x-ui.table-th class="px-6 py-3.5">Pegawai</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5">Jenis Cuti</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5">Durasi</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5">Alasan</x-ui.table-th>
                            <x-ui.table-th align="right" class="px-6 py-3.5">Aksi Keputusan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($pending as $r)
                        <x-ui.table-row :interactive="true">
                            <x-ui.table-td padding="comfortable">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                        {{ strtoupper(substr($r->employee->nama_lengkap ?? 'P', 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-ink font-sans leading-tight">{{ $r->employee->nama_lengkap ?? 'Pegawai' }}</p>
                                        <p class="text-[10px] text-muted font-sans mt-0.5">Pengajuan: {{ $r->created_at?->translatedFormat('d M Y') }}</p>
                                    </div>
                                </div>
                            </x-ui.table-td>
                            <x-ui.table-td padding="comfortable" class="text-sm font-medium">{{ $r->jenisCuti->nama ?? '-' }}</x-ui.table-td>
                            <x-ui.table-td padding="comfortable" class="text-sm font-mono">{{ $r->jumlah_hari_kerja }} Hari Kerja<br><span class="text-[10px] text-muted font-sans">{{ $r->tanggal_mulai?->translatedFormat('d M') }} - {{ $r->tanggal_selesai?->translatedFormat('d M Y') }}</span></x-ui.table-td>
                            <x-ui.table-td title="{{ $r->alasan }}" padding="comfortable" class="text-muted max-w-xs truncate">{{ $r->alasan }}</x-ui.table-td>
                            <x-ui.table-td align="right" padding="comfortable">
                                <div class="flex items-center justify-end gap-2.5">
                                    {{-- Detail --}}
                                    <x-ui.button href="{{ route('cuti.show', $r->id) }}" variant="secondary" size="icon" title="Tinjau Detail" aria-label="Tinjau Detail">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </x-ui.button>

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
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @empty
                        <x-ui.table-row>
                            <x-ui.table-td colspan="5" align="center" class="px-6 py-8 text-muted">
                                Tidak ada pengajuan cuti yang memerlukan otorisasi persetujuan saat ini.
                            </x-ui.table-td>
                        </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
