<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SsoRoleMappedAccountSeeder extends Seeder
{
    /**
     * Persona SSO sintetis (HANYA local/testing). Password autentikasi dikelola
     * sepenuhnya oleh Keycloak dan tidak disimpan oleh seeder SIMPEG.
     *
     * Auth callback TIDAK PERNAH membaca daftar ini sebagai otorisasi: role internal tetap ditentukan
     * aplikasi SIMPEG (role kosong pada pegawai valid → pegawai, role existing
     * tidak pernah dioverwrite).
     *
     * @var list<array{email: string, username: string, role: string}>
     */
    public const UAT_ACCOUNTS = [
        [
            'email' => 'fixture-super-admin@example.test',
            'username' => 'fixture-super-admin',
            'role' => 'super_admin',
        ],
        [
            'email' => 'fixture-admin-kepegawaian@example.test',
            'username' => 'fixture-admin-kepegawaian',
            'role' => 'admin_kepegawaian',
        ],
        [
            'email' => 'fixture-pimpinan@example.test',
            'username' => 'fixture-pimpinan',
            'role' => 'pimpinan',
        ],
        [
            'email' => 'fixture-kepala-bagian@example.test',
            'username' => 'fixture-kepala-bagian',
            'role' => 'kepala_bagian',
        ],
        [
            'email' => 'fixture-pegawai@example.test',
            'username' => 'fixture-pegawai',
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
     * Menanam pegawai + user untuk setiap persona sintetis agar fixture autentikasi
     * dan role lokal tersedia tanpa menyimpan identitas UAT nyata di repository.
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
     * - keycloak_username adalah atribut fixture; direset idempoten oleh seeder
     *   saat belum berbenturan. Password lokal tidak pernah disetel karena login
     *   diautentikasi oleh Keycloak.
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
            //
            // Lookup dibatasi 2 kandidat: 0 = tidak ada pemilik, 1 = dipakai,
            // >1 = duplikat case-insensitive legacy → fail-closed (warn + skip),
            // tidak pernah memilih arbitrer via first().
            $userByEmailCandidates = User::whereRaw('lower(email) = ?', [strtolower($email)])
                ->limit(2)
                ->get();

            if ($userByEmailCandidates->count() > 1) {
                $this->command?->warn(
                    "SSO UAT account '{$email}' dilewati: email dimiliki lebih dari satu user (duplikat case-insensitive legacy)."
                );

                continue;
            }

            $userByEmail = $userByEmailCandidates->first();

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
            $isNewUser = ! $user->exists;

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
                'password' => $isNewUser ? Str::random(48) : $user->password,
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

            // Password lokal tidak dipakai untuk autentikasi Keycloak dan tidak boleh
            // dibuat/reset oleh seeder. User baru mendapat password acak saat dibuat
            // agar memenuhi schema tanpa menyimpan credential SSO.

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
