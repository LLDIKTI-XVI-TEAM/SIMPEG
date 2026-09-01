<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class SsoRoleMappedAccountSeeder extends Seeder
{
    /**
     * Persona SSO sintetis (HANYA local/testing): tidak ada email, username, atau
     * credential Keycloak nyata yang disimpan di repository. Password di bawah
     * hanya dipakai untuk fixture lokal dan bukan credential UAT/produksi.
     *
     * Akun-akun ini adalah evidence UAT Issue #6 — persona yang dipakai untuk
     * browser smoke test dengan login Keycloak nyata. Auth callback TIDAK PERNAH
     * membaca daftar ini sebagai otorisasi: role internal tetap ditentukan
     * aplikasi SIMPEG (role kosong pada pegawai valid → pegawai, role existing
     * tidak pernah dioverwrite).
     *
     * @var list<array{email: string, username: string, password: string, role: string}>
     */
    public const UAT_ACCOUNTS = [
        [
            'email' => 'super-admin@example.test',
            'username' => 'fixture-super-admin',
            'password' => 'fixture-only-super-admin',
            'role' => 'super_admin',
        ],
        [
            'email' => 'admin-kepegawaian@example.test',
            'username' => 'fixture-admin-kepegawaian',
            'password' => 'fixture-only-admin-kepegawaian',
            'role' => 'admin_kepegawaian',
        ],
        [
            'email' => 'pimpinan@example.test',
            'username' => 'fixture-pimpinan',
            'password' => 'fixture-only-pimpinan',
            'role' => 'pimpinan',
        ],
        [
            'email' => 'kepala-bagian@example.test',
            'username' => 'fixture-kepala-bagian',
            'password' => 'fixture-only-kepala-bagian',
            'role' => 'kepala_bagian',
        ],
        [
            'email' => 'pegawai@example.test',
            'username' => 'fixture-pegawai',
            'password' => 'fixture-only-pegawai',
            'role' => 'pegawai',
        ],
    ];

    /**
     * Pemetaan email → role yang diharapkan, diturunkan dari UAT_ACCOUNTS agar
     * tidak ada duplikasi daftar akun uji di source.
     *
     * @return array<string, string>
     */
    public static function roleMapping(): array
    {
        $mapping = [];

        foreach (self::UAT_ACCOUNTS as $account) {
            $mapping[$account['email']] = $account['role'];
        }

        return $mapping;
    }

    /**
     * Menanam pegawai + user untuk setiap akun UAT SSO di atas, sehingga login
     * SSO pertama akun tersebut langsung menemukan tepat satu pegawai dan
     * preferred_username-nya tersedia sebagai atribut login tambahan.
     *
     * Seeder ini BUKAN sumber otorisasi: auth callback tidak pernah membaca
     * daftar ini; role internal ditentukan aplikasi SIMPEG.
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
     * - password dan keycloak_username adalah milik fixture akun UAT: direset
     *   idempoten oleh seeder ini setiap dijalankan (mengikuti pola akun demo).
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach (self::UAT_ACCOUNTS as $uatAccount) {
            $email = trim((string) $uatAccount['email']);
            $username = trim((string) $uatAccount['username']);
            $role = trim((string) $uatAccount['role']);

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
                    "SSO UAT account '{$email}' dilewati: pencocokan pegawai ambigu (lebih dari satu pegawai cocok)."
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
                    "SSO UAT account '{$email}' dilewati: pegawai existing berstatus non-aktif."
                );

                continue;
            }

            // Validasi user pemilik email SEBELUM membuat placeholder: bila email
            // mapping sudah dimiliki user yang terhubung ke pegawai lain, mapping pasti
            // ditolak callback (konflik identitas). Membuat placeholder lebih dulu akan
            // meninggalkan employee palsu tanpa user pada setiap kasus konflik ini.
            $userByEmail = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();

            if ($userByEmail && $userByEmail->employee_id !== null && (! $employee || $userByEmail->employee_id !== $employee->id)) {
                $this->command?->warn(
                    "SSO UAT account '{$email}' dilewati: email sudah dimiliki user yang terhubung ke pegawai lain."
                );

                continue;
            }

            // Sejajar invariant runtime resolveUserForEmployee (manual_binding_required):
            // user tanpa pegawai yang ber-role selain pegawai tidak boleh di-bind otomatis
            // lewat seeder — subject SSO pemilik email bisa terikat lalu login dengan
            // privilege existing tanpa pemetaan admin. Mapping dilewati + peringatan.
            if ($userByEmail && $userByEmail->employee_id === null && $userByEmail->role !== 'pegawai') {
                $this->command?->warn(
                    "SSO UAT account '{$email}' dilewati: user ber-privilege '{$userByEmail->role}' belum terhubung pegawai — petakan manual via jalur administratif SIMPEG."
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

            if ($userByEmployee && $userByEmail && $userByEmployee->isNot($userByEmail)) {
                $this->command?->warn(
                    "SSO UAT account '{$email}' dilewati: konflik identitas (email juga dimiliki user lain selain user pegawai)."
                );

                continue;
            }

            $user = $userByEmployee ?? $userByEmail ?? new User;

            // Tolak ketidakcocokan relasi: user yang sudah terhubung ke pegawai lain
            // tidak boleh dipindahkan ke pegawai hasil lookup email (email pegawai asal
            // mungkin berubah setelah akun dipetakan). Mapping dilewati + peringatan.
            if ($user->exists && $user->employee_id !== null && $user->employee_id !== $employee->id) {
                $this->command?->warn(
                    "SSO UAT account '{$email}' dilewati: user sudah terhubung ke pegawai lain."
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
            ]);

            // Status verifikasi email user existing TIDAK disentuh: Keycloak tidak pernah
            // memverifikasi email internal user, jadi menandainya terverifikasi berdasar
            // fixture adalah klaim palsu (konsisten dengan kontrak runtime callback).
            // Hanya placeholder baru yang emailnya memang berasal dari fixture yang
            // ditandai terverifikasi.
            if (! $user->exists && $user->email_verified_at === null) {
                $user->email_verified_at = now();
            }

            // keycloak_username adalah atribut login tambahan (identitas kanonis tetap
            // keycloak_id): diklaim hanya bila belum dipakai user lain — benturan tidak
            // boleh menggagalkan seeding akun.
            if ($username !== '' && $this->usernameIsAvailable($user, $username)) {
                $user->keycloak_username = $username;
            } elseif ($username !== '') {
                $this->command?->warn(
                    "SSO UAT account '{$email}': keycloak_username '{$username}' sudah dipakai user lain, diklaim dilewati."
                );
            }

            // Password adalah milik fixture akun UAT (dipakai tooling/QA lokal):
            // direset idempoten setiap seeder dijalankan — mengikuti pola akun demo.
            // Model memakai cast 'hashed' sehingga nilai plain langsung di-hash.
            $user->password = $uatAccount['password'];

            $user->save();

            $this->command?->info("SSO UAT account '{$email}' seeded with role: {$user->role}.");
        }
    }

    private function displayName(string $email): string
    {
        $localPart = strstr($email, '@', true) ?: $email;

        return ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }

    /**
     * True bila keycloak_username belum dipakai user lain (atau milik user ini sendiri).
     */
    private function usernameIsAvailable(User $user, string $username): bool
    {
        $query = User::query()->whereRaw('lower(keycloak_username) = ?', [strtolower($username)]);

        if ($user->exists) {
            $query->whereKeyNot($user->getKey());
        }

        return ! $query->exists();
    }
}
