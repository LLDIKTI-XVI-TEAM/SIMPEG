<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        // Data Pribadi
        'nama_lengkap',
        'nip',
        'nik',
        'no_kk',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'agama_id',
        'status_kawin_id',
        'golongan_darah',
        'foto',
        'jenis_pegawai_id',
        'status_aktif',

        // Snapshot fields
        'golongan_terakhir',
        'pangkat_terakhir',
        'jabatan_terakhir',
        'kelas_jabatan',

        // Pendidikan snapshot
        'pendidikan_terakhir',
        'prodi_pendidikan_terakhir',

        // Pensiun
        'tanggal_pensiun',
        'tanggal_kenaikan_pangkat_berikutnya',
        'tanggal_kgb_berikutnya',
        'tanggal_akhir_kontrak',

        // Profil status
        'profil_status',

        // Data Kontak
        'alamat',
        'no_hp',
        'email',
        'no_telepon_rumah',

        // Flags
        'is_kinerja_baik',

        // SSO & RBAC
        'keycloak_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'tanggal_pensiun' => 'date',
            'tanggal_kenaikan_pangkat_berikutnya' => 'date',
            'tanggal_kgb_berikutnya' => 'date',
            'tanggal_akhir_kontrak' => 'date',
            'is_kinerja_baik' => 'boolean',
            'nik' => 'encrypted',
            'no_kk' => 'encrypted',
        ];
    }

    // --- Reference Relations ---

    public function agama(): BelongsTo
    {
        return $this->belongsTo(RefAgama::class, 'agama_id');
    }

    public function statusKawin(): BelongsTo
    {
        return $this->belongsTo(RefStatusPerkawinan::class, 'status_kawin_id');
    }

    public function jenisPegawai(): BelongsTo
    {
        return $this->belongsTo(RefJenisPegawai::class, 'jenis_pegawai_id');
    }

    // --- Child Relations ---

    public function families(): HasMany
    {
        return $this->hasMany(EmployeeFamily::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function rankHistories(): HasMany
    {
        return $this->hasMany(RankHistory::class);
    }

    public function positionHistories(): HasMany
    {
        return $this->hasMany(PositionHistory::class);
    }

    public function salaryHistories(): HasMany
    {
        return $this->hasMany(SalaryHistory::class);
    }

    public function disciplineRecords(): HasMany
    {
        return $this->hasMany(DisciplineRecord::class);
    }

    public function educationHistories(): HasMany
    {
        return $this->hasMany(EducationHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function supervisorAssignments(): HasMany
    {
        return $this->hasMany(SupervisorAssignment::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function ewsAlerts(): HasMany
    {
        return $this->hasMany(EwsAlert::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SimpegNotification::class, 'user_id');
    }

    public function appointment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Appointment::class);
    }

    // --- Helpers ---

    public function latestRank(): ?RankHistory
    {
        return $this->rankHistories()->where('is_latest', true)->first();
    }

    public function latestPosition(): ?PositionHistory
    {
        return $this->positionHistories()->where('is_latest', true)->first();
    }

    public function latestSalary(): ?SalaryHistory
    {
        return $this->salaryHistories()->where('is_latest', true)->first();
    }

    public function currentSupervisor(): ?SupervisorAssignment
    {
        return $this->supervisorAssignments()
            ->whereNull('tanggal_berakhir')
            ->first();
    }
}
