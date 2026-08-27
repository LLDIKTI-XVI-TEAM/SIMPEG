<?php

namespace Tests\Feature;

use App\Actions\Cuti\GenerateLeaveProofAction;
use App\Models\Employee;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PimpinanLeaveProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake('local');
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

        foreach (['pimpinan.cuti.document.show', 'pimpinan.cuti.document.download'] as $routeName) {
            $response = $this->actingAs($pimpinan)
                ->get(route($routeName, $leave))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertHeader('pragma', 'no-cache')
                ->assertHeader('x-content-type-options', 'nosniff');

            $cacheControl = strtolower((string) $response->headers->get('cache-control'));
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('no-cache', $cacheControl);
            $this->assertStringContainsString('must-revalidate', $cacheControl);
            $this->assertSame('0', $response->headers->get('expires'));
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidStoredProofCases(): array
    {
        return [
            'folder request lain' => ['cross_request'],
            'folder dokumen pegawai' => ['employee_documents'],
            'path traversal' => ['traversal'],
            'mime bukan PDF' => ['wrong_mime'],
            'file hilang' => ['missing'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidExistingProofCases(): array
    {
        return [
            'folder request lain' => ['cross_request'],
            'folder dokumen pegawai' => ['employee_documents'],
            'mime bukan PDF' => ['wrong_mime'],
        ];
    }

    #[DataProvider('invalidStoredProofCases')]
    public function test_download_bukti_tersimpan_fail_closed_untuk_path_mime_atau_file_tidak_sah(string $case): void
    {
        [$leave, $pimpinan] = $this->leaveAwaitingFinalApproval();

        $this->actingAs($pimpinan)->post(route('pimpinan.cuti.decision', $leave), [
            'keputusan' => 'DISETUJUI',
            'catatan' => 'Disetujui.',
        ])->assertRedirect();

        $proof = LeaveProof::query()->where('leave_request_id', $leave->id)->sole();
        $filename = Str::uuid().'.pdf';
        $path = match ($case) {
            'cross_request' => 'leave-proofs/'.Str::uuid().'/'.$filename,
            'employee_documents' => 'employee-documents/'.$leave->employee_id.'/'.$filename,
            'traversal' => 'leave-proofs/'.$leave->id.'/../'.$filename,
            default => 'leave-proofs/'.$leave->id.'/'.$filename,
        };

        if ($case !== 'missing' && $case !== 'traversal') {
            $this->assertTrue(Storage::disk('local')->put($path, "%PDF-1.4\nSIMPEG TEST\n%%EOF"));
        }

        $proof->forceFill([
            'document_path' => $path,
            'document_mime' => $case === 'wrong_mime' ? 'image/png' : 'application/pdf',
        ])->save();

        foreach (['pimpinan.cuti.document.show', 'pimpinan.cuti.document.download'] as $routeName) {
            $this->actingAs($pimpinan)
                ->get(route($routeName, $leave))
                ->assertNotFound()
                ->assertDontSee('SIMPEG TEST');
        }
    }

    #[DataProvider('invalidExistingProofCases')]
    public function test_generator_idempoten_menolak_bukti_existing_yang_tidak_memenuhi_kontrak_storage(string $case): void
    {
        [$leave, $pimpinan] = $this->leaveAwaitingFinalApproval();

        $this->actingAs($pimpinan)->post(route('pimpinan.cuti.decision', $leave), [
            'keputusan' => 'DISETUJUI',
            'catatan' => 'Disetujui.',
        ])->assertRedirect();

        $proof = LeaveProof::query()->where('leave_request_id', $leave->id)->sole();
        $path = $case === 'cross_request'
            ? 'leave-proofs/'.Str::uuid().'/'.Str::uuid().'.pdf'
            : ($case === 'employee_documents'
                ? 'employee-documents/'.$leave->employee_id.'/'.Str::uuid().'.pdf'
                : (string) $proof->document_path);
        $this->assertTrue(Storage::disk('local')->put($path, "%PDF-1.4\nSIMPEG TEST\n%%EOF"));
        $proof->forceFill([
            'document_path' => $path,
            'document_mime' => $case === 'wrong_mime' ? 'image/png' : 'application/pdf',
        ])->save();

        $this->expectException(RuntimeException::class);
        app(GenerateLeaveProofAction::class)->execute($leave->fresh(), $pimpinan);
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
