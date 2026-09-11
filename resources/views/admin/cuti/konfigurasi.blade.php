@pushOnce('head')
    @vite('resources/js/pages/chain-batch-editor.js')
    @vite('resources/js/pages/cuti-config-navigation.js')
@endPushOnce

<x-layouts.app title="Konfigurasi Approval Cuti">
    @php
        $oldSteps = old('steps');
        $oldStepItems = is_array($oldSteps)
            ? collect($oldSteps)->filter(fn (mixed $step): bool => is_array($step))
            : collect();
        $pybmcEmployeeId = is_array($oldSteps)
            ? $oldStepItems->firstWhere('step_type', 'pybmc')['approver_employee_id'] ?? ''
            : $initialPybmcEmployeeId;
        $isCanonicalNumericStepKey = static function (int|string $key): bool {
            $isNonNegativeInteger = is_int($key) && $key >= 0;
            $isCanonicalNumericString = is_string($key)
                && ctype_digit($key)
                && (string) (int) $key === $key;

            return $isNonNegativeInteger || $isCanonicalNumericString;
        };
        $hydrateVerifier = function (array $step, int $renderIndex, int|string $errorKey) use ($errors): array {
            return [
                'role_label' => $step['role_label'] ?? 'Verifikator '.($renderIndex + 1),
                'approver_employee_id' => $step['approver_employee_id'] ?? '',
                'client_key' => "existing-{$renderIndex}",
                'validation_errors' => [
                    'role_label' => $errors->first("steps.{$errorKey}.role_label"),
                    'approver_employee_id' => $errors->first("steps.{$errorKey}.approver_employee_id"),
                ],
            ];
        };

        if (is_array($oldSteps)) {
            $verifierSteps = $oldStepItems
                ->filter(fn (array $step): bool => ($step['step_type'] ?? null) === 'verifier')
                // Simpan key request sebelum values() mengurutkan ulang row untuk kebutuhan editor.
                ->map(fn (array $step, int|string $originalKey): array => [
                    'step' => $step,
                    'original_key' => $originalKey,
                ])
                ->values()
                ->map(fn (array $entry, int $renderIndex): array => $hydrateVerifier(
                    $entry['step'],
                    $renderIndex,
                    $entry['original_key'],
                ))
                ->all();
            $kepalaBagianKey = $oldStepItems->search(
                fn (array $step): bool => ($step['step_type'] ?? null) === 'kepala_bagian',
            );
            $pybmcKey = $oldStepItems->search(
                fn (array $step): bool => ($step['step_type'] ?? null) === 'pybmc',
            );
            $kepalaBagianError = $kepalaBagianKey === false
                ? ''
                : $errors->first("steps.{$kepalaBagianKey}.approver_employee_id");
            $pybmcError = $pybmcKey === false
                ? ''
                : $errors->first("steps.{$pybmcKey}.approver_employee_id");

            if ($pybmcKey === '_pybmc' && $pybmcError === '') {
                // FormRequest memindahkan _pybmc setelah seluruh entry numeric, termasuk entry malformed.
                $numericStepKeys = collect(array_keys($oldSteps))
                    ->reject(fn (int|string $key): bool => $key === '_pybmc');

                if ($numericStepKeys->every($isCanonicalNumericStepKey)) {
                    $normalizedPybmcKey = $numericStepKeys->count();
                    $pybmcError = $errors->first("steps.{$normalizedPybmcKey}.approver_employee_id");
                }
            }
        } else {
            $verifierSteps = collect($initialVerifierSteps)
                ->values()
                ->map(fn (array $step, int $index): array => $hydrateVerifier($step, $index, $index))
                ->all();
            $kepalaBagianError = $errors->first('steps.'.count($verifierSteps).'.approver_employee_id');
            $pybmcError = $errors->first('steps.'.(count($verifierSteps) + 1).'.approver_employee_id');
        }

        // Redirect validasi memulihkan draft belum tersimpan, bukan baseline bersih.
        // Kehadiran key tetap dihitung saat pilihan dikosongkan; editor tanpa izin tidak dipulihkan.
        $restoredEditor = null;
        $oldInput = session()->getOldInput();
        if ($errors->any() && is_array($oldInput)) {
            if ($hasGlobalIdentityScope
                && (array_key_exists('approver_employee_id', $oldInput) || array_key_exists('pybmc_reason', $oldInput))) {
                $restoredEditor = 'pybmc';
            } elseif ($selectedEmployee && $canAssignKepalaBagian
                && (array_key_exists('kepala_bagian_id', $oldInput) || array_key_exists('effective_date', $oldInput))) {
                $restoredEditor = 'atasan';
            } elseif ($selectedEmployee
                && (array_key_exists('steps', $oldInput) || array_key_exists('reason', $oldInput))) {
                $restoredEditor = 'pegawai';
            }
        }
    @endphp

    <div
        class="space-y-6"
        x-data="{
        ...cutiConfigNavigation({
            initialTab: @js($initialTab ?? 'pegawai'),
            initialStep: @js($initialStep ?? 'susun'),
            restoredEditor: @js($restoredEditor),
            canViewAudit: @js((bool) ($canViewAudit ?? false)),
            hasGlobalIdentityScope: @js((bool) $hasGlobalIdentityScope),
            successUrl: @js(route('cuti.config')),
            batchSuccessCounts: @js(session('cuti_batch_success_counts')),
        }),
        nextVerifierKey: @js(count($verifierSteps)),
        verifiers: @js($verifierSteps),
        pybmcEmployeeId: @js($pybmcEmployeeId),
        kepalaBagianError: @js($kepalaBagianError),
        pybmcError: @js($pybmcError),
        announcement: '',
        maxVerifierSteps: 8,
        approverLookupEndpoint: @js(route('cuti.config.batch.approvers')),
        approverSearch: @js($approverSearch),
        approverSearchSequence: 0,
        approverSearchLoading: false,
        approverSearchMessage: '',
        approverSearchError: '',
        initialApproverCandidateIds: @js($approverCandidates->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()),
        approverLookupCandidates: [],
        invalidateApproverSearch() {
            this.approverSearchSequence++;
            this.approverSearchLoading = false;
            this.approverSearchMessage = '';
            this.approverSearchError = '';
            const selectedIds = new Set([
                ...this.verifiers.map((verifier) => verifier.approver_employee_id),
                this.pybmcEmployeeId,
            ].filter(Boolean));
            // Pilihan editor tetap sah; hanya hasil pencarian yang belum dipilih yang dibuang.
            this.approverLookupCandidates = this.approverLookupCandidates
                .filter((candidate) => selectedIds.has(candidate.id));
        },
        async searchApproverCandidates() {
            this.invalidateApproverSearch();
            const sequence = this.approverSearchSequence;
            const query = this.approverSearch.trim();

            if (query.length < 2) {
                this.approverSearchError = 'Ketik minimal 2 karakter.';
                return;
            }

            this.approverSearchLoading = true;

            try {
                const response = await fetch(`${this.approverLookupEndpoint}?q=${encodeURIComponent(query)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (! response.ok) {
                    throw new Error('Lookup kandidat tidak tersedia.');
                }

                const result = await response.json();
                // Respons usang tidak boleh mengganti kandidat, pesan, atau loading pencarian terbaru.
                if (sequence !== this.approverSearchSequence) return;
                const candidates = Array.isArray(result.data) ? result.data : [];
                const selectedIds = new Set([
                    ...this.verifiers.map((verifier) => verifier.approver_employee_id),
                    this.pybmcEmployeeId,
                ].filter(Boolean));
                const preservedCandidates = this.approverLookupCandidates
                    .filter((candidate) => selectedIds.has(candidate.id));
                const uniqueCandidates = new Map(
                    [...preservedCandidates, ...candidates]
                        .filter((candidate) => ! this.initialApproverCandidateIds.includes(candidate.id))
                        .map((candidate) => [candidate.id, candidate]),
                );

                this.approverLookupCandidates = [...uniqueCandidates.values()];
                this.approverSearchMessage = candidates.length > 0
                    ? `${candidates.length} kandidat ditemukan.`
                    : 'Kandidat tidak ditemukan.';
            } catch (error) {
                if (sequence !== this.approverSearchSequence) return;
                this.approverSearchError = 'Pencarian kandidat gagal. Coba lagi.';
            } finally {
                if (sequence === this.approverSearchSequence) this.approverSearchLoading = false;
            }
        },
        addVerifier() {
            if (this.verifiers.length >= this.maxVerifierSteps) return;
            let number = 1;
            while (this.verifiers.some((verifier) => verifier.role_label === `Verifikator ${number}`)) number++;
            const clientKey = `new-${++this.nextVerifierKey}`;
            this.verifiers.push({
                role_label: `Verifikator ${number}`,
                approver_employee_id: '',
                client_key: clientKey,
                validation_errors: {
                    role_label: '',
                    approver_employee_id: '',
                },
            });
            this.markDirty('pegawai');
            this.announce('Verifikator ditambahkan.');
            this.$nextTick(() => this.focusVerifier(clientKey));
        },
        removeVerifier(index) {
            const neighborKey = this.verifiers[index + 1]?.client_key
                ?? this.verifiers[index - 1]?.client_key
                ?? null;
            this.verifiers.splice(index, 1);
            this.markDirty('pegawai');
            this.announce('Verifikator dihapus.');
            this.$nextTick(() => {
                if (neighborKey) {
                    this.focusVerifier(neighborKey);
                } else {
                    this.$refs.addVerifierButton?.focus();
                }
            });
        },
        moveVerifier(index, direction) {
            const nextIndex = index + direction;
            if (nextIndex < 0 || nextIndex >= this.verifiers.length) return;
            const verifier = this.verifiers[index];
            [this.verifiers[index], this.verifiers[nextIndex]] = [this.verifiers[nextIndex], this.verifiers[index]];
            this.markDirty('pegawai');
            this.announce(direction < 0
                ? 'Verifikator dipindahkan naik.'
                : 'Verifikator dipindahkan turun.');
            this.$nextTick(() => this.focusVerifier(verifier.client_key));
        },
        focusVerifier(clientKey) {
            const row = document.querySelector(`[data-verifier-key=${clientKey}]`);
            row?.querySelector('[data-verifier-label]')?.focus();
        },
        announce(message) {
            this.announcement = '';
            this.$nextTick(() => { this.announcement = message; });
        },
    }"
        @submit.capture="guardSubmit($event)"
        @click.capture="guardLink($event)"
        @input.capture="trackFormChange($event)"
        @change.capture="trackFormChange($event)"
        @cuti-config-state.window="syncBatchState($event.detail)"
        @cuti-config-step.window="syncBatchStep($event.detail.step)"
        @cuti-config-applied.window="finishBatch($event.detail)"
    >
        {{-- PAGE HEADER & BREADCRUMB --}}
        <p class="sr-only" aria-live="polite" aria-atomic="true" x-text="announcement"></p>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Konfigurasi Approval Cuti</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Konfigurasi Approval Cuti']
                ]" />
            </div>
        </div>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif
        <p x-show="navigationNotice" x-cloak x-text="navigationNotice" class="rounded-xl border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink" role="status"></p>

        {{-- Ringkasan status konfigurasi: dibaca sekilas sebelum admin menyusun chain. --}}
        <div @class(['grid gap-4', 'sm:grid-cols-3' => $canViewAudit ?? false, 'sm:grid-cols-2' => ! ($canViewAudit ?? false)])>
            <div class="flex items-center gap-4 rounded-xl border border-border bg-surface p-4 shadow-sm">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-2xl font-semibold leading-tight text-ink">{{ $chainStats['active'] }}</p>
                    <p class="text-xs text-muted">Chain pegawai aktif</p>
                </div>
            </div>
            <div class="flex items-center gap-4 rounded-xl border border-border bg-surface p-4 shadow-sm">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-tight text-ink" title="{{ $globalPybmc?->approver?->nama_lengkap ?? 'Belum ditetapkan' }}">{{ $globalPybmc?->approver?->nama_lengkap ?? 'Belum ditetapkan' }}</p>
                    <p class="text-xs text-muted">PYBMC global saat ini</p>
                </div>
            </div>
            @if ($canViewAudit ?? false)
            <div class="flex items-center gap-4 rounded-xl border border-border bg-surface p-4 shadow-sm">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-2xl font-semibold leading-tight text-ink">{{ count($auditRows) }}</p>
                    <p class="text-xs text-muted">Perubahan tercatat terakhir</p>
                </div>
            </div>
            @endif
        </div>

        <x-ui.tabs label="Bagian konfigurasi approval cuti" @keydown="handleTabKey($event)" class="scrollbar-none">
            <x-ui.tab active="activeTab === 'pegawai'" click="switchTab('pegawai')" id="cuti-config-tab-pegawai" data-config-tab="pegawai" aria-controls="cuti-config-panel-pegawai" ::tabindex="activeTab === 'pegawai' ? 0 : -1" class="min-h-11">Per Pegawai</x-ui.tab>
            <x-ui.tab active="activeTab === 'rangkaian'" click="switchTab('rangkaian')" id="cuti-config-tab-rangkaian" data-config-tab="rangkaian" aria-controls="cuti-config-panel-rangkaian" ::tabindex="activeTab === 'rangkaian' ? 0 : -1" class="min-h-11">
                Terapkan Rangkaian
                <span x-show="batchApplying" x-cloak class="ml-1 text-xs font-semibold" aria-label="Penerapan sedang berlangsung">Memproses</span>
                <span x-show="!batchApplying && batchNotice" x-cloak class="ml-1 h-2 w-2 rounded-full bg-warning" :aria-label="batchNotice" title="Ada pembaruan pada penerapan massal"></span>
            </x-ui.tab>
            @if ($hasGlobalIdentityScope)
                <x-ui.tab active="activeTab === 'pybmc'" click="switchTab('pybmc')" id="cuti-config-tab-pybmc" data-config-tab="pybmc" aria-controls="cuti-config-panel-pybmc" ::tabindex="activeTab === 'pybmc' ? 0 : -1" class="min-h-11">PYBMC Global</x-ui.tab>
            @endif
            @if ($canViewAudit ?? false)
                <x-ui.tab active="activeTab === 'riwayat'" click="switchTab('riwayat')" id="cuti-config-tab-riwayat" data-config-tab="riwayat" aria-controls="cuti-config-panel-riwayat" ::tabindex="activeTab === 'riwayat' ? 0 : -1" class="min-h-11">Riwayat Perubahan</x-ui.tab>
            @endif
        </x-ui.tabs>

        <div id="cuti-config-panel-pegawai" role="tabpanel" aria-labelledby="cuti-config-tab-pegawai" x-show="activeTab === 'pegawai'" x-cloak>
        <div>
        <div class="space-y-6">
        <section class="rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="employee-chain-heading">
            <div class="border-b border-border bg-soft/30 px-5 py-4">
                <h3 id="employee-chain-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Chain Approval Pegawai</h3>
            </div>

            <x-cuti.employee-combobox
                id="employee-search"
                :action="route('cuti.config')"
                :lookup-endpoint="route('cuti.config.batch.targets')"
                name="employee_id"
                query-name="search"
                :query-value="$search"
                :selected-id="$selectedEmployee?->id"
                :selected-label="$selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : null"
                :preserved="['approver_search' => $approverSearch]"
                :clear-url="route('cuti.config', array_filter(['approver_search' => $approverSearch]))"
                :fallback-options="$targetEmployees"
                label="Cari Pegawai"
                placeholder="Nama atau NIP"
                help="Ketik minimal 2 karakter."
                submit-label="Cari Pegawai"
                class="border-b border-border px-5 py-4"
                data-config-employee-picker
                data-config-selection-restore-id="{{ $selectedEmployee?->id }}"
                data-config-selection-restore-label="{{ $selectedEmployee ? $selectedEmployee->nama_lengkap . ' (' . $selectedEmployee->nip . ')' : '' }}"
                x-on:config-selection-restore="selectedId = $el.dataset.configSelectionRestoreId; selectedLabel = $el.dataset.configSelectionRestoreLabel; query = selectedLabel; results = []; open = false"
            />

            @if ($selectedEmployee)
                @if ($selectedKepalaBagian)
                    <form method="GET" action="{{ route('cuti.config') }}" @submit.prevent="searchApproverCandidates()" data-config-local-form class="space-y-1 border-b border-border bg-soft/20 px-5 py-4">
                        <input type="hidden" name="search" value="{{ $search }}">
                        <input type="hidden" name="employee_id" value="{{ $selectedEmployee->id }}">
                        <label for="approver-search" class="text-xs font-bold uppercase tracking-wider text-ink">Cari Kandidat Approver</label>
                        <div class="flex items-start gap-3">
                            <input id="approver-search" name="approver_search" type="search" value="{{ $approverSearch }}" x-model="approverSearch" @input="invalidateApproverSearch()" placeholder="Nama atau NIP" aria-describedby="approver-search-help approver-search-status" class="min-h-11 min-w-0 flex-1 rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <x-ui.button type="submit" variant="secondary" class="min-h-11 shrink-0" ::disabled="approverSearchLoading" x-text="approverSearchLoading ? 'Mencari...' : 'Cari Kandidat'">Cari Kandidat</x-ui.button>
                        </div>
                        <p id="approver-search-help" class="text-xs text-muted">Hasil pencarian mengisi pilihan Verifikator dan PYBMC.</p>
                        <p id="approver-search-status" class="text-xs" aria-live="polite" aria-atomic="true">
                            <span x-show="approverSearchMessage" x-text="approverSearchMessage" class="text-muted"></span>
                            <span x-show="approverSearchError" x-text="approverSearchError" class="font-semibold text-danger"></span>
                        </p>
                    </form>
                @endif

                @php
                    $kabagFormOpen = ! $selectedKepalaBagian
                        || $errors->hasAny(['kepala_bagian_id', 'effective_date', 'redirect_to']);
                    $rawOldKepalaBagianId = old('kepala_bagian_id', '');
                    $oldKepalaBagianId = is_string($rawOldKepalaBagianId) ? $rawOldKepalaBagianId : '';
                @endphp

                @if ($canAssignKepalaBagian)
                    <div
                        class="border-b border-border bg-soft/20 px-5 py-4"
                        x-data="{
                            kabagFormOpen: @js((bool) $kabagFormOpen),
                            kabagLookupEndpoint: @js(route('pegawai.supervisor-lookup', $selectedEmployee->id)),
                            kabagQuery: @js($oldKepalaBagianLabel ?? ''),
                            kabagResults: [],
                            kabagOpen: false,
                            kabagLoading: false,
                            kabagError: '',
                            kabagSelectionError: '',
                            kabagSelectedId: @js($oldKepalaBagianId),
                            kabagSelectedName: @js($oldKepalaBagianLabel ?? ''),
                            kabagActiveIndex: -1,
                            kabagRequestId: 0,
                            kabagSearchTimer: null,
                            searchKabag() {
                                window.clearTimeout(this.kabagSearchTimer);
                                this.kabagRequestId++;
                                this.kabagError = '';
                                this.kabagSelectionError = '';
                                if (this.kabagQuery !== this.kabagSelectedName) {
                                    this.kabagSelectedId = '';
                                }
                                if (this.kabagQuery.trim().length < 2) {
                                    this.kabagResults = [];
                                    this.kabagOpen = false;
                                    this.kabagLoading = false;
                                    return;
                                }
                                this.kabagSearchTimer = window.setTimeout(() => this.fetchKabagCandidates(), 250);
                            },
                            async fetchKabagCandidates() {
                                const requestId = ++this.kabagRequestId;
                                this.kabagLoading = true;
                                this.kabagOpen = true;
                                this.kabagActiveIndex = -1;
                                try {
                                    const response = await fetch(`${this.kabagLookupEndpoint}?q=${encodeURIComponent(this.kabagQuery.trim())}`, {
                                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                                    });
                                    if (!response.ok) {
                                        throw new Error('Lookup Atasan Langsung tidak tersedia.');
                                    }
                                    const result = await response.json();
                                    if (requestId !== this.kabagRequestId) return;
                                    this.kabagResults = Array.isArray(result.data) ? result.data : [];
                                } catch (error) {
                                    if (requestId !== this.kabagRequestId) return;
                                    this.kabagResults = [];
                                    this.kabagError = 'Pencarian Atasan Langsung gagal. Coba lagi.';
                                } finally {
                                    if (requestId === this.kabagRequestId) {
                                        this.kabagLoading = false;
                                    }
                                }
                            },
                            selectKabag(candidate) {
                                this.kabagRequestId++;
                                this.kabagSelectionError = '';
                                this.kabagSelectedId = candidate.id;
                                this.kabagSelectedName = candidate.nama_lengkap;
                                this.kabagQuery = candidate.nama_lengkap;
                                this.kabagResults = [];
                                this.kabagActiveIndex = -1;
                                this.kabagOpen = false;
                                this.markDirty('atasan');
                            },
                            closeKabagLookup() {
                                window.setTimeout(() => { this.kabagOpen = false; }, 120);
                            },
                            moveKabagActiveIndex(direction) {
                                if (! this.kabagOpen || this.kabagResults.length === 0) return;
                                const next = this.kabagActiveIndex + direction;
                                this.kabagActiveIndex = Math.min(Math.max(next, 0), this.kabagResults.length - 1);
                            },
                            chooseActiveKabag() {
                                if (this.kabagActiveIndex >= 0 && this.kabagResults[this.kabagActiveIndex]) {
                                    this.selectKabag(this.kabagResults[this.kabagActiveIndex]);
                                }
                            },
                            guardKabagSubmit(event) {
                                if (! this.kabagSelectedId) {
                                    event.preventDefault();
                                    this.kabagSelectionError = 'Pilih Atasan Langsung dari hasil pencarian terlebih dahulu.';
                                }
                            }
                        }"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h4 class="text-sm font-semibold text-ink">Penetapan Atasan Langsung</h4>
                                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-muted">
                                    @if ($selectedKepalaBagian)
                                        Atasan Langsung efektif: <span class="font-semibold text-ink">{{ $selectedKepalaBagian->nama_lengkap }} ({{ $selectedKepalaBagian->nip }})</span>
                                    @else
                                        Belum ada Atasan Langsung efektif — tetapkan di bawah ini.
                                    @endif
                                </p>
                            </div>
                            @if ($selectedKepalaBagian)
                                <x-ui.button type="button" variant="secondary" size="sm" @click="kabagFormOpen = ! kabagFormOpen" ::aria-expanded="kabagFormOpen.toString()" aria-controls="kabag-inline-form" class="shrink-0">
                                    <span x-text="kabagFormOpen ? 'Tutup Form' : 'Ubah Atasan Langsung'"></span>
                                </x-ui.button>
                            @endif
                        </div>

                        <form
                            id="kabag-inline-form"
                            x-show="kabagFormOpen"
                            x-cloak
                            method="POST"
                            action="{{ route('pegawai.assign-atasan', $selectedEmployee->id) }}"
                            @submit="guardKabagSubmit($event)"
                            data-config-form="atasan"
                            class="mt-4"
                        >
                            @csrf
                            <input type="hidden" name="redirect_to" value="cuti-config">
                            <input type="hidden" name="kepala_bagian_id" :value="kabagSelectedId">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_13rem_auto] sm:items-start">
                                <div class="space-y-1">
                                    <label for="kabag_inline_lookup" class="text-xs font-bold uppercase tracking-wider text-ink">Cari Atasan Langsung</label>
                                    <div class="relative">
                                        <input
                                            id="kabag_inline_lookup"
                                            x-model="kabagQuery"
                                            @input="searchKabag()"
                                            @focus="kabagQuery.trim().length >= 2 && (kabagOpen = true)"
                                            @blur="closeKabagLookup()"
                                            @keydown.arrow-down.prevent="moveKabagActiveIndex(1)"
                                            @keydown.arrow-up.prevent="moveKabagActiveIndex(-1)"
                                            @keydown.enter.prevent="chooseActiveKabag()"
                                            @keydown.escape.prevent="kabagOpen = false"
                                            type="search"
                                            autocomplete="off"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            :aria-expanded="kabagOpen.toString()"
                                            aria-controls="kabag_inline_lookup_results"
                                            :aria-activedescendant="kabagActiveIndex >= 0 ? `kabag_inline_option_${kabagActiveIndex}` : null"
                                            aria-describedby="kabag_inline_lookup_help kabag_inline_selection_error {{ $errors->has('kepala_bagian_id') ? 'kabag_inline_lookup_error' : '' }}"
                                            :aria-invalid="{{ $errors->has('kepala_bagian_id') ? 'true' : 'false' }}"
                                            placeholder="Ketik minimal 2 karakter nama atau NIP"
                                            class="w-full rounded-xl border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 {{ $errors->has('kepala_bagian_id') ? 'border-danger focus:border-danger focus:ring-danger/20' : 'border-border' }}"
                                        >
                                        <div
                                            id="kabag_inline_lookup_results"
                                            x-cloak
                                            x-show="kabagOpen"
                                            role="listbox"
                                            aria-label="Hasil pencarian Atasan Langsung"
                                            class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-border bg-surface p-1 shadow-md"
                                        >
                                            <div x-show="kabagLoading" class="flex items-center gap-2 px-3 py-2 text-xs text-muted">
                                                <x-ui.loading size="sm" color="primary" />
                                                Memuat kandidat.
                                            </div>
                                            <p x-show="!kabagLoading && kabagError" x-text="kabagError" class="px-3 py-2 text-xs text-danger"></p>
                                            <p x-show="!kabagLoading && !kabagError && kabagResults.length === 0" class="px-3 py-2 text-xs text-muted">Tidak ada kandidat yang cocok.</p>
                                            <template x-for="(candidate, index) in kabagResults" :key="candidate.id">
                                                <button
                                                    type="button"
                                                    :id="`kabag_inline_option_${index}`"
                                                    role="option"
                                                    :aria-selected="kabagActiveIndex === index"
                                                    @mousedown.prevent="selectKabag(candidate)"
                                                    @mouseenter="kabagActiveIndex = index"
                                                    :class="kabagActiveIndex === index ? 'bg-soft text-ink' : 'text-ink'"
                                                    class="flex w-full flex-col rounded-lg px-3 py-2 text-left transition-colors focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                >
                                                    <span x-text="candidate.nama_lengkap" class="text-sm font-semibold"></span>
                                                    <span x-text="`NIP. ${candidate.nip}`" class="text-xs text-muted"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                    <p id="kabag_inline_lookup_help" class="text-xs text-muted">Ketik minimal 2 karakter nama atau NIP.</p>
                                    <p id="kabag_inline_selection_error" x-show="kabagSelectionError" x-cloak x-text="kabagSelectionError" class="text-xs font-semibold text-danger" role="alert"></p>
                                    @error('kepala_bagian_id')
                                        <p id="kabag_inline_lookup_error" class="text-xs font-semibold text-danger" role="alert">{{ $message }}</p>
                                    @enderror
                                    @error('redirect_to')
                                        <p class="text-xs font-semibold text-danger" role="alert">{{ $message }}</p>
                                    @enderror
                                    <p x-show="kabagSelectedName" x-cloak class="text-xs text-muted">Dipilih: <span x-text="kabagSelectedName" class="font-semibold text-ink"></span></p>
                                    <p x-show="kabagSelectedId && !kabagSelectedName" x-cloak class="text-xs text-muted">Pilihan sebelumnya dipertahankan. Cari ulang untuk mengganti.</p>
                                </div>
                                <x-form.input
                                    name="effective_date"
                                    type="date"
                                    label="Tanggal Efektif"
                                    :value="old('effective_date', now()->toDateString())"
                                    required
                                    help="Gunakan hari ini atau tanggal sebelumnya agar penugasan langsung aktif."
                                />
                                <div class="sm:pt-6">
                                    <x-ui.button type="submit" size="sm">Simpan Atasan Langsung</x-ui.button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endif

                <form method="POST" action="{{ route('cuti.config.employee-chain.store', $selectedEmployee) }}" data-config-form="pegawai" class="divide-y divide-border">
                    @csrf
                    <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-ink">Susun Chain Approval</p>
                        </div>
                        @if ($selectedKepalaBagian)
                            <p class="rounded-xl border border-primary/15 bg-soft px-4 py-2 text-center text-xs font-semibold text-primary">
                                <span x-show="verifiers.length" x-text="`Verifikator ×${verifiers.length}`"></span>
                                <span x-show="verifiers.length" aria-hidden="true">→</span>
                                Atasan Langsung
                                <span aria-hidden="true">→</span>
                                PYBMC
                            </p>
                        @endif
                    </div>
                    <div class="sticky top-0 z-10 border-b border-border bg-surface/95 px-5 py-3 shadow-[0_4px_12px_rgb(15_23_42/0.08)] backdrop-blur-sm sm:hidden">
                        <x-ui.button type="submit" class="w-full">Simpan Chain Pegawai</x-ui.button>
                    </div>
                    <div class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-muted">Pegawai</p>
                            <p class="mt-1 text-sm font-semibold text-ink">{{ $selectedEmployee->nama_lengkap }}</p>
                            <p class="text-xs text-muted">{{ $selectedEmployee->nip }}@if ($selectedEmployee->jabatan_terakhir), {{ $selectedEmployee->jabatan_terakhir }}@endif</p>
                        </div>
                        <div>
                            <label for="kepala-bagian-display" class="text-xs font-bold uppercase tracking-wider text-muted">Atasan Langsung</label>
                            <input
                                id="kepala-bagian-display"
                                type="text"
                                readonly
                                value="{{ $selectedKepalaBagian ? $selectedKepalaBagian->nama_lengkap . ' (' . $selectedKepalaBagian->nip . ')' : 'Belum ditetapkan' }}"
                                :aria-describedby="kepalaBagianError ? 'kepala-bagian-error' : null"
                                :aria-invalid="Boolean(kepalaBagianError)"
                                class="mt-1 w-full rounded-xl border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm"
                            >
                            <p id="kepala-bagian-error" x-show="kepalaBagianError" x-text="kepalaBagianError" class="mt-1 text-xs font-semibold text-danger" role="alert"></p>
                        </div>
                    </div>

                    @if (! $selectedKepalaBagian)
                        <div class="px-5 pb-5">
                            <x-ui.alert variant="warning">Atasan Langsung belum ditetapkan untuk pegawai. Tetapkan penugasan Atasan Langsung sebelum menyimpan chain.</x-ui.alert>
                        </div>
                    @else
                        <fieldset class="space-y-4 px-5 py-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <legend class="text-sm font-semibold text-ink">Verifikator</legend>
                                    <p class="mt-0.5 text-xs leading-relaxed text-muted">Opsional. Tambahkan satu atau beberapa verifikator sesuai kebutuhan.</p>
                                </div>
                                <x-ui.button type="button" variant="secondary" size="sm" class="min-h-11" x-ref="addVerifierButton" @click="addVerifier()" ::disabled="verifiers.length >= maxVerifierSteps">Tambah Verifikator</x-ui.button>
                            </div>

                            <p id="verifier-limit-help" class="text-xs text-muted">Maksimum 8 verifikator.</p>
                            @error('steps')
                                <p data-config-error-summary tabindex="-1" class="text-xs font-semibold text-danger" role="alert">{{ $message }}</p>
                            @enderror

                            <template x-for="(verifier, index) in verifiers" :key="verifier.client_key">
                                <div :data-verifier-key="verifier.client_key" class="grid gap-3 rounded-xl border border-border bg-soft/30 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <input type="hidden" :name="`steps[${index}][step_type]`" value="verifier">
                                        <div>
                                            <label class="text-xs font-bold uppercase tracking-wider text-ink" :for="`verifier-label-${verifier.client_key}`" x-text="`Label Verifikator ${index + 1}`"></label>
                                            <input data-verifier-label :id="`verifier-label-${verifier.client_key}`" :name="`steps[${index}][role_label]`" x-model="verifier.role_label" required maxlength="100" :aria-describedby="verifier.validation_errors.role_label ? `verifier-label-error-${verifier.client_key}` : null" :aria-invalid="Boolean(verifier.validation_errors.role_label)" class="mt-1 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                            <p :id="`verifier-label-error-${verifier.client_key}`" x-show="verifier.validation_errors.role_label" x-text="verifier.validation_errors.role_label" class="mt-1 text-xs font-semibold text-danger" role="alert"></p>
                                        </div>
                                        <div>
                                            <label class="text-xs font-bold uppercase tracking-wider text-ink" :for="`verifier-${verifier.client_key}`" x-text="`Pegawai Verifikator ${index + 1}`"></label>
                                            <select :id="`verifier-${verifier.client_key}`" :name="`steps[${index}][approver_employee_id]`" x-model="verifier.approver_employee_id" required :aria-describedby="verifier.validation_errors.approver_employee_id ? `verifier-error-${verifier.client_key}` : null" :aria-invalid="Boolean(verifier.validation_errors.approver_employee_id)" class="mt-1 w-full rounded-xl border border-border bg-surface py-2 pl-4 pr-10 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                                <option value="">Pilih verifikator</option>
                                                @foreach ($approverCandidates as $approver)
                                                    <option value="{{ $approver->id }}" @disabled(! $approver->is_selectable)>{{ $approver->nama_lengkap }} ({{ $approver->nip }})</option>
                                                @endforeach
                                                <template x-for="approver in approverLookupCandidates" :key="`lookup-verifier-${approver.id}`">
                                                    <option :value="approver.id" x-text="`${approver.nama_lengkap} (${approver.nip})`"></option>
                                                </template>
                                            </select>
                                            <p :id="`verifier-error-${verifier.client_key}`" x-show="verifier.validation_errors.approver_employee_id" x-text="verifier.validation_errors.approver_employee_id" class="mt-1 text-xs font-semibold text-danger" role="alert"></p>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2" aria-label="Aksi urutan verifikator">
                                        <x-ui.button type="button" variant="ghost" size="compact-icon" @click="moveVerifier(index, -1)" x-bind:disabled="index === 0" aria-label="Naikkan urutan verifikator" title="Naikkan urutan verifikator" class="h-11 w-11 sm:h-8 sm:w-8">↑</x-ui.button>
                                        <x-ui.button type="button" variant="ghost" size="compact-icon" @click="moveVerifier(index, 1)" x-bind:disabled="index === verifiers.length - 1" aria-label="Turunkan urutan verifikator" title="Turunkan urutan verifikator" class="h-11 w-11 sm:h-8 sm:w-8">↓</x-ui.button>
                                        <x-ui.button type="button" variant="danger" size="compact-icon" @click="removeVerifier(index)" ::aria-label="`Hapus verifikator ${index + 1}`" title="Hapus verifikator" class="h-11 w-11 sm:h-8 sm:w-8">×</x-ui.button>
                                    </div>
                                </div>
                            </template>
                        </fieldset>

                        <input type="hidden" :name="`steps[${verifiers.length}][step_type]`" value="kepala_bagian">
                        <input type="hidden" :name="`steps[${verifiers.length}][role_label]`" value="Atasan Langsung">
                        <input type="hidden" :name="`steps[${verifiers.length}][approver_employee_id]`" value="{{ $selectedKepalaBagian->id }}">

                        <div class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                            <div>
                                <label for="employee-pybmc" class="text-sm font-semibold text-ink">PYBMC</label>
                                <p class="mt-0.5 text-xs leading-relaxed text-muted">Opsional — kosongkan untuk memakai konfigurasi global.</p>
                            </div>
                            <div>
                                <input type="hidden" name="steps[_pybmc][step_type]" value="pybmc" x-bind:disabled="! pybmcEmployeeId">
                                <input type="hidden" name="steps[_pybmc][role_label]" value="PYBMC" x-bind:disabled="! pybmcEmployeeId">
                                <select id="employee-pybmc" :name="pybmcEmployeeId ? 'steps[_pybmc][approver_employee_id]' : null" x-model="pybmcEmployeeId" :aria-describedby="`employee-pybmc-help ${pybmcError ? 'employee-pybmc-error' : ''}`.trim()" :aria-invalid="Boolean(pybmcError)" class="w-full rounded-xl border border-border bg-surface py-2 pl-4 pr-10 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                    <option value="">Gunakan PYBMC global</option>
                                    @foreach ($approverCandidates as $approver)
                                        <option value="{{ $approver->id }}" @disabled(! $approver->is_selectable)>{{ $approver->nama_lengkap }} ({{ $approver->nip }})</option>
                                    @endforeach
                                    <template x-for="approver in approverLookupCandidates" :key="`lookup-pybmc-${approver.id}`">
                                        <option :value="approver.id" x-text="`${approver.nama_lengkap} (${approver.nip})`"></option>
                                    </template>
                                </select>
                                <p id="employee-pybmc-help" class="mt-1 text-xs text-muted">Perubahan chain berlaku untuk pengajuan berikutnya.</p>
                                <p id="employee-pybmc-error" x-show="pybmcError" x-text="pybmcError" class="mt-1 text-xs font-semibold text-danger" role="alert"></p>
                            </div>
                        </div>

                        <div class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-start">
                            <div>
                                <label for="employee-chain-reason" class="text-sm font-semibold text-ink">Alasan Perubahan (opsional)</label>
                                <p class="mt-1 text-xs leading-relaxed text-muted">Dicatat dalam log audit.</p>
                            </div>
                             <div class="space-y-3">
                                 <x-form.textarea name="reason" id="employee-chain-reason" rows="3" placeholder="Contoh: Penyesuaian verifikator setelah mutasi jabatan" />
                                <x-ui.button type="submit" class="hidden w-full sm:inline-flex">Simpan Chain Pegawai</x-ui.button>
                             </div>
                         </div>
                     @endif
                 </form>
            @elseif ($search !== null && trim($search) !== '')
                <div class="px-5 py-5">
                    <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border bg-soft/20 px-6 py-10 text-center">
                        <svg class="h-8 w-8 text-muted/60" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                        <p class="text-sm font-semibold text-ink">Pilih pegawai dari hasil pencarian</p>
                        <p class="max-w-sm text-xs leading-relaxed text-muted">Penetapan Atasan Langsung dan susunan chain tampil setelah pegawai dipilih.</p>
                    </div>
                </div>
            @else
                <div class="px-5 py-5">
                    <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border bg-soft/20 px-6 py-10 text-center">
                        <svg class="h-8 w-8 text-muted/60" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <p class="text-sm font-semibold text-ink">Mulai dengan mencari pegawai</p>
                        <p class="max-w-sm text-xs leading-relaxed text-muted">Ketik nama atau NIP pada kolom pencarian di atas.</p>
                    </div>
                </div>
            @endif
        </section>
        </div>

        </div>
        </div>

        <div id="cuti-config-panel-pybmc" role="tabpanel" aria-labelledby="cuti-config-tab-pybmc" x-show="activeTab === 'pybmc'" x-cloak>
        @if ($hasGlobalIdentityScope)
        <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="global-pybmc-heading">
            <div class="border-b border-border bg-soft/30 px-5 py-4">
                <h3 id="global-pybmc-heading" class="text-xs font-bold uppercase tracking-wider text-ink">PYBMC Global</h3>
                <p class="mt-0.5 text-xs text-muted">Override global mengubah PYBMC pada semua chain aktif.</p>
            </div>
            <div class="space-y-4 px-5 py-5">
                <div class="flex items-center justify-between gap-3 rounded-xl border border-border bg-soft/30 px-4 py-3 text-sm">
                    <span class="shrink-0 text-muted">PYBMC aktif</span>
                    <span class="text-right font-semibold text-ink">{{ $globalPybmc?->approver?->nama_lengkap ?? 'Belum ditetapkan' }}</span>
                </div>
                @php
                    $pybmcCurrentLabel = $globalPybmc?->approver
                        ? $globalPybmc->approver->nama_lengkap.' ('.$globalPybmc->approver->nip.')'
                        : '';
                    $rawPybmcPrefillId = old('approver_employee_id', $globalPybmc?->approver_employee_id ?? '');
                    $pybmcPrefillId = is_string($rawPybmcPrefillId) ? $rawPybmcPrefillId : '';
                    $pybmcPrefillLabel = old('approver_employee_id') === null ? $pybmcCurrentLabel : ($oldGlobalPybmcLabel ?? '');
                @endphp
                <form
                    method="POST"
                    action="{{ route('cuti.config.pybmc-global') }}"
                    data-config-form="pybmc"
                    class="space-y-3"
                    x-data="{
                        pybmcLookupEndpoint: @js(route('cuti.config.batch.approvers')),
                        pybmcQuery: @js($pybmcPrefillLabel),
                        pybmcResults: [],
                        pybmcOpen: false,
                        pybmcLoading: false,
                        pybmcError: '',
                        pybmcSelectionError: '',
                        pybmcSelectedId: @js($pybmcPrefillId),
                        pybmcSelectedName: @js($pybmcPrefillLabel),
                        pybmcActiveIndex: -1,
                        pybmcRequestId: 0,
                        pybmcSearchTimer: null,
                        searchPybmc() {
                            window.clearTimeout(this.pybmcSearchTimer);
                            this.pybmcRequestId++;
                            this.pybmcError = '';
                            this.pybmcSelectionError = '';
                            if (this.pybmcQuery !== this.pybmcSelectedName) {
                                this.pybmcSelectedId = '';
                            }
                            if (this.pybmcQuery.trim().length < 2) {
                                this.pybmcResults = [];
                                this.pybmcOpen = false;
                                this.pybmcLoading = false;
                                return;
                            }
                            this.pybmcSearchTimer = window.setTimeout(() => this.fetchPybmcCandidates(), 250);
                        },
                        async fetchPybmcCandidates() {
                            const requestId = ++this.pybmcRequestId;
                            this.pybmcLoading = true;
                            this.pybmcOpen = true;
                            this.pybmcActiveIndex = -1;
                            try {
                                const response = await fetch(`${this.pybmcLookupEndpoint}?q=${encodeURIComponent(this.pybmcQuery.trim())}`, {
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                                });
                                if (!response.ok) {
                                    throw new Error('Lookup pegawai tidak tersedia.');
                                }
                                const result = await response.json();
                                if (requestId !== this.pybmcRequestId) return;
                                this.pybmcResults = Array.isArray(result.data) ? result.data : [];
                            } catch (error) {
                                if (requestId !== this.pybmcRequestId) return;
                                this.pybmcResults = [];
                                this.pybmcError = 'Pencarian pegawai gagal. Coba lagi.';
                            } finally {
                                if (requestId === this.pybmcRequestId) {
                                    this.pybmcLoading = false;
                                }
                            }
                        },
                        selectPybmc(candidate) {
                            this.pybmcRequestId++;
                            this.pybmcSelectionError = '';
                            this.pybmcSelectedId = candidate.id;
                            this.pybmcSelectedName = candidate.nama_lengkap;
                            this.pybmcQuery = candidate.nama_lengkap;
                            this.pybmcResults = [];
                            this.pybmcActiveIndex = -1;
                            this.pybmcOpen = false;
                            this.markDirty('pybmc');
                        },
                        closePybmcLookup() {
                            window.setTimeout(() => { this.pybmcOpen = false; }, 120);
                        },
                        movePybmcActiveIndex(direction) {
                            if (! this.pybmcOpen || this.pybmcResults.length === 0) return;
                            const next = this.pybmcActiveIndex + direction;
                            this.pybmcActiveIndex = Math.min(Math.max(next, 0), this.pybmcResults.length - 1);
                        },
                        chooseActivePybmc() {
                            if (this.pybmcActiveIndex >= 0 && this.pybmcResults[this.pybmcActiveIndex]) {
                                this.selectPybmc(this.pybmcResults[this.pybmcActiveIndex]);
                            }
                        },
                        guardPybmcSubmit(event) {
                            if (! this.pybmcSelectedId) {
                                event.preventDefault();
                                this.pybmcSelectionError = 'Pilih pegawai dari hasil pencarian terlebih dahulu.';
                            }
                        }
                    }"
                    @submit="guardPybmcSubmit($event)"
                >
                    @csrf
                    <input type="hidden" name="approver_employee_id" :value="pybmcSelectedId">
                    <div class="space-y-1">
                        <label for="pybmc-global-lookup" class="text-xs font-bold uppercase tracking-wider text-ink">Pegawai PYBMC</label>
                        <div class="relative">
                            <input
                                id="pybmc-global-lookup"
                                x-model="pybmcQuery"
                                @input="searchPybmc()"
                                @focus="pybmcQuery.trim().length >= 2 && (pybmcOpen = true)"
                                @blur="closePybmcLookup()"
                                @keydown.arrow-down.prevent="movePybmcActiveIndex(1)"
                                @keydown.arrow-up.prevent="movePybmcActiveIndex(-1)"
                                @keydown.enter.prevent="chooseActivePybmc()"
                                @keydown.escape.prevent="pybmcOpen = false"
                                type="search"
                                autocomplete="off"
                                role="combobox"
                                aria-autocomplete="list"
                                :aria-expanded="pybmcOpen.toString()"
                                aria-controls="pybmc_global_lookup_results"
                                :aria-activedescendant="pybmcActiveIndex >= 0 ? `pybmc_global_option_${pybmcActiveIndex}` : null"
                                aria-describedby="pybmc_global_selection_error {{ $errors->has('approver_employee_id') ? 'pybmc_global_lookup_error' : '' }}"
                                :aria-invalid="{{ $errors->has('approver_employee_id') ? 'true' : 'false' }}"
                                placeholder="Ketik minimal 2 karakter nama atau NIP"
                                class="min-h-11 w-full rounded-xl border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-[border-color,box-shadow] duration-200 placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 {{ $errors->has('approver_employee_id') ? 'border-danger focus:border-danger focus:ring-danger/20' : 'border-border' }}"
                            >
                            <div
                                id="pybmc_global_lookup_results"
                                x-cloak
                                x-show="pybmcOpen"
                                role="listbox"
                                aria-label="Hasil pencarian pegawai PYBMC"
                                class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-border bg-surface p-1 shadow-md"
                            >
                                <div x-show="pybmcLoading" class="flex items-center gap-2 px-3 py-2 text-xs text-muted">
                                    <x-ui.loading size="sm" color="primary" />
                                    Memuat kandidat.
                                </div>
                                <p x-show="!pybmcLoading && pybmcError" x-text="pybmcError" class="px-3 py-2 text-xs text-danger"></p>
                                <p x-show="!pybmcLoading && !pybmcError && pybmcResults.length === 0" class="px-3 py-2 text-xs text-muted">Tidak ada kandidat yang cocok.</p>
                                <template x-for="(candidate, index) in pybmcResults" :key="candidate.id">
                                    <button
                                        type="button"
                                        :id="`pybmc_global_option_${index}`"
                                        role="option"
                                        :aria-selected="pybmcActiveIndex === index"
                                        @mousedown.prevent="selectPybmc(candidate)"
                                        @mouseenter="pybmcActiveIndex = index"
                                        :class="pybmcActiveIndex === index ? 'bg-soft text-ink' : 'text-ink'"
                                        class="flex w-full flex-col rounded-lg px-3 py-2 text-left transition-colors focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    >
                                        <span x-text="candidate.nama_lengkap" class="text-sm font-semibold"></span>
                                        <span x-text="`NIP. ${candidate.nip}`" class="text-xs text-muted"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <p id="pybmc_global_selection_error" x-show="pybmcSelectionError" x-cloak x-text="pybmcSelectionError" class="text-xs font-semibold text-danger" role="alert"></p>
                        @error('approver_employee_id')
                            <p id="pybmc_global_lookup_error" class="text-xs font-semibold text-danger" role="alert">{{ $message }}</p>
                        @enderror
                        <p x-show="pybmcSelectedId && !pybmcSelectedName" x-cloak class="text-xs text-muted">Pilihan sebelumnya dipertahankan. Cari ulang untuk mengganti.</p>
                    </div>
                    <x-form.textarea name="pybmc_reason" id="pybmc-global-reason" label="Alasan PYBMC Global" :required="true" rows="3" placeholder="Contoh: Pergantian pejabat PYBMC" />
                    <x-ui.button type="submit" class="w-full">Simpan PYBMC Global</x-ui.button>
                </form>
            </div>
        </section>

        @endif

        </div>

        <div id="cuti-config-panel-rangkaian" role="tabpanel" aria-labelledby="cuti-config-tab-rangkaian" x-show="activeTab === 'rangkaian'" x-cloak>
        @include('admin.cuti.partials.chain-batch-composer')
        </div>

        @if ($canViewAudit ?? false)
        <section id="cuti-config-panel-riwayat" role="tabpanel" aria-labelledby="cuti-config-tab-riwayat" x-show="activeTab === 'riwayat'" x-cloak class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
            <div class="flex items-center justify-between border-b border-border bg-soft/30 px-5 py-4">
                <div>
                    <h3 id="audit-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Log Perubahan Konfigurasi</h3>
                </div>
                <x-ui.badge variant="muted" size="md">{{ count($auditRows) }} entri</x-ui.badge>
            </div>
            <div class="hidden overflow-x-auto md:block">
                <x-ui.table caption="Log perubahan konfigurasi cuti" class="text-left text-xs">
                    <x-ui.table-head>
                        <x-ui.table-row class="border-b border-border bg-soft">
                            <x-ui.table-th class="px-5 py-3">Waktu</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Sumber</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Pengguna</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Catatan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse ($auditRows as $row)
                            <x-ui.table-row>
                                <x-ui.table-td padding="wide" class="whitespace-nowrap text-muted">{{ $row['created_at'] }}</x-ui.table-td>
                                <x-ui.table-td padding="wide" class="font-medium text-ink">{{ $row['source'] }}</x-ui.table-td>
                                <x-ui.table-td padding="wide" class="text-muted">{{ $row['user_name'] }}</x-ui.table-td>
                                <x-ui.table-td padding="wide" class="max-w-[320px] truncate text-muted" title="{{ $row['reason'] }}">{{ $row['reason'] }}</x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="4" align="center" class="px-5 py-8 text-sm text-muted">Belum ada perubahan konfigurasi tercatat.</x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
            <div class="space-y-3 p-5 md:hidden">
                @forelse ($auditRows as $row)
                    <article class="rounded-xl border border-border bg-soft/30 p-4">
                        <dl class="grid gap-3 text-xs">
                            <div class="grid gap-0.5">
                                <dt class="font-bold uppercase tracking-wider text-muted">Waktu</dt>
                                <dd class="text-ink">{{ $row['created_at'] }}</dd>
                            </div>
                            <div class="grid gap-0.5">
                                <dt class="font-bold uppercase tracking-wider text-muted">Sumber</dt>
                                <dd class="font-medium text-ink">{{ $row['source'] }}</dd>
                            </div>
                            <div class="grid gap-0.5">
                                <dt class="font-bold uppercase tracking-wider text-muted">Pengguna</dt>
                                <dd class="text-ink">{{ $row['user_name'] }}</dd>
                            </div>
                            <div class="grid gap-0.5">
                                <dt class="font-bold uppercase tracking-wider text-muted">Catatan</dt>
                                <dd class="break-words text-ink">{{ $row['reason'] }}</dd>
                            </div>
                        </dl>
                    </article>
                @empty
                    <p class="py-3 text-center text-sm text-muted">Belum ada perubahan konfigurasi tercatat.</p>
                @endforelse
            </div>
        </section>
        @endif

        <x-ui.modal id="cuti-config-leave-confirmation" show="leaveConfirmOpen" title="Buang perubahan yang belum disimpan?"
            description-id="cuti-config-leave-description" close-action="cancelDeparture()" max-width="md"
            panel-class="[&_button]:min-h-11 [&_button]:min-w-11">
            <p id="cuti-config-leave-description" class="text-sm leading-relaxed text-ink">Anda memiliki perubahan yang belum disimpan. Tetap di halaman untuk melanjutkan, atau buang perubahan dan lanjutkan.</p>
            <x-slot:footer>
                <div class="flex flex-wrap justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" @click="cancelDeparture()" data-modal-initial-focus="true">Tetap di Halaman</x-ui.button>
                    <x-ui.button type="button" variant="danger" @click="confirmDeparture()">Buang dan Lanjutkan</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>

    </div>
</x-layouts.app>
