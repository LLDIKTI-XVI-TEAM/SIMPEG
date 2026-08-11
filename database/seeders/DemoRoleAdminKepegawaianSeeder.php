<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoRoleAdminKepegawaianSeeder extends Seeder
{
    public function run(): void
    {
        // Wrapper kompatibilitas ini memilih akun dari katalog demo berdasarkan role,
        // sehingga KEYCLOAK_TEST_USERNAME tidak dapat mengubah akun role lain.
        $this->callWith(DemoSsoUserSeeder::class, ['onlyRole' => 'admin_kepegawaian']);
    }
}
