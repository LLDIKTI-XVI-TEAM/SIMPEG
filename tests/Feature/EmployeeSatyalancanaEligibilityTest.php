<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeSatyalancanaEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_kepegawaian_can_update_satyalancana_eligibility(): void
    {
        $employee = Employee::factory()->create([
            'is_satyalancana_eligible' => true,
            'satyalancana_note' => null,
        ]);
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postJsonWithCsrf(route('pegawai.satyalancana.update', $employee->id), [
                'is_satyalancana_eligible' => false,
                'satyalancana_note' => 'Belum memenuhi syarat administrasi.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Kelayakan Satyalancana pegawai berhasil diperbarui.')
            ->assertJsonPath('is_satyalancana_eligible', false)
            ->assertJsonPath('satyalancana_note', 'Belum memenuhi syarat administrasi.');

        $employee->refresh();
        $this->assertFalse($employee->is_satyalancana_eligible);
        $this->assertSame('Belum memenuhi syarat administrasi.', $employee->satyalancana_note);

        $log = AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(true, $log->old_values['is_satyalancana_eligible']);
        $this->assertSame(false, $log->new_values['is_satyalancana_eligible']);
        $this->assertSame('Belum memenuhi syarat administrasi.', $log->new_values['satyalancana_note']);
    }

    public function test_super_admin_can_update_satyalancana_eligibility(): void
    {
        $employee = Employee::factory()->create(['is_satyalancana_eligible' => false]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJsonWithCsrf(route('pegawai.satyalancana.update', $employee->id), [
                'is_satyalancana_eligible' => true,
                'satyalancana_note' => '',
            ])
            ->assertOk()
            ->assertJsonPath('is_satyalancana_eligible', true)
            ->assertJsonPath('satyalancana_note', null);

        $employee->refresh();
        $this->assertTrue($employee->is_satyalancana_eligible);
        $this->assertNull($employee->satyalancana_note);
    }

    public function test_pimpinan_and_pegawai_cannot_update_satyalancana_eligibility(): void
    {
        foreach ([User::factory()->pimpinan()->create(), User::factory()->pegawai()->create()] as $user) {
            $employee = Employee::factory()->create(['is_satyalancana_eligible' => true]);

            $this->actingAs($user)
                ->postJsonWithCsrf(route('pegawai.satyalancana.update', $employee->id), [
                    'is_satyalancana_eligible' => false,
                    'satyalancana_note' => 'Percobaan ubah.',
                ])
                ->assertForbidden();

            $this->assertTrue($employee->refresh()->is_satyalancana_eligible);
            $this->assertNull($employee->satyalancana_note);
        }
    }

    public function test_satyalancana_eligibility_payload_is_validated(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.satyalancana.update', $employee->id), [
                'is_satyalancana_eligible' => 'bukan_boolean',
                'satyalancana_note' => str_repeat('x', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_satyalancana_eligible', 'satyalancana_note']);
    }

    public function test_missing_employee_returns_not_found(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.satyalancana.update', Str::uuid()), [
                'is_satyalancana_eligible' => true,
                'satyalancana_note' => null,
            ])
            ->assertNotFound();
    }

    /**
     * Menyertakan token CSRF agar test JSON tetap melewati middleware web seperti interaksi Alpine di halaman detail.
     *
     * @param  array<string, mixed>  $data
     */
    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, array_merge($data, ['_token' => 'test-token']));
    }
}
