@props([
    'active',
    'click' => null,
    'href' => null,
    'variant' => 'underline',
    'type' => 'button',
])

@php
    $styles = [
        'underline' => [
            'base' => 'shrink-0 pb-2 text-xs md:text-sm transition-colors cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 font-sans',
            'active' => 'border-b-2 border-primary text-primary font-bold',
            'inactive' => 'text-muted hover:text-ink font-semibold',
        ],
        'sidebar' => [
            'base' => 'w-full rounded-lg px-4 py-3 text-left text-sm transition-colors cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 font-sans flex items-center justify-between gap-3',
            'active' => 'bg-primary text-white font-semibold',
            'inactive' => 'text-muted hover:bg-soft hover:text-ink font-medium',
        ],
        'sidebar-soft' => [
            'base' => 'w-full py-2.5 text-left text-sm transition-all cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 font-sans flex items-center justify-between gap-3',
            'active' => 'bg-soft text-primary font-semibold border-l-4 border-primary pl-2 rounded-r-lg',
            'inactive' => 'text-muted hover:bg-soft/50 hover:text-ink font-medium pl-3 rounded-lg',
        ],
        'pills' => [
            'base' => 'rounded-lg border px-3.5 py-2 text-sm transition-colors cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 font-sans',
            'active' => 'border-primary bg-primary text-white font-semibold',
            'inactive' => 'border-border bg-surface text-muted hover:bg-soft hover:text-ink font-medium',
        ],
    ];

    $style = $styles[$variant] ?? $styles['underline'];
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        role="tab"
        x-bind:aria-selected="{{ $active }} ? 'true' : 'false'"
        @if ($click) x-on:click="{{ $click }}" @endif
        x-bind:class="{{ $active }} ? '{{ $style['active'] }}' : '{{ $style['inactive'] }}'"
        {{ $attributes->class($style['base']) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        role="tab"
        x-bind:aria-selected="{{ $active }} ? 'true' : 'false'"
        @if ($click) x-on:click="{{ $click }}" @endif
        x-bind:class="{{ $active }} ? '{{ $style['active'] }}' : '{{ $style['inactive'] }}'"
        {{ $attributes->class($style['base']) }}
    >
        {{ $slot }}
    </button>
@endif
