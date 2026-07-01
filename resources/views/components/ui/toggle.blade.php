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
    <div class="w-11 h-6 bg-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary {{ $attributes->get('class') }}"></div>
</label>
