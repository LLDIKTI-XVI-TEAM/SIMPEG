<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Support\EmployeeImport\EmployeeRowMapper;
use App\Support\EmployeeValidationRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ValidateImportBatchAction
{
    private ?array $jenisPegawaiCache = null;

    /**
     * Validate the import batch.
     *
     * @param  string  $batchId
     * @param  array|null  $updatedRows
     * @param  User|null  $user
     * @return array
     */
    public function execute(string $batchId, ?array $updatedRows, ?User $user): array
    {
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        if ($batch === null) {
            abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
        }

        if ($batch['user_id'] !== null && ($user === null || $batch['user_id'] !== $user->id)) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        if ($updatedRows !== null) {
            $batch['rows'] = $updatedRows;
            $batch['total_rows'] = count($updatedRows);
            Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
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
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        return [
            'batch_id' => $batchId,
            'type' => $type,
            'type_label' => UploadImportBatchAction::TEMPLATE_LABELS[$type] ?? UploadImportBatchAction::TEMPLATE_LABELS['utama'],
            'total_rows' => $batch['total_rows'],
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'skip_count' => $skipCount,
            'results' => $results,
        ];
    }

    private function validateTemplateRow(string $type, array $row, array &$seenNips, array &$seenEmails): array
    {
        return $this->validateRow($row, $seenNips, $seenEmails);
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
}
