<x-layouts.app title="Konfigurasi Approval Cuti">
    {{-- State Alpine: menahan pilihan approver dan alasan agar tombol simpan hanya aktif saat alasan terisi. --}}
    <div class="space-y-6" x-data="{
        stage2: '{{ old('stage2_approver_id', $stage2Id) }}',
        stage3: '{{ old('stage3_approver_id', $stage3Id) }}',
        reason: '{{ old('reason', '') }}',
        showConfirm: false,
        expandedAudit: null,

        // Skip-logic ditandai aktif bila approver stage 2 dan stage 3 sama,
        // sehingga super_admin tahu satu tahap approval akan dilewati otomatis.
        get skipDuplicate() {
            return this.stage2 !== '' && this.stage2 === this.stage3;
        },
        openConfirm() {
            if (this.reason.trim() === '' || this.stage2 === '' || this.stage3 === '') return;
            $dispatch('open-confirm-cuti-konfig');
        },
        submitForm() {
            this.$refs.configForm.submit();
        }
    }" @confirm-cuti-konfig.window="submitForm()">

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi Approval Cuti</h2>
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="text-muted">Cuti</span>
                    <span>/</span>
                    <span class="font-medium text-ink">Konfigurasi Approval</span>
                </nav>
            </div>
            <a href="{{ route('cuti') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft">
                Kembali ke Cuti
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>

        @if(session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        {{-- FORM KONFIGURASI APPROVER --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30">
                <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Penentuan Approver Cuti</h3>
                <p class="mt-0.5 text-xs text-muted">Atasan langsung (stage 1) ditentukan otomatis dari struktur pegawai. Form ini hanya menetapkan approver stage 2 dan stage 3.</p>
            </div>

            <form method="POST" action="{{ route('cuti.config.update') }}" x-ref="configForm" class="divide-y divide-border">
                @csrf

                {{-- Penanda skip-logic: muncul hanya ketika approver kedua tahap dipilih sama. --}}
                <template x-if="skipDuplicate">
                    <div class="bg-warning/5 border-b border-warning/15 px-5 py-3 flex items-center gap-2.5">
                        <svg class="w-4 h-4 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span class="text-xs font-medium text-warning">Approver Stage 2 dan Stage 3 sama. Salah satu tahap approval akan dilewati otomatis.</span>
                    </div>
                </template>

                {{-- Stage 2 --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <label class="text-sm font-semibold text-ink block" for="cfg-stage2">Approver Stage 2 (Verifikator) <span class="text-danger">*</span></label>
                        <p class="text-xs text-muted mt-0.5 leading-relaxed">Memverifikasi pengajuan setelah disetujui atasan langsung.</p>
                    </div>
                    <div class="sm:col-span-7">
                        <select id="cfg-stage2" name="stage2_approver_id" x-model="stage2"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">-- Pilih Approver --</option>
                            @foreach($eligibleUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->role }})</option>
                            @endforeach
                        </select>
                        @error('stage2_approver_id')
                            <p class="text-xs text-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Stage 3 --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <label class="text-sm font-semibold text-ink block" for="cfg-stage3">Approver Stage 3 (Pimpinan) <span class="text-danger">*</span></label>
                        <p class="text-xs text-muted mt-0.5 leading-relaxed">Memberi persetujuan final. Setelah tahap ini pengajuan berstatus Disetujui.</p>
                    </div>
                    <div class="sm:col-span-7">
                        <select id="cfg-stage3" name="stage3_approver_id" x-model="stage3"
                            class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">-- Pilih Approver --</option>
                            @foreach($eligibleUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->role }})</option>
                            @endforeach
                        </select>
                        @error('stage3_approver_id')
                            <p class="text-xs text-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Alasan + tombol simpan --}}
                <div class="px-5 py-5 flex flex-col md:flex-row md:items-start justify-between gap-6 hover:bg-soft/5 transition-colors">
                    <div class="max-w-md">
                        <label class="text-sm font-semibold text-ink block" for="cfg-reason">Alasan Perubahan <span class="text-danger">*</span></label>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Sebutkan alasan perubahan approver untuk dicatat dalam log audit kepegawaian.</p>
                    </div>
                    <div class="flex-1 max-w-xl space-y-3">
                        <x-form.textarea
                            name="reason"
                            id="cfg-reason"
                            rows="3"
                            value="{{ old('reason') }}"
                            placeholder="Contoh: Pergantian pejabat verifikator karena mutasi jabatan"
                            class="resize-y transition-colors"
                            x-model="reason"
                        />

                        <button type="button" @click="openConfirm()"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 active:opacity-80 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer"
                            :disabled="reason.trim() === '' || stage2 === '' || stage3 === ''">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            Simpan Konfigurasi
                        </button>
                    </div>
                </div>
            </form>
        </div>

        {{-- BACKFILL CHAIN DINAMIS --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30">
                <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Backfill Chain Dinamis</h3>
                <p class="mt-0.5 text-xs text-muted">Membuat chain approval per pegawai aktif dari Kepala Bagian dan konfigurasi stage 2/3 lama.</p>
            </div>
            <div class="grid grid-cols-1 gap-4 px-5 py-5 md:grid-cols-[1fr_auto] md:items-start">
                <div class="space-y-2 text-sm text-muted">
                    <p><span class="font-semibold text-ink">{{ $chainStats['active'] }}</span> chain aktif sudah tersedia.</p>
                    <p>Backfill aman dijalankan ulang; pegawai yang sudah memiliki chain aktif akan dilewati.</p>
                </div>
                <form method="POST" action="{{ route('cuti.config.backfill') }}" class="w-full max-w-md space-y-3">
                    @csrf
                    <x-form.textarea
                        name="backfill_reason"
                        id="backfill-reason"
                        label="Alasan Backfill"
                        :required="true"
                        rows="3"
                        placeholder="Contoh: Backfill awal dari konfigurasi approval lama"
                    />
                    <button type="submit"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft">
                        Jalankan Backfill Chain
                    </button>
                </form>
            </div>
        </div>

        {{-- PYBMC GLOBAL --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30">
                <h3 class="text-xs font-bold text-ink uppercase tracking-wider">PYBMC Global</h3>
                <p class="mt-0.5 text-xs text-muted">Final approver default untuk chain baru ketika pegawai belum punya PYBMC khusus.</p>
            </div>
            <div class="grid grid-cols-1 gap-4 px-5 py-5 md:grid-cols-[1fr_auto] md:items-start">
                <div class="space-y-2 text-sm text-muted">
                    <p>PYBMC aktif: <span class="font-semibold text-ink">{{ $globalPybmc?->approver?->nama_lengkap ?? 'Belum ditetapkan' }}</span></p>
                    <p>Perubahan dicatat ke audit dan dipakai oleh chain baru berikutnya.</p>
                </div>
                <form method="POST" action="{{ route('cuti.config.pybmc-global') }}" class="w-full max-w-md space-y-3">
                    @csrf
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans" for="pybmc-global-approver">Pegawai PYBMC <span class="text-danger">*</span></label>
                    <select id="pybmc-global-approver" name="approver_employee_id"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <option value="">-- Pilih PYBMC --</option>
                        @foreach($eligibleUsers as $user)
                            @if($user->employee_id)
                                <option value="{{ $user->employee_id }}" @selected(old('approver_employee_id', $globalPybmc?->approver_employee_id) === $user->employee_id)>{{ $user->name }} ({{ $user->role }})</option>
                            @endif
                        @endforeach
                    </select>
                    @error('approver_employee_id')
                        <p class="text-[11px] text-danger font-semibold font-sans">{{ $message }}</p>
                    @enderror
                    <x-form.textarea
                        name="pybmc_reason"
                        id="pybmc-global-reason"
                        label="Alasan PYBMC Global"
                        :required="true"
                        rows="3"
                        placeholder="Contoh: Pergantian pejabat PYBMC"
                    />
                    <button type="submit"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft">
                        Simpan PYBMC Global
                    </button>
                </form>
            </div>
        </div>

        {{-- LOG PERUBAHAN KONFIGURASI (dari audit log nyata) --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Log Perubahan Konfigurasi</h3>
                    <p class="mt-0.5 text-xs text-muted">Riwayat perubahan approver. Klik baris untuk detail.</p>
                </div>
                <x-ui.badge variant="muted" size="md">{{ $auditRows->count() }} entri</x-ui.badge>
            </div>

            <div class="overflow-x-auto">
                <x-ui.table class="text-left border-collapse text-xs">
                    <x-ui.table-head>
                        <x-ui.table-row class="bg-soft border-b border-border">
                            <x-ui.table-th class="px-5 py-3 w-8"></x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Waktu</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Pengguna</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Tahap</x-ui.table-th>
                            <x-ui.table-th align="right" class="px-5 py-3">Approver Lama</x-ui.table-th>
                            <x-ui.table-th align="center" class="px-3 py-3"></x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Approver Baru</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Catatan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($auditRows as $idx => $row)
                            @php
                                // Label tahap diturunkan dari kunci konfigurasi yang disimpan di dalam payload audit
                                // (bukan auditable_id, karena kolom itu bertipe UUID).
                                $configKey = $row->new_values['key'] ?? $row->auditable_id;
                                $tahap = $configKey === 'stage2_approver_id' ? 'Stage 2 (Verifikator)' : ($configKey === 'stage3_approver_id' ? 'Stage 3 (Pimpinan)' : $configKey);
                                $oldName = $row->old_values['approver_name'] ?? 'Tidak ada';
                                $newName = $row->new_values['approver_name'] ?? 'Tidak ada';
                                $reasonText = $row->new_values['reason'] ?? '-';
                            @endphp
                            <x-ui.table-row @click="expandedAudit = expandedAudit === {{ $idx }} ? null : {{ $idx }}" :interactive="true" class="cursor-pointer">
                                <x-ui.table-td align="center" padding="wide">
                                    <svg class="w-3.5 h-3.5 text-muted transition-transform duration-200"
                                        :class="expandedAudit === {{ $idx }} ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                </x-ui.table-td>
                                <x-ui.table-td padding="wide" class="text-muted whitespace-nowrap">{{ $row->created_at?->format('d M Y, H:i') }}</x-ui.table-td>
                                <x-ui.table-td padding="wide">
                                    <span class="font-semibold text-ink block">{{ $row->user_name ?? 'Sistem' }}</span>
                                    <span class="text-[9px] text-muted block font-mono">{{ $row->ip_address }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="wide" class="font-medium">{{ $tahap }}</x-ui.table-td>
                                <x-ui.table-td align="right" padding="wide" class="font-mono text-muted">{{ $oldName }}</x-ui.table-td>
                                <x-ui.table-td align="center" class="px-3 py-3.5 text-muted">&rarr;</x-ui.table-td>
                                <x-ui.table-td padding="wide" class="font-mono font-bold text-success">{{ $newName }}</x-ui.table-td>
                                <x-ui.table-td title="{{ $reasonText }}" padding="wide" class="text-muted italic max-w-[200px] truncate">{{ $reasonText }}</x-ui.table-td>
                            </x-ui.table-row>

                            <x-ui.table-row x-show="expandedAudit === {{ $idx }}" x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" style="display: none;">
                                <x-ui.table-td colspan="8" class="bg-soft/30 px-8 py-4 border-t border-border">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 text-xs">
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">IP Address</span>
                                            <p class="text-sm text-ink font-mono">{{ $row->ip_address ?? '-' }}</p>
                                        </div>
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">User Agent</span>
                                            <p class="text-xs text-muted truncate font-mono" title="{{ $row->user_agent }}">{{ $row->user_agent ?? '-' }}</p>
                                        </div>
                                        <div class="space-y-1 sm:col-span-2 font-sans">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Alasan Perubahan</span>
                                            <p class="text-sm text-ink leading-relaxed">{{ $reasonText }}</p>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="8" align="center" class="px-5 py-8 text-sm text-muted">
                                    Belum ada perubahan konfigurasi tercatat.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
        </div>

        {{-- MODAL KONFIRMASI --}}

        <x-ui.confirm-dialog
            id="cuti-konfig"
            title="Konfirmasi Perubahan Approver"
            message="Perubahan ini langsung berlaku untuk pengajuan cuti berikutnya dan dicatat di log audit."
            confirm-text="Ya, Simpan"
            variant="warning"
        />

    </div>
</x-layouts.app>
