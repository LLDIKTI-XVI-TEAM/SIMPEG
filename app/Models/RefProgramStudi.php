<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referensi program studi yang dipilih pada data pendidikan pegawai.
 *
 * @property bool $is_active
 */
class RefProgramStudi extends Model
{
    use HasUuid;

    protected $table = 'ref_program_studi';

    protected $fillable = ['nama', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'program_studi_id');
    }

    /** @return HasMany<EducationHistory, $this> */
    public function educationHistories(): HasMany
    {
        return $this->hasMany(EducationHistory::class, 'program_studi_id');
    }
}
