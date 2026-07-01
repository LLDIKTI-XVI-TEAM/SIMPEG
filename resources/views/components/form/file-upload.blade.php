@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'accept' => null,
    'required' => false,
    'disabled' => false,
    'help' => null,
    'errorKey' => null,
    'mode' => 'inline',
    'size' => 'md',
    'wrapperClass' => '',
    'inputClass' => '',
    'xRef' => null,
    'change' => null,
    'title' => 'Klik atau seret berkas di sini',
    'hint' => null,
    'labelSrOnly' => false,
])

@php
    $fieldId = $id ?? ($name ? str_replace(['.', '[', ']'], ['_', '_', ''], $name) : null);
    $fieldErrorKey = $errorKey ?? $name;
    $errorId = $fieldId ? $fieldId . '_error' : null;
    $helpId = $fieldId ? $fieldId . '_help' : null;
    $hasError = $fieldErrorKey ? $errors->has($fieldErrorKey) : false;
    $describedBy = trim(($help && $helpId ? $helpId : '') . ' ' . ($hasError && $errorId ? $errorId : ''));
    $isRequired = filter_var($required, FILTER_VALIDATE_BOOL);
    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL);

    $inputSizes = [
        'sm' => 'px-3 py-1.5 text-xs file:mr-2 file:px-2 file:py-1 file:text-xs',
        'md' => 'px-3 py-2 text-xs file:mr-3 file:px-3 file:py-1.5 file:text-xs',
    ];

    $baseInputClass = trim(implode(' ', [
        'w-full rounded-lg border bg-surface text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans',
        'file:rounded-lg file:border-0 file:font-bold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 file:transition file:cursor-pointer',
        $inputSizes[$size] ?? $inputSizes['md'],
        $hasError ? 'border-danger focus:border-danger focus:ring-danger/20' : 'border-border',
        $isDisabled ? 'cursor-not-allowed opacity-70' : '',
        $inputClass,
    ]));

    $dropzoneClass = trim(implode(' ', [
        'border-2 border-dashed rounded-lg text-center relative hover:border-primary transition group',
        $size === 'sm' ? 'p-6' : 'p-10',
        $hasError ? 'border-danger bg-danger/5' : 'border-border bg-soft/50',
    ]));
@endphp

<div @class(['space-y-1', $wrapperClass])>
    @if ($label)
        <label @if ($fieldId) for="{{ $fieldId }}" @endif class="{{ $labelSrOnly ? 'sr-only' : 'text-xs font-bold text-ink uppercase tracking-wider font-sans block' }}">
            {{ $label }}
            @if ($isRequired)
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif

    @if ($mode === 'dropzone')
        <div {{ $attributes->class([$dropzoneClass]) }}>
            <input
                type="file"
                @if ($fieldId) id="{{ $fieldId }}" @endif
                @if ($name) name="{{ $name }}" @endif
                @if ($accept) accept="{{ $accept }}" @endif
                @if ($xRef) x-ref="{{ $xRef }}" @endif
                @if ($change) x-on:change="{{ $change }}" @endif
                @required($isRequired)
                @disabled($isDisabled)
                @if ($hasError) aria-invalid="true" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                class="absolute inset-0 opacity-0 cursor-pointer w-full h-full"
            >

            @isset($icon)
                {{ $icon }}
            @else
                <svg class="mx-auto h-12 w-12 text-muted group-hover:text-primary transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
            @endisset

            <p class="text-xs text-ink font-semibold mt-2 font-sans">{{ $title }}</p>

            @if ($hint)
                <p class="text-[10px] text-muted mt-1 font-sans">{{ $hint }}</p>
            @endif

            {{ $slot }}
        </div>
    @else
        <input
            type="file"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            @if ($accept) accept="{{ $accept }}" @endif
            @if ($xRef) x-ref="{{ $xRef }}" @endif
            @if ($change) x-on:change="{{ $change }}" @endif
            @required($isRequired)
            @disabled($isDisabled)
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([$baseInputClass]) }}
        >

        {{ $slot }}
    @endif

    @if ($help)
        <p @if ($helpId) id="{{ $helpId }}" @endif class="text-[10px] text-muted mt-1 font-sans">{{ $help }}</p>
    @endif

    @if ($hasError)
        <p @if ($errorId) id="{{ $errorId }}" @endif class="text-[11px] text-danger font-semibold font-sans">
            {{ $errors->first($fieldErrorKey) }}
        </p>
    @endif
</div>
