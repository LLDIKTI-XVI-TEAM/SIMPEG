<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nama_pegawai' => fake()->name(),
            'email_pegawai' => fake()->unique()->safeEmail(),
            'golongan' => 'III/a',
            'jabatan' => 'Analis Kepegawaian',
            'kelas_jabatan' => '7',
            'nip' => fake()->unique()->numerify('##################'),
            'nomor_telepon' => fake()->phoneNumber(),
            'pangkat' => 'Penata Muda',
            'pendidikan_terakhir' => 'S1',
            'pensiun' => fake()->dateTimeBetween('+5 years', '+20 years')->format('Y-m-d'),
            'person' => fake()->firstName(),
            'person_formula' => fake()->firstName(),
            'prodi_pendidikan_terakhir' => 'Manajemen',
            'status_kepegawaian' => 'PNS',
            'tanggal_lahir' => fake()->dateTimeBetween('-60 years', '-25 years')->format('Y-m-d'),
        ];
    }
}
