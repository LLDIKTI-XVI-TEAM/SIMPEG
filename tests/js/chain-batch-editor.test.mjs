import assert from 'node:assert/strict';
import test from 'node:test';
import { createChainBatchEditor } from '../../resources/js/pages/chain-batch-editor.js';

const target = (id) => ({ id: String(id), nama_lengkap: `Pegawai ${id}`, nip: `00${id}` });
const response = (data, status = 200) => ({ ok: status < 400, status, redirected: false, json: async () => data });
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
};
const config = { targetsUrl: '/target', approversUrl: '/approver', previewUrl: '/pratinjau', applyUrl: '/terapkan', requestAnimationFrame: (callback) => callback() };
const ready = (fetch) => {
    const editor = createChainBatchEditor({ ...config, fetch });
    editor.toggleTarget(target(1));
    editor.preview = { preview_token: 'token', can_apply: true, rows: [], counts: { create: 1, replace: 0, unchanged: 2, skip_existing: 3 } };
    return editor;
};

test('target dimuat malas saat Pilih dibuka, navigasi tidak membatalkan preview', async () => {
    let calls = 0;
    const editor = createChainBatchEditor({ ...config, globalPybmc: target(9), fetch: async () => {
        calls++;
        return response({ data: [target(1)], current_page: 1, last_page: 1, total: 1 });
    } });
    editor.init();
    assert.equal(calls, 0);
    await editor.goToStep('pilih');
    assert.equal(calls, 1);
    editor.toggleTarget(target(1));
    editor.preview = { preview_token: 'tetap' };
    await editor.goToStep('tinjau');
    editor.setVisible(false);
    editor.setVisible(true);
    await editor.goToStep('pilih');
    assert.equal(calls, 1);
    assert.equal(editor.preview.preview_token, 'tetap');
    assert.equal(editor.step, 'pilih');
});

test('pagination dan filter 50 target tidak mengubah 45 tindakan, token, atau payload', () => {
    const editor = createChainBatchEditor();
    for (let i = 1; i <= 50; i++) editor.toggleTarget(target(i));
    editor.preview = {
        preview_token: 'utuh', can_apply: true, counts: { create: 40, replace: 5, skip_existing: 5 },
        rows: Array.from({ length: 50 }, (_, i) => ({ ...target(i + 1), employee_id: String(i + 1), outcome: i < 40 ? 'create' : i < 45 ? 'replace' : 'skip_existing' })),
    };
    const before = editor.payload();
    assert.equal(editor.pagedReviewRows.length, 10);
    assert.equal(editor.reviewLastPage, 5);
    editor.setReviewPage(5);
    assert.equal(editor.pagedReviewRows[0].employee_id, '41');
    editor.setReviewFilter('reviewFilter', 'replace');
    assert.equal(editor.reviewPage, 1);
    assert.equal(editor.pagedReviewRows.length, 5);
    assert.equal(editor.applyCount, 45);
    assert.equal(editor.preview.preview_token, 'utuh');
    assert.deepEqual(editor.payload(), before);
    assert.equal(editor.payload().employee_ids.length, 50);
});

