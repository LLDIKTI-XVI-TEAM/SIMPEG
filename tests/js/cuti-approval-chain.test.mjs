import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const bladeSource = await readFile(
    new URL('../../resources/views/admin/cuti/konfigurasi.blade.php', import.meta.url),
    'utf8',
);

const focusVerifierBody = bladeSource.match(
    /focusVerifier\(clientKey\)\s*\{([\s\S]*?)\n\s*\},\n\s*announce\(/,
)?.[1];

const searchApproverCandidatesBody = bladeSource.match(
    /async searchApproverCandidates\(\)\s*\{([\s\S]*?)\n\s*\},\n\s*addVerifier\(/,
)?.[1];
const invalidateApproverSearchBody = bladeSource.match(
    /invalidateApproverSearch\(\)\s*\{([\s\S]*?)\n\s*\},\n\s*async searchApproverCandidates\(/,
)?.[1];

assert.ok(focusVerifierBody, 'Method focusVerifier harus dapat dieksekusi oleh test regresi.');

test('fokus verifikator memakai dokumen browser saat root magic Alpine tidak tersedia', () => {
    let focused = false;
    const label = { focus: () => { focused = true; } };
    const row = {
        querySelector(selector) {
            assert.equal(selector, '[data-verifier-label]');

            return label;
        },
    };

    globalThis.document = {
        querySelector(selector) {
            assert.equal(selector, '[data-verifier-key=existing-0]');

            return row;
        },
    };

    const focusVerifier = new Function('clientKey', focusVerifierBody);

    focusVerifier.call({}, 'existing-0');

    assert.equal(focused, true);
});

test('pencarian kandidat mempertahankan pilihan editor yang belum disimpan', async () => {
    assert.ok(
        searchApproverCandidatesBody,
        'Method searchApproverCandidates harus tersedia untuk pencarian tanpa reload.',
    );

    const originalFetch = globalThis.fetch;
    const verifier = {
        role_label: 'Pemeriksa awal',
        approver_employee_id: 'selected-dynamic',
        client_key: 'existing-0',
        validation_errors: { role_label: '', approver_employee_id: '' },
    };
    const context = {
        approverLookupEndpoint: '/cuti/lookup-pegawai',
        approverSearch: 'Pegawai Baru',
        approverSearchLoading: false,
        approverSearchSequence: 0,
        invalidateApproverSearch: new Function(invalidateApproverSearchBody),
        approverSearchError: '',
        initialApproverCandidateIds: ['static-id'],
        approverLookupCandidates: [
            { id: 'selected-dynamic', nama_lengkap: 'Pilihan Belum Disimpan', nip: '100' },
        ],
        verifiers: [verifier],
        pybmcEmployeeId: 'static-id',
    };

    try {
        globalThis.fetch = async () => ({
            ok: true,
            json: async () => ({
                data: [
                    { id: 'static-id', nama_lengkap: 'Kandidat Awal', nip: '200' },
                    { id: 'new-id', nama_lengkap: 'Pegawai Baru', nip: '300' },
                ],
            }),
        });

        const searchApproverCandidates = new Function(
            `return async function () {${searchApproverCandidatesBody}}`,
        )();

        await searchApproverCandidates.call(context);

        assert.deepEqual(context.verifiers, [verifier]);
        assert.equal(context.pybmcEmployeeId, 'static-id');
        assert.deepEqual(
            context.approverLookupCandidates.map((candidate) => candidate.id),
            ['selected-dynamic', 'new-id'],
        );
        assert.equal(context.approverSearchLoading, false);
        assert.equal(context.approverSearchError, '');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
};
const candidateResponse = (id) => ({
    ok: true,
    json: async () => ({ data: [{ id, nama_lengkap: id, nip: '100' }] }),
});
const lookupContext = (fetch) => ({
    approverLookupEndpoint: '/cuti/lookup-pegawai',
    approverSearch: 'Andi', approverSearchSequence: 0,
    approverSearchLoading: false, approverSearchError: '', approverSearchMessage: '',
    initialApproverCandidateIds: [], approverLookupCandidates: [], verifiers: [], pybmcEmployeeId: '',
    invalidateApproverSearch: new Function(invalidateApproverSearchBody),
    searchApproverCandidates: new Function('fetch', `return async function () {${searchApproverCandidatesBody}}`)(fetch),
});

test('respons Andi yang datang setelah Budi tidak menimpa hasil pencarian Budi', async () => {
    const first = deferred();
    const page = lookupContext((url) => url.endsWith('Andi') ? first.promise : candidateResponse('Budi'));
    const pending = page.searchApproverCandidates();
    page.approverSearch = 'Budi';
    await page.searchApproverCandidates();
    first.resolve(candidateResponse('Andi'));
    await pending;
    assert.deepEqual(page.approverLookupCandidates.map(({ id }) => id), ['Budi']);
    assert.equal(page.approverSearchMessage, '1 kandidat ditemukan.');
    assert.equal(page.approverSearchError, '');
    assert.equal(page.approverSearchLoading, false);
});

test('galat lama tidak mengakhiri loading atau menampilkan error pada pencarian terbaru', async () => {
    const first = deferred();
    const second = deferred();
    const page = lookupContext((url) => url.endsWith('Andi') ? first.promise : second.promise);
    const pending = page.searchApproverCandidates();
    page.approverSearch = 'Budi';
    const latest = page.searchApproverCandidates();
    first.reject(new TypeError('Koneksi lama gagal'));
    await pending;
    assert.equal(page.approverSearchError, '');
    assert.equal(page.approverSearchLoading, true);
    second.resolve(candidateResponse('Budi'));
    await latest;
    assert.deepEqual(page.approverLookupCandidates.map(({ id }) => id), ['Budi']);
});

test('pencarian terlalu pendek membatalkan respons sebelumnya', async () => {
    const first = deferred();
    const page = lookupContext(() => first.promise);
    const pending = page.searchApproverCandidates();
    page.approverSearch = '';
    await page.searchApproverCandidates();
    first.resolve(candidateResponse('Andi'));
    await pending;
    assert.deepEqual(page.approverLookupCandidates, []);
    assert.equal(page.approverSearchMessage, '');
    assert.equal(page.approverSearchLoading, false);
    assert.match(page.approverSearchError, /minimal 2 karakter/);
});

test('mengedit teks sebelum submit baru membuang hasil usang tetapi menjaga kandidat terpilih', async () => {
    const first = deferred();
    const page = lookupContext(() => first.promise);
    page.verifiers = [{ approver_employee_id: 'terpilih' }];
    page.approverLookupCandidates = [{ id: 'terpilih' }, { id: 'belum-dipilih' }];
    const pending = page.searchApproverCandidates();
    page.approverSearch = 'Budi';
    page.invalidateApproverSearch();
    first.resolve(candidateResponse('Andi'));
    await pending;
    assert.deepEqual(page.approverLookupCandidates, [{ id: 'terpilih' }]);
    assert.equal(page.verifiers[0].approver_employee_id, 'terpilih');
    assert.equal(page.approverSearchMessage, '');
    assert.equal(page.approverSearchLoading, false);
});

test('galat pencarian terbaru tetap terlihat dan dapat dicoba ulang', async () => {
    let fail = true;
    const page = lookupContext(async () => {
        if (fail) throw new TypeError('Koneksi gagal');
        return candidateResponse('Andi');
    });
    await page.searchApproverCandidates();
    assert.match(page.approverSearchError, /gagal/);
    assert.equal(page.approverSearchLoading, false);
    fail = false;
    await page.searchApproverCandidates();
    assert.equal(page.approverSearchError, '');
    assert.deepEqual(page.approverLookupCandidates.map(({ id }) => id), ['Andi']);
});
