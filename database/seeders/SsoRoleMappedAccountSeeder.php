<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SsoRoleMappedAccountSeeder extends Seeder
{
    /**
     * Fixture akun uji UAT (HANYA local/testing): email Keycloak terverifikasi →
     * role internal yang diharapkan untuk AKUN PLACEHOLDER baru.
     *
     * Dataset ini sengaja hidup di seeder — bukan di config/ — agar production config
     * tidak pernah membawa pemetaan email → elevated role (Issue #6: daftar akun uji
     * adalah evidence UAT, bukan konfigurasi otorisasi). Auth callback TIDAK PERNAH
     * membaca dataset ini; role internal ditentukan aplikasi SIMPEG.
     *
     * @var array<string, string>
     */
    public const ROLE_MAPPING = [
        'dayensite@gmail.com' => 'super_admin',
        'sitedayen@gmail.com' => 'admin_kepegawaian',
        'dionkobi08@gmail.com' => 'pimpinan',
        'dayen6153@gmail.com' => 'kepala_bagian',
        'dionleonn05@gmail.com' => 'pegawai',
    ];

    /**
     * Menanam pegawai + user placeholder untuk setiap email pada fixture ROLE_MAPPING
     * di atas, sehingga login SSO pertama akun tersebut langsung menemukan tepat satu
     * pegawai.
     *
     * Seeder ini BUKAN sumber otorisasi: auth callback tidak pernah membaca
     * role_mapping; role internal ditentukan aplikasi SIMPEG.
     *
     * Seeder ini fail-closed: hanya berjalan di local/testing. Di produksi, pegawai
     * dengan email itu dibuat lewat alur admin/import yang normal.
     *
     * Seeder bersifat idempotent dan non-destructive terhadap data existing:
     * - status/lifecycle pegawai yang sudah ada TIDAK diubah (tidak dipaksa Aktif);
     *   status aktif hanya ditetapkan ketika placeholder pegawai benar-benar baru;
     * - relasi user → employee yang sudah ada TIDAK ditimpa; bila user sudah terhubung
     *   ke pegawai lain, mapping untuk email tersebut dilewati (reject, bukan overwrite)
     *   agar identitas SSO tidak berpindah pegawai secara diam-diam;
     * - nama, email internal, dan role user existing TIDAK ditimpa;
     * - resolver user memakai kontrak kanonis Issue #6: employee_id dulu, baru
     *   email case-insensitive (termasuk email_pribadi pegawai).
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach (self::ROLE_MAPPING as $mappedEmail => $mappedRole) {
            $email = trim((string) $mappedEmail);
            $role = trim((string) $mappedRole);

            if ($email === '' || $role === '') {
                continue;
            }

            // Lookup kanonis case-insensitive pada email legacy + email_pribadi kanonis,
            // agar pegawai existing tidak terlewat hanya karena kapitalisasi/kolom berbeda.
            // Discovery TANPA filter status aktif agar pegawai non-aktif pemilik email
            // kanonis tetap terlihat (tidak terjadi duplikasi placeholder dengan email
            // yang sama); kelayakannya diperiksa eksplisit setelah kandidat ditemukan.
            //
            // Sama seperti callback, kandidat diambil hingga 2 dan dihitung — seeder tidak
            // boleh memilih pegawai secara arbitrer saat pencocokan ambigu (kolom legacy
            // email tidak memiliki constraint unik).
            $candidates = Employee::query()
                ->where(function ($query) use ($email): void {
                    $query
                        ->whereRaw('lower(email) = ?', [strtolower($email)])
                        ->orWhereRaw('lower(email_pribadi) = ?', [strtolower($email)]);
                })
                ->limit(2)
                ->get()
                ->unique('id');

            if ($candidates->count() > 1) {
                // Lebih dari satu pegawai cocok → ambigu, sama seperti kontrak
                // callback: jangan pilih arbitrer dan jangan mengikat user ber-role ke
                // pegawai yang salah. Mapping dilewati + peringatan.
                $this->command?->warn(
                    "SSO mapped account '{$email}' dilewati: pencocokan pegawai ambigu (lebih dari satu pegawai cocok)."
                );

                continue;
            }

            $employee = $candidates->first();

            if ($employee && ! $employee->isActive()) {
                // Pegawai existing ternyata non-aktif (Nonaktif/Pensiun/Mutasi): JANGAN
                // mengubah status/lifecycle-nya dan JANGAN membuat placeholder duplikat
                // dengan email kanonis yang sama. Mapping dilewati; callback pun akan
                // menolak mapping ke pegawai non-aktif.
                $this->command?->warn(
                    "SSO mapped account '{$email}' dilewati: pegawai existing berstatus non-aktif."
                );

                continue;
            }

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

            // Resolver user kanonis: employee_id dulu (kontrak Issue #6), baru
            // fallback email case-insensitive. Keduanya di-resolve TERPISAH agar
            // konflik identitas terdeteksi — operator ?? di sini akan menyembunyikan
            // konflik yang nanti ditolak callback sebagai identity_conflict.
            $userByEmployee = User::where('employee_id', $employee->id)->first();
            $userByEmail = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();

            if ($userByEmployee && $userByEmail && $userByEmployee->isNot($userByEmail)) {
                $this->command?->warn(
                    "SSO mapped account '{$email}' dilewati: konflik identitas (email juga dimiliki user lain selain user pegawai)."
                );

                continue;
            }

            $user = $userByEmployee ?? $userByEmail ?? new User;

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
                // Nama hanya untuk placeholder baru atau bila nama existing kosong;
                // nama yang sudah ditetapkan admin/pengguna tidak boleh ditimpa (non-destructive).
                'name' => $user->exists && filled($user->name) ? $user->name : $this->displayName($email),
                // Email existing tidak ditimpa (user hasil resolver employee_id boleh
                // memegang email internal berbeda); email mapping hanya untuk user baru.
                'email' => $user->exists ? $user->email : $email,
                'role' => $user->exists ? $user->role : $role,
                'employee_id' => $employee->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            // Password acak hanya untuk placeholder user baru; user existing yang sudah
            // menetapkan password via profil tidak boleh ditimpa tanpa audit.
            if (! $user->exists) {
                $user->password = Str::random(48);
            }

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