test('pencarian literal nama/NIP, semua sebab skip, kosong dan 10/25 final rows', () => {
    const editor = createChainBatchEditor();
    editor.result = { counts: { skip_existing: 1, skip_missing_supervisor: 1 }, rows: [
        { employee_id: '1', nama_lengkap: 'Nama %_ Test', nip: '012345', outcome: 'skip_existing' },
        { employee_id: '2', nama_lengkap: 'Pegawai Dua', nip: '098765', outcome: 'skip_missing_supervisor' },
        ...Array.from({ length: 26 }, (_, i) => ({ ...target(i + 3), employee_id: String(i + 3), outcome: 'create' })),
    ] };
    editor.setReviewFilter('reviewFilter', 'skipped');
    assert.equal(editor.filteredReviewRows.length, 2);
    editor.setReviewFilter('reviewSearch', '%_');
    assert.equal(editor.filteredReviewRows[0].employee_id, '1');
    editor.setReviewFilter('reviewSearch', '9876');
    assert.equal(editor.filteredReviewRows[0].employee_id, '2');
    editor.setReviewFilter('reviewSearch', 'tak ditemukan');
    assert.equal(editor.pagedReviewRows.length, 0);
    assert.equal(editor.reviewLastPage, 1);
    editor.resetReview();
    editor.setReviewFilter('reviewPerPage', '25');
    assert.equal(editor.pagedReviewRows.length, 25);
    editor.setReviewPage(99);
    assert.equal(editor.reviewPage, 2);
    assert.equal(editor.pagedReviewRows.length, 3);
    editor.setReviewFilter('reviewPerPage', '1000');
    assert.equal(editor.reviewPerPage, 10);
    assert.equal(editor.reviewPage, 1);
    assert.deepEqual(editor.summary(), { create: 0, replace: 0, unchanged: 0, skipped: 2 });
});

test('deep link tanpa draft kembali Susun dan alasan tidak menjadi prasyarat masuk Tinjau', async () => {
    const editor = createChainBatchEditor({ initialStep: 'tinjau', globalPybmc: target(9) });
    editor.init();
    assert.equal(editor.step, 'susun');
    assert.match(editor.notice, /belum|susun/i);
    editor.toggleTarget(target(1));
    await editor.goToStep('tinjau');
    assert.equal(editor.step, 'tinjau');
    assert.equal(editor.draft.reason, '');
    assert.equal(editor.preview, null);
});

test('validasi membuka tahap field dan respons tersembunyi tidak mencuri fokus', async () => {
    const editor = ready(async () => response({ errors: { 'verifiers.0.approver_employee_id': ['Pilih pegawai.'] } }, 422));
    editor.setVisible(false);
    editor.$nextTick = (callback) => callback();
    let focused = 0;
    editor.editorRoot = { querySelectorAll: () => [], querySelector: () => ({ focus: () => { focused++; } }) };
    await editor.loadPreview();
    assert.equal(editor.step, 'susun');
    assert.equal(focused, 0);
    editor.setVisible(true);
    assert.equal(focused, 1);
    assert.match(editor.error, /isian/);
});

for (const focusKind of ['heading', 'error']) {
    test(`fokus ${focusKind} menunggu frame pembukaan panel setelah nextTick`, async () => {
        const ticks = [];
        let panelVisible = false;
        const frames = [() => { panelVisible = true; }];
        let activeElement = 'BODY';
        const field = {
            disabled: false,
            getClientRects: () => panelVisible ? [{}] : [],
            focus: () => { if (panelVisible) activeElement = focusKind; },
        };
        const editor = createChainBatchEditor({ globalPybmc: target(9), requestAnimationFrame: (callback) => frames.push(callback) });
        editor.$nextTick = (callback) => ticks.push(callback);
        editor.editorRoot = { querySelector: () => field, querySelectorAll: () => [field] };
        editor.toggleTarget(target(1));
        editor.step = 'pilih';
        if (focusKind === 'heading') await editor.goToStep('tinjau');
        else { editor.errors = { reason: ['Alasan wajib diisi.'] }; editor.focusFirstError(); }

        while (ticks.length) ticks.shift()();
        assert.equal(activeElement, 'BODY');
        while (frames.length) frames.shift()();
        assert.equal(activeElement, focusKind);
    });

    test(`fokus ${focusKind} yang tertunda tidak mencuri fokus setelah tab atau tahap berubah`, () => {
        const ticks = [];
        const frames = [];
        let focused = 0;
        const field = { disabled: false, getClientRects: () => [{}], focus: () => { focused++; } };
        const editor = createChainBatchEditor({ requestAnimationFrame: (callback) => frames.push(callback) });
        editor.$nextTick = (callback) => ticks.push(callback);
        editor.editorRoot = { querySelector: () => field, querySelectorAll: () => [field] };
        editor.errors = { reason: ['Alasan wajib diisi.'] };
        const requestFocus = () => focusKind === 'heading' ? editor.focus('#batch-susun-heading') : editor.focusFirstError();
        requestFocus();
        while (ticks.length) ticks.shift()();
        editor.setVisible(false);
        while (frames.length) frames.shift()();
        assert.equal(focused, 0);
        editor.setVisible(true);
        while (ticks.length) ticks.shift()();
        while (frames.length) frames.shift()();
        assert.equal(focused, 1);

        requestFocus();
        while (ticks.length) ticks.shift()();
        editor.step = 'pilih';
        while (frames.length) frames.shift()();
        assert.equal(focused, 1);
    });
}

