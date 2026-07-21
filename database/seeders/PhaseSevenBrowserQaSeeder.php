<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseSevenBrowserQaSeeder extends Seeder
{
    private const QA_REASON_PREFIX = '[QA Phase 7 Browser]';

    private const KEPALA_LEMBAGA_EMAIL = 'qa-phase7-kepala-lembaga@example.test';

    private const DEMO_APPROVER_USERNAME = 'demo-klabat-kabag';

    private const DEMO_PEGAWAI_USERNAME = 'demo-klabat-pegawai';

    private const CHAIN_ID = '70000000-0000-4000-8000-000000000001';

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

        [$kepalaLembaga, $pendingRequest] = DB::transaction(function (): array {
            $jenisPegawaiId = RefJenisPegawai::query()->where('nama', 'PNS')->value('id');
            $statusAktifId = RefStatusPegawai::query()->where('nama', 'Aktif')->value('id');
            $jenisCutiTahunan = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();

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

            $this->replaceQaFixtures($normalEmployee, $approver, $kepalaLembaga, $admin, $jenisCutiTahunan);

            $pendingRequest = LeaveRequest::query()
                ->where('employee_id', $normalEmployee->id)
                ->where('alasan', self::QA_REASON_PREFIX.' pending approval')
                ->firstOrFail();

            return [$kepalaLembaga, $pendingRequest];
        });

        $this->command?->line('QA_KEPALA_LEMBAGA_ID='.$kepalaLembaga->id);
        $this->command?->line('QA_PENDING_CUTI_ID='.$pendingRequest->id);
        $this->command?->line('QA_APPROVER_USERNAME='.self::DEMO_APPROVER_USERNAME);
    }

    private function upsertAdminSession(?string $jenisPegawaiId, ?string $statusAktifId): User
    {
        $email = 'demo-klabat-kepeg@example.test';
        $user = User::query()
            ->where('keycloak_username', 'demo-klabat-kepeg')
            ->orWhere('email', $email)
            ->first() ?? new User;

        $employee = $user->employee_id !== null
            ? Employee::withTrashed()->find($user->employee_id)
            : Employee::withTrashed()->where('email', $email)->first();

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
        ?string $statusAktifId,
        array $extra = [],
    ): Employee {
        $employee = Employee::withTrashed()->where('email', $email)->first() ?? new Employee;

        if ($employee->trashed()) {
            $employee->restore();
        }

        $employee->fill([
            'nama_lengkap' => $name,
            'nip' => $nip,
            'tempat_lahir' => 'Gorontalo',
            'tanggal_lahir' => '1980-01-01',
            'jenis_kelamin' => 'L',
            'jenis_pegawai_id' => $jenisPegawaiId,
            'status_pegawai_id' => $statusAktifId,
            'status_aktif' => 'Aktif',
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
            ? Employee::withTrashed()->find($user->employee_id)
            : Employee::withTrashed()->where('email', $user->email)->first();

        if ($employee === null) {
            throw new \RuntimeException('Employee untuk demo-klabat-kabag belum tersedia. Jalankan DemoSsoUserSeeder terlebih dahulu.');
        }

        if ($employee->trashed()) {
            $employee->restore();
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
            ? Employee::withTrashed()->find($user->employee_id)
            : Employee::withTrashed()->where('email', $user->email)->first();

        if ($employee === null) {
            throw new \RuntimeException('Employee untuk demo-klabat-pegawai belum tersedia. Jalankan DemoSsoUserSeeder terlebih dahulu.');
        }

        if ($employee->trashed()) {
            $employee->restore();
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
            ->delete();
        LeaveApprovalChain::query()
            ->whereKey(self::CHAIN_ID)
            ->delete();
        LeaveRequest::query()
            ->where('employee_id', $normalEmployee->id)
            ->where('alasan', 'like', self::QA_REASON_PREFIX.'%')
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

        LeaveBalance::updateOrCreate(
            ['employee_id' => $normalEmployee->id, 'tahun' => 2026],
            [
                'jatah_awal' => 12,
                'carry_over' => 0,
                'terpakai' => 0,
                'sisa' => 12,
                'sisa_n2' => 0,
                'sisa_n1' => 0,
                'sisa_tahun_berjalan' => 12,
                'terpakai_tahun_berjalan' => 0,
                'hangus' => 0,
            ],
        );

        // Snapshot fixture dibuat langsung supaya browser QA dapat memeriksa semua status tanpa audit atau notifikasi.
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
            'disetujui',
            'disetujui',
            2,
            $approver,
            $kepalaLembaga,
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
