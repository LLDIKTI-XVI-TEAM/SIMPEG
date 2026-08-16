<?php

namespace App\Actions\Employees;

use App\Models\User;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeImport\EmployeeRowMapper;
use App\Support\EmployeeImport\ImportColumnMapping;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UploadImportBatchAction
{
    public const CACHE_PREFIX = 'import_batch:';

    public const LIFECYCLE_LOCK_PREFIX = 'import_batch:lifecycle:';

    /** Lock lifecycle lebih panjang dari batas request import agar claim tidak membaca validasi parsial. */
    public const LIFECYCLE_LOCK_SECONDS = 300;

    public const CACHE_TTL_MINUTES = 30;

    public const STORAGE_DIR = 'imports';

    public const TEMPLATE_HEADERS = [
        'utama' => EmployeeRowMapper::HEADERS,
    ];

    public const TEMPLATE_LABELS = [
        'utama' => 'Data Utama',
    ];

    public function __construct(private readonly CsvEmployeeReader $reader) {}

    /**
     * Mengunggah dan membaca file import, menyimpan baris mentah di cache, lalu mengembalikan metadata batch.
     *
     * @throws ValidationException
     */
    public function execute(UploadedFile $file, ?string $requestedType, ?User $user): array
    {
        try {
            $rows = $this->reader->readRaw($file);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        // File tanpa baris data: bisa berarti hanya berisi header, atau hanya berisi
        // baris contoh yang sengaja dilewati importer. Pesan diperjelas agar admin
        // paham baris contoh otomatis di-skip dan tahu harus mengisi data asli.
        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['File belum berisi data pegawai. Baris contoh otomatis dilewati, jadi isi data pegawai di bawah baris contoh lalu unggah ulang.'],
            ]);
        }

        $firstRowData = $rows[0]['data'] ?? [];
        $headers = array_keys($firstRowData);
        $type = $this->detectTemplateType($headers, $requestedType);
        $batchId = (string) Str::uuid();

        // Pemetaan kolom adalah state batch: auto-map dari nama header menjadi mapping awal
        // yang masih boleh diubah admin, lalu dipakai ulang oleh preview, validasi, dan eksekusi.
        $mapping = ImportColumnMapping::autoMap($headers);
        $warnings = ImportColumnMapping::warnings($mapping);

        $file->storeAs(self::STORAGE_DIR, $batchId.'_'.$file->getClientOriginalName(), 'local');

        Cache::put(self::CACHE_PREFIX.$batchId, [
            'filename' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
            'user_id' => $user?->id,
            'type' => $type,
            'type_label' => self::TEMPLATE_LABELS[$type],
            'headers' => $headers,
            'total_rows' => count($rows),
            'rows' => $rows,
            'mapping' => $mapping,
            'mapping_source' => 'auto',
            'warnings' => $warnings,
            'validation' => null,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'batch_id' => $batchId,
            'filename' => $file->getClientOriginalName(),
            'type' => $type,
            'type_label' => self::TEMPLATE_LABELS[$type],
            'total_rows' => count($rows),
            'headers' => $headers,
            'mapping' => $mapping,
            'warnings' => $warnings,
            'required_targets' => ImportColumnMapping::requiredTargets(),
        ];
    }

    /**
     * Menentukan tipe template berdasarkan header CSV.
     *
     * @throws ValidationException
     */
    private function detectTemplateType(array $headers, ?string $requestedType): string
    {
        $requestedType = is_string($requestedType) ? trim($requestedType) : null;

        if ($requestedType !== null && isset(self::TEMPLATE_HEADERS[$requestedType])) {
            return $requestedType;
        }

        foreach (self::TEMPLATE_HEADERS as $type => $requiredHeaders) {
            if (array_values(array_diff($requiredHeaders, $headers)) === []) {
                return $type;
            }
        }

        // Header non-standar sengaja tetap diterima sebagai 'utama' karena admin masih dapat
        // memetakan kolom secara manual pada langkah preview. Batas kepercayaan data tidak
        // berada di sini, melainkan pada validasi per-baris di sisi server (field wajib, NIP,
        // email, referensi), sehingga file yang salah total tetap ditolak di tahap itu.
        if (count($headers) > 0) {
            return 'utama';
        }

        throw ValidationException::withMessages([
            'file' => ['Header template tidak dikenali. Gunakan template utama dari halaman import.'],
        ]);
    }
}
