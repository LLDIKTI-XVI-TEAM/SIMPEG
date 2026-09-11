import assert from 'node:assert/strict';
import test from 'node:test';

import {
    doughnutTooltipContent,
    doughnutTooltipPlacement,
    tooltipDataValue,
    tooltipLabel,
} from '../../resources/js/charts/tooltip-value.js';

const context = (type, parsed, indexAxis = 'x', label = '') => ({
    parsed,
    label,
    chart: {
        config: { type },
        options: { indexAxis },
    },
});

test('tooltip bar vertikal memakai jumlah pada sumbu Y, bukan indeks kategori', () => {
    assert.equal(tooltipDataValue(context('bar', { x: 2, y: 12 })), 12);
});

test('tooltip line memakai jumlah pada sumbu Y, bukan indeks kategori', () => {
    assert.equal(tooltipDataValue(context('line', { x: 4, y: 18 })), 18);
});

test('tooltip line hanya menampilkan jumlah, bukan persentase dari akumulasi lintas bulan', () => {
    assert.equal(tooltipLabel(context('line', { x: 4, y: 100 }), 1200), ' 100 pegawai');
});

test('tooltip bar horizontal memakai jumlah pada sumbu X', () => {
    assert.equal(tooltipDataValue(context('bar', { x: 9, y: 1 }, 'y')), 9);
});

test('tooltip doughnut memakai nilai arc secara langsung', () => {
    assert.equal(tooltipDataValue(context('doughnut', 7)), 7);
});

test('tooltip doughnut menampilkan nama, jumlah, dan persentase', () => {
    const cpns = context('doughnut', 3, 'x', 'CPNS');

    assert.deepEqual(doughnutTooltipContent(cpns, 16), {
        label: 'CPNS',
        value: '3 pegawai (18.8%)',
    });
});

test('tooltip doughnut PNS dan PPPK diposisikan pada sisi luar ring', () => {
    assert.equal(doughnutTooltipPlacement(-Math.PI / 2, (5 * Math.PI) / 8), 'right');
    assert.equal(doughnutTooltipPlacement((5 * Math.PI) / 8, (9 * Math.PI) / 8), 'left');
});
