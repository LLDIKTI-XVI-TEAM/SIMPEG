<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transisi status kepegawaian terjadwal (K-STATUS-06): tanggal efektif di masa
 * depan disimpan di sini tanpa mengubah snapshot; scheduler menerapkannya saat
 * jatuh tempo secara idempoten.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $status_pegawai_id
 * @property Carbon $tanggal_efektif
 * @property string $kind
 * @property string|null $keterangan
 * @property string|null $status_note
 * @property string|null $document_id
 * @property string|null $source_ews_alert_id
 * @property string|null $created_by_user_id
 * @property string|null $actor_user_id_snapshot
 * @property string|null $actor_name_snapshot
 * @property string|null $actor_original_role
 * @property string|null $actor_effective_role
 * @property string|null $authorization_permission
 * @property string|null $authorization_action
 * @property bool|null $actor_simulation
 * @property string|null $actor_ip_address
 * @property string|null $actor_user_agent
 * @property string $provenance_status
 * @property bool $is_applied
 * @property Carbon|null $applied_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Employee $employee
 * @property-read RefStatusPegawai $statusPegawai
 * @property-read Document|null $document
 * @property-read EwsAlert|null $sourceEwsAlert
 * @property-read User|null $createdBy
 */
class EmployeeStatusTransition extends Model
{
    use HasUuid;

    public const KIND_DEACTIVATE = 'deactivate';

    public const KIND_RESTORE = 'restore';

    public const KIND_STATUS = 'status';

    public const KIND_EWS_RETIREMENT = 'ews_retirement';

    public const PROVENANCE_CAPTURED = 'captured';

    public const PROVENANCE_MISSING = 'missing';

    public const PROVENANCE_LEGACY_HISTORICAL = 'legacy_historical';

    protected $fillable = [
        'employee_id',
        'status_pegawai_id',
        'tanggal_efektif',
        'kind',
        'keterangan',
        'status_note',
        'document_id',
        'created_by_user_id',
        'is_applied',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_efektif' => 'date',
            'is_applied' => 'boolean',
            'applied_at' => 'datetime',
            'actor_simulation' => 'boolean',
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

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<EwsAlert, $this> */
    public function sourceEwsAlert(): BelongsTo
    {
        return $this->belongsTo(EwsAlert::class, 'source_ews_alert_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
