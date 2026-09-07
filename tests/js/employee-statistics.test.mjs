import assert from 'node:assert/strict';
import test from 'node:test';

let statisticsPageFactory;

globalThis.window = {
    Alpine: {
        data(name, factory) {
            if (name === 'employeeStatisticsPage') statisticsPageFactory = factory;
        },
    },
    getComputedStyle: () => ({ getPropertyValue: () => '' }),
};
globalThis.document = { addEventListener() {} };

await import('../../resources/js/pages/employee-statistics.js');

test('lifecycle destroy menghancurkan semua grafik saat halaman statistik dilepas', () => {
    const page = statisticsPageFactory();
    let destroyed = 0;

    page.charts = {
        jenis_pegawai: { destroy: () => { destroyed += 1; } },
        golongan: { destroy: () => { destroyed += 1; } },
    };

    page.destroy();

    assert.equal(destroyed, 2);
    assert.deepEqual(page.charts, {});
});
