import assert from 'node:assert/strict';
import test from 'node:test';
import { createCutiConfigNavigation } from '../../resources/js/pages/cuti-config-navigation.js';

test('draft yang dipulihkan setelah validasi langsung terlindungi tanpa mengetik ulang', () => {
    for (const editor of ['pegawai', 'atasan', 'pybmc']) {
        let focused = 0;
        const ticks = [];
        const page = createCutiConfigNavigation({ restoredEditor: editor, hasGlobalIdentityScope: true });
        page.$el = { querySelector: (selector) => {
            assert.equal(selector, `[data-config-form="${editor}"]`);
            return { querySelector: () => ({ focus: () => { focused++; } }) };
        } };
        page.$nextTick = (callback) => ticks.push(callback);
        page.init();
        assert.deepEqual(page.dirtyEditors, { [editor]: true });
        assert.equal(page.activeTab, editor === 'pybmc' ? 'pybmc' : 'pegawai');
        assert.equal(focused, 0);
        ticks.forEach((callback) => callback());
        assert.equal(focused, 1);
        assert.equal(page.requestDeparture(() => {}), false);
        page.cancelDeparture();
        assert.equal(page.requestDeparture(() => {}, null, null, editor), true);
        let prevented = false;
        page.beforeUnload({ preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true);
    }
});

test('error form tanpa field invalid memakai ringkasan lokal sebagai fallback fokus', () => {
    let focused = 0;
    const page = createCutiConfigNavigation({ restoredEditor: 'pegawai' });
    page.$el = { querySelector: () => ({ querySelector: (selector) =>
        selector === '[data-config-error-summary]' ? { focus: () => { focused++; } } : null,
    }) };
    page.$nextTick = (callback) => callback();
    page.init();
    assert.equal(focused, 1);
});

test('GET bersih dan marker form yang tidak tersedia tidak menghasilkan draft atau fokus palsu', () => {
    for (const restoredEditor of [undefined, 'tidak-dikenal', 'pybmc', 'atasan']) {
        const page = createCutiConfigNavigation({ restoredEditor });
        page.$el = { querySelector: () => null };
        page.$nextTick = () => assert.fail('Tidak ada error yang perlu difokuskan.');
        page.init();
        assert.deepEqual(page.dirtyEditors, {});
        assert.equal(page.requestDeparture(() => {}), true);
    }
});

test('tab berotorisasi dan URL hanya menyimpan konteks navigasi aman', () => {
    let url;
    const page = createCutiConfigNavigation({
        initialTab: 'riwayat', canViewAudit: false, hasGlobalIdentityScope: false,
        location: { href: 'https://simpeg.test/cuti/konfigurasi?employee_id=pegawai-a#lama' },
        history: { replaceState: (_state, _title, value) => { url = new URL(value); } },
    });
    assert.equal(page.activeTab, 'pegawai');
    assert.deepEqual(page.tabs, ['pegawai', 'rangkaian']);
    page.switchTab('pybmc');
    assert.equal(page.activeTab, 'pegawai');
    page.switchTab('rangkaian');
    assert.equal(url.searchParams.get('tab'), 'rangkaian');
    assert.equal(url.searchParams.get('employee_id'), 'pegawai-a');
    assert.equal(url.hash, '');
    assert.deepEqual([...url.searchParams.keys()].sort(), ['employee_id', 'step', 'tab']);
});

test('tab tidak membuang draft dan submit editor sendiri tidak meminta konfirmasi', () => {
    const page = createCutiConfigNavigation();
    page.markDirty('pegawai');
    page.switchTab('rangkaian');
    assert.equal(page.dirtyEditors.pegawai, true);
    assert.equal(page.requestDeparture(() => {}, null, null, 'pegawai'), true);
    assert.equal(page.leaveConfirmOpen, false);
    page.markDirty('rangkaian');
    assert.equal(page.requestDeparture(() => {}, null, null, 'pegawai'), false);
    assert.equal(page.leaveConfirmOpen, true);
});

test('batal perpindahan memulihkan pemilih, lanjut menjalankan satu navigasi', () => {
    const page = createCutiConfigNavigation();
    let restored = 0;
    let navigated = 0;
    page.markDirty('pegawai');
    page.requestDeparture(() => { navigated++; }, () => { restored++; });
    page.cancelDeparture();
    assert.equal(restored, 1);
    assert.equal(navigated, 0);
    assert.equal(page.dirtyEditors.pegawai, true);
    page.requestDeparture(() => { navigated++; });
    page.confirmDeparture();
    page.confirmDeparture();
    assert.equal(navigated, 1);
});

test('sukses batch tidak membersihkan draft editor lain dan applying mengunci navigasi', () => {
    const page = createCutiConfigNavigation();
    page.markDirty('pegawai');
    page.syncBatchState({ dirty: true, applying: true });
    page.switchTab('rangkaian');
    assert.equal(page.activeTab, 'pegawai');
    assert.equal(page.requestDeparture(() => assert.fail('Tidak boleh pergi.')), false);
    page.syncBatchState({ dirty: false, applying: false });
    assert.equal(page.dirtyEditors.rangkaian, false);
    assert.equal(page.dirtyEditors.pegawai, true);
});

test('sukses batch tanpa draft lain menuju URL utama dan tidak meminta beforeunload lagi', () => {
    const destinations = [];
    const page = createCutiConfigNavigation({
        initialTab: 'rangkaian', successUrl: '/cuti/konfigurasi-approval',
        location: { href: 'https://simpeg.test/cuti/konfigurasi-approval?tab=rangkaian&step=tinjau', assign: (url) => destinations.push(url) },
    });
    page.syncBatchState({ dirty: false, applying: false });
    page.finishBatch({ counts: { create: 2 } });

    assert.deepEqual(destinations, ['/cuti/konfigurasi-approval']);
    assert.equal(page.leaveConfirmOpen, false);
    assert.equal(page.allowUnload, true);
    page.beforeUnload({ preventDefault: () => assert.fail('Redirect sukses tidak boleh terhalang prompt kedua.') });
});

test('sukses batch menjaga draft lain sampai konfirmasi, batal menampilkan toast tanpa pindah', () => {
    const destinations = [];
    const notifications = [];
    const announcements = [];
    let restoredFocus = 0;
    const page = createCutiConfigNavigation({ successUrl: '/cuti/konfigurasi-approval', location: { assign: (url) => destinations.push(url) } });
    page.$dispatch = (name, detail) => notifications.push({ name, detail });
    page.announce = (message) => announcements.push(message);
    page.markDirty('pegawai');
    page.markDirty('pybmc');
    page.syncBatchState({ dirty: false, applying: false });
    page.finishBatch({ counts: { create: 1, replace: 2, unchanged: 3, skip_existing: 1, skip_inactive: 2, skip_supervisor: 3, skip_self_required: 4 }, onStay: () => { restoredFocus++; } });
    assert.equal(page.leaveConfirmOpen, true);
    assert.deepEqual(destinations, []);
    assert.equal(page.allowUnload, false);
    assert.equal(restoredFocus, 0);
    page.cancelDeparture();
    assert.deepEqual(destinations, []);
    assert.deepEqual(page.dirtyEditors, { pegawai: true, pybmc: true, rangkaian: false });
    assert.equal(notifications.length, 1);
    assert.equal(notifications[0].name, 'notify');
    assert.equal(notifications[0].detail.type, 'success');
    assert.match(notifications[0].detail.message, /1 dibuat.*2 diganti.*3 tidak berubah.*10 dilewati/);
    assert.deepEqual(announcements, [notifications[0].detail.message]);
    assert.equal(restoredFocus, 1);
});

test('konfirmasi meninggalkan draft setelah batch sukses menjalankan redirect tepat sekali', () => {
    const destinations = [];
    const page = createCutiConfigNavigation({ successUrl: '/cuti/konfigurasi-approval', location: { assign: (url) => destinations.push(url) } });
    page.markDirty('atasan');
    page.finishBatch({ counts: { create: 1 } });
    page.confirmDeparture();
    page.confirmDeparture();
    assert.deepEqual(destinations, ['/cuti/konfigurasi-approval']);
    assert.equal(page.allowUnload, true);
});

test('toast flash tujuan menunggu inisialisasi komponen dan menjaga counts final lengkap', () => {
    const ticks = [];
    const notifications = [];
    const announcements = [];
    const page = createCutiConfigNavigation({ batchSuccessCounts: {
        create: 1, replace: 2, unchanged: 3, skip_existing: 1, skip_inactive: 2, skip_supervisor: 3, skip_self_required: 4,
    } });
    page.$nextTick = (callback) => ticks.push(callback);
    page.$dispatch = (name, detail) => notifications.push({ name, detail });
    page.announce = (message) => announcements.push(message);
    page.init();
    assert.equal(page.activeTab, 'pegawai');
    assert.deepEqual(notifications, []);
    assert.deepEqual(announcements, []);
    while (ticks.length) ticks.shift()();
    assert.equal(notifications.length, 1);
    assert.equal(notifications[0].name, 'notify');
    assert.equal(notifications[0].detail.type, 'success');
    assert.match(notifications[0].detail.message, /1 dibuat.*2 diganti.*3 tidak berubah.*10 dilewati/);
    assert.deepEqual(announcements, [notifications[0].detail.message]);

    const noFlash = createCutiConfigNavigation();
    noFlash.$nextTick = (callback) => callback();
    noFlash.$dispatch = () => assert.fail('Halaman biasa tidak boleh mengarang sukses batch.');
    noFlash.announce = () => assert.fail('Halaman biasa tidak boleh mengumumkan sukses batch.');
    noFlash.init();
});

test('beforeunload aktif hanya untuk draft atau transaksi yang masih berjalan', () => {
    const page = createCutiConfigNavigation();
    let prevented = 0;
    const event = { preventDefault: () => { prevented++; }, returnValue: undefined };
    page.beforeUnload(event);
    assert.equal(prevented, 0);
    page.markDirty('pegawai');
    page.beforeUnload(event);
    assert.equal(prevented, 1);
    assert.equal(event.returnValue, '');
});

test('filter batch bukan perubahan draft dan konfirmasi submit gagal tetap menjaga beforeunload', () => {
    const page = createCutiConfigNavigation();
    page.trackFormChange({ target: { closest: () => ({ dataset: { configForm: 'rangkaian' } }) } });
    assert.equal(page.hasUnsavedExcept(null), false);
    page.markDirty('pegawai');
    page.requestDeparture(() => { /* requestSubmit dapat berhenti pada validasi HTML tanpa event submit. */ });
    page.confirmDeparture();
    assert.equal(page.allowUnload, false);
});

test('submit berikutnya tetap dikonfirmasi setelah requestSubmit ditolak validasi HTML', () => {
    const page = createCutiConfigNavigation();
    page.markDirty('rangkaian');
    const form = { dataset: { configForm: 'pegawai' }, requestSubmit() {}, closest: () => null };
    const submit = () => {
        const event = { target: form, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() {} };
        page.guardSubmit(event);
        return event;
    };
    assert.equal(submit().defaultPrevented, true);
    page.confirmDeparture();
    assert.equal(submit().defaultPrevented, true);
    assert.equal(page.leaveConfirmOpen, true);
});
