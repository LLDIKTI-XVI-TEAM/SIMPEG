export const SKIP_MAPPING = 'skip';

export const IMPORT_TARGET_FIELDS = Object.freeze([
    { key: 'Nama Pegawai', label: 'Nama Pegawai (dengan Gelar)' },
    { key: 'Person', label: 'Nama Lengkap (Person / tanpa Gelar)' },
    { key: 'Email Pegawai', label: 'Email Pegawai' },
    { key: 'NIP', label: 'NIP' },
    { key: 'NIK', label: 'NIK' },
    { key: 'No KK', label: 'No KK' },
    { key: 'Golongan', label: 'Golongan' },
    { key: 'Jabatan', label: 'Jabatan' },
    { key: 'Kelas Jabatan', label: 'Kelas Jabatan' },
    { key: 'Pangkat', label: 'Pangkat' },
    { key: 'Nomor Telepon', label: 'Nomor Telepon' },
    { key: 'Tanggal Lahir', label: 'Tanggal Lahir' },
    { key: 'Status Kepegawaian', label: 'Status Kepegawaian (PNS/CPNS/PPPK)' },
    { key: 'Pensiun', label: 'Tanggal Pensiun' },
    { key: 'Pendidikan Terakhir', label: 'Pendidikan Terakhir' },
    { key: 'Prodi Pendidikan Terakhir', label: 'Prodi Pendidikan Terakhir' },
    { key: SKIP_MAPPING, label: 'Tidak dipakai / Skip (nilai diabaikan)' },
]);

// Field yang wajib memiliki kolom sumber sebelum validasi menurut US-3.2.
// NIK, No KK, Person, Pangkat, dan Pensiun tetap opsional pada import awal.
export const REQUIRED_IMPORT_TARGETS = Object.freeze([
    'Nama Pegawai',
    'Email Pegawai',
    'Golongan',
    'Jabatan',
    'Kelas Jabatan',
    'NIP',
    'Nomor Telepon',
    'Pendidikan Terakhir',
    'Prodi Pendidikan Terakhir',
    'Status Kepegawaian',
    'Tanggal Lahir',
]);

const KNOWN_IGNORED_HEADERS = new Set([
    'no',
    'personformula',
    'role',
]);

const LOCKED_IGNORED_HEADERS = new Set([
    'no',
    'role',
]);

const NORMALIZED_HEADER_TARGETS = new Map([
    ['namapegawai', 'Nama Pegawai'],
    ['namadengangelar', 'Nama Pegawai'],
    ['person', 'Person'],
    ['namalengkap', 'Person'],
    ['emailpegawai', 'Email Pegawai'],
    ['email', 'Email Pegawai'],
    ['nip', 'NIP'],
    ['nik', 'NIK'],
    ['nokk', 'No KK'],
    ['nomorkk', 'No KK'],
    ['kk', 'No KK'],
    ['golongan', 'Golongan'],
    ['gol', 'Golongan'],
    ['jabatan', 'Jabatan'],
    ['kelasjabatan', 'Kelas Jabatan'],
    ['pangkat', 'Pangkat'],
    ['nomortelepon', 'Nomor Telepon'],
    ['nomorhp', 'Nomor Telepon'],
    ['nohp', 'Nomor Telepon'],
    ['telepon', 'Nomor Telepon'],
    ['hp', 'Nomor Telepon'],
    ['tanggallahir', 'Tanggal Lahir'],
    ['tgllahir', 'Tanggal Lahir'],
    ['statuskepegawaian', 'Status Kepegawaian'],
    ['statuspegawai', 'Status Kepegawaian'],
    ['jenispegawai', 'Status Kepegawaian'],
    ['pensiun', 'Pensiun'],
    ['tanggalpensiun', 'Pensiun'],
    ['tglpensiun', 'Pensiun'],
    ['pendidikanterakhir', 'Pendidikan Terakhir'],
    ['prodipendidikanterakhir', 'Prodi Pendidikan Terakhir'],
    ['programstudipendidikanterakhir', 'Prodi Pendidikan Terakhir'],
    ['prodi', 'Prodi Pendidikan Terakhir'],
]);

export function normalizeImportHeader(header) {
    return String(header ?? '')
        .trim()
        .toLocaleLowerCase('id-ID')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]/g, '');
}

export function isKnownIgnoredHeader(header) {
    return KNOWN_IGNORED_HEADERS.has(normalizeImportHeader(header));
}

export function isLockedIgnoredHeader(header) {
    return LOCKED_IGNORED_HEADERS.has(normalizeImportHeader(header));
}

export function createInitialColumnMapping(headers = []) {
    return Object.fromEntries(headers.map((header) => {
        const normalized = normalizeImportHeader(header);
        const target = KNOWN_IGNORED_HEADERS.has(normalized)
            ? SKIP_MAPPING
            : (NORMALIZED_HEADER_TARGETS.get(normalized) ?? SKIP_MAPPING);

        return [header, target];
    }));
}

export function duplicateMappedFields(mapping = {}) {
    const counts = {};

    Object.values(mapping).forEach((target) => {
        if (target && target !== SKIP_MAPPING) {
            counts[target] = (counts[target] ?? 0) + 1;
        }
    });

    return Object.keys(counts).filter((target) => counts[target] > 1);
}

export function missingMandatoryFields(mapping = {}) {
    const mappedTargets = new Set(Object.values(mapping));

    return REQUIRED_IMPORT_TARGETS.filter((target) => !mappedTargets.has(target));
}

export function skippedSourceHeaders(headers = [], mapping = {}) {
    return headers.filter((header) => !mapping[header] || mapping[header] === SKIP_MAPPING);
}

export function unknownSourceHeaders(headers = [], mapping = {}) {
    return skippedSourceHeaders(headers, mapping).filter((header) => !isKnownIgnoredHeader(header));
}

export function knownIgnoredSourceHeaders(headers = [], mapping = {}) {
    return skippedSourceHeaders(headers, mapping).filter((header) => isKnownIgnoredHeader(header));
}

export function sourceHeadersForTargets(mapping = {}, targets = []) {
    const targetSet = new Set(targets);

    return Object.entries(mapping)
        .filter(([, target]) => targetSet.has(target))
        .map(([source]) => source);
}

export function buildMappedRows(rows = [], mapping = {}, compatibilityDefaults = {}) {
    return rows.map((row) => {
        const mappedData = {};

        Object.entries(mapping).forEach(([sourceHeader, targetField]) => {
            if (!targetField || targetField === SKIP_MAPPING) {
                return;
            }

            mappedData[targetField] = row?.data?.[sourceHeader] ?? null;
        });

        Object.entries(compatibilityDefaults).forEach(([field, value]) => {
            mappedData[field] = value;
        });

        return {
            row: Number(row.row),
            data: mappedData,
        };
    });
}

export function mappingControlId(header, index = 0) {
    const slug = normalizeImportHeader(header) || 'kolom';

    return `mapping-${index}-${slug}`;
}
