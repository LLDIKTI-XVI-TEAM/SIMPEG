import Chart from 'chart.js/auto';
import {
    doughnutTooltipContent,
    doughnutTooltipPlacement,
    tooltipLabel,
} from './tooltip-value.js';

export { Chart };

export const TONE_KEYS = ['primary', 'secondary', 'info', 'success', 'warning', 'orange', 'danger', 'muted'];

const token = (name) => window
    .getComputedStyle(document.documentElement)
    .getPropertyValue(`--color-${name}`)
    .trim();

export const chartTheme = () => {
    const tones = Object.fromEntries(TONE_KEYS.map((tone) => [tone, token(tone)]));

    return {
        tones,
        palette: TONE_KEYS.map((tone) => tones[tone]),
        surface: token('surface'),
        ink: token('ink'),
        border: token('border'),
        muted: token('muted'),
    };
};

export const colorFor = (row, index, theme) => theme.tones[row?.tone]
    || theme.palette[index % theme.palette.length];

const withAlpha = (color, opacity) => {
    const normalized = color.replace('#', '');
    const hex = normalized.length === 3
        ? normalized.split('').map((character) => character + character).join('')
        : normalized;

    if (!/^[0-9a-f]{6}$/i.test(hex)) return color;

    const value = Number.parseInt(hex, 16);
    const red = (value >> 16) & 255;
    const green = (value >> 8) & 255;
    const blue = value & 255;

    return `rgba(${red}, ${green}, ${blue}, ${opacity})`;
};

const tooltipOptions = (theme, total) => ({
    position: 'average',
    backgroundColor: theme.ink,
    titleFont: { family: 'Poppins', size: 13, weight: '600' },
    bodyFont: { family: 'Poppins', size: 12 },
    padding: 12,
    cornerRadius: 6,
    caretSize: 5,
    caretPadding: 4,
    callbacks: {
        title(contexts) {
            return contexts[0]?.label ?? '';
        },
        label(context) {
            return tooltipLabel(context, total);
        },
    },
});

const externalTooltipElement = (chart) => {
    if (chart.$simpegDoughnutTooltip) return chart.$simpegDoughnutTooltip;

    const element = document.createElement('div');
    const title = document.createElement('strong');
    const value = document.createElement('span');

    element.className = 'simpeg-doughnut-tooltip';
    element.setAttribute('aria-hidden', 'true');
    title.className = 'simpeg-doughnut-tooltip__label';
    value.className = 'simpeg-doughnut-tooltip__value';
    element.append(title, value);
    document.body.append(element);

    chart.$simpegDoughnutTooltip = element;

    return element;
};

const oppositePlacement = (placement) => ({
    top: 'bottom',
    right: 'left',
    bottom: 'top',
    left: 'right',
})[placement] ?? 'top';

const outsideTooltipPosition = (placement, anchorX, anchorY, width, height, gap = 12) => {
    if (placement === 'right') return { left: anchorX + gap, top: anchorY - (height / 2) };
    if (placement === 'left') return { left: anchorX - width - gap, top: anchorY - (height / 2) };
    if (placement === 'bottom') return { left: anchorX - (width / 2), top: anchorY + gap };

    return { left: anchorX - (width / 2), top: anchorY - height - gap };
};

const doughnutOutsideAnchor = (placement, centerX, centerY, radiusX, radiusY, angle) => {
    const arcX = centerX + (Math.cos(angle) * radiusX);
    const arcY = centerY + (Math.sin(angle) * radiusY);

    if (placement === 'right') return { x: centerX + radiusX, y: arcY };
    if (placement === 'left') return { x: centerX - radiusX, y: arcY };
    if (placement === 'bottom') return { x: arcX, y: centerY + radiusY };

    return { x: arcX, y: centerY - radiusY };
};

