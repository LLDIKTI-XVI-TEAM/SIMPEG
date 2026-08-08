<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mitigasi race condition pada Windows/Podman bind mount di mana proses eksternal
        // (seperti wkhtmltopdf) mungkin masih memegang file handle untuk sepersekian detik.
        $attempts = 0;
        while (true) {
            try {
                Storage::fake('local');
                Storage::fake('public');
                break;
            } catch (\UnexpectedValueException $e) {
                $attempts++;
                if ($attempts >= 5) {
                    throw $e;
                }
                usleep(100_000); // Tunggu 100ms sebelum mencoba lagi
            }
        }
    }

    /**
     * Menyediakan reference data yang konsisten bagi test yang membutuhkan relasi master data.
     */
    protected function seedReferenceData(): void
    {
        $this->seed(ReferenceSeeder::class);
    }

    /**
     * Menyediakan permission matrix sebelum test melakukan pemeriksaan otorisasi.
     */
    protected function seedRbac(): void
    {
        $this->seed(RbacSeeder::class);
    }

    /**
     * Membuat dan mengautentikasi user dengan role SIMPEG tanpa mengulang wiring sesi di setiap test.
     */
    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }
}
