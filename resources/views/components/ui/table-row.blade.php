@props([
    'interactive' => false,
])

<tr {{ $attributes->class([
    'transition-colors duration-150 hover:bg-soft/60 group',
    'cursor-pointer' => filter_var($interactive, FILTER_VALIDATE_BOOL),
]) }}>
    {{ $slot }}
</tr>
