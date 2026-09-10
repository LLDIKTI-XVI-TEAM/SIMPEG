<section aria-labelledby="batch-heading"
    x-data="chainBatchEditor({
        targetsUrl: @js(route('cuti.config.batch.targets')),
        approversUrl: @js(route('cuti.config.batch.approvers')),
        previewUrl: @js(route('cuti.config.batch.preview')),
        applyUrl: @js(route('cuti.config.batch.apply')),
        csrfToken: @js(csrf_token()),
        globalPybmc: @js($globalPybmc?->approver ? ['id' => $globalPybmc->approver->id, 'nama_lengkap' => $globalPybmc->approver->nama_lengkap, 'nip' => $globalPybmc->approver->nip] : null),
        initialStep: @js($initialStep ?? 'susun'),
        visible: @js(($initialTab ?? 'pegawai') === 'rangkaian'),
    })"
    data-chain-batch-editor
    @cuti-config-tab.window="setVisible($event.detail.tab === 'rangkaian')">
<x-ui.card padding="lg" class="space-y-6">
    <header class="space-y-2">
        <h2 id="batch-heading" class="text-lg font-semibold text-ink">Terapkan Rangkaian ke Pegawai</h2>
        <p class="max-w-prose text-sm text-muted">Susun rangkaian, pilih pegawai, lalu periksa dampaknya sebelum menerapkan.</p>
    </header>
    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></p>
    <p x-show="notice" x-cloak x-text="notice" class="rounded-xl border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink" role="status"></p>
    <div x-show="error" x-cloak x-ref="errorSummary" data-batch-error-summary tabindex="-1" role="alert"
        class="space-y-2 rounded-xl border border-danger/30 bg-danger/5 p-4 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/30">
        <p x-text="error"></p>
        <ul class="list-disc space-y-1 pl-5">
            <template x-for="(messages, field) in errors" :key="field"><li x-text="messages[0]"></li></template>
        </ul>
    </div>

    <nav aria-label="Tahap penerapan massal">
        <ol class="grid grid-cols-3 overflow-hidden rounded-xl border border-border bg-soft/30">
            <template x-for="(item, index) in [{key: 'susun', label: 'Susun'}, {key: 'pilih', label: 'Pilih Pegawai'}, {key: 'tinjau', label: 'Tinjau'}]" :key="item.key">
                <li :class="index > 0 ? 'border-l border-border' : ''">
                    <button type="button" @click="goToStep(item.key)" :disabled="applying"
                        :aria-current="step === item.key ? 'step' : null"
                        :class="step === item.key ? 'bg-primary text-white' : 'text-muted hover:bg-soft hover:text-ink'"
                        class="flex min-h-11 w-full items-center justify-center gap-2 px-3 py-2 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary/30 sm:text-sm">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full border border-current text-[11px]" x-text="index + 1"></span>
                        <span x-text="item.label"></span>
                    </button>
                </li>
            </template>
        </ol>
    </nav>

    <section aria-labelledby="batch-susun-heading" x-show="step === 'susun'" x-cloak class="space-y-5">
        <h3 id="batch-susun-heading" tabindex="-1" class="text-lg font-semibold text-ink focus:outline-none focus:ring-2 focus:ring-primary/30">Susun Rangkaian</h3>
        <div class="space-y-4">
            <template x-for="(step, index) in draft.verifiers" :key="step.key">
                <div class="space-y-3 border-b border-border pb-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-ink" x-text="'Verifikator ' + (index + 1)"></h4>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button variant="ghost" @click="moveVerifier(index, -1)" ::disabled="applying || index === 0" aria-describedby="batch-verifier-help" class="min-h-11" ::aria-label="'Naikkan Verifikator ' + (index + 1)">Naik</x-ui.button>
                            <x-ui.button variant="ghost" @click="moveVerifier(index, 1)" ::disabled="applying || index === draft.verifiers.length - 1" aria-describedby="batch-verifier-help" class="min-h-11" ::aria-label="'Turunkan Verifikator ' + (index + 1)">Turun</x-ui.button>
                            <x-ui.button variant="danger" @click="removeVerifier(index)" ::disabled="applying" class="min-h-11" ::aria-label="'Hapus Verifikator ' + (index + 1)">Hapus</x-ui.button>
                        </div>
                    </div>
                    <div class="grid items-start gap-4 md:grid-cols-2">
                        <div class="space-y-1">
                            <label :for="step.key + '-label'" class="text-xs font-bold uppercase tracking-wider text-ink">Label peran</label>
                            <x-form.input name="batch_verifier_label" id="batch-verifier-label" :use-old-input="false"
                                ::id="step.key + '-label'" ::value="step.role_label" @input="updateVerifier(step, $event.target.value)"
                                maxlength="100" ::disabled="applying" ::aria-invalid="Boolean(fieldError('verifiers.' + index + '.role_label'))"
                                ::aria-describedby="step.key + '-label-error'" class="min-h-11" />
                            <p :id="step.key + '-label-error'" class="text-xs text-danger" x-text="fieldError('verifiers.' + index + '.role_label')"></p>
                        </div>
                        @include('admin.cuti.partials.chain-batch-approver', ['candidate' => 'step', 'label' => 'Pegawai verifikator', 'errorField' => "'verifiers.' + index + '.approver_employee_id'"])
                    </div>
                </div>
            </template>
            <div class="space-y-2">
                <x-ui.button id="batch-add-verifier" variant="secondary" @click="addVerifier()" ::disabled="applying || draft.verifiers.length >= 8" aria-describedby="batch-verifier-help" class="min-h-11">Tambah Verifikator</x-ui.button>
                <p id="batch-verifier-help" class="text-xs text-muted">Maksimal 8 verifikator. Gunakan Naik atau Turun untuk mengatur urutan; tahap pertama tidak dapat dinaikkan dan tahap terakhir tidak dapat diturunkan.</p>
            </div>
        </div>
        <div class="space-y-1 border-b border-border pb-5">
            <h4 class="text-sm font-semibold text-ink">Atasan Langsung</h4>
            <p class="text-sm text-muted">Otomatis mengikuti penugasan efektif setiap pegawai. Orang yang digunakan ditampilkan pada pratinjau.</p>
        </div>
        <div class="grid items-start gap-4 md:grid-cols-2">
            <div class="space-y-2">
                <x-form.select name="batch_pybmc_mode" id="batch-pybmc-mode" label="PYBMC" :use-old-input="false"
                    ::value="draft.pybmc_mode" @change="changeDraft('pybmc_mode', $event.target.value)" ::disabled="applying" class="min-h-11">
                    @if ($globalPybmc?->approver)
                        <option value="global">Gunakan PYBMC global</option>
                    @endif
                    <option value="custom">Pilih PYBMC untuk rangkaian ini</option>
                </x-form.select>
                <p x-show="draft.pybmc_mode === 'global'" x-cloak class="text-sm text-ink"
                    x-text="globalPybmc ? globalPybmc.nama_lengkap + ' (NIP ' + globalPybmc.nip + ')' : ''"></p>
                <p class="text-xs text-muted">Pilihan ini tidak mengubah konfigurasi PYBMC global.</p>
            </div>
            <div x-show="draft.pybmc_mode === 'custom'" x-cloak>
                @include('admin.cuti.partials.chain-batch-approver', ['candidate' => 'pybmc', 'label' => 'Pegawai PYBMC', 'errorField' => "'pybmc_employee_id'"])
            </div>
        </div>
        <fieldset class="space-y-3" :disabled="applying">
            <legend class="mb-2 text-sm font-semibold text-ink">Penerapan rangkaian</legend>
            <label class="flex min-h-11 cursor-pointer items-start gap-3">
                <input type="radio" name="batch_mode" value="missing_only" :checked="draft.mode === 'missing_only'" @change="changeDraft('mode', 'missing_only')" aria-describedby="batch-mode-missing-help" class="mt-1 h-5 w-5 shrink-0 accent-primary focus:ring-2 focus:ring-primary/30">
                <span><span class="block text-sm font-medium text-ink">Hanya pegawai yang belum memiliki rangkaian</span><span id="batch-mode-missing-help" class="mt-1 block text-xs text-muted">Pegawai yang sudah memiliki rangkaian aktif akan dilewati.</span></span>
            </label>
            <label class="flex min-h-11 cursor-pointer items-start gap-3">
                <input type="radio" name="batch_mode" value="replace" :checked="draft.mode === 'replace'" @change="changeDraft('mode', 'replace')" aria-describedby="batch-mode-replace-help" class="mt-1 h-5 w-5 shrink-0 accent-primary focus:ring-2 focus:ring-primary/30">
                <span><span class="block text-sm font-medium text-ink">Perbarui rangkaian pegawai terpilih</span><span id="batch-mode-replace-help" class="mt-1 block text-xs text-muted">Buat rangkaian jika belum ada. Rangkaian aktif yang berbeda akan diganti; riwayat sebelumnya tetap tersimpan.</span></span>
            </label>
        </fieldset>
        <div class="space-y-2">
            <div class="flex justify-end border-t border-border pt-5">
                <x-ui.button type="button" @click="goToStep('pilih')" ::disabled="applying" class="min-h-11">Lanjut Pilih Pegawai</x-ui.button>
            </div>
        </div>
    </section>

    <section aria-labelledby="batch-pilih-heading" x-show="step === 'pilih'" x-cloak class="space-y-4">
        <h3 id="batch-pilih-heading" tabindex="-1" class="text-lg font-semibold text-ink focus:outline-none focus:ring-2 focus:ring-primary/30">Pilih Pegawai</h3>
        <div class="grid items-start gap-4 md:grid-cols-2">
            <x-form.input name="batch_target_query" id="batch-target-query" label="Cari pegawai" :use-old-input="false" placeholder="Nama atau NIP" maxlength="100"
                x-model="pickerQuery" @input="scheduleTargets()" ::disabled="applying" class="min-h-11"
                ::aria-invalid="Boolean(fieldError('employee_ids'))" aria-describedby="batch-target-help batch-target-error" />
            <x-form.select name="batch_unit" id="batch-unit" label="Unit kerja" :use-old-input="false" x-model="pickerUnit" @change="scheduleTargets()" ::disabled="applying" class="min-h-11" aria-describedby="batch-target-help">
                <option value="">Semua unit kerja</option>
                @foreach ($unitKerjaOptions as $unitKerja)<option value="{{ $unitKerja->id }}">{{ $unitKerja->nama }}</option>@endforeach
            </x-form.select>
        </div>
        <p id="batch-target-help" class="text-xs text-muted">Filter hanya mencakup unit yang dipilih, tidak termasuk sub-unit. Pilihan tetap tersimpan saat pencarian atau halaman berubah. Maksimal 100 pegawai per penerapan.</p>
        <p id="batch-target-error" class="text-xs text-danger" x-text="fieldError('employee_ids')"></p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.button variant="secondary" @click="selectPage()" ::disabled="applying || pickerLoading || pickerState !== 'results'" aria-describedby="batch-target-help" class="min-h-11">Pilih halaman ini</x-ui.button>
            <span class="text-sm font-medium text-ink" aria-live="polite" x-text="selected.size + ' dari 100 pegawai dipilih'"></span>
        </div>
        <div :aria-busy="pickerLoading" class="border-y border-border">
            <div x-show="pickerLoading" x-cloak class="space-y-3 py-4" role="status" aria-live="polite">
                <p class="text-sm text-muted">Memuat pegawai…</p>
                <div class="h-11 rounded-lg bg-soft" aria-hidden="true"></div><div class="h-11 rounded-lg bg-soft" aria-hidden="true"></div>
            </div>
            <div x-show="pickerState === 'error'" x-cloak class="space-y-3 py-4">
                <p class="text-sm text-danger" role="alert" x-text="pickerError"></p>
                <x-ui.button variant="secondary" @click="loadTargets()" ::disabled="applying" class="min-h-11">Coba muat pegawai lagi</x-ui.button>
            </div>
            <p x-show="pickerState === 'empty'" x-cloak class="py-5 text-sm text-muted">Tidak ada pegawai aktif yang cocok dalam cakupan akses Anda. Ubah pencarian atau filter unit.</p>
            <ul x-show="pickerState === 'results'" x-cloak class="divide-y divide-border">
                <template x-for="person in pickerRows" :key="person.id">
                    <li>
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 py-3">
                            <input type="checkbox" :checked="selected.has(person.id)" @change="toggleTarget(person)"
                                :disabled="applying || (selected.size >= 100 && !selected.has(person.id))" aria-describedby="batch-target-help"
                                class="h-5 w-5 shrink-0 accent-primary focus:ring-2 focus:ring-primary/30">
                            <span class="min-w-0 flex-1"><span class="block break-words text-sm font-medium text-ink" x-text="person.nama_lengkap"></span><span class="block font-mono text-xs text-muted" x-text="'NIP ' + person.nip"></span></span>
                            <x-ui.badge variant="muted" class="shrink-0"><span x-text="person.has_active_chain ? 'Sudah ada' : 'Belum ada'"></span></x-ui.badge>
                        </label>
                    </li>
                </template>
            </ul>
        </div>
        <nav class="flex flex-wrap items-center justify-between gap-3" aria-label="Halaman pilihan pegawai">
            <p id="batch-pagination-help" class="text-xs text-muted" x-text="'Halaman ' + pickerPage + ' dari ' + pickerLastPage + ' · ' + pickerTotal + ' pegawai. Maksimal 25 per halaman.'"></p>
            <div class="flex gap-2">
                <x-ui.button variant="secondary" @click="loadTargets(pickerPage - 1)" ::disabled="applying || pickerLoading || pickerPage <= 1" aria-describedby="batch-pagination-help" class="min-h-11">Sebelumnya</x-ui.button>
                <x-ui.button variant="secondary" @click="loadTargets(pickerPage + 1)" ::disabled="applying || pickerLoading || pickerPage >= pickerLastPage" aria-describedby="batch-pagination-help" class="min-h-11">Berikutnya</x-ui.button>
            </div>
        </nav>
        <details x-show="selected.size > 0" x-cloak open class="border-t border-border pt-3">
            <summary class="min-h-11 cursor-pointer py-3 text-sm font-semibold text-ink focus:outline-none focus:ring-2 focus:ring-primary/30">Daftar pegawai terpilih</summary>
            <ul class="max-h-60 divide-y divide-border overflow-y-auto">
                <template x-for="person in selectedTargets" :key="person.id">
                    <li class="flex items-center gap-3 py-2">
                        <span class="min-w-0 flex-1"><span class="block break-words text-sm text-ink" x-text="person.nama_lengkap"></span><span class="block font-mono text-xs text-muted" x-text="'NIP ' + person.nip"></span></span>
                        <x-ui.button variant="ghost" @click="toggleTarget(person)" ::disabled="applying" class="min-h-11 shrink-0" ::aria-label="'Lepas pilihan ' + person.nama_lengkap">Lepas</x-ui.button>
                    </li>
                </template>
            </ul>
        </details>
        <div class="flex flex-wrap justify-between gap-3 border-t border-border pt-5">
            <x-ui.button type="button" variant="secondary" @click="goToStep('susun')" ::disabled="applying" class="min-h-11">Kembali ke Susun</x-ui.button>
            <x-ui.button type="button" @click="goToStep('tinjau')" ::disabled="applying || selected.size === 0" class="min-h-11">Tinjau Pilihan</x-ui.button>
        </div>
    </section>

    <section aria-labelledby="batch-tinjau-heading" x-show="step === 'tinjau'" x-cloak class="space-y-4">
        <h3 id="batch-tinjau-heading" tabindex="-1" class="text-lg font-semibold text-ink focus:outline-none focus:ring-2 focus:ring-primary/30">Tinjau Penerapan</h3>
        <p id="batch-preview-help" class="text-sm text-muted">Isi alasan bila diperlukan, lalu muat pratinjau. Berpindah ke tahap ini tidak menjalankan pratinjau otomatis.</p>
        <div class="space-y-1">
            <x-form.textarea name="batch_reason" id="batch-reason" label="Alasan penerapan" rows="3" maxlength="500"
                ::value="draft.reason" @input="changeDraft('reason', $event.target.value)" ::disabled="applying"
                ::aria-invalid="Boolean(fieldError('reason'))" aria-describedby="batch-reason-help batch-reason-error" />
            <p id="batch-reason-help" class="max-w-prose text-xs text-muted">Wajib jika memilih lebih dari satu pegawai dan ada rangkaian aktif yang berbeda untuk diganti. Selain itu opsional. Jika diisi, gunakan 5–500 karakter.</p>
            <p id="batch-reason-error" class="text-xs text-danger" x-text="fieldError('reason')"></p>
        </div>
        <x-ui.button @click="loadPreview()" ::disabled="previewLoading || applying || selected.size === 0" x-show="!preview" aria-describedby="batch-preview-help" class="min-h-11">
            <span x-text="previewLoading ? 'Memuat pratinjau…' : (previewOutdated ? 'Perbarui pratinjau' : 'Muat pratinjau')"></span>
        </x-ui.button>
        <template x-if="preview || result">
            <div class="space-y-4">
                <h4 id="batch-review-heading" tabindex="-1" class="w-fit rounded-sm text-sm font-semibold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2" x-text="result ? 'Hasil penerapan' : 'Ringkasan pratinjau'"></h4>
                <p x-show="result" class="text-sm font-semibold text-ink" role="status">Penerapan selesai. Berikut hasil dari server.</p>
                <dl class="grid grid-cols-2 gap-4 border-y border-border py-4 sm:grid-cols-4" aria-label="Ringkasan penerapan">
                    <div><dt class="text-xs text-muted" x-text="result ? 'Dibuat' : 'Akan dibuat'"></dt><dd class="mt-1 text-lg font-semibold text-ink" x-text="summary().create"></dd></div>
                    <div><dt class="text-xs text-muted" x-text="result ? 'Diganti' : 'Akan diganti'"></dt><dd class="mt-1 text-lg font-semibold text-ink" x-text="summary().replace"></dd></div>
                    <div><dt class="text-xs text-muted">Tidak berubah</dt><dd class="mt-1 text-lg font-semibold text-ink" x-text="summary().unchanged"></dd></div>
                    <div><dt class="text-xs text-muted">Dilewati</dt><dd class="mt-1 text-lg font-semibold text-ink" x-text="summary().skipped"></dd></div>
                </dl>
                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_7rem]">
                    <x-form.input name="batch_review_search" id="batch-review-search" label="Cari hasil" :use-old-input="false" x-model="reviewSearch" @input="setReviewFilter('reviewSearch', $event.target.value)" placeholder="Nama atau NIP" class="min-h-11" />
                    <x-form.select name="batch_review_filter" id="batch-review-filter" label="Dampak" :use-old-input="false" x-model="reviewFilter" @change="setReviewFilter('reviewFilter', $event.target.value)" class="min-h-11">
                        <option value="all">Semua dampak</option>
                        <option value="create">Dibuat</option>
                        <option value="replace">Diganti</option>
                        <option value="unchanged">Tidak berubah</option>
                        <option value="skipped">Dilewati</option>
                    </x-form.select>
                    <x-form.select name="batch_review_per_page" id="batch-review-per-page" label="Per halaman" :use-old-input="false" x-model.number="reviewPerPage" @change="setReviewFilter('reviewPerPage', Number($event.target.value))" class="min-h-11">
                        <option value="10">10</option>
                        <option value="25">25</option>
                    </x-form.select>
                </div>
                <p class="text-xs text-muted" aria-live="polite" x-text="filteredReviewRows.length === 0 ? 'Tidak ada hasil yang cocok dengan filter tampilan.' : `Menampilkan ${(reviewPage - 1) * reviewPerPage + 1}–${Math.min(reviewPage * reviewPerPage, filteredReviewRows.length)} dari ${filteredReviewRows.length} hasil cocok; ${selected.size} pegawai dipilih. Filter ini tidak mengubah sasaran penerapan.`"></p>
                <x-ui.button type="button" variant="secondary" x-show="filteredReviewRows.length === 0 && reviewRows.length > 0" @click="resetReview()" class="min-h-11">Reset Filter</x-ui.button>
                <ul class="divide-y divide-border md:hidden">
                    <template x-for="row in pagedReviewRows" :key="row.employee_id">
                        <li class="space-y-3 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0"><p class="break-words text-sm font-semibold text-ink" x-text="row.nama_lengkap"></p><p class="font-mono text-xs text-muted" x-text="'NIP ' + row.nip"></p></div>
                                <x-ui.badge variant="muted"><span x-text="outcomeLabel(row.outcome)"></span></x-ui.badge>
                            </div>
                            <p class="text-sm text-ink" x-text="'Atasan Langsung: ' + (row.supervisor?.nama_lengkap ?? 'Belum tersedia')"></p>
                            <p class="text-sm text-muted" x-text="row.message"></p>
                            <details>
                                <summary class="min-h-11 w-fit cursor-pointer rounded-sm py-3 text-sm font-medium text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2" :aria-label="'Lihat rangkaian sebelum dan sesudah untuk ' + row.nama_lengkap">Lihat rangkaian sebelum dan sesudah</summary>
                                <div class="grid gap-5 pb-2 md:grid-cols-2">
                                    <template x-for="part in [{key: 'before_steps', label: 'Sebelum'}, {key: 'after_steps', label: 'Sesudah'}]" :key="part.key">
                                        <div class="space-y-2">
                                            <h4 class="text-sm font-semibold text-ink" x-text="part.label"></h4>
                                            <p x-show="!row[part.key]?.length" class="text-xs text-muted" x-text="part.key === 'before_steps' ? 'Belum ada rangkaian aktif.' : 'Tidak ada rangkaian baru untuk diterapkan.'"></p>
                                            <ol class="space-y-2">
                                                <template x-for="(step, stepIndex) in row[part.key]" :key="stepIndex">
                                                    <li class="text-sm text-ink"><span class="font-medium" x-text="(stepIndex + 1) + '. ' + step.role_label"></span><span class="block text-muted" x-text="step.approver?.nama_lengkap ?? 'Pegawai tidak tersedia'"></span><span class="block font-mono text-xs text-muted" x-text="step.approver?.nip ? 'NIP ' + step.approver.nip : ''"></span></li>
                                                </template>
                                            </ol>
                                        </div>
                                    </template>
                                </div>
                            </details>
                        </li>
                    </template>
                </ul>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-y border-border bg-soft/40 text-xs uppercase tracking-wide text-muted"><tr><th class="px-3 py-3">Pegawai</th><th class="px-3 py-3">Dampak</th><th class="px-3 py-3">Atasan Langsung</th><th class="px-3 py-3">Rincian</th></tr></thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="row in pagedReviewRows" :key="`desktop-${row.employee_id}`">
                                <tr>
                                    <td class="px-3 py-3"><span class="block font-semibold text-ink" x-text="row.nama_lengkap"></span><span class="font-mono text-xs text-muted" x-text="'NIP ' + row.nip"></span></td>
                                    <td class="px-3 py-3"><x-ui.badge variant="muted"><span x-text="outcomeLabel(row.outcome)"></span></x-ui.badge><span class="mt-1 block text-xs text-muted" x-text="row.message"></span></td>
                                    <td class="px-3 py-3 text-ink" x-text="row.supervisor?.nama_lengkap ?? 'Belum tersedia'"></td>
                                    <td class="px-3 py-3">
                                        <details>
                                            <summary class="min-h-11 w-fit cursor-pointer rounded-sm py-3 font-medium text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2" :aria-label="'Lihat rangkaian sebelum dan sesudah untuk ' + row.nama_lengkap">Buka rincian</summary>
                                            <div class="grid gap-5 pb-2 lg:grid-cols-2">
                                                <template x-for="part in [{key: 'before_steps', label: 'Sebelum'}, {key: 'after_steps', label: 'Sesudah'}]" :key="part.key">
                                                    <div><h4 class="font-semibold text-ink" x-text="part.label"></h4><p x-show="!row[part.key]?.length" class="mt-1 text-xs text-muted">Tidak ada rangkaian.</p><ol class="mt-2 space-y-2"><template x-for="(chainStep, chainIndex) in row[part.key]" :key="chainIndex"><li class="text-xs text-ink"><span class="font-medium" x-text="`${chainIndex + 1}. ${chainStep.role_label}`"></span><span class="block text-muted" x-text="chainStep.approver?.nama_lengkap ?? 'Pegawai tidak tersedia'"></span></li></template></ol></div>
                                                </template>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <nav class="flex flex-wrap items-center justify-between gap-3" aria-label="Halaman hasil pratinjau">
                    <p class="text-xs text-muted" x-text="`Halaman ${reviewPage} dari ${reviewLastPage}`"></p>
                    <div class="flex gap-2"><x-ui.button type="button" variant="secondary" @click="setReviewPage(reviewPage - 1)" ::disabled="reviewPage <= 1" class="min-h-11">Sebelumnya</x-ui.button><x-ui.button type="button" variant="secondary" @click="setReviewPage(reviewPage + 1)" ::disabled="reviewPage >= reviewLastPage" class="min-h-11">Berikutnya</x-ui.button></div>
                </nav>
                <p id="batch-apply-help" class="text-sm text-muted" x-text="preview?.can_apply && applyCount > 0 ? 'Periksa rincian di atas. Konfirmasi berikutnya akan menerapkan rangkaian.' : 'Tidak ada pegawai yang dapat diterapkan dari pratinjau ini. Periksa sebab atau ubah pilihan.'" x-show="preview"></p>
                <x-ui.button x-show="preview" @click="openConfirmation()" ::disabled="!preview?.can_apply || applyCount === 0 || applying" aria-describedby="batch-apply-help" class="min-h-11"><span x-text="'Terapkan ke ' + applyCount + ' Pegawai'"></span></x-ui.button>
                <x-ui.button type="button" variant="secondary" @click="goToStep('pilih')" ::disabled="applying" class="min-h-11">Kembali ke Pilih Pegawai</x-ui.button>
            </div>
        </template>
    </section>
    <x-ui.modal id="batch-confirmation" show="confirmOpen" title="Konfirmasi Penerapan Rangkaian"
        description-id="batch-confirmation-description" close-action="closeConfirmation()" max-width="md"
        panel-class="[&_button]:min-h-11 [&_button]:min-w-11">
        <p id="batch-confirmation-description" class="text-sm text-ink">Terapkan rangkaian sesuai pratinjau terakhir?</p>
        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div><dt class="text-muted">Dibuat</dt><dd class="font-semibold text-ink" x-text="summary(preview).create"></dd></div>
            <div><dt class="text-muted">Diganti</dt><dd class="font-semibold text-ink" x-text="summary(preview).replace"></dd></div>
            <div><dt class="text-muted">Tidak berubah</dt><dd class="font-semibold text-ink" x-text="summary(preview).unchanged"></dd></div>
            <div><dt class="text-muted">Dilewati</dt><dd class="font-semibold text-ink" x-text="summary(preview).skipped"></dd></div>
        </dl>
        <p id="batch-confirmation-progress" x-show="applying" x-cloak class="mt-4 text-sm text-muted" role="status">Menerapkan rangkaian. Tunggu hingga hasil ditampilkan.</p>
        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-3">
                <x-ui.button variant="secondary" @click="closeConfirmation()" ::disabled="applying" aria-describedby="batch-confirmation-progress" data-modal-initial-focus="true" class="min-h-11">Batal</x-ui.button>
                <x-ui.button @click="apply()" ::disabled="applying || !preview?.can_apply || applyCount === 0" aria-describedby="batch-confirmation-progress" class="min-h-11"><span x-text="applying ? 'Menerapkan…' : 'Konfirmasi Penerapan'"></span></x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.card>
</section>
