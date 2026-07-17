<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memastikan halaman konfigurasi menampilkan pembuat chain per pegawai,
 * bukan lagi permukaan konfigurasi approval tetap.
 */
class CutiConfigPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_melihat_kontrol_chain_dinamis_untuk_pegawai_terpilih(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Konfigurasi',
            'nip' => '100000000000000001',
        ]);
        $pegawai = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Konfigurasi Chain',
            'nip' => '100000000000000002',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $verifikator = Employee::factory()->create();
        User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'user_name' => 'Admin Konfigurasi',
            'event' => 'UPDATE',
            'auditable_type' => 'LeaveApprovalChain',
            'auditable_id' => $pegawai->id,
            'old_values' => [],
            'new_values' => ['reason' => 'Penyesuaian verifikator karena perubahan struktur jabatan dan kebutuhan pemeriksaan berlapis.'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $response = $this->actingAs($actor)->get(route('cuti.config', [
            'search' => 'Pegawai Konfigurasi',
            'employee_id' => $pegawai->id,
        ]));

        $response->assertOk()
            ->assertSee('Chain Approval Pegawai')
            ->assertSee('class="rounded-xl border border-border bg-surface shadow-sm"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('x-show="false"', false)
            ->assertSee("document.getElementById('employee-search')?.form?.requestSubmit()", false)
            ->assertSee('@keydown.enter.prevent="selectActive($event)"', false)
            ->assertSee(':name="selectedId ? null :', false)
            ->assertSee('x-show="selectedId"', false)
            ->assertSee($pegawai->nama_lengkap)
            ->assertSee($kepalaBagian->nama_lengkap)
            ->assertSee('Kepala Bagian')
            ->assertSee('Tambah Verifikator')
            ->assertSee('x-for="(verifier, index) in verifiers"', false)
            ->assertSee('maxVerifierSteps: 8', false)
            ->assertSee('Label Verifikator ${index + 1}', false)
            ->assertSee('Naikkan urutan verifikator', false)
            ->assertSee('Turunkan urutan verifikator', false)
            ->assertSee('Hapus verifikator ${index + 1}', false)
            ->assertSee('h-11 w-11', false)
            ->assertSee('sm:h-8 sm:w-8', false)
            ->assertSee('name="steps[0][step_type]" value="kepala_bagian"', false)
            ->assertSee('action="'.route('cuti.config.employee-chain.store', $pegawai).'"', false)
            ->assertSee('sticky top-0 z-10', false)
            ->assertSee('sm:hidden', false)
            ->assertSee('PYBMC Khusus')
            ->assertSee(':name="pybmcEmployeeId ? \'steps[_pybmc][approver_employee_id]\' : null" x-model="pybmcEmployeeId"', false)
            ->assertSee('PYBMC Global')
            ->assertSee('Override global mengubah PYBMC pada semua chain aktif', false)
            ->assertSee('hidden overflow-x-auto md:block', false)
            ->assertSee('space-y-3 p-5 md:hidden', false)
            ->assertSee('break-words text-ink', false)
            ->assertSee('Chain pegawai')
            ->assertSee('Admin Konfigurasi')
            ->assertSee('Penyesuaian verifikator karena perubahan struktur jabatan dan kebutuhan pemeriksaan berlapis.')
            ->assertDontSee('Approver Stage 2', false)
            ->assertDontSee('Approver Stage 3', false)
            ->assertDontSee('stage2_approver_id', false)
            ->assertDontSee('stage3_approver_id', false);

        $component = file_get_contents(resource_path('views/components/cuti/employee-combobox.blade.php'));
        $this->assertIsString($component);
        $this->assertStringContainsString('name="{{ $queryName }}"', $component);
        $this->assertStringContainsString(':name="selectedId ? null : @js($queryName)"', $component);
    }

    public function test_pencarian_pegawai_dibatasi_lima_puluh_hasil(): void
    {
        $actor = User::factory()->superAdmin()->create();

        foreach (range(1, 51) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Target Konfigurasi %03d', $number),
            ]);
        }

        $response = $this->actingAs($actor)->get(route('cuti.config', ['search' => 'Target Konfigurasi']));

        $response->assertOk()
            ->assertSee('Target Konfigurasi 001')
            ->assertDontSee('Target Konfigurasi 051');
    }
}
