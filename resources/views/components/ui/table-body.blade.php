@props([
    'divided' => true,
])

<tbody {{ $attributes->class([
    'divide-y divide-border' => filter_var($divided, FILTER_VALIDATE_BOOL),
]) }}>
    {{ $slot }}
</tbody>
