@props([
    'active',
    'click' => null,
    'type' => 'button',
])

<button
    type="{{ $type }}"
    role="tab"
    x-bind:aria-selected="{{ $active }} ? 'true' : 'false'"
    @if ($click) x-on:click="{{ $click }}" @endif
    x-bind:class="{{ $active }} ? 'bg-surface text-primary shadow-sm' : 'hover:text-ink'"
    {{ $attributes->class(['rounded-md px-3 py-1.5 transition cursor-pointer focus:outline-none font-sans']) }}
>
    {{ $slot }}
</button>
