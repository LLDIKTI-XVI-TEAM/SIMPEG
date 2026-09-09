<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string|null $history_id
 * @property string $jenis_dokumen
 * @property string $nama_dokumen
 * @property string|null $nomor_dokumen
 * @property string $file_path
 * @property string|null $keterangan
 * @property Carbon|null $tanggal_dokumen
 * @property-read Employee|null $employee
 */
class Document extends Model
{
    use HasUuid;

    public const STORAGE_DISK = 'employee_documents';

    protected $fillable = [
        'employee_id',
        'history_id',
        'jenis_dokumen',
        'nama_dokumen',
        'nomor_dokumen',
        'tanggal_dokumen',
        'file_path',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_dokumen' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fileExists(): bool
    {
        return $this->file_path !== null && Storage::disk(self::STORAGE_DISK)->exists($this->file_path);
    }

    public function fileExtension(): string
    {
        return strtoupper(pathinfo((string) $this->file_path, PATHINFO_EXTENSION) ?: 'FILE');
    }

    public function fileSizeLabel(): string
    {
        if (! $this->fileExists()) {
            return 'File tidak ditemukan';
        }

        $bytes = Storage::disk(self::STORAGE_DISK)->size($this->file_path);

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.').' MB';
        }

        return max(1, (int) ceil($bytes / 1024)).' KB';
    }

    public function fileStatus(): string
    {
        return $this->fileExists() ? 'tersedia' : 'file_tidak_ditemukan';
    }

    public function fileStatusLabel(): string
    {
        return $this->fileExists() ? 'File tersedia' : 'File tidak ditemukan';
    }
}
