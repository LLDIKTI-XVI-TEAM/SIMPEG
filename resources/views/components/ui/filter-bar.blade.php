@props([
    'searchModel' => null,
    'searchId' => null,
    'searchPlaceholder' => 'Cari...',
    'searchCols' => 'col-span-1 sm:col-span-2 lg:col-span-1',
    'searchLabel' => null,
])

<div class="rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 shadow-sm mb-4 print:hidden">
    @isset($header)
        <div class="flex items-center justify-between border-b border-border pb-3">
            <div>
                {{ $header }}
            </div>
            @isset($actions)
                {{ $actions }}
            @endisset
        </div>
    @endisset

    <div {{ $attributes->merge(['class' => 'grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4']) }}>
        @if($searchModel || $searchId)
            {{-- Search input --}}
            <div class="{{ $searchCols }} space-y-1.5 flex flex-col justify-end">
                @if($searchLabel)
                    <label class="text-[11px] font-bold text-muted font-sans uppercase tracking-wider">{{ $searchLabel }}</label>
                @endif
                <div class="flex h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 focus-within:ring-2 focus-within:ring-primary/20 focus-within:border-primary w-full">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input type="text" 
                        @if($searchId) id="{{ $searchId }}" @endif
                        @if($searchModel) x-model="{{ $searchModel }}" @endif
                        placeholder="{{ $searchPlaceholder }}"
                        class="h-full w-full bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>
            </div>
        @endif

        {{ $slot }}
    </div>
</div>
