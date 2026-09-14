import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/views/admin/cuti/form-pengajuan.blade.php', import.meta.url),
    'utf8',
);

const methodSource = (methodName, nextMethodName) => {
    const start = source.indexOf(`                ${methodName}()`);
    const end = source.indexOf(nextMethodName
        ? `                ${nextMethodName}()`
        : '            }));', start);

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

const dateForm = () => Object.assign(
    Function(`return ({
        ${methodSource('onStartDateChanged', 'async refreshBalance')},
        ${methodSource('validateSaldo', 'async calculateDays')},
        ${methodSource('async calculateDays')},
        ${methodSource('selectedLeaveTypeCode', 'requiresLeaveCase')}
    });`)(),
    {
        startDate: '2026-09-12', endDate: '2026-09-13', workDays: 0,
        workdayWarnings: [], workdayError: '', workdayValidationError: '',
        isCalculating: false, workdayRequestId: 0, dateInputsChanged: false,
        selectedJenisCuti: 'annual_id', leaveTypeCodes: { annual_id: 'tahunan' },
        balance: { saldo_dapat_diajukan: 24 }, saldoError: false, saldoErrorMsg: '',
        refreshBalance: async () => {},
    },
);

const workdayResponse = (days) => ({
    ok: true,
    json: async () => ({ data: { jumlah_hari_kerja: days, warnings: [] } }),
});

test('rentang tanpa hari kerja menampilkan alasan dan menahan kirim untuk semua jenis cuti', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => workdayResponse(0));
    for (const code of ['tahunan', 'sakit']) {
        const form = dateForm();
        form.leaveTypeCodes.annual_id = code;
        await form.calculateDays();

        assert.equal(form.workDays, 0);
        assert.match(form.workdayValidationError, /tidak memiliki hari kerja/);
        assert.equal(form.isSubmissionBlocked(), true);
    }
});

test('tanggal terbalik memberi pesan Indonesia tanpa memanggil kalkulator', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => assert.fail('Rentang terbalik tidak dikirim ke kalkulator.'));
    const form = dateForm();
    form.startDate = '2026-09-18';
    form.endDate = '2026-09-14';
    await form.calculateDays();

    assert.match(form.workdayValidationError, /Tanggal selesai.*tanggal mulai/);
    assert.equal(form.isSubmissionBlocked(), true);
});

test('kirim ditahan selama hitungan baru dan dibuka setelah rentang valid', async (t) => {
    let finish;
    t.mock.method(globalThis, 'fetch', () => new Promise((resolve) => { finish = resolve; }));
    const form = dateForm();
    form.workDays = 5;
    const pending = form.calculateDays();

    assert.equal(form.isSubmissionBlocked(), true);
    finish(workdayResponse(1));
    await pending;
    assert.equal(form.workDays, 1);
    assert.equal(form.workdayValidationError, '');
    assert.equal(form.isSubmissionBlocked(), false);
});

test('mengubah tanggal membuang error tanggal lama tetapi kalkulasi saat init tidak', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => workdayResponse(1));
    const form = dateForm();
    await form.calculateDays();
    assert.equal(form.dateInputsChanged, false);

    await form.onStartDateChanged();
    assert.equal(form.dateInputsChanged, true);
    form.dateInputsChanged = false;
    await form.onEndDateChanged();
    assert.equal(form.dateInputsChanged, true);
});

test('respons nol yang terlambat tidak menimpa hasil tanggal baru', async (t) => {
    const pending = [];
    t.mock.method(globalThis, 'fetch', () => new Promise((resolve) => pending.push(resolve)));
    const form = dateForm();
    const oldRequest = form.calculateDays();
    form.startDate = '2026-09-14';
    form.endDate = '2026-09-18';
    const newRequest = form.calculateDays();

    pending[1](workdayResponse(5));
    await newRequest;
    pending[0](workdayResponse(0));
    await oldRequest;
    assert.equal(form.workDays, 5);
    assert.equal(form.workdayValidationError, '');
    assert.equal(form.isSubmissionBlocked(), false);
});

test('mengosongkan tanggal menghapus pesan nol yang sudah tidak relevan', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => workdayResponse(0));
    const form = dateForm();
    await form.calculateDays();
    form.endDate = '';
    await form.onEndDateChanged();

    assert.equal(form.workdayValidationError, '');
    assert.equal(form.workdayError, '');
    assert.equal(form.isCalculating, false);
});

test('kegagalan kalkulator mempertahankan fallback validasi backend', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => { throw new Error('Jaringan terputus'); });
    const form = dateForm();
    await form.calculateDays();

    assert.notEqual(form.workdayError, '');
    assert.equal(form.workdayValidationError, '');
    assert.equal(form.isSubmissionBlocked(), false);
});
