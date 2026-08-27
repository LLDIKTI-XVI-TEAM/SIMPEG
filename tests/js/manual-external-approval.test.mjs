import assert from 'node:assert/strict';
import test from 'node:test';

let editorFactory;

globalThis.window = {
    Alpine: {
        data(name, factory) {
            if (name === 'manualExternalApprovalEditor') editorFactory = factory;
        },
    },
    clearTimeout: globalThis.clearTimeout,
    confirm: () => true,
    setTimeout: globalThis.setTimeout,
};
globalThis.document = { addEventListener() {} };

const approvalModule = await import('../../resources/js/pages/manual-external-approval.js');
const { resetLeaveAdministrationWorkspaceScroll } = approvalModule;

const step = (type) => ({
    step_type: type,
    approver_source: 'simpeg_employee',
    approver_employee_id: '',
    approver_name: '',
    approver_label: '',
    approver_position: '',
    approver_institution: '',
    acted_on: '2026-08-20',
    decision_note: '',
});

const deferred = () => {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });

    return { promise, resolve };
};

const editor = () => editorFactory(
    [step('kepala_bagian'), step('pybmc')],
    { available: false, valid: false, steps: [], warnings: [] },
    '/cuti/approver-lookup',
);

const focusableControl = ({ disabled = false, visible = true } = {}) => ({
    disabled,
    hidden: false,
    getAttribute(attribute) {
        if (attribute === 'aria-hidden') return 'false';
        if (attribute === 'tabindex') return null;

        return null;
    },
    getClientRects: () => visible ? [{}] : [],
    focus() {
        document.activeElement = this;
    },
});

const tabEvent = (shiftKey = false) => ({
    defaultPrevented: false,
    shiftKey,
    preventDefault() {
        this.defaultPrevented = true;
    },
});

test('workspace pegawai mengembalikan scroll ke atas setelah load tanpa fragment', () => {
    let loadListener;
    let deferredReset;
    let scrollOptions;
    const browserWindow = {
        location: { hash: '' },
        addEventListener(event, listener, options) {
            assert.equal(event, 'load');
            assert.deepEqual(options, { once: true });
            loadListener = listener;
        },
        setTimeout(callback, delay) {
            assert.equal(delay, 0);
            deferredReset = callback;
        },
        scrollTo(options) {
            scrollOptions = options;
        },
    };
    const browserDocument = {
        readyState: 'loading',
        querySelector: () => ({}),
    };

    resetLeaveAdministrationWorkspaceScroll(browserWindow, browserDocument);
    assert.equal(typeof loadListener, 'function');
    loadListener();
    assert.equal(scrollOptions, undefined, 'Reset harus menunggu scroll restoration bawaan browser.');
    assert.equal(typeof deferredReset, 'function');
    deferredReset();

    assert.deepEqual(scrollOptions, {
        top: 0,
        left: 0,
        behavior: 'instant',
    });
});

test('workspace pegawai mempertahankan fragment versi manual', () => {
    let listenerRegistered = false;
    let scrollCalled = false;
    const browserWindow = {
        location: { hash: '#manual-version-form' },
        addEventListener() {
            listenerRegistered = true;
        },
        scrollTo() {
            scrollCalled = true;
        },
    };
    const browserDocument = {
        readyState: 'loading',
        querySelector: () => ({}),
    };

    resetLeaveAdministrationWorkspaceScroll(browserWindow, browserDocument);

    assert.equal(listenerRegistered, false);
    assert.equal(scrollCalled, false);
});

test('hasil lookup async tetap terikat pada stable key setelah tahap lain dihapus', async () => {
    const instance = editor();
    const original = instance.steps[0];
    const other = instance.steps[1];
    const response = deferred();
    const approver = {
        id: '11111111-1111-4111-8111-111111111111',
        nama_lengkap: 'Approver Tahap Asal',
        nip: '199001012020121001',
        jabatan_terakhir: 'Kepala Bagian',
    };
    globalThis.fetch = () => response.promise;

    assert.match(original.clientKey, /^manual-approval-step-/);
    assert.notEqual(original.clientKey, other.clientKey);
    const pending = instance.lookup(original.clientKey, 'Approver');
    instance.removeStep(1);
    response.resolve({ ok: true, json: async () => ({ data: [approver] }) });
    await pending;

    assert.equal(instance.steps[0], original);
    assert.deepEqual(original.lookupResults, [approver]);
    assert.deepEqual(other.lookupResults, []);
    instance.chooseApprover(original.clientKey, approver);
    assert.equal(original.approver_employee_id, approver.id);
    assert.equal(other.approver_employee_id, '');
});

