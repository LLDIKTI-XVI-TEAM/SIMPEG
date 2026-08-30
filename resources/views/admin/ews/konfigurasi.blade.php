<x-layouts.app title="Konfigurasi EWS">
    @php
        // Helper: convert days to human-readable label
        function daysToHumanLabel($days)
        {
            $d = (int) $days;
            if ($d >= 365 && $d % 365 === 0)
                return 'H-' . ($d / 365) . ' Tahun';
            if ($d >= 360 && $d <= 370)
                return '≈ H-1 Tahun';
            if ($d >= 330 && $d < 360)
                return '≈ H-11 Bulan';
            if ($d >= 300 && $d < 330)
                return '≈ H-10 Bulan';
            if ($d >= 270 && $d < 300)
                return '≈ H-9 Bulan';
            if ($d >= 240 && $d < 270)
                return '≈ H-8 Bulan';
            if ($d >= 210 && $d < 240)
                return '≈ H-7 Bulan';
            if ($d >= 180 && $d < 210)
                return '≈ H-6 Bulan';
            if ($d >= 150 && $d < 180)
                return '≈ H-5 Bulan';
            if ($d >= 120 && $d < 150)
                return '≈ H-4 Bulan';
            if ($d >= 90 && $d < 120)
                return '≈ H-3 Bulan';
            if ($d >= 60 && $d < 90)
                return '≈ H-2 Bulan';
            if ($d >= 28 && $d < 60)
                return '≈ H-1 Bulan';
            if ($d >= 14 && $d < 28)
                return 'H-' . $d . ' Hari (≈2 Minggu)';
            return 'H-' . $d . ' Hari';
        }
    @endphp

    {{-- ================================================================ --}}
    {{-- Alpine.js state --}}
    {{-- ================================================================ --}}
    <div class="space-y-6" x-data="{
            configs: {{ json_encode($configs) }},
            reason: '{{ old('reason', '') }}',
            showConfirm: false,
            expandedAudit: null,

            // Visual Helpers
            pangkat_h90: '{{ old('pangkat_h90', $configs['pangkat_h90']) }}',
            pangkat_h60: '{{ old('pangkat_h60', $configs['pangkat_h60']) }}',
            pangkat_h30: '{{ old('pangkat_h30', $configs['pangkat_h30']) }}',

            kgb_h60: '{{ old('kgb_h60', $configs['kgb_h60']) }}',
            kgb_h30: '{{ old('kgb_h30', $configs['kgb_h30']) }}',
            kgb_h14: '{{ old('kgb_h14', $configs['kgb_h14']) }}',

            pensiun_y1: '{{ old('pensiun_y1', $configs['pensiun_y1']) }}',
            pensiun_m6: '{{ old('pensiun_m6', $configs['pensiun_m6']) }}',
            pensiun_m3: '{{ old('pensiun_m3', $configs['pensiun_m3']) }}',

            pppk_m6: '{{ old('pppk_m6', $configs['pppk_m6']) }}',
            pppk_m3: '{{ old('pppk_m3', $configs['pppk_m3']) }}',
            pppk_m1: '{{ old('pppk_m1', $configs['pppk_m1']) }}',

            satyalancana_h180: '{{ old('satyalancana_h180', $configs['satyalancana_h180']) }}',
            satyalancana_h90: '{{ old('satyalancana_h90', $configs['satyalancana_h90']) }}',
            satyalancana_h30: '{{ old('satyalancana_h30', $configs['satyalancana_h30']) }}',

            ews_scheduler_time: '{{ old('ews_scheduler_time', $configs['ews_scheduler_time']) }}',

            // Threshold validation
            get thresholdWarnings() {
                let warnings = [];
                if (+this.pangkat_h90 <= +this.pangkat_h60) warnings.push('Pangkat: Tahap 1 harus > Tahap 2');
                if (+this.pangkat_h60 <= +this.pangkat_h30) warnings.push('Pangkat: Tahap 2 harus > Tahap 3');
                if (+this.kgb_h60 <= +this.kgb_h30) warnings.push('KGB: Tahap 1 harus > Tahap 2');
                if (+this.kgb_h30 <= +this.kgb_h14) warnings.push('KGB: Tahap 2 harus > Tahap 3');
                if (+this.pensiun_y1 <= +this.pensiun_m6) warnings.push('Pensiun: Tahap 1 harus > Tahap 2');
                if (+this.pensiun_m6 <= +this.pensiun_m3) warnings.push('Pensiun: Tahap 2 harus > Tahap 3');
                if (+this.pppk_m6 <= +this.pppk_m3) warnings.push('PPPK: Tahap 1 harus > Tahap 2');
                if (+this.pppk_m3 <= +this.pppk_m1) warnings.push('PPPK: Tahap 2 harus > Tahap 3');
                if (+this.satyalancana_h180 <= +this.satyalancana_h90) warnings.push('Satyalancana: Tahap 1 harus > Tahap 2');
                if (+this.satyalancana_h90 <= +this.satyalancana_h30) warnings.push('Satyalancana: Tahap 2 harus > Tahap 3');
                return warnings;
            },

            // Helper: convert days to human-readable
            humanLabel(days) {
                const d = parseInt(days);
                if (isNaN(d) || d <= 0) return '-';
                if (d >= 365 && d % 365 === 0) return 'H-' + (d / 365) + ' Tahun';
                if (d >= 360) return '≈ H-1 Tahun';
                if (d >= 180) return '≈ H-6 Bulan';
                if (d >= 90) return '≈ H-3 Bulan';
                if (d >= 60) return '≈ H-2 Bulan';
                if (d >= 28) return '≈ H-1 Bulan';
                if (d >= 14) return 'H-' + d + ' Hari';
                return 'H-' + d + ' Hari';
            },

            p90Class() { return +this.pangkat_h90 <= +this.pangkat_h60 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            p60Class() { return (+this.pangkat_h90 <= +this.pangkat_h60 || +this.pangkat_h60 <= +this.pangkat_h30) ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            p30Class() { return +this.pangkat_h60 <= +this.pangkat_h30 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },

            k60Class() { return +this.kgb_h60 <= +this.kgb_h30 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            k30Class() { return (+this.kgb_h60 <= +this.kgb_h30 || +this.kgb_h30 <= +this.kgb_h14) ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            k14Class() { return +this.kgb_h30 <= +this.kgb_h14 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },

            py1Class() { return +this.pensiun_y1 <= +this.pensiun_m6 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            pm6Class() { return (+this.pensiun_y1 <= +this.pensiun_m6 || +this.pensiun_m6 <= +this.pensiun_m3) ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            pm3Class() { return +this.pensiun_m6 <= +this.pensiun_m3 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },

            pp6Class() { return +this.pppk_m6 <= +this.pppk_m3 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            pp3Class() { return (+this.pppk_m6 <= +this.pppk_m3 || +this.pppk_m3 <= +this.pppk_m1) ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            pp1Class() { return +this.pppk_m3 <= +this.pppk_m1 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },

            sl180Class() { return +this.satyalancana_h180 <= +this.satyalancana_h90 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            sl90Class() { return (+this.satyalancana_h180 <= +this.satyalancana_h90 || +this.satyalancana_h90 <= +this.satyalancana_h30) ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },
            sl30Class() { return +this.satyalancana_h90 <= +this.satyalancana_h30 ? 'border-danger focus:ring-danger/20 focus:border-danger' : 'border-border focus:ring-primary/20 focus:border-primary'; },

            openConfirm() {
                if (this.reason.trim() === '' || this.thresholdWarnings.length > 0) return;
                $dispatch('open-confirm-ews-konfig');
            },
            submitForm() {
                this.$refs.configForm.submit();
            }
        }" @confirm-ews-konfig.window="submitForm()">

        {{-- ================================================================ --}}
        {{-- PAGE HEADER --}}
        {{-- ================================================================ --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi EWS</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Konfigurasi EWS']
                ]" />
            </div>
        </div>

        @if(session('success'))
            <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
        @endif

        @if(session('error'))
            <x-ui.alert variant="danger" class="mb-4">{{ session('error') }}</x-ui.alert>
        @endif
        <div
            class="rounded-lg border border-border bg-surface px-5 py-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div>
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider block">Status
                        Scheduler</span>
                    <x-ui.badge
                        :variant="$schedulerStatus['status'] === 'gagal' ? 'danger' : ($schedulerStatus['status'] === 'berhasil' ? 'success' : 'muted')"
                        size="md"
                        dot
                    >
                        @if($schedulerStatus['status'] === 'gagal')
                            Gagal (Perlu Perhatian)
                        @elseif($schedulerStatus['status'] === 'berhasil')
                            Aktif & Berjalan Normal
                        @else
                            {{ $schedulerStatus['status_label'] ?? 'Belum Jalan' }}
                        @endif
                    </x-ui.badge>
                </div>
            </div>

            <div class="h-px md:h-8 w-full md:w-px bg-border"></div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 flex-1 max-w-2xl">
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Waktu Harian</span>
                    <span class="text-xs font-semibold text-ink"><span x-text="ews_scheduler_time"></span>
                        WITA</span>
                </div>
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Terakhir Jalan</span>
                    <span class="text-xs font-semibold text-ink">{{ $schedulerStatus['last_run'] }}</span>
                </div>
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Total Pegawai</span>
                    <span class="text-xs font-semibold text-ink">{{ $schedulerStatus['employees_checked'] }}
                        diperiksa</span>
                </div>
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Peringatan</span>
                    <x-ui.badge variant="warning" size="sm">{{ $schedulerStatus['alerts_created'] }} peringatan</x-ui.badge>
                </div>
            </div>
        </div>

        @if($schedulerStatus['status'] === 'gagal' && !empty($schedulerStatus['error_message']))
            <div class="rounded-lg border border-danger/25 bg-danger/5 px-5 py-3 mt-3">
                <div class="flex items-start gap-2.5">
                    <svg class="w-4 h-4 shrink-0 text-danger mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <span class="text-xs font-semibold text-danger">Pesan Error Eksekusi Terakhir:</span>
                        <p class="text-xs text-danger mt-1 whitespace-pre-wrap">{{ $schedulerStatus['error_message'] }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================================================================ --}}
        {{-- E2: CORE CONFIGURATIONS FORM (Settings Row-by-Row Flow) --}}
        {{-- ================================================================ --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Daftar Parameter Ambang Batas EWS
                    </h3>
                </div>
                <div class="flex items-center gap-2.5">
                    <span
                        class="inline-flex items-center gap-1.5 text-[10px] font-semibold text-success">
                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                        Dapat Diubah
                    </span>
                </div>
            </div>

            <form method="POST" action="{{ route('ews.config.update') }}" x-ref="configForm"
                class="divide-y divide-border">
                @csrf

                {{-- Threshold validation warnings (live) --}}
                <template x-if="thresholdWarnings.length > 0">
                    <div class="rounded-lg border border-danger/25 bg-danger/5 px-5 py-3 space-y-1">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 shrink-0 text-danger" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            <span class="text-xs font-semibold text-danger">Peringatan Urutan Threshold:</span>
                        </div>
                        <template x-for="w in thresholdWarnings" :key="w">
                            <p class="text-[11px] text-danger pl-6" x-text="'• ' + w"></p>
                        </template>
                    </div>
                </template>

                <!-- Header Tabel Desktop -->
                <div class="px-5 py-3 bg-soft/40 border-b border-border grid grid-cols-1 sm:grid-cols-12 gap-4 text-[10px] font-bold text-muted uppercase tracking-wider hidden sm:grid select-none">
                    <div class="sm:col-span-5 flex items-center gap-3">
                        <span>Parameter Notifikasi</span>
                    </div>
                    <div class="sm:col-span-7 grid grid-cols-3 gap-6 text-center">
                        <div class="text-success bg-success/5 border border-success/10 rounded-md py-1">Tahap 1 (Awal)</div>
                        <div class="text-warning bg-warning/5 border border-warning/10 rounded-md py-1">Tahap 2 (Dekat)</div>
                        <div class="text-danger bg-danger/5 border border-danger/10 rounded-md py-1">Tahap 3 (Mendesak)</div>
                    </div>
                </div>

                {{-- Row 1: Scheduler Time --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-ink block" for="cfg-scheduler-time">Waktu Scheduler Harian (WITA)</label>
                                <p class="text-xs text-muted mt-0.5 leading-relaxed">Pemeriksaan otomatis berjalan di jam ini setiap hari untuk memperbarui peringatan.</p>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-7 flex sm:justify-end">
                        <div class="w-full sm:w-auto">
                            <input type="time" id="cfg-scheduler-time" name="ews_scheduler_time"
                                x-model="ews_scheduler_time"
                                class="w-full sm:w-40 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-semibold">
                            @error('ews_scheduler_time')
                                <p class="text-xs text-danger mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Row 2: Masa Berlaku & Milestone Event --}}
                <div class="border-y border-border bg-soft/20 px-5 py-5">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-ink">Masa Berlaku Event EWS</h3>
                        <p class="mt-0.5 text-xs leading-relaxed text-muted">Ubah durasi dasar setiap event. Perubahan langsung dipakai scheduler untuk data lama dan baru. Nilai BUP global <strong>0</strong> mempertahankan BUP berdasarkan jabatan.</p>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <label class="rounded-xl border border-border bg-surface p-3">
                            <span class="block text-xs font-semibold text-ink">Kenaikan Pangkat</span>
                            <span class="mt-0.5 block text-[10px] text-muted">TMT pangkat + tahun</span>
                            <span class="mt-2 flex items-center gap-2">
                                <input type="number" name="pangkat_required_years" min="1" max="100" step="1" value="{{ old('pangkat_required_years', $configs['pangkat_required_years']) }}" class="w-20 rounded-lg border border-border bg-surface px-2 py-1.5 text-center text-xs text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <span class="text-xs font-medium text-muted">tahun</span>
                            </span>
                            @error('pangkat_required_years')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </label>
                        <label class="rounded-xl border border-border bg-surface p-3">
                            <span class="block text-xs font-semibold text-ink">KGB</span>
                            <span class="mt-0.5 block text-[10px] text-muted">TMT KGB + tahun</span>
                            <span class="mt-2 flex items-center gap-2">
                                <input type="number" name="kgb_required_years" min="1" max="100" step="1" value="{{ old('kgb_required_years', $configs['kgb_required_years']) }}" class="w-20 rounded-lg border border-border bg-surface px-2 py-1.5 text-center text-xs text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <span class="text-xs font-medium text-muted">tahun</span>
                            </span>
                            @error('kgb_required_years')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </label>
                        <label class="rounded-xl border border-border bg-surface p-3">
                            <span class="block text-xs font-semibold text-ink">BUP Pensiun Global</span>
                            <span class="mt-0.5 block text-[10px] text-muted">0 = mengikuti BUP jabatan</span>
                            <span class="mt-2 flex items-center gap-2">
                                <input type="number" name="pensiun_required_age_years" min="0" max="100" step="1" value="{{ old('pensiun_required_age_years', $configs['pensiun_required_age_years']) }}" class="w-20 rounded-lg border border-border bg-surface px-2 py-1.5 text-center text-xs text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <span class="text-xs font-medium text-muted">tahun</span>
                            </span>
                            @error('pensiun_required_age_years')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </label>
                        <label class="rounded-xl border border-border bg-surface p-3">
                            <span class="block text-xs font-semibold text-ink">Masa Kontrak PPPK</span>
                            <span class="mt-0.5 block text-[10px] text-muted">Dipakai jika tanggal akhir kosong</span>
                            <span class="mt-2 flex items-center gap-2">
                                <input type="number" name="pppk_contract_years" min="1" max="100" step="1" value="{{ old('pppk_contract_years', $configs['pppk_contract_years']) }}" class="w-20 rounded-lg border border-border bg-surface px-2 py-1.5 text-center text-xs text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <span class="text-xs font-medium text-muted">tahun</span>
                            </span>
                            @error('pppk_contract_years')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </label>
                        <div class="rounded-xl border border-border bg-surface p-3">
                            <span class="block text-xs font-semibold text-ink">Milestone Satyalancana</span>
                            <span class="mt-0.5 block text-[10px] text-muted">Harus berurutan dari kecil ke besar</span>
                            <span class="mt-2 flex items-center gap-1.5">
                                @foreach(['satyalancana_years_1', 'satyalancana_years_2', 'satyalancana_years_3'] as $field)
                                    <input type="number" name="{{ $field }}" min="1" max="100" step="1" value="{{ old($field, $configs[$field]) }}" class="w-14 rounded-lg border border-border bg-surface px-1 py-1.5 text-center text-xs text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" aria-label="{{ $field }} dalam tahun">
                                @endforeach
                                <span class="text-xs font-medium text-muted">th</span>
                            </span>
                            @foreach(['satyalancana_years_1', 'satyalancana_years_2', 'satyalancana_years_3'] as $field)
                                @error($field)<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Row 3: Kenaikan Pangkat --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-ink block">Kenaikan Pangkat</span>
                                <p class="text-xs text-muted mt-0.5 leading-relaxed">Peringatan periodik menjelang kenaikan pangkat berkala dari TMT pangkat ditambah masa yang diatur pada konfigurasi.</p>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-7 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Tahap 1 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-success/5 border border-success/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-success uppercase tracking-wider block sm:hidden">Tahap 1 (Awal)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pangkat_h90" x-model="pangkat_h90" min="1" step="1"
                                    aria-label="Kenaikan Pangkat Tahap 1 (Awal) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + p90Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-success min-w-[68px] text-center" x-text="humanLabel(pangkat_h90)"></span>
                        </div>
                        <!-- Tahap 2 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-warning/5 border border-warning/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-warning uppercase tracking-wider block sm:hidden">Tahap 2 (Dekat)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pangkat_h60" x-model="pangkat_h60" min="1" step="1"
                                    aria-label="Kenaikan Pangkat Tahap 2 (Dekat) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + p60Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-warning min-w-[68px] text-center" x-text="humanLabel(pangkat_h60)"></span>
                        </div>
                        <!-- Tahap 3 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-danger/5 border border-danger/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-danger uppercase tracking-wider block sm:hidden">Tahap 3 (Mendesak)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pangkat_h30" x-model="pangkat_h30" min="1" step="1"
                                    aria-label="Kenaikan Pangkat Tahap 3 (Mendesak) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + p30Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-danger min-w-[68px] text-center" x-text="humanLabel(pangkat_h30)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 3: KGB --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5h16.5M5.25 7.5h13.5m-12 9a3 3 0 0 1 3-3h9a3 3 0 0 1 3 3M5.25 12h13.5" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-ink block">Kenaikan Gaji Berkala (KGB)</span>
                                <p class="text-xs text-muted mt-0.5 leading-relaxed">Peringatan periodik menjelang kenaikan gaji berkala dari TMT KGB ditambah masa yang diatur pada konfigurasi.</p>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-7 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Tahap 1 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-success/5 border border-success/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-success uppercase tracking-wider block sm:hidden">Tahap 1 (Awal)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="kgb_h60" x-model="kgb_h60" min="1" step="1"
                                    aria-label="Kenaikan Gaji Berkala Tahap 1 (Awal) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + k60Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-success min-w-[68px] text-center" x-text="humanLabel(kgb_h60)"></span>
                        </div>
                        <!-- Tahap 2 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-warning/5 border border-warning/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-warning uppercase tracking-wider block sm:hidden">Tahap 2 (Dekat)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="kgb_h30" x-model="kgb_h30" min="1" step="1"
                                    aria-label="Kenaikan Gaji Berkala Tahap 2 (Dekat) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + k30Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-warning min-w-[68px] text-center" x-text="humanLabel(kgb_h30)"></span>
                        </div>
                        <!-- Tahap 3 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-danger/5 border border-danger/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-danger uppercase tracking-wider block sm:hidden">Tahap 3 (Mendesak)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="kgb_h14" x-model="kgb_h14" min="1" step="1"
                                    aria-label="Kenaikan Gaji Berkala Tahap 3 (Mendesak) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + k14Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-danger min-w-[68px] text-center" x-text="humanLabel(kgb_h14)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 4: Pensiun --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-ink block">Batas Usia Pensiun (BUP)</span>
                                <p class="text-xs text-muted mt-0.5 leading-relaxed">Peringatan pensiun berdasarkan BUP jabatan, atau usia BUP global bila diatur pada konfigurasi.</p>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-7 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Tahap 1 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-success/5 border border-success/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-success uppercase tracking-wider block sm:hidden">Tahap 1 (Awal)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pensiun_y1" x-model="pensiun_y1" min="1" step="1"
                                    aria-label="Batas Usia Pensiun Tahap 1 (Awal) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + py1Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-success min-w-[68px] text-center" x-text="humanLabel(pensiun_y1)"></span>
                        </div>
                        <!-- Tahap 2 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-warning/5 border border-warning/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-warning uppercase tracking-wider block sm:hidden">Tahap 2 (Dekat)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pensiun_m6" x-model="pensiun_m6" min="1" step="1"
                                    aria-label="Batas Usia Pensiun Tahap 2 (Dekat) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + pm6Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-warning min-w-[68px] text-center" x-text="humanLabel(pensiun_m6)"></span>
                        </div>
                        <!-- Tahap 3 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-danger/5 border border-danger/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-danger uppercase tracking-wider block sm:hidden">Tahap 3 (Mendesak)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pensiun_m3" x-model="pensiun_m3" min="1" step="1"
                                    aria-label="Batas Usia Pensiun Tahap 3 (Mendesak) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + pm3Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-danger min-w-[68px] text-center" x-text="humanLabel(pensiun_m3)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 5: PPPK --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-ink block">Kontrak PPPK</span>
                                <p class="text-xs text-muted mt-0.5 leading-relaxed">Peringatan periodik menjelang kedaluwarsa masa penugasan kontrak PPPK pegawai.</p>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-7 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Tahap 1 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-success/5 border border-success/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-success uppercase tracking-wider block sm:hidden">Tahap 1 (Awal)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pppk_m6" x-model="pppk_m6" min="1" step="1"
                                    aria-label="Kontrak PPPK Tahap 1 (Awal) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + pp6Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-success min-w-[68px] text-center" x-text="humanLabel(pppk_m6)"></span>
                        </div>
                        <!-- Tahap 2 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-warning/5 border border-warning/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-warning uppercase tracking-wider block sm:hidden">Tahap 2 (Dekat)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pppk_m3" x-model="pppk_m3" min="1" step="1"
                                    aria-label="Kontrak PPPK Tahap 2 (Dekat) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + pp3Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-warning min-w-[68px] text-center" x-text="humanLabel(pppk_m3)"></span>
                        </div>
                        <!-- Tahap 3 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-danger/5 border border-danger/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-danger uppercase tracking-wider block sm:hidden">Tahap 3 (Mendesak)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="pppk_m1" x-model="pppk_m1" min="1" step="1"
                                    aria-label="Kontrak PPPK Tahap 3 (Mendesak) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + pp1Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-danger min-w-[68px] text-center" x-text="humanLabel(pppk_m1)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 6: Satyalancana --}}
                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-12 gap-4 items-center hover:bg-soft/10 transition-colors">
                    <div class="sm:col-span-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.504-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.871m0 0a4.5 4.5 0 0 1 4.008 0m-4.008 0L7.5 12.75m6.004 1.5 1.996-1.5m-8 0a3 3 0 1 1 6 0m2 0a3 3 0 1 0-6 0" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-ink block">Satyalancana</span>
                                <p class="text-xs text-muted mt-0.5 leading-relaxed">Peringatan menjelang tiga milestone masa kerja dari TMT pengangkatan pertama yang dapat diatur pada konfigurasi.</p>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-7 grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Tahap 1 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-success/5 border border-success/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-success uppercase tracking-wider block sm:hidden">Tahap 1 (Awal)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="satyalancana_h180" x-model="satyalancana_h180" min="1" step="1"
                                    aria-label="Satyalancana Tahap 1 (Awal) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + sl180Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-success min-w-[68px] text-center" x-text="humanLabel(satyalancana_h180)"></span>
                        </div>
                        <!-- Tahap 2 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-warning/5 border border-warning/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-warning uppercase tracking-wider block sm:hidden">Tahap 2 (Dekat)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="satyalancana_h90" x-model="satyalancana_h90" min="1" step="1"
                                    aria-label="Satyalancana Tahap 2 (Dekat) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + sl90Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-warning min-w-[68px] text-center" x-text="humanLabel(satyalancana_h90)"></span>
                        </div>
                        <!-- Tahap 3 -->
                        <div class="flex items-center sm:flex-col justify-between sm:justify-center gap-2 bg-danger/5 border border-danger/15 rounded-xl px-3 py-2.5">
                            <span class="text-[10px] font-bold text-danger uppercase tracking-wider block sm:hidden">Tahap 3 (Mendesak)</span>
                            <div class="flex items-center gap-1.5">
                                <input type="number" name="satyalancana_h30" x-model="satyalancana_h30" min="1" step="1"
                                    aria-label="Satyalancana Tahap 3 (Mendesak) dalam hari"
                                    :class="'w-20 sm:w-24 text-center rounded-lg border px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 bg-surface ' + sl30Class()">
                                <span class="text-[10px] font-semibold text-muted" aria-hidden="true">hari</span>
                            </div>
                            <span class="text-[10px] font-bold text-danger min-w-[68px] text-center" x-text="humanLabel(satyalancana_h30)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 7: Reason input & Action Button --}}
                <div
                    class="px-5 py-5 flex flex-col md:flex-row md:items-start justify-between gap-6 hover:bg-soft/5 transition-colors">
                    <div class="max-w-md">
                        <label class="text-sm font-semibold text-ink block" for="cfg-reason">Alasan Perubahan <span
                                class="text-danger">*</span></label>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Sebutkan alasan atau dasar hukum perubahan
                            nilai ambang batas ini untuk dicatat dalam log audit kepegawaian secara lengkap.</p>
                    </div>
                    <div class="flex-1 max-w-xl space-y-3">
                        <x-form.textarea
                            name="reason"
                            id="cfg-reason"
                            rows="3"
                            placeholder="Contoh: Penyesuaian masa tenggang berkas usul pensiun dan kenaikan pangkat untuk semester gasal"
                            class="resize-y transition-colors"
                            x-model="reason"
                        />

                        <x-ui.button type="button" @click="openConfirm()" class="w-full"
                            ::disabled="reason.trim() === '' || thresholdWarnings.length > 0">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            Simpan Konfigurasi EWS
                        </x-ui.button>
                        <div class="space-y-1" aria-live="polite">
                            <p class="text-xs text-danger" x-show="reason.trim() === ''">
                                Isi alasan perubahan sebelum menyimpan konfigurasi.
                            </p>
                            <p class="text-xs text-danger" x-show="reason.trim() !== '' && thresholdWarnings.length > 0">
                                Perbaiki urutan threshold terlebih dahulu. Tahap 1 harus lebih besar dari Tahap 2, dan Tahap 2 lebih besar dari Tahap 3.
                            </p>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        {{-- ================================================================ --}}
        {{-- E3: TIDY AUDIT LOG TABLE (Expandable Table style) --}}
        {{-- ================================================================ --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30">
                <div>
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Log Perubahan Konfigurasi</h3>
                    <p class="mt-0.5 text-xs text-muted">Riwayat lengkap perubahan parameter EWS. Klik baris untuk
                        detail IP, device, dan nilai lama/baru.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <x-ui.table class="text-left border-collapse text-xs">
                    <x-ui.table-head>
                        <x-ui.table-row class="bg-soft border-b border-border">
                            <x-ui.table-th class="px-5 py-3 w-8"></x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Waktu</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Pengguna</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Parameter</x-ui.table-th>
                            <x-ui.table-th align="right" class="px-5 py-3">Nilai Lama</x-ui.table-th>
                            <x-ui.table-th align="center" class="px-3 py-3"></x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Nilai Baru</x-ui.table-th>
                            <x-ui.table-th class="px-5 py-3">Catatan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($auditRows as $idx => $row)
                            {{-- Main row --}}
                            <x-ui.table-row @click="expandedAudit = expandedAudit === {{ $idx }} ? null : {{ $idx }}" :interactive="true" class="cursor-pointer">
                                <x-ui.table-td align="center" padding="wide">
                                    <svg class="w-3.5 h-3.5 text-muted transition-transform duration-200"
                                        :class="expandedAudit === {{ $idx }} ? 'rotate-90' : ''" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                </x-ui.table-td>
                                <x-ui.table-td padding="wide" class="text-muted whitespace-nowrap">{{ $row['time'] }}</x-ui.table-td>
                                <x-ui.table-td padding="wide">
                                    <span class="font-semibold text-ink block">{{ $row['actor'] }}</span>
                                    <span class="text-[9px] text-muted block">{{ $row['ip_address'] }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="wide" class="font-medium">{{ $row['field'] }}</x-ui.table-td>
                                <x-ui.table-td align="right" padding="wide" class="text-muted">{{ $row['before'] }}</x-ui.table-td>
                                <x-ui.table-td align="center" class="px-3 py-3.5 text-muted">→</x-ui.table-td>
                                <x-ui.table-td padding="wide" class="font-bold text-success">{{ $row['after'] }}</x-ui.table-td>
                                <x-ui.table-td title="{{ $row['reason'] }}" padding="wide" class="text-muted italic max-w-[200px] truncate">{{ $row['reason'] }}</x-ui.table-td>
                            </x-ui.table-row>

                            {{-- Detail row (expanded) --}}
                            <x-ui.table-row x-show="expandedAudit === {{ $idx }}" x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                style="display: none;">
                                <x-ui.table-td colspan="8" class="bg-soft/30 px-8 py-4 border-t border-border">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 text-xs">
                                        {{-- Event --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Event
                                                Type</span>
                                            <p class="text-sm font-semibold text-ink">
                                                {{ $row['event'] ?? 'UPDATE_EWS_CONFIG' }}
                                            </p>
                                        </div>
                                        {{-- IP Address --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">IP
                                                Address</span>
                                            <p class="text-sm text-ink">{{ $row['ip_address'] ?? '127.0.0.1' }}
                                            </p>
                                        </div>
                                        {{-- User Agent --}}
                                        <div class="space-y-1 sm:col-span-2">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">User
                                                Agent</span>
                                            <p class="text-xs text-muted truncate"
                                                title="{{ $row['user_agent'] ?? 'Mozilla/5.0' }}">
                                                {{ $row['user_agent'] ?? 'Mozilla/5.0' }}
                                            </p>
                                        </div>
                                        {{-- Old Values --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Nilai
                                                Lama (JSON)</span>
                                            <div class="rounded-md border border-danger/20 bg-danger/5 px-3 py-2">
                                                <code
                                                    class="text-xs text-danger">{{ json_encode(['value' => $row['before']], JSON_PRETTY_PRINT) }}</code>
                                            </div>
                                        </div>
                                        {{-- New Values --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Nilai
                                                Baru (JSON)</span>
                                            <div class="rounded-md border border-success/20 bg-success/5 px-3 py-2">
                                                <code
                                                    class="text-xs text-success">{{ json_encode(['value' => $row['after']], JSON_PRETTY_PRINT) }}</code>
                                            </div>
                                        </div>
                                        {{-- Reason --}}
                                        <div class="space-y-1 sm:col-span-2 font-sans">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Alasan
                                                Perubahan Lengkap</span>
                                            <p class="text-sm text-ink leading-relaxed">
                                                {{ $row['reason'] ?? 'Penyesuaian konfigurasi rutin' }}
                                            </p>
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

            <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-soft/20 px-6 py-4 sm:flex-row">
                <div class="flex flex-wrap items-center justify-center gap-4 text-sm text-muted sm:justify-start">
                    <form method="GET" action="{{ route('ews.config') }}" class="flex items-center gap-2">
                        <span class="whitespace-nowrap">Tampilkan</span>
                        <label for="ews-config-log-per-page" class="sr-only">Jumlah log perubahan per halaman</label>
                        <select
                            id="ews-config-log-per-page"
                            name="log_per_page"
                            onchange="this.form.submit()"
                            class="cursor-pointer appearance-none rounded-md border border-border bg-surface px-2.5 py-1 text-center font-sans text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                            @foreach([10, 25, 50] as $option)
                                <option value="{{ $option }}" @selected($auditRows->perPage() === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        <span class="hidden sm:inline">data</span>
                    </form>

                    <div class="hidden border-l border-border pl-4 md:block">
                        Menampilkan <span class="font-medium text-ink">{{ $auditRows->firstItem() ?? 0 }}</span>
                        - <span class="font-medium text-ink">{{ $auditRows->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium text-ink">{{ $auditRows->total() }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $auditRows->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </div>

        {{-- CONFIRMATION MODAL --}}
        <x-ui.confirm-dialog
            id="ews-konfig"
            title="Konfirmasi Perubahan Threshold EWS"
            message="Pastikan threshold yang Anda masukkan sudah sesuai. Perubahan ini akan segera memengaruhi status peringatan dini seluruh pegawai."
            confirm-text="Ya, Terapkan Perubahan"
            variant="warning"
        >
            <div class="rounded-lg bg-soft/50 p-4 border border-border">
                <dl class="space-y-3 text-xs">
                    <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                        <dt class="text-muted">Scheduler Time (WITA)</dt>
                        <dd class="font-semibold text-ink" x-text="ews_scheduler_time"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                        <dt class="text-muted">Pangkat Tahap 1 / 2 / 3</dt>
                        <dd class="font-semibold text-ink"><span x-text="pangkat_h90"></span> / <span
                                x-text="pangkat_h60"></span> / <span x-text="pangkat_h30"></span></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                        <dt class="text-muted">KGB Tahap 1 / 2 / 3</dt>
                        <dd class="font-semibold text-ink"><span x-text="kgb_h60"></span> / <span
                                x-text="kgb_h30"></span> / <span x-text="kgb_h14"></span></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                        <dt class="text-muted">Pensiun (BUP) Tahap 1 / 2 / 3</dt>
                        <dd class="font-semibold text-ink">
                            <span x-text="pensiun_y1"></span>h / <span x-text="pensiun_m6"></span>h / <span
                                x-text="pensiun_m3"></span>h
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                        <dt class="text-muted">PPPK Kontrak Tahap 1 / 2 / 3</dt>
                        <dd class="font-semibold text-ink">
                            <span x-text="pppk_m6"></span>h / <span x-text="pppk_m3"></span>h / <span
                                x-text="pppk_m1"></span>h
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                        <dt class="text-muted">Satyalancana Tahap 1 / 2 / 3</dt>
                        <dd class="font-semibold text-ink">
                            <span x-text="satyalancana_h180"></span>h / <span x-text="satyalancana_h90"></span>h / <span
                                x-text="satyalancana_h30"></span>h
                        </dd>
                    </div>
                    <div class="pt-2 mt-2">
                        <dt class="font-bold text-muted uppercase tracking-wide text-[10px]">Alasan Perubahan</dt>
                        <dd class="mt-1 text-ink leading-relaxed italic" x-text="reason"></dd>
                    </div>
                </dl>
            </div>
        </x-ui.confirm-dialog>


    </div>
</x-layouts.app>
