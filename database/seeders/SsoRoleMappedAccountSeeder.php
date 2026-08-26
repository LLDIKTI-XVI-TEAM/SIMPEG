<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SsoRoleMappedAccountSeeder extends Seeder
{
    /**
     * Menanam pegawai + user placeholder untuk setiap email yang terdaftar di
     * config services.keycloak.role_mapping, sehingga login SSO pertama akun
     * tersebut langsung menemukan tepat satu pegawai dan mendapat role internalnya.
     *
     * Seeder ini fail-closed: hanya berjalan di local/testing. Di produksi, pegawai
     * dengan email itu dibuat lewat alur admin/import yang normal.
     *
     * Seeder bersifat idempotent dan non-destructive terhadap data existing:
     * - status/lifecycle pegawai yang sudah ada TIDAK diubah (tidak dipaksa Aktif);
     *   status aktif hanya ditetapkan ketika placeholder pegawai benar-benar baru;
     * - relasi user → employee yang sudah ada TIDAK ditimpa; bila user sudah terhubung
     *   ke pegawai lain, mapping untuk email tersebut dilewati (reject, bukan overwrite)
     *   agar identitas SSO tidak berpindah pegawai secara diam-diam.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $email = trim((string) $email);
            $role = trim((string) $role);

            if ($email === '' || $role === '') {
                continue;
            }

            $employee = Employee::where('email', $email)->first();

            if (! $employee) {
                // Placeholder baru: hanya di sini status aktif + role ditetapkan.
                $employee = Employee::factory()->create([
                    'nama_lengkap' => $this->displayName($email),
                    'email' => $email,
                    'status_aktif' => 'Aktif',
                    'role' => $role,
                ]);
            }
            // Pegawai existing: status/role domain tidak disentuh agar seeder ulang
            // tidak menghidupkan kembali pegawai Non-Aktif/Pensiun/Mutasi, dan tidak
            // menaikkan/menurunkan role yang sudah ditetapkan admin.

            $user = User::where('email', $email)->first() ?? new User;

            // Tolak ketidakcocokan relasi: user yang sudah terhubung ke pegawai lain
            // tidak boleh dipindahkan ke pegawai hasil lookup email (email pegawai asal
            // mungkin berubah setelah akun dipetakan). Mapping dilewati + peringatan.
            if ($user->exists && $user->employee_id !== null && $user->employee_id !== $employee->id) {
                $this->command?->warn(
                    "SSO mapped account '{$email}' dilewati: user sudah terhubung ke pegawai lain."
                );

                continue;
            }

            $user->fill([
                'name' => $this->displayName($email),
                'email' => $email,
                'role' => $user->exists ? $user->role : $role,
                'employee_id' => $employee->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => Str::random(48),
            ]);

            $user->save();

            $this->command?->info("SSO mapped account '{$email}' seeded with role: {$user->role}.");
        }
    }

    private function displayName(string $email): string
    {
        $localPart = strstr($email, '@', true) ?: $email;

        return ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }
}
