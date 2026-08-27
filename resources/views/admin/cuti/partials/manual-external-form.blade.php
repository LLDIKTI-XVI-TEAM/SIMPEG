@php
    $editorId = $editorId ?? 'manual-approval';
    $allowCurrentPreview = $allowCurrentPreview ?? false;
    $approvalStepErrors = collect($errors->keys())
        ->contains(static fn (string $key): bool => $key === 'approval_steps' || str_starts_with($key, 'approval_steps.'));
    $approvalStepFieldErrors = [];
    $approvalStepFieldNames = [
        'step_type',
        'approver_source',
        'approver_employee_id',
        'approver_name',
        'approver_position',
        'approver_institution',
        'acted_on',
        'decision_note',
    ];

    foreach ($errors->getMessages() as $key => $messages) {
        if (preg_match('/^approval_steps\.(\d+)\.([a-z_]+)$/', $key, $matches) !== 1
            || ! in_array($matches[2], $approvalStepFieldNames, true)) {
            continue;
        }

        $approvalStepFieldErrors[(int) $matches[1]][$matches[2]] = implode(' ', $messages);
    }
@endphp

<section
    aria-labelledby="{{ $editorId }}-title"
    class="rounded-xl border border-border p-4"
    data-manual-approval-editor="{{ $editorId }}"
    x-data="manualExternalApprovalEditor(@js($initialApprovalSteps), @js($currentApprovalChainPreview), @js(route('cuti.manual.approver-lookup')), @js($editorId), @js($approvalStepFieldErrors))"
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h4 id="{{ $editorId }}-title" class="text-sm font-semibold text-ink">Riwayat Persetujuan Eksternal</h4>
            <p class="mt-1 text-xs leading-relaxed text-muted">
                Susun 2-10 tahap: maksimal 8 Verifikator, tepat satu Kepala Bagian, lalu tepat satu PYBMC pada tahap terakhir.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($allowCurrentPreview)
            <button
                type="button"
                x-on:click="openPreview()"
                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-xs font-semibold text-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            >
                Pratinjau Rangkaian Saat Ini
            </button>
            @endif
            <button
                type="button"
                x-on:click="addStep()"
                x-bind:disabled="steps.length >= 10"
                data-manual-approval-add="{{ $editorId }}"
                class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white focus:outline-none focus:ring-2 focus:ring-primary/30 disabled:opacity-50"
            >
                Tambah Tahap
            </button>
        </div>
    </div>

    @if ($approvalStepErrors)
        <div
            class="mt-4 rounded-xl border border-danger/30 bg-danger/5 p-4 text-sm text-danger"
            role="alert"
            tabindex="-1"
            x-init="focusFirstError($root, $el)"
            aria-label="Kesalahan tahap persetujuan"
        >
            <p class="font-semibold">Periksa kembali tahap persetujuan:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->getMessages() as $key => $messages)
                    @if ($key === 'approval_steps' || str_starts_with($key, 'approval_steps.'))
                        @foreach ($messages as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    <p class="sr-only" aria-live="polite" aria-atomic="true" x-text="editorStatus"></p>

    <p x-show="steps.length === 0" class="mt-4 rounded-xl border border-dashed border-border p-4 text-sm text-muted">
        Editor masih kosong. Tambahkan tahap satu per satu atau buka pratinjau, lalu salin rangkaian aktif pegawai.
    </p>

    <div class="mt-4 space-y-4">
        <template x-for="(step, index) in steps" :key="step.clientKey">
            <fieldset class="rounded-xl border border-border p-4" x-bind:data-step-key="step.clientKey">
                <legend class="px-1 text-sm font-semibold text-ink">Tahap <span x-text="index + 1"></span></legend>

                {{-- UUID pegawai hanya dikirim sebagai value tersembunyi hasil lookup, bukan input bebas pengguna. --}}
                <input type="hidden" x-bind:name="`approval_steps[${index}][step_type]`" x-bind:value="step.step_type">
                <input type="hidden" x-bind:name="`approval_steps[${index}][approver_source]`" x-bind:value="step.approver_source">
                <input type="hidden" x-bind:name="`approval_steps[${index}][approver_employee_id]`" x-bind:value="step.approver_employee_id ?? ''">
                <input type="hidden" x-bind:name="`approval_steps[${index}][approver_name]`" x-bind:value="step.approver_name ?? ''">
                <input type="hidden" x-bind:name="`approval_steps[${index}][approver_position]`" x-bind:value="step.approver_position ?? ''">
                <input type="hidden" x-bind:name="`approval_steps[${index}][approver_institution]`" x-bind:value="step.approver_institution ?? ''">
                <input type="hidden" x-bind:name="`approval_steps[${index}][acted_on]`" x-bind:value="step.acted_on ?? ''">
                <input type="hidden" x-bind:name="`approval_steps[${index}][decision_note]`" x-bind:value="step.decision_note ?? ''">

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label x-bind:for="`{{ $editorId }}-type-${index}`" class="mb-1 block text-xs font-semibold text-ink">Jenis tahap</label>
                        <select
                            x-bind:id="`{{ $editorId }}-type-${index}`"
                            x-model="step.step_type"
                            x-on:change="clearFieldError(step.clientKey, 'step_type')"
                            x-bind:aria-invalid="step.fieldErrors.step_type ? 'true' : 'false'"
                            x-bind:aria-describedby="step.fieldErrors.step_type ? `{{ $editorId }}-type-${index}-error` : null"
                            data-step-focus="type"
                            class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="verifier">Verifikator</option>
                            <option value="kepala_bagian">Kepala Bagian</option>
                            <option value="pybmc">PYBMC</option>
                        </select>
                        <p x-show="step.fieldErrors.step_type" x-bind:id="`{{ $editorId }}-type-${index}-error`" x-text="step.fieldErrors.step_type" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                    <div>
                        <label x-bind:for="`{{ $editorId }}-source-${index}`" class="mb-1 block text-xs font-semibold text-ink">Sumber approver</label>
                        <select
                            x-bind:id="`{{ $editorId }}-source-${index}`"
                            x-model="step.approver_source"
                            x-on:change="changeApproverSource(step.clientKey)"
                            x-bind:aria-invalid="step.fieldErrors.approver_source ? 'true' : 'false'"
                            x-bind:aria-describedby="step.fieldErrors.approver_source ? `{{ $editorId }}-source-${index}-error` : null"
                            class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="simpeg_employee">Pegawai SIMPEG</option>
                            <option value="external_official">Pejabat eksternal</option>
                        </select>
                        <p x-show="step.fieldErrors.approver_source" x-bind:id="`{{ $editorId }}-source-${index}-error`" x-text="step.fieldErrors.approver_source" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                </div>

                <div x-show="step.approver_source === 'simpeg_employee'" class="mt-4" x-on:click.outside="closeLookup(step)">
                    <label x-bind:for="`{{ $editorId }}-lookup-${index}`" class="mb-1 block text-xs font-semibold text-ink">Cari pegawai berdasarkan nama atau NIP</label>
                    <input
                        x-bind:id="`{{ $editorId }}-lookup-${index}`"
                        type="search"
                        autocomplete="off"
                        x-model="step.lookupQuery"
                        x-on:input="scheduleLookup(step.clientKey, $event.target.value)"
                        x-on:focus="if (step.lookupState !== 'idle') step.lookupOpen = true"
                        x-on:keydown.arrow-down.prevent="moveLookupActive(step, 1)"
                        x-on:keydown.arrow-up.prevent="moveLookupActive(step, -1)"
                        x-on:keydown.enter="chooseActiveApprover(step, $event)"
                        x-on:keydown.escape.prevent="closeLookup(step)"
                        role="combobox"
                        aria-autocomplete="list"
                        x-bind:aria-expanded="step.lookupOpen.toString()"
                        x-bind:aria-controls="`{{ $editorId }}-lookup-${index}-listbox`"
                        x-bind:aria-activedescendant="step.lookupActiveIndex >= 0 ? `{{ $editorId }}-lookup-${index}-option-${step.lookupActiveIndex}` : null"
                        x-bind:aria-invalid="step.fieldErrors.approver_employee_id ? 'true' : 'false'"
                        x-bind:aria-describedby="step.fieldErrors.approver_employee_id ? `{{ $editorId }}-lookup-${index}-help {{ $editorId }}-lookup-${index}-status {{ $editorId }}-lookup-${index}-error` : `{{ $editorId }}-lookup-${index}-help {{ $editorId }}-lookup-${index}-status`"
                        data-step-focus="lookup"
                        class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20"
                        placeholder="Ketik minimal 2 karakter"
                    >
                    <p x-bind:id="`{{ $editorId }}-lookup-${index}-help`" class="mt-1 text-xs text-muted">Ketik minimal 2 karakter, lalu pilih satu pegawai dari hasil pencarian.</p>
                    <p x-bind:id="`{{ $editorId }}-lookup-${index}-status`" class="sr-only" aria-live="polite" aria-atomic="true" x-text="lookupStatusMessage(step)"></p>
                    <div
                        x-show="step.lookupOpen && !step.lookupLoading && step.lookupState === 'results' && step.lookupResults.length > 0"
                        x-bind:id="`{{ $editorId }}-lookup-${index}-listbox`"
                        role="listbox"
                        aria-label="Hasil pencarian approver"
                        class="mt-2 max-h-52 space-y-1 overflow-y-auto rounded-lg border border-border p-2"
                    >
                        <template x-for="approver in step.lookupResults" :key="approver.id">
                            <button
                                type="button"
                                x-on:click="chooseApprover(step.clientKey, approver)"
                                x-on:mousemove="step.lookupActiveIndex = step.lookupResults.indexOf(approver)"
                                x-bind:id="`{{ $editorId }}-lookup-${index}-option-${step.lookupResults.indexOf(approver)}`"
                                role="option"
                                x-bind:aria-selected="step.lookupActiveIndex === step.lookupResults.indexOf(approver)"
                                class="flex min-h-11 w-full flex-col justify-center rounded-lg px-3 py-2 text-left hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                <span class="text-sm font-semibold text-ink" x-text="approver.nama_lengkap"></span>
                                <span class="text-xs text-muted" x-text="`NIP ${approver.nip}${approver.jabatan_terakhir ? ` - ${approver.jabatan_terakhir}` : ''}`"></span>
                            </button>
                        </template>
                    </div>
                    <div
                        x-show="step.lookupOpen && (step.lookupLoading || step.lookupState === 'empty' || step.lookupState === 'error' || step.lookupState === 'session_expired')"
                        class="mt-2 max-h-52 space-y-1 overflow-y-auto rounded-lg border border-border p-2"
                    >
                        <p x-show="step.lookupLoading" class="min-h-11 px-3 py-3 text-xs text-muted">Mencari approver...</p>
                        <p x-show="!step.lookupLoading && step.lookupState === 'empty'" class="min-h-11 px-3 py-3 text-xs text-muted">Approver tidak ditemukan.</p>
                        <div x-show="!step.lookupLoading && step.lookupState === 'error'" class="space-y-2 px-3 py-2">
                            <p class="text-xs text-danger" x-text="step.lookupError" role="alert"></p>
                            <button type="button" x-on:click="retryLookup(step.clientKey)" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-xs font-semibold text-primary focus:outline-none focus:ring-2 focus:ring-primary/20">Coba Lagi</button>
                        </div>
                        <div x-show="!step.lookupLoading && step.lookupState === 'session_expired'" class="space-y-2 px-3 py-2">
                            <p class="text-xs text-danger" x-text="step.lookupError" role="alert"></p>
                            <button type="button" x-on:click="window.location.reload()" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-3 py-2 text-xs font-semibold text-primary focus:outline-none focus:ring-2 focus:ring-primary/20">Muat Ulang Halaman</button>
                        </div>
                    </div>
                    <p x-show="step.fieldErrors.approver_employee_id" x-bind:id="`{{ $editorId }}-lookup-${index}-error`" x-text="step.fieldErrors.approver_employee_id" class="mt-1 text-xs text-danger" role="alert"></p>
                    <div x-show="step.approver_employee_id" class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-soft px-3 py-2">
                        <span class="min-w-0 break-words text-sm font-semibold text-ink" x-text="step.approver_label"></span>
                        <button type="button" x-on:click="clearInternalApprover(step.clientKey)" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-xs font-semibold text-danger focus:outline-none focus:ring-2 focus:ring-danger/20">Hapus</button>
                    </div>
                </div>

                <div x-show="step.approver_source === 'external_official'" class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label x-bind:for="`{{ $editorId }}-name-${index}`" class="mb-1 block text-xs font-semibold text-ink">Nama pejabat eksternal <span class="text-danger" aria-hidden="true">*</span></label>
                        <input x-bind:id="`{{ $editorId }}-name-${index}`" x-model="step.approver_name" x-on:input="clearFieldError(step.clientKey, 'approver_name')" x-bind:required="step.approver_source === 'external_official'" x-bind:aria-invalid="step.fieldErrors.approver_name ? 'true' : 'false'" x-bind:aria-describedby="step.fieldErrors.approver_name ? `{{ $editorId }}-name-${index}-error` : null" type="text" maxlength="255" class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <p x-show="step.fieldErrors.approver_name" x-bind:id="`{{ $editorId }}-name-${index}-error`" x-text="step.fieldErrors.approver_name" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                    <div>
                        <label x-bind:for="`{{ $editorId }}-position-${index}`" class="mb-1 block text-xs font-semibold text-ink">Jabatan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input x-bind:id="`{{ $editorId }}-position-${index}`" x-model="step.approver_position" x-on:input="clearFieldError(step.clientKey, 'approver_position')" x-bind:required="step.approver_source === 'external_official'" x-bind:aria-invalid="step.fieldErrors.approver_position ? 'true' : 'false'" x-bind:aria-describedby="step.fieldErrors.approver_position ? `{{ $editorId }}-position-${index}-error` : null" type="text" maxlength="255" class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <p x-show="step.fieldErrors.approver_position" x-bind:id="`{{ $editorId }}-position-${index}-error`" x-text="step.fieldErrors.approver_position" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                    <div class="md:col-span-2">
                        <label x-bind:for="`{{ $editorId }}-institution-${index}`" class="mb-1 block text-xs font-semibold text-ink">Instansi <span class="text-danger" aria-hidden="true">*</span></label>
                        <input x-bind:id="`{{ $editorId }}-institution-${index}`" x-model="step.approver_institution" x-on:input="clearFieldError(step.clientKey, 'approver_institution')" x-bind:required="step.approver_source === 'external_official'" x-bind:aria-invalid="step.fieldErrors.approver_institution ? 'true' : 'false'" x-bind:aria-describedby="step.fieldErrors.approver_institution ? `{{ $editorId }}-institution-${index}-error` : null" type="text" maxlength="255" class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <p x-show="step.fieldErrors.approver_institution" x-bind:id="`{{ $editorId }}-institution-${index}-error`" x-text="step.fieldErrors.approver_institution" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label x-bind:for="`{{ $editorId }}-acted-${index}`" class="mb-1 block text-xs font-semibold text-ink">Tanggal keputusan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input x-bind:id="`{{ $editorId }}-acted-${index}`" x-model="step.acted_on" x-on:input="clearFieldError(step.clientKey, 'acted_on')" x-bind:aria-invalid="step.fieldErrors.acted_on ? 'true' : 'false'" x-bind:aria-describedby="step.fieldErrors.acted_on ? `{{ $editorId }}-acted-${index}-error` : null" type="date" required class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <p x-show="step.fieldErrors.acted_on" x-bind:id="`{{ $editorId }}-acted-${index}-error`" x-text="step.fieldErrors.acted_on" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                    <div>
                        <label x-bind:for="`{{ $editorId }}-note-${index}`" class="mb-1 block text-xs font-semibold text-ink">Catatan keputusan (opsional)</label>
                        <input x-bind:id="`{{ $editorId }}-note-${index}`" x-model="step.decision_note" x-on:input="clearFieldError(step.clientKey, 'decision_note')" x-bind:aria-invalid="step.fieldErrors.decision_note ? 'true' : 'false'" x-bind:aria-describedby="step.fieldErrors.decision_note ? `{{ $editorId }}-note-${index}-error` : null" type="text" maxlength="2000" class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <p x-show="step.fieldErrors.decision_note" x-bind:id="`{{ $editorId }}-note-${index}-error`" x-text="step.fieldErrors.decision_note" class="mt-1 text-xs text-danger" role="alert"></p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" x-on:click="removeStep(index)" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-danger px-3 text-xs font-semibold text-danger focus:outline-none focus:ring-2 focus:ring-danger/20" x-bind:aria-label="`Hapus tahap ${index + 1}`">Hapus tahap</button>
                </div>
            </fieldset>
        </template>
    </div>

    @if ($allowCurrentPreview)
        @include('admin.cuti.partials.manual-external-chain-preview')
    @endif
</section>