const doughnutExternalTooltip = ({ chart, tooltip }, total) => {
    if (typeof document === 'undefined') return;

    const element = externalTooltipElement(chart);

    if (tooltip.opacity === 0 || tooltip.dataPoints.length === 0) {
        element.classList.remove('is-visible');
        element.style.visibility = 'hidden';

        return;
    }

    const point = tooltip.dataPoints[0];
    const arc = point?.element;

    if (!arc || typeof arc.startAngle !== 'number' || typeof arc.endAngle !== 'number') return;

    const content = doughnutTooltipContent(point, total);
    element.querySelector('.simpeg-doughnut-tooltip__label').textContent = content.label;
    element.querySelector('.simpeg-doughnut-tooltip__value').textContent = content.value;
    element.classList.remove('is-visible');
    element.style.visibility = 'hidden';

    const canvasBounds = chart.canvas.getBoundingClientRect();
    const scaleX = canvasBounds.width / chart.width;
    const scaleY = canvasBounds.height / chart.height;
    const angle = (arc.startAngle + arc.endAngle) / 2;
    const centerX = canvasBounds.left + (arc.x * scaleX);
    const centerY = canvasBounds.top + (arc.y * scaleY);
    const radiusX = arc.outerRadius * scaleX;
    const radiusY = arc.outerRadius * scaleY;
    const width = element.offsetWidth;
    const height = element.offsetHeight;
    let placement = doughnutTooltipPlacement(arc.startAngle, arc.endAngle);
    let anchor = doughnutOutsideAnchor(placement, centerX, centerY, radiusX, radiusY, angle);
    let position = outsideTooltipPosition(placement, anchor.x, anchor.y, width, height);
    const margin = 8;

    const isOutsideViewport = ({ left, top }) => (
        left < margin
        || top < margin
        || (left + width) > (window.innerWidth - margin)
        || (top + height) > (window.innerHeight - margin)
    );

    if (isOutsideViewport(position)) {
        placement = oppositePlacement(placement);
        anchor = doughnutOutsideAnchor(placement, centerX, centerY, radiusX, radiusY, angle);
        position = outsideTooltipPosition(placement, anchor.x, anchor.y, width, height);
    }

    element.dataset.placement = placement;
    element.style.left = `${Math.round(Math.min(Math.max(position.left, margin), window.innerWidth - width - margin))}px`;
    element.style.top = `${Math.round(Math.min(Math.max(position.top, margin), window.innerHeight - height - margin))}px`;
    element.style.visibility = 'visible';
    element.classList.add('is-visible');
};

const doughnutTooltipPlugin = {
    id: 'simpeg-doughnut-tooltip',
    afterDestroy(chart) {
        chart.$simpegDoughnutTooltip?.remove();
        delete chart.$simpegDoughnutTooltip;
    },
};

const axisOptions = (theme) => ({
    grid: { color: theme.border },
    ticks: {
        font: { family: 'Poppins', size: 11 },
        color: theme.muted,
        precision: 0,
    },
});

export const createDashboardChart = (canvas, initialConfig = {}) => {
    const labels = Array.isArray(initialConfig.labels) ? initialConfig.labels : [];
    const data = Array.isArray(initialConfig.data)
        ? initialConfig.data.map((value) => Math.max(0, Number(value) || 0))
        : [];

    if (!canvas || labels.length === 0 || data.length === 0) return null;

    const type = initialConfig.type || 'bar';
    const theme = chartTheme();
    const dataTotal = data.reduce((sum, value) => sum + value, 0);
    const configuredTooltipTotal = Number(initialConfig.tooltipTotal);
    // Distribusi dapat menyembunyikan kategori non-standar; persentase tooltip
    // tetap memakai populasi lengkap yang dikirim oleh server.
    const total = Number.isFinite(configuredTooltipTotal) && configuredTooltipTotal >= dataTotal
        ? configuredTooltipTotal
        : dataTotal;
    const colors = labels.map((_, index) => colorFor({ tone: initialConfig.tones?.[index] }, index, theme));

    const common = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: tooltipOptions(theme, total),
        },
    };

    if (type === 'doughnut') {
        return new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: theme.surface,
                    hoverOffset: 6,
                }],
            },
            options: {
                ...common,
                cutout: '70%',
                plugins: {
                    ...common.plugins,
                    tooltip: {
                        ...tooltipOptions(theme, total),
                        enabled: false,
                        position: 'nearest',
                        external(context) {
                            doughnutExternalTooltip(context, total);
                        },
                    },
                },
            },
            plugins: [doughnutTooltipPlugin],
        });
    }

    if (type === 'line') {
        return new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data,
                    borderColor: theme.tones.primary,
                    backgroundColor: withAlpha(theme.tones.primary, 0.08),
                    fill: true,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: theme.surface,
                    pointBorderColor: theme.tones.primary,
                    pointBorderWidth: 2,
                    tension: 0.35,
                }],
            },
            options: {
                ...common,
                scales: {
                    x: axisOptions(theme),
                    y: { ...axisOptions(theme), beginAtZero: false },
                },
            },
        });
    }

    const horizontal = type === 'horizontal-bar';

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: horizontal ? colors : theme.tones.primary,
                borderRadius: 6,
                maxBarThickness: 32,
            }],
        },
        options: {
            ...common,
            indexAxis: horizontal ? 'y' : 'x',
            scales: {
                x: axisOptions(theme),
                y: axisOptions(theme),
            },
        },
    });
};
