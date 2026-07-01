@props([
    'size' => 'md',
    'color' => 'current',
])

@php
    $sizes = [
        'xs' => 'w-3 h-3',
        'sm' => 'w-3.5 h-3.5',
        'md' => 'w-4 h-4',
        'lg' => 'w-6 h-6',
        'xl' => 'w-8 h-8',
    ];
    
    $colors = [
        'current' => 'text-current',
        'primary' => 'text-primary',
        'white' => 'text-white',
        'muted' => 'text-muted',
    ];
    
    $sizeClass = $sizes[(string) $size] ?? $sizes['md'];
    $colorClass = $colors[(string) $color] ?? $colors['current'];
@endphp

<svg {{ $attributes->merge(['class' => "animate-spin $sizeClass $colorClass"]) }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
</svg>
