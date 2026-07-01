@props([
    'caption' => null,
])

<table {{ $attributes->class('w-full') }}>
    @if ($caption)
        <caption class="sr-only">{{ $caption }}</caption>
    @endif

    {{ $slot }}
</table>
