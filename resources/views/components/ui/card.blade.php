@props([
    'as' => 'div',
    'padding' => 'md',
    'variant' => 'default',
    'shadow' => true,
])

@php
    $tag = in_array($as, ['div', 'section', 'article', 'form', 'a'], true) ? $as : 'div';

    $variants = [
        'default' => 'border border-border bg-surface transition-colors duration-200',
        'soft' => 'border border-border bg-soft transition-colors duration-200',
        'interactive' => 'cursor-pointer border border-border bg-surface transition-[background-color,box-shadow,transform] duration-200 hover:-translate-y-1 hover:bg-soft/40 hover:shadow-lg',
    ];

    $paddings = [
        'none' => '',
        'sm' => 'p-4',
        'md' => 'p-5',
        'lg' => 'p-6',
    ];
@endphp

<{{ $tag }} {{ $attributes->class([
    'rounded-2xl',
    $variants[$variant] ?? $variants['default'],
    $paddings[$padding] ?? $paddings['md'],
    'shadow-md shadow-slate-200/50' => filter_var($shadow, FILTER_VALIDATE_BOOL),
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
