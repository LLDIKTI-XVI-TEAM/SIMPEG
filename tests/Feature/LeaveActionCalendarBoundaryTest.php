<?php

namespace Tests\Feature;

use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\EmployeeFileStorageService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveActionCalendarBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
    }

    /**
     * Membuat state yang sengaja memanggil Action langsung agar batas domain diuji tanpa FormRequest.
     *
     * @return array{user: User, employee: Employee, type: RefJenisCuti, request: LeaveRequest, reservation_event_count: int}
     */
    private function fixture(string $code): array
    {
        $jenisPegawai = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenisPegawai->id]);
        $supervisor = Employee::factory()->create();
        $finalApprover = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $type = RefJenisCuti::query()->where('code', $code)->firstOrFail();

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2018-01-01',
            'no_sk' => 'SK-ACTION-BOUNDARY',
            'tanggal_sk' => '2018-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain Action boundary',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture direct Action.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $supervisor->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $finalApprover->id,
                'is_final' => true,
            ],
        ]);

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-10',
            'jumlah_hari_kerja' => 5,
            'alasan' => 'Fixture resubmit direct Action.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1',
            'nomor_telepon' => '+62 431 123456',
            'status' => 'perlu_perubahan',
        ]);
        $reservationEventCount = 0;

        if ($code === 'tahunan') {
            LeaveBalanceReservationEvent::create([
                'employee_id' => $employee->id,
                'leave_request_id' => $request->id,
                'leave_balance_id' => $balance->id,
                'tahun' => 2026,
                'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
                'amount' => 5,
                'reason' => 'Baseline direct Action.',
                'dedup_key' => "action-boundary:{$request->id}",
                'occurred_at' => now(),
            ]);
            $reservationEventCount = 1;
        }

        return [
            'user' => $user,
            'employee' => $employee,
            'type' => $type,
            'request' => $request,
            'reservation_event_count' => $reservationEventCount,
        ];
    }

    public static function crossYearActionCases(): array
    {
        return [
            'submit tahunan' => ['submit', 'tahunan'],
            'submit cuti besar' => ['submit', 'besar'],
            'resubmit tahunan' => ['resubmit', 'tahunan'],
            'resubmit cuti besar' => ['resubmit', 'besar'],
        ];
    }

    #[DataProvider('crossYearActionCases')]
    public function test_direct_action_menolak_lintas_tahun_sebelum_file_dan_mutasi(string $operation, string $code): void
    {

        $fixture = $this->fixture($code);
        $payload = [
            'jenis_cuti_id' => $fixture['type']->id,
            'tanggal_mulai' => '2026-12-30',
            'tanggal_selesai' => '2027-01-05',
            'alasan' => 'Direct Action lintas tahun.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1',
            'nomor_telepon' => '+62 431 123456',
        ];
        $httpRequest = Request::create('/direct-action', 'POST', $payload, [], [
            'lampiran' => UploadedFile::fake()->create('lintas-tahun.pdf', 100, 'application/pdf'),
        ]);
        $httpRequest->setUserResolver(fn () => $fixture['user']);
        $beforeRequestCount = LeaveRequest::query()->count();
        $beforeAuditCount = AuditLog::query()->count();
        $beforeNotificationCount = SimpegNotification::query()->count();

        $this->mock(EmployeeFileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('storeLampiran');
            /** @var Expectation $deleteExpectation */
            $deleteExpectation = $mock->shouldReceive('deletePublicFile');
            $deleteExpectation->zeroOrMoreTimes()->andReturnUsing(function (?string $path): void {
                $this->assertNull($path);
            });
        });

        try {
            if ($operation === 'submit') {
                app(SubmitLeaveRequestAction::class)->execute($fixture['employee'], $payload, $httpRequest);
            } else {
                app(ResubmitLeaveRequestAction::class)->execute($fixture['request'], $payload, $httpRequest);
            }
            $this->fail('Direct Action lintas tahun harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Pengajuan cuti tidak boleh melewati tahun kalender. Pisahkan menjadi dua pengajuan terpisah untuk tiap tahun.',
                $exception->errors()['tanggal_selesai'][0],
            );
        }

        $this->assertSame($beforeRequestCount, LeaveRequest::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('cuti'));
        $this->assertSame($fixture['reservation_event_count'], LeaveBalanceReservationEvent::query()->count());
        $this->assertSame($beforeAuditCount, AuditLog::query()->count());
        $this->assertSame($beforeNotificationCount, SimpegNotification::query()->count());

        if ($operation === 'resubmit') {
            $fixture['request']->refresh();
            $this->assertSame('perlu_perubahan', $fixture['request']->status);
            $this->assertSame('2026-07-06', $fixture['request']->tanggal_mulai->toDateString());
            $this->assertSame('2026-07-10', $fixture['request']->tanggal_selesai->toDateString());
            $this->assertSame('Fixture resubmit direct Action.', $fixture['request']->alasan);
            $this->assertNull($fixture['request']->lampiran_path);
        }
    }

    public function test_direct_submit_menerima_rentang_dalam_satu_tahun_kalender(): void
    {
        $fixture = $this->fixture('tahunan');
        $payload = [
            'jenis_cuti_id' => $fixture['type']->id,
            'tanggal_mulai' => '2026-12-01',
            'tanggal_selesai' => '2026-12-03',
            'alasan' => 'Direct Action satu tahun kalender.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1',
            'nomor_telepon' => '+62 431 123456',
        ];
        $httpRequest = Request::create('/direct-action', 'POST', $payload);
        $httpRequest->setUserResolver(fn () => $fixture['user']);

        $leaveRequest = app(SubmitLeaveRequestAction::class)->execute($fixture['employee'], $payload, $httpRequest);

        $this->assertSame($fixture['employee']->id, $leaveRequest->employee_id);
        $this->assertSame('2026-12-01', $leaveRequest->tanggal_mulai->toDateString());
        $this->assertSame('2026-12-03', $leaveRequest->tanggal_selesai->toDateString());
        $this->assertSame('menunggu_approval', $leaveRequest->status);
        $this->assertSame(2, LeaveRequest::query()->count());
    }
}
