@props([
    'current' => 'currentPage',
    'total' => 'totalPages',
    'action' => null,
])

@php
    $currentStr = (string) $current;
    $totalStr = (string) $total;
    $actionStr = $action ? (string) $action : null;

    $prevAction = $actionStr ? str_replace('page', $currentStr.' - 1', $actionStr) : "if ({$currentStr} > 1) {$currentStr}--";
    $nextAction = $actionStr ? str_replace('page', $currentStr.' + 1', $actionStr) : "if ({$currentStr} < {$totalStr}) {$currentStr}++";
    $pageAction = $actionStr ? $actionStr : "{$currentStr} = page";
@endphp

<div class="flex items-center gap-1.5" x-show="{{ $total }} > 1" style="display: none;">
    {{-- Prev --}}
    <button type="button"
            @click="{{ $prevAction }}"
            :disabled="{{ $current }} === 1"
            :class="{{ $current }} === 1 ? 'opacity-50 cursor-not-allowed text-muted' : 'hover:bg-soft hover:text-ink text-ink cursor-pointer'"
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border bg-surface transition focus:outline-none shadow-sm">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
    </button>

    {{-- Pages --}}
    <template x-for="page in {{ $total }}" :key="page">
        <div class="flex items-center gap-1.5">
            {{-- Ellipsis Before --}}
            <span x-show="page > 2 && page === {{ $current }} - 2" class="flex items-end justify-center px-1 text-muted text-sm font-medium">...</span>

            {{-- Page Number --}}
            <button type="button"
                    @click="{{ $pageAction }}"
                    x-show="page === 1 || page === {{ $total }} || Math.abs(page - {{ $current }}) <= 1"
                    :class="{{ $current }} === page ? 'bg-primary text-white border-primary shadow-sm' : 'bg-surface text-muted hover:bg-soft hover:text-ink border-border cursor-pointer shadow-sm'"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border text-sm font-semibold transition font-sans focus:outline-none"
                    x-text="page">
            </button>

            {{-- Ellipsis After --}}
            <span x-show="page < {{ $total }} - 1 && page === {{ $current }} + 2" class="flex items-end justify-center px-1 text-muted text-sm font-medium">...</span>
        </div>
    </template>

    {{-- Next --}}
    <button type="button"
            @click="{{ $nextAction }}"
            :disabled="{{ $current }} >= {{ $total }}"
            :class="{{ $current }} >= {{ $total }} ? 'opacity-50 cursor-not-allowed text-muted' : 'hover:bg-soft hover:text-ink text-ink cursor-pointer'"
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border bg-surface transition focus:outline-none shadow-sm">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
    </button>
</div>
