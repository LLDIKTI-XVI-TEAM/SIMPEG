<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApprovalConfigSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Dra. Merlina Rahman',
                'email' => 'merlina.rahman@example.com',
                'role' => 'admin_kepegawaian',
                'password' => bcrypt('password'),
            ],
            [
                'name' => 'Riza Hamzah',
                'email' => 'riza.hamzah@example.com',
                'role' => 'admin_kepegawaian',
                'password' => bcrypt('password'),
            ],
            [
                'name' => 'Dr. Abdul Kadir',
                'email' => 'abdul.kadir@example.com',
                'role' => 'pimpinan',
                'password' => bcrypt('password'),
            ],
            [
                'name' => 'Nurarningsih Dumbea, S.P.',
                'email' => 'rainingdumbea47@gmail.com',
                'role' => 'admin_kepegawaian',
                'password' => bcrypt('password'),
            ]
        ];

        foreach ($users as $userData) {
            User::firstOrCreate(['email' => $userData['email']], $userData);
        }

        $merlina = User::where('email', 'merlina.rahman@example.com')->first();
        $kadir = User::where('email', 'abdul.kadir@example.com')->first();

        if ($merlina && $kadir) {
            DB::table('approval_configs')->updateOrInsert(
                ['key' => 'stage2_approver_id'],
                ['value' => (string) $merlina->id, 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('approval_configs')->updateOrInsert(
                ['key' => 'stage3_approver_id'],
                ['value' => (string) $kadir->id, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