test('constructor tanpa config tidak memulai jaringan dan pilihan bertahan lintas halaman', () => {
    const editor = createChainBatchEditor({ fetch: () => assert.fail('Constructor tidak boleh fetch.') });
    editor.preview = { preview_token: 'token' };
    editor.toggleTarget(target(1));
    editor.pickerPage = 2;
    assert.equal(editor.selected.has('1'), true);
    assert.equal(editor.preview, null);
    editor.toggleTarget(target(1));
    assert.equal(editor.selected.size, 0);
});

test('target 101 dan pilih halaman yang melebihi batas ditolak utuh', () => {
    const editor = createChainBatchEditor({});
    for (let i = 1; i <= 99; i++) editor.toggleTarget(target(i));
    editor.pickerRows = [target(100), target(101)];
    editor.selectPage();
    assert.equal(editor.selected.size, 99);
    assert.match(editor.error, /100/);
    editor.toggleTarget(target(100));
    editor.toggleTarget(target(101));
    assert.equal(editor.selected.size, 100);
    assert.equal(editor.selected.has('101'), false);
});

test('verifikator berurutan, maksimal delapan, hapus menjaga focus target', () => {
    const editor = createChainBatchEditor({ requestAnimationFrame: config.requestAnimationFrame });
    let focused;
    editor.$nextTick = (callback) => callback();
    editor.editorRoot = { querySelector: (selector) => ({ focus: () => { focused = selector; } }) };
    for (let i = 0; i < 9; i++) editor.addVerifier();
    assert.equal(editor.draft.verifiers.length, 8);
    const first = editor.draft.verifiers[0];
    editor.moveVerifier(0, 1);
    assert.equal(editor.draft.verifiers[1], first);
    editor.removeVerifier(1);
    assert.equal(editor.draft.verifiers.length, 7);
    assert.equal(editor.draft.verifiers.includes(first), false);
    assert.equal(focused, `#${editor.draft.verifiers[1].key}-label`);
});

test('mode alasan dan teks approver membatalkan preview serta UUID lama', () => {
    const editor = ready();
    editor.changeDraft('mode', 'replace');
    assert.equal(editor.preview, null);
    editor.preview = { preview_token: 'token' };
    editor.changeDraft('reason', 'Alasan baru');
    assert.equal(editor.preview, null);
    editor.chooseApprover(editor.pybmc, target(2));
    editor.scheduleApprover(editor.pybmc, 'x');
    assert.equal(editor.pybmc.employeeId, '');
    assert.equal(editor.payload().pybmc_employee_id, null);
});

test('target response lama tidak menimpa pencarian baru dan loading selesai', async () => {
    const old = deferred();
    let calls = 0;
    const editor = createChainBatchEditor({ ...config, fetch: () => ++calls === 1 ? old.promise : Promise.resolve(response({ data: [target(2)], current_page: 1, last_page: 1, total: 1 })) });
    const pending = editor.loadTargets();
    await editor.loadTargets();
    old.resolve(response({ data: [target(1)], current_page: 1, last_page: 1, total: 1 }));
    await pending;
    assert.deepEqual(editor.pickerRows, [target(2)]);
    assert.equal(editor.pickerLoading, false);
});

