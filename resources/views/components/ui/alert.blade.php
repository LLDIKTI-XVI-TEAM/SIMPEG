@props([
    'variant' => 'info',
    'title' => null,
    'dismissible' => false,
    'dismissAction' => null,
    'size' => 'md',
])

@php
    $variant = $variant === 'error' ? 'danger' : $variant;

    $variants = [
        'success' => [
            'box' => 'border-success/20 bg-success/10 text-success',
            'icon' => 'text-success',
            'path' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        ],
        'danger' => [
            'box' => 'border-danger/20 bg-danger/10 text-danger',
            'icon' => 'text-danger',
            'path' => 'm9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        ],
        'warning' => [
            'box' => 'border-warning/20 bg-warning/10 text-warning-dark',
            'icon' => 'text-warning-dark',
            'path' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
        ],
        'info' => [
            'box' => 'border-info/20 bg-info/10 text-info',
            'icon' => 'text-info',
            'path' => 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
        ],
    ];

    $sizes = [
        'sm' => 'px-3 py-2 text-xs',
        'md' => 'px-4 py-3 text-sm',
        'lg' => 'px-4 py-4 text-sm',
    ];

    $current = $variants[$variant] ?? $variants['info'];
@endphp

<div
    role="alert"
    {{ $attributes->class([
        'flex gap-3 rounded-lg border font-sans',
        $sizes[$size] ?? $sizes['md'],
        $current['box'],
    ]) }}
>
    <svg class="h-4 w-4 shrink-0 {{ $current['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $current['path'] }}" />
    </svg>

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold leading-5">{{ $title }}</p>
        @endif

        @if (trim((string) $slot) !== '')
            <div @class(['leading-5', 'mt-1' => $title])>
                {{ $slot }}
            </div>
        @endif
    </div>

    @if (filter_var($dismissible, FILTER_VALIDATE_BOOL))
        <button
            type="button"
            @if ($dismissAction) @click="{{ $dismissAction }}" @endif
            class="-m-1 rounded-md p-1 transition hover:bg-ink/5 focus:outline-none focus:ring-2 focus:ring-primary/20"
            aria-label="Tutup alert"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    @endif
</div>
