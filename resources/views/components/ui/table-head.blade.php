@props([
    'variant' => 'default',
])

@php
    $variants = [
        'default' => 'bg-soft',
        'muted' => 'bg-soft/40',
        'plain' => '',
    ];
@endphp

<thead {{ $attributes->class($variants[$variant] ?? $variants['default']) }}>
    {{ $slot }}
</thead>
