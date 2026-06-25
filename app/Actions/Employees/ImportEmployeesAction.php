<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Services\AuditService;
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

    public function __construct(private readonly CsvEmployeeReader $reader) {}

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
        $seenNips = [];
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
            $duplicateErrors = $this->duplicateErrors($data, $row['row'], $seenNips, $seenEmails);
            $rowErrors = array_merge_recursive($referenceErrors, $duplicateErrors);

            if ($rowErrors !== []) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $rowErrors,
                ];

                continue;
            }

            $validatedRows[] = $data;
        }

        if ($errors !== []) {
            return $this->failedSummary($errors);
        }

        DB::transaction(function () use ($validatedRows): void {
            foreach ($validatedRows as $data) {
                Employee::create($data);
            }
        });

        AuditService::log('IMPORT', 'Employee', null, null, [
            'total_inserted' => count($validatedRows),
            'filename' => $request->file('file')->getClientOriginalName(),
        ], $request);

        return [
            'message' => 'Import selesai.',
            'inserted' => count($validatedRows),
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
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $seenNips
     * @param  array<string, int>  $seenEmails
     * @return array<string, array<int, string>>
     */
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

    /**
     * @param  array<int, array{row: int, errors: array<string, mixed>}>  $errors
     * @return array{message: string, inserted: int, failed: int, errors: array<int, array{row: int, errors: array<string, mixed>}>}
     */
    private function failedSummary(array $errors): array
    {
        return [
            'message' => 'Import gagal. Perbaiki baris bermasalah lalu unggah ulang.',
            'inserted' => 0,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }
}
