@props([
    'variant' => 'underline',
    'label' => null,
])

@php
    $classes = [
        'underline' => 'flex gap-4 md:gap-6 overflow-x-auto border-b border-border pb-1 select-none',
        'sidebar' => 'space-y-1',
        'sidebar-soft' => 'space-y-1',
        'pills' => 'inline-flex flex-wrap gap-2',
    ];
@endphp

<div
    @if ($label) aria-label="{{ $label }}" @endif
    role="tablist"
    {{ $attributes->class($classes[$variant] ?? $classes['underline']) }}
>
    {{ $slot }}
</div>
