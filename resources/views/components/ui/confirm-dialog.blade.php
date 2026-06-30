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
        'primary' => 'text-primary bg-primary/10',
        'danger'  => 'text-danger bg-danger/10',
        'warning' => 'text-warning bg-warning/10',
    ];
@endphp

<div x-data="{ open: false }" 
     @open-confirm-{{ $id }}.window="open = true" 
     @keydown.escape.window="open = false" 
     class="relative z-50">
     
    {{-- Trigger Slot --}}
    @isset($trigger)
        <div @click="open = true" class="inline-block">
            {{ $trigger }}
        </div>
    @endisset

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
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full {{ $iconColors[(string)$variant] ?? $iconColors['primary'] }}">
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
                            class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold text-muted hover:bg-soft transition-colors font-sans focus:outline-none">
                        {{ $cancelText }}
                    </button>

                    @if($action)
                        <form action="{{ $action }}" method="POST" class="inline-block w-full sm:w-auto">
                            @csrf
                            @if(!in_array(strtoupper($method), ['GET', 'POST']))
                                @method($method)
                            @endif
                            <button type="submit" 
                                    class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold transition-colors focus:outline-none {{ $buttonVariants[(string)$variant] ?? $buttonVariants['primary'] }}">
                                {{ $confirmText }}
                            </button>
                        </form>
                    @else
                        {{-- Dispatch event when confirm button clicked --}}
                        <button type="button" 
                                @click="open = false; $dispatch('confirm-{{ $id }}')"
                                class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold transition-colors focus:outline-none {{ $buttonVariants[(string)$variant] ?? $buttonVariants['primary'] }}">
                            {{ $confirmText }}
                        </button>
                    @endif
                </div>
                
            </div>
        </div>
    </div>
</div>
