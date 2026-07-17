@props([
    'action',
    'id',
    'name',
    'selectedId' => null,
    'selectedLabel' => null,
    'queryName' => 'search',
    'queryValue' => null,
    'preserved' => [],
    'label' => 'Cari Pegawai',
    'placeholder' => 'Nama atau NIP',
    'help' => 'Ketik minimal 2 karakter nama atau NIP.',
    'clearUrl' => null,
    'fallbackOptions' => [],
    'fallbackName' => null,
    'fallbackLabel' => 'Pilih Pegawai',
    'fallbackPlaceholder' => 'ID pegawai',
    'submitLabel' => 'Terapkan',
])

@php
    $listboxId = $id.'-listbox';
    $helpId = $id.'-help';
    $statusId = $id.'-status';
    $fallbackOptions = collect($fallbackOptions);
@endphp

<form
    x-data="{
        query: @js($selectedLabel ?? $queryValue ?? ''),
        selectedId: @js($selectedId ?? ''),
        selectedLabel: @js($selectedLabel ?? ''),
        results: [],
        open: false,
        loading: false,
        error: '',
        activeIndex: -1,
        debounceTimer: null,
        controller: null,
        requestId: 0,
        endpoint: @js(route('cuti.employee-lookup')),
        onInput() {
            if (this.query !== this.selectedLabel) this.selectedId = '';

            clearTimeout(this.debounceTimer);
            this.requestId++;
            this.controller?.abort();
            this.error = '';
            this.results = [];
            this.activeIndex = -1;

            if (this.query.trim().length < 2) {
                this.loading = false;
                this.open = false;

                return;
            }

            this.loading = true;
            this.open = true;
            this.debounceTimer = setTimeout(() => this.lookup(), 300);
        },
        async lookup() {
            const term = this.query.trim();

            if (term.length < 2) return;

            const requestId = ++this.requestId;
            this.controller?.abort();
            this.controller = new AbortController();

            try {
                const url = new URL(this.endpoint, window.location.origin);
                url.searchParams.set('q', term);

                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: this.controller.signal,
                });

                if (! response.ok) throw new Error('Pencarian pegawai gagal dimuat.');

                const payload = await response.json();

                if (requestId !== this.requestId) return;

                this.results = Array.isArray(payload.data) ? payload.data : [];
                this.activeIndex = this.results.length ? 0 : -1;
            } catch (error) {
                if (error.name === 'AbortError' || requestId !== this.requestId) return;

                this.results = [];
                this.activeIndex = -1;
                this.error = 'Pencarian pegawai gagal dimuat. Coba lagi.';
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },
        choose(employee) {
            this.selectedId = employee.id;
            this.selectedLabel = `${employee.nama_lengkap} (${employee.nip})`;
            this.query = this.selectedLabel;
            this.results = [];
            this.open = false;
            this.activeIndex = -1;
            this.$nextTick(() => document.getElementById(@js($id))?.form?.requestSubmit());
        },
        moveActive(direction) {
            if (! this.results.length) return;

            this.open = true;
            this.activeIndex = (this.activeIndex + direction + this.results.length) % this.results.length;
        },
        selectActive(event) {
            if (this.open && this.activeIndex >= 0 && this.results[this.activeIndex]) {
                event.preventDefault();
                this.choose(this.results[this.activeIndex]);
            }
        },
        close() {
            this.open = false;
            this.activeIndex = -1;
        },
        statusMessage() {
            if (this.loading) return 'Memuat pegawai.';
            if (this.error) return this.error;
            if (this.open && this.query.trim().length >= 2 && ! this.results.length) return 'Pegawai tidak ditemukan.';

            return '';
        },
    }"
    method="GET"
    action="{{ $action }}"
    {{ $attributes->class('flex flex-col gap-3 sm:flex-row sm:items-end') }}
