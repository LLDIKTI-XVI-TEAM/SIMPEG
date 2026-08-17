@props([
    'tab',
    'idPrefix',
])

<section
    data-employee-detail-panel="{{ $tab }}"
    id="{{ $idPrefix }}-panel-{{ $tab }}"
    role="tabpanel"
    aria-labelledby="{{ $idPrefix }}-tab-{{ $tab }}"
    x-show="activeTab === '{{ $tab }}'"
    @if($tab !== 'profile') x-cloak @endif
    x-transition
    {{ $attributes->class(['space-y-4']) }}
>
    {{ $slot }}
</section>
