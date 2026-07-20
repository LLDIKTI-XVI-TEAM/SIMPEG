<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
