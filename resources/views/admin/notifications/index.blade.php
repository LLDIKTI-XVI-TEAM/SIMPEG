<x-layouts.app title="Semua Notifikasi">
    
    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Pusat Notifikasi & Peringatan</h2>
            <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <span class="font-medium text-ink">Notifikasi</span>
            </nav>
        </div>
    </div>

        {{-- Log Cards --}}
        <div class="space-y-4">
            @forelse($notifications as $notif)
                @php
                    $typeLower = strtolower($notif->type);
                    $color = match(true) {
                        str_contains($typeLower, 'pensiun') => 'warning',
                        str_contains($typeLower, 'dokumen') => 'danger',
                        str_contains($typeLower, 'cuti') => 'info',
                        str_contains($typeLower, 'kenaikan_pangkat') => 'success',
                        default => 'primary',
                    };
                    $subText = $notif->data['label'] ?? 'Notifikasi Sistem';
                @endphp
                <div class="rounded-lg border border-border border-l-4 border-l-{{ $color }} bg-surface p-5 shadow-sm flex gap-4 items-start hover:bg-soft/20 transition-colors">
                    <div class="rounded-lg bg-{{ $color }}/10 p-2.5 text-{{ $color }} shrink-0 mt-0.5">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-{{ $color }} font-sans">{{ $subText }}</span>
                            <span class="text-[10px] text-muted font-sans font-mono shrink-0">{{ $notif->created_at->format('d F Y, H:i') }}</span>
                        </div>
                        <h3 class="text-sm font-bold text-ink font-sans leading-snug">{{ $notif->title }}</h3>
                        <p class="text-xs text-muted font-sans leading-relaxed">{{ $notif->body }}</p>
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-border bg-surface p-8 text-center shadow-sm">
                    <p class="text-sm text-muted">Belum ada notifikasi.</p>
                </div>
            @endforelse

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $notifications->onEachSide(1)->links('vendor.pagination.simpeg') }}
            </div>
        </div>
</x-layouts.app>