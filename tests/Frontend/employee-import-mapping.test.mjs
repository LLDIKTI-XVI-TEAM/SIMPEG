import test from 'node:test';
import assert from 'node:assert/strict';

import {
    buildMappedRows,
    createInitialColumnMapping,
    duplicateMappedFields,
    knownIgnoredSourceHeaders,
    isLockedIgnoredHeader,
    missingMandatoryFields,
    normalizeImportHeader,
    unknownSourceHeaders,
} from '../../resources/js/employee-import-mapping.js';

const canonicalHeaders = [
    'No',
    'Nama Pegawai',
    'Email Pegawai',
    'Golongan',
    'Jabatan',
    'Kelas Jabatan',
    'NIP',
    'Nomor Telepon',
    'Pangkat',
    'Pendidikan Terakhir',
    'Pensiun',
    'Person',
    'Person Formula',
    'Prodi Pendidikan Terakhir',
    'Status Kepegawaian',
    'Tanggal Lahir',
    'Role',
];

test('canonical template maps without duplicate targets', () => {
    const mapping = createInitialColumnMapping(canonicalHeaders);

    assert.equal(mapping.Jabatan, 'Jabatan');
    assert.equal(mapping['Kelas Jabatan'], 'Kelas Jabatan');
    assert.equal(mapping['Pendidikan Terakhir'], 'Pendidikan Terakhir');
    assert.equal(mapping['Prodi Pendidikan Terakhir'], 'Prodi Pendidikan Terakhir');
    assert.equal(mapping['Person Formula'], 'skip');
    assert.equal(mapping.Role, 'skip');
    assert.equal(mapping.No, 'skip');
    assert.deepEqual(duplicateMappedFields(mapping), []);
    assert.deepEqual(missingMandatoryFields(mapping), []);
});

test('header normalization supports spacing, case, and safe aliases', () => {
    const headers = [' Nama Dengan Gelar ', 'E-mail', 'Tgl Lahir', 'No. HP'];
    const mapping = createInitialColumnMapping(headers);

    assert.equal(normalizeImportHeader('Tgl-Lahir'), 'tgllahir');
    assert.equal(mapping[' Nama Dengan Gelar '], 'Nama Pegawai');
    assert.equal(mapping['E-mail'], 'Email Pegawai');
    assert.equal(mapping['Tgl Lahir'], 'Tanggal Lahir');
    assert.equal(mapping['No. HP'], 'Nomor Telepon');
});

test('manual duplicate mapping and missing required targets are reported', () => {
    const mapping = createInitialColumnMapping(canonicalHeaders);
    mapping['Kelas Jabatan'] = 'Jabatan';
    mapping.Golongan = 'skip';

    assert.deepEqual(duplicateMappedFields(mapping), ['Jabatan']);
    assert.deepEqual(missingMandatoryFields(mapping), ['Golongan', 'Kelas Jabatan']);
});

test('known ignored and unknown headers are distinguished', () => {
    const headers = [...canonicalHeaders, 'Kode Internal Baru'];
    const mapping = createInitialColumnMapping(headers);

    assert.deepEqual(knownIgnoredSourceHeaders(headers, mapping), ['No', 'Person Formula', 'Role']);
    assert.deepEqual(unknownSourceHeaders(headers, mapping), ['Kode Internal Baru']);
    assert.equal(isLockedIgnoredHeader('No'), true);
    assert.equal(isLockedIgnoredHeader('role'), true);
    assert.equal(isLockedIgnoredHeader('Person Formula'), false);
});

test('mapped rows use selected targets and never use Role value from source file', () => {
    const rows = [{
        row: 2,
        data: {
            'Nama Lengkap Bergelar': 'Adithian Gunawan, S.Kom.',
            'Surel Pegawai': 'adithian@example.test',
            Role: 'super_admin',
            'Kolom Tidak Dipakai': 'rahasia',
        },
    }];
    const mapping = {
        'Nama Lengkap Bergelar': 'Nama Pegawai',
        'Surel Pegawai': 'Email Pegawai',
        Role: 'skip',
        'Kolom Tidak Dipakai': 'skip',
    };

    const [mappedRow] = buildMappedRows(rows, mapping, { Role: 'pegawai' });

    assert.deepEqual(mappedRow, {
        row: 2,
        data: {
            'Nama Pegawai': 'Adithian Gunawan, S.Kom.',
            'Email Pegawai': 'adithian@example.test',
            Role: 'pegawai',
        },
    });
});
