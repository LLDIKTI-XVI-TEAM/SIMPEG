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
