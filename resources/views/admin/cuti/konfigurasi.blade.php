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

        <section class="rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="employee-chain-heading">
            <div class="border-b border-border bg-soft/30 px-5 py-4">
                <h3 id="employee-chain-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Chain Approval Pegawai</h3>
                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-muted">Cari lalu pilih pegawai aktif. Autocomplete menampilkan maksimal 15 hasil agar interaksi tetap ringan.</p>
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
                help="Ketik minimal 2 karakter. Hasil dibatasi oleh server agar halaman tetap ringan."
                submit-label="Cari Pegawai"
                class="border-b border-border px-5 py-4"
            />

            @if ($selectedEmployee)
                @if ($selectedKepalaBagian)
                    <form method="GET" action="{{ route('cuti.config') }}" class="grid gap-3 border-b border-border bg-soft/20 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        <input type="hidden" name="search" value="{{ $search }}">
                        <input type="hidden" name="employee_id" value="{{ $selectedEmployee->id }}">
                        <x-form.input name="approver_search" id="approver-search" label="Cari Kandidat Approver" value="{{ $approverSearch }}" placeholder="Nama atau NIP" help="Hasil dibatasi hingga 50 pegawai aktif. Kandidat chain saat ini tetap ditampilkan." />
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">Cari Kandidat</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('cuti.config.employee-chain.store', $selectedEmployee) }}" class="divide-y divide-border">
                    @csrf
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
                            <p class="mt-1 text-[11px] text-muted">Ditentukan dari struktur pegawai. Validasi server tetap menjadi sumber kebenaran.</p>
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
                                    <p class="mt-0.5 text-xs leading-relaxed text-muted">Tambahkan nol atau lebih verifikator. Duplikasi approver dicatat dan dilewati otomatis saat approval.</p>
                                </div>
                                <button type="button" @click="addVerifier()" :disabled="verifiers.length >= maxVerifierSteps" class="inline-flex items-center justify-center rounded-xl border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-50">Tambah Verifikator</button>
                            </div>

                            <p id="verifier-limit-help" class="text-[11px] text-muted">Maksimum 8 verifikator, sehingga Kepala Bagian dan PYBMC tetap berada dalam batas 10 langkah.</p>
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
                                <p class="mt-0.5 text-xs leading-relaxed text-muted">Opsional. Kosongkan untuk memakai PYBMC global. Jika dipilih, PYBMC khusus menjadi approver final.</p>
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
                                <p class="mt-1 text-xs leading-relaxed text-muted">Alasan wajib dicatat dalam log audit kepegawaian.</p>
                            </div>
                             <div class="space-y-3">
                                 <x-form.textarea name="reason" id="employee-chain-reason" :required="true" rows="3" placeholder="Contoh: Penyesuaian verifikator setelah mutasi jabatan" />
                                <button type="submit" class="hidden w-full items-center justify-center rounded-xl border border-transparent bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30 sm:inline-flex">Simpan Chain Pegawai</button>
                             </div>
                         </div>
                     @endif
                 </form>
            @elseif ($search !== null && trim($search) !== '')
                <div class="px-5 py-5 text-sm text-muted">Pilih pegawai dari hasil pencarian untuk melihat Kepala Bagian dan menyusun chain.</div>
            @else
                <div class="px-5 py-5 text-sm text-muted">Cari pegawai untuk mulai menyusun chain per pegawai.</div>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="backfill-heading">
            <div class="flex items-start justify-between gap-3 border-b border-border bg-soft/30 px-5 py-4">
                <div>
                    <h3 id="backfill-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Backfill Chain Dinamis</h3>
                    <p class="mt-0.5 text-xs text-muted">Membuat chain pegawai aktif dari Kepala Bagian dan konfigurasi lama yang tersedia.</p>
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
            <div class="grid gap-4 px-5 py-5 md:grid-cols-[1fr_auto] md:items-start">
                <div class="space-y-2 text-sm text-muted">
                    <p><span class="font-semibold text-ink">{{ $chainStats['active'] }}</span> chain aktif tersedia.</p>
                    <p>Backfill dapat diulang. Pegawai dengan chain aktif dilewati.</p>
                </div>
                <form method="POST" action="{{ route('cuti.config.backfill') }}" class="w-full max-w-md space-y-3">
                    @csrf
                    <x-form.textarea name="backfill_reason" id="backfill-reason" label="Alasan Backfill" :required="true" rows="3" placeholder="Contoh: Backfill awal konfigurasi approval" />
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary shadow-sm transition-all duration-200 hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/20">Jalankan Backfill Chain</button>
                </form>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="global-pybmc-heading">
            <div class="border-b border-border bg-soft/30 px-5 py-4">
                <h3 id="global-pybmc-heading" class="text-xs font-bold uppercase tracking-wider text-ink">PYBMC Global</h3>
                <p class="mt-0.5 text-xs text-muted">Override global mengubah PYBMC pada semua chain aktif. Snapshot pengajuan yang sudah disubmit tetap tidak berubah.</p>
            </div>
            <div class="grid gap-4 px-5 py-5 md:grid-cols-[1fr_auto] md:items-start">
                <div class="space-y-2 text-sm text-muted">
                    <p>PYBMC aktif: <span class="font-semibold text-ink">{{ $globalPybmc?->approver?->nama_lengkap ?? 'Belum ditetapkan' }}</span></p>
                    <p>Perubahan dicatat dalam audit dan diterapkan ke chain aktif.</p>
                </div>
                <form method="POST" action="{{ route('cuti.config.pybmc-global') }}" class="w-full max-w-md space-y-3">
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

        <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-labelledby="audit-heading">
            <div class="flex items-center justify-between border-b border-border bg-soft/30 px-5 py-4">
                <div>
                    <h3 id="audit-heading" class="text-xs font-bold uppercase tracking-wider text-ink">Log Perubahan Konfigurasi</h3>
                    <p class="mt-0.5 text-xs text-muted">Riwayat konfigurasi lama, chain pegawai, dan PYBMC global.</p>
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
