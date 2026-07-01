@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class(['flex flex-col gap-3']) }}>
    @isset($breadcrumb)
        <nav class="flex flex-wrap items-center gap-1.5 text-xs text-muted font-sans" aria-label="Breadcrumb">
            {{ $breadcrumb }}
        </nav>
    @endisset

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-2xl font-bold text-ink font-sans">{{ $title }}</h2>

            @if ($description)
                <p class="mt-1 text-sm text-muted font-sans">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
