@props([
    'as' => 'div',
    'href' => null,
    'label' => null,
    'value' => null,
    'valueBinding' => null,
    'unit' => null,
    'description' => null,
    'variant' => 'primary',
    'size' => 'md',
    'padding' => 'md',
    'surface' => 'default',
    'center' => false,
    'accent' => false,
    'shadow' => true,
    'labelClass' => '',
    'valueClass' => '',
    'unitClass' => '',
    'descriptionClass' => '',
])

@php
    $tag = $href ? 'a' : (in_array($as, ['div', 'section', 'article'], true) ? $as : 'div');
    $isCenter = filter_var($center, FILTER_VALIDATE_BOOL);
    $hasAccent = filter_var($accent, FILTER_VALIDATE_BOOL);

    $variants = [
        'primary' => [
            'text' => 'text-primary',
            'icon' => 'bg-primary/10 text-primary',
            'border' => 'border-b-primary',
            'surface' => 'border-primary/20 bg-primary/5',
        ],
        'success' => [
            'text' => 'text-success',
            'icon' => 'bg-success/10 text-success',
            'border' => 'border-b-success',
            'surface' => 'border-success/20 bg-success/5',
        ],
        'warning' => [
            'text' => 'text-warning',
            'icon' => 'bg-warning/10 text-warning',
            'border' => 'border-b-warning',
            'surface' => 'border-warning/20 bg-warning/5',
        ],
        'danger' => [
            'text' => 'text-danger',
            'icon' => 'bg-danger/10 text-danger',
            'border' => 'border-b-danger',
            'surface' => 'border-danger/20 bg-danger/5',
        ],
        'info' => [
            'text' => 'text-info',
            'icon' => 'bg-info/10 text-info',
            'border' => 'border-b-info',
            'surface' => 'border-info/20 bg-info/5',
        ],
        'muted' => [
            'text' => 'text-ink',
            'icon' => 'bg-soft text-muted',
            'border' => 'border-b-border',
            'surface' => 'border-border bg-soft/50',
        ],
    ];

    $sizes = [
        'sm' => [
            'value' => 'text-2xl',
            'iconBox' => 'rounded-lg p-2.5',
            'iconSize' => 'w-5 h-5',
        ],
        'md' => [
            'value' => 'text-2xl',
            'iconBox' => 'rounded-lg p-2.5',
            'iconSize' => 'w-6 h-6',
        ],
        'lg' => [
            'value' => 'text-3xl',
            'iconBox' => 'rounded-xl p-3',
            'iconSize' => 'w-6 h-6',
        ],
    ];

    $tone = $variants[$variant] ?? $variants['primary'];
    $dimension = $sizes[$size] ?? $sizes['md'];
    $paddings = [
        'none' => '',
        'sm' => 'p-4',
        'md' => 'p-5',
        'lg' => 'p-6',
    ];
    $surfaceClass = $surface === 'soft' ? $tone['surface'] : '';
    $labelClasses = trim('text-[10px] font-bold text-muted uppercase tracking-wider font-sans ' . $labelClass);
    $valueClasses = trim('mt-1 text-2xl font-extrabold leading-none ' . $dimension['value'] . ' ' . $tone['text'] . ' ' . $valueClass);
    $inlineValueClasses = trim(str_replace('mt-1', '', $valueClasses));
    $unitClasses = trim('text-sm text-muted font-sans ' . $unitClass);
    $descriptionClasses = trim('text-[10px] text-muted font-sans ' . $descriptionClass);
    $cardAttributes = $attributes->merge($href ? ['href' => $href] : []);
@endphp

<{{ $tag }}
    {{ $cardAttributes->class([
        'flex flex-col justify-between rounded-lg border bg-surface',
        $paddings[$padding] ?? $paddings['md'],
        'text-center' => $isCenter,
        'transition-colors hover:bg-soft/40 cursor-pointer' => $href,
        'border-border' => ! $surfaceClass,
        'shadow-sm' => filter_var($shadow, FILTER_VALIDATE_BOOL),
        'border-b-[3px]' => $hasAccent,
        $tone['border'] => $hasAccent,
        $surfaceClass,
    ]) }}
>
    @if ($isCenter)
        <div class="space-y-1">
            @isset($badge)
                {{ $badge }}
            @elseif ($label)
                <span class="{{ $labelClasses }}">{{ $label }}</span>
            @endif

            @if ($unit)
                <div class="mt-1 flex items-baseline justify-center gap-2">
                    <span class="{{ $inlineValueClasses }}" @if ($valueBinding) x-text="{{ $valueBinding }}" @endif>
                        @if (! $valueBinding)
                            {{ $value }}
                        @endif
                    </span>
                    <span class="{{ $unitClasses }}">{{ $unit }}</span>
                </div>
            @else
                <p class="{{ $valueClasses }}" @if ($valueBinding) x-text="{{ $valueBinding }}" @endif>
                    @if (! $valueBinding)
                        {{ $value }}
                    @endif
                </p>
            @endif
        </div>
    @else
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                @isset($badge)
                    {{ $badge }}
                @elseif ($label)
                    <p class="{{ $labelClasses }}">{{ $label }}</p>
                @endif

                @if ($unit)
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="{{ $inlineValueClasses }}" @if ($valueBinding) x-text="{{ $valueBinding }}" @endif>
                            @if (! $valueBinding)
                                {{ $value }}
                            @endif
                        </span>
                        <span class="{{ $unitClasses }}">{{ $unit }}</span>
                    </div>
                @else
                    <p class="{{ $valueClasses }}" @if ($valueBinding) x-text="{{ $valueBinding }}" @endif>
                        @if (! $valueBinding)
                            {{ $value }}
                        @endif
                    </p>
                @endif
            </div>

            @isset($icon)
                <div class="{{ $dimension['iconBox'] }} {{ $tone['icon'] }} shrink-0">
                    {{ $icon }}
                </div>
            @endisset
        </div>
    @endif

    @if ($description || isset($meta) || $slot->isNotEmpty())
        <div class="mt-4 pt-3 border-t border-border flex flex-col gap-1.5 {{ $descriptionClasses }}">
            @if ($description)
                <span>{{ $description }}</span>
            @endif

            @isset($meta)
                {{ $meta }}
            @endisset

            {{ $slot }}
        </div>
    @endif
</{{ $tag }}>
