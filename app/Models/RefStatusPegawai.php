<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property string $kelompok
 * @property bool $is_default
 * @property bool $is_active
 */
class RefStatusPegawai extends Model
{
    use HasUuid;

    /** @var array<string, string> */
    private const ACTIVE_GROUPS = [
        'aktif' => 'Aktif',
        'aktif/khusus' => 'Aktif/khusus',
    ];

    protected $table = 'ref_status_pegawai';

    protected $fillable = ['kode', 'nama', 'kelompok', 'keterangan', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Menilai kelompok aktif dengan normalisasi yang juga dipakai query Employee.
     */
    public static function isActiveGroup(mixed $group): bool
    {
        return is_string($group)
            && array_key_exists(mb_strtolower(trim($group)), self::ACTIVE_GROUPS);
    }

    /**
     * Nilai lowercase ini menjadi satu sumber predicate SQL status aktif.
     *
     * @return list<string>
     */
    public static function normalizedActiveGroups(): array
    {
        return array_keys(self::ACTIVE_GROUPS);
    }

    /**
     * Nilai kanonis kelompok aktif untuk consumer yang memerlukan daftar display.
     *
     * @return list<string>
     */
    public static function activeGroups(): array
    {
        return array_values(self::ACTIVE_GROUPS);
    }

    /**
     * Scope kelompok aktif yang menjaga normalisasi query tetap sama dengan predicate instance.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereActiveGroup(Builder $query): Builder
    {
        return $query->whereIn(
            DB::raw('LOWER(TRIM(kelompok))'),
            self::normalizedActiveGroups(),
        );
    }

    /**
     * Input kelompok aktif disimpan kanonis, sedangkan kelompok nonaktif bebas
     * dipertahankan karena katalog status dapat berkembang.
     */
    protected function kelompok(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value): mixed {
                if (! is_string($value)) {
                    return $value;
                }

                $normalized = mb_strtolower(trim($value));

                return self::ACTIVE_GROUPS[$normalized] ?? $value;
            },
        );
    }
}