test('respons approver lama dan response saat row dihapus tidak mengisi pilihan', async () => {
    const old = deferred();
    const editor = createChainBatchEditor({ ...config, fetch: () => old.promise });
    editor.addVerifier();
    const step = editor.draft.verifiers[0];
    const pending = editor.lookupApprover(step, 'lama');
    editor.removeVerifier(0);
    old.resolve(response({ data: [target(2)] }));
    await pending;
    assert.deepEqual(step.results, []);
    assert.equal(step.loading, false);
});

test('perubahan draft ketika preview pending mengabaikan token usang', async () => {
    const pendingResponse = deferred();
    const editor = ready(() => pendingResponse.promise);
    const pending = editor.loadPreview();
    editor.changeDraft('reason', 'Alasan berubah');
    pendingResponse.resolve(response({ data: { preview_token: 'old', can_apply: true, counts: { create: 1 }, rows: [] } }));
    await pending;
    assert.equal(editor.preview, null);
    assert.equal(editor.previewLoading, false);
});

for (const [label, payload, status] of [
    ['sukses', { data: { preview_token: 'terbaru', can_apply: true, counts: { create: 1 }, rows: [] } }, 200],
    ['validasi', { errors: { reason: ['Alasan wajib diisi.'] } }, 422],
]) {
    for (const destination of ['susun', 'tinjau']) {
        test(`respons preview ${label} tertunda menjaga tahap dan fokus pilihan pengguna di ${destination}`, async () => {
            const pendingResponse = deferred();
            const editor = createChainBatchEditor({
                globalPybmc: target(9), previewUrl: config.previewUrl,
                requestAnimationFrame: config.requestAnimationFrame, fetch: () => pendingResponse.promise,
            });
            let activeElement;
            const steps = [];
            editor.$nextTick = (callback) => callback();
            editor.editorRoot = {
                querySelectorAll: () => [],
                querySelector: (selector) => ({ focus: () => { activeElement = selector; } }),
            };
            editor.$dispatch = (name, detail) => { if (name === 'cuti-config-step') steps.push(detail.step); };
            editor.toggleTarget(target(1));
            await editor.goToStep('tinjau');
            const draft = editor.payload();
            const pending = editor.loadPreview();

            await editor.goToStep('susun');
            if (destination === 'tinjau') await editor.goToStep('tinjau');
            activeElement = 'isian-yang-sedang-dikerjakan';
            steps.length = 0;
            pendingResponse.resolve(response(payload, status));
            await pending;

            assert.equal(editor.step, destination);
            assert.equal(activeElement, 'isian-yang-sedang-dikerjakan');
            assert.deepEqual(steps, [], 'Respons terlambat tidak boleh mengubah tahap pada URL.');
            assert.deepEqual(editor.payload(), draft);
            assert.equal(editor.previewLoading, false);
            if (status === 200) assert.equal(editor.preview.preview_token, 'terbaru');
            else assert.equal(editor.fieldError('reason'), 'Alasan wajib diisi.');
        });
    }
}

test('panah lookup menggulir opsi aktif tanpa memindahkan fokus input', () => {
    const editor = createChainBatchEditor({});
    editor.pybmc.results = Array.from({ length: 15 }, (_, index) => target(index));
    let selector;
    let options;
    editor.$nextTick = (callback) => callback();
    editor.editorRoot = { querySelector: (value) => {
        selector = value;
        return { scrollIntoView: (value) => { options = value; }, focus: () => assert.fail('Fokus harus tetap pada input.') };
    } };
    for (let i = 0; i < 15; i++) editor.moveApprover(editor.pybmc, 1);
    assert.equal(selector, `#${editor.pybmc.key}-option-14`);
    assert.deepEqual(options, { block: 'nearest' });
});

