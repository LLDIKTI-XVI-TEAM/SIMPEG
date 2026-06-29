<?php

namespace App\Actions\Employees;

use App\Models\User;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeImport\EmployeeRowMapper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UploadImportBatchAction
{
    public const CACHE_PREFIX = 'import_batch:';

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
     * Upload and parse the import file, cache the raw rows, and return batch metadata.
     *
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
            'validation' => null,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'batch_id' => $batchId,
            'filename' => $file->getClientOriginalName(),
            'type' => $type,
            'type_label' => self::TEMPLATE_LABELS[$type],
            'total_rows' => count($rows),
            'headers' => $headers,
        ];
    }

    /**
     * Detect the type of template based on CSV headers.
     *
     *
     * @throws ValidationException
     */
    private function detectTemplateType(array $headers, ?string $requestedType): string
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
            'file' => ['Header template tidak dikenali. Gunakan template utama dari halaman import.'],
        ]);
    }
}
