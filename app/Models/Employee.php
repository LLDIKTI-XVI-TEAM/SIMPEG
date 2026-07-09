<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $nama_lengkap
 * @property string|null $nama_dengan_gelar
 * @property string $nip
 * @property string|null $jabatan_terakhir
 * @property string|null $golongan_terakhir
 * @property string|null $jenis_pegawai_id
 * @property string|null $status_aktif
 * @property string|null $status_pegawai_id
 * @property string|null $kepala_bagian_id
 * @property string|null $kelas_jabatan
 * @property string|null $kelas_jabatan_terakhir
 * @property string|null $email
 * @property string|null $email_pribadi
 * @property Carbon|null $tanggal_lahir
 * @property Carbon|null $tanggal_pensiun
 * @property Carbon|null $tanggal_kenaikan_pangkat_berikutnya
 * @property Carbon|null $tanggal_kgb_berikutnya
 * @property Carbon|null $tanggal_akhir_kontrak
 * @property Carbon|null $deleted_at
 * @property bool $is_kinerja_baik
 * @property bool $is_kepala_lembaga
 * @property-read RefJenisPegawai|null $jenisPegawai
 * @property-read Employee|null $kepalaBagian
 * @property-read Collection<int, PositionHistory> $positionHistories
 * @property-read Collection<int, DisciplineRecord> $disciplineRecords
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        // Data Pribadi
        'nama_lengkap',
        'nama_dengan_gelar',
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
        'status_pegawai_id',
        'status_keterangan',
        'kepala_bagian_id',

        // Snapshot fields
        'golongan_terakhir',
        'pangkat_terakhir',
        'jabatan_terakhir',
        'kelas_jabatan',
        'kelas_jabatan_terakhir',

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
        'email_pribadi',
        'no_telepon_rumah',

        // Flags
        'is_kinerja_baik',
        'is_kepala_lembaga',

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
            'is_kepala_lembaga' => 'boolean',
            'nik' => 'encrypted',
            'no_kk' => 'encrypted',
        ];
    }

    // --- Reference Relations ---

    /** @return BelongsTo<RefAgama, $this> */
    public function agama(): BelongsTo
    {
        return $this->belongsTo(RefAgama::class, 'agama_id');
    }

    /** @return BelongsTo<RefStatusPerkawinan, $this> */
    public function statusKawin(): BelongsTo
    {
        return $this->belongsTo(RefStatusPerkawinan::class, 'status_kawin_id');
    }

    /** @return BelongsTo<RefJenisPegawai, $this> */
    public function jenisPegawai(): BelongsTo
    {
        return $this->belongsTo(RefJenisPegawai::class, 'jenis_pegawai_id');
    }

    /** @return BelongsTo<RefStatusPegawai, $this> */
    public function statusPegawai(): BelongsTo
    {
        return $this->belongsTo(RefStatusPegawai::class, 'status_pegawai_id');
    }

    // --- Child Relations ---

    /** @return HasMany<EmployeeFamily, $this> */
    public function families(): HasMany
    {
        return $this->hasMany(EmployeeFamily::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<RankHistory, $this> */
    public function rankHistories(): HasMany
    {
        return $this->hasMany(RankHistory::class);
    }

    /** @return HasMany<PositionHistory, $this> */
    public function positionHistories(): HasMany
    {
        return $this->hasMany(PositionHistory::class);
    }

    /** @return HasMany<SalaryHistory, $this> */
    public function salaryHistories(): HasMany
    {
        return $this->hasMany(SalaryHistory::class);
    }

    /** @return HasMany<DisciplineRecord, $this> */
    public function disciplineRecords(): HasMany
    {
        return $this->hasMany(DisciplineRecord::class);
    }

    /** @return HasMany<EducationHistory, $this> */
    public function educationHistories(): HasMany
    {
        return $this->hasMany(EducationHistory::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<SupervisorAssignment, $this> */
    public function supervisorAssignments(): HasMany
    {
        return $this->hasMany(SupervisorAssignment::class);
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** @return HasMany<LeaveBalance, $this> */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** @return HasMany<EwsAlert, $this> */
    public function ewsAlerts(): HasMany
    {
        return $this->hasMany(EwsAlert::class);
    }

    /** @return HasMany<SimpegNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(SimpegNotification::class, 'user_id');
    }

    /** @return HasOne<Appointment, $this> */
    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }

    // --- Supervisor Relations ---

    /** @return BelongsTo<Employee, $this> */
    public function kepalaBagian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'kepala_bagian_id');
    }

    /** @return HasMany<Employee, $this> */
    public function bawahanBagian(): HasMany
    {
        return $this->hasMany(Employee::class, 'kepala_bagian_id');
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

    public function getFotoPublicPathAttribute(): ?string
    {
        $path = trim((string) $this->getRawOriginal('foto'));

        if ($path === '' || $path === '0') {
            return null;
        }

        $path = ltrim($path, '/');

        foreach (['public/', 'storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        return $path !== '' && $path !== '0' ? $path : null;
    }

    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto_public_path
            ? asset('storage/'.$this->foto_public_path)
            : null;
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['email_pribadi'] ?? $value,
            set: fn ($value) => [
                'email' => $value,
                'email_pribadi' => $value,
            ],
        );
    }

    protected function statusAktif(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['status_pegawai_id'] ?? null
                ? (RefStatusPegawai::whereKey($attributes['status_pegawai_id'])->value('nama') ?? $value)
                : $value,
            set: function ($value): array {
                $statusPegawaiId = $value !== null
                    ? RefStatusPegawai::where('nama', $value)->value('id')
                    : null;

                return [
                    'status_aktif' => $value,
                    'status_pegawai_id' => $statusPegawaiId,
                ];
            },
        );
    }

    protected function statusPegawaiId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $value,
            set: fn ($value) => [
                'status_pegawai_id' => $value,
                'status_aktif' => $value
                    ? (RefStatusPegawai::whereKey($value)->value('nama') ?? 'Aktif')
                    : null,
            ],
        );
    }

    protected function emailPribadi(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $value ?? ($attributes['email'] ?? null),
            set: fn ($value) => [
                'email_pribadi' => $value,
                'email' => $value,
            ],
        );
    }

    protected function kelasJabatan(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['kelas_jabatan_terakhir'] ?? $value,
            set: fn ($value) => [
                'kelas_jabatan' => $value,
                'kelas_jabatan_terakhir' => $value,
            ],
        );
    }

    protected function kelasJabatanTerakhir(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $value ?? ($attributes['kelas_jabatan'] ?? null),
            set: fn ($value) => [
                'kelas_jabatan_terakhir' => $value,
                'kelas_jabatan' => $value,
            ],
        );
    }
}
