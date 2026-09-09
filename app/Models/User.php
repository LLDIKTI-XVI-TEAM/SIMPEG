<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Rbac\PatenCapability;
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

    protected static function booted(): void
    {
        static::saved(function (self $user): void {
            if ($user->employee_id === null) {
                return;
            }

            // Saat SSO baru mengikat Employee ke User, inbox historis yang belum
            // memiliki recipient dapat dipulihkan hanya bila mappingnya tunggal.
            if (self::query()->where('employee_id', $user->employee_id)->count() === 1) {
                SimpegNotification::query()
                    ->where('user_id', $user->employee_id)
                    ->whereNull('recipient_user_id')
                    ->update(['recipient_user_id' => $user->id]);
            }
        });
    }

    /**
     * Hierarki role SIMPEG: rank lebih kecil = role lebih rendah.
     * Switch role hanya boleh dari role ber-rank lebih tinggi menuju role
     * ber-rank lebih rendah; aturan ini dipakai canSwitchToRole() dan turunannya.
     *
     * @var array<string, int>
     */
    public const ROLE_RANKS = [
        'super_admin' => 5,
        'admin_kepegawaian' => 4,
        'pimpinan' => 3,
        'kepala_bagian' => 2,
        'pegawai' => 1,
    ];

    /**
     * Label tampilan role untuk menu simulasi (konsisten dengan halaman admin).
     *
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        'admin_kepegawaian' => 'Admin Kepegawaian',
        'pimpinan' => 'Pimpinan',
        'kepala_bagian' => 'Kepala Bagian',
        'pegawai' => 'Pegawai',
    ];

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
            if ($this->isSwitchTargetBelowOriginalRole($this->temporary_role)) {
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
        // Issue #6: authenticated tidak sama dengan authorized. Role internal
        // yang kosong/tidak valid selalu fail-closed sebelum evaluasi PATEN
        // maupun RBAC; PATEN tidak boleh bergantung pada pivot, tetapi tetap
        // mensyaratkan identitas role internal yang sah.
        if (! $this->hasValidInternalRole()) {
            return false;
        }

        // PATEN tidak menggunakan pivot role_permissions, namun prasyaratnya
        // berbeda menurut konteks. Hak record seperti approval/proof tidak
        // boleh direduksi menjadi boolean global pada User.
        if (PatenCapability::requiresActiveEmployee($permission)) {
            return $this->hasActiveEmployeeIdentity();
        }

        if (PatenCapability::isUserContextCapability($permission)) {
            return $this->exists && $this->getKey() !== null;
        }

        if (PatenCapability::requiresRecordContext($permission)) {
            return false;
        }

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
     * Role internal sah adalah role efektif yang terdaftar pada tabel roles.
     * Berlaku untuk seluruh evaluasi PATEN/RBAC agar role kosong/tidak valid
     * selalu fail-closed sesuai kontrak Issue #6.
     *
     * Role kanonis (ROLE_RANKS) tidak memerlukan query: FK cascade pada
     * role_permissions menjamin tidak ada mapping yatim bila row role dihapus,
     * sehingga hasil pemeriksaan identik dengan query database. Role non-kanonis
     * tetap diverifikasi ke database untuk mendukung role kustom.
     */
    private function hasValidInternalRole(): bool
    {
        $role = $this->getEffectiveRole();

        if (! is_string($role) || $role === '') {
            return false;
        }

        if (isset(self::ROLE_RANKS[$role])) {
            return true;
        }

        return Role::query()->where('name', $role)->exists();
    }

    /**
     * Identitas pegawai aktif adalah prasyarat bersama capability PATEN.
     * Status menggunakan predicate kanonis Employee::isActive() dan tidak
     * pernah memakai nama role, claim SSO, maupun grant legacy.
     */
    private function hasActiveEmployeeIdentity(): bool
    {
        return $this->employee_id !== null && $this->employee()->first()?->isActive() === true;
    }

    /** Menentukan validitas hierarki target simulasi tanpa mengevaluasi permission. */
    private function isSwitchTargetBelowOriginalRole(string $targetRole): bool
    {
        if ($targetRole === $this->role) {
            return false;
        }

        $originRank = self::ROLE_RANKS[$this->role] ?? null;
        $targetRank = self::ROLE_RANKS[$targetRole] ?? null;

        return $originRank !== null && $targetRank !== null && $targetRank < $originRank;
    }

    /**
     * Mengecek permission pada role asli tanpa membaca temporary_role.
     * Dipakai untuk boundary origin-role agar evaluasi role efektif tidak rekursif.
     */
    public function hasOriginalRolePermission(string $permission): bool
    {
        if ($this->role === null || $this->role === '') {
            return false;
        }

        return Role::query()
            ->where('name', $this->role)
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * Switch Role dibatasi pada role asal yang disetujui. Hak memulai simulasi
     * tetap membutuhkan permission users.switch_role dari konfigurasi RBAC.
     */
    public function canInitiateSwitchRole(): bool
    {
        return isset(self::ROLE_RANKS[$this->role ?? ''])
            && $this->hasOriginalRolePermission('users.switch_role');
    }

    /**
     * Menentukan apakah role asli boleh memulai simulasi role.
     *
     * Capability memulai simulasi berasal dari permission asal. Hierarki hanya
     * menentukan target yang sah: selalu harus lebih rendah dari role asli.
     * Permission fitur biasa tetap ditentukan dari effective role saat simulasi.
     */
    public function canSwitchToRole(string $targetRole): bool
    {
        if (! $this->canInitiateSwitchRole()) {
            return false;
        }

        return $this->isSwitchTargetBelowOriginalRole($targetRole);
    }

    /**
     * Opsi role tujuan simulasi untuk role asal yang diizinkan: seluruh role
     * ber-rank lebih rendah dari role asli, dengan label tampilannya.
     *
     * @return array<string, string>
     */
    public function switchableRoleOptions(): array
    {
        if (! $this->canInitiateSwitchRole()) {
            return [];
        }

        $originRank = self::ROLE_RANKS[$this->role] ?? null;

        if ($originRank === null) {
            return [];
        }

        $options = [];

        foreach (self::ROLE_LABELS as $roleKey => $label) {
            $targetRank = self::ROLE_RANKS[$roleKey] ?? null;

            if ($targetRank !== null && $targetRank < $originRank) {
                $options[$roleKey] = $label;
            }
        }

        return $options;
    }

    /**
     * True bila user ini memiliki minimal satu role tujuan simulasi yang sah.
     */
    public function canSwitchToAnyRole(): bool
    {
        return $this->switchableRoleOptions() !== [];
    }
}
