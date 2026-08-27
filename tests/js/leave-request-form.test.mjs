import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/views/admin/cuti/form-pengajuan.blade.php', import.meta.url),
    'utf8',
);

const methodSource = (methodName, nextMethodName) => {
    const start = source.indexOf(`                ${methodName}()`);
    const end = source.indexOf(`                ${nextMethodName}()`, start);

    assert.notEqual(start, -1, `Method ${methodName} harus tersedia pada komponen form.`);
    assert.notEqual(end, -1, `Batas method ${methodName} harus dapat ditemukan.`);

    return source.slice(start, end).trim().replace(/,$/, '');
};

const component = () => {
    const selectedLeaveTypeCode = methodSource('selectedLeaveTypeCode', 'requiresLeaveCase');
    const validateSaldo = methodSource('validateSaldo', 'async calculateDays');

    return Function(`return ({ ${selectedLeaveTypeCode}, ${validateSaldo} });`)();
};

test('validasi saldo form memakai code tahunan walaupun flag option berbeda', () => {
    globalThis.document = {
        getElementById: () => ({
            selectedIndex: 1,
            options: [null, {
                getAttribute: (attribute) => attribute === 'data-mengurangi-saldo-tahunan' ? 'false' : null,
            }],
        }),
    };
    const form = Object.assign(component(), {
        leaveTypeCodes: { annual_id: 'tahunan' },
        selectedJenisCuti: 'annual_id',
        workDays: 3,
        balance: { saldo_dapat_diajukan: 2 },
        saldoError: false,
        saldoErrorMsg: '',
    });

    form.validateSaldo();

    assert.equal(form.saldoError, true);
    assert.match(form.saldoErrorMsg, /Saldo cuti tahunan tidak mencukupi/);
});

test('validasi saldo form mengabaikan flag option true untuk code non-tahunan', () => {
    globalThis.document = {
        getElementById: () => ({
            selectedIndex: 1,
            options: [null, {
                getAttribute: (attribute) => attribute === 'data-mengurangi-saldo-tahunan' ? 'true' : null,
            }],
        }),
    };
    const form = Object.assign(component(), {
        leaveTypeCodes: { sick_id: 'sakit' },
        selectedJenisCuti: 'sick_id',
        workDays: 3,
        balance: { saldo_dapat_diajukan: 2 },
        saldoError: false,
        saldoErrorMsg: '',
    });

    form.validateSaldo();

    assert.equal(form.saldoError, false);
    assert.equal(form.saldoErrorMsg, '');
});
