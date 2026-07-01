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
        'sm' => 'px-4 py-3',
        'md' => 'px-4 py-3.5',
        'wide' => 'px-5 py-3.5',
        'lg' => 'px-5 py-4',
        'xl' => 'px-6 py-5',
        'comfortable' => 'px-6 py-4',
    ];
@endphp

<td {{ $attributes->class([
    $paddings[$padding] ?? $paddings['md'],
    $alignments[$align] ?? $alignments['left'],
    'text-xs text-ink font-sans',
]) }}>
    {{ $slot }}
</td>
