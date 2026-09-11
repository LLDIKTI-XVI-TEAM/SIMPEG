const RADIAL_CHART_TYPES = new Set(['doughnut', 'pie', 'polarArea']);

/**
 * Mengambil nilai data dari context tooltip Chart.js, bukan nilai indeks kategori.
 * Bar vertikal dan line memakai sumbu Y; bar horizontal memakai sumbu X.
 */
export const tooltipDataValue = (context) => {
    const parsed = context?.parsed;

    if (typeof parsed === 'number') return parsed;

    const chartType = context?.chart?.config?.type ?? context?.chart?.type;
    if (RADIAL_CHART_TYPES.has(chartType)) {
        const value = Number(parsed);

        return Number.isFinite(value) ? value : 0;
    }

    const isHorizontalBar = chartType === 'bar'
        && context?.chart?.options?.indexAxis === 'y';
    const value = Number(isHorizontalBar ? parsed?.x : parsed?.y);

    return Number.isFinite(value) ? value : 0;
};

export const tooltipLabel = (context, total) => {
    const value = tooltipDataValue(context);

    // Snapshot tren bulanan bukan bagian-bagian dari satu populasi yang sama;
    // totalnya adalah akumulasi seluruh bulan dan tidak dapat dipakai sebagai penyebut persentase.
    const chartType = context?.chart?.config?.type ?? context?.chart?.type;
    if (chartType === 'line') return ` ${value} pegawai`;

    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';

    return ` ${value} pegawai (${percentage}%)`;
};

export const doughnutTooltipContent = (context, total) => {
    const value = tooltipDataValue(context);
    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
    const label = String(context?.label ?? '').trim();

    return {
        label: label === '' ? 'Data pegawai' : label,
        value: `${value} pegawai (${percentage}%)`,
    };
};

/**
 * Menentukan sisi luar ring berdasarkan titik tengah sebuah arc Chart.js.
 * Dengan ini tooltip PNS/PPPK tidak pernah menutupi total pada lubang donut.
 */
export const doughnutTooltipPlacement = (startAngle, endAngle) => {
    const angle = (startAngle + endAngle) / 2;
    const horizontal = Math.cos(angle);
    const vertical = Math.sin(angle);

    if (Math.abs(horizontal) >= Math.abs(vertical)) {
        return horizontal >= 0 ? 'right' : 'left';
    }

    return vertical >= 0 ? 'bottom' : 'top';
};
