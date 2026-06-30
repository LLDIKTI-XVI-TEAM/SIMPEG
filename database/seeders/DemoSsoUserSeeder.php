<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSsoUserSeeder extends Seeder
{
    public function run(): void
    {
        // Akun SSO demo (mis. demo-klabat) hanya untuk pengembangan/pengujian. Jika ditanam di
        // produksi, akun super_admin demo ini akan menempati slot bootstrap super_admin pertama
        // sehingga pegawai asli pertama yang login via SSO tidak otomatis menjadi super_admin.
        // Gerbang fail-closed: hanya local dan testing yang menanam akun demo ini.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $username = config('services.keycloak.test_username', 'demo-klabat');

        if ($username === '') {
            return;
        }

        $user = User::firstOrNew(['keycloak_username' => $username]);
        $user->fill([
            'name' => 'Demo Klabat',
            'email' => 'demo-klabat@example.test',
            'role' => 'super_admin',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = Str::random(48);
        }

        $user->save();
    }
}
