import assert from 'node:assert/strict';
import test from 'node:test';

let dashboardChartFactory;

globalThis.window = {
    Alpine: {
        data(name, factory) {
            if (name === 'dashboardChart') dashboardChartFactory = factory;
        },
    },
};
globalThis.document = { addEventListener() {} };

await import('../../resources/js/pages/dashboard-charts.js');

test('lifecycle dashboard menghancurkan grafik ketika halaman dilepas', () => {
    const component = dashboardChartFactory();
    let destroyed = 0;
    component.chart = { destroy: () => { destroyed += 1; } };

    component.destroy();

    assert.equal(destroyed, 1);
    assert.equal(component.chart, null);
});
