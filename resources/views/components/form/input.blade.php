@props([
    'name',
    'id' => null,
    'label' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
    'help' => null,
    'errorKey' => null,
    'errorBag' => null,
    'useOldInput' => true,
    'size' => 'md',
    'labelSrOnly' => false,
    'wrapperClass' => '',
])

@php
    $fieldId = $id ?? str_replace(['.', '[', ']'], ['_', '_', ''], $name);
    $fieldErrorKey = $errorKey ?? $name;
    $fieldErrors = $errorBag ? $errors->getBag($errorBag) : $errors;
    $errorId = $fieldId . '_error';
    $helpId = $fieldId . '_help';
    $hasError = $fieldErrors->has($fieldErrorKey);
    $describedBy = trim(($help ? $helpId : '') . ' ' . ($hasError ? $errorId : ''));
    $shouldUseOldInput = filter_var($useOldInput, FILTER_VALIDATE_BOOL);

    $sizes = [
        'sm' => 'px-3 py-2 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-4 py-2.5 text-sm',
    ];

    $inputValue = $shouldUseOldInput ? old($fieldErrorKey, $value) : $value;
@endphp

<div @class(['space-y-1', $wrapperClass])>
    @if ($label)
        <label for="{{ $fieldId }}" class="{{ $labelSrOnly ? 'sr-only' : 'text-xs font-bold text-ink uppercase tracking-wider font-sans' }}">
            {{ $label }}
            @if (filter_var($required, FILTER_VALIDATE_BOOL))
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif

    <div class="relative w-full">
        <input
            id="{{ $fieldId }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($type !== 'password') value="{{ $inputValue }}" @endif
            @required(filter_var($required, FILTER_VALIDATE_BOOL))
            @disabled(filter_var($disabled, FILTER_VALIDATE_BOOL))
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'w-full rounded-xl border bg-surface text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans transition-all duration-200',
                $sizes[$size] ?? $sizes['md'],
                'border-danger focus:border-danger focus:ring-danger/20' => $hasError,
                'border-border' => ! $hasError,
                'cursor-pointer' => $type === 'date' || $type === 'time',
                'cursor-not-allowed opacity-70' => filter_var($disabled, FILTER_VALIDATE_BOOL),
                isset($suffix) ? 'pr-20' : '',
            ]) }}
        >
        @if (isset($suffix))
            <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                {{ $suffix }}
            </div>
        @endif
    </div>

    {{ $slot }}

    @if ($help)
        <p id="{{ $helpId }}" class="text-[11px] text-muted font-sans">{{ $help }}</p>
    @endif

    @if ($hasError)
        <p id="{{ $errorId }}" class="text-[11px] text-danger font-semibold font-sans">{{ $fieldErrors->first($fieldErrorKey) }}</p>
    @endif
</div>
