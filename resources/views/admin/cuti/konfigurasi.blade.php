<x-layouts.app title="Konfigurasi Approval Cuti">
    @php
        $oldSteps = old('steps');
        $verifierSteps = is_array($oldSteps)
            ? collect($oldSteps)
                ->filter(fn (array $step): bool => ($step['step_type'] ?? null) === 'verifier')
                ->values()
                ->map(fn (array $step, int $index): array => [
                    'role_label' => $step['role_label'] ?? 'Verifikator '.($index + 1),
                    'approver_employee_id' => $step['approver_employee_id'] ?? '',
                ])
                ->all()
            : $initialVerifierSteps;
        $pybmcEmployeeId = is_array($oldSteps)
            ? collect($oldSteps)->firstWhere('step_type', 'pybmc')['approver_employee_id'] ?? ''
            : $initialPybmcEmployeeId;
    @endphp

    <div class="space-y-6" x-data="{
        verifiers: @js($verifierSteps),
        pybmcEmployeeId: @js($pybmcEmployeeId),
        errors: @js($errors->messages()),
        maxVerifierSteps: 8,
        backfillHelpOpen: false,
        openBackfillHelp() {
            this.backfillHelpOpen = true;
            this.$nextTick(() => this.$refs.backfillHelpClose.focus());
        },
        closeBackfillHelp() {
            if (! this.backfillHelpOpen) return;
            this.backfillHelpOpen = false;
            this.$nextTick(() => this.$refs.backfillHelpTrigger.focus());
        },
        trapBackfillHelpFocus(event) {
            const focusable = [...this.$refs.backfillHelpDialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]')]
                .filter((element) => ! element.disabled && element.tabIndex >= 0 && element.offsetParent !== null);
            const first = focusable[0];
            const last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
        addVerifier() {
            if (this.verifiers.length >= this.maxVerifierSteps) return;
            let number = 1;
            while (this.verifiers.some((verifier) => verifier.role_label === `Verifikator ${number}`)) number++;
            this.verifiers.push({ role_label: `Verifikator ${number}`, approver_employee_id: '' });
        },
        removeVerifier(index) {
            this.verifiers.splice(index, 1);
        },
        moveVerifier(index, direction) {
            const nextIndex = index + direction;
            if (nextIndex < 0 || nextIndex >= this.verifiers.length) return;
            [this.verifiers[index], this.verifiers[nextIndex]] = [this.verifiers[nextIndex], this.verifiers[index]];
        },
        isDuplicate(employeeId, index) {
            if (! employeeId) return false;
            return this.verifiers.some((verifier, verifierIndex) => verifierIndex !== index && verifier.approver_employee_id === employeeId)
                || employeeId === this.pybmcEmployeeId;
        },
        errorFor(key) {
            return this.errors[key]?.[0] ?? '';
        }
    }">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <nav class="mb-2 flex items-center gap-1.5 text-xs text-muted" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span aria-hidden="true">/</span>
                    <span>Cuti</span>
                    <span aria-hidden="true">/</span>
                    <span class="font-medium text-ink">Konfigurasi Approval</span>
                </nav>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi Approval Cuti</h2>
            </div>
            <a href="{{ route('cuti') }}" class="inline-flex items-center gap-2 rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft">
                Kembali ke Cuti
                <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        {{-- Ringkasan status konfigurasi: dibaca sekilas sebelum admin menyusun chain. --}}
        <div class="grid gap-4 sm:grid-cols-3">
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
        </div>

        <div class="grid gap-6 xl:grid-cols-3 xl:items-start">
        <div class="space-y-6 xl:col-span-2">
        <section class="rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="employee-chain-heading">
            <div class="border-b border-border bg-soft/30 px-5 py-4">
                <h3 id="employee-chain-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Chain Approval Pegawai</h3>
            </div>

            <div class="flex items-center gap-2.5 px-5 pt-4">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white" aria-hidden="true">1</span>
                <p class="text-sm font-semibold text-ink">Pilih Pegawai</p>
            </div>
            <x-cuti.employee-combobox
                id="employee-search"
                :action="route('cuti.config')"
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
                class="border-b border-border px-5 pb-4 pt-3"
            />

            @if ($selectedEmployee)
                @if ($selectedKepalaBagian)
                    <form method="GET" action="{{ route('cuti.config') }}" class="space-y-1 border-b border-border bg-soft/20 px-5 py-4">
                        <input type="hidden" name="search" value="{{ $search }}">
                        <input type="hidden" name="employee_id" value="{{ $selectedEmployee->id }}">
                        <label for="approver-search" class="text-xs font-bold uppercase tracking-wider text-ink">Cari Kandidat Approver</label>
                        <div class="flex items-start gap-3">
                            <input id="approver-search" name="approver_search" type="search" value="{{ $approverSearch }}" placeholder="Nama atau NIP" aria-describedby="approver-search-help" class="min-h-11 min-w-0 flex-1 rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-all duration-200 placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <button type="submit" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-border bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">Cari Kandidat</button>
                        </div>
                        <p id="approver-search-help" class="text-[11px] text-muted">Hasil pencarian mengisi pilihan Verifikator dan PYBMC.</p>
                    </form>
                @endif

                @php
                    $canAssignKepalaBagian = in_array(auth()->user()?->role, ['super_admin', 'admin_kepegawaian'], true)
                        && (auth()->user()?->hasPermission('employees.update') ?? false);
                    $kabagFormOpen = ! $selectedKepalaBagian
                        || $errors->hasAny(['kepala_bagian_id', 'effective_date', 'redirect_to']);
                @endphp

                @if ($canAssignKepalaBagian)
                    <div
                        class="border-b border-border bg-soft/20 px-5 py-4"
                        x-data="{
                            kabagFormOpen: @js((bool) $kabagFormOpen),
                            kabagLookupEndpoint: @js(route('pegawai.supervisor-lookup', $selectedEmployee->id)),
                            kabagQuery: '',
                            kabagResults: [],
                            kabagOpen: false,
                            kabagLoading: false,
                            kabagError: '',
                            kabagSelectionError: '',
                            kabagSelectedId: @js((string) old('kepala_bagian_id', '')),
                            kabagSelectedName: '',
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
                                        throw new Error('Lookup Kepala Bagian tidak tersedia.');
                                    }
                                    const result = await response.json();
                                    if (requestId !== this.kabagRequestId) return;
                                    this.kabagResults = Array.isArray(result.data) ? result.data : [];
                                } catch (error) {
                                    if (requestId !== this.kabagRequestId) return;
                                    this.kabagResults = [];
                                    this.kabagError = 'Pencarian Kepala Bagian gagal. Coba lagi.';
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
                                    this.kabagSelectionError = 'Pilih Kepala Bagian dari hasil pencarian terlebih dahulu.';
                                }
                            }
                        }"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white" aria-hidden="true">2</span>
                                <div>
                                <h4 class="text-sm font-semibold text-ink">Penetapan Kepala Bagian</h4>
                                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-muted">
                                    @if ($selectedKepalaBagian)
                                        Kepala Bagian aktif: <span class="font-semibold text-ink">{{ $selectedKepalaBagian->nama_lengkap }} ({{ $selectedKepalaBagian->nip }})</span>
                                    @else
                                        Belum ada Kepala Bagian aktif — tetapkan di bawah ini.
                                    @endif
                                </p>
                                </div>
                            </div>
                            @if ($selectedKepalaBagian)
                                <button type="button" @click="kabagFormOpen = ! kabagFormOpen" :aria-expanded="kabagFormOpen.toString()" aria-controls="kabag-inline-form" class="inline-flex shrink-0 items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">
                                    <span x-text="kabagFormOpen ? 'Tutup Form' : 'Ubah Kepala Bagian'"></span>
                                </button>
                            @endif
                        </div>

                        <form
                            id="kabag-inline-form"
                            x-show="kabagFormOpen"
                            x-cloak
                            method="POST"
                            action="{{ route('pegawai.assign-atasan', $selectedEmployee->id) }}"
                            @submit="guardKabagSubmit($event)"
                            class="mt-4"
                        >
                            @csrf
                            <input type="hidden" name="redirect_to" value="cuti-config">
                            <input type="hidden" name="kepala_bagian_id" :value="kabagSelectedId">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_13rem_auto] sm:items-start">
                                <div class="space-y-1">
                                    <label for="kabag_inline_lookup" class="text-xs font-bold uppercase tracking-wider text-ink">Cari Kepala Bagian</label>
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
                                            class="w-full rounded-xl border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-all duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 {{ $errors->has('kepala_bagian_id') ? 'border-danger focus:border-danger focus:ring-danger/20' : 'border-border' }}"
                                        >
                                        <div
                                            id="kabag_inline_lookup_results"
                                            x-cloak
                                            x-show="kabagOpen"
                                            role="listbox"
                                            aria-label="Hasil pencarian Kepala Bagian"
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
                                    <p id="kabag_inline_lookup_help" class="text-[11px] text-muted">Ketik minimal 2 karakter nama atau NIP.</p>
                                    <p id="kabag_inline_selection_error" x-show="kabagSelectionError" x-cloak x-text="kabagSelectionError" class="text-[11px] font-semibold text-danger" role="alert"></p>
                                    @error('kepala_bagian_id')
                                        <p id="kabag_inline_lookup_error" class="text-[11px] font-semibold text-danger" role="alert">{{ $message }}</p>
                                    @enderror
                                    @error('redirect_to')
                                        <p class="text-[11px] font-semibold text-danger" role="alert">{{ $message }}</p>
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
                                    <x-ui.button type="submit" size="sm">Simpan Kepala Bagian</x-ui.button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endif

                <form method="POST" action="{{ route('cuti.config.employee-chain.store', $selectedEmployee) }}" class="divide-y divide-border">
                    @csrf
                    <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white" aria-hidden="true">3</span>
                            <p class="text-sm font-semibold text-ink">Susun Chain Approval</p>
                        </div>
                        @if ($selectedKepalaBagian)
                            <p class="rounded-xl border border-primary/15 bg-soft px-4 py-2 text-center text-xs font-semibold text-primary" aria-live="polite">
                                Kepala Bagian
                                <span aria-hidden="true">→</span>
                                <span x-text="verifiers.length ? `Verifikator ×${verifiers.length}` : 'Tanpa verifikator'"></span>
                                <span aria-hidden="true">→</span>
                                <span x-text="pybmcEmployeeId ? 'PYBMC khusus' : 'PYBMC global'"></span>
                            </p>
                        @endif
                    </div>
                    <div class="sticky top-0 z-10 border-b border-border bg-surface/95 px-5 py-3 shadow-[0_4px_12px_rgb(15_23_42/0.08)] backdrop-blur-sm sm:hidden">
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-transparent bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30">Simpan Chain Pegawai</button>
                    </div>
                    <div class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-muted">Pegawai</p>
                            <p class="mt-1 text-sm font-semibold text-ink">{{ $selectedEmployee->nama_lengkap }}</p>
                            <p class="text-xs text-muted">{{ $selectedEmployee->nip }}@if ($selectedEmployee->jabatan_terakhir), {{ $selectedEmployee->jabatan_terakhir }}@endif</p>
                        </div>
                        <div>
                            <label for="kepala-bagian-display" class="text-xs font-bold uppercase tracking-wider text-muted">Kepala Bagian</label>
                            <input id="kepala-bagian-display" type="text" readonly value="{{ $selectedKepalaBagian ? $selectedKepalaBagian->nama_lengkap . ' (' . $selectedKepalaBagian->nip . ')' : 'Belum ditetapkan' }}" class="mt-1 w-full rounded-xl border border-border bg-soft px-4 py-2 text-sm text-ink shadow-sm">
                        </div>
                    </div>

                    @if (! $selectedKepalaBagian)
                        <div class="px-5 pb-5">
                            <x-ui.alert variant="warning">Pegawai belum memiliki Kepala Bagian aktif. Tetapkan struktur pegawai sebelum menyimpan chain.</x-ui.alert>
                        </div>
                    @else
                        <input type="hidden" name="steps[0][step_type]" value="kepala_bagian">
                        <input type="hidden" name="steps[0][role_label]" value="Kepala Bagian">
                        <input type="hidden" name="steps[0][approver_employee_id]" value="{{ $selectedKepalaBagian->id }}">

                        <fieldset class="space-y-4 px-5 py-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <legend class="text-sm font-semibold text-ink">Verifikator</legend>
                                    <p class="mt-0.5 text-xs leading-relaxed text-muted">Opsional. Approver duplikat dilewati otomatis.</p>
                                </div>
                                <button type="button" @click="addVerifier()" :disabled="verifiers.length >= maxVerifierSteps" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-50">Tambah Verifikator</button>
                            </div>

                            <p id="verifier-limit-help" class="text-[11px] text-muted">Maksimum 8 verifikator.</p>
                            @error('steps')
                                <p class="text-[11px] font-semibold text-danger" role="alert">{{ $message }}</p>
                            @enderror

                            <template x-for="(verifier, index) in verifiers" :key="index">
                                <div class="grid gap-3 rounded-xl border border-border bg-soft/30 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <input type="hidden" :name="`steps[${index + 1}][step_type]`" value="verifier">
                                        <div>
                                            <label class="text-xs font-bold uppercase tracking-wider text-ink" :for="`verifier-label-${index}`" x-text="`Label Verifikator ${index + 1}`"></label>
                                            <input :id="`verifier-label-${index}`" :name="`steps[${index + 1}][role_label]`" x-model="verifier.role_label" required maxlength="100" :aria-invalid="Boolean(errorFor(`steps.${index + 1}.role_label`))" class="mt-1 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm transition-all duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                            <p x-show="errorFor(`steps.${index + 1}.role_label`)" x-text="errorFor(`steps.${index + 1}.role_label`)" class="mt-1 text-[11px] font-semibold text-danger" role="alert"></p>
                                        </div>
                                        <div>
                                            <label class="text-xs font-bold uppercase tracking-wider text-ink" :for="`verifier-${index}`" x-text="`Pegawai Verifikator ${index + 1}`"></label>
                                            <select :id="`verifier-${index}`" :name="`steps[${index + 1}][approver_employee_id]`" x-model="verifier.approver_employee_id" required :aria-describedby="`${isDuplicate(verifier.approver_employee_id, index) ? `verifier-duplicate-${index} ` : ''}${errorFor(`steps.${index + 1}.approver_employee_id`) ? `verifier-error-${index}` : ''}`" :aria-invalid="Boolean(errorFor(`steps.${index + 1}.approver_employee_id`))" class="mt-1 w-full rounded-xl border border-border bg-surface py-2 pl-4 pr-10 text-sm text-ink shadow-sm transition-all duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                                <option value="">Pilih verifikator</option>
                                                @foreach ($approverCandidates as $approver)
                                                    <option value="{{ $approver->id }}">{{ $approver->nama_lengkap }} ({{ $approver->nip }})</option>
                                                @endforeach
                                            </select>
                                            <p :id="`verifier-duplicate-${index}`" x-show="isDuplicate(verifier.approver_employee_id, index)" class="mt-1 text-[11px] text-warning">Approver sama akan dilewati otomatis saat approval.</p>
                                            <p :id="`verifier-error-${index}`" x-show="errorFor(`steps.${index + 1}.approver_employee_id`)" x-text="errorFor(`steps.${index + 1}.approver_employee_id`)" class="mt-1 text-[11px] font-semibold text-danger" role="alert"></p>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2" aria-label="Aksi urutan verifikator">
                                        <button type="button" @click="moveVerifier(index, -1)" :disabled="index === 0" aria-label="Naikkan urutan verifikator" title="Naikkan urutan verifikator" class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-muted transition-all duration-200 hover:bg-soft hover:text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-50 sm:h-8 sm:w-8">↑</button>
                                        <button type="button" @click="moveVerifier(index, 1)" :disabled="index === verifiers.length - 1" aria-label="Turunkan urutan verifikator" title="Turunkan urutan verifikator" class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-muted transition-all duration-200 hover:bg-soft hover:text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-50 sm:h-8 sm:w-8">↓</button>
                                        <button type="button" @click="removeVerifier(index)" :aria-label="`Hapus verifikator ${index + 1}`" title="Hapus verifikator" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-danger/20 bg-surface text-danger shadow-sm transition-all duration-200 hover:bg-danger/5 focus:outline-none focus:ring-2 focus:ring-danger/20 sm:h-8 sm:w-8">×</button>
                                    </div>
                                </div>
                            </template>
                        </fieldset>

                        <div class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                            <div>
                                <label for="employee-pybmc" class="text-sm font-semibold text-ink">PYBMC Khusus</label>
                                <p class="mt-0.5 text-xs leading-relaxed text-muted">Opsional — kosongkan untuk memakai PYBMC global.</p>
                            </div>
                            <div>
                                <input type="hidden" name="steps[_pybmc][step_type]" value="pybmc" x-bind:disabled="! pybmcEmployeeId">
                                <input type="hidden" name="steps[_pybmc][role_label]" value="PYBMC" x-bind:disabled="! pybmcEmployeeId">
                                <select id="employee-pybmc" :name="pybmcEmployeeId ? 'steps[_pybmc][approver_employee_id]' : null" x-model="pybmcEmployeeId" aria-describedby="employee-pybmc-help" class="w-full rounded-xl border border-border bg-surface py-2 pl-4 pr-10 text-sm text-ink shadow-sm transition-all duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                    <option value="">Gunakan PYBMC global</option>
                                    @foreach ($approverCandidates as $approver)
                                        <option value="{{ $approver->id }}">{{ $approver->nama_lengkap }} ({{ $approver->nip }})</option>
                                    @endforeach
                                </select>
                                <p id="employee-pybmc-help" class="mt-1 text-[11px] text-muted">Perubahan chain berlaku untuk pengajuan berikutnya.</p>
                            </div>
                        </div>

                        <div class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-start">
                            <div>
                                <label for="employee-chain-reason" class="text-sm font-semibold text-ink">Alasan Perubahan <span class="text-danger">*</span></label>
                                <p class="mt-1 text-xs leading-relaxed text-muted">Dicatat dalam log audit.</p>
                            </div>
                             <div class="space-y-3">
                                 <x-form.textarea name="reason" id="employee-chain-reason" :required="true" rows="3" placeholder="Contoh: Penyesuaian verifikator setelah mutasi jabatan" />
                                <button type="submit" class="hidden w-full items-center justify-center rounded-xl border border-transparent bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30 sm:inline-flex">Simpan Chain Pegawai</button>
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
                        <p class="max-w-sm text-xs leading-relaxed text-muted">Langkah 2 dan 3 tampil setelah pegawai dipilih.</p>
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

        <div class="space-y-6">
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
                <form method="POST" action="{{ route('cuti.config.pybmc-global') }}" class="space-y-3">
                    @csrf
                    <x-form.select name="approver_employee_id" id="pybmc-global-approver" label="Pegawai PYBMC" :required="true" :value="$globalPybmc?->approver_employee_id" placeholder="Pilih PYBMC">
                        @foreach ($approverCandidates as $approver)
                            <option value="{{ $approver->id }}">{{ $approver->nama_lengkap }} ({{ $approver->nip }})</option>
                        @endforeach
                    </x-form.select>
                    <x-form.textarea name="pybmc_reason" id="pybmc-global-reason" label="Alasan PYBMC Global" :required="true" rows="3" placeholder="Contoh: Pergantian pejabat PYBMC" />
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">Simpan PYBMC Global</button>
                </form>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="backfill-heading">
            <div class="flex items-start justify-between gap-3 border-b border-border bg-soft/30 px-5 py-4">
                <div>
                    <h3 id="backfill-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Backfill Chain Dinamis</h3>
                    <p class="mt-0.5 text-xs text-muted">Buat chain massal untuk pegawai yang belum punya chain.</p>
                </div>
                <button
                    type="button"
                    x-ref="backfillHelpTrigger"
                    @click="openBackfillHelp()"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-surface text-sm font-bold text-primary shadow-sm transition-colors hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20"
                    aria-label="Pelajari Backfill Chain Dinamis"
                    aria-haspopup="dialog"
                    :aria-expanded="backfillHelpOpen"
                    aria-controls="backfill-help-dialog"
                >?</button>
            </div>
            <div class="space-y-4 px-5 py-5">
                <p class="rounded-xl border border-border bg-soft/30 px-4 py-3 text-sm text-muted">Dapat diulang — pegawai yang sudah punya chain aktif dilewati.</p>
                <form method="POST" action="{{ route('cuti.config.backfill') }}" class="space-y-3">
                    @csrf
                    <x-form.textarea name="backfill_reason" id="backfill-reason" label="Alasan Backfill" :required="true" rows="3" placeholder="Contoh: Backfill awal konfigurasi approval" />
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">Jalankan Backfill Chain</button>
                </form>
            </div>
        </section>

        </div>
        </div>

        <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="audit-heading">
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

        <x-ui.modal
            id="backfill-help-dialog"
            x-ref="backfillHelpDialog"
            @keydown.tab="trapBackfillHelpFocus($event)"
            show="backfillHelpOpen"
            title="Apa itu Backfill Chain Dinamis?"
            title-id="backfill-help-title"
            description-id="backfill-help-description"
            close-action="closeBackfillHelp()"
            max-width="lg"
        >
            <div id="backfill-help-description" class="space-y-5 text-sm leading-relaxed text-muted">
                <p>Backfill membuat alur persetujuan cuti secara otomatis untuk pegawai aktif yang belum mempunyai chain.</p>

                <div class="rounded-xl border border-primary/15 bg-soft px-4 py-3 text-center font-semibold text-primary">
                    Kepala Bagian <span aria-hidden="true">→</span> Verifikator (jika tersedia) <span aria-hidden="true">→</span> PYBMC
                </div>

                <ul class="list-disc space-y-2 pl-5">
                    <li>Chain dibuat dari Kepala Bagian pegawai, Verifikator lama, dan PYBMC lama yang tersedia.</li>
                    <li>Pegawai yang sudah mempunyai chain aktif dilewati dan tidak diubah.</li>
                    <li>Pegawai tanpa Kepala Bagian atau approver final belum dapat dibuatkan chain.</li>
                    <li>Backfill dapat dijalankan kembali setelah data pegawai diperbaiki.</li>
                    <li>Alasan Backfill disimpan pada setiap chain yang berhasil dibuat dan catatan auditnya.</li>
                </ul>

                <p class="rounded-xl bg-warning/10 px-4 py-3 text-ink">
                    Gunakan Backfill untuk membuat konfigurasi awal secara massal. Untuk mengubah chain pegawai tertentu, gunakan konfigurasi khusus pegawai.
                </p>

                <div class="flex justify-end">
                    <button
                        id="backfill-help-close"
                        x-ref="backfillHelpClose"
                        type="button"
                        @click="closeBackfillHelp()"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
                    >Mengerti</button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-layouts.app>
