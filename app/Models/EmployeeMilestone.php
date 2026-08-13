<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Model untuk menyimpan milestone kepegawaian yang sudah dikalkulasi.
 * Tujuan: Optimisasi EWS scheduler agar tidak perlu kalkulasi ulang setiap hari.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $type (kenaikan_pangkat, kgb, pensiun, satyalancana, pppk_contract_end)
 * @property string $milestone_key
 * @property Carbon $milestone_date
 * @property Carbon $calculated_at
 * @property array|null $metadata
 * @property bool $is_active
 * @property-read Employee $employee
 */
class EmployeeMilestone extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'type',
        'milestone_key',
        'milestone_date',
        'calculated_at',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'milestone_date' => 'date',
        'calculated_at' => 'date',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Konstanta untuk tipe milestone.
     */
    public const TYPE_KENAIKAN_PANGKAT = 'kenaikan_pangkat';

    public const TYPE_KGB = 'kgb';

    public const TYPE_PENSIUN = 'pensiun';

    public const TYPE_SATYALANCANA = 'satyalancana';

    public const TYPE_PPPK_CONTRACT_END = 'pppk_contract_end';

    public const KEY_DEFAULT = 'default';

    /** @var list<string> */
    public const SATYALANCANA_KEYS = ['10', '20', '30'];

    /**
     * Menetapkan slot stabil agar constraint database dapat membedakan milestone scalar
     * dari tiga milestone masa kerja Satyalancana.
     */
    protected static function booted(): void
    {
        static::saving(function (EmployeeMilestone $milestone): void {
            $expectedKey = self::keyFor($milestone->type, $milestone->metadata);

            if (empty($milestone->milestone_key)) {
                $milestone->milestone_key = $expectedKey;

                return;
            }

            if ((string) $milestone->milestone_key !== $expectedKey) {
                throw new InvalidArgumentException("Milestone key tidak valid untuk tipe {$milestone->type}.");
            }
        });
    }

    /** @param  array<string, mixed>|null  $metadata */
    public static function keyFor(string $type, ?array $metadata = null): string
    {
        if ($type !== self::TYPE_SATYALANCANA) {
            return self::KEY_DEFAULT;
        }

        $years = $metadata['satyalancana_years'] ?? $metadata['years_of_service'] ?? null;
        $key = is_numeric($years) && (float) $years === (float) (int) $years
            ? (string) (int) $years
            : null;

        if ($key === null || ! in_array($key, self::SATYALANCANA_KEYS, true)) {
            throw new InvalidArgumentException('Milestone Satyalancana hanya mendukung masa kerja 10, 20, atau 30 tahun.');
        }

        return $key;
    }
}