test('422 mempertahankan draft dan pilihan serta membuka galat per field', async () => {
    const editor = ready(async () => response({ message: 'Tidak valid', errors: { reason: ['Alasan wajib diisi.'] } }, 422));
    editor.changeDraft('reason', 'abcd');
    await editor.loadPreview();
    assert.equal(editor.draft.reason, 'abcd');
    assert.equal(editor.selected.size, 1);
    assert.equal(editor.fieldError('reason'), 'Alasan wajib diisi.');
    assert.equal(editor.previewLoading, false);
});

test('batal modal mempertahankan draft preview dan counts modal dari server', () => {
    const editor = ready();
    editor.openConfirmation();
    assert.equal(editor.confirmOpen, true);
    assert.deepEqual(editor.summary(), { create: 1, replace: 0, unchanged: 2, skipped: 3 });
    editor.closeConfirmation();
    assert.equal(editor.confirmOpen, false);
    assert.equal(editor.selected.size, 1);
    assert.equal(editor.preview.preview_token, 'token');
});

test('klik konfirmasi ganda hanya satu POST dan hasil berasal dari server', async () => {
    const pendingResponse = deferred();
    let calls = 0;
    const editor = ready(() => { calls++; return pendingResponse.promise; });
    const ticks = [];
    let focused;
    let onStay;
    editor.$nextTick = (callback) => ticks.push(callback);
    editor.$dispatch = (name, detail) => { if (name === 'cuti-config-applied') onStay = detail.onStay; };
    editor.editorRoot = { querySelector: (selector) => ({ focus: () => { focused = selector; } }) };
    editor.$el = { querySelector: () => assert.fail('Scope modal tidak boleh menjadi root fokus.') };
    editor.openConfirmation();
    const pending = editor.apply();
    await editor.apply();
    assert.equal(calls, 1);
    pendingResponse.resolve(response({ data: { rows: [{ employee_id: '1', outcome: 'create' }], counts: { create: 1 } } }));
    await pending;
    assert.equal(editor.preview, null);
    assert.equal(editor.result.counts.create, 1);
    assert.equal(editor.selected.get('1').has_active_chain, true);
    assert.equal(editor.applying, false);
    assert.equal(focused, undefined);
    while (ticks.length) ticks.shift()();
    assert.equal(focused, undefined, 'Fokus hasil tidak boleh mencuri fokus modal konfirmasi draft lain.');
    onStay();
    while (ticks.length) ticks.shift()();
    assert.equal(focused, '#batch-review-heading');
});

test('sukses batch meminta navigasi hanya sesudah state tersimpan dan memakai counts final', async () => {
    const counts = { create: 2, replace: 1, unchanged: 3, skip_existing: 4, skip_inactive: 1, skip_supervisor: 2, skip_self_required: 0 };
    const editor = ready(async () => response({ data: { rows: [{ employee_id: '1', outcome: 'create' }], counts } }));
    const events = [];
    editor.$dispatch = (name, detail) => events.push({ name, detail });
    editor.openConfirmation();
    await editor.apply();

    const applied = events.findIndex((event) => event.name === 'cuti-config-applied');
    assert.ok(applied > 0, 'Sukses harus meminta navigasi halaman konfigurasi.');
    assert.deepEqual(events[applied - 1], { name: 'cuti-config-state', detail: { dirty: false, applying: false, notice: '' } });
    assert.deepEqual(events[applied].detail.counts, counts);
    assert.equal(typeof events[applied].detail.onStay, 'function');
    assert.equal(events.filter((event) => event.name === 'cuti-config-applied').length, 1);
    assert.equal(editor.result.counts.create, 2);
});

