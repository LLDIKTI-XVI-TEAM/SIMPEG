@props([
    'show' => null,
    'title' => null,
    'titleId' => null,
    'descriptionId' => null,
    'maxWidth' => 'md',
    'closeAction' => null,
    'panelClass' => '',
    'bodyClass' => 'p-6',
    'headerClass' => '',
    'footerClass' => '',
    'overlayClass' => '',
    'id' => null,
])

@php
    $modalTitleId = $titleId ?? 'modal_title_' . substr(md5((string) $title . spl_object_id($attributes)), 0, 8);

    $widths = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        '3xl' => 'max-w-3xl',
        '4xl' => 'max-w-4xl',
    ];
@endphp

<div
    @if ($id) id="{{ $id }}" @endif
    x-data="{
        modalWasOpen: false,
        modalTrigger: null,
        trapModalFocus(event) {
            const selector = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [contenteditable=true]';
            const focusable = Array.from(event.currentTarget.querySelectorAll(selector))
                .filter((element) => element.offsetParent !== null && element.getAttribute('aria-hidden') !== 'true');

            if (focusable.length === 0) {
                event.preventDefault();
                event.currentTarget.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const outside = !event.currentTarget.contains(document.activeElement);

            if (event.shiftKey && (outside || document.activeElement === first)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (outside || document.activeElement === last)) {
                event.preventDefault();
                first.focus();
            }
        },
    }"
    @if ($show) x-show="{{ $show }}" @endif
    @if ($show)
        x-effect="
            const modalIsOpen = Boolean({{ $show }});
            if (modalIsOpen && !modalWasOpen) {
                modalTrigger = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                    ? document.activeElement
                    : null;
                $nextTick(() => {
                    const errorTarget = $el.querySelector('[data-error-autofocus=true]');
                    const explicitTarget = $el.querySelector('[data-modal-initial-focus=true]');
                    const genericTarget = $el.querySelector('input:not([type=hidden]):not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled]), a[href]');
                    const initial = errorTarget ?? explicitTarget ?? genericTarget ?? $el;
                    initial.focus();
                });
            } else if (!modalIsOpen && modalWasOpen) {
                const trigger = modalTrigger;
                $nextTick(() => {
                    if (trigger?.isConnected) trigger.focus();
                });
            }
            modalWasOpen = modalIsOpen;
        "
    @endif
    @if ($show && $closeAction) @keydown.escape.window="if ({{ $show }}) { {{ $closeAction }} }" @endif
    @keydown.tab="trapModalFocus($event)"
    class="fixed inset-0 z-50 overflow-y-auto"
    style="display: none;"
    x-transition
    tabindex="-1"
    role="dialog"
    aria-modal="true"
    @if ($title) aria-labelledby="{{ $modalTitleId }}" @endif
    @if ($descriptionId) aria-describedby="{{ $descriptionId }}" @endif
>
    <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div
            class="fixed inset-0 bg-ink/50 transition-opacity {{ $overlayClass }}"
            @if ($closeAction) @click="{{ $closeAction }}" @endif
            aria-hidden="true"
        ></div>

        <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

        <div
            {{ $attributes->class([
                'relative z-10 inline-block w-full transform overflow-hidden rounded-lg border border-border bg-surface text-left align-bottom shadow-xl transition-all sm:my-8 sm:align-middle',
                $widths[$maxWidth] ?? $widths['md'],
                $panelClass,
            ]) }}
        >
            @if ($title || $closeAction)
                <div @class(['flex items-center justify-between border-b border-border px-6 py-4', $headerClass])>
                    @if ($title)
                        <h3 id="{{ $modalTitleId }}" class="text-sm font-bold text-ink font-sans">{{ $title }}</h3>
                    @else
                        <span></span>
                    @endif

                    @if ($closeAction)
                        <button type="button" @click="{{ $closeAction }}" class="rounded-lg p-1.5 text-muted transition-colors hover:bg-soft hover:text-ink focus:outline-none focus:ring-2 focus:ring-primary/20" aria-label="Tutup modal">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    @endif
                </div>
            @endif

            <div class="{{ $bodyClass }}">
                {{ $slot }}
            </div>

            @isset($footer)
                <div @class(['border-t border-border bg-soft px-6 py-4', $footerClass])>
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
