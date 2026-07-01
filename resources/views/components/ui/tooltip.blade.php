@props([
    'text',
    'position' => 'top', // top, bottom, left, right
])

<div x-data="{ tooltipVisible: false }"
     @mouseenter="tooltipVisible = true"
     @mouseleave="tooltipVisible = false"
     {{ $attributes->merge(['class' => 'relative inline-flex']) }}>
    
    {{ $slot }}

    <div x-show="tooltipVisible"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 px-2.5 py-1.5 text-xs font-medium text-white bg-ink rounded shadow-sm whitespace-nowrap pointer-events-none"
         @if($position === 'top')
            style="bottom: 100%; left: 50%; transform: translate(-50%, -8px); display: none;"
         @elseif($position === 'bottom')
            style="top: 100%; left: 50%; transform: translate(-50%, 8px); display: none;"
         @elseif($position === 'left')
            style="top: 50%; right: 100%; transform: translate(-8px, -50%); display: none;"
         @elseif($position === 'right')
            style="top: 50%; left: 100%; transform: translate(8px, -50%); display: none;"
         @endif
         x-cloak>
         {{ $text }}
         
         {{-- Arrow --}}
         @if($position === 'top')
            <div class="absolute w-2 h-2 bg-ink transform rotate-45" style="bottom: -4px; left: calc(50% - 4px);"></div>
         @elseif($position === 'bottom')
            <div class="absolute w-2 h-2 bg-ink transform rotate-45" style="top: -4px; left: calc(50% - 4px);"></div>
         @elseif($position === 'left')
            <div class="absolute w-2 h-2 bg-ink transform rotate-45" style="right: -4px; top: calc(50% - 4px);"></div>
         @elseif($position === 'right')
            <div class="absolute w-2 h-2 bg-ink transform rotate-45" style="left: -4px; top: calc(50% - 4px);"></div>
         @endif
    </div>
</div>
