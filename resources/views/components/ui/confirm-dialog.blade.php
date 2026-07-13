@props([
    'id',
    'title' => 'Konfirmasi',
    'message' => 'Apakah Anda yakin ingin melanjutkan?',
    'confirmText' => 'Ya, Lanjutkan',
    'cancelText' => 'Batal',
    'variant' => 'primary', // primary, danger, warning
    'action' => null, // Optional form action URL
    'method' => 'POST', // Form method
])

@php
    $buttonVariants = [
        'primary' => 'bg-primary text-white shadow-sm hover:opacity-90',
        'danger'  => 'bg-danger text-white shadow-sm hover:opacity-90',
        'warning' => 'bg-warning text-white shadow-sm hover:opacity-90',
    ];
    $iconColors = [
        'primary' => 'text-primary bg-primary/10 ring-primary/5',
        'danger'  => 'text-danger bg-danger/10 ring-danger/5',
        'warning' => 'text-warning bg-warning/10 ring-warning/5',
    ];
    $confirmIcons = [
        'primary' => '<svg class="w-4 h-4 mr-2 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>',
        'danger'  => '<svg class="w-4 h-4 mr-2 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>',
        'warning' => '<svg class="w-4 h-4 mr-2 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>',
    ];
@endphp

<div x-data="{ open: false }"
     @open-confirm-{{ $id }}.window="open = true"
     @keydown.escape.window="open = false"
     class="inline-block">

    {{-- Trigger Slot --}}
    @isset($trigger)
        <div @click="open = true" class="inline-block">
            {{ $trigger }}
        </div>
    @endisset

    <template x-teleport="body">
        <div class="relative z-50">
            {{-- Backdrop --}}
            <div x-show="open"
         style="display: none;"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-ink/60 transition-opacity"></div>

    {{-- Modal Panel --}}
    <div x-show="open" style="display: none;" class="fixed inset-0 z-10 w-screen overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4 text-center sm:p-0">
            <div x-show="open"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="relative transform overflow-hidden rounded-xl bg-surface p-6 text-left shadow-xl transition-all w-full sm:max-w-md border border-border"
                 style="max-width: 400px; margin-left: auto; margin-right: auto;"
                 @click.away="open = false">

                {{-- Icon Top Center --}}
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full ring-8 {{ $iconColors[(string)$variant] ?? $iconColors['primary'] }}">
                    @if($variant === 'danger')
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    @elseif($variant === 'warning')
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    @else
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                        </svg>
                    @endif
                </div>

                {{-- Text Content --}}
                <div class="mt-5 text-center">
                    <h3 class="text-xl font-bold text-ink" id="modal-title-{{ $id }}">
                        {{ $title }}
                    </h3>
                    <div class="mt-2">
                        <p class="text-sm text-muted leading-relaxed font-sans">
                            {{ $message }}
                        </p>
                    </div>
                    @if($slot->isNotEmpty())
                        <div class="mt-4 text-left">
                            {{ $slot }}
                        </div>
                    @endif
                </div>

                {{-- Actions --}}
                <div class="mt-8 flex flex-col-reverse sm:flex-row justify-center gap-3">
                    <button type="button"
                            @click="open = false"
                            class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink hover:bg-soft transition-colors font-sans focus:outline-none shadow-sm">
                        <svg class="w-4 h-4 mr-2 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        {{ $cancelText }}
                    </button>

                    @if($action)
                        <form action="{{ $action }}" method="POST" class="inline-block w-full sm:w-auto"
                              x-data="{ submitting: false }"
                              @submit="submitting = true">
                            @csrf
                            @if(!in_array(strtoupper($method), ['GET', 'POST']))
                                @method($method)
                            @endif
                            <button type="submit"
                                    x-bind:disabled="submitting"
                                    class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold transition-all focus:outline-none disabled:opacity-75 disabled:cursor-wait {{ $buttonVariants[(string)$variant] ?? $buttonVariants['primary'] }}">
                                <span x-show="!submitting" class="inline-flex items-center">
                                    {!! $confirmIcons[(string)$variant] ?? $confirmIcons['primary'] !!}
                                    {{ $confirmText }}
                                </span>
                                <span x-show="submitting" style="display: none;" class="inline-flex items-center">
                                    <svg class="animate-spin h-4 w-4 mr-2 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Memproses...
                                </span>
                            </button>
                        </form>
                    @else
                        {{-- Dispatch event when confirm button clicked --}}
                        <div x-data="{ submitting: false }" class="inline-block w-full sm:w-auto">
                            <button type="button"
                                    @click="submitting = true; setTimeout(() => open = false, 300); $dispatch('confirm-{{ $id }}')"
                                    x-bind:disabled="submitting"
                                    class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold transition-all focus:outline-none disabled:opacity-75 disabled:cursor-wait {{ $buttonVariants[(string)$variant] ?? $buttonVariants['primary'] }}">
                                <span x-show="!submitting" class="inline-flex items-center">
                                    {!! $confirmIcons[(string)$variant] ?? $confirmIcons['primary'] !!}
                                    {{ $confirmText }}
                                </span>
                                <span x-show="submitting" style="display: none;" class="inline-flex items-center">
                                    <svg class="animate-spin h-4 w-4 mr-2 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Memproses...
                                </span>
                            </button>
                        </div>
                    @endif
                </div>

            </div>
        </div>
    </div>
        </div>
    </template>
</div>
