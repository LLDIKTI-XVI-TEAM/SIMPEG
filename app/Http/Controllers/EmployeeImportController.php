<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportEmployeesRequest;
use App\Models\Employee;
use App\Services\AuditService;
use App\Models\RefJenisPegawai;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeValidationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmployeeImportController extends Controller
{
    private const CACHE_PREFIX = 'import_batch:';
    private const CACHE_TTL_MINUTES = 30;
    private const STORAGE_DIR = 'imports';
    private const PREVIEW_LIMIT = 10;

    private ?array $jenisPegawaiCache = null;

    // ── Step 1: Upload & Parse ───────────────────────────────────────────────

    public function upload(ImportEmployeesRequest $request, CsvEmployeeReader $reader): JsonResponse
    {
        try {
            $rows = $reader->read($request->file('file'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['File tidak berisi data pegawai (hanya header).'],
            ]);
        }

        $batchId = (string) Str::uuid();

        // Simpan file asli ke storage sementara
        $request->file('file')->storeAs(self::STORAGE_DIR, $batchId . '_' . $request->file('file')->getClientOriginalName(), 'local');

        // Ambil headers dari keys data baris pertama
        $firstRowData = $rows[0]['data'] ?? [];
        $headers = array_keys($firstRowData);

        // Simpan parsed data ke cache
        Cache::put(self::CACHE_PREFIX . $batchId, [
            'filename'    => $request->file('file')->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
            'user_id'     => $request->user()?->id,
            'headers'     => $headers,
            'total_rows'  => count($rows),
            'rows'        => $rows,
            'validation'  => null,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return response()->json([
            'batch_id'   => $batchId,
            'filename'   => $request->file('file')->getClientOriginalName(),
            'total_rows' => count($rows),
            'headers'    => $headers,
        ]);
    }

    // ── Step 2: Preview ──────────────────────────────────────────────────────

    public function preview(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        return response()->json([
            'batch_id'   => $batchId,
            'filename'   => $batch['filename'],
            'total_rows' => $batch['total_rows'],
            'headers'    => $batch['headers'],
            'rows'       => $batch['rows'],
        ]);
    }

    // ── Step 3: Validate ─────────────────────────────────────────────────────

    public function validate(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        // Jika frontend mengirim rows yang sudah diedit, update cache
        if ($request->has('rows')) {
            $editedRows = $request->input('rows');
            $batch['rows'] = $editedRows;
            $batch['total_rows'] = count($editedRows);
            Cache::put(self::CACHE_PREFIX . $batchId, $batch, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }

        $results = [];
        $validCount = 0;
        $errorCount = 0;
        $skipCount = 0;
        $seenNips = [];
        $seenEmails = [];

        foreach ($batch['rows'] as $row) {
            $rowResult = $this->validateRow($row, $seenNips, $seenEmails);
            $results[] = $rowResult;

            match ($rowResult['status']) {
                'valid' => $validCount++,
                'error' => $errorCount++,
                'skip'  => $skipCount++,
            };
        }

        // Simpan hasil validasi ke cache
        $batch['validation'] = [
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'skip_count'  => $skipCount,
            'results'     => $results,
        ];
        Cache::put(self::CACHE_PREFIX . $batchId, $batch, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return response()->json([
            'batch_id'    => $batchId,
            'total_rows'  => $batch['total_rows'],
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'skip_count'  => $skipCount,
            'results'     => $results,
        ]);
    }

    // ── Step 4: Execute ──────────────────────────────────────────────────────

    public function execute(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        if ($batch['validation'] === null) {
            return response()->json([
                'message' => 'Data belum divalidasi. Jalankan validasi terlebih dahulu.',
            ], 422);
        }

        $validationResults = $batch['validation']['results'];
        $rowsToInsert = [];

        foreach ($validationResults as $result) {
            if ($result['status'] === 'valid' && isset($result['validated_data'])) {
                $rowsToInsert[] = $result['validated_data'] + [
                    'status_aktif'    => 'Aktif',
                    'profil_status'   => 'belum_lengkap',
                    'is_kinerja_baik' => true,
                ];
            }
        }

        $insertedCount = 0;

        if ($rowsToInsert !== []) {
            DB::transaction(function () use ($rowsToInsert, &$insertedCount): void {
                foreach ($rowsToInsert as $data) {
                    Employee::create($data);
                    $insertedCount++;
                }
            });
        }

        AuditService::log('IMPORT', 'Employee', null, null, [
            'total_inserted' => $insertedCount,
            'total_skipped'  => $batch['validation']['skip_count'],
            'total_failed'   => $batch['validation']['error_count'],
            'filename'       => $batch['filename'],
        ], $request);

        // Cleanup: hapus cache dan file temp
        $this->cleanupBatch($batchId, $batch['filename']);

        return response()->json([
            'message'  => 'Import selesai.',
            'inserted' => $insertedCount,
            'skipped'  => $batch['validation']['skip_count'],
            'failed'   => $batch['validation']['error_count'],
        ]);
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    private function getBatchOrFail(string $batchId, Request $request): array
    {
        $batch = Cache::get(self::CACHE_PREFIX . $batchId);

        if ($batch === null) {
            abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
        }

        // Pastikan batch milik user yang sama
        if ($batch['user_id'] !== null && $batch['user_id'] !== $request->user()?->id) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        return $batch;
    }

    private function validateRow(array $row, array &$seenNips, array &$seenEmails): array
    {
        $data = $row['data'];
        $nama = $data['nama_lengkap'] ?? '-';

        // Validasi basic rules
        $validator = Validator::make($data, EmployeeValidationRules::import(), [], EmployeeValidationRules::attributes());

        if ($validator->fails()) {
            return [
                'row'    => $row['row'],
                'nama'   => $nama,
                'status' => 'error',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        $validated = $validator->validated();

        // Resolve jenis_pegawai → jenis_pegawai_id
        $referenceErrors = $this->resolveReferences($validated);

        // Cek duplikat NIP di database (skip, bukan error)
        $skipErrors = [];
        if (! empty($validated['nip'])) {
            $existsInDb = Employee::where('nip', $validated['nip'])->exists();
            if ($existsInDb) {
                $skipErrors['nip'][] = 'NIP sudah terdaftar di database.';
            }
        }

        // Cek duplikat di dalam file
        $duplicateErrors = $this->duplicateErrors($validated, $row['row'], $seenNips, $seenEmails);

        // Jika ada skip (NIP di DB), mark sebagai skip
        if ($skipErrors !== []) {
            return [
                'row'    => $row['row'],
                'nama'   => $nama,
                'status' => 'skip',
                'errors' => $skipErrors,
            ];
        }

        // Gabungkan reference errors dan duplicate errors
        $allErrors = array_merge_recursive($referenceErrors, $duplicateErrors);

        if ($allErrors !== []) {
            return [
                'row'    => $row['row'],
                'nama'   => $nama,
                'status' => 'error',
                'errors' => $allErrors,
            ];
        }

        return [
            'row'            => $row['row'],
            'nama'           => $nama,
            'status'         => 'valid',
            'errors'         => [],
            'validated_data' => $validated,
        ];
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
                $errors['jenis_pegawai'][] = 'Jenis pegawai harus salah satu dari: ' . implode(', ', array_keys($cache)) . '.';
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

    private function cleanupBatch(string $batchId, string $filename): void
    {
        Cache::forget(self::CACHE_PREFIX . $batchId);

        $storedName = $batchId . '_' . $filename;
        if (Storage::disk('local')->exists(self::STORAGE_DIR . '/' . $storedName)) {
            Storage::disk('local')->delete(self::STORAGE_DIR . '/' . $storedName);
        }
    }
}