test('perubahan query setelah memilih approver menghapus UUID dan label pilihan lama', () => {
    const instance = editor();
    const target = instance.steps[0];
    const approver = {
        id: '77777777-7777-4777-8777-777777777777',
        nama_lengkap: 'Approver Lama',
        nip: '199001012020121007',
        jabatan_terakhir: 'Kepala Bagian',
    };
    window.setTimeout = () => 73;

    instance.chooseApprover(target.clientKey, approver);
    instance.scheduleLookup(target.clientKey, 'Approver Baru');

    assert.equal(target.approver_employee_id, '');
    assert.equal(target.approver_label, '');
    window.setTimeout = globalThis.setTimeout;
});

test('lookup tanpa hasil menyimpan status kosong yang dapat diumumkan', async () => {
    const instance = editor();
    const target = instance.steps[0];
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: [] }) });

    await instance.lookup(target.clientKey, 'Tidak Ada');

    assert.equal(target.lookupState, 'empty');
    assert.equal(instance.lookupStatusMessage(target), 'Approver tidak ditemukan.');
});

test('lookup gagal menyimpan pesan error yang dapat diumumkan', async () => {
    const instance = editor();
    const target = instance.steps[0];
    globalThis.fetch = async () => {
        throw new Error('Jaringan terputus');
    };

    await instance.lookup(target.clientKey, 'Approver');

    assert.equal(target.lookupState, 'error');
    assert.equal(target.lookupError, 'Pencarian approver gagal dimuat. Coba lagi.');
    assert.equal(instance.lookupStatusMessage(target), 'Pencarian approver gagal dimuat. Coba lagi.');
});

test('lookup membedakan sesi berakhir dari kegagalan jaringan', async () => {
    const instance = editor();
    const target = instance.steps[0];
    globalThis.fetch = async () => ({
        ok: false,
        status: 401,
        redirected: false,
    });

    await instance.lookup(target.clientKey, 'Approver');

    assert.equal(target.lookupState, 'session_expired');
    assert.equal(target.lookupError, 'Sesi Anda telah berakhir. Muat ulang halaman untuk masuk kembali.');
    assert.equal(instance.lookupStatusMessage(target), 'Sesi Anda telah berakhir. Muat ulang halaman untuk masuk kembali.');
});

test('retry lookup memakai query terakhir dan memulihkan hasil', async () => {
    const instance = editor();
    const target = instance.steps[0];
    target.lookupQuery = 'Approver Ulang';
    target.lookupState = 'error';
    target.lookupError = 'Pencarian approver gagal dimuat. Coba lagi.';
    const approver = {
        id: '88888888-8888-4888-8888-888888888888',
        nama_lengkap: 'Approver Ulang',
        nip: '199001012020121008',
        jabatan_terakhir: 'Kepala Bagian',
    };
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: [approver] }) });

    await instance.retryLookup(target.clientKey);

    assert.equal(target.lookupState, 'results');
    assert.deepEqual(target.lookupResults, [approver]);
    assert.equal(target.lookupError, '');
});

test('error per field dari server melekat pada tahap awal dan dapat dibersihkan saat diedit', () => {
    const instance = editorFactory(
        [step('kepala_bagian'), step('pybmc')],
        { available: false, valid: false, steps: [], warnings: [] },
        '/cuti/approver-lookup',
        'manual-approval',
        { 0: { approver_name: 'Nama pejabat wajib diisi.', acted_on: 'Tanggal tidak valid.' } },
    );
    const target = instance.steps[0];

    assert.equal(target.fieldErrors.approver_name, 'Nama pejabat wajib diisi.');
    assert.equal(target.fieldErrors.acted_on, 'Tanggal tidak valid.');

    instance.clearFieldError(target.clientKey, 'approver_name');

    assert.equal(target.fieldErrors.approver_name, undefined);
    assert.equal(target.fieldErrors.acted_on, 'Tanggal tidak valid.');
});

