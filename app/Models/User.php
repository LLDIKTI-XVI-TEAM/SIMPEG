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
     * temporary_permission (bila simulasi aktif) berperan sebagai PEMBATAS sekaligus PENAMBAH
     * izin sementara, tetapi tidak pernah menjadi otoritatif terhadap RBAC terkini: sebuah
     * permission hanya dianggap dimiliki jika (a) terdaftar di snapshot temporary_permission DAN
     * (b) MASIH dimiliki role efektif di tabel RBAC saat pemeriksaan dilakukan. Re-validasi ini
     * memastikan snapshot yang dapat kedaluwarsa (misalnya permission dicabut dari role target
     * setelah switch) tidak lagi lolos pemeriksaan selama simulasi belum di-revert.
     *
     * Jika temporary_role aktif tanpa temporary_permission, permission dicek terhadap role efektif tersebut.
     */
    public function hasPermission(string $permission): bool
    {
        $effectiveRole = $this->getEffectiveRole();

        if ($effectiveRole === null || $effectiveRole === '') {
            return false;
        }

        $roleOwnsPermission = Role::query()
            ->where('name', $effectiveRole)
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();

        // Simulasi dengan temporary_permission: izin sementara hanya berlaku apabila
        // masih dimiliki role efektif (keanggotaan divalidasi ulang setiap pemeriksaan).
        if ($this->temporary_role !== null && $this->temporary_permission !== null && $this->temporary_permission !== '') {
            return $this->hasTemporaryPermission($permission) && $roleOwnsPermission;
        }

        return $roleOwnsPermission;
    }

    /**
     * Memeriksa apakah permission terdaftar pada snapshot temporary_permission (JSON array atau CSV).
     */
    private function hasTemporaryPermission(string $permission): bool
    {
        $perms = json_decode((string) $this->temporary_permission, true);

        if (is_array($perms)) {
            return in_array($permission, $perms, true);
        }

        $perms = array_map('trim', explode(',', (string) $this->temporary_permission));

        return in_array($permission, $perms, true);
    }

    /**
     * Menentukan apakah user dapat switch ke target_role yang dipilih.
     * Switch role hanya diperbolehkan ke role dengan level hierarki lebih rendah.
     * Hierarki: super_admin (5) > admin_kepegawaian (4) > pimpinan (3) > kepala_bagian (2) > pegawai (1).
     */
    public function canSwitchToRole(string $targetRole): bool
    {
        // Tidak boleh switch ke role yang sama dengan role asli
        if ($targetRole === $this->role) {
            return false;
        }

        $hierarchy = [
            'super_admin' => 5,
            'admin_kepegawaian' => 4,
            'pimpinan' => 3,
            'kepala_bagian' => 2,
            'pegawai' => 1,
        ];

        $currentLevel = $hierarchy[$this->role] ?? 0;
        $targetLevel = $hierarchy[$targetRole] ?? 0;

        // Target harus ada di hierarki dan levelnya lebih rendah
        return $targetLevel > 0 && $targetLevel < $currentLevel;
    }
}
