@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex w-full items-center justify-end overflow-x-auto pb-1 scrollbar-hide">
        <ul class="flex items-center gap-1.5 shrink-0">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <li class="flex h-8 w-8 shrink-0 cursor-not-allowed items-center justify-center rounded-lg border border-border bg-surface text-muted opacity-50 shadow-sm" aria-hidden="true">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                </li>
            @else
                <li class="shrink-0">
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-border bg-surface hover:bg-soft hover:text-ink text-ink transition focus:outline-none shadow-sm" aria-label="{{ __('pagination.previous') }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    </a>
                </li>
            @endif

            {{-- Pagination Elements --}}
            @php
                $currentPage = $paginator->currentPage();
                $lastPage = max(1, $paginator->lastPage());

                $customElements = [];
                if ($lastPage <= 7) {
                    $urls = [];
                    for ($i = 1; $i <= $lastPage; $i++) {
                        $urls[$i] = $paginator->url($i);
                    }
                    $customElements[] = $urls;
                } else {
                    if ($currentPage <= 4) {
                        $urls1 = [];
                        $end = max(5, $currentPage + 1);
                        for ($i = 1; $i <= $end; $i++) {
                            $urls1[$i] = $paginator->url($i);
                        }
                        $customElements[] = $urls1;
                        $customElements[] = '...';
                        $customElements[] = [$lastPage => $paginator->url($lastPage)];
                    } elseif ($currentPage >= $lastPage - 3) {
                        $customElements[] = [1 => $paginator->url(1)];
                        $customElements[] = '...';
                        $urls2 = [];
                        $start = min($currentPage - 1, $lastPage - 4);
                        for ($i = $start; $i <= $lastPage; $i++) {
                            $urls2[$i] = $paginator->url($i);
                        }
                        $customElements[] = $urls2;
                    } else {
                        $customElements[] = [1 => $paginator->url(1)];
                        $customElements[] = '...';
                        $urls3 = [];
                        for ($i = $currentPage - 1; $i <= $currentPage + 1; $i++) {
                            $urls3[$i] = $paginator->url($i);
                        }
                        $customElements[] = $urls3;
                        $customElements[] = '...';
                        $customElements[] = [$lastPage => $paginator->url($lastPage)];
                    }
                }
            @endphp
            @foreach ($customElements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <li class="flex items-end justify-center px-1 text-muted text-sm font-medium" aria-hidden="true">{{ $element }}</li>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border text-sm font-semibold transition font-sans focus:outline-none bg-primary text-white border-primary shadow-sm" aria-current="page">{{ $page }}</li>
                        @else
                            <li class="shrink-0">
                                <a href="{{ $url }}" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border text-sm font-semibold transition font-sans focus:outline-none bg-surface text-muted hover:bg-soft hover:text-ink border-border cursor-pointer shadow-sm" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                    {{ $page }}
                                </a>
                            </li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <li class="shrink-0">
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-border bg-surface hover:bg-soft hover:text-ink text-ink transition focus:outline-none shadow-sm" aria-label="{{ __('pagination.next') }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </li>
            @else
                <li class="flex h-8 w-8 shrink-0 cursor-not-allowed items-center justify-center rounded-lg border border-border bg-surface text-muted opacity-50 shadow-sm" aria-hidden="true">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </li>
            @endif
        </ul>
    </nav>
@endif