test('respons validasi memfokuskan field pertama yang gagal dan memakai ringkasan sebagai fallback', () => {
    const instance = editor();
    let nextTick;
    let invalidFocused = false;
    let fallbackFocused = false;
    const invalidField = {
        disabled: false,
        getClientRects: () => [{}],
        focus: () => { invalidFocused = true; },
    };
    const editorRoot = { querySelectorAll: () => [invalidField] };
    const fallback = { focus: () => { fallbackFocused = true; } };
    instance.$nextTick = (callback) => {
        nextTick = callback;
    };

    assert.equal(typeof instance.focusFirstError, 'function', 'Editor harus menyediakan fokus error per-field.');
    instance.focusFirstError(editorRoot, fallback);
    nextTick();

    assert.equal(invalidFocused, true);
    assert.equal(fallbackFocused, false);
});

test('tambah tahap mengumumkan perubahan dan memindahkan fokus ke tahap baru', () => {
    const instance = editor();
    let nextTick;
    let focused = false;
    instance.$nextTick = (callback) => {
        nextTick = callback;
    };

    instance.addStep();
    const added = instance.steps[2];
    document.querySelector = (selector) => selector.includes(added.clientKey)
        ? { focus: () => { focused = true; } }
        : null;
    nextTick();

    assert.equal(instance.editorStatus, 'Tahap 3 ditambahkan.');
    assert.equal(focused, true);
});

test('hapus tahap mengumumkan perubahan dan memindahkan fokus ke tahap terdekat', () => {
    const instance = editor();
    const survivor = instance.steps[1];
    let nextTick;
    let focused = false;
    instance.$nextTick = (callback) => {
        nextTick = callback;
    };

    instance.removeStep(0);
    document.querySelector = (selector) => selector.includes(survivor.clientKey)
        ? { focus: () => { focused = true; } }
        : null;
    nextTick();

    assert.equal(instance.editorStatus, 'Tahap 1 dihapus.');
    assert.equal(focused, true);
});

test('pengumuman identik berturut-turut tetap menghasilkan perubahan live-region pada tick baru', () => {
    const instance = editor();
    const pendingTicks = [];
    const transitions = [];
    let status = '';
    Object.defineProperty(instance, 'editorStatus', {
        configurable: true,
        get: () => status,
        set: (value) => {
            if (value !== status) transitions.push(value);
            status = value;
        },
    });
    instance.$nextTick = (callback) => pendingTicks.push(callback);

    instance.announce('Tahap 1 dihapus.');
    assert.equal(instance.editorStatus, 'Tahap 1 dihapus.');
    assert.equal(pendingTicks.length, 0, 'Pengumuman pertama dapat langsung mengisi live-region.');

    instance.announce('Tahap 1 dihapus.');
    assert.equal(pendingTicks.length, 1, 'Pengumuman identik kedua harus dijadwalkan ulang.');
    assert.equal(instance.editorStatus, '');
    pendingTicks.shift()();

    assert.deepEqual(transitions, [
        'Tahap 1 dihapus.',
        '',
        'Tahap 1 dihapus.',
    ]);
});

