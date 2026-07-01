@props([
    'variant' => 'success', // success, danger, warning, muted
    'title' => '',
    'description' => '',
    'pulse' => false,
])

@php
    $variants = [
        'success' => [
            'border' => 'border-success',
            'bg' => 'bg-success',
            'text' => 'text-ink',
        ],
        'danger' => [
            'border' => 'border-danger',
            'bg' => 'bg-danger',
            'text' => 'text-danger',
        ],
        'warning' => [
            'border' => 'border-warning',
            'bg' => 'bg-warning',
            'text' => 'text-warning',
        ],
        'muted' => [
            'border' => 'border-border',
            'bg' => 'bg-transparent',
            'text' => 'text-muted',
        ],
    ];

    $v = $variants[$variant] ?? $variants['success'];
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }}>
    <div class="absolute -left-[22px] top-1.5 h-3 w-3 rounded-full border-2 {{ $v['border'] }} bg-surface flex items-center justify-center">
        @if($variant !== 'muted')
            <div class="h-1 w-1 rounded-full {{ $v['bg'] }} {{ $pulse ? 'animate-pulse' : '' }}"></div>
        @endif
    </div>
    <div class="pl-3">
        @if($title)
            <p class="text-xs {{ $variant === 'muted' ? 'font-medium' : 'font-bold' }} {{ $v['text'] }} font-sans">{{ $title }}</p>
        @endif
        @if($description)
            <p class="text-[10px] text-muted font-sans mt-0.5">{{ $description }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
