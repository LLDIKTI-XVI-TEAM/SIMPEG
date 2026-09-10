<div class="relative space-y-1" @click.outside="{{ $candidate }}.open = false">
    <label :for="{{ $candidate }}.key + '-query'" class="text-xs font-bold uppercase tracking-wider text-ink">{{ $label }}</label>
    <x-form.input
        name="batch_approver_search" id="batch-approver-search" :use-old-input="false"
        ::id="{{ $candidate }}.key + '-query'" ::value="{{ $candidate }}.query"
        @input="scheduleApprover({{ $candidate }}, $event.target.value)"
        @keydown.down.prevent="moveApprover({{ $candidate }}, 1)"
        @keydown.up.prevent="moveApprover({{ $candidate }}, -1)"
        @keydown.enter="chooseActiveApprover({{ $candidate }}, $event)"
        @keydown.escape.stop.prevent="{{ $candidate }}.open = false"
        @focus="{{ $candidate }}.open = {{ $candidate }}.results.length > 0"
        ::disabled="applying" ::aria-invalid="Boolean(fieldError({!! $errorField !!}))"
        ::aria-describedby="{{ $candidate }}.key + '-help ' + {{ $candidate }}.key + '-error ' + {{ $candidate }}.key + '-status'"
        role="combobox" aria-autocomplete="list" aria-haspopup="listbox" autocomplete="off"
        ::aria-expanded="{{ $candidate }}.open"
        ::aria-controls="{{ $candidate }}.key + '-results'"
        ::aria-activedescendant="{{ $candidate }}.open && {{ $candidate }}.activeIndex >= 0 ? {{ $candidate }}.key + '-option-' + {{ $candidate }}.activeIndex : null"
        class="min-h-11" placeholder="Cari nama atau NIP"
    />
    <p :id="{{ $candidate }}.key + '-help'" class="text-xs text-muted">Ketik 2–100 karakter, lalu pilih pegawai dari hasil pencarian.</p>
    <p :id="{{ $candidate }}.key + '-error'" class="text-xs text-danger" x-text="fieldError({!! $errorField !!})"></p>
    <p :id="{{ $candidate }}.key + '-status'" role="status" aria-live="polite" class="text-xs text-muted"
        x-text="{{ $candidate }}.loading ? 'Mencari pegawai…' : {{ $candidate }}.state === 'empty' ? 'Pegawai tidak ditemukan. Coba nama atau NIP lain.' : {{ $candidate }}.state === 'results' ? {{ $candidate }}.results.length + ' pegawai ditemukan.' : {{ $candidate }}.error"></p>
    <x-ui.button variant="secondary" x-show="{{ $candidate }}.state === 'error'" x-cloak
        @click="lookupApprover({{ $candidate }})" ::disabled="applying" class="min-h-11">Coba pencarian lagi</x-ui.button>
    <ul x-show="{{ $candidate }}.open && {{ $candidate }}.results.length" x-cloak
        :id="{{ $candidate }}.key + '-results'" role="listbox" aria-label="Hasil pencarian {{ $label }}"
        class="absolute z-20 max-h-60 w-full overflow-y-auto rounded-xl border border-border bg-surface shadow-lg">
        <template x-for="(person, resultIndex) in {{ $candidate }}.results" :key="person.id">
            <li role="option" :id="{{ $candidate }}.key + '-option-' + resultIndex"
                :aria-selected="{{ $candidate }}.activeIndex === resultIndex"
                @mousedown.prevent @click="chooseApprover({{ $candidate }}, person)"
                :class="{{ $candidate }}.activeIndex === resultIndex ? 'bg-soft' : ''"
                class="min-h-11 cursor-pointer px-4 py-3 text-sm text-ink hover:bg-soft">
                <span class="block font-medium" x-text="person.nama_lengkap"></span>
                <span class="block font-mono text-xs text-muted" x-text="'NIP ' + person.nip"></span>
            </li>
        </template>
    </ul>
</div>
