import assert from 'node:assert/strict';
import test from 'node:test';
import { createEmployeeArchivePicker } from '../../resources/js/pages/employee-archive-picker.js';

const documentRow = (id) => ({ id, label: `SK ${id}`, nama_dokumen: `Dokumen ${id}`, nomor_dokumen: id, tanggal_dokumen: '2026-01-01' });
const response = (rows, page = 1, lastPage = 2) => ({ ok: true, json: async () => ({ data: rows, meta: { current_page: page, last_page: lastPage, total: 12 } }) });

test('arsip dimuat saat dibuka; pencarian dan halaman dilakukan di server', async () => {
    const calls = [];
    const picker = createEmployeeArchivePicker({ url: '/pilihan-arsip', category: 'sk_kgb', fetch: async (url) => {
        calls.push(url);
        return response([documentRow('a')]);
    } });
    assert.equal(calls.length, 0);
    await picker.setActive(true);
    assert.match(calls[0], /kategori=sk_kgb/);
    picker.query = 'SK lama';
    await picker.load(1);
    assert.match(calls[1], /q=SK\+lama/);
    await picker.load(2);
    assert.match(calls[2], /page=2/);
    picker.query = 'Kata baru';
    await picker.load(2);
    assert.match(calls[3], /q=Kata\+baru&page=1/);
});

test('pilihan tetap tersimpan ketika hasil pencarian atau halaman berubah', async () => {
    let next = response([documentRow('a')]);
    const picker = createEmployeeArchivePicker({ url: '/arsip', fetch: async () => next });
    const events = [];
    picker.$dispatch = (name, doc) => events.push([name, doc]);
    await picker.setActive(true);
    picker.select('a');
    next = response([documentRow('b')], 2);
    await picker.load(2);
    assert.equal(picker.selected.id, 'a');
    assert.deepEqual(picker.rows.map(row => row.id), ['b']);
    await picker.setActive(false);
    assert.equal(picker.active, false);
    assert.equal(picker.selected.id, 'a');
    assert.deepEqual(events, [['archive-selected', documentRow('a')]]);
    picker.clear();
    assert.equal(picker.selected, null);
});

test('respons terlambat tidak mengganti hasil pencarian terbaru atau panel yang ditutup', async () => {
    const pending = [];
    const picker = createEmployeeArchivePicker({ url: '/arsip', fetch: (_url, options) => new Promise(resolve => pending.push({ resolve, signal: options.signal })) });
    const old = picker.setActive(true);
    picker.query = 'baru';
    const fresh = picker.load(1);
    assert.equal(pending[0].signal.aborted, true);
    pending[1].resolve(response([documentRow('baru')]));
    await fresh;
    pending[0].resolve(response([documentRow('lama')]));
    await old;
    assert.equal(picker.rows[0].id, 'baru');
    const late = picker.load(2);
    await picker.setActive(false);
    pending[2].resolve(response([documentRow('terlambat')]));
    await late;
    assert.equal(picker.rows[0].id, 'baru');
    assert.equal(picker.loading, false);
});

test('kegagalan jaringan dapat dicoba ulang dan hasil kosong tidak dianggap error', async () => {
    let fail = true;
    const picker = createEmployeeArchivePicker({ url: '/arsip', fetch: async () => {
        if (fail) throw new TypeError('network');
        return response([], 1, 1);
    } });
    await picker.setActive(true);
    assert.ok(picker.error);
    assert.equal(picker.loading, false);
    fail = false;
    await picker.load(1);
    assert.equal(picker.error, '');
    assert.equal(picker.loaded, true);
    assert.deepEqual(picker.rows, []);
});

test('sesi berakhir atau izin dicabut tidak menyisakan pilihan yang dapat dikirim', async () => {
    for (const status of [401, 403, 419]) {
        const picker = createEmployeeArchivePicker({ url: '/arsip', selected: documentRow('a'), fetch: async () => ({ ok: false, status }) });
        await picker.setActive(true);
        assert.ok(picker.error);
        assert.equal(picker.selected, null);
    }
});

test('pilihan hasil validasi dipulihkan tanpa memuat seluruh arsip; destroy membatalkan request', async () => {
    let signal;
    const picker = createEmployeeArchivePicker({ url: '/arsip', selected: documentRow('a'), fetch: (_url, options) => {
        signal = options.signal;
        return new Promise(() => {});
    } });
    assert.equal(picker.selected.id, 'a');
    picker.setActive(true);
    picker.destroy();
    assert.equal(signal.aborted, true);
});
