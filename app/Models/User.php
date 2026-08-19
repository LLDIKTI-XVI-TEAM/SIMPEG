<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $employee_id
 * @property string|null $role
 * @property string|null $temporary_role
 * @property string|null $temporary_permission
 * @property Carbon|null $temporary_role_started_at
 * @property string|null $temporary_role_switched_by
 * @property-read Employee|null $employee
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuid, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'keycloak_id',
        'keycloak_username',
        'employee_id',
        'role',
        'temporary_role',
        'temporary_permission',
        'temporary_role_started_at',
        'temporary_role_switched_by',
        'email_verified_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'temporary_role_started_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Mengembalikan role efektif pengguna, memperhitungkan simulasi role sementara.
     * Digunakan oleh middleware dan otorisasi untuk menentukan hak akses saat ini.
     * Jika temporary_role tidak lagi valid (misalnya role asli akun telah diturunkan sehingga
     * temporary_role tidak lebih rendah dari role asli), simulasi dianggap gugur dan mengembalikan role asli.
     */
    public function getEffectiveRole(): ?string
    {
        if ($this->temporary_role !== null) {
            // Validasi bahwa temporary_role harus selalu berada di hierarki yang lebih rendah dari role asli saat ini
            if ($this->canSwitchToRole($this->temporary_role)) {
                return $this->temporary_role;
            }
        }

        return $this->role;
    }

    /**
     * Mengecek apakah role pengguna memiliki permission tertentu.
     * Fail-closed: role kosong atau tidak terdaftar selalu mengembalikan false.
     *
     * Permission efektif SELALU diturunkan dinamis dari role efektif pada setiap pemeriksaan,
     * termasuk selama simulasi (dari role tujuan). temporary_permission hanyalah metadata
     * simulasi untuk keperluan audit/backward-compatibility dan tidak pernah memberikan
     * maupun membatasi otorisasi; perubahan konfigurasi permission berlaku pada request berikutnya.
     */
    public function hasPermission(string $permission): bool
    {
        $effectiveRole = $this->getEffectiveRole();

        if ($effectiveRole === null || $effectiveRole === '') {
            return false;
        }

        return Role::query()
            ->where('name', $effectiveRole)
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * Menentukan apakah user dapat switch ke target_role yang dipilih.
     *
     * Switch hanya diperbolehkan dari role asli super_admin menuju role tujuan yang diizinkan
     * (Admin Kepegawaian, Pimpinan, Kepala Bagian, atau Pegawai). Target bukan allowlist atau
     * switch ke role yang sama ditolak fail-closed. Allowlist eksplisit dipakai sebagai aturan
     * domain (bukan perhitungan level numerik) agar batas target selalu jelas dan stabil.
     */
    public function canSwitchToRole(string $targetRole): bool
    {
        // Switch ke role yang sama dengan role asli tidak pernah diizinkan.
        if ($targetRole === $this->role) {
            return false;
        }

        // Hanya role asli super_admin yang boleh melakukan simulasi; invite asal role lain
        // (miskonfigurasi) tidak boleh dianggap sebagai origin yang sah.
        if ($this->role !== 'super_admin') {
            return false;
        }

        return in_array($targetRole, [
            'admin_kepegawaian',
            'pimpinan',
            'kepala_bagian',
            'pegawai',
        ], true);
    }
}
