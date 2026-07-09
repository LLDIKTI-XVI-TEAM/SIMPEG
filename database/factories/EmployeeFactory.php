<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        $jenisPegawai = RefJenisPegawai::firstOrCreate([
            'nama' => fake()->randomElement(['PNS', 'PPPK']),
        ]);
        $statusPegawai = RefStatusPegawai::firstOrCreate(
            ['nama' => 'Aktif'],
            ['keterangan' => 'Pegawai aktif', 'is_default' => true],
        );
        $kelasJabatan = (string) fake()->numberBetween(5, 12);
        $email = fake()->unique()->safeEmail();

        return [
            'nama_lengkap' => fake()->name(),
            'nip' => fake()->unique()->numerify('##################'),
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-60 years', '-25 years')->format('Y-m-d'),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'jenis_pegawai_id' => $jenisPegawai->id,
            'status_pegawai_id' => $statusPegawai->id,
            'status_aktif' => 'Aktif',
            'golongan_terakhir' => fake()->randomElement(['III/a', 'III/b', 'III/c', 'III/d', 'IV/a']),
            'pangkat_terakhir' => fake()->randomElement(['Penata Muda', 'Penata Muda Tingkat 1', 'Penata', 'Pembina']),
            'jabatan_terakhir' => fake()->randomElement(['Analis Kepegawaian', 'Pengelola Data', 'Perencana', 'Arsiparis']),
            'kelas_jabatan' => $kelasJabatan,
            'kelas_jabatan_terakhir' => $kelasJabatan,
            'pendidikan_terakhir' => fake()->randomElement(['D3', 'S1', 'S2']),
            'prodi_pendidikan_terakhir' => fake()->randomElement(['Manajemen', 'Administrasi Negara', 'Hukum', 'Akuntansi']),
            'tanggal_pensiun' => fake()->dateTimeBetween('+5 years', '+20 years')->format('Y-m-d'),
            'profil_status' => 'belum_lengkap',
            'no_hp' => fake()->phoneNumber(),
            'email_pribadi' => $email,
            'is_kinerja_baik' => true,
            'is_satyalancana_eligible' => true,
            'satyalancana_note' => null,
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