test('Tab dari kontrol terakhir modal kembali ke kontrol pertama yang aktif dan terlihat', () => {
    const instance = editor();
    const first = focusableControl();
    const hidden = focusableControl({ visible: false });
    const disabled = focusableControl({ disabled: true });
    const last = focusableControl();
    instance.$refs = {
        chainPreviewDialog: {
            querySelectorAll: () => [first, hidden, disabled, last],
        },
    };
    document.activeElement = last;
    const event = tabEvent();

    assert.equal(typeof instance.trapPreviewFocus, 'function', 'Editor harus menyediakan focus trap modal.');
    instance.trapPreviewFocus(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(document.activeElement, first);
});

test('Shift+Tab dari kontrol pertama modal kembali ke kontrol terakhir yang aktif dan terlihat', () => {
    const instance = editor();
    const first = focusableControl();
    const hidden = focusableControl({ visible: false });
    const disabled = focusableControl({ disabled: true });
    const last = focusableControl();
    instance.$refs = {
        chainPreviewDialog: {
            querySelectorAll: () => [first, hidden, disabled, last],
        },
    };
    document.activeElement = first;
    const event = tabEvent(true);

    assert.equal(typeof instance.trapPreviewFocus, 'function', 'Editor harus menyediakan focus trap modal.');
    instance.trapPreviewFocus(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(document.activeElement, last);
});

test('remove membatalkan timer dan request serta mengabaikan response tahap yang sudah dibuang', async () => {
    const instance = editor();
    const removed = instance.steps[0];
    const survivor = instance.steps[1];
    const response = deferred();
    let requestSignal;
    let clearedTimer;
    window.setTimeout = () => 73;
    window.clearTimeout = (timer) => {
        clearedTimer = timer;
    };
    globalThis.fetch = (_url, options) => {
        requestSignal = options.signal;

        return response.promise;
    };

    instance.scheduleLookup(removed.clientKey, 'Approver');
    const pending = instance.lookup(removed.clientKey, 'Approver');
    instance.removeStep(0);

    assert.equal(clearedTimer, 73);
    assert.equal(requestSignal.aborted, true);
    response.resolve({
        ok: true,
        json: async () => ({
            data: [{ id: '22222222-2222-4222-8222-222222222222', nama_lengkap: 'Hasil Terlambat', nip: '1' }],
        }),
    });
    await pending;

    assert.deepEqual(instance.steps, [survivor]);
    assert.equal(survivor.approver_employee_id, '');
    assert.deepEqual(survivor.lookupResults, []);
    window.setTimeout = globalThis.setTimeout;
    window.clearTimeout = globalThis.clearTimeout;
});

test('response request lama tidak menimpa hasil request terbaru pada stable key yang sama', async () => {
    const instance = editor();
    const target = instance.steps[0];
    const older = deferred();
    const newer = deferred();
    const responses = [older, newer];
    globalThis.fetch = () => responses.shift().promise;

    const olderPending = instance.lookup(target.clientKey, 'Lama');
    const newerPending = instance.lookup(target.clientKey, 'Baru');
    newer.resolve({
        ok: true,
        json: async () => ({
            data: [{ id: '33333333-3333-4333-8333-333333333333', nama_lengkap: 'Hasil Baru', nip: '3' }],
        }),
    });
    await newerPending;
    older.resolve({
        ok: true,
        json: async () => ({
            data: [{ id: '44444444-4444-4444-8444-444444444444', nama_lengkap: 'Hasil Lama', nip: '4' }],
        }),
    });
    await olderPending;

    assert.deepEqual(target.lookupResults.map((approver) => approver.nama_lengkap), ['Hasil Baru']);
});

test('tahap baru dan hasil copy selalu memperoleh client key baru yang unik', () => {
    const instance = editor();
    const initialKeys = instance.steps.map((approvalStep) => approvalStep.clientKey);
    const originalConfirm = window.confirm;
    let confirmMessage = '';
    window.confirm = (message) => {
        confirmMessage = message;
        return true;
    };

    instance.addStep();
    const addedKey = instance.steps[2].clientKey;
    assert.equal(new Set([...initialKeys, addedKey]).size, 3);

    instance.preview = {
        available: true,
        valid: true,
        warnings: [],
        steps: [
            { step_type: 'kepala_bagian', approver: { id: '55555555-5555-4555-8555-555555555555', nama_lengkap: 'Kabag Copy' } },
            { step_type: 'pybmc', approver: { id: '66666666-6666-4666-8666-666666666666', nama_lengkap: 'PYBMC Copy' } },
        ],
    };
    instance.$refs = { chainPreviewDialog: { close() {} } };
    instance.copyPreview();
    window.confirm = originalConfirm;

    const copiedKeys = instance.steps.map((approvalStep) => approvalStep.clientKey);
    assert.equal(confirmMessage, 'Timpa draf rangkaian yang sedang diedit?');
    assert.equal(new Set(copiedKeys).size, 2);
    assert.equal(copiedKeys.some((key) => [...initialKeys, addedKey].includes(key)), false);
});
