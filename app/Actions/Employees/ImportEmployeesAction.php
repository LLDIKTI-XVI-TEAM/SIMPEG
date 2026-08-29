<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\Employees\TmtCalculatorService;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportEmployeesAction
{
    /** @var array<string, string>|null */
    private ?array $jenisPegawaiCache = null;

    public function __construct(
        private readonly CsvEmployeeReader $reader,
        private readonly TmtCalculatorService $tmtCalculator,
    ) {}

    /**
     * Mengimpor pegawai secara all-or-nothing agar file bermasalah tidak membuat data parsial.
     *
     * @return array{message: string, inserted: int, failed: int, errors: array<int, array{row: int, errors: array<string, mixed>}>}
     */
    public function execute(Request $request): array
    {
        try {
            $rows = $this->reader->read($request->file('file'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        $validatedRows = [];
        $errors = [];
        $skippedCount = 0;
        /** @var array<string, int> $seenNips */
        $seenNips = [];
        /** @var array<string, int> $seenEmails */
        $seenEmails = [];

        foreach ($rows as $row) {
            $validator = Validator::make(
                $row['data'],
                EmployeeValidationRules::import(),
                [],
                EmployeeValidationRules::attributes(),
            );

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $validator->errors()->toArray(),
                ];

                continue;
            }

            $data = $validator->validated();
            $referenceErrors = $this->resolveReferences($data);
            $duplicateResult = $this->duplicateErrors($data, $row['row'], $seenNips, $seenEmails);

            $rowErrors = array_merge_recursive($referenceErrors, $duplicateResult['errors']);

            if ($rowErrors !== []) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $rowErrors,
                ];

                continue;
            }

            // Skip NIP hanya berlaku bila baris tidak memiliki error yang harus diperbaiki admin.
            if ($duplicateResult['skip']) {
                $skippedCount++;

                continue;
            }

            $validatedRows[] = $data;
        }

        if ($errors !== []) {
            return $this->failedSummary($errors, $skippedCount);
        }

        DB::transaction(function () use ($validatedRows): void {
            $aktifId = RefStatusPegawai::where('nama', 'Aktif')->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');

            foreach ($validatedRows as $data) {
                $employee = Employee::create($data + [
                    'status_pegawai_id' => $aktifId,
                    'status_aktif' => 'Aktif',
                    'profil_status' => 'belum_lengkap',
                    'is_kinerja_baik' => true,
                ]);

                // Endpoint import kompatibilitas mengikuti batas yang sama dengan wizard:
                // catat provenance pensiun tanpa menghitung milestone lain dari snapshot massal.
                if ($employee->tanggal_pensiun !== null) {
                    $this->tmtCalculator->recordImportedPensionDate($employee);
                }
            }
        });

        AuditService::log('IMPORT', 'Employee', null, null, [
            'total_inserted' => count($validatedRows),
            'total_skipped' => $skippedCount,
            'filename' => $request->file('file')->getClientOriginalName(),
        ], $request);

        return [
            'message' => 'Import selesai.',
            'inserted' => count($validatedRows),
            'skipped' => $skippedCount,
            'failed' => 0,
            'errors' => [],
        ];
    }

    /**
     * Resolve nama jenis pegawai dari CSV menjadi FK `jenis_pegawai_id`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
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

    /**
     * @return array<string, string>
     */
    private function getJenisPegawaiCache(): array
    {
        if ($this->jenisPegawaiCache === null) {
            $this->jenisPegawaiCache = RefJenisPegawai::pluck('id', 'nama')->all();
        }

        return $this->jenisPegawaiCache;
    }

    /**
     * Menjaga file import tidak berisi NIP/email ganda sebelum transaksi insert dimulai.
     * - NIP ganda dalam satu berkas → error dengan prioritas tertinggi
     * - Email yang telah terdaftar → error
     * - Email ganda dalam berkas → error
     * - NIP sudah ada di database → skip bila baris tidak memiliki error lain
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $seenNips
     * @param  array<string, int>  $seenEmails
     * @return array{errors: array<string, array<int, string>>, skip: bool}
     */
    private function duplicateErrors(array $data, int $row, array &$seenNips, array &$seenEmails): array
    {
        $errors = [];
        $skip = false;

        if (! empty($data['nip'])) {
            $nip = (string) $data['nip'];

            // Duplikasi NIP dalam berkas diprioritaskan agar sumber konflik dapat diperbaiki.
            if (isset($seenNips[$nip])) {
                $errors['nip'][] = "NIP sudah ada pada baris {$seenNips[$nip]}.";
            } else {
                $seenNips[$nip] = $row;

                // NIP database menjadi skip hanya bila tidak ada duplikasi dalam berkas.
                if (Employee::query()->where('nip', $nip)->exists()) {
                    $skip = true;
                }
            }
        }

        if (! empty($data['email_pribadi'])) {
            $email = strtolower((string) $data['email_pribadi']);

            // Duplikasi email dalam berkas harus diperbaiki sebelum data disimpan.
            if (isset($seenEmails[$email])) {
                $errors['email_pribadi'][] = "Email pegawai sudah ada pada baris {$seenEmails[$email]}.";
            } else {
                $seenEmails[$email] = $row;
            }

            // Email yang telah digunakan tidak boleh dipakai oleh pegawai lain.
            if (Employee::query()->whereRaw('LOWER(email_pribadi) = ?', [$email])->exists()) {
                $errors['email_pribadi'][] = 'Email pegawai sudah terdaftar di database.';
            }
        }

        return ['errors' => $errors, 'skip' => $skip];
    }

    /**
     * @param  array<int, array{row: int, errors: array<string, mixed>}>  $errors
     * @return array{message: string, inserted: int, skipped: int, failed: int, errors: array<int, array{row: int, errors: array<string, mixed>}>}
     */
    private function failedSummary(array $errors, int $skippedCount = 0): array
    {
        $message = 'Import gagal. Perbaiki baris bermasalah lalu unggah ulang.';

        if ($skippedCount > 0) {
            $message .= " {$skippedCount} baris dilewati karena NIP sudah terdaftar.";
        }

        return [
            'message' => $message,
            'inserted' => 0,
            'skipped' => $skippedCount,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }
}
