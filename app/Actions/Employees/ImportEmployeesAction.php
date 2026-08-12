<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
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
     * Mengimpor semua baris valid; K-US-02 melewati NIP yang sudah tersimpan,
     * sedangkan error validasi lain tetap membatalkan import file.
     *
     * @return array{
     *     message: string,
     *     inserted: int,
     *     skipped: int,
     *     skipped_rows: array<int, array{row: int, errors: array<string, array<int, string>>}>,
     *     failed: int,
     *     errors: array<int, array{row: int, errors: array<string, mixed>}>
     * }
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
        $skippedRows = [];
        $errors = [];
        /** @var array<string, int> $seenNips */
        $seenNips = [];
        /** @var array<string, int> $seenEmails */
        $seenEmails = [];

        foreach ($rows as $row) {
            $validator = Validator::make(
                $row['data'],
                EmployeeValidationRules::import(allowExistingNip: true),
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
            $databaseErrors = $this->existingEmailErrors($data);
            $rowErrors = array_merge_recursive($referenceErrors, $duplicateErrors, $databaseErrors);

            if ($rowErrors !== []) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $rowErrors,
                ];

                continue;
            }

            if (! empty($data['nip']) && Employee::withTrashed()->where('nip', $data['nip'])->exists()) {
                $skippedRows[] = [
                    'row' => $row['row'],
                    'errors' => ['NIP' => ['NIP sudah terdaftar di database.']],
                ];

                continue;
            }

            $validatedRows[] = [
                'row' => $row['row'],
                'data' => $data,
            ];
        }

        if ($errors !== []) {
            return $this->failedSummary($errors);
        }

        [$insertedCount, $executionSkippedRows] = DB::transaction(function () use ($validatedRows): array {
            $aktifId = RefStatusPegawai::where('nama', 'Aktif')->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');
            $insertedCount = 0;
            $skippedRows = [];

            foreach ($validatedRows as $row) {
                $data = $row['data'];
                if (! empty($data['nip']) && Employee::withTrashed()->where('nip', $data['nip'])->exists()) {
                    $skippedRows[] = [
                        'row' => $row['row'],
                        'errors' => ['NIP' => ['NIP sudah terdaftar di database.']],
                    ];

                    continue;
                }

                Employee::create($data + [
                    'status_pegawai_id' => $aktifId,
                    'status_aktif' => 'Aktif',
                    'profil_status' => 'belum_lengkap',
                    'is_kinerja_baik' => true,
                ]);
                $insertedCount++;
            }

            return [$insertedCount, $skippedRows];
        });
        $skippedRows = [...$skippedRows, ...$executionSkippedRows];

        AuditService::log('IMPORT', 'Employee', null, null, [
            'total_inserted' => $insertedCount,
            'total_skipped' => count($skippedRows),
            'total_failed' => 0,
            'filename' => $request->file('file')->getClientOriginalName(),
        ], $request);

        return [
            'message' => 'Import selesai.',
            'inserted' => $insertedCount,
            'skipped' => count($skippedRows),
            'skipped_rows' => $skippedRows,
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
            $nip = (string) $data['nip'];

            if (isset($seenNips[$nip])) {
                $errors['nip'][] = "NIP sudah ada pada baris {$seenNips[$nip]}.";
            } else {
                $seenNips[$nip] = $row;
            }
        }

        if (! empty($data['email_pribadi'])) {
            $email = strtolower((string) $data['email_pribadi']);

            if (isset($seenEmails[$email])) {
                $errors['email_pribadi'][] = "Email pegawai sudah ada pada baris {$seenEmails[$email]}.";
            } else {
                $seenEmails[$email] = $row;
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, array{row: int, errors: array<string, mixed>}>  $errors
     * @return array{
     *     message: string,
     *     inserted: int,
     *     skipped: int,
     *     skipped_rows: array<int, array{row: int, errors: array<string, array<int, string>>}>,
     *     failed: int,
     *     errors: array<int, array{row: int, errors: array<string, mixed>}>
     * }
     */
    private function failedSummary(array $errors): array
    {
        return [
            'message' => 'Import gagal. Perbaiki baris bermasalah lalu unggah ulang.',
            'inserted' => 0,
            'skipped' => 0,
            'skipped_rows' => [],
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * K-US-02: NIP existing akan di-skip, tetapi email existing tetap error.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function existingEmailErrors(array $data): array
    {
        $email = $data['email_pribadi'] ?? null;
        if (! is_string($email) || $email === '') {
            return [];
        }

        $email = strtolower($email);
        $exists = Employee::withTrashed()
            ->where(function ($query) use ($email): void {
                $query->whereRaw('LOWER(email_pribadi) = ?', [$email])
                    ->orWhereRaw('LOWER(email) = ?', [$email]);
            })
            ->exists();

        return $exists ? ['email_pribadi' => ['Email pegawai sudah terdaftar di database.']] : [];
    }
}
