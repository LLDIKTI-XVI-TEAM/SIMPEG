<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'keycloak_id' => null,
            'keycloak_username' => null,
            'employee_id' => null,
            'role' => null,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'super_admin',
        ]);
    }

    public function adminKepegawaian(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin_kepegawaian',
        ]);
    }

    public function pimpinan(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'pimpinan',
        ]);
    }

    public function kepalaBagian(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'kepala_bagian',
        ]);
    }

    public function pegawai(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'pegawai',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
