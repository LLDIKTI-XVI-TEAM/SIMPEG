<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $employee_id
 * @property string|null $status_pegawai_id
 * @property string $status_nama
 * @property string|null $keterangan
 * @property Carbon $tanggal_efektif
 * @property string|null $nomor_berkas
 * @property string|null $file_sk
 * @property string|null $changed_by_user_id
 * @property bool $is_latest
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 * @property-read RefStatusPegawai|null $statusPegawai
 * @property-read User|null $changedBy
 */
class EmployeeStatusHistory extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'status_pegawai_id',
        'status_nama',
        'keterangan',
        'tanggal_efektif',
        'nomor_berkas',
        'file_sk',
        'changed_by_user_id',
        'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_efektif' => 'date',
            'is_latest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<RefStatusPegawai, $this> */
    public function statusPegawai(): BelongsTo
    {
        return $this->belongsTo(RefStatusPegawai::class, 'status_pegawai_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'nomor_berkas', 'nomor_dokumen')
            ->where('jenis_dokumen', 'sk_status_pegawai');
    }
}
