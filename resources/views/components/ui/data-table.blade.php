@props([
    'rows',
    'meta',
    'columns',
    'fetchPage',
    'isLoading' => 'isLoading',
    'perPage' => 'perPage',
    'setPerPage' => 'setPerPage($event.target.value)',
    'sort' => null,
    'direction' => null,
    'setSort' => null,
    'searchModel' => null,
    'searchPlaceholder' => 'Cari...',
    'emptyTitle' => 'Tidak ada data',
    'emptyIcon' => 'search',
    'caption' => null,
    'colspanCount' => null,
    'checkAllId' => null,   // Jika diisi, kolom dengan key='check' akan menampilkan checkbox select-all
    'filterClass' => null,
])

@php
    // Auto-calculate colspan if not provided
    $colspanCount = $colspanCount ?? count($columns);
@endphp

<div class="space-y-4">
    {{-- Top Bar: Search and Extra Filters --}}
    @if($searchModel || isset($filters))
        <x-ui.filter-bar
            :searchModel="$searchModel"
            :searchPlaceholder="$searchPlaceholder"
            :class="$filterClass"
        >
            {{ $filters ?? '' }}
        </x-ui.filter-bar>
    @endif

    {{-- Main Table Card --}}
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table :caption="$caption">
                <x-ui.table-head>
                    <x-ui.table-row>
                        @foreach ($columns as $col)
                            <x-ui.table-th
                                align="{{ $col['align'] ?? 'left' }}"
                                class="{{ $col['width'] ?? '' }}"
                            >
                                @if ($checkAllId && ($col['key'] ?? '') === 'check')
                                    {{-- Select-all checkbox --}}
                                    <input
                                        type="checkbox"
                                        id="{{ $checkAllId }}"
                                        class="h-4 w-4 rounded border-border text-primary focus:ring-primary/20 cursor-pointer"
                                        title="Pilih semua"
                                        aria-label="Pilih semua baris"
                                    >
                                @elseif (!empty($col['sortable']) && $setSort && $sort && $direction)
                                    <button
                                        @click="{{ str_replace('col', sprintf("'%s'", $col['key']), $setSort) }}"
                                        type="button"
                                        class="flex items-center gap-1.5 hover:text-ink focus:outline-none w-full {{ ($col['align'] ?? 'left') === 'right' ? 'justify-end' : (($col['align'] ?? 'left') === 'center' ? 'justify-center' : 'justify-start') }}"
                                    >
                                        {{ $col['label'] }}

                                        {{-- Sort Icons --}}
                                        <div class="flex flex-col opacity-40" :class="{ 'opacity-100 text-primary': {{ $sort }} === '{{ $col['key'] }}' }">
                                            <svg class="w-3 h-3 -mb-1" :class="{ 'opacity-30': {{ $sort }} === '{{ $col['key'] }}' && {{ $direction }} !== 'asc' }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path></svg>
                                            <svg class="w-3 h-3" :class="{ 'opacity-30': {{ $sort }} === '{{ $col['key'] }}' && {{ $direction }} !== 'desc' }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                        </div>
                                    </button>
                                @else
                                    {{ $col['label'] }}
                                @endif
                            </x-ui.table-th>
                        @endforeach
                    </x-ui.table-row>
                </x-ui.table-head>

                <x-ui.table-body>
                    {{-- Loading State --}}
                    <template x-if="{{ $isLoading }}">
                        <tr>
                            <td colspan="{{ $colspanCount }}">
                                <div class="flex justify-center items-center py-12">
                                    <x-ui.loading size="lg" color="primary" />
                                </div>
                            </td>
                        </tr>
                    </template>

                    @if ($slot->isNotEmpty())
                        {{-- Custom body slot: caller renders its own x-for rows --}}
                        {{ $slot }}
                    @else
                        {{-- Default: auto-render rows from $rows + column definitions --}}
                        <template x-if="!{{ $isLoading }} && {{ $rows }} && {{ $rows }}.length > 0">
                            <template x-for="(row, index) in {{ $rows }}" :key="row.id || index">
                                <x-ui.table-row class="hover:bg-soft/40 transition-colors">
                                    @foreach ($columns as $col)
                                        <x-ui.table-td align="{{ $col['align'] ?? 'left' }}">
                                            @if (!empty($col['slot']))
                                                @php
                                                    $slotName = 'col_' . $col['key'];
                                                    $slotNameDash = 'col-' . $col['key'];
                                                @endphp
                                                {{ ${$slotName} ?? ${$slotNameDash} ?? '' }}
                                            @else
                                                <span x-text="row.{{ $col['key'] }}"></span>
                                            @endif
                                        </x-ui.table-td>
                                    @endforeach
                                </x-ui.table-row>
                            </template>
                        </template>
                    @endif

                    {{-- Empty State (works for both default and custom slot modes) --}}
                    <template x-if="!{{ $isLoading }} && (!{{ $rows }} || {{ $rows }}.length === 0)">
                        <tr>
                            <td colspan="{{ $colspanCount }}">
                                <x-ui.empty-state
                                    icon="{{ $emptyIcon }}"
                                    title="{{ $emptyTitle }}"
                                    message="Coba sesuaikan filter atau kata kunci pencarian Anda."
                                />
                            </td>
                        </tr>
                    </template>
                </x-ui.table-body>
            </x-ui.table>
        </div>

        {{-- Footer: Pagination & Meta --}}
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
            <div class="flex items-center gap-3 text-sm text-muted">
                <span class="whitespace-nowrap">Tampilkan</span>
                <select
                    @change="{{ $setPerPage }}"
                    class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center"
                >
                    <option value="10" :selected="{{ $perPage }} == 10">10</option>
                    <option value="25" :selected="{{ $perPage }} == 25">25</option>
                    <option value="50" :selected="{{ $perPage }} == 50">50</option>
                    <option value="100" :selected="{{ $perPage }} == 100">100</option>
                </select>
                <span class="hidden sm:inline">data</span>

                {{-- Meta Info --}}
                <div class="hidden md:block ml-2 border-l border-border pl-4" x-show="{{ $meta }} && {{ $meta }}.total > 0">
                    Menampilkan <span class="font-medium text-ink" x-text="{{ $meta }}.from || 0"></span>
                    - <span class="font-medium text-ink" x-text="{{ $meta }}.to || 0"></span>
                    dari <span class="font-medium text-ink" x-text="{{ $meta }}.total || 0"></span>
                </div>
            </div>

            <x-ui.pagination
                current="{{ $meta }}.current_page"
                total="{{ $meta }}.last_page"
                action="{{ $fetchPage }}"
            />
        </div>
    </x-ui.card>
</div>