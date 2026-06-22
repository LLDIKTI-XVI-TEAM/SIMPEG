<?php

namespace Database\Factories;

use App\Models\RefJenisPegawai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        $jenisPegawai = RefJenisPegawai::firstOrCreate([
            'nama' => fake()->randomElement(['PNS', 'PPPK']),
        ]);

        return [
            'nama_lengkap' => fake()->name(),
            'nip' => fake()->unique()->numerify('##################'),
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-60 years', '-25 years')->format('Y-m-d'),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'jenis_pegawai_id' => $jenisPegawai->id,
            'status_aktif' => 'Aktif',
            'golongan_terakhir' => fake()->randomElement(['III/a', 'III/b', 'III/c', 'III/d', 'IV/a']),
            'pangkat_terakhir' => fake()->randomElement(['Penata Muda', 'Penata Muda Tingkat 1', 'Penata', 'Pembina']),
            'jabatan_terakhir' => fake()->randomElement(['Analis Kepegawaian', 'Pengelola Data', 'Perencana', 'Arsiparis']),
            'kelas_jabatan' => (string) fake()->numberBetween(5, 12),
            'pendidikan_terakhir' => fake()->randomElement(['D3', 'S1', 'S2']),
            'prodi_pendidikan_terakhir' => fake()->randomElement(['Manajemen', 'Administrasi Negara', 'Hukum', 'Akuntansi']),
            'tanggal_pensiun' => fake()->dateTimeBetween('+5 years', '+20 years')->format('Y-m-d'),
            'profil_status' => 'belum_lengkap',
            'no_hp' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'is_kinerja_baik' => true,
            'role' => 'pegawai',
        ];
    }

    /**
     * Full profile state.
     */
    public function lengkap(): static
    {
        return $this->state(fn () => [
            'nik' => fake()->numerify('################'),
            'alamat' => fake()->address(),
            'profil_status' => 'lengkap',
        ]);
    }
}
