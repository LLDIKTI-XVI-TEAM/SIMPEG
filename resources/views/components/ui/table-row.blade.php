@props([
    'interactive' => false,
])

<tr {{ $attributes->class([
    'transition-colors hover:bg-soft/30' => filter_var($interactive, FILTER_VALIDATE_BOOL),
]) }}>
    {{ $slot }}
</tr>
