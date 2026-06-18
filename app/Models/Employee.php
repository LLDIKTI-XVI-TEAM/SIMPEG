<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'nama_pegawai',
        'email_pegawai',
        'golongan',
        'jabatan',
        'kelas_jabatan',
        'nip',
        'nomor_telepon',
        'pangkat',
        'pendidikan_terakhir',
        'pensiun',
        'person',
        'person_formula',
        'prodi_pendidikan_terakhir',
        'status_kepegawaian',
        'tanggal_lahir',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pensiun' => 'date',
            'tanggal_lahir' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
