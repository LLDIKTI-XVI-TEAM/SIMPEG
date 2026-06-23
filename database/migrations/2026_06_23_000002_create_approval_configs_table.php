<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('approval_configs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Insert default users if they do not exist
        $users = [
            [
                'name' => 'Dra. Merlina Rahman',
                'email' => 'merlina.rahman@example.com',
                'role' => 'Admin Kepegawaian',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Riza Hamzah',
                'email' => 'riza.hamzah@example.com',
                'role' => 'Admin Kepegawaian',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Dr. Abdul Kadir',
                'email' => 'abdul.kadir@example.com',
                'role' => 'Pimpinan',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Nurarningsih Dumbea, S.P.',
                'email' => 'rainingdumbea47@gmail.com',
                'role' => 'Admin Kepegawaian',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($users as $userData) {
            // Check if user exists first to avoid duplicates
            $exists = DB::table('users')->where('email', $userData['email'])->exists();
            if (!$exists) {
                DB::table('users')->insert($userData);
            }
        }

        // Seed default config
        $merlina = DB::table('users')->where('email', 'merlina.rahman@example.com')->first();
        $kadir = DB::table('users')->where('email', 'abdul.kadir@example.com')->first();

        if ($merlina && $kadir) {
            DB::table('approval_configs')->insert([
                ['key' => 'stage2_approver_id', 'value' => (string) $merlina->id, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'stage3_approver_id', 'value' => (string) $kadir->id, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_configs');
    }
};
