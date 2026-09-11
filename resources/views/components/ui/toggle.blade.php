@props([
    'name' => null,
    'id' => null,
    'disabled' => false,
])

@php
    $id = $id ?? $name ?? uniqid('toggle-');
@endphp

<label class="relative inline-flex items-center cursor-pointer select-none {{ $disabled ? 'opacity-50 cursor-not-allowed' : '' }}">
    <input type="checkbox" id="{{ $id }}" name="{{ $name }}" @disabled($disabled) {{ $attributes->except('class') }} class="sr-only peer">
    <div class="h-6 w-11 rounded-full bg-border transition-colors peer peer-checked:bg-primary peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary/20 peer-checked:after:translate-x-full peer-checked:after:border-white after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-border after:bg-white after:content-[''] after:transition-transform {{ $attributes->get('class') }}"></div>
</label>
