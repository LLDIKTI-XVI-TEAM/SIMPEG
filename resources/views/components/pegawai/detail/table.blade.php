@props([
    'name',
    'headings',
    'showActions' => false,
    'empty' => false,
    'emptyLabel' => 'Belum ada data.',
])

@php($columnCount = count($headings) + ($showActions ? 1 : 0))

<div {{ $attributes->class(['overflow-x-auto rounded-lg border border-border']) }}>
    <table data-employee-detail-table="{{ $name }}" class="w-full">
        <thead class="border-b border-border bg-soft">
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-muted font-sans">
                @foreach($headings as $heading)
                    <th class="px-4 py-3">{{ $heading }}</th>
                @endforeach

                @if($showActions)
                    <th class="px-4 py-3 text-left">Aksi</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-border text-xs text-ink font-sans">
            {{ $slot }}

            @if($empty)
                <tr>
                    <td colspan="{{ $columnCount }}" class="px-4 py-6 text-center font-semibold text-muted">
                        {{ $emptyLabel }}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
