<?php

namespace App\Http\Controllers;

use App\Actions\Employees\ImportEmployeesAction;
use App\Actions\Histories\CreateKgbHistoryAction;
use App\Actions\Histories\CreatePositionHistoryAction;
use App\Actions\Histories\CreateRankHistoryAction;
use App\Http\Requests\ImportEmployeesRequest;
use App\Models\Employee;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Services\AuditService;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeImport\EmployeeRowMapper;
use App\Support\EmployeeValidationRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class EmployeeImportController extends Controller
{
    private const CACHE_PREFIX = 'import_batch:';

    private const CACHE_TTL_MINUTES = 30;

    private const STORAGE_DIR = 'imports';

    private const TEMPLATE_HEADERS = [
        'utama' => EmployeeRowMapper::HEADERS,
        'pelengkap' => ['NIP', 'NIK', 'No KK', 'Tempat Lahir', 'Jenis Kelamin', 'Agama', 'Status Kawin', 'Golongan Darah'],
        'kepangkatan' => ['NIP', 'Golongan', 'TMT Pangkat', 'No SK', 'Tanggal SK'],
        'jabatan' => ['NIP', 'Nama Jabatan', 'Jenis Jabatan', 'Unit Kerja', 'TMT Jabatan', 'No SK', 'Tanggal SK'],
        'kgb' => ['NIP', 'TMT KGB', 'Gaji Pokok', 'No SK', 'Tanggal SK'],
    ];

    private const TEMPLATE_LABELS = [
        'utama' => 'Data Utama',
        'pelengkap' => 'Data Pelengkap',
        'kepangkatan' => 'Riwayat Pangkat',
        'jabatan' => 'Riwayat Jabatan',
        'kgb' => 'Riwayat KGB',
    ];

    private ?array $jenisPegawaiCache = null;

    public function store(ImportEmployeesRequest $request, ImportEmployeesAction $action): JsonResponse|RedirectResponse
    {
        $summary = $action->execute($request);

        if ($request->expectsJson()) {
            return response()->json($summary, $summary['failed'] > 0 ? 422 : 200);
        }

        if ($summary['failed'] > 0) {
            return back()
                ->withErrors(['file' => $summary['message']])
                ->with('import_summary', $summary);
        }

        return back()->with('import_summary', $summary);
    }

    public function upload(ImportEmployeesRequest $request, CsvEmployeeReader $reader): JsonResponse
    {
        try {
            $rows = $reader->readRaw($request->file('file'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['File tidak berisi data import (hanya header).'],
            ]);
        }

        $firstRowData = $rows[0]['data'] ?? [];
        $headers = array_keys($firstRowData);
        $type = $this->detectTemplateType($headers, $request->input('type'));
        $batchId = (string) Str::uuid();

        $request->file('file')->storeAs(self::STORAGE_DIR, $batchId.'_'.$request->file('file')->getClientOriginalName(), 'local');

        Cache::put(self::CACHE_PREFIX.$batchId, [
            'filename' => $request->file('file')->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
            'user_id' => $request->user()?->id,
            'type' => $type,
            'type_label' => self::TEMPLATE_LABELS[$type],
            'headers' => $headers,
            'total_rows' => count($rows),
            'rows' => $rows,
            'validation' => null,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return response()->json([
            'batch_id' => $batchId,
            'filename' => $request->file('file')->getClientOriginalName(),
            'type' => $type,
            'type_label' => self::TEMPLATE_LABELS[$type],
            'total_rows' => count($rows),
            'headers' => $headers,
        ]);
    }

    public function preview(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        return response()->json([
            'batch_id' => $batchId,
            'filename' => $batch['filename'],
            'type' => $batch['type'] ?? 'utama',
            'type_label' => $batch['type_label'] ?? self::TEMPLATE_LABELS['utama'],
            'total_rows' => $batch['total_rows'],
            'headers' => $batch['headers'],
            'rows' => $batch['rows'],
        ]);
    }

    public function validate(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        if ($request->has('rows')) {
            $validatedRequest = $request->validate([
                'rows' => ['required', 'array'],
                'rows.*.row' => ['required', 'integer', 'min:2'],
                'rows.*.data' => ['required', 'array'],
            ]);
            $batch['rows'] = $validatedRequest['rows'];
            $batch['total_rows'] = count($validatedRequest['rows']);
            Cache::put(self::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }

        $type = $batch['type'] ?? 'utama';
        $results = [];
        $validCount = 0;
        $errorCount = 0;
        $skipCount = 0;
        $seenNips = [];
        $seenEmails = [];

        foreach ($batch['rows'] as $row) {
            $rowResult = $this->validateTemplateRow($type, $row, $seenNips, $seenEmails);
            $results[] = $rowResult;

            match ($rowResult['status']) {
                'valid' => $validCount++,
                'error' => $errorCount++,
                'skip' => $skipCount++,
            };
        }

        $batch['validation'] = [
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'skip_count' => $skipCount,
            'results' => $results,
        ];
        Cache::put(self::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return response()->json([
            'batch_id' => $batchId,
            'type' => $type,
            'type_label' => self::TEMPLATE_LABELS[$type] ?? self::TEMPLATE_LABELS['utama'],
            'total_rows' => $batch['total_rows'],
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'skip_count' => $skipCount,
            'results' => $results,
        ]);
    }

    public function execute(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        if ($batch['validation'] === null) {
            return response()->json([
                'message' => 'Data belum divalidasi. Jalankan validasi terlebih dahulu.',
            ], 422);
        }

        $type = $batch['type'] ?? 'utama';
        $validRows = array_values(array_filter(
            $batch['validation']['results'],
            fn (array $result) => $result['status'] === 'valid' && isset($result['validated_data']),
        ));
        $processedCount = 0;

        if ($validRows !== []) {
            DB::transaction(function () use ($validRows, $type, $request, &$processedCount): void {
                foreach ($validRows as $result) {
                    $this->executeValidatedRow($type, $result['validated_data'], $request);
                    $processedCount++;
                }
            });
        }

        AuditService::log('IMPORT', 'Employee', null, null, [
            'template_type' => $type,
            'template_label' => self::TEMPLATE_LABELS[$type] ?? self::TEMPLATE_LABELS['utama'],
            'total_inserted' => $type === 'utama' ? $processedCount : 0,
            'total_processed' => $processedCount,
            'total_skipped' => $batch['validation']['skip_count'],
            'total_failed' => $batch['validation']['error_count'],
            'filename' => $batch['filename'],
        ], $request);

        $this->cleanupBatch($batchId, $batch['filename']);

        return response()->json([
            'message' => 'Import selesai.',
            'inserted' => $processedCount,
            'processed' => $processedCount,
            'skipped' => $batch['validation']['skip_count'],
            'failed' => $batch['validation']['error_count'],
        ]);
    }

    private function getBatchOrFail(string $batchId, Request $request): array
    {
        $batch = Cache::get(self::CACHE_PREFIX.$batchId);

        if ($batch === null) {
            abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
        }

        if ($batch['user_id'] !== null && $batch['user_id'] !== $request->user()?->id) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        return $batch;
    }

    private function detectTemplateType(array $headers, mixed $requestedType): string
    {
        $requestedType = is_string($requestedType) ? trim($requestedType) : null;

        if ($requestedType !== null && isset(self::TEMPLATE_HEADERS[$requestedType])) {
            $missing = array_values(array_diff(self::TEMPLATE_HEADERS[$requestedType], $headers));

            if ($missing === []) {
                return $requestedType;
            }
        }

        foreach (self::TEMPLATE_HEADERS as $type => $requiredHeaders) {
            if (array_values(array_diff($requiredHeaders, $headers)) === []) {
                return $type;
            }
        }

        throw ValidationException::withMessages([
            'file' => ['Header template tidak dikenali. Gunakan template utama, pelengkap, riwayat pangkat, riwayat jabatan, atau riwayat KGB dari halaman import.'],
        ]);
    }

    private function validateTemplateRow(string $type, array $row, array &$seenNips, array &$seenEmails): array
    {
        return match ($type) {
            'pelengkap' => $this->validateSupplementalRow($row, $seenNips),
            'kepangkatan' => $this->validateRankHistoryRow($row),
            'jabatan' => $this->validatePositionHistoryRow($row),
            'kgb' => $this->validateKgbHistoryRow($row),
            default => $this->validateRow($row, $seenNips, $seenEmails),
        };
    }

    private function executeValidatedRow(string $type, array $data, Request $request): void
    {
        if ($type === 'utama') {
            Employee::create($data + [
                'status_aktif' => 'Aktif',
                'profil_status' => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ]);

            return;
        }

        $employee = Employee::findOrFail($data['employee_id']);
        unset($data['employee_id']);

        match ($type) {
            'pelengkap' => $employee->update($data),
            'kepangkatan' => app(CreateRankHistoryAction::class)->execute($employee, $data, $request),
            'jabatan' => app(CreatePositionHistoryAction::class)->execute($employee, $data, $request),
            'kgb' => app(CreateKgbHistoryAction::class)->execute($employee, $data, $request),
            default => null,
        };
    }

    private function validateRow(array $row, array &$seenNips, array &$seenEmails): array
    {
        $data = $row['data'];
        $nama = $data['Nama Pegawai'] ?? '-';
        $mappedData = app(EmployeeRowMapper::class)->map($data);
        $validator = Validator::make($mappedData, EmployeeValidationRules::import(), [], EmployeeValidationRules::attributes());

        if ($validator->fails()) {
            return $this->rowError($row, $nama, $this->mapErrors($validator->errors()->toArray(), [
                'nama_lengkap' => 'Nama Pegawai',
                'email' => 'Email Pegawai',
                'golongan_terakhir' => 'Golongan',
                'jabatan_terakhir' => 'Jabatan',
                'kelas_jabatan' => 'Kelas Jabatan',
                'nip' => 'NIP',
                'nik' => 'NIK',
                'no_kk' => 'No KK',
                'no_hp' => 'Nomor Telepon',
                'pangkat_terakhir' => 'Pangkat',
                'pendidikan_terakhir' => 'Pendidikan Terakhir',
                'tanggal_pensiun' => 'Pensiun',
                'prodi_pendidikan_terakhir' => 'Prodi Pendidikan Terakhir',
                'jenis_pegawai' => 'Status Kepegawaian',
                'tanggal_lahir' => 'Tanggal Lahir',
                'role' => 'Role',
            ]));
        }

        $validated = $validator->validated();
        $referenceErrors = $this->resolveReferences($validated);
        $skipErrors = [];

        if (! empty($validated['nip']) && Employee::where('nip', $validated['nip'])->exists()) {
            $skipErrors['NIP'][] = 'NIP sudah terdaftar di database.';
        }

        $databaseErrors = [];
        if (! empty($validated['email']) && Employee::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->exists()) {
            $databaseErrors['Email Pegawai'][] = 'Email pegawai sudah terdaftar di database.';
        }

        $duplicateErrors = $this->mapErrors($this->duplicateErrors($validated, $row['row'], $seenNips, $seenEmails), [
            'nip' => 'NIP',
            'email' => 'Email Pegawai',
        ]);

        if ($skipErrors !== []) {
            return [
                'row' => $row['row'],
                'nama' => $nama,
                'status' => 'skip',
                'errors' => $skipErrors,
            ];
        }

        $allErrors = array_merge_recursive(
            $this->mapErrors($referenceErrors, ['jenis_pegawai' => 'Status Kepegawaian']),
            $databaseErrors,
            $duplicateErrors,
        );

        if ($allErrors !== []) {
            return $this->rowError($row, $nama, $allErrors);
        }

        return $this->rowValid($row, $nama, $validated);
    }

    private function validateSupplementalRow(array $row, array &$seenNips): array
    {
        $raw = $row['data'];
        $mapped = [
            'nip' => $this->stringOrNull($raw['NIP'] ?? null),
            'nik' => $this->stringOrNull($raw['NIK'] ?? null),
            'no_kk' => $this->stringOrNull($raw['No KK'] ?? null),
            'tempat_lahir' => $this->stringOrNull($raw['Tempat Lahir'] ?? null),
            'jenis_kelamin' => $this->normalizeGender($raw['Jenis Kelamin'] ?? null),
            'agama' => $this->stringOrNull($raw['Agama'] ?? null),
            'status_kawin' => $this->stringOrNull($raw['Status Kawin'] ?? null),
            'golongan_darah' => $this->normalizeBloodType($raw['Golongan Darah'] ?? null),
        ];
        $nama = $mapped['nip'] ?? '-';
        $validator = Validator::make($mapped, [
            'nip' => ['required', 'string', 'size:18'],
            'nik' => ['nullable', 'string', 'size:16'],
            'no_kk' => ['nullable', 'string', 'size:16'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'agama' => ['nullable', 'string', 'max:100'],
            'status_kawin' => ['nullable', 'string', 'max:100'],
            'golongan_darah' => ['nullable', 'in:A,B,AB,O'],
        ], [], [
            'nip' => 'NIP',
            'nik' => 'NIK',
            'no_kk' => 'No KK',
            'tempat_lahir' => 'Tempat Lahir',
            'jenis_kelamin' => 'Jenis Kelamin',
            'agama' => 'Agama',
            'status_kawin' => 'Status Kawin',
            'golongan_darah' => 'Golongan Darah',
        ]);

        if ($validator->fails()) {
            return $this->rowError($row, $nama, $this->mapErrors($validator->errors()->toArray(), [
                'nip' => 'NIP',
                'nik' => 'NIK',
                'no_kk' => 'No KK',
                'tempat_lahir' => 'Tempat Lahir',
                'jenis_kelamin' => 'Jenis Kelamin',
                'agama' => 'Agama',
                'status_kawin' => 'Status Kawin',
                'golongan_darah' => 'Golongan Darah',
            ]));
        }

        $validated = $validator->validated();
        $employee = null;
        $errors = $this->employeeLookupErrors($validated['nip'], $employee);
        $errors = array_merge_recursive($errors, $this->duplicateNipErrors($validated['nip'], $row['row'], $seenNips));

        if ($validated['agama'] !== null) {
            $agama = $this->findReference(RefAgama::class, $validated['agama']);
            if ($agama === null) {
                $errors['Agama'][] = 'Agama tidak ditemukan di data referensi.';
            } else {
                $validated['agama_id'] = $agama->id;
            }
        }

        if ($validated['status_kawin'] !== null) {
            $status = $this->findReference(RefStatusPerkawinan::class, $validated['status_kawin']);
            if ($status === null) {
                $errors['Status Kawin'][] = 'Status kawin tidak ditemukan di data referensi.';
            } else {
                $validated['status_kawin_id'] = $status->id;
            }
        }

        unset($validated['nip'], $validated['agama'], $validated['status_kawin']);

        if ($errors !== []) {
            return $this->rowError($row, $nama, $errors);
        }

        $validated = array_filter($validated, fn ($value) => $value !== null);
        $validated['employee_id'] = $employee->id;

        return $this->rowValid($row, $employee->nama_lengkap, $validated);
    }

    private function validateRankHistoryRow(array $row): array
    {
        $raw = $row['data'];
        $mapped = [
            'nip' => $this->stringOrNull($raw['NIP'] ?? null),
            'golongan' => $this->stringOrNull($raw['Golongan'] ?? null),
            'tmt_pangkat' => $this->normalizeDate($raw['TMT Pangkat'] ?? null),
            'no_sk' => $this->stringOrNull($raw['No SK'] ?? null),
            'tanggal_sk' => $this->normalizeDate($raw['Tanggal SK'] ?? null),
        ];
        $nama = $mapped['nip'] ?? '-';
        $validator = Validator::make($mapped, [
            'nip' => ['required', 'string', 'size:18'],
            'golongan' => ['required', 'string', 'max:100'],
            'tmt_pangkat' => ['required', 'date'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->rowError($row, $nama, $this->mapErrors($validator->errors()->toArray(), [
                'nip' => 'NIP',
                'golongan' => 'Golongan',
                'tmt_pangkat' => 'TMT Pangkat',
                'no_sk' => 'No SK',
                'tanggal_sk' => 'Tanggal SK',
            ]));
        }

        $validated = $validator->validated();
        $employee = null;
        $errors = $this->employeeLookupErrors($validated['nip'], $employee);
        $golongan = $this->findReference(RefGolongan::class, $validated['golongan'], ['kode', 'nama']);

        if ($golongan === null) {
            $errors['Golongan'][] = 'Golongan tidak ditemukan di data referensi.';
        }

        if ($errors !== []) {
            return $this->rowError($row, $nama, $errors);
        }

        return $this->rowValid($row, $employee->nama_lengkap, [
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => $validated['tmt_pangkat'],
            'no_sk' => $validated['no_sk'],
            'tanggal_sk' => $validated['tanggal_sk'],
        ]);
    }

    private function validatePositionHistoryRow(array $row): array
    {
        $raw = $row['data'];
        $mapped = [
            'nip' => $this->stringOrNull($raw['NIP'] ?? null),
            'nama_jabatan' => $this->stringOrNull($raw['Nama Jabatan'] ?? null),
            'jenis_jabatan' => $this->stringOrNull($raw['Jenis Jabatan'] ?? null),
            'eselon' => $this->stringOrNull($raw['Eselon'] ?? null),
            'unit_kerja' => $this->stringOrNull($raw['Unit Kerja'] ?? null),
            'tmt_jabatan' => $this->normalizeDate($raw['TMT Jabatan'] ?? null),
            'no_sk' => $this->stringOrNull($raw['No SK'] ?? null),
            'tanggal_sk' => $this->normalizeDate($raw['Tanggal SK'] ?? null),
        ];
        $nama = $mapped['nip'] ?? '-';
        $validator = Validator::make($mapped, [
            'nip' => ['required', 'string', 'size:18'],
            'nama_jabatan' => ['required', 'string', 'max:255'],
            'jenis_jabatan' => ['required', 'string', 'max:100'],
            'eselon' => ['nullable', 'string', 'max:100'],
            'unit_kerja' => ['required', 'string', 'max:255'],
            'tmt_jabatan' => ['required', 'date'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->rowError($row, $nama, $this->mapErrors($validator->errors()->toArray(), [
                'nip' => 'NIP',
                'nama_jabatan' => 'Nama Jabatan',
                'jenis_jabatan' => 'Jenis Jabatan',
                'eselon' => 'Eselon',
                'unit_kerja' => 'Unit Kerja',
                'tmt_jabatan' => 'TMT Jabatan',
                'no_sk' => 'No SK',
                'tanggal_sk' => 'Tanggal SK',
            ]));
        }

        $validated = $validator->validated();
        $employee = null;
        $errors = $this->employeeLookupErrors($validated['nip'], $employee);
        $jenisJabatan = $this->findReference(RefJenisJabatan::class, $validated['jenis_jabatan']);
        $unitKerja = $this->findReference(RefUnitKerja::class, $validated['unit_kerja']);
        $eselon = $validated['eselon'] !== null ? $this->findReference(RefEselon::class, $validated['eselon'], ['kode', 'nama']) : null;

        if ($jenisJabatan === null) {
            $errors['Jenis Jabatan'][] = 'Jenis jabatan tidak ditemukan di data referensi.';
        }

        if ($unitKerja === null) {
            $errors['Unit Kerja'][] = 'Unit kerja tidak ditemukan di data referensi.';
        }

        if ($validated['eselon'] !== null && $eselon === null) {
            $errors['Eselon'][] = 'Eselon tidak ditemukan di data referensi.';
        }

        if ($errors !== []) {
            return $this->rowError($row, $nama, $errors);
        }

        return $this->rowValid($row, $employee->nama_lengkap, [
            'employee_id' => $employee->id,
            'nama_jabatan' => $validated['nama_jabatan'],
            'jenis_jabatan_id' => $jenisJabatan->id,
            'eselon_id' => $eselon?->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => $validated['tmt_jabatan'],
            'no_sk' => $validated['no_sk'],
            'tanggal_sk' => $validated['tanggal_sk'],
        ]);
    }

    private function validateKgbHistoryRow(array $row): array
    {
        $raw = $row['data'];
        $mapped = [
            'nip' => $this->stringOrNull($raw['NIP'] ?? null),
            'tmt_kgb' => $this->normalizeDate($raw['TMT KGB'] ?? null),
            'gaji_pokok' => $this->normalizeMoney($raw['Gaji Pokok'] ?? null),
            'no_sk' => $this->stringOrNull($raw['No SK'] ?? null),
            'tanggal_sk' => $this->normalizeDate($raw['Tanggal SK'] ?? null),
        ];
        $nama = $mapped['nip'] ?? '-';
        $validator = Validator::make($mapped, [
            'nip' => ['required', 'string', 'size:18'],
            'tmt_kgb' => ['required', 'date'],
            'gaji_pokok' => ['required', 'numeric', 'min:0'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->rowError($row, $nama, $this->mapErrors($validator->errors()->toArray(), [
                'nip' => 'NIP',
                'tmt_kgb' => 'TMT KGB',
                'gaji_pokok' => 'Gaji Pokok',
                'no_sk' => 'No SK',
                'tanggal_sk' => 'Tanggal SK',
            ]));
        }

        $validated = $validator->validated();
        $employee = null;
        $errors = $this->employeeLookupErrors($validated['nip'], $employee);

        if ($errors !== []) {
            return $this->rowError($row, $nama, $errors);
        }

        return $this->rowValid($row, $employee->nama_lengkap, [
            'employee_id' => $employee->id,
            'tmt_kgb' => $validated['tmt_kgb'],
            'gaji_pokok' => $validated['gaji_pokok'],
            'no_sk' => $validated['no_sk'],
            'tanggal_sk' => $validated['tanggal_sk'],
        ]);
    }

    private function rowValid(array $row, string $nama, array $validatedData): array
    {
        return [
            'row' => $row['row'],
            'nama' => $nama,
            'status' => 'valid',
            'errors' => [],
            'validated_data' => $validatedData,
        ];
    }

    private function rowError(array $row, string $nama, array $errors): array
    {
        return [
            'row' => $row['row'],
            'nama' => $nama,
            'status' => 'error',
            'errors' => $errors,
        ];
    }

    private function employeeLookupErrors(string $nip, ?Employee &$employee = null): array
    {
        $employee = Employee::where('nip', $nip)->first();

        if ($employee !== null) {
            return [];
        }

        return ['NIP' => ['NIP tidak ditemukan di database pegawai. Import data utama terlebih dahulu.']];
    }

    private function duplicateNipErrors(string $nip, int $row, array &$seenNips): array
    {
        if (isset($seenNips[$nip])) {
            return ['NIP' => ["NIP sudah ada pada baris {$seenNips[$nip]}."]];
        }

        $seenNips[$nip] = $row;

        return [];
    }

    private function findReference(string $modelClass, string $value, array $columns = ['nama']): ?Model
    {
        $target = $this->normalizeLookup($value);

        return $modelClass::query()
            ->get()
            ->first(function (Model $model) use ($columns, $target): bool {
                foreach ($columns as $column) {
                    if ($this->normalizeLookup((string) $model->getAttribute($column)) === $target) {
                        return true;
                    }
                }

                return false;
            });
    }

    private function getJenisPegawaiCache(): array
    {
        if ($this->jenisPegawaiCache === null) {
            $this->jenisPegawaiCache = RefJenisPegawai::pluck('id', 'nama')->all();
        }

        return $this->jenisPegawaiCache;
    }

    private function resolveReferences(array &$data): array
    {
        $errors = [];
        $jenisPegawai = $data['jenis_pegawai'] ?? null;

        if ($jenisPegawai !== null) {
            $cache = $this->getJenisPegawaiCache();
            $cacheNormalized = array_change_key_case($cache, CASE_UPPER);
            $key = strtoupper(trim($jenisPegawai));

            if (isset($cacheNormalized[$key])) {
                $data['jenis_pegawai_id'] = $cacheNormalized[$key];
            } else {
                $errors['jenis_pegawai'][] = 'Jenis pegawai harus salah satu dari: '.implode(', ', array_keys($cache)).'.';
            }
            unset($data['jenis_pegawai']);
        }

        return $errors;
    }

    private function duplicateErrors(array $data, int $row, array &$seenNips, array &$seenEmails): array
    {
        $errors = [];

        if (! empty($data['nip'])) {
            $nip = $data['nip'];

            if (isset($seenNips[$nip])) {
                $errors['nip'][] = "NIP sudah ada pada baris {$seenNips[$nip]}.";
            } else {
                $seenNips[$nip] = $row;
            }
        }

        if (! empty($data['email'])) {
            $email = strtolower($data['email']);

            if (isset($seenEmails[$email])) {
                $errors['email'][] = "Email pegawai sudah ada pada baris {$seenEmails[$email]}.";
            } else {
                $seenEmails[$email] = $row;
            }
        }

        return $errors;
    }

    private function mapErrors(array $errors, array $fieldMap): array
    {
        $mapped = [];

        foreach ($errors as $field => $messages) {
            $mapped[$fieldMap[$field] ?? $field] = $messages;
        }

        return $mapped;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function normalizeDate(mixed $value): mixed
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return $value;
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'F j, Y', 'F d, Y', 'M j, Y', 'M d, Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $value;
    }

    private function normalizeMoney(mixed $value): mixed
    {
        $value = $this->stringOrNull($value);

        if ($value === null || is_numeric($value)) {
            return $value;
        }

        $normalized = preg_replace('/\s+/', '', $value) ?? $value;

        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $normalized)) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $normalized)) {
            $normalized = str_replace(',', '', $normalized);
        } elseif (preg_match('/^\d+,\d+$/', $normalized)) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return $normalized;
    }

    private function normalizeGender(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'l', 'laki-laki', 'laki laki', 'pria' => 'L',
            'p', 'perempuan', 'wanita' => 'P',
            default => $value,
        };
    }

    private function normalizeBloodType(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value === null ? null : strtoupper($value);
    }

    private function normalizeLookup(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return mb_strtolower($value);
    }

    private function cleanupBatch(string $batchId, string $filename): void
    {
        Cache::forget(self::CACHE_PREFIX.$batchId);

        $storedName = $batchId.'_'.$filename;
        if (Storage::disk('local')->exists(self::STORAGE_DIR.'/'.$storedName)) {
            Storage::disk('local')->delete(self::STORAGE_DIR.'/'.$storedName);
        }
    }
}
