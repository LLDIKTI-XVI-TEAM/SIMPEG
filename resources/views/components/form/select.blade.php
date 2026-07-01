@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'value' => null,
    'options' => [],
    'placeholder' => null,
    'placeholderValue' => '',
    'required' => false,
    'disabled' => false,
    'help' => null,
    'errorKey' => null,
    'size' => 'md',
    'labelSrOnly' => false,
    'wrapperClass' => '',
])

@php
    $fieldId = $id ?? ($name ? str_replace(['.', '[', ']'], ['_', '_', ''], $name) : null);
    $fieldErrorKey = $errorKey ?? $name;
    $errorId = ($fieldId ?? 'select') . '_error';
    $helpId = ($fieldId ?? 'select') . '_help';
    $hasError = $fieldErrorKey ? $errors->has($fieldErrorKey) : false;
    $describedBy = trim(($help ? $helpId : '') . ' ' . ($hasError ? $errorId : ''));

    $sizes = [
        'sm' => 'pl-3 pr-10 py-1.5 text-xs',
        'md' => 'pl-4 pr-10 py-2 text-sm',
        'lg' => 'pl-4 pr-10 py-2.5 text-sm',
    ];

    $hasOldValue = $fieldErrorKey ? old($fieldErrorKey, null) !== null : false;
    $selectedValue = $fieldErrorKey ? old($fieldErrorKey, $value) : $value;
    $normalizedOptions = $options instanceof \Illuminate\Support\Collection ? $options->all() : $options;
    $slotHtml = (string) $slot;

    if ($slotHtml !== '' && ($hasOldValue || $value !== null)) {
        $slotHtml = preg_replace_callback('/<option\b([^>]*)>/i', function ($matches) use ($selectedValue) {
            $attributes = preg_replace('/\sselected(?:=(["\']).*?\1)?/i', '', $matches[1]);

            if (preg_match('/\svalue=(["\'])(.*?)\1/i', $attributes, $valueMatch)
                && (string) $selectedValue === html_entity_decode($valueMatch[2], ENT_QUOTES, 'UTF-8')) {
                $attributes .= ' selected';
            }

            return '<option' . $attributes . '>';
        }, $slotHtml);
    }
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

    <div class="relative">
        <select
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            @required(filter_var($required, FILTER_VALIDATE_BOOL))
            @disabled(filter_var($disabled, FILTER_VALIDATE_BOOL))
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'w-full appearance-none rounded-lg border bg-surface text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer',
                $sizes[$size] ?? $sizes['md'],
                'border-danger focus:border-danger focus:ring-danger/20' => $hasError,
                'border-border' => ! $hasError,
                'cursor-not-allowed opacity-70' => filter_var($disabled, FILTER_VALIDATE_BOOL),
            ]) }}
        >
            @if ($placeholder !== null)
                <option value="{{ $placeholderValue }}" @selected((string) $selectedValue === (string) $placeholderValue)>{{ $placeholder }}</option>
            @endif

            @foreach ($normalizedOptions as $optionValue => $optionLabel)
                @php
                    $optionDisabled = false;

                    if (is_array($optionLabel)) {
                        $optionDisabled = (bool) ($optionLabel['disabled'] ?? false);
                        $optionValue = $optionLabel['value'] ?? $optionValue;
                        $optionLabel = $optionLabel['label'] ?? $optionValue;
                    }
                @endphp
                <option value="{{ $optionValue }}" @selected((string) $selectedValue === (string) $optionValue) @disabled($optionDisabled)>{{ $optionLabel }}</option>
            @endforeach

            {!! $slotHtml !!}
        </select>

        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </div>
    </div>

    @if ($help)
        <p id="{{ $helpId }}" class="text-[11px] text-muted font-sans">{{ $help }}</p>
    @endif

    @if ($fieldErrorKey)
        @error($fieldErrorKey)
            <p id="{{ $errorId }}" class="text-[11px] text-danger font-semibold font-sans">{{ $message }}</p>
        @enderror
    @endif
</div>
