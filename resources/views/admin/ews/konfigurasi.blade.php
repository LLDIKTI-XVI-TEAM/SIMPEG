<x-layouts.app title="Konfigurasi EWS">
    @php
        $badgeClass = [
            'Read-only' => 'bg-info/10 text-info',
            'Configurable' => 'bg-primary/10 text-primary',
            'Aktif' => 'bg-success/10 text-success',
        ];

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
            reason: '',
            showConfirm: false,
            expandedAudit: null,

            // Visual Helpers
            pangkat_h90: '{{ $configs['pangkat_h90'] }}',
            pangkat_h60: '{{ $configs['pangkat_h60'] }}',
            pangkat_h30: '{{ $configs['pangkat_h30'] }}',

            kgb_h60: '{{ $configs['kgb_h60'] }}',
            kgb_h30: '{{ $configs['kgb_h30'] }}',
            kgb_h14: '{{ $configs['kgb_h14'] }}',

            pensiun_y1: '{{ $configs['pensiun_y1'] }}',
            pensiun_m6: '{{ $configs['pensiun_m6'] }}',
            pensiun_m3: '{{ $configs['pensiun_m3'] }}',

            pppk_m6: '{{ $configs['pppk_m6'] }}',
            pppk_m3: '{{ $configs['pppk_m3'] }}',
            pppk_m1: '{{ $configs['pppk_m1'] }}',

            ews_scheduler_time: '{{ $configs['ews_scheduler_time'] }}',

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

            openConfirm() {
                if (this.reason.trim() === '') return;
                if (this.thresholdWarnings.length > 0) {
                    if (!confirm('Terdapat peringatan urutan threshold:\n\n' + this.thresholdWarnings.join('\n') + '\n\nLanjutkan menyimpan?')) return;
                }
                this.showConfirm = true;
            },
            submitForm() {
                this.$refs.configForm.submit();
            }
        }">

        {{-- ================================================================ --}}
        {{-- PAGE HEADER --}}
        {{-- ================================================================ --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi Early Warning System (EWS)</h2>
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="text-muted">EWS</span>
                    <span>/</span>
                    <span class="font-medium text-ink">Konfigurasi</span>
                </nav>
            </div>
            {{-- Shortcut link to EWS Aktif --}}
            <a href="{{ route('ews') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                Lihat EWS Aktif
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>


        {{-- ================================================================ --}}
        {{-- E1: SCHEDULER STATUS BAR (Layout E Horizontal Style) --}}
        {{-- ================================================================ --}}
        @php
            // Simulated scheduler status data
            $schedulerStatus = [
                'last_run' => '23 Jun 2026, 07:00 WITA',
                'next_run' => '24 Jun 2026, ' . $configs['ews_scheduler_time'] . ' WITA',
                'status' => 'success', // success | failed | pending
                'alerts_created' => 12,
                'last_duration' => '2.4 detik',
                'pegawai_checked' => 48,
            ];
        @endphp
        <div
            class="rounded-lg border border-border bg-surface px-5 py-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div>
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider block">Status
                        Scheduler</span>
                    <p class="text-sm font-semibold text-ink">Aktif & Berjalan Normal</p>
                </div>
            </div>

            <div class="h-px md:h-8 w-full md:w-px bg-border"></div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 flex-1 max-w-2xl">
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Waktu Harian</span>
                    <span class="text-xs font-mono font-semibold text-ink"><span x-text="ews_scheduler_time"></span>
                        WITA</span>
                </div>
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Terakhir Jalan</span>
                    <span class="text-xs font-semibold text-ink">{{ $schedulerStatus['last_run'] }}</span>
                </div>
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Total Pegawai</span>
                    <span class="text-xs font-semibold text-ink">{{ $schedulerStatus['pegawai_checked'] }}
                        checked</span>
                </div>
                <div>
                    <span class="text-[9px] font-bold text-muted uppercase tracking-wider block">Peringatan</span>
                    <span class="text-xs font-bold text-warning">{{ $schedulerStatus['alerts_created'] }} alerts</span>
                </div>
            </div>
        </div>

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
                        class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-0.5 text-[10px] font-semibold text-success border border-success/15">
                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                        Status: Configurable
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

                {{-- Row 1: Scheduler Time --}}
                <div
                    class="px-5 py-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 hover:bg-soft/10 transition-colors">
                    <div class="max-w-md">
                        <label class="text-sm font-semibold text-ink block" for="cfg-scheduler-time">Waktu Scheduler
                            Harian (WITA)</label>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Pemeriksaan otomatis berjalan di jam ini
                            setiap hari untuk memperbarui peringatan kepegawaian.</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <input type="time" id="cfg-scheduler-time" name="ews_scheduler_time"
                            x-model="ews_scheduler_time"
                            class="w-40 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono font-semibold">
                        @error('ews_scheduler_time')
                            <p class="text-xs text-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Row 2: Kenaikan Pangkat --}}
                <div
                    class="px-5 py-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 hover:bg-soft/10 transition-colors">
                    <div class="max-w-md">
                        <span class="text-sm font-semibold text-ink block">Kenaikan Pangkat</span>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Peringatan periodik menjelang kenaikan
                            pangkat berkala pegawai berdasarkan PP 99/2000 (TMT + 4 Tahun).</p>
                    </div>

                    <div class="flex items-center gap-6 flex-wrap">
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-success uppercase tracking-wider block">Tahap
                                1</span>
                            <input type="number" name="pangkat_h90" x-model="pangkat_h90"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-success block mt-1"
                                x-text="humanLabel(pangkat_h90)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-warning uppercase tracking-wider block">Tahap
                                2</span>
                            <input type="number" name="pangkat_h60" x-model="pangkat_h60"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-warning block mt-1"
                                x-text="humanLabel(pangkat_h60)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-danger uppercase tracking-wider block">Tahap 3</span>
                            <input type="number" name="pangkat_h30" x-model="pangkat_h30"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-danger block mt-1"
                                x-text="humanLabel(pangkat_h30)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 3: KGB --}}
                <div
                    class="px-5 py-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 hover:bg-soft/10 transition-colors">
                    <div class="max-w-md">
                        <span class="text-sm font-semibold text-ink block">Kenaikan Gaji Berkala (KGB)</span>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Peringatan periodik menjelang kenaikan gaji
                            berkala (KGB) pegawai (TMT + 2 Tahun).</p>
                    </div>

                    <div class="flex items-center gap-6 flex-wrap">
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-success uppercase tracking-wider block">Tahap
                                1</span>
                            <input type="number" name="kgb_h60" x-model="kgb_h60"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-success block mt-1"
                                x-text="humanLabel(kgb_h60)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-warning uppercase tracking-wider block">Tahap
                                2</span>
                            <input type="number" name="kgb_h30" x-model="kgb_h30"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-warning block mt-1"
                                x-text="humanLabel(kgb_h30)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-danger uppercase tracking-wider block">Tahap 3</span>
                            <input type="number" name="kgb_h14" x-model="kgb_h14"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-danger block mt-1"
                                x-text="humanLabel(kgb_h14)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 4: Pensiun --}}
                <div
                    class="px-5 py-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 hover:bg-soft/10 transition-colors">
                    <div class="max-w-md">
                        <span class="text-sm font-semibold text-ink block">Batas Usia Pensiun (BUP)</span>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Peringatan pensiun berdasarkan tanggal lahir
                            ditambah usia wajib pensiun pegawai.</p>
                    </div>

                    <div class="flex items-center gap-6 flex-wrap">
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-success uppercase tracking-wider block">Tahap
                                1</span>
                            <input type="number" name="pensiun_y1" x-model="pensiun_y1"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-success block mt-1"
                                x-text="humanLabel(pensiun_y1)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-warning uppercase tracking-wider block">Tahap
                                2</span>
                            <input type="number" name="pensiun_m6" x-model="pensiun_m6"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-warning block mt-1"
                                x-text="humanLabel(pensiun_m6)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-danger uppercase tracking-wider block">Tahap 3</span>
                            <input type="number" name="pensiun_m3" x-model="pensiun_m3"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-danger block mt-1"
                                x-text="humanLabel(pensiun_m3)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 5: PPPK --}}
                <div
                    class="px-5 py-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 hover:bg-soft/10 transition-colors">
                    <div class="max-w-md">
                        <span class="text-sm font-semibold text-ink block">Kontrak PPPK</span>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Peringatan periodik menjelang kedaluwarsa
                            masa penugasan kontrak PPPK pegawai.</p>
                    </div>

                    <div class="flex items-center gap-6 flex-wrap">
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-success uppercase tracking-wider block">Tahap
                                1</span>
                            <input type="number" name="pppk_m6" x-model="pppk_m6"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-success block mt-1"
                                x-text="humanLabel(pppk_m6)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-warning uppercase tracking-wider block">Tahap
                                2</span>
                            <input type="number" name="pppk_m3" x-model="pppk_m3"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-warning block mt-1"
                                x-text="humanLabel(pppk_m3)"></span>
                        </div>
                        <div class="h-10 w-px bg-border hidden sm:block"></div>
                        <div class="text-center min-w-[90px]">
                            <span class="text-[9px] font-bold text-danger uppercase tracking-wider block">Tahap 3</span>
                            <input type="number" name="pppk_m1" x-model="pppk_m1"
                                class="w-24 text-center mt-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                            <span class="text-[9px] font-semibold text-danger block mt-1"
                                x-text="humanLabel(pppk_m1)"></span>
                        </div>
                    </div>
                </div>

                {{-- Row 6: Reason input & Action Button --}}
                <div
                    class="px-5 py-5 flex flex-col md:flex-row md:items-start justify-between gap-6 hover:bg-soft/5 transition-colors">
                    <div class="max-w-md">
                        <label class="text-sm font-semibold text-ink block" for="cfg-reason">Alasan Perubahan <span
                                class="text-danger">*</span></label>
                        <p class="text-xs text-muted mt-1 leading-relaxed">Sebutkan alasan atau dasar hukum perubahan
                            nilai ambang batas ini untuk dicatat dalam log audit kepegawaian secara lengkap.</p>
                    </div>
                    <div class="flex-1 max-w-xl space-y-3">
                        <textarea id="cfg-reason" name="reason" x-model="reason" rows="3"
                            placeholder="Contoh: Penyesuaian masa tenggang berkas usul pensiun dan kenaikan pangkat untuk semester gasal"
                            class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                        @error('reason')
                            <p class="text-xs text-danger mt-1">{{ $message }}</p>
                        @enderror

                        <button type="button" @click="openConfirm()"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 active:opacity-80 disabled:cursor-not-allowed disabled:opacity-50 font-sans cursor-pointer"
                            :disabled="reason.trim() === ''">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            Simpan Konfigurasi EWS
                        </button>
                    </div>
                </div>
            </form>
        </div>

        {{-- ================================================================ --}}
        {{-- E3: TIDY AUDIT LOG TABLE (Expandable Table style) --}}
        {{-- ================================================================ --}}
        <div class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-soft/30 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-ink uppercase tracking-wider">Log Perubahan Konfigurasi</h3>
                    <p class="mt-0.5 text-xs text-muted">Riwayat lengkap perubahan parameter EWS. Klik baris untuk
                        detail IP, device, dan nilai lama/baru.</p>
                </div>
                <span
                    class="text-[10px] font-semibold text-muted bg-soft px-2.5 py-1 rounded-full border border-border">{{ count($auditRows) }}
                    entri</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-soft border-b border-border">
                            <th class="px-5 py-3 font-semibold text-muted w-8"></th>
                            <th class="px-5 py-3 font-semibold text-muted">Waktu</th>
                            <th class="px-5 py-3 font-semibold text-muted">Pengguna</th>
                            <th class="px-5 py-3 font-semibold text-muted">Parameter</th>
                            <th class="px-5 py-3 font-semibold text-muted text-right">Nilai Lama</th>
                            <th class="px-3 py-3 text-center text-muted"></th>
                            <th class="px-5 py-3 font-semibold text-muted">Nilai Baru</th>
                            <th class="px-5 py-3 font-semibold text-muted">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($auditRows as $idx => $row)
                            {{-- Main row --}}
                            <tr class="hover:bg-soft/30 transition-colors cursor-pointer"
                                @click="expandedAudit = expandedAudit === {{ $idx }} ? null : {{ $idx }}">
                                <td class="px-5 py-3.5 text-center">
                                    <svg class="w-3.5 h-3.5 text-muted transition-transform duration-200"
                                        :class="expandedAudit === {{ $idx }} ? 'rotate-90' : ''" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                </td>
                                <td class="px-5 py-3.5 text-muted whitespace-nowrap">{{ $row['time'] }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="font-semibold text-ink block">{{ $row['actor'] }}</span>
                                    <span class="text-[9px] text-muted block font-mono">{{ $row['ip_address'] }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-ink font-medium">{{ $row['field'] }}</td>
                                <td class="px-5 py-3.5 text-right font-mono text-muted">{{ $row['before'] }}</td>
                                <td class="px-3 py-3.5 text-center text-muted">→</td>
                                <td class="px-5 py-3.5 font-mono font-bold text-success">{{ $row['after'] }}</td>
                                <td class="px-5 py-3.5 text-muted italic max-w-[200px] truncate"
                                    title="{{ $row['reason'] }}">{{ $row['reason'] }}</td>
                            </tr>

                            {{-- Detail row (expanded) --}}
                            <tr x-show="expandedAudit === {{ $idx }}" x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                style="display: none;">
                                <td colspan="8" class="bg-soft/30 px-8 py-4 border-t border-border">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 text-xs">
                                        {{-- Event --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Event
                                                Type</span>
                                            <p class="text-sm font-semibold text-ink font-mono">
                                                {{ $row['event'] ?? 'UPDATE_EWS_CONFIG' }}
                                            </p>
                                        </div>
                                        {{-- IP Address --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">IP
                                                Address</span>
                                            <p class="text-sm text-ink font-mono">{{ $row['ip_address'] ?? '127.0.0.1' }}
                                            </p>
                                        </div>
                                        {{-- User Agent --}}
                                        <div class="space-y-1 sm:col-span-2">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">User
                                                Agent</span>
                                            <p class="text-xs text-muted truncate font-mono"
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
                                                    class="text-xs text-danger font-mono">{{ json_encode(['value' => $row['before']], JSON_PRETTY_PRINT) }}</code>
                                            </div>
                                        </div>
                                        {{-- New Values --}}
                                        <div class="space-y-1">
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted">Nilai
                                                Baru (JSON)</span>
                                            <div class="rounded-md border border-success/20 bg-success/5 px-3 py-2">
                                                <code
                                                    class="text-xs text-success font-mono">{{ json_encode(['value' => $row['after']], JSON_PRETTY_PRINT) }}</code>
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-sm text-muted">
                                    <div class="flex flex-col items-center gap-2">
                                        <svg class="w-8 h-8 text-muted/40" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" stroke-width="1">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                        Belum ada perubahan konfigurasi tercatat.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- CONFIRMATION MODAL --}}
        {{-- ================================================================ --}}
        <div x-show="showConfirm" x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4" style="display: none;">
            <div x-show="showConfirm" x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                @click.outside="showConfirm = false"
                class="w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl">
                {{-- Modal header --}}
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-warning/10">
                        <svg class="h-5 w-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-ink">Konfirmasi Perubahan Parameter EWS</h3>
                        <p class="mt-1 text-sm text-muted">
                            Perubahan ini langsung berlaku untuk scheduler pengecekan EWS harian dan dicatat di log
                            audit.
                        </p>
                    </div>
                </div>

                {{-- Ringkasan perubahan --}}
                <div class="mt-5 rounded-lg border border-border bg-soft p-4 max-h-[250px] overflow-y-auto">
                    <p class="text-xs font-bold uppercase tracking-wide text-muted">Ringkasan Parameter Baru</p>
                    <dl class="mt-3 space-y-2 text-xs font-sans">
                        <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                            <dt class="text-muted">Scheduler Time (WITA)</dt>
                            <dd class="font-semibold text-ink font-mono" x-text="ews_scheduler_time"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                            <dt class="text-muted">Pangkat Tahap 1 / 2 / 3</dt>
                            <dd class="font-semibold text-ink font-mono"><span x-text="pangkat_h90"></span> / <span
                                    x-text="pangkat_h60"></span> / <span x-text="pangkat_h30"></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                            <dt class="text-muted">KGB Tahap 1 / 2 / 3</dt>
                            <dd class="font-semibold text-ink font-mono"><span x-text="kgb_h60"></span> / <span
                                    x-text="kgb_h30"></span> / <span x-text="kgb_h14"></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                            <dt class="text-muted">Pensiun (BUP) Tahap 1 / 2 / 3</dt>
                            <dd class="font-semibold text-ink font-mono">
                                <span x-text="pensiun_y1"></span>h / <span x-text="pensiun_m6"></span>h / <span
                                    x-text="pensiun_m3"></span>h
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-border pb-1.5">
                            <dt class="text-muted">PPPK Kontrak Tahap 1 / 2 / 3</dt>
                            <dd class="font-semibold text-ink font-mono">
                                <span x-text="pppk_m6"></span>h / <span x-text="pppk_m3"></span>h / <span
                                    x-text="pppk_m1"></span>h
                            </dd>
                        </div>
                        <div class="pt-2 mt-2">
                            <dt class="font-bold text-muted uppercase tracking-wide text-[10px]">Alasan Perubahan</dt>
                            <dd class="mt-1 text-ink leading-relaxed italic" x-text="reason"></dd>
                        </div>
                    </dl>
                </div>

                {{-- Aksi modal --}}
                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" @click="showConfirm = false"
                        class="rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-muted transition-colors hover:bg-soft cursor-pointer focus:outline-none">
                        Batal
                    </button>
                    <button type="button" @click="submitForm()"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 cursor-pointer focus:outline-none">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Ya, Terapkan Perubahan
                    </button>
                </div>
            </div>
        </div>

    </div>
</x-layouts.app>