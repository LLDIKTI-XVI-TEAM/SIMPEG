@props([
    'period' => null,
    'idPrefix',
    'layout' => 'stacked',
])

@php
    $isMonthly = is_string($period) && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $period) === 1;
    $isAnnual = is_string($period) && preg_match('/^\d{4}$/', $period) === 1;
    $annualValue = $isAnnual ? $period : ($isMonthly ? substr($period, 0, 4) : '');
    $monthlyValue = $isMonthly ? $period : '';
    $monthLabels = [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember',
    ];
    $activePeriodLabel = $isMonthly
        ? $monthLabels[substr($period, 5, 2)].' '.substr($period, 0, 4)
        : ($isAnnual ? 'Tahun '.$period : 'Semua periode');
    $isInline = $layout === 'inline';
@endphp

<fieldset
    x-data="{
        mode: @js($isMonthly ? 'bulan' : 'tahun'),
        annualValue: @js($annualValue),
        monthlyValue: @js($monthlyValue),
        selectedPeriod() {
            return this.mode === 'bulan' ? this.monthlyValue : this.annualValue;
        },
    }"
>
    <legend class="text-xs font-bold uppercase tracking-wider text-ink">Periode Laporan</legend>
    <p class="text-xs text-muted">Pilih periode untuk menampilkan data cuti yang sesuai.</p>
    <input type="hidden" name="periode" value="{{ $period ?? '' }}" :value="selectedPeriod()">

    <div class="mt-3 {{ $isInline ? 'grid gap-3 md:grid-cols-[auto_minmax(14rem,20rem)_minmax(0,1fr)] md:items-end' : 'space-y-3' }}">
        <div class="inline-flex rounded-xl border border-border bg-soft p-1" role="group" aria-label="Pilih mode periode laporan">
            <button
                type="button"
                @click="mode = 'tahun'"
                :aria-pressed="(mode === 'tahun').toString()"
                :class="mode === 'tahun' ? 'bg-surface text-primary shadow-sm' : 'text-muted hover:text-ink'"
                class="inline-flex min-h-9 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
                Tahunan
            </button>
            <button
                type="button"
                @click="mode = 'bulan'"
                :aria-pressed="(mode === 'bulan').toString()"
                :class="mode === 'bulan' ? 'bg-surface text-primary shadow-sm' : 'text-muted hover:text-ink'"
                class="inline-flex min-h-9 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
                Bulanan
            </button>
        </div>

        <div class="{{ $isInline ? 'max-w-none' : 'max-w-sm' }}">
            <div x-show="mode === 'tahun'">
                <label for="{{ $idPrefix }}-periode-tahun" class="text-xs font-semibold text-ink">Tahun laporan</label>
                <input
                    id="{{ $idPrefix }}-periode-tahun"
                    type="text"
                    x-model="annualValue"
                    :disabled="mode !== 'tahun'"
                    @disabled($isMonthly)
                    value="{{ $annualValue }}"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    maxlength="4"
                    placeholder="Contoh: 2026"
                    class="mt-1 min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                >
            </div>
            <div x-show="mode === 'bulan'" x-cloak>
                <label for="{{ $idPrefix }}-periode-bulan" class="text-xs font-semibold text-ink">Bulan laporan</label>
                <input
                    id="{{ $idPrefix }}-periode-bulan"
                    type="month"
                    x-model="monthlyValue"
                    :disabled="mode !== 'bulan'"
                    @disabled(!$isMonthly)
                    value="{{ $monthlyValue }}"
                    class="mt-1 min-h-11 w-full rounded-xl border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                >
            </div>
        </div>

        <div class="flex flex-col items-start gap-1 text-xs {{ $isInline ? 'md:min-h-11 md:justify-center' : '' }}">
            <span data-applied-period class="font-semibold text-primary">Aktif: {{ $activePeriodLabel }}</span>
            <span class="text-muted">Kosongkan periode untuk menampilkan seluruh data.</span>
        </div>
    </div>
</fieldset>
