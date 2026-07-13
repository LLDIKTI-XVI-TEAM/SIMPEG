<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Metadata dokumen pendukung Kepala Lembaga yang berkasnya disimpan secara privat.
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $uploaded_by
 * @property string $stored_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $deleted_at
 * @property-read Employee $employee
 * @property-read User|null $uploader
 */
class KepalaLembagaSupportingDocument extends Model
{
    use HasUuid, SoftDeletes;

    public const STORAGE_DISK = 'local';

    public const PATH_PREFIX = 'cuti/kepala-lembaga-support';

    protected $fillable = [
        'employee_id',
        'uploaded_by',
        'stored_path',
        'original_filename',
        'mime_type',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
