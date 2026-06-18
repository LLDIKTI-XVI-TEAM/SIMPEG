<?php

namespace Tests\Feature;

use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeder_includes_jenis_pegawai(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'PNS']);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'CPNS']);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'PPPK']);
    }

    public function test_reference_seeder_is_idempotent(): void
    {
        $this->seed(ReferenceSeeder::class);
        $pnsId = DB::table('ref_jenis_pegawai')->where('nama', 'PNS')->value('id');

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertSame($pnsId, DB::table('ref_jenis_pegawai')->where('nama', 'PNS')->value('id'));
    }
}
