@props([
    'items' => []
])

<nav class="mt-1 flex items-center gap-1.5 text-xs text-muted font-sans">
    @foreach($items as $index => $item)
        @if(isset($item['url']) && $index < count($items) - 1)
            <a href="{{ $item['url'] }}" class="transition-colors hover:text-ink">{{ $item['label'] }}</a>
            <span>/</span>
        @else
            <span class="font-medium text-ink">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
