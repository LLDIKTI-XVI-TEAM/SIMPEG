@props(['url', 'category', 'name', 'label', 'mode', 'selected' => null])

<div {{ $attributes->class('mt-1 space-y-3') }}
    x-data="employeeArchivePicker(@js(['url' => $url, 'category' => $category, 'selected' => $selected]))"
    x-init="$watch('{{ $mode }}', value => setActive(value === 'arsip')); setActive({{ $mode }} === 'arsip')"
    x-show="{{ $mode }} === 'arsip'" x-cloak>
    <input type="hidden" name="{{ $name }}" :value="selected?.id ?? ''" :disabled="!active">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
            <label for="{{ $name }}-query" class="block text-sm font-medium text-ink">Cari {{ $label }}</label>
            <input id="{{ $name }}-query" type="search" x-model="query" maxlength="100"
                @keydown.enter.prevent="load(1)" placeholder="Nomor atau nama dokumen"
                class="mt-1 min-h-11 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink focus-visible:outline-2 focus-visible:outline-primary">
        </div>
        <x-ui.button type="button" variant="secondary" @click="load(1)" class="min-h-11" x-bind:disabled="loading">Cari</x-ui.button>
    </div>
    <p x-show="selected" class="text-sm text-ink" aria-live="polite">
        Dipilih: <strong class="wrap-anywhere" x-text="selected?.label"></strong>
        <button type="button" @click="clear()" class="ml-2 min-h-11 text-primary underline">Batalkan pilihan</button>
    </p>
    <div aria-live="polite" :aria-busy="loading">
        <p x-show="loading" class="text-sm text-muted">Memuat arsip…</p>
        <div x-show="error" class="text-sm text-danger" role="alert">
            <span x-text="error"></span>
            <button type="button" @click="load(1)" class="min-h-11 underline" :disabled="loading">Coba lagi</button>
        </div>
        <p x-show="loaded && !loading && !error && rows.length === 0" class="text-sm text-muted">Tidak ada dokumen yang cocok. Coba kata kunci lain atau unggah dokumen baru.</p>
        <ul x-show="!loading && !error && rows.length" class="divide-y divide-border rounded-lg border border-border">
            <template x-for="doc in rows" :key="doc.id">
                <li>
                    <button type="button" @click="select(doc.id)" :aria-pressed="selected?.id === doc.id"
                        class="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-soft focus-visible:outline-2 focus-visible:outline-primary"
                        :class="selected?.id === doc.id ? 'bg-primary/5' : ''">
                        <span class="min-w-0 wrap-anywhere">
                            <span class="block text-sm font-semibold text-ink" x-text="doc.label"></span>
                            <span class="block text-xs text-muted" x-text="doc.nama_dokumen"></span>
                        </span>
                        <span class="shrink-0 text-sm text-primary" x-text="selected?.id === doc.id ? 'Dipilih' : 'Pilih'"></span>
                    </button>
                </li>
            </template>
        </ul>
    </div>
    <div x-show="loaded && !error" class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <span class="text-muted" x-text="`${total} dokumen · Halaman ${page} dari ${lastPage}`"></span>
        <div class="flex gap-2">
            <x-ui.button type="button" variant="secondary" @click="load(page - 1)" x-bind:disabled="loading || page <= 1" class="min-h-11">Sebelumnya</x-ui.button>
            <x-ui.button type="button" variant="secondary" @click="load(page + 1)" x-bind:disabled="loading || page >= lastPage" class="min-h-11">Berikutnya</x-ui.button>
        </div>
    </div>
</div>
