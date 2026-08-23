<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\TransactionSideEffectManager;
use App\Support\EmployeeImport\EmployeeRowMapper;
use App\Support\EmployeeImport\ImportBatchCacheMutation;
use App\Support\EmployeeImport\ImportColumnMapping;
use App\Support\EmployeeValidationRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ValidateImportBatchAction
{
    private ?array $jenisPegawaiCache = null;

    public function __construct(private readonly TransactionSideEffectManager $sideEffects) {}

    /**
     * Memvalidasi seluruh baris batch import sesuai pemetaan aktif dan aturan identitas pegawai.
     */
    public function execute(string $batchId, ?array $updatedRows, ?User $user): array
    {
        $lifecycleLock = Cache::lock(
            UploadImportBatchAction::LIFECYCLE_LOCK_PREFIX.$batchId,
            UploadImportBatchAction::LIFECYCLE_LOCK_SECONDS,
        );

        if (! $lifecycleLock->get()) {
            throw ValidationException::withMessages([
                'message' => ['Batch import sedang diproses oleh permintaan lain. Silakan coba kembali.'],
            ]);
        }

        $releaseAfterScope = $this->sideEffects->afterCompletion(static fn () => $lifecycleLock->release());

        try {
            $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

            if ($batch === null) {
                abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
            }

            if ($batch['user_id'] !== null && ($user === null || $batch['user_id'] !== $user->id)) {
                abort(403, 'Anda tidak memiliki akses ke batch import ini.');
            }

            $cacheKey = UploadImportBatchAction::CACHE_PREFIX.$batchId;
            $cacheMutation = new ImportBatchCacheMutation($cacheKey, $batch);
            $this->sideEffects->afterRollback(static fn () => $cacheMutation->restoreIfUnchanged());

            if ($updatedRows !== null) {
                $batch['rows'] = $this->mergeEditedRows($batch['rows'], $updatedRows);
                $batch['total_rows'] = count($batch['rows']);
                $cacheMutation->put($batch);
            }

            // Pemetaan aktif batch adalah sumber kebenaran tafsir kolom. Field wajib yang belum
            // dipetakan menolak seluruh validasi lebih dulu dengan pesan yang menyebut field-nya,
            // agar admin memperbaiki pemetaan sebelum menilai baris.
            $mapping = $batch['mapping'] ?? ImportColumnMapping::autoMap($batch['headers']);
            $missingRequired = ImportColumnMapping::missingRequired($mapping);

            if ($missingRequired !== []) {
                throw ValidationException::withMessages([
                    'mapping' => array_map(
                        fn (string $target): string => "Field wajib {$target} belum dipetakan ke kolom mana pun.",
                        $missingRequired,
                    ),
                ]);
            }

            // Setelah admin menyimpan mapping manual, heuristik kolom bergeser dimatikan
            // agar pilihan admin tidak ditimpa tebakan positional.
            $allowShiftDetection = ($batch['mapping_source'] ?? 'auto') !== 'manual';

            $type = $batch['type'] ?? 'utama';
            $results = [];
            $validCount = 0;
            $errorCount = 0;
            $skipCount = 0;
            $seenNips = [];
            $seenEmails = [];
            // Catat NIP/email yang muncul lebih dari sekali dengan nomor baris
            // kemunculan duplikat terakhir untuk koreksi retroaktif.
            $duplicatedNips = [];
            $duplicatedEmails = [];

            foreach ($batch['rows'] as $row) {
                $rowResult = $this->validateTemplateRow($type, $row, $seenNips, $seenEmails, $duplicatedNips, $duplicatedEmails, $mapping, $allowShiftDetection);
                $results[] = $rowResult;

                match ($rowResult['status']) {
                    'valid' => $validCount++,
                    'error' => $errorCount++,
                    'skip' => $skipCount++,
                };
            }

            // Kemunculan pertama sudah dinilai sebelum duplikat berikutnya ditemukan.
            // Naikkan status kemunculan pertama menjadi error agar duplikasi dalam file simetris.
            if ($duplicatedNips !== [] || $duplicatedEmails !== []) {
                foreach ($results as &$rowResult) {
                    if ($rowResult['status'] === 'error') {
                        continue; // Pesan duplikasi mungkin sudah tercatat pada baris error ini.
                    }

                    $data = $rowResult['validated_data'] ?? [];
                    $upgrades = [];

                    $nip = $data['nip'] ?? null;
                    if ($nip !== null && array_key_exists($nip, $duplicatedNips)) {
                        $upgrades['NIP'][] = "NIP sudah ada pada baris {$duplicatedNips[$nip]}.";
                    }

                    $email = isset($data['email_pribadi']) ? strtolower($data['email_pribadi']) : null;
                    if ($email !== null && array_key_exists($email, $duplicatedEmails)) {
                        $upgrades['Email Pegawai'][] = "Email pegawai sudah ada pada baris {$duplicatedEmails[$email]}.";
                    }

                    if ($upgrades !== []) {
                        $prevStatus = $rowResult['status'];
                        $rowResult['status'] = 'error';
                        $rowResult['errors'] = array_merge_recursive($rowResult['errors'] ?? [], $upgrades);
                        unset($rowResult['validated_data'], $rowResult['skip_reason']);

                        if ($prevStatus === 'skip') {
                            $skipCount--;
                        } elseif ($prevStatus === 'valid') {
                            $validCount--;
                        }
                        $errorCount++;
                    }
                }
                unset($rowResult);
            }

            $batch['validation'] = [
                'valid_count' => $validCount,
                'error_count' => $errorCount,
                'skip_count' => $skipCount,
                'results' => $results,
            ];
            $cacheMutation->put($batch);

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
        } finally {
            if (! $releaseAfterScope) {
                $lifecycleLock->release();
            }
        }
    }

    /**
     * Gabungkan baris yang diedit admin ke state batch berdasarkan nomor baris.
     *
     * Preview hanya memuat 10 baris pertama, sehingga payload berisi subset baris hasil
     * edit, bukan seluruh baris. Nomor baris yang tidak dikenal ditolak agar client tidak
     * bisa menyuntikkan baris baru di luar isi file.
     *
     * @param  list<array{row:int, data:array<string, mixed>}>  $batchRows
     * @param  list<array{row:int, data:array<string, mixed>}>  $editedRows
     * @return list<array{row:int, data:array<string, mixed>}>
     */
    private function mergeEditedRows(array $batchRows, array $editedRows): array
    {
        $indexByRowNumber = [];
        foreach ($batchRows as $index => $row) {
            $indexByRowNumber[$row['row']] = $index;
        }

        foreach ($editedRows as $edited) {
            $rowNumber = (int) $edited['row'];

            if (! isset($indexByRowNumber[$rowNumber])) {
                throw ValidationException::withMessages([
                    'rows' => ["Baris {$rowNumber} tidak ditemukan pada batch import ini."],
                ]);
            }

            $batchRows[$indexByRowNumber[$rowNumber]]['data'] = $edited['data'];
        }

        return $batchRows;
    }

    private function validateTemplateRow(string $type, array $row, array &$seenNips, array &$seenEmails, array &$duplicatedNips, array &$duplicatedEmails, array $mapping, bool $allowShiftDetection): array
    {
        return $this->validateRow($row, $seenNips, $seenEmails, $duplicatedNips, $duplicatedEmails, $mapping, $allowShiftDetection);
    }

    private function validateRow(array $row, array &$seenNips, array &$seenEmails, array &$duplicatedNips, array &$duplicatedEmails, array $mapping, bool $allowShiftDetection): array
    {
        // Key baris sumber dinormalkan ke header kanonis memakai mapping aktif batch;
        // kolom bertanda tidak dipakai dibuang sebelum mapper membaca nilai apa pun.
        $sourceData = $allowShiftDetection
            ? app(EmployeeRowMapper::class)->alignShiftedOptionalIdentityColumns($row['data'])
            : $row['data'];
        $data = ImportColumnMapping::apply($sourceData, $mapping);
        $nama = $data['Nama Pegawai'] ?? '-';
        $mappedData = app(EmployeeRowMapper::class)->map($data, allowShiftDetection: false);
        $validator = Validator::make($mappedData, EmployeeValidationRules::import(), [], EmployeeValidationRules::attributes());

        if ($validator->fails()) {
            return $this->rowError($row, $nama, $this->mapErrors($validator->errors()->toArray(), [
                'nama_dengan_gelar' => 'Nama Pegawai',
                'nama_lengkap' => 'Nama Lengkap (Person)',
                'email_pribadi' => 'Email Pegawai',
                'golongan_terakhir' => 'Golongan',
                'jabatan_terakhir' => 'Jabatan',
                'kelas_jabatan_terakhir' => 'Kelas Jabatan',
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
            ]));
        }

        $validated = $validator->validated();
        $referenceErrors = $this->resolveReferences($validated);

        // Duplikasi dalam file harus dilaporkan sebagai error sebelum pemeriksaan database.
        // Email yang sudah terdaftar tetap error, sedangkan NIP database menjadi skip
        // hanya bila baris tidak memiliki error yang lebih kuat.

        $duplicateErrors = $this->mapErrors($this->duplicateErrors($validated, $row['row'], $seenNips, $seenEmails, $duplicatedNips, $duplicatedEmails), [
            'nip' => 'NIP',
            'email_pribadi' => 'Email Pegawai',
        ]);

        $databaseErrors = [];
        if (! empty($validated['email_pribadi']) && Employee::withTrashed()->whereRaw('LOWER(email_pribadi) = ?', [strtolower($validated['email_pribadi'])])->exists()) {
            $databaseErrors['Email Pegawai'][] = 'Email pegawai sudah terdaftar di database.';
        }

        $shouldSkip = $this->shouldSkipNip($validated, $seenNips, $row['row']);
        $skipErrors = [];
        if ($shouldSkip) {
            $skipErrors['NIP'][] = 'NIP sudah terdaftar di database.';
        }

        $allErrors = array_merge_recursive(
            $this->mapErrors($referenceErrors, ['jenis_pegawai' => 'Status Kepegawaian']),
            $duplicateErrors,
            $databaseErrors,
        );

        if ($allErrors !== []) {
            return $this->rowError($row, $nama, $allErrors);
        }

        if ($skipErrors !== []) {
            return [
                'row' => $row['row'],
                'nama' => $nama,
                'status' => 'skip',
                'errors' => $skipErrors,
                'skip_reason' => 'NIP sudah terdaftar di database — baris akan dilewati.',
                'validated_data' => $validated,
            ];
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

    private function duplicateErrors(array $data, int $row, array &$seenNips, array &$seenEmails, array &$duplicatedNips, array &$duplicatedEmails): array
    {
        $errors = [];

        if (! empty($data['nip'])) {
            $nip = $data['nip'];

            if (isset($seenNips[$nip])) {
                $errors['nip'][] = "NIP sudah ada pada baris {$seenNips[$nip]}.";
                // Penanda ini memungkinkan koreksi retroaktif pada kemunculan pertama.
                $duplicatedNips[$nip] = $row;
            } else {
                $seenNips[$nip] = $row;
            }
        }

        if (! empty($data['email_pribadi'])) {
            $email = strtolower($data['email_pribadi']);

            if (isset($seenEmails[$email])) {
                $errors['email_pribadi'][] = "Email pegawai sudah ada pada baris {$seenEmails[$email]}.";
                // Penanda ini memungkinkan koreksi retroaktif pada kemunculan pertama.
                $duplicatedEmails[$email] = $row;
            } else {
                $seenEmails[$email] = $row;
            }
        }

        return $errors;
    }

    /**
     * Menentukan apakah NIP database dapat dilewati tanpa menutupi duplikasi dalam file.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $seenNips
     */
    private function shouldSkipNip(array $data, array $seenNips, int $row): bool
    {
        if (empty($data['nip'])) {
            return false;
        }

        $nip = $data['nip'];

        // Kemunculan berikutnya harus tetap dilaporkan sebagai duplikasi dalam file.
        if (isset($seenNips[$nip]) && $seenNips[$nip] !== $row) {
            return false;
        }

        return Employee::withTrashed()->where('nip', $nip)->exists();
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
