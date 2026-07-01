@props([
    'align' => 'left',
    'padding' => 'md',
])

@php
    $alignments = [
        'left' => 'text-left',
        'center' => 'text-center',
        'right' => 'text-right',
    ];

    $paddings = [
        'xs' => 'px-3 py-2',
        'sm' => 'px-4 py-2.5',
        'md' => 'px-4 py-3',
        'lg' => 'px-6 py-3',
        'wide' => 'px-5 py-3.5',
    ];
@endphp

<th {{ $attributes->class([
    $paddings[$padding] ?? $paddings['md'],
    $alignments[$align] ?? $alignments['left'],
    'text-xs font-semibold uppercase tracking-wide text-muted font-sans border-b border-border',
]) }}>
    {{ $slot }}
</th>