>
    @foreach ($preserved as $preservedName => $preservedValue)
        @if ($preservedValue !== null && $preservedValue !== '')
            <input type="hidden" name="{{ $preservedName }}" value="{{ $preservedValue }}">
        @endif
    @endforeach

    <input type="hidden" :name="selectedId ? @js($name) : null" x-model="selectedId">

    <div class="relative min-w-0 flex-1 space-y-1" @click.outside="close()">
        <label for="{{ $id }}" class="text-xs font-bold uppercase tracking-wider text-ink">{{ $label }}</label>
        <div class="relative">
            <input
                id="{{ $id }}"
                name="{{ $queryName }}"
                :name="selectedId ? null : @js($queryName)"
                type="search"
                x-model="query"
                @input="onInput()"
                @focus="if (results.length || loading || error) open = true"
                @keydown.arrow-down.prevent="moveActive(1)"
                @keydown.arrow-up.prevent="moveActive(-1)"
                @keydown.enter.prevent="selectActive($event)"
                @keydown.escape.prevent="close()"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $listboxId }}"
                :aria-expanded="open.toString()"
                :aria-activedescendant="activeIndex >= 0 ? '{{ $listboxId }}-' + activeIndex : null"
                aria-describedby="{{ $helpId }} {{ $statusId }}"
                placeholder="{{ $placeholder }}"
                autocomplete="off"
                class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-all duration-200 placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            >
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-muted" x-show="loading" x-cloak aria-hidden="true">
                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                </svg>
            </div>
        </div>

        <div
            x-cloak
            x-show="open && (loading || error || query.trim().length >= 2)"
            id="{{ $listboxId }}"
            role="listbox"
            aria-label="Hasil pencarian pegawai"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-border bg-surface p-1 shadow-lg"
        >
            <p x-show="loading" class="min-h-11 px-3 py-3 text-sm text-muted">Memuat pegawai...</p>
            <p x-show="!loading && error" x-text="error" class="min-h-11 px-3 py-3 text-sm text-danger" role="alert"></p>
            <p x-show="!loading && !error && !results.length" class="min-h-11 px-3 py-3 text-sm text-muted">Pegawai tidak ditemukan.</p>
            <template x-for="(employee, index) in results" :key="employee.id">
                <button
                    type="button"
                    :id="'{{ $listboxId }}-' + index"
                    role="option"
                    :aria-selected="activeIndex === index"
                    @mousedown.prevent="choose(employee)"
                    @mousemove="activeIndex = index"
                    class="flex min-h-11 w-full flex-col justify-center rounded-lg px-3 py-2 text-left transition hover:bg-soft focus:bg-soft focus:outline-none"
                    :class="{ 'bg-soft': activeIndex === index }"
                >
                    <span x-text="employee.nama_lengkap" class="text-sm font-semibold text-ink"></span>
                    <span x-text="employee.nip" class="text-xs text-muted"></span>
                </button>
            </template>
        </div>

        <p id="{{ $helpId }}" class="text-[11px] text-muted">{{ $help }}</p>
        <p id="{{ $statusId }}" class="sr-only" aria-live="polite" x-text="statusMessage()"></p>
    </div>

    @if ($clearUrl)
        <a x-show="selectedId" x-cloak href="{{ $clearUrl }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-border bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">Bersihkan</a>
    @endif

    <button x-show="false" type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-transparent bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30">{{ $submitLabel }}</button>

    <noscript>
        @if ($fallbackOptions->isNotEmpty())
            <label for="{{ $id }}-fallback" class="sr-only">{{ $fallbackLabel }}</label>
            <select id="{{ $id }}-fallback" name="{{ $name }}" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">Pilih pegawai dari hasil pencarian</option>
                @foreach ($fallbackOptions as $employee)
                    <option value="{{ $employee->id }}" @selected($selectedId === $employee->id)>{{ $employee->nama_lengkap }} ({{ $employee->nip }})</option>
                @endforeach
            </select>
        @elseif ($fallbackName)
            <label for="{{ $id }}-fallback" class="sr-only">{{ $fallbackLabel }}</label>
            <input id="{{ $id }}-fallback" name="{{ $fallbackName }}" type="text" value="{{ $selectedId }}" placeholder="{{ $fallbackPlaceholder }}" class="min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
        @endif
    </noscript>
</form>
