<x-layouts.app :title="$title">
    <x-ui.card padding="lg">
        <h2 class="text-xl font-bold text-ink mb-2">{{ $title }}</h2>
        <p class="text-sm text-muted">Halaman ini sedang dalam tahap pengembangan (Fase 2 / Integrasi).</p>
        
        <div class="mt-6 flex gap-3">
            <x-ui.button href="{{ route('dashboard') }}" variant="primary">
                Kembali ke Dashboard
            </x-ui.button>
        </div>
    </x-ui.card>
</x-layouts.app>
