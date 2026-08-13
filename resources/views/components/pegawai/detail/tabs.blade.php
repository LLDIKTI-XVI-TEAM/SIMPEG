@props([
    'tabs',
    'idPrefix',
])

<nav
    data-employee-detail-tabs
    role="tablist"
    aria-label="Navigasi detail pegawai"
    aria-orientation="horizontal"
    class="flex gap-4 overflow-x-auto border-b border-border pb-1 md:gap-6"
    @keydown.right.prevent="moveTab(1)"
    @keydown.left.prevent="moveTab(-1)"
    @keydown.home.prevent="selectTab(tabs[0])"
    @keydown.end.prevent="selectTab(tabs[tabs.length - 1])"
>
    @foreach($tabs as $tab => $label)
        <button
            type="button"
            role="tab"
            id="{{ $idPrefix }}-tab-{{ $tab }}"
            aria-controls="{{ $idPrefix }}-panel-{{ $tab }}"
            :aria-selected="activeTab === '{{ $tab }}'"
            :tabindex="activeTab === '{{ $tab }}' ? 0 : -1"
            @click="selectTab('{{ $tab }}')"
            :class="activeTab === '{{ $tab }}' ? 'border-b-2 border-primary text-primary font-bold' : 'border-b-2 border-transparent text-muted hover:text-ink font-semibold'"
            class="shrink-0 cursor-pointer pb-2 text-xs transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 md:text-sm font-sans"
        >{{ $label }}</button>
    @endforeach
</nav>
