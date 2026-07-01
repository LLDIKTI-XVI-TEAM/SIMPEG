@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'as' => null,
    'disabled' => false,
    'fullWidth' => false,
])

@php
    $variant = match ($variant) {
        'secondary', 'outline' => 'secondary',
        'error' => 'danger',
        default => $variant,
    };

    $variants = [
        'primary' => 'border border-primary bg-primary text-white shadow-sm hover:opacity-90 focus:ring-primary/30',
        'secondary' => 'border border-border bg-surface text-primary shadow-sm hover:bg-soft hover:border-primary/30 focus:ring-primary/30',
        'muted' => 'border border-border bg-surface text-ink shadow-sm hover:bg-soft focus:ring-primary/20',
        'danger' => 'border border-danger/20 bg-surface text-danger shadow-sm hover:bg-danger/5 focus:ring-danger/20',
        'danger-solid' => 'border border-danger bg-danger text-white shadow-sm hover:opacity-90 focus:ring-danger/30',
        'success' => 'border border-success bg-success text-white shadow-sm hover:opacity-90 focus:ring-success/30',
        'warning' => 'border border-warning bg-warning text-white shadow-sm hover:opacity-90 focus:ring-warning/30',
        'ghost' => 'border border-transparent bg-transparent text-muted hover:bg-soft hover:text-ink focus:ring-primary/20',
        'link' => 'border border-transparent bg-transparent text-primary hover:underline focus:ring-primary/20',
    ];

    $sizes = [
        'xs' => 'gap-1.5 rounded px-3 py-2 text-xs',
        'sm' => 'gap-1.5 rounded-lg px-3.5 py-1.5 text-xs',
        'md' => 'gap-2 rounded-lg px-4 py-2.5 text-sm',
        'lg' => 'gap-2 rounded-lg px-5 py-3 text-sm',
        'icon' => 'h-8 w-8 rounded-lg p-0',
    ];

    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL);
    $tag = ($as === 'a' || $href) ? 'a' : 'button';
    $classes = [
        'inline-flex items-center justify-center font-semibold font-sans transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-50',
        $sizes[(string) $size] ?? $sizes['md'],
        $variants[(string) $variant] ?? $variants['primary'],
        'w-full' => filter_var($fullWidth, FILTER_VALIDATE_BOOL),
        'pointer-events-none opacity-50' => $tag === 'a' && $isDisabled,
    ];

    $title = $attributes->get('title');
@endphp

@if ($title)
<x-ui.tooltip text="{{ $title }}">
@endif

@if ($tag === 'a')
    <a
        @if ($href) href="{{ $href }}" @endif
        @if ($isDisabled) aria-disabled="true" tabindex="-1" @endif
        {{ $attributes->except('title')->class($classes) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @disabled($isDisabled)
        {{ $attributes->except('title')->class($classes) }}
    >
        {{ $slot }}
    </button>
@endif

@if ($title)
</x-ui.tooltip>
@endif
