<x-layouts.app title="Konfigurasi EWS">
    @php
        $badgeClass = [
            'Read-only'    => 'bg-info/10 text-info',
            'Configurable' => 'bg-primary/10 text-primary',
            'Aktif'        => 'bg-success/10 text-success',
        ];
    @endphp

    {{-- ================================================================ --}}
    {{-- Alpine.js state --}}
    {{-- ================================================================ --}}
    <div
        class="space-y-6"
        x-data="{
            configs: {{ json_encode($configs) }},
            reason: '',
            showConfirm: false,

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

            openConfirm() {
                if (this.reason.trim() === '') return;
                this.showConfirm = true;
            },
            submitForm() {
                this.$refs.configForm.submit();
            }
        }"
    >

        {{-- ================================================================ --}}
        {{-- PAGE HEADER --}}
        {{-- ================================================================ --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="text-muted">EWS</span>
                    <span>/</span>
                    <span class="font-medium text-ink">Konfigurasi</span>
                </nav>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi Early Warning System (EWS)</h2>
                <p class="mt-1.5 max-w-2xl text-sm text-muted">
                    Kelola jam eksekusi scheduler otomatis harian dan ambang batas (threshold) hari peringatan EWS untuk kenaikan pangkat, KGB, pensiun, dan PPPK.
                </p>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- VISUAL THRESHOLDS TIMELINE --}}
        {{-- ================================================================ --}}
        <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-base font-semibold text-ink">Visualisasi Interval Peringatan EWS</h3>
                <p class="mt-0.5 text-sm text-muted">Preview rentang waktu peringatan (tahap hijau, kuning, merah) yang aktif saat ini.</p>
            </div>
            <div class="p-6 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    {{-- Pangkat --}}
                    <div class="space-y-2 p-4 rounded-lg bg-soft/50 border border-border">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">Kenaikan Pangkat (PP 99/2000)</span>
                            <span class="text-xs text-muted">TMT + 4 Tahun</span>
                        </div>
                        <div class="h-3 w-full rounded-full bg-border overflow-hidden flex">
                            <div class="bg-success h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 40%">H-<span x-text="pangkat_h90"></span></div>
                            <div class="bg-warning h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 30%">H-<span x-text="pangkat_h60"></span></div>
                            <div class="bg-danger h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 30%">H-<span x-text="pangkat_h30"></span></div>
                        </div>
                        <div class="flex justify-between text-[10px] text-muted font-medium pt-1">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success"></span> Tahap 1: H-<span x-text="pangkat_h90"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-warning"></span> Tahap 2: H-<span x-text="pangkat_h60"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-danger"></span> Tahap 3: H-<span x-text="pangkat_h30"></span> Hari</span>
                        </div>
                    </div>

                    {{-- KGB --}}
                    <div class="space-y-2 p-4 rounded-lg bg-soft/50 border border-border">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">Kenaikan Gaji Berkala (KGB)</span>
                            <span class="text-xs text-muted">TMT + 2 Tahun</span>
                        </div>
                        <div class="h-3 w-full rounded-full bg-border overflow-hidden flex">
                            <div class="bg-success h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 40%">H-<span x-text="kgb_h60"></span></div>
                            <div class="bg-warning h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 35%">H-<span x-text="kgb_h30"></span></div>
                            <div class="bg-danger h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 25%">H-<span x-text="kgb_h14"></span></div>
                        </div>
                        <div class="flex justify-between text-[10px] text-muted font-medium pt-1">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success"></span> Tahap 1: H-<span x-text="kgb_h60"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-warning"></span> Tahap 2: H-<span x-text="kgb_h30"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-danger"></span> Tahap 3: H-<span x-text="kgb_h14"></span> Hari</span>
                        </div>
                    </div>

                    {{-- Pensiun --}}
                    <div class="space-y-2 p-4 rounded-lg bg-soft/50 border border-border">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">Batas Usia Pensiun (BUP)</span>
                            <span class="text-xs text-muted">Lahir + Usia BUP</span>
                        </div>
                        <div class="h-3 w-full rounded-full bg-border overflow-hidden flex">
                            <div class="bg-success h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 45%">H-<span x-text="pensiun_y1"></span></div>
                            <div class="bg-warning h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 30%">H-<span x-text="pensiun_m6"></span></div>
                            <div class="bg-danger h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 25%">H-<span x-text="pensiun_m3"></span></div>
                        </div>
                        <div class="flex justify-between text-[10px] text-muted font-medium pt-1">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success"></span> Tahap 1: H-<span x-text="pensiun_y1"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-warning"></span> Tahap 2: H-<span x-text="pensiun_m6"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-danger"></span> Tahap 3: H-<span x-text="pensiun_m3"></span> Hari</span>
                        </div>
                    </div>

                    {{-- PPPK --}}
                    <div class="space-y-2 p-4 rounded-lg bg-soft/50 border border-border">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">Kontrak PPPK Berakhir</span>
                            <span class="text-xs text-muted">Akhir Kontrak</span>
                        </div>
                        <div class="h-3 w-full rounded-full bg-border overflow-hidden flex">
                            <div class="bg-success h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 45%">H-<span x-text="pppk_m6"></span></div>
                            <div class="bg-warning h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 30%">H-<span x-text="pppk_m3"></span></div>
                            <div class="bg-danger h-full flex items-center justify-center text-[8px] text-white font-bold" style="width: 25%">H-<span x-text="pppk_m1"></span></div>
                        </div>
                        <div class="flex justify-between text-[10px] text-muted font-medium pt-1">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success"></span> Tahap 1: H-<span x-text="pppk_m6"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-warning"></span> Tahap 2: H-<span x-text="pppk_m3"></span> Hari</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-danger"></span> Tahap 3: H-<span x-text="pppk_m1"></span> Hari</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- MAIN CONTENT --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">

            {{-- ============================================================ --}}
            {{-- LEFT — Audit Perubahan --}}
            {{-- ============================================================ --}}
            <div class="space-y-6">

                {{-- Audit Trail --}}
                <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                    <div class="border-b border-border px-6 py-4">
                        <h3 class="text-base font-semibold text-ink">Audit Perubahan Konfigurasi EWS</h3>
                        <p class="mt-0.5 text-sm text-muted">Riwayat perubahan ambang batas dan waktu scheduler EWS.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-soft">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Waktu</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Actor</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Parameter</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Sebelum</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Sesudah</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($auditRows as $row)
                                    <tr class="transition-colors hover:bg-soft/60">
                                        <td class="px-4 py-3.5 text-xs text-muted">{{ $row['time'] }}</td>
                                        <td class="px-4 py-3.5 text-sm font-semibold text-ink">{{ $row['actor'] }}</td>
                                        <td class="px-4 py-3.5 text-sm text-ink">{{ $row['field'] }}</td>
                                        <td class="px-4 py-3.5 text-sm text-muted">{{ $row['before'] }}</td>
                                        <td class="px-4 py-3.5 text-sm font-semibold text-ink">{{ $row['after'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">
                                            Belum ada perubahan konfigurasi tercatat.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- RIGHT — Form Edit Konfigurasi + Ketentuan --}}
            {{-- ============================================================ --}}
            <div class="space-y-6">

                {{-- Form Edit Konfigurasi --}}
                <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-ink">Edit Parameter EWS</h3>
                    <p class="mt-1 text-sm text-muted">Perubahan langsung berlaku pada scheduler berikutnya.</p>

                    <form method="POST" action="{{ route('ews.config.update') }}" x-ref="configForm">
                        @csrf
                        <div class="mt-5 space-y-6">

                            {{-- Scheduler Time --}}
                            <div class="space-y-1.5 p-3 rounded-lg bg-soft/30 border border-border">
                                <label class="text-sm font-semibold text-ink" for="cfg-scheduler-time">
                                    Waktu Scheduler Harian (WITA)
                                </label>
                                <p class="text-[11px] text-muted">Pemeriksaan otomatis berjalan di jam ini setiap hari.</p>
                                <input
                                    type="time"
                                    id="cfg-scheduler-time"
                                    name="ews_scheduler_time"
                                    x-model="ews_scheduler_time"
                                    class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"
                                >
                                @error('ews_scheduler_time')
                                    <p class="text-xs text-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Pangkat Thresholds --}}
                            <div class="space-y-3 p-3 rounded-lg bg-soft/30 border border-border">
                                <span class="text-sm font-semibold text-ink block border-b border-border pb-1">Kenaikan Pangkat (Hari)</span>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 1</label>
                                        <input type="number" name="pangkat_h90" x-model="pangkat_h90" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 2</label>
                                        <input type="number" name="pangkat_h60" x-model="pangkat_h60" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 3</label>
                                        <input type="number" name="pangkat_h30" x-model="pangkat_h30" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                </div>
                            </div>

                            {{-- KGB Thresholds --}}
                            <div class="space-y-3 p-3 rounded-lg bg-soft/30 border border-border">
                                <span class="text-sm font-semibold text-ink block border-b border-border pb-1">Gaji Berkala (KGB) (Hari)</span>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 1</label>
                                        <input type="number" name="kgb_h60" x-model="kgb_h60" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 2</label>
                                        <input type="number" name="kgb_h30" x-model="kgb_h30" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 3</label>
                                        <input type="number" name="kgb_h14" x-model="kgb_h14" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                </div>
                            </div>

                            {{-- Pensiun Thresholds --}}
                            <div class="space-y-3 p-3 rounded-lg bg-soft/30 border border-border">
                                <span class="text-sm font-semibold text-ink block border-b border-border pb-1">Pensiun BUP (Hari)</span>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 1</label>
                                        <input type="number" name="pensiun_y1" x-model="pensiun_y1" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 2</label>
                                        <input type="number" name="pensiun_m6" x-model="pensiun_m6" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 3</label>
                                        <input type="number" name="pensiun_m3" x-model="pensiun_m3" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                </div>
                            </div>

                            {{-- PPPK Thresholds --}}
                            <div class="space-y-3 p-3 rounded-lg bg-soft/30 border border-border">
                                <span class="text-sm font-semibold text-ink block border-b border-border pb-1">Kontrak PPPK (Hari)</span>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 1</label>
                                        <input type="number" name="pppk_m6" x-model="pppk_m6" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 2</label>
                                        <input type="number" name="pppk_m3" x-model="pppk_m3" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-muted font-medium">Tahap 3</label>
                                        <input type="number" name="pppk_m1" x-model="pppk_m1" class="w-full text-center rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                    </div>
                                </div>
                            </div>

                            {{-- Alasan --}}
                            <div class="space-y-1.5">
                                <label class="text-sm font-semibold text-ink" for="cfg-reason">
                                    Alasan Perubahan <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    id="cfg-reason"
                                    name="reason"
                                    x-model="reason"
                                    rows="4"
                                    placeholder="Contoh: penyesuaian durasi waktu persiapan pemberkasan pensiun pegawai"
                                    class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                                ></textarea>
                                @error('reason')
                                    <p class="text-xs text-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{-- Tombol simpan --}}
                        <button
                            type="button"
                            @click="openConfirm()"
                            class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 active:opacity-80 disabled:cursor-not-allowed disabled:opacity-50 font-sans cursor-pointer"
                            :disabled="reason.trim() === ''"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            Simpan Parameter
                        </button>
                    </form>
                    <p class="mt-2 text-center text-xs text-muted">Akan muncul konfirmasi sebelum perubahan diterapkan.</p>
                </div>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- CONFIRMATION MODAL --}}
        {{-- ================================================================ --}}
        <div
            x-show="showConfirm"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4"
            style="display: none;"
        >
            <div
                x-show="showConfirm"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                @click.outside="showConfirm = false"
                class="w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl"
            >
                {{-- Modal header --}}
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-warning/10">
                        <svg class="h-5 w-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-ink">Konfirmasi Perubahan Parameter EWS</h3>
                        <p class="mt-1 text-sm text-muted">
                            Perubahan ini langsung berlaku untuk scheduler pengecekan EWS harian dan dicatat di log audit.
                        </p>
                    </div>
                </div>

                {{-- Ringkasan perubahan --}}
                <div class="mt-5 rounded-lg border border-border bg-soft p-4 max-h-[250px] overflow-y-auto">
                    <p class="text-xs font-bold uppercase tracking-wide text-muted">Ringkasan Parameter Baru</p>
                    <dl class="mt-3 space-y-2 text-xs">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">Scheduler Time (WITA)</dt>
                            <dd class="font-semibold text-ink" x-text="ews_scheduler_time"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">Pangkat H-90 / H-60 / H-30</dt>
                            <dd class="font-semibold text-ink"><span x-text="pangkat_h90"></span> / <span x-text="pangkat_h60"></span> / <span x-text="pangkat_h30"></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">KGB H-60 / H-30 / H-14</dt>
                            <dd class="font-semibold text-ink"><span x-text="kgb_h60"></span> / <span x-text="kgb_h30"></span> / <span x-text="kgb_h14"></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">Pensiun H-1th / H-6bln / H-3bln</dt>
                            <dd class="font-semibold text-ink"><span x-text="pensiun_y1"></span> / <span x-text="pensiun_m6"></span> / <span x-text="pensiun_m3"></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">PPPK H-6bln / H-3bln / H-1bln</dt>
                            <dd class="font-semibold text-ink"><span x-text="pppk_m6"></span> / <span x-text="pppk_m3"></span> / <span x-text="pppk_m1"></span></dd>
                        </div>
                        <div class="border-t border-border pt-2 mt-2">
                            <dt class="font-semibold text-muted">Alasan Perubahan</dt>
                            <dd class="mt-1 text-ink" x-text="reason"></dd>
                        </div>
                    </dl>
                </div>

                {{-- Aksi modal --}}
                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        @click="showConfirm = false"
                        class="rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-muted transition-colors hover:bg-soft cursor-pointer focus:outline-none"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitForm()"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 cursor-pointer focus:outline-none"
                    >
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
