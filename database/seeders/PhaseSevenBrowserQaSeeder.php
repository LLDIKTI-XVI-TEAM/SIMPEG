<?php

namespace Database\Seeders;

use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Support\Cuti\CutiInstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PhaseSevenBrowserQaSeeder extends Seeder
{
    private const QA_REASON_PREFIX = '[QA Phase 7 Browser]';

    private const KEPALA_LEMBAGA_EMAIL = 'qa-phase7-kepala-lembaga@example.test';

    private const MANUAL_EMPLOYEE_EMAIL = 'qa-phase7-cuti-manual@example.test';

    private const INVALID_EMPLOYEE_EMAIL = 'qa-phase7-chain-invalid@example.test';

    private const INACTIVE_APPROVER_EMAIL = 'qa-phase7-approver-nonaktif@example.test';

    /**
     * Persona browser QA kini memakai AKUN UJI SSO TERPETAKAN (fixture
     * SsoRoleMappedAccountSeeder) sehingga seluruh skenario dapat diuji lewat
     * login Keycloak nyata — jalur dev-login sudah dihapus dan tidak ada lagi
     * akun dengan password lokal yang bisa dipakai login.
     *
     * @see SsoRoleMappedAccountSeeder::ROLE_MAPPING
     */
    private const SSO_ADMIN_ROLE = 'admin_kepegawaian';

    private const SSO_APPROVER_ROLE = 'kepala_bagian';

    private const SSO_PEGAWAI_ROLE = 'pegawai';

    private const CHAIN_ID = '70000000-0000-4000-8000-000000000001';

    /** @var array<string, string> */
    private const PREVIEW_CHAIN_IDS = [
        'manual' => '70000000-0000-4000-8000-000000000002',
        'invalid' => '70000000-0000-4000-8000-000000000005',
    ];

    private const BALANCE_YEAR = 2026;

    private const RECONCILIATION_NOTE = '[QA Phase 7 Browser] Catatan pemakaian tahunan fixture.';

    private const RECONCILIATION_CORRECTION_REASON = 'Selaraskan ulang fakta pemakaian fixture browser QA.';

    private const MANUAL_USAGE_NOTE = '[QA Phase 7 Browser] Cuti manual dengan snapshot persetujuan.';

    /** @var array<int, int> */
    private const RECONCILIATION_USAGE = [
        2024 => 12,
        2025 => 12,
        2026 => 2,
    ];

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
            throw new RuntimeException(
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

            $admin = $this->upsertAdminSession();
            $kepalaLembaga = $this->upsertEmployee(
                self::KEPALA_LEMBAGA_EMAIL,
                '198001012026000002',
                'QA Fase 7 Kepala Lembaga',
                'pimpinan',
                $jenisPegawaiId,
                $statusAktifId,
                ['is_kepala_lembaga' => true]
            );
            $approver = $this->resolveDemoApprover();
            $normalEmployee = $this->resolveDemoPegawai($approver);
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
        $this->command?->line('QA_ADMIN_EMAIL='.$this->ssoEmailForRole(self::SSO_ADMIN_ROLE));
        $this->command?->line('QA_APPROVER_EMAIL='.$this->ssoEmailForRole(self::SSO_APPROVER_ROLE));
        $this->command?->line('QA_PEGAWAI_EMAIL='.$this->ssoEmailForRole(self::SSO_PEGAWAI_ROLE));
        $this->command?->line('QA_MANUAL_EMPLOYEE_ID='.$manualEmployee->id);
        $this->command?->line('QA_MANUAL_USAGE_ID='.$manualUsage->id);
        $this->command?->line('QA_INVALID_CHAIN_EMPLOYEE_ID='.$invalidEmployee->id);
    }

    /**
     * Email akun uji SSO untuk role persona QA — satu-satunya sumber adalah fixture
     * SsoRoleMappedAccountSeeder agar tidak ada duplikasi daftar akun uji di source.
     */
    private function ssoEmailForRole(string $role): string
    {
        $email = array_search($role, SsoRoleMappedAccountSeeder::roleMapping(), true);

        if ($email === false) {
            throw new RuntimeException("Fixture SSO untuk role '{$role}' tidak ditemukan di SsoRoleMappedAccountSeeder::roleMapping().");
        }

        return $email;
    }

    /**
     * User akun uji SSO untuk role persona QA, di-resolve dengan kontrak kanonis
     * Issue #6: pegawai dicari via email kanonis (case-insensitive, kolom legacy +
     * email_pribadi), lalu user diambil via employee_id. Lookup email eksak pada user
     * tidak cukup karena SsoRoleMappedAccountSeeder mendukung user existing dengan
     * email internal berbeda dari email mapping.
     */
    private function ssoUserForRole(string $role): User
    {
        $email = strtolower($this->ssoEmailForRole($role));

        // Sama seperti resolver SSO utama: kandidat diambil hingga 2 dan dihitung —
        // pencocokan ambigu (email_pribadi pegawai A = kolom legacy email pegawai B)
        // tidak boleh dipilih arbitrer; persona QA wajib menunjuk tepat satu pegawai.
        $candidates = Employee::query()
            ->where(function ($query) use ($email): void {
                $query
                    ->whereRaw('lower(email) = ?', [$email])
                    ->orWhereRaw('lower(email_pribadi) = ?', [$email]);
            })
            ->limit(2)
            ->get()
            ->unique('id');

        if ($candidates->count() > 1) {
            throw new RuntimeException("Persona QA untuk role '{$role}' mencocokkan lebih dari satu pegawai — perbaiki data email kanonis sebelum menjalankan seeder.");
        }

        $employee = $candidates->first();

        $user = $employee !== null
            ? User::query()->where('employee_id', $employee->id)->first()
            : User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            throw new RuntimeException("Akun SSO untuk role '{$role}' belum tersedia. Jalankan SsoRoleMappedAccountSeeder terlebih dahulu.");
        }

        return $user;
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

    private function upsertAdminSession(): User
    {
        // Sesi admin QA memakai akun uji SSO terpetakan (admin_kepegawaian) — akun
        // sudah ditanam SsoRoleMappedAccountSeeder beserta pegawai aktifnya. Seeder
        // memvalidasi state yang disiapkan, bukan memperbaikinya secara diam-diam.
        $user = $this->ssoUserForRole(self::SSO_ADMIN_ROLE);

        if ($user->employee_id === null) {
            throw new RuntimeException('Persona QA admin kepegawaian belum terhubung ke pegawai. Petakan akun melalui jalur administratif SIMPEG (UpdateUserMappingAction) lalu jalankan ulang seeder.');
        }

        if ($user->role !== self::SSO_ADMIN_ROLE) {
            throw new RuntimeException("Persona QA admin kepegawaian ber-role '{$user->role}', expected '".self::SSO_ADMIN_ROLE."'. Set role melalui jalur administratif SIMPEG lalu jalankan ulang seeder.");
        }

        // Persona admin wajib memegang pegawai AKTIF: sesi admin QA yang gagal melewati
        // EnsureActiveEmployeeAccount membuat skenario browser QA tidak dapat dijalankan.
        $employee = Employee::query()->find($user->employee_id);

        if ($employee === null || ! $employee->isActive()) {
            $status = $employee?->status_aktif ?? 'tidak diketahui';
            throw new RuntimeException("Persona QA admin kepegawaian memegang pegawai berstatus '{$status}', expected aktif. Perbaiki status melalui jalur administratif SIMPEG lalu jalankan ulang seeder.");
        }

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

    private function resolveDemoApprover(): Employee
    {
        $user = $this->ssoUserForRole(self::SSO_APPROVER_ROLE);

        $employee = $user->employee_id !== null
            ? Employee::query()->find($user->employee_id)
            : null;

        if ($employee === null) {
            throw new RuntimeException('Persona QA kepala bagian belum terhubung ke pegawai. Petakan akun melalui jalur administratif SIMPEG (UpdateUserMappingAction) lalu jalankan ulang seeder.');
        }

        // Persona SSO existing tidak boleh di-fix secara diam-diam: validasi state
        // yang disiapkan (aktif + role sesuai) dan gagal dengan pesan actionable agar
        // misconfiguration UAT tetap terlihat, bukan disembunyikan oleh seeder.
        if (! $employee->isActive()) {
            throw new RuntimeException("Persona QA kepala bagian ('{$employee->nama_lengkap}') berstatus '{$employee->status_aktif}', expected aktif. Perbaiki status melalui jalur administratif SIMPEG lalu jalankan ulang seeder.");
        }

        if ($user->role !== self::SSO_APPROVER_ROLE || $employee->role !== self::SSO_APPROVER_ROLE) {
            throw new RuntimeException("Persona QA kepala bagian ber-role user '{$user->role}' / employee '{$employee->role}', expected '".self::SSO_APPROVER_ROLE."'. Set role melalui jalur administratif SIMPEG lalu jalankan ulang seeder.");
        }

        return $employee;
    }

    /**
     * Memakai akun uji SSO terpetakan (pegawai) agar form pengajuan diuji dengan
     * sesi pegawai nyata melalui login Keycloak — bukan akun demo ber-password lokal.
     */
    private function resolveDemoPegawai(
        Employee $approver,
    ): Employee {
        $user = $this->ssoUserForRole(self::SSO_PEGAWAI_ROLE);

        $employee = $user->employee_id !== null
            ? Employee::query()->find($user->employee_id)
            : null;

        if ($employee === null) {
            throw new RuntimeException('Persona QA pegawai belum terhubung ke pegawai. Petakan akun melalui jalur administratif SIMPEG (UpdateUserMappingAction) lalu jalankan ulang seeder.');
        }

        // Persona SSO existing tidak boleh di-fix secara diam-diam: validasi state
        // yang disiapkan (aktif + role sesuai) dan gagal dengan pesan actionable.
        if (! $employee->isActive()) {
            throw new RuntimeException("Persona QA pegawai ('{$employee->nama_lengkap}') berstatus '{$employee->status_aktif}', expected aktif. Perbaiki status melalui jalur administratif SIMPEG lalu jalankan ulang seeder.");
        }

        if ($user->role !== self::SSO_PEGAWAI_ROLE || $employee->role !== self::SSO_PEGAWAI_ROLE) {
            throw new RuntimeException("Persona QA pegawai ber-role user '{$user->role}' / employee '{$employee->role}', expected '".self::SSO_PEGAWAI_ROLE."'. Set role melalui jalur administratif SIMPEG lalu jalankan ulang seeder.");
        }

        // Wiring relasi QA (penugasan atasan) boleh disiapkan seeder — bukan status
        // lifecycle ataupun role internal.
        $employee->fill([
            'kepala_bagian_id' => $approver->id,
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
                'role_label' => 'Atasan Langsung',
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
        $approvedFact = app(LeaveUsageRecordService::class)->recordApprovedRequest($approvedRequest, $admin);

        // Fakta pengajuan harus ada lebih dulu agar snapshot rekonsiliasi membekukan pemakaian yang telah dihitung.
        $this->ensureAnnualReconciliation($normalEmployee, $approvedFact, $admin);

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
            $this->approvalChainStep(2, 'kepala_bagian', 'Atasan Langsung', $approver, false),
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
                $this->approvalChainStep(1, 'kepala_bagian', 'Atasan Langsung', $inactiveApprover, false),
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
            throw new RuntimeException('Fakta cuti manual fixture QA terduplikasi.');
        }

        if ($existing->isNotEmpty()) {
            $record = $existing->sole();

            if (! $this->matchesManualUsage($record, $leaveType, $activeApprover, $inactiveApprover)) {
                throw new RuntimeException('Fakta cuti manual fixture QA tidak sesuai kontrak snapshot.');
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

    /**
     * Membentuk projection QA dari satu snapshot fakta tiga tahun dengan aktor manusia eksplisit.
     * Snapshot identik tidak diganti agar rerun tidak menambah fact, ledger, atau audit duplikat.
     */
    private function ensureAnnualReconciliation(
        Employee $employee,
        LeaveUsageRecord $approvedFact,
        User $admin,
    ): void {
        $active = LeaveUsageReconciliationSet::query()
            ->with(['records' => fn ($query) => $query->orderBy('usage_year')])
            ->where('employee_id', $employee->id)
            ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->first();

        if ($active !== null && $this->matchesQaReconciliation($active, $approvedFact)) {
            return;
        }

        $reconciledAt = CarbonImmutable::create(
            self::BALANCE_YEAR,
            12,
            31,
            12,
            0,
            0,
            config('app.timezone'),
        );
        $service = app(LeaveUsageReconciliationService::class);

        if ($active === null) {
            $service->createAnnualReconciliationSet(
                $employee,
                self::BALANCE_YEAR,
                self::RECONCILIATION_USAGE,
                $reconciledAt,
                self::RECONCILIATION_NOTE,
                $admin,
            );

            return;
        }

        if ($active->balance_year !== self::BALANCE_YEAR) {
            throw new RuntimeException('Catatan pemakaian aktif fixture QA memakai tahun saldo yang tidak didukung.');
        }

        $service->replaceAnnualReconciliationSet(
            $active,
            self::RECONCILIATION_USAGE,
            $reconciledAt,
            self::RECONCILIATION_NOTE,
            self::RECONCILIATION_CORRECTION_REASON,
            $admin,
        );
    }

    /**
     * No-op hanya aman bila snapshot tiga tahun juga membekukan tepat satu fakta pengajuan QA.
     * Membership yang hilang harus memicu replacement agar projection tidak menghitung dua kali.
     */
    private function matchesQaReconciliation(
        LeaveUsageReconciliationSet $set,
        LeaveUsageRecord $approvedFact,
    ): bool {
        if ($set->balance_year !== self::BALANCE_YEAR || $set->records->count() !== 3) {
            return false;
        }

        $usage = $set->records
            ->filter(fn (LeaveUsageRecord $record): bool => $record->source_type === LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION
                && $record->record_status === LeaveUsageRecord::STATUS_ACTIVE)
            ->mapWithKeys(fn (LeaveUsageRecord $record): array => [
                $record->usage_year => $record->workdays,
            ])
            ->all();

        if ($usage !== self::RECONCILIATION_USAGE
            || $approvedFact->employee_id !== $set->employee_id
            || $approvedFact->source_type !== LeaveUsageRecord::SOURCE_APPROVED_REQUEST
            || $approvedFact->record_status !== LeaveUsageRecord::STATUS_ACTIVE
            || $approvedFact->usage_year !== self::BALANCE_YEAR
            || $approvedFact->workdays !== self::RECONCILIATION_USAGE[self::BALANCE_YEAR]
        ) {
            return false;
        }

        $annualFact = $set->records->first(
            fn (LeaveUsageRecord $record): bool => $record->usage_year === self::BALANCE_YEAR
                && $record->source_type === LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION
                && $record->record_status === LeaveUsageRecord::STATUS_ACTIVE,
        );
        $membership = $set->memberships()->get();

        return $annualFact instanceof LeaveUsageRecord
            && $membership->count() === 1
            && $membership->sole()->annual_reconciliation_record_id === $annualFact->id
            && $membership->sole()->itemized_usage_record_id === $approvedFact->id
            && $membership->sole()->included_workdays === $approvedFact->workdays;
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
                throw new RuntimeException('UUID pengajuan fixture QA sudah digunakan oleh data yang tidak didukung.');
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
            'role_label' => 'Atasan Langsung',
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
