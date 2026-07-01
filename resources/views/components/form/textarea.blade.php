@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'value' => null,
    'placeholder' => null,
    'rows' => 3,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'help' => null,
    'errorKey' => null,
    'size' => 'md',
    'labelSrOnly' => false,
    'labelClass' => '',
    'wrapperClass' => '',
])

@php
    $fieldId = $id ?? ($name ? str_replace(['.', '[', ']'], ['_', '_', ''], $name) : null);
    $fieldErrorKey = $errorKey ?? $name;
    $errorId = ($fieldId ?? 'textarea') . '_error';
    $helpId = ($fieldId ?? 'textarea') . '_help';
    $hasError = $fieldErrorKey ? $errors->has($fieldErrorKey) : false;
    $describedBy = trim(($help ? $helpId : '') . ' ' . ($hasError ? $errorId : ''));

    $sizes = [
        'sm' => 'px-3 py-2 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-4 py-2.5 text-sm',
    ];

    $textareaValue = $fieldErrorKey ? old($fieldErrorKey, $value) : $value;
@endphp

<div @class(['space-y-1', $wrapperClass])>
    @if ($label)
        <label @if ($fieldId) for="{{ $fieldId }}" @endif class="{{ $labelSrOnly ? 'sr-only' : trim('text-xs font-bold text-ink uppercase tracking-wider font-sans ' . $labelClass) }}">
            {{ $label }}
            @if (filter_var($required, FILTER_VALIDATE_BOOL))
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif

    <textarea
        @if ($fieldId) id="{{ $fieldId }}" @endif
        @if ($name) name="{{ $name }}" @endif
        rows="{{ $rows }}"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @required(filter_var($required, FILTER_VALIDATE_BOOL))
        @disabled(filter_var($disabled, FILTER_VALIDATE_BOOL))
        @readonly(filter_var($readonly, FILTER_VALIDATE_BOOL))
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'w-full rounded-lg border bg-surface text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans resize-none',
            $sizes[$size] ?? $sizes['md'],
            'border-danger focus:border-danger focus:ring-danger/20' => $hasError,
            'border-border' => ! $hasError,
            'cursor-not-allowed opacity-70' => filter_var($disabled, FILTER_VALIDATE_BOOL),
        ]) }}
    >{{ $textareaValue }}</textarea>

    {{ $slot }}

    @if ($help)
        <p id="{{ $helpId }}" class="text-[11px] text-muted font-sans">{{ $help }}</p>
    @endif

    @if ($fieldErrorKey)
        @error($fieldErrorKey)
            <p id="{{ $errorId }}" class="text-[11px] text-danger font-semibold font-sans">{{ $message }}</p>
        @enderror
    @endif
</div>
