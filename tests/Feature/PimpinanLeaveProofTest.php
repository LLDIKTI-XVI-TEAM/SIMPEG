<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PimpinanLeaveProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_final_approval_generates_one_private_leave_proof_document(): void
    {

        [$leave, $pimpinan] = $this->leaveAwaitingFinalApproval();

        $this->actingAs($pimpinan)
            ->post(route('pimpinan.cuti.decision', $leave), [
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Disetujui.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave));

        $proof = LeaveProof::query()->where('leave_request_id', $leave->id)->first();

        $this->assertNotNull($proof);
        $this->assertNotSame('', $proof->token);
        $this->assertNotNull($proof->generated_at);
        Storage::disk('local')->assertExists($proof->document_path);
    }

    public function test_public_verification_shows_required_leave_data_without_sensitive_identifiers(): void
    {

        [$leave, $pimpinan] = $this->leaveAwaitingFinalApproval();
        $leave->employee->forceFill([
            'nik' => '7301010101010001',
            'no_kk' => '7301010101010002',
        ])->save();

        $this->actingAs($pimpinan)->post(route('pimpinan.cuti.decision', $leave), [
            'keputusan' => 'DISETUJUI',
            'catatan' => 'Disetujui.',
        ]);
        $proof = LeaveProof::query()->where('leave_request_id', $leave->id)->firstOrFail();

        $this->get(route('cuti.verify', $proof->token))
            ->assertOk()
            ->assertSee('LLDIKTI Wilayah XVI')
            ->assertSee('Pemohon Cuti Final')
            ->assertSee('Cuti Sakit')
            ->assertSee('Disetujui')
            ->assertDontSee('7301010101010001')
            ->assertDontSee('7301010101010002');
    }

    public function test_invalid_public_verification_token_returns_a_safe_not_found_page(): void
    {
        $this->get(route('cuti.verify', 'token-tidak-valid'))
            ->assertNotFound()
            ->assertSee('Dokumen Tidak Ditemukan')
            ->assertDontSee('NIK')
            ->assertDontSee('No. KK');
    }

    public function test_pimpinan_can_open_and_download_a_final_leave_document(): void
    {

        [$leave, $pimpinan] = $this->leaveAwaitingFinalApproval();

        $this->actingAs($pimpinan)->post(route('pimpinan.cuti.decision', $leave), [
            'keputusan' => 'DISETUJUI',
            'catatan' => 'Disetujui.',
        ]);

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.document.show', $leave))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.document.download', $leave))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_non_pimpinan_cannot_open_a_final_leave_document(): void
    {

        [$leave, $pimpinan] = $this->leaveAwaitingFinalApproval();

        $this->actingAs($pimpinan)->post(route('pimpinan.cuti.decision', $leave), [
            'keputusan' => 'DISETUJUI',
            'catatan' => 'Disetujui.',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'pegawai']))
            ->get(route('pimpinan.cuti.document.download', $leave))
            ->assertForbidden();
    }

    /** @return array{LeaveRequest, User} */
    private function leaveAwaitingFinalApproval(): array
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Cuti Final']);
        $approver = Employee::factory()->create(['nama_lengkap' => 'Pimpinan Final']);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Sakit',
                'code' => 'cuti_sakit',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        return [$leave, User::factory()->pimpinan()->create(['employee_id' => $approver->id])];
    }
}