for (const [label, fetch] of [
    ['422', async () => response({ errors: { reason: ['Alasan wajib diisi.'] } }, 422)],
    ['403', async () => response({}, 403)],
    ['401', async () => response({}, 401)],
    ['500', async () => response({}, 500)],
    ['respons tidak terbaca', async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token'); } })],
    ['koneksi putus', async () => { throw new TypeError('Failed to fetch'); }],
]) {
    test(`apply ${label} tetap di editor tanpa toast sukses, navigasi, atau retry`, async () => {
        let calls = 0;
        const editor = ready((...args) => { calls++; return fetch(...args); });
        const events = [];
        editor.$dispatch = (name) => events.push(name);
        const draft = editor.payload();
        editor.openConfirmation();
        await editor.apply();
        await editor.apply();
        assert.equal(events.includes('cuti-config-applied'), false);
        assert.equal(events.includes('notify'), false);
        assert.deepEqual(editor.payload(), draft);
        assert.equal(editor.dirty, true);
        assert.equal(calls, 1);
    });
}

test('hasil apply tidak pasti membatalkan token tanpa retry atau menghapus input', async () => {
    let calls = 0;
    const editor = ready(async () => { calls++; throw new TypeError('Failed to fetch'); });
    editor.openConfirmation();
    await editor.apply();
    assert.equal(calls, 1);
    assert.equal(editor.preview, null);
    assert.equal(editor.selected.size, 1);
    assert.match(editor.error, /belum dapat dipastikan.*pratinjau/i);
});

for (const [status, expected] of [[401, /Salin isian yang masih ada di halaman ini/], [419, /Salin isian yang masih ada di halaman ini/], [403, /izin/], [429, /Terlalu banyak/]]) {
    test(`status ${status} dibedakan dan input tetap tersedia`, async () => {
        const editor = ready(async () => response({}, status));
        await editor.loadPreview();
        assert.match(editor.error, expected);
        assert.equal(editor.selected.size, 1);
        assert.equal(editor.previewLoading, false);
    });
}

test('lookup target approver preview dan apply tidak mengikuti redirect login', async () => {
    const requests = [];
    const editor = ready(async (url, options) => {
        requests.push({ url, options });
        return response({}, 401);
    });
    await editor.loadTargets();
    await editor.lookupApprover(editor.pybmc, 'Pegawai');
    await editor.loadPreview();
    editor.preview = { preview_token: 'token', can_apply: true, rows: [], counts: { create: 1 } };
    editor.openConfirmation();
    await editor.apply();
    assert.deepEqual(requests.map(({ url }) => url), ['/target?page=1', '/approver?q=Pegawai', '/pratinjau', '/terapkan']);
    requests.forEach(({ options }) => assert.equal(options.redirect, 'manual'));
});

for (const path of ['targets', 'approver', 'preview', 'apply']) {
    test(`redirect buram ${path} mempertahankan isian tanpa kirim ulang`, async () => {
        let calls = 0;
        const editor = ready(async () => {
            calls++;
            return { type: 'opaqueredirect', status: 0, ok: false, redirected: false, json: async () => { throw new SyntaxError('Empty body'); } };
        });
        editor.draft.reason = 'Alasan penerapan tetap tersedia';
        editor.pybmc.employeeId = 'pybmc-terpilih';
        editor.pybmc.query = 'Pegawai Terpilih';
        const initialPayload = editor.payload();
        if (path === 'targets') await editor.loadTargets();
        if (path === 'approver') await editor.lookupApprover(editor.pybmc);
        if (path === 'preview') await editor.loadPreview();
        if (path === 'apply') {
            editor.openConfirmation();
            await editor.apply();
            await editor.apply();
            assert.equal(editor.preview, null);
        }
        const message = path === 'targets' ? editor.pickerError : path === 'approver' ? editor.pybmc.error : editor.error;
        assert.match(message, /Sesi Anda telah berakhir.*Salin isian/);
        assert.deepEqual(editor.payload(), initialPayload);
        assert.equal(editor.pybmc.query, 'Pegawai Terpilih');
        assert.equal(calls, 1);
        assert.equal(editor.applying, false);
    });
}

test('respons non JSON dibedakan dari validasi dan sesi', async () => {
    const editor = ready(async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token'); } }));
    await editor.loadPreview();
    assert.match(editor.error, /Respons server tidak dapat dibaca/);
});
