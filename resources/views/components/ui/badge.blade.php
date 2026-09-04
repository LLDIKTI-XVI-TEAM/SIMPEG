@props([
    'variant' => 'muted',
    'size' => 'md',
    'dot' => false,
    'pill' => false,
    'uppercase' => false,
])

@php
    $variant = match ($variant) {
        'error' => 'danger',
        'secondary' => 'muted',
        default => $variant,
    };

    $variants = [
        'primary' => [
            'box' => 'bg-primary/10 text-primary',
            'dot' => 'bg-primary',
        ],
        'success' => [
            'box' => 'bg-success/10 text-success',
            'dot' => 'bg-success',
        ],
        'danger' => [
            'box' => 'bg-danger/10 text-danger',
            'dot' => 'bg-danger',
        ],
        'warning' => [
            'box' => 'bg-warning/10 text-warning',
            'dot' => 'bg-warning',
        ],
        'orange' => [
            'box' => 'bg-orange/10 text-orange',
            'dot' => 'bg-orange',
        ],
        'info' => [
            'box' => 'bg-info/10 text-info',
            'dot' => 'bg-info',
        ],
        'muted' => [
            'box' => 'bg-soft text-muted',
            'dot' => 'bg-muted',
        ],
        'ink' => [
            'box' => 'bg-surface text-ink',
            'dot' => 'bg-ink',
        ],
        'none' => [
            'box' => '',
            'dot' => 'bg-current',
        ],
    ];

    $sizes = [
        // Semua badge yang memuat teks status mengikuti kontrak tipografi text-xs.
        // Ukuran hanya membedakan kepadatan ruang, bukan keterbacaan teks.
        'xs' => 'px-1.5 py-0.5 text-xs',
        'sm' => 'px-2 py-0.5 text-xs',
        'md' => 'px-2.5 py-1 text-xs',
    ];

    $current = $variants[$variant] ?? $variants['muted'];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 font-semibold font-sans leading-none whitespace-nowrap',
    $sizes[$size] ?? $sizes['md'],
    $current['box'],
    'rounded-full' => filter_var($pill, FILTER_VALIDATE_BOOL),
    'rounded-md' => ! filter_var($pill, FILTER_VALIDATE_BOOL),
    'uppercase tracking-wider' => filter_var($uppercase, FILTER_VALIDATE_BOOL),
]) }}>
    @if (filter_var($dot, FILTER_VALIDATE_BOOL))
        <span class="h-1.5 w-1.5 rounded-full {{ $current['dot'] }}" aria-hidden="true"></span>
    @endif

    {{ $slot }}
</span>
