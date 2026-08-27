@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'value' => null,
    'required' => false,
    'disabled' => false,
    'help' => null,
    'errorKey' => null,
    'errorBag' => null,
    'useOldInput' => true,
    'size' => 'md',
    'labelSrOnly' => false,
    'wrapperClass' => '',
    'min' => null,
    'max' => null,
])

@php
    $fieldId = $id ?? ($name ? str_replace(['.', '[', ']'], ['_', '_', ''], $name) : null);
    $fieldErrorKey = $errorKey ?? $name;
    $fieldErrors = $errorBag ? $errors->getBag($errorBag) : $errors;
    $errorId = ($fieldId ?? 'date') . '_error';
    $helpId = ($fieldId ?? 'date') . '_help';
    $hasError = $fieldErrorKey ? $fieldErrors->has($fieldErrorKey) : false;
    $describedBy = trim(($help ? $helpId : '') . ' ' . ($hasError ? $errorId : ''));
    $shouldUseOldInput = filter_var($useOldInput, FILTER_VALIDATE_BOOL);

    $sizes = [
        'sm' => 'px-3 py-2 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-4 py-2.5 text-sm',
    ];

    $inputValue = $shouldUseOldInput && $fieldErrorKey ? old($fieldErrorKey, $value) : $value;
@endphp

<div @class(['space-y-1', $wrapperClass])>
    @if ($label)
        <label @if ($fieldId) for="{{ $fieldId }}" @endif class="{{ $labelSrOnly ? 'sr-only' : 'text-xs font-bold text-ink uppercase tracking-wider font-sans' }}">
            {{ $label }}
            @if (filter_var($required, FILTER_VALIDATE_BOOL))
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif

    <input
        @if ($fieldId) id="{{ $fieldId }}" @endif
        @if ($name) name="{{ $name }}" @endif
        type="date"
        @if ($inputValue !== null && $inputValue !== '') value="{{ $inputValue }}" @endif
        @if ($min) min="{{ $min }}" @endif
        @if ($max) max="{{ $max }}" @endif
        @required(filter_var($required, FILTER_VALIDATE_BOOL))
        @disabled(filter_var($disabled, FILTER_VALIDATE_BOOL))
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'w-full rounded-lg border bg-surface text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer',
            $sizes[$size] ?? $sizes['md'],
            'border-danger focus:border-danger focus:ring-danger/20' => $hasError,
            'border-border' => ! $hasError,
            'cursor-not-allowed opacity-70' => filter_var($disabled, FILTER_VALIDATE_BOOL),
        ]) }}
    >

    {{ $slot }}

    @if ($help)
        <p id="{{ $helpId }}" class="text-[11px] text-muted font-sans">{{ $help }}</p>
    @endif

    @if ($fieldErrorKey)
        @if ($hasError)
            <p id="{{ $errorId }}" class="text-[11px] text-danger font-semibold font-sans">{{ $fieldErrors->first($fieldErrorKey) }}</p>
        @endif
    @endif
</div>
