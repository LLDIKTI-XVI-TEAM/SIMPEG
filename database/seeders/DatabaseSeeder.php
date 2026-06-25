<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\ApprovalConfigSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ReferenceSeeder::class,
            RbacSeeder::class,
            ApprovalConfigSeeder::class,
            EwsConfigSeeder::class,
        ]);

    }
}
