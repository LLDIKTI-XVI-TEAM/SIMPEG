<x-layouts.app :title="$title">
    <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
        <h2 class="text-xl font-bold text-ink mb-2">{{ $title }}</h2>
        <p class="text-sm text-muted">Halaman ini sedang dalam tahap pengembangan (Fase 2 / Integrasi).</p>
        
        <div class="mt-6 flex gap-3">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 transition-colors">
                Kembali ke Dashboard
            </a>
        </div>
    </div>
</x-layouts.app>
