@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class(['flex flex-col gap-3']) }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-2xl md:text-3xl font-extrabold text-ink font-sans tracking-tight">{{ $title }}</h2>

            @if ($description)
                <p class="mt-1.5 text-sm md:text-base text-muted font-sans font-medium">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>

    @isset($breadcrumb)
        <nav class="flex flex-wrap items-center gap-1.5 text-xs text-muted font-sans" aria-label="Breadcrumb">
            {{ $breadcrumb }}
        </nav>
    @endisset
</div>
