import Chart from 'chart.js/auto';

const TONE_KEYS = ['primary', 'secondary', 'info', 'success', 'warning', 'orange', 'danger', 'muted'];

const token = (name) => window
    .getComputedStyle(document.documentElement)
    .getPropertyValue(`--color-${name}`)
    .trim();

const chartTheme = () => {
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

const colorFor = (row, index, theme) => theme.tones[row.tone] || theme.palette[index % theme.palette.length];

const registerEmployeeStatistics = () => {
    window.Alpine.data('employeeStatisticsPage', (initialData = {}) => ({
        dimensions: initialData.dimensions || {},
        totalEmployees: initialData.total || 0,
        summary: initialData.summary || {},
        activeViews: {
            jenis_pegawai: 'chart',
            jenis_kelamin: 'chart',
            golongan: 'chart',
            pendidikan: 'chart',
            jenis_jabatan: 'chart',
            jabatan: 'chart',
            unit_kerja: 'chart',
            status_pegawai: 'chart',
        },
        charts: {},

        init() {
            this.$nextTick(() => {
                this.applyProgressWidths();
                this.initAllCharts();
            });
        },

        destroy() {
            Object.values(this.charts).forEach((chart) => chart.destroy());
            this.charts = {};
        },

        applyProgressWidths() {
            this.$el.querySelectorAll('[data-statistics-progress]').forEach((element) => {
                const width = Number(element.dataset.statisticsProgress);

                element.style.width = `${Number.isFinite(width) ? Math.max(0, Math.min(width, 100)) : 0}%`;
            });
        },

        toggleView(dimensionKey, view) {
            this.activeViews[dimensionKey] = view;
            if (view === 'chart') {
                this.$nextTick(() => {
                    this.renderChart(dimensionKey);
                });
            }
        },

        initAllCharts() {
            Object.keys(this.dimensions).forEach((key) => {
                this.renderChart(key);
            });
        },

        renderChart(key) {
            const canvas = document.getElementById(`chart-${key}`);
            if (!canvas) return;

            const rows = this.dimensions[key] || [];
            if (rows.length === 0) return;

            // Destroy previous instance if exists
            if (this.charts[key]) {
                this.charts[key].destroy();
                delete this.charts[key];
            }

            const labels = rows.map((r) => r.label);
            const data = rows.map((r) => r.total);
            const totalSum = data.reduce((a, b) => a + b, 0);
            const theme = chartTheme();

            const isDonut = ['jenis_pegawai', 'jenis_kelamin', 'status_pegawai'].includes(key);
            const isHorizontal = ['jabatan', 'unit_kerja', 'jenis_jabatan'].includes(key);

            const backgroundColors = rows.map((row, index) => colorFor(row, index, theme));

            const ctx = canvas.getContext('2d');

            if (isDonut) {
                this.charts[key] = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels,
                        datasets: [
                            {
                                data,
                                backgroundColor: backgroundColors,
                                borderWidth: 2,
                                borderColor: theme.surface,
                                hoverOffset: 6,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: {
                                display: false, // We render clean, custom accessible legends
                            },
                            tooltip: {
                                backgroundColor: theme.ink,
                                titleFont: { family: 'Poppins', size: 13, weight: '600' },
                                bodyFont: { family: 'Poppins', size: 12 },
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label(context) {
                                        const val = context.parsed;
                                        const pct = totalSum > 0 ? ((val / totalSum) * 100).toFixed(1) : 0;
                                        return ` ${val} pegawai (${pct}%)`;
                                    },
                                },
                            },
                        },
                    },
                });
            } else {
                this.charts[key] = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            {
                                data,
                                backgroundColor: isHorizontal ? backgroundColors : theme.tones.primary,
                                borderRadius: 6,
                                maxBarThickness: 32,
                            },
                        ],
                    },
                    options: {
                        indexAxis: isHorizontal ? 'y' : 'x',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: theme.ink,
                                titleFont: { family: 'Poppins', size: 13, weight: '600' },
                                bodyFont: { family: 'Poppins', size: 12 },
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label(context) {
                                        const val = isHorizontal ? context.parsed.x : context.parsed.y;
                                        const pct = totalSum > 0 ? ((val / totalSum) * 100).toFixed(1) : 0;
                                        return ` ${val} pegawai (${pct}%)`;
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { color: theme.border },
                                ticks: {
                                    font: { family: 'Poppins', size: 11 },
                                    color: theme.muted,
                                    precision: 0,
                                },
                            },
                            y: {
                                grid: { color: theme.border },
                                ticks: {
                                    font: { family: 'Poppins', size: 11 },
                                    color: theme.muted,
                                    precision: 0,
                                },
                            },
                        },
                    },
                });
            }

            canvas.dataset.statisticsChartReady = 'true';
        },
    }));
};

if (window.Alpine) {
    registerEmployeeStatistics();
} else {
    document.addEventListener('alpine:init', registerEmployeeStatistics);
}
