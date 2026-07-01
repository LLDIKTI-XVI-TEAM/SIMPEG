@props([
    'variant' => 'muted',
    'size' => 'sm',
    'dot' => false,
    'pill' => true,
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
            'box' => 'border-primary/20 bg-primary/10 text-primary',
            'dot' => 'bg-primary',
        ],
        'success' => [
            'box' => 'border-success/20 bg-success/10 text-success',
            'dot' => 'bg-success',
        ],
        'danger' => [
            'box' => 'border-danger/20 bg-danger/10 text-danger',
            'dot' => 'bg-danger',
        ],
        'warning' => [
            'box' => 'border-warning/25 bg-warning/10 text-warning',
            'dot' => 'bg-warning',
        ],
        'info' => [
            'box' => 'border-info/25 bg-info/10 text-info',
            'dot' => 'bg-info',
        ],
        'muted' => [
            'box' => 'border-border bg-soft text-muted',
            'dot' => 'bg-muted',
        ],
        'ink' => [
            'box' => 'border-border bg-surface text-ink',
            'dot' => 'bg-ink',
        ],
        'none' => [
            'box' => '',
            'dot' => 'bg-current',
        ],
    ];

    $sizes = [
        'xs' => 'px-1.5 py-0.5 text-[9px]',
        'sm' => 'px-2 py-0.5 text-[10px]',
        'md' => 'px-2.5 py-1 text-xs',
    ];

    $current = $variants[$variant] ?? $variants['muted'];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 border font-semibold font-sans leading-none',
    $sizes[$size] ?? $sizes['sm'],
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
