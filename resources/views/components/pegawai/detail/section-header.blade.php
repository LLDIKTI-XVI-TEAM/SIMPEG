@props([
    'title',
    'description' => null,
])

<div
    data-employee-detail-section-header
    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
>
    <div class="min-w-0">
        <h3 class="text-sm font-bold text-ink font-sans">{{ $title }}</h3>

        @if($description)
            <p class="mt-0.5 text-xs text-muted font-sans">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
