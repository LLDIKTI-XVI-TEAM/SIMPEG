<?php

namespace Database\Factories;

use App\Models\RefJenisJabatan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefJenisJabatan>
 */
class RefJenisJabatanFactory extends Factory
{
    protected $model = RefJenisJabatan::class;

    public function definition(): array
    {
        return [
            'nama' => fake()->jobTitle(),
            'maks_usia_pensiun' => fake()->numberBetween(55, 65),
            'catatan' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
