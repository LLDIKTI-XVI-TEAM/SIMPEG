<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SimpegNotification;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class NotificationRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_notifikasi_terbaca_tetap_tersimpan_dan_tampil_di_halaman_riwayat(): void
    {
        // Halaman Semua Notifikasi berfungsi sebagai riwayat: notifikasi yang sudah
        // dibaca tidak boleh dihapus dan harus tetap terlihat oleh pemiliknya.
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'ews.followup.pensiun',
            'title' => 'Notifikasi Sudah Dibaca',
            'body' => 'Riwayat notifikasi yang sudah dibaca.',
            'is_read' => true,
            'read_at' => now()->subDays(30),
        ]);
        SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'cuti.disetujui',
            'title' => 'Notifikasi Belum Dibaca',
            'body' => 'Notifikasi baru yang belum dibaca.',
            'is_read' => false,
        ]);

        $this->assertSame(2, SimpegNotification::count());

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifikasi Sudah Dibaca')
            ->assertSee('Notifikasi Belum Dibaca');
    }

    public function test_notifikasi_milik_pegawai_lain_tidak_tampil_di_halaman_riwayat(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        SimpegNotification::create([
            'user_id' => $otherEmployee->id,
            'type' => 'ews.followup.kgb',
            'title' => 'Notifikasi Milik Pegawai Lain',
            'body' => 'Tidak boleh bocor ke riwayat pegawai lain.',
            'is_read' => true,
            'read_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Notifikasi Milik Pegawai Lain');
    }

    public function test_command_purge_notifikasi_terbaca_tidak_terdaftar(): void
    {
        // Penghapusan permanen notifikasi terbaca dilarang: is_read/read_at hanya
        // penanda status baca. Command purge tidak boleh terdaftar kembali tanpa
        // keputusan retensi produk yang terdokumentasi.
        $this->assertArrayNotHasKey('notifications:purge-read', Artisan::all());
        $this->assertArrayNotHasKey('notifications:purge-read-ews', Artisan::all());
    }
}
