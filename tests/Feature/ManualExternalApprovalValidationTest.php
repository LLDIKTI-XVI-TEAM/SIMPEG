<?php

namespace Tests\Feature;

use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Employee;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\ManualExternalApprovalChainService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualExternalApprovalValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-21 09:00:00');
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('invalidChainProvider')]
    public function test_route_dan_action_langsung_menolak_struktur_chain_tidak_valid(
        array $steps,
        string $errorPath,
    ): void {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $payload = $this->validData(['approval_steps' => $steps]);

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), array_merge($payload, ['dokumen' => $this->validDocument()]))
            ->assertSessionHasErrors($errorPath);

        try {
            app(StoreManualLeaveUsageAction::class)->execute(
                $employee->id,
                $payload,
                $this->validDocument('direct-invalid.pdf'),
                $admin,
                $this->requestFor($admin),
            );
            $this->fail("Action langsung seharusnya menolak {$errorPath}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorPath, $exception->errors());
        }

        $this->assertDatabaseCount('leave_usage_records', 0);
    }

    /** @return array<string, array{array<int, array<string, mixed>>, string}> */
    public static function invalidChainProvider(): array
    {
        $kabag = self::externalStep('kepala_bagian');
        $pybmc = self::externalStep('pybmc');
        $verifier = self::externalStep('verifier');

        return [
            'kosong' => [[], 'approval_steps'],
            'satu tahap' => [[$pybmc], 'approval_steps'],
            'lebih dari sepuluh' => [[...array_fill(0, 9, $verifier), $kabag, $pybmc], 'approval_steps'],
            'lebih dari delapan verifier' => [[...array_fill(0, 9, $verifier), $kabag], 'approval_steps'],
            'tanpa kabag' => [[$verifier, $pybmc], 'approval_steps'],
            'dua kabag' => [[$kabag, $kabag, $pybmc], 'approval_steps'],
            'tanpa pybmc' => [[$verifier, $kabag], 'approval_steps'],
            'dua pybmc' => [[$kabag, $pybmc, $pybmc], 'approval_steps'],
            'pybmc bukan terakhir' => [[$pybmc, $kabag], 'approval_steps.0.step_type'],
            'verifier setelah kabag' => [[$kabag, $verifier, $pybmc], 'approval_steps.1.step_type'],
            'jenis tidak dikenal' => [[$kabag, array_merge($pybmc, ['step_type' => 'lainnya'])], 'approval_steps.1.step_type'],
            'client mengirim urutan' => [[array_merge($kabag, ['step_order' => 1]), $pybmc], 'approval_steps.0.step_order'],
            'client mengirim hasil' => [[$kabag, array_merge($pybmc, ['result_code' => 'final_approved'])], 'approval_steps.1.result_code'],
            'internal tanpa employee' => [[array_merge($kabag, [
                'approver_source' => 'simpeg_employee',
                'approver_employee_id' => null,
                'approver_name' => null,
                'approver_position' => null,
                'approver_institution' => null,
            ]), $pybmc], 'approval_steps.0.approver_employee_id'],
            'external membawa employee' => [[array_merge($kabag, [
                'approver_employee_id' => '00000000-0000-4000-8000-000000000001',
            ]), $pybmc], 'approval_steps.0.approver_employee_id'],
            'external nama kosong' => [[array_merge($kabag, ['approver_name' => " \u{00A0} "]), $pybmc], 'approval_steps.0.approver_name'],
            'external jabatan kosong' => [[array_merge($kabag, ['approver_position' => '   ']), $pybmc], 'approval_steps.0.approver_position'],
            'external institusi kosong' => [[array_merge($kabag, ['approver_institution' => '   ']), $pybmc], 'approval_steps.0.approver_institution'],
            'tanggal masa depan' => [[array_merge($kabag, ['acted_on' => '2026-08-22']), $pybmc], 'approval_steps.0.acted_on'],
            'tanggal tahap mundur' => [[
                array_merge($kabag, ['acted_on' => '2026-08-19']),
                array_merge($pybmc, ['acted_on' => '2026-08-18']),
            ], 'approval_steps.1.acted_on'],
        ];
    }

    public function test_validasi_struktur_memakai_label_atasan_langsung(): void
    {
        $errors = app(ManualExternalApprovalChainService::class)->violations([
            self::externalStep('verifier'),
            self::externalStep('pybmc'),
        ]);

        $this->assertContains(
            'Riwayat persetujuan wajib memiliki tepat satu Atasan Langsung.',
            $errors['approval_steps'],
        );
        $this->assertStringNotContainsString('Kepala Bagian', implode(' ', $errors['approval_steps']));
    }

    public function test_tanggal_tahap_yang_sama_tetap_diterima(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), $this->validData())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record = LeaveUsageRecord::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(
            ['2026-08-18', '2026-08-18'],
            $record->externalApprovalSteps
                ->map(fn ($step): string => $step->acted_on->toDateString())
                ->all(),
        );
    }

    public function test_tanggal_tindakan_hari_ini_mengikuti_kalender_bisnis_wita_saat_aplikasi_utc(): void
    {
        $originalTimezone = config('app.timezone');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2025-12-31 16:30:00', 'UTC'));
        $steps = [
            array_merge(self::externalStep('kepala_bagian'), ['acted_on' => '2026-01-01']),
            array_merge(self::externalStep('pybmc'), ['acted_on' => '2026-01-01']),
        ];

        try {
            $violations = app(ManualExternalApprovalChainService::class)->violations($steps);

            $this->assertArrayNotHasKey('approval_steps.0.acted_on', $violations);
            $this->assertArrayNotHasKey('approval_steps.1.acted_on', $violations);
        } finally {
            config(['app.timezone' => $originalTimezone]);
        }
    }

    public function test_tanggal_tindakan_setelah_hari_bisnis_wita_tetap_ditolak_saat_aplikasi_utc(): void
    {
        $originalTimezone = config('app.timezone');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2025-12-31 16:30:00', 'UTC'));
        $steps = [
            array_merge(self::externalStep('kepala_bagian'), ['acted_on' => '2026-01-02']),
            array_merge(self::externalStep('pybmc'), ['acted_on' => '2026-01-02']),
        ];

        try {
            $violations = app(ManualExternalApprovalChainService::class)->violations($steps);

            $this->assertArrayHasKey('approval_steps.0.acted_on', $violations);
            $this->assertArrayHasKey('approval_steps.1.acted_on', $violations);
        } finally {
            config(['app.timezone' => $originalTimezone]);
        }
    }

    public function test_employee_internal_yang_tidak_ada_ditolak_dengan_path_indexed(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $steps = [
            array_merge(self::externalStep('kepala_bagian'), [
                'approver_source' => 'simpeg_employee',
                'approver_employee_id' => '00000000-0000-4000-8000-000000000001',
                'approver_name' => null,
                'approver_position' => null,
                'approver_institution' => null,
            ]),
            self::externalStep('pybmc'),
        ];

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), array_merge(
                $this->validData(['approval_steps' => $steps]),
                ['dokumen' => $this->validDocument()],
            ))
            ->assertSessionHasErrors('approval_steps.0.approver_employee_id');
    }

    public function test_nomor_dokumen_dan_upload_opsional_tetapi_file_invalid_tetap_ditolak(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($admin)
            ->post($this->storeUrl($employee), $this->validData([
                'approval_document_number' => null,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_usage_records', [
            'employee_id' => $employee->id,
            'approval_document_number' => null,
        ]);
        $this->assertDatabaseCount('leave_usage_documents', 0);

        $other = Employee::factory()->create();
        $this->actingAs($admin)
            ->post($this->storeUrl($other), array_merge($this->validData(), [
                'approval_document_number' => str_repeat('X', 256),
                'dokumen' => UploadedFile::fake()->create('script.php', 10, 'application/pdf'),
            ]))
            ->assertSessionHasErrors(['approval_document_number', 'dokumen']);
    }

    public function test_normalisasi_whitespace_unicode_dan_result_diturunkan_server(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $internal = Employee::factory()->create([
            'nama_lengkap' => "Ni\u{00A0}Luh Sakti",
            'nip' => null,
            'jabatan_terakhir' => null,
        ]);
        $steps = [
            array_merge(self::externalStep('verifier'), [
                'approver_name' => "  O'Connor\u{2003}Putra  ",
                'approver_position' => "  Ketua\u{00A0}Tim  ",
                'approver_institution' => "  Instansi\u{2003}External  ",
            ]),
            array_merge(self::externalStep('kepala_bagian'), [
                'approver_source' => 'simpeg_employee',
                'approver_employee_id' => $internal->id,
                'approver_name' => null,
                'approver_position' => null,
                'approver_institution' => null,
            ]),
            self::externalStep('pybmc'),
        ];

        $record = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validData(['approval_steps' => $steps]),
            null,
            $admin,
            $this->requestFor($admin),
        );

        $stored = $record->externalApprovalSteps()->orderBy('step_order')->get();
        $this->assertSame([1, 2, 3], $stored->pluck('step_order')->all());
        $this->assertSame(['verified', 'approved', 'final_approved'], $stored->pluck('result_code')->all());
        $this->assertSame("O'Connor Putra", $stored[0]->approver_name_snapshot);
        $this->assertSame('Ketua Tim', $stored[0]->approver_position_snapshot);
        $this->assertSame('Instansi External', $stored[0]->approver_institution_snapshot);
        $this->assertSame($internal->id, $stored[1]->approver_employee_id);
        $this->assertSame('LLDIKTI Wilayah XVI', $stored[1]->approver_institution_snapshot);
        $this->assertNull($stored[1]->approver_nip_snapshot);
        $this->assertNull($stored[1]->approver_position_snapshot);
    }

    public function test_action_tidak_menyentuh_validator_dokumen_atau_record_saat_aktor_tidak_berwenang(): void
    {
        $employee = Employee::factory()->create();

        foreach (['super_admin', 'pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });

            try {
                app(StoreManualLeaveUsageAction::class)->execute(
                    $employee->id,
                    $this->validData(),
                    null,
                    $actor,
                    null,
                );
                $this->fail("Role {$role} seharusnya ditolak.");
            } catch (AuthorizationException) {
                $this->assertFalse(collect($queries)->contains(
                    fn (string $sql): bool => str_contains($sql, 'employees')
                        || str_contains($sql, 'leave_usage_records')
                        || str_contains($sql, 'leave_usage_external_approval_steps'),
                ));
                $this->assertSame([], Storage::disk('local')->allFiles('cuti/pemakaian'));
                $this->assertDatabaseCount('leave_usage_records', 0);
            }
        }
    }

    public function test_koreksi_direct_action_memakai_validator_struktural_yang_sama(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $current = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            $this->validData(),
            null,
            $admin,
            $this->requestFor($admin),
        );

        try {
            app(CorrectManualLeaveUsageAction::class)->execute(
                $current->id,
                array_merge($this->validData(['approval_steps' => []]), [
                    'correction_reason' => 'Koreksi dengan chain invalid.',
                ]),
                null,
                $admin,
                $this->requestFor($admin),
            );
            $this->fail('Koreksi direct Action wajib menolak chain kosong.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval_steps', $exception->errors());
        }

        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $current->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 1);
    }

    /** @return array<string, mixed> */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'leave_type_id' => RefJenisCuti::query()->firstOrCreate(
                ['code' => 'snapshot-validation'],
                ['nama' => 'Cuti Snapshot Validation', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
            )->id,
            'leave_request_case_id' => null,
            'tanggal_mulai' => '2026-08-19',
            'tanggal_selesai' => '2026-08-20',
            'alasan' => 'Cuti telah disetujui di luar SIMPEG.',
            'approval_document_number' => 'SURAT/2026/001',
            'approval_steps' => [
                self::externalStep('kepala_bagian'),
                self::externalStep('pybmc'),
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private static function externalStep(string $type): array
    {
        return [
            'step_type' => $type,
            'approver_source' => 'external_official',
            'approver_employee_id' => null,
            'approver_name' => $type === 'pybmc' ? 'Pejabat Yang Berwenang' : 'Kepala Bagian External',
            'approver_position' => $type === 'pybmc' ? 'PYBMC' : 'Kepala Bagian',
            'approver_institution' => 'Instansi External',
            'acted_on' => '2026-08-18',
            'decision_note' => null,
        ];
    }

    private function validDocument(string $name = 'bukti.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/cuti/pemakaian-manual', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    private function storeUrl(Employee $employee): string
    {
        return "/cuti/pemakaian-manual/{$employee->id}";
    }
}
