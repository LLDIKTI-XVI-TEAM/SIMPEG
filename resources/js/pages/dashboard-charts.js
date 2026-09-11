import { createDashboardChart } from '../charts/simpeg-chart.js';

const registerDashboardCharts = () => {
    window.Alpine.data('dashboardChart', (config = {}) => ({
        chart: null,

        init() {
            this.$nextTick(() => {
                this.chart = createDashboardChart(this.$refs.canvas, config);
                if (this.chart) this.$refs.canvas.dataset.dashboardChartReady = 'true';
            });
        },

        destroy() {
            this.chart?.destroy();
            this.chart = null;
        },
    }));
};

if (window.Alpine) {
    registerDashboardCharts();
} else {
    document.addEventListener('alpine:init', registerDashboardCharts);
}
