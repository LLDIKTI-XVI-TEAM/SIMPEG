@props([
    'label' => null,
])

<div
    @if ($label) aria-label="{{ $label }}" @endif
    role="tablist"
    {{ $attributes->class(['inline-flex rounded-lg border border-border bg-soft p-1 text-xs font-semibold text-muted']) }}
>
    {{ $slot }}
</div>
