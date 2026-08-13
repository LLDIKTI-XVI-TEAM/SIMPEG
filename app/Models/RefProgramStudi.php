<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Observers\RefProgramStudiObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referensi program studi yang dipilih pada data pendidikan pegawai.
 *
 * @property bool $is_active
 */
#[ObservedBy(RefProgramStudiObserver::class)]
class RefProgramStudi extends Model
{
    use HasUuid;

    protected $table = 'ref_program_studi';

    protected $fillable = ['nama', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Keep the stored value and validation input on one canonical form. */
    public function setNamaAttribute(string $value): void
    {
        $this->attributes['nama'] = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
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
