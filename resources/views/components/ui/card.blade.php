@props([
    'as' => 'div',
    'padding' => 'md',
    'variant' => 'default',
    'shadow' => true,
])

@php
    $tag = in_array($as, ['div', 'section', 'article', 'form', 'a'], true) ? $as : 'div';

    $variants = [
        'default' => 'border border-border bg-surface',
        'soft' => 'border border-border bg-soft',
        'interactive' => 'border border-border bg-surface transition-colors hover:bg-soft/40',
    ];

    $paddings = [
        'none' => '',
        'sm' => 'p-4',
        'md' => 'p-5',
        'lg' => 'p-6',
    ];
@endphp

<{{ $tag }} {{ $attributes->class([
    'rounded-lg',
    $variants[$variant] ?? $variants['default'],
    $paddings[$padding] ?? $paddings['md'],
    'shadow-sm' => filter_var($shadow, FILTER_VALIDATE_BOOL),
]) }}>
    @isset($header)
        <div class="border-b border-border px-5 py-4">
            {{ $header }}
        </div>
    @endisset

    {{ $slot }}

    @isset($footer)
        <div class="border-t border-border px-5 py-4">
            {{ $footer }}
        </div>
    @endisset
</{{ $tag }}>
