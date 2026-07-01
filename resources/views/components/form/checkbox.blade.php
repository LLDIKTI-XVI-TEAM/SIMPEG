@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'value' => '1',
    'checked' => false,
    'required' => false,
    'disabled' => false,
    'help' => null,
    'errorKey' => null,
    'size' => 'md',
    'labelClass' => '',
    'wrapperClass' => '',
    'inputClass' => '',
    'srOnly' => false,
])

@php
    $fieldId = $id ?? ($name ? str_replace(['.', '[', ']'], ['_', '_', ''], $name) : null);
    $fieldErrorKey = $errorKey ?? $name;
    $errorId = ($fieldId ?? 'checkbox') . '_error';
    $helpId = ($fieldId ?? 'checkbox') . '_help';
    $hasError = $fieldErrorKey ? $errors->has($fieldErrorKey) : false;
    $describedBy = trim(($help ? $helpId : '') . ' ' . ($hasError ? $errorId : ''));
    $isChecked = $fieldErrorKey ? old($fieldErrorKey, $checked) : $checked;

    $sizes = [
        'sm' => 'h-4 w-4',
        'md' => 'h-4.5 w-4.5',
        'lg' => 'h-5 w-5',
    ];
@endphp

<div @class(['space-y-1', $wrapperClass])>
    <label @if ($fieldId) for="{{ $fieldId }}" @endif class="{{ trim('inline-flex items-start gap-2.5 font-sans ' . $labelClass) }}">
        <input
            type="checkbox"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            value="{{ $value }}"
            @checked(filter_var($isChecked, FILTER_VALIDATE_BOOL))
            @required(filter_var($required, FILTER_VALIDATE_BOOL))
            @disabled(filter_var($disabled, FILTER_VALIDATE_BOOL))
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'rounded border-border text-primary focus:ring-primary/20 cursor-pointer',
                $sizes[$size] ?? $sizes['md'],
                'sr-only' => filter_var($srOnly, FILTER_VALIDATE_BOOL),
                'border-danger focus:ring-danger/20' => $hasError,
                'cursor-not-allowed opacity-70' => filter_var($disabled, FILTER_VALIDATE_BOOL),
                $inputClass,
            ]) }}
        >

        @if ($label || $slot->isNotEmpty())
            <span class="text-xs text-ink">
                {{ $label }}
                {{ $slot }}
                @if (filter_var($required, FILTER_VALIDATE_BOOL))
                    <span class="text-danger">*</span>
                @endif
            </span>
        @endif
    </label>

    @if ($help)
        <p id="{{ $helpId }}" class="text-[11px] text-muted font-sans">{{ $help }}</p>
    @endif

    @if ($fieldErrorKey)
        @error($fieldErrorKey)
            <p id="{{ $errorId }}" class="text-[11px] text-danger font-semibold font-sans">{{ $message }}</p>
        @enderror
    @endif
</div>
