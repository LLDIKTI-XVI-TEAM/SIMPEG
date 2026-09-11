import assert from 'node:assert/strict';
import test from 'node:test';

let auditLogPageFactory;

globalThis.window = {
    Alpine: {
        data(name, factory) {
            if (name === 'auditLogPage') auditLogPageFactory = factory;
        },
    },
};
globalThis.document = { addEventListener() {} };

await import('../../resources/js/pages/audit-log.js');

test('payload audit diparse dari atribut data, termasuk karakter khusus', () => {
    const logs = [{
        id: 'audit-1',
        event: 'UPDATE',
        modul: 'Employee',
        record_id: 'pegawai-1',
        new_values: { catatan: "Perubahan 'khusus' <valid>" },
    }];
    const page = auditLogPageFactory({ dataset: { auditLogs: JSON.stringify(logs) } });

    assert.deepEqual(page.logs, logs);
    assert.equal(page.getRingkasan(logs[0]), 'UPDATE: Mengubah catatan');
});

test('payload audit yang tidak valid tidak merusak state halaman', () => {
    const page = auditLogPageFactory({ dataset: { auditLogs: '{invalid-json' } });

    assert.deepEqual(page.logs, []);
    assert.deepEqual(page.selectedLog, {});
});
