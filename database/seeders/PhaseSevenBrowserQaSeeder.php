<?php

namespace Database\Seeders;

use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Support\Cuti\CutiInstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseSevenBrowserQaSeeder extends Seeder
{
    private const QA_REASON_PREFIX = '[QA Phase 7 Browser]';

    private const KEPALA_LEMBAGA_EMAIL = 'qa-phase7-kepala-lembaga@example.test';

    private const MANUAL_EMPLOYEE_EMAIL = 'qa-phase7-cuti-manual@example.test';

    private const INVALID_EMPLOYEE_EMAIL = 'qa-phase7-chain-invalid@example.test';

    private const INACTIVE_APPROVER_EMAIL = 'qa-phase7-approver-nonaktif@example.test';

    private const DEMO_APPROVER_USERNAME = 'demo-klabat-kabag';

    private const DEMO_PEGAWAI_USERNAME = 'demo-klabat-pegawai';

    private const CHAIN_ID = '70000000-0000-4000-8000-000000000001';

    /** @var array<string, string> */
    private const PREVIEW_CHAIN_IDS = [
        'manual' => '70000000-0000-4000-8000-000000000002',
        'invalid' => '70000000-0000-4000-8000-000000000005',
    ];

    private const BALANCE_YEAR = 2026;

    private const MANUAL_USAGE_NOTE = '[QA Phase 7 Browser] Cuti manual dengan snapshot persetujuan.';

    /** @var array<string, string> */
    private const REQUEST_IDS = [
        'pending approval' => '70000000-0000-4000-8000-000000000011',
        'perlu perubahan' => '70000000-0000-4000-8000-000000000012',
        'ditangguhkan' => '70000000-0000-4000-8000-000000000013',
        'disetujui' => '70000000-0000-4000-8000-000000000014',
        'tidak disetujui' => '70000000-0000-4000-8000-000000000015',
    ];

    public function run(): void
    {
        // Fixture browser QA hanya boleh ada di local/testing agar data khusus pengujian tidak masuk lingkungan lain.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('PhaseSevenBrowserQaSeeder dibatalkan: hanya boleh dijalankan pada environment local atau testing.');

            return;
        }

        // Fixture bertanggal tetap tidak boleh berjalan pada tahun lain karena akan menyesatkan hasil QA saldo.
        $applicationYear = CarbonImmutable::now(config('app.timezone'))->year;

        if ($applicationYear !== self::BALANCE_YEAR) {
            throw new \RuntimeException(
                'PhaseSevenBrowserQaSeeder hanya mendukung tahun saldo 2026; tahun aplikasi saat ini '.$applicationYear.'.'
            );
        }

        [$kepalaLembaga, $pendingRequest, $manualEmployee, $manualUsage, $invalidEmployee] = DB::transaction(function (): array {
            $jenisPegawaiId = RefJenisPegawai::query()->where('nama', 'PNS')->value('id');
            $statusAktifId = RefStatusPegawai::query()->where('nama', 'Aktif')->value('id');
            $statusNonaktifId = RefStatusPegawai::query()
                ->where('kode', 'NONAKTIF')
                ->where('kelompok', 'Nonaktif')
                ->value('id');
            $jenisCutiTahunan = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
            $jenisCutiSakit = RefJenisCuti::query()->where('code', 'sakit')->firstOrFail();

            $admin = $this->upsertAdminSession($jenisPegawaiId, $statusAktifId);
            $kepalaLembaga = $this->upsertEmployee(
                self::KEPALA_LEMBAGA_EMAIL,
                '198001012026000002',
                'QA Fase 7 Kepala Lembaga',
                'pimpinan',
                $jenisPegawaiId,
                $statusAktifId,
                ['is_kepala_lembaga' => true]
            );
            $approver = $this->resolveDemoApprover($jenisPegawaiId, $statusAktifId);
            $normalEmployee = $this->resolveDemoPegawai($jenisPegawaiId, $statusAktifId, $approver);
            $manualEmployee = $this->upsertEmployee(
                self::MANUAL_EMPLOYEE_EMAIL,
                '198001012026000005',
                'QA Fase 7 Cuti Manual',
                'pegawai',
                $jenisPegawaiId,
                $statusAktifId,
            );
            $invalidEmployee = $this->upsertEmployee(
                self::INVALID_EMPLOYEE_EMAIL,
                '198001012026000007',
                'QA Fase 7 Chain Invalid',
                'pegawai',
                $jenisPegawaiId,
                $statusAktifId,
            );
            $inactiveApprover = $this->upsertEmployee(
                self::INACTIVE_APPROVER_EMAIL,
                '198001012026000008',
                'QA Fase 7 Pejabat Nonaktif',
                'kepala_bagian',
                $jenisPegawaiId,
                $statusNonaktifId,
            );

            foreach ([$normalEmployee, $manualEmployee, $invalidEmployee] as $eligibleEmployee) {
                $this->ensureEligibleAppointment($eligibleEmployee);
            }

            $this->replaceQaFixtures($normalEmployee, $approver, $kepalaLembaga, $admin, $jenisCutiTahunan);
            $this->replacePreviewChains(
                $manualEmployee,
                $invalidEmployee,
                $normalEmployee,
                $approver,
                $kepalaLembaga,
                $inactiveApprover,
                $admin,
            );
            $manualUsage = $this->ensureManualUsage(
                $manualEmployee,
                $jenisCutiSakit,
                $approver,
                $inactiveApprover,
                $admin,
            );

            $pendingRequest = LeaveRequest::query()
                ->where('employee_id', $normalEmployee->id)
                ->where('alasan', self::QA_REASON_PREFIX.' pending approval')
                ->firstOrFail();

            return [$kepalaLembaga, $pendingRequest, $manualEmployee, $manualUsage, $invalidEmployee];
        });

        $this->command?->line('QA_KEPALA_LEMBAGA_ID='.$kepalaLembaga->id);
        $this->command?->line('QA_PENDING_CUTI_ID='.$pendingRequest->id);
        $this->command?->line('QA_APPROVER_USERNAME='.self::DEMO_APPROVER_USERNAME);
        $this->command?->line('QA_MANUAL_EMPLOYEE_ID='.$manualEmployee->id);
        $this->command?->line('QA_MANUAL_USAGE_ID='.$manualUsage->id);
        $this->command?->line('QA_INVALID_CHAIN_EMPLOYEE_ID='.$invalidEmployee->id);
    }

    /** Menjaga fixture saldo QA memenuhi masa kerja satu tahun sebelum tahun fakta pertama. */
    private function ensureEligibleAppointment(Employee $employee): void
    {
        Appointment::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
            ],
        );
    }

    private function upsertAdminSession(?string $jenisPegawaiId, ?string $statusAktifId): User
    {
        $email = 'demo-klabat-kepeg@example.test';
        $user = User::query()
            ->where('keycloak_username', 'demo-klabat-kepeg')
            ->orWhere('email', $email)
            ->first() ?? new User;

        $employee = $user->employee_id !== null
            ? Employee::query()->find($user->employee_id)
            : Employee::query()->where('email', $email)->first();

        if ($employee === null) {
            $employee = $this->upsertEmployee(
                $email,
                '198001012026000004',
                'Demo Klabat (Admin Kepegawaian)',
                'admin_kepegawaian',
                $jenisPegawaiId,
                $statusAktifId,
            );
        }

        $user->fill([
            'name' => 'Demo Klabat (Admin Kepegawaian)',
            'email' => $email,
            'keycloak_username' => 'demo-klabat-kepeg',
            'role' => 'admin_kepegawaian',
            'employee_id' => $employee->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => $user->password ?? 'demo-klabat-kepeg',
        ]);
        $user->save();

        return $user;
    }

    /** @param array<string, mixed> $extra */
    private function upsertEmployee(
        string $email,
        string $nip,
        string $name,
        string $role,
        ?string $jenisPegawaiId,
        ?string $statusPegawaiId,
        array $extra = [],
    ): Employee {
        $employee = Employee::query()->where('email', $email)->first() ?? new Employee;

        $employee->fill([
            'nama_lengkap' => $name,
            'nip' => $nip,
            'tempat_lahir' => 'Gorontalo',
            'tanggal_lahir' => '1980-01-01',
            'jenis_kelamin' => 'L',
            'jenis_pegawai_id' => $jenisPegawaiId,
            'status_pegawai_id' => $statusPegawaiId,
            'jabatan_terakhir' => $role === 'kepala_bagian' ? 'Kepala Bagian' : 'Analis Kepegawaian',
            'kelas_jabatan' => '9',
            'pendidikan_terakhir' => 'S1',
            'profil_status' => 'lengkap',
            'email' => $email,
            'is_kinerja_baik' => true,
            'is_kepala_lembaga' => false,
            'role' => $role,
            ...$extra,
        ]);
        $employee->save();

        return $employee;
    }

    private function resolveDemoApprover(?string $jenisPegawaiId, ?string $statusAktifId): Employee
    {
        $user = User::query()
            ->where('keycloak_username', self::DEMO_APPROVER_USERNAME)
            ->firstOrFail();

        $employee = $user->employee_id !== null
            ? Employee::query()->find($user->employee_id)
            : Employee::query()->where('email', $user->email)->first();

        if ($employee === null) {
            throw new \RuntimeException('Employee untuk demo-klabat-kabag belum tersedia. Jalankan DemoSsoUserSeeder terlebih dahulu.');
        }

        $employee->fill([
            'nama_lengkap' => 'Demo Klabat (Kepala Bagian)',
            'status_pegawai_id' => $statusAktifId,
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawaiId,
            'jabatan_terakhir' => 'Kepala Bagian',
            'role' => 'kepala_bagian',
        ]);
        $employee->save();

        $user->fill([
            'name' => 'Demo Klabat (Kepala Bagian)',
            'role' => 'kepala_bagian',
            'employee_id' => $employee->id,
        ]);
        $user->save();

        return $employee;
    }

    /**
     * Memakai akun demo pegawai yang diizinkan dev-login agar form pengajuan diuji dengan sesi pegawai nyata.
     */
    private function resolveDemoPegawai(
        ?string $jenisPegawaiId,
        ?string $statusAktifId,
        Employee $approver,
    ): Employee {
        $user = User::query()
            ->where('keycloak_username', self::DEMO_PEGAWAI_USERNAME)
            ->firstOrFail();

        $employee = $user->employee_id !== null
            ? Employee::query()->find($user->employee_id)
            : Employee::query()->where('email', $user->email)->first();

        if ($employee === null) {
            throw new \RuntimeException('Employee untuk demo-klabat-pegawai belum tersedia. Jalankan DemoSsoUserSeeder terlebih dahulu.');
        }

        $employee->fill([
            'nama_lengkap' => 'QA Fase 7 Pegawai',
            'status_pegawai_id' => $statusAktifId,
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawaiId,
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'kepala_bagian_id' => $approver->id,
            'is_kepala_lembaga' => false,
            'role' => 'pegawai',
        ]);
        $employee->save();

        // Form cuti membaca timeline penugasan aktual, bukan pointer legacy pada employee.
        SupervisorAssignment::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'tanggal_mulai' => '2026-01-01',
            ],
            [
                'supervisor_id' => $approver->id,
                'kepala_bagian_id' => $approver->id,
                'tanggal_berakhir' => null,
            ],
        );

        $user->fill([
            'name' => 'QA Fase 7 Pegawai',
            'role' => 'pegawai',
            'employee_id' => $employee->id,
        ]);
        $user->save();

        return $employee;
    }

    private function replaceQaFixtures(
        Employee $normalEmployee,
        Employee $approver,
        Employee $kepalaLembaga,
        User $admin,
        RefJenisCuti $jenisCutiTahunan,
    ): void {
        // UUID fixture tetap dibersihkan saat target pemohon QA berubah agar rerun seeder tetap idempoten.
        LeaveRequest::query()
            ->whereIn('id', array_values(self::REQUEST_IDS))
            ->where('id', '!=', self::REQUEST_IDS['disetujui'])
            ->delete();
        LeaveApprovalChain::query()
            ->whereKey(self::CHAIN_ID)
            ->delete();
        LeaveRequest::query()
            ->where('employee_id', $normalEmployee->id)
            ->where('alasan', 'like', self::QA_REASON_PREFIX.'%')
            ->where('id', '!=', self::REQUEST_IDS['disetujui'])
            ->delete();
        LeaveApprovalChain::query()
            ->where('employee_id', $normalEmployee->id)
            ->where('change_reason', self::QA_REASON_PREFIX)
            ->delete();

        $chain = LeaveApprovalChain::create([
            'id' => self::CHAIN_ID,
            'employee_id' => $normalEmployee->id,
            'name' => 'QA Fase 7 Browser Approval Chain',
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'change_reason' => self::QA_REASON_PREFIX,
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_role_key' => 'kepala_bagian',
                'approver_employee_id' => $approver->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_role_key' => 'pimpinan',
                'approver_employee_id' => $kepalaLembaga->id,
                'is_final' => true,
            ],
        ]);

        $approvedRequest = $this->createRequest(
            $normalEmployee,
            $jenisCutiTahunan,
            'disetujui',
            'disetujui',
            2,
            $approver,
            $kepalaLembaga,
        );
        app(LeaveUsageRecordService::class)->recordApprovedRequest($approvedRequest, $admin);

        // Status non-final dibuat langsung karena hanya menjadi variasi tampilan browser QA.
        $pendingRequest = $this->createRequest(
            $normalEmployee,
            $jenisCutiTahunan,
            'menunggu_approval',
            'pending approval',
            1,
            $approver,
            $kepalaLembaga,
        );
        $this->createRequest(
            $normalEmployee,
            $jenisCutiTahunan,
            'perlu_perubahan',
            'perlu perubahan',
            1,
            $approver,
            $kepalaLembaga,
            'Mohon lengkapi alasan pengajuan.',
        );
        $this->createRequest(
            $normalEmployee,
            $jenisCutiTahunan,
            'ditangguhkan',
            'ditangguhkan',
            1,
            $approver,
            $kepalaLembaga,
            'Ditangguhkan karena kebutuhan layanan.',
        );
        $this->createRequest(
            $normalEmployee,
            $jenisCutiTahunan,
            'tidak_disetujui',
            'tidak disetujui',
            1,
            $approver,
            $kepalaLembaga,
            'Tidak dapat disetujui untuk periode tersebut.',
        );

    }

    private function replacePreviewChains(
        Employee $manualEmployee,
        Employee $invalidEmployee,
        Employee $verifier,
        Employee $approver,
        Employee $finalApprover,
        Employee $inactiveApprover,
        User $admin,
    ): void {
        $previewEmployeeIds = [$manualEmployee->id, $invalidEmployee->id];

        LeaveApprovalChain::query()
            ->whereIn('id', array_values(self::PREVIEW_CHAIN_IDS))
            ->delete();
        LeaveApprovalChain::query()
            ->whereIn('employee_id', $previewEmployeeIds)
            ->where('change_reason', self::QA_REASON_PREFIX.' Preview chain')
            ->delete();

        $validSteps = [
            $this->approvalChainStep(1, 'verifier', 'Verifikator', $verifier, false),
            $this->approvalChainStep(2, 'kepala_bagian', 'Kepala Bagian', $approver, false),
            $this->approvalChainStep(3, 'pybmc', 'PYBMC', $finalApprover, true),
        ];
        $this->createPreviewChain(
            self::PREVIEW_CHAIN_IDS['manual'],
            $manualEmployee,
            'QA Fase 7 Chain Cuti Manual',
            $validSteps,
            $admin,
        );
        $this->createPreviewChain(
            self::PREVIEW_CHAIN_IDS['invalid'],
            $invalidEmployee,
            'QA Fase 7 Chain Approver Nonaktif',
            [
                $this->approvalChainStep(1, 'kepala_bagian', 'Kepala Bagian', $inactiveApprover, false),
                $this->approvalChainStep(2, 'pybmc', 'PYBMC', $finalApprover, true),
            ],
            $admin,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function createPreviewChain(
        string $id,
        Employee $employee,
        string $name,
        array $steps,
        User $admin,
    ): void {
        $chain = LeaveApprovalChain::query()->create([
            'id' => $id,
            'employee_id' => $employee->id,
            'name' => $name,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'change_reason' => self::QA_REASON_PREFIX.' Preview chain',
        ]);
        $chain->steps()->createMany($steps);
    }

    /** @return array<string, mixed> */
    private function approvalChainStep(
        int $order,
        string $type,
        string $label,
        Employee $approver,
        bool $isFinal,
    ): array {
        return [
            'step_order' => $order,
            'step_type' => $type,
            'role_label' => $label,
            'approver_role_key' => $type,
            'approver_employee_id' => $approver->id,
            'is_final' => $isFinal,
        ];
    }

    /**
     * Fakta manual dibuat melalui Action produksi agar validasi, kalkulasi hari kerja,
     * snapshot historis, audit, dan larangan approval ulang tetap satu kontrak.
     */
    private function ensureManualUsage(
        Employee $employee,
        RefJenisCuti $leaveType,
        Employee $activeApprover,
        Employee $inactiveApprover,
        User $admin,
    ): LeaveUsageRecord {
        $existing = LeaveUsageRecord::query()
            ->with('externalApprovalSteps')
            ->where('employee_id', $employee->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->where('administrative_note', self::MANUAL_USAGE_NOTE)
            ->limit(2)
            ->get();

        if ($existing->count() > 1) {
            throw new \RuntimeException('Fakta cuti manual fixture QA terduplikasi.');
        }

        if ($existing->isNotEmpty()) {
            $record = $existing->sole();

            if (! $this->matchesManualUsage($record, $leaveType, $activeApprover, $inactiveApprover)) {
                throw new \RuntimeException('Fakta cuti manual fixture QA tidak sesuai kontrak snapshot.');
            }

            return $record;
        }

        return app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            [
                'leave_type_id' => $leaveType->id,
                'tanggal_mulai' => '2026-08-06',
                'tanggal_selesai' => '2026-08-07',
                'alasan' => self::MANUAL_USAGE_NOTE,
                'approval_document_number' => null,
                'approval_steps' => [
                    [
                        'step_type' => 'verifier',
                        'approver_source' => 'simpeg_employee',
                        'approver_employee_id' => $activeApprover->id,
                        'acted_on' => '2026-08-03',
                        'decision_note' => 'Diverifikasi dari arsip eksternal.',
                    ],
                    [
                        'step_type' => 'kepala_bagian',
                        'approver_source' => 'simpeg_employee',
                        'approver_employee_id' => $inactiveApprover->id,
                        'acted_on' => '2026-08-04',
                        'decision_note' => 'Disetujui Kepala Bagian yang kini nonaktif.',
                    ],
                    [
                        'step_type' => 'pybmc',
                        'approver_source' => 'external_official',
                        'approver_name' => 'Kepala Lembaga Arsip QA',
                        'approver_position' => 'Pejabat Yang Berwenang Memberikan Cuti',
                        'approver_institution' => CutiInstitution::NAME,
                        'acted_on' => '2026-08-05',
                        'decision_note' => 'Persetujuan final tercatat pada arsip eksternal.',
                    ],
                ],
            ],
            null,
            $admin,
        );
    }

    private function matchesManualUsage(
        LeaveUsageRecord $record,
        RefJenisCuti $leaveType,
        Employee $activeApprover,
        Employee $inactiveApprover,
    ): bool {
        $steps = $record->externalApprovalSteps
            ->map(fn ($step): array => [
                $step->step_order,
                $step->step_type,
                $step->approver_source,
                $step->approver_employee_id,
                $step->approver_name_snapshot,
                $step->result_code,
            ])
            ->all();

        return $record->leave_type_id === $leaveType->id
            && $record->record_status === LeaveUsageRecord::STATUS_ACTIVE
            && $record->start_date?->toDateString() === '2026-08-06'
            && $record->end_date?->toDateString() === '2026-08-07'
            && $record->workdays === 2
            && $record->approval_document_number === null
            && $record->leave_request_id === null
            && $record->leave_request_case_id === null
            && $steps === [
                [1, 'verifier', 'simpeg_employee', $activeApprover->id, $activeApprover->nama_lengkap, 'verified'],
                [2, 'kepala_bagian', 'simpeg_employee', $inactiveApprover->id, $inactiveApprover->nama_lengkap, 'approved'],
                [3, 'pybmc', 'external_official', null, 'Kepala Lembaga Arsip QA', 'final_approved'],
            ];
    }

    private function createRequest(
        Employee $employee,
        RefJenisCuti $jenisCuti,
        string $status,
        string $fixtureName,
        int $currentStage,
        Employee $approver,
        Employee $finalApprover,
        ?string $decisionNote = null,
    ): LeaveRequest {
        $existing = LeaveRequest::query()->find(self::REQUEST_IDS[$fixtureName]);

        if ($existing !== null) {
            if ($existing->employee_id !== $employee->id || $fixtureName !== 'disetujui') {
                throw new \RuntimeException('UUID pengajuan fixture QA sudah digunakan oleh data yang tidak didukung.');
            }

            return $existing;
        }

        $request = LeaveRequest::create([
            'id' => self::REQUEST_IDS[$fixtureName],
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-11-02',
            'tanggal_selesai' => '2026-11-03',
            'jumlah_hari_kerja' => 2,
            'alasan' => self::QA_REASON_PREFIX.' '.$fixtureName,
            'status' => $status,
        ]);
        $request->forceFill(['current_stage' => $currentStage])->save();

        $firstStep = [
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
            'decision_note' => $decisionNote,
        ];
        $finalStep = [
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $finalApprover->id,
            'status' => 'pending',
            'is_final' => true,
        ];

        if ($status === 'disetujui') {
            $firstStep = [...$firstStep, 'status' => 'approved', 'acted_at' => '2026-10-01 09:00:00'];
            $finalStep = [...$finalStep, 'status' => 'approved', 'acted_at' => '2026-10-01 10:00:00'];
        }

        if ($status === 'tidak_disetujui') {
            $firstStep = [...$firstStep, 'status' => 'tidak_disetujui', 'acted_at' => '2026-10-01 09:00:00'];
            $finalStep = [
                ...$finalStep,
                'status' => 'skipped',
                'skipped_reason' => 'request_not_approved',
                'decision_note' => 'Dilewati karena pengajuan sudah tidak disetujui.',
                'acted_at' => '2026-10-01 09:00:00',
            ];
        }

        $request->steps()->createMany([$firstStep, $finalStep]);

        return $request;
    }
}
