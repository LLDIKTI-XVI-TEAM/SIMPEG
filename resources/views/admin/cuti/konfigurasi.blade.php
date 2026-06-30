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
            <div class="rounded-lg bg-success/10 border border-success/20 px-5 py-3 text-sm text-success flex items-center gap-2">
                <svg class="w-5 h-5 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
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
                        <textarea id="cfg-reason" name="reason" x-model="reason" rows="3"
                            placeholder="Contoh: Pergantian pejabat verifikator karena mutasi jabatan"
                            class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">{{ old('reason') }}</textarea>
                        @error('reason')
                            <p class="text-xs text-danger mt-1">{{ $message }}</p>
                        @enderror

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

        {{-- LOG PERUBAHAN KONFIGURASI (dari audit log nyata) --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Log Perubahan Konfigurasi</h3>
                    <p class="mt-0.5 text-xs text-muted">Riwayat perubahan approver. Klik baris untuk detail.</p>
                </div>
                <span class="text-[10px] font-semibold text-muted bg-soft px-2.5 py-1 rounded-full border border-border">{{ $auditRows->count() }} entri</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-soft border-b border-border">
                            <th class="px-5 py-3 font-semibold text-muted w-8"></th>
                            <th class="px-5 py-3 font-semibold text-muted">Waktu</th>
                            <th class="px-5 py-3 font-semibold text-muted">Pengguna</th>
                            <th class="px-5 py-3 font-semibold text-muted">Tahap</th>
                            <th class="px-5 py-3 font-semibold text-muted text-right">Approver Lama</th>
                            <th class="px-3 py-3 text-center text-muted"></th>
                            <th class="px-5 py-3 font-semibold text-muted">Approver Baru</th>
                            <th class="px-5 py-3 font-semibold text-muted">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
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
                            <tr class="hover:bg-soft/30 transition-colors cursor-pointer"
                                @click="expandedAudit = expandedAudit === {{ $idx }} ? null : {{ $idx }}">
                                <td class="px-5 py-3.5 text-center">
                                    <svg class="w-3.5 h-3.5 text-muted transition-transform duration-200"
                                        :class="expandedAudit === {{ $idx }} ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                </td>
                                <td class="px-5 py-3.5 text-muted whitespace-nowrap">{{ $row->created_at?->format('d M Y, H:i') }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="font-semibold text-ink block">{{ $row->user_name ?? 'Sistem' }}</span>
                                    <span class="text-[9px] text-muted block font-mono">{{ $row->ip_address }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-ink font-medium">{{ $tahap }}</td>
                                <td class="px-5 py-3.5 text-right font-mono text-muted">{{ $oldName }}</td>
                                <td class="px-3 py-3.5 text-center text-muted">&rarr;</td>
                                <td class="px-5 py-3.5 font-mono font-bold text-success">{{ $newName }}</td>
                                <td class="px-5 py-3.5 text-muted italic max-w-[200px] truncate" title="{{ $reasonText }}">{{ $reasonText }}</td>
                            </tr>

                            <tr x-show="expandedAudit === {{ $idx }}" x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" style="display: none;">
                                <td colspan="8" class="bg-soft/30 px-8 py-4 border-t border-border">
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-sm text-muted">
                                    Belum ada perubahan konfigurasi tercatat.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
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
