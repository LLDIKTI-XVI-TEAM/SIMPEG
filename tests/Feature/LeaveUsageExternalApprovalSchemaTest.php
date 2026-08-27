<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\ManualExternalApprovalChainService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveUsageExternalApprovalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_menyediakan_nomor_dokumen_nullable_dan_tabel_snapshot(): void
    {
        $this->assertTrue(Schema::hasColumn('leave_usage_records', 'approval_document_number'));
        $this->assertTrue(Schema::hasTable('leave_usage_external_approval_steps'));

        $columns = Schema::getColumnListing('leave_usage_external_approval_steps');

        foreach ([
            'id',
            'leave_usage_record_id',
            'step_order',
            'step_type',
            'approver_source',
            'approver_employee_id',
            'approver_name_snapshot',
            'approver_nip_snapshot',
            'approver_position_snapshot',
            'approver_institution_snapshot',
            'acted_on',
            'result_code',
            'decision_note',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns);
        }
    }

    public function test_runtime_tidak_menerima_pegawai_legacy_soft_deleted_sebagai_approver_baru(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            $this->markTestSkipped('Schema lifecycle canonical tidak lagi memiliki soft delete pegawai.');
        }

        $approver = Employee::factory()->create();
        $approver->delete();
        $payload = $this->validManualApprovalPayload();
        $payload[0] = array_merge($payload[0], [
            'approver_source' => LeaveUsageExternalApprovalStep::SOURCE_SIMPEG_EMPLOYEE,
            'approver_employee_id' => $approver->id,
            'approver_name' => null,
            'approver_position' => null,
            'approver_institution' => null,
        ]);

        try {
            app(ManualExternalApprovalChainService::class)->normalize($payload);
            $this->fail('Pegawai legacy soft deleted tidak boleh dipilih pada runtime normal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval_steps.0.approver_employee_id', $exception->errors());
        }
    }

    public function test_relasi_approver_runtime_tidak_menghidupkan_kembali_pegawai_legacy_soft_deleted(): void
    {
        if (! Schema::hasColumn('employees', 'deleted_at')) {
            $this->markTestSkipped('Schema lifecycle canonical tidak lagi memiliki soft delete pegawai.');
        }

        [$employee, $actor] = $this->employeeAndActor();
        $approver = Employee::factory()->create();
        $recordId = $this->insertValidManual($employee, $actor, $approver);
        $step = LeaveUsageExternalApprovalStep::query()
            ->where('leave_usage_record_id', $recordId)
            ->where('approver_employee_id', $approver->id)
            ->sole();
        $approver->delete();

        $this->assertNull($step->fresh()->approverEmployee);
    }

    public function test_database_menerima_chain_valid_internal_dengan_nip_dan_jabatan_nullable(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $approver = Employee::factory()->create([
            'nama_lengkap' => 'Ni Luh Sakti',
            'nip' => null,
            'jabatan_terakhir' => null,
        ]);
        $recordId = (string) Str::uuid();

        DB::transaction(function () use ($employee, $actor, $approver, $recordId): void {
            DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $recordId));
            DB::table('leave_usage_external_approval_steps')->insert([
                $this->internalStep($recordId, 1, 'kepala_bagian', 'approved', $approver),
                $this->externalStep($recordId, 2, 'pybmc', 'final_approved'),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        $this->assertDatabaseHas('leave_usage_external_approval_steps', [
            'leave_usage_record_id' => $recordId,
            'step_order' => 1,
            'approver_employee_id' => $approver->id,
            'approver_nip_snapshot' => null,
            'approver_position_snapshot' => null,
            'approver_institution_snapshot' => 'LLDIKTI Wilayah XVI',
        ]);
    }

    public function test_database_menolak_manual_baru_tanpa_chain_lengkap(): void
    {
        [$employee, $actor] = $this->employeeAndActor();

        $this->assertQueryRejected(function () use ($employee, $actor): void {
            DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor));
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'snapshot persetujuan');
    }

    public function test_database_menolak_tanggal_tahap_yang_mundur(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $recordId = (string) Str::uuid();
        $first = array_merge($this->externalStep($recordId, 1, 'kepala_bagian', 'approved'), [
            'acted_on' => '2026-03-21',
        ]);
        $second = array_merge($this->externalStep($recordId, 2, 'pybmc', 'final_approved'), [
            'acted_on' => '2026-03-20',
        ]);

        $this->assertQueryRejected(function () use ($employee, $actor, $recordId, $first, $second): void {
            DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $recordId));
            DB::table('leave_usage_external_approval_steps')->insert([$first, $second]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'kronologis');
    }

    public function test_database_menolak_tanggal_tahap_setelah_hari_bisnis_wita(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $recordId = (string) Str::uuid();
        $tomorrow = now('Asia/Makassar')->addDay()->toDateString();
        $steps = [
            array_merge($this->externalStep($recordId, 1, 'kepala_bagian', 'approved'), ['acted_on' => $tomorrow]),
            array_merge($this->externalStep($recordId, 2, 'pybmc', 'final_approved'), ['acted_on' => $tomorrow]),
        ];

        $this->assertQueryRejected(function () use ($employee, $actor, $recordId, $steps): void {
            DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $recordId));
            DB::table('leave_usage_external_approval_steps')->insert($steps);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'masa depan');
    }

    public function test_database_menolak_step_pada_parent_non_manual_dan_perubahan_source_dua_arah(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $manualId = $this->insertValidManual($employee, $actor);
        $approvedId = $this->insertApprovedFact($employee, $actor);

        $this->assertQueryRejected(function () use ($approvedId): void {
            DB::table('leave_usage_external_approval_steps')->insert([
                $this->externalStep($approvedId, 1, 'kepala_bagian', 'approved'),
                $this->externalStep($approvedId, 2, 'pybmc', 'final_approved'),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'manual_external');

        $this->assertQueryRejected(
            fn (): int => DB::table('leave_usage_records')->where('id', $manualId)->update([
                'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            ]),
            'source_type',
        );
        $this->assertQueryRejected(
            fn (): int => DB::table('leave_usage_records')->where('id', $approvedId)->update([
                'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            ]),
            'source_type',
        );
    }

    public function test_database_menolak_xor_snapshot_dan_field_external_kosong(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $approver = Employee::factory()->create();
        $recordId = $this->insertValidManual($employee, $actor);

        $invalidSteps = [
            'internal tanpa employee' => array_merge($this->externalStep($recordId, 3, 'verifier', 'verified'), [
                'approver_source' => 'simpeg_employee',
            ]),
            'external dengan employee' => array_merge($this->externalStep($recordId, 3, 'verifier', 'verified'), [
                'approver_employee_id' => $approver->id,
            ]),
            'nama kosong' => array_merge($this->externalStep($recordId, 3, 'verifier', 'verified'), [
                'approver_name_snapshot' => "\u{00A0}",
            ]),
            'jabatan external kosong' => array_merge($this->externalStep($recordId, 3, 'verifier', 'verified'), [
                'approver_position_snapshot' => '   ',
            ]),
            'institusi external kosong' => array_merge($this->externalStep($recordId, 3, 'verifier', 'verified'), [
                'approver_institution_snapshot' => '',
            ]),
        ];

        foreach ($invalidSteps as $label => $step) {
            $this->assertQueryRejected(
                fn (): bool => DB::table('leave_usage_external_approval_steps')->insert($step),
                'leave_usage_external_approval',
                $label,
            );
        }
    }

    public function test_database_menolak_tipe_hasil_duplikat_dan_urutan_chain_tidak_valid(): void
    {
        [$employee, $actor] = $this->employeeAndActor();

        $cases = [
            'satu tahap' => [
                $this->externalStep('', 1, 'pybmc', 'final_approved'),
            ],
            'urutan berlubang' => [
                $this->externalStep('', 1, 'kepala_bagian', 'approved'),
                $this->externalStep('', 3, 'pybmc', 'final_approved'),
            ],
            'verifier setelah kabag' => [
                $this->externalStep('', 1, 'kepala_bagian', 'approved'),
                $this->externalStep('', 2, 'verifier', 'verified'),
                $this->externalStep('', 3, 'pybmc', 'final_approved'),
            ],
            'pybmc bukan terakhir' => [
                $this->externalStep('', 1, 'pybmc', 'final_approved'),
                $this->externalStep('', 2, 'kepala_bagian', 'approved'),
            ],
            'dua kabag' => [
                $this->externalStep('', 1, 'kepala_bagian', 'approved'),
                $this->externalStep('', 2, 'kepala_bagian', 'approved'),
                $this->externalStep('', 3, 'pybmc', 'final_approved'),
            ],
            'hasil tidak sesuai tipe' => [
                $this->externalStep('', 1, 'kepala_bagian', 'verified'),
                $this->externalStep('', 2, 'pybmc', 'final_approved'),
            ],
        ];

        $eleven = [];
        for ($order = 1; $order <= 9; $order++) {
            $eleven[] = $this->externalStep('', $order, 'verifier', 'verified');
        }
        $eleven[] = $this->externalStep('', 10, 'kepala_bagian', 'approved');
        $eleven[] = $this->externalStep('', 11, 'pybmc', 'final_approved');
        $cases['lebih dari sepuluh tahap'] = $eleven;

        foreach ($cases as $label => $steps) {
            $recordId = (string) Str::uuid();
            $this->assertQueryRejected(function () use ($employee, $actor, $recordId, $steps): void {
                DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $recordId));
                DB::table('leave_usage_external_approval_steps')->insert(array_map(
                    fn (array $step): array => array_merge($step, ['leave_usage_record_id' => $recordId]),
                    $steps,
                ));
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }, 'leave_usage_external_approval', $label);
        }

        $recordId = $this->insertValidManual($employee, $actor);
        $this->assertQueryRejected(
            fn (): bool => DB::table('leave_usage_external_approval_steps')->insert(
                $this->externalStep($recordId, 3, 'unknown', 'verified'),
            ),
            'leave_usage_external_approval',
            'tipe tidak dikenal',
        );
    }

    public function test_snapshot_append_only_dan_fk_employee_restrict(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $approver = Employee::factory()->create();
        $recordId = $this->insertValidManual($employee, $actor, $approver);
        $stepId = (string) DB::table('leave_usage_external_approval_steps')
            ->where('leave_usage_record_id', $recordId)
            ->where('step_order', 1)
            ->value('id');

        $this->assertQueryRejected(
            fn (): int => DB::table('leave_usage_external_approval_steps')->where('id', $stepId)->update([
                'decision_note' => 'Mutasi terlarang.',
            ]),
            'append-only',
        );
        $this->assertQueryRejected(
            fn (): int => DB::table('leave_usage_external_approval_steps')->where('id', $stepId)->delete(),
            'append-only',
        );
        $this->assertQueryRejected(
            fn (): int => DB::table('employees')->where('id', $approver->id)->delete(),
            'leave_usage_external_approval_steps_approver_employee_id_foreig',
        );
    }

    public function test_nomor_dokumen_parent_tidak_dapat_diubah_saat_lifecycle_ditutup(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $recordId = $this->insertValidManual($employee, $actor);

        $this->assertQueryRejected(
            fn (): int => DB::table('leave_usage_records')->where('id', $recordId)->update([
                'approval_document_number' => 'MUTASI/LANGSUNG/001',
                'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
                'correction_reason' => 'Lifecycle ditutup tanpa membuat versi fakta baru.',
                'updated_at' => now()->addSecond(),
            ]),
            'kolom substantif bersifat immutable',
        );
    }

    private function insertValidManual(Employee $employee, User $actor, ?Employee $approver = null): string
    {
        $recordId = (string) Str::uuid();

        DB::transaction(function () use ($employee, $actor, $recordId, $approver): void {
            DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $recordId));
            DB::table('leave_usage_external_approval_steps')->insert([
                $approver instanceof Employee
                    ? $this->internalStep($recordId, 1, 'kepala_bagian', 'approved', $approver)
                    : $this->externalStep($recordId, 1, 'kepala_bagian', 'approved'),
                $this->externalStep($recordId, 2, 'pybmc', 'final_approved'),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        return $recordId;
    }

    private function insertApprovedFact(Employee $employee, User $actor): string
    {
        $requestId = (string) Str::uuid();
        DB::table('leave_requests')->insert([
            'id' => $requestId,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->leaveType()->id,
            'tanggal_mulai' => '2026-04-01',
            'tanggal_selesai' => '2026-04-01',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture pengajuan approved.',
            'status' => 'disetujui',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $recordId = (string) Str::uuid();
        DB::table('leave_usage_records')->insert(array_merge(
            $this->manualFactPayload($employee, $actor, $recordId),
            [
                'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
                'leave_request_id' => $requestId,
            ],
        ));

        return $recordId;
    }

    /** @return array<string, mixed> */
    private function manualFactPayload(Employee $employee, User $actor, ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType()->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-04-01',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'workdays' => 1,
            'administrative_note' => 'Fixture schema snapshot.',
            'approval_document_number' => null,
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function externalStep(string $recordId, int $order, string $type, string $result): array
    {
        return [
            'id' => (string) Str::uuid(),
            'leave_usage_record_id' => $recordId,
            'step_order' => $order,
            'step_type' => $type,
            'approver_source' => 'external_official',
            'approver_employee_id' => null,
            'approver_name_snapshot' => "Pejabat External {$order}",
            'approver_nip_snapshot' => null,
            'approver_position_snapshot' => 'Pejabat Penguji',
            'approver_institution_snapshot' => 'Instansi Penguji',
            'acted_on' => '2026-03-20',
            'result_code' => $result,
            'decision_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function internalStep(
        string $recordId,
        int $order,
        string $type,
        string $result,
        Employee $approver,
    ): array {
        return array_merge($this->externalStep($recordId, $order, $type, $result), [
            'approver_source' => 'simpeg_employee',
            'approver_employee_id' => $approver->id,
            'approver_name_snapshot' => $approver->nama_lengkap,
            'approver_nip_snapshot' => $approver->nip,
            'approver_position_snapshot' => $approver->jabatan_terakhir,
            'approver_institution_snapshot' => 'LLDIKTI Wilayah XVI',
        ]);
    }

    /** @return array{Employee, User} */
    private function employeeAndActor(): array
    {
        return [Employee::factory()->create(), User::factory()->adminKepegawaian()->create()];
    }

    private function leaveType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'snapshot-schema'],
            ['nama' => 'Cuti Snapshot Schema', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
        );
    }

    private function assertQueryRejected(callable $operation, string $message, string $label = ''): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Operasi schema {$label} seharusnya ditolak.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage(), $label);
        }
    }
}
