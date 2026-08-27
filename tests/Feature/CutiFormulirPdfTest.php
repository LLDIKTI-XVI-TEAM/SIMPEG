<?php

namespace Tests\Feature;

use App\Actions\Cuti\DownloadOfficialLeavePdfAction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\Cuti\LeaveProofService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class CutiFormulirPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_malformed_uuid_returns_not_found(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/dashboard/cuti/not-a-uuid/formulir-pdf')
            ->assertNotFound();
    }

    public function test_requester_can_download_final_official_form(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($fixture['leave_request']))
            ->assertOk();
    }

    public function test_final_official_form_is_legal_portrait_pdf_attachment(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        $response = $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($fixture['leave_request']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename=Formulir_Cuti_'.$fixture['leave_request']->id.'.pdf',
            $response->headers->get('Content-Disposition'),
        );
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $content);
        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $content, $mediaBox);
        $this->assertEqualsWithDelta(612.0, (float) $mediaBox[1], 0.5);
        $this->assertEqualsWithDelta(1008.0, (float) $mediaBox[2], 0.5);
    }

    public function test_final_official_form_renders_valid_pdf_for_maximum_unbroken_content(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $reason = str_repeat('A', 500);
        $address = str_repeat('B', 1000);
        $employeeName = rtrim(str_repeat('Nama Pegawai Panjang ', 10));
        $position = rtrim(str_repeat('Jabatan Panjang ', 10));
        $unit = rtrim(str_repeat('Unit Kerja Panjang ', 5));
        $fixture['leave_request']->update([
            'alasan' => $reason,
            'alamat_selama_cuti' => $address,
            'nomor_telepon' => '+62 (431) 123-456',
        ]);
        $fixture['leave_request']->employee->update(['nama_lengkap' => $employeeName]);
        $fixture['leave_request']->employee->positionHistories()->where('is_latest', true)->update([
            'nama_jabatan' => $position,
        ]);
        RefUnitKerja::query()->where('nama', 'Bagian Kepegawaian')->update(['nama' => $unit]);
        $fixture['leave_request']->steps()->where('step_order', 1)->update(['decision_note' => str_repeat('C', 500)]);

        $response = $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($fixture['leave_request']))
            ->assertOk();

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+612(?:\.0+)?\s+1008(?:\.0+)?\s*\]/', $content);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)\b/', $content));
        $data = $this->viewDataFor($fixture['leave_request']->fresh());
        $this->assertSame($reason, $data['reason']);
        $this->assertSame($address, $data['addressDuringLeave']);
        $this->assertSame('+62 (431) 123-456', $data['phoneDuringLeave']);
        $this->assertSame($employeeName, $data['employeeName']);
        $this->assertSame($position, $data['employeePosition']);
        $this->assertSame($unit, $data['employeeUnit']);
        $this->assertSame(str_repeat('C', 500), $data['steps'][0]['note']);
    }

    public function test_final_official_form_download_uses_at_most_twenty_application_queries(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $this->actingAs($fixture['requester_user']);
        $queries = [];
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        // Dispatcher sementara mengisolasi pengukuran agar listener tidak bocor ke test lain.
        $connection->setEventDispatcher(new Dispatcher($this->app));
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            $response = $this->get($this->formUrl($fixture['leave_request']));
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $response->assertOk();
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertLessThanOrEqual(20, count($queries), implode(PHP_EOL, $queries));
    }

    public function test_repeated_official_form_downloads_are_read_only_and_keep_issuance_snapshot(): void
    {

        $fixture = $this->makeOfficialFormFixture();
        $leaveRequest = $fixture['leave_request']->fresh();
        $proof = $fixture['proof']->fresh();
        $requestBefore = $leaveRequest->getAttributes();
        $proofBefore = $proof->getAttributes();
        $proofCountBefore = LeaveProof::query()->count();
        $auditCountBefore = DB::table('audit_logs')->count();
        $storageFilesBefore = Storage::disk('local')->allFiles();
        $issueDateTimeBefore = $this->viewDataFor($leaveRequest)['issueDateTimeLabel'];

        // Unduhan adalah pembacaan PII; jam akses tidak boleh mengubah bukti penerbitan atau menulis jejak baru.
        Carbon::setTestNow('2030-01-01 00:00:00 UTC');
        try {
            foreach (['2030-01-01 00:00:00 UTC', '2030-02-01 00:00:00 UTC', '2030-03-01 00:00:00 UTC'] as $now) {
                Carbon::setTestNow($now);
                $this->session(['last_activity_at' => now()->timestamp]);
                $response = $this->actingAs($fixture['requester_user'])
                    ->get($this->formUrl($leaveRequest))
                    ->assertOk();

                $content = $response->getContent();
                $this->assertIsString($content);
                $this->assertStringStartsWith('%PDF-', $content);
                $this->assertSame($issueDateTimeBefore, $this->viewDataFor($leaveRequest)['issueDateTimeLabel']);
            }
        } finally {
            Carbon::setTestNow();
        }

        $leaveRequest->refresh();
        $proof->refresh();
        $this->assertSame($requestBefore, $leaveRequest->getAttributes());
        $this->assertSame($proofBefore, $proof->getAttributes());
        $this->assertSame($proofCountBefore, LeaveProof::query()->count());
        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
        $this->assertNull($proof->document_path);
        $this->assertSame($storageFilesBefore, Storage::disk('local')->allFiles());
        $this->assertSame($issueDateTimeBefore, $this->viewDataFor($leaveRequest)['issueDateTimeLabel']);
    }

    public function test_every_snapshotted_approver_including_historical_approver_can_download_final_official_form(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        foreach ($fixture['approver_users'] as $approverUser) {
            $this->actingAs($approverUser)
                ->get($this->formUrl($fixture['leave_request']))
                ->assertOk();
        }
    }

    public function test_user_with_cuti_read_all_can_download_final_official_form(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $viewer = User::factory()->adminKepegawaian()->create();

        $this->actingAs($viewer)
            ->get($this->formUrl($fixture['leave_request']))
            ->assertOk();
    }

    public function test_stored_final_official_form_serves_identical_immutable_bytes_to_every_authorized_audience(): void
    {
        Storage::fake('local');
        $fixture = $this->makeOfficialFormFixture();
        $leaveRequest = $fixture['leave_request'];
        $storedBytes = "%PDF-1.4\n% snapshot resmi saat persetujuan final\n%%EOF\n";
        $storedPath = 'leave-proofs/'.$leaveRequest->id.'/00000000-0000-4000-8000-000000000f01.pdf';
        $this->assertTrue(Storage::disk('local')->put($storedPath, $storedBytes));
        $fixture['proof']->update([
            'document_path' => $storedPath,
            'document_mime' => 'application/pdf',
        ]);
        $this->adoptStoredProofArtifact($leaveRequest, $storedPath, $storedBytes);

        // Perubahan administratif setelah persetujuan tidak boleh menulis ulang isi bukti final yang sudah diterbitkan.
        $leaveRequest->employee->positionHistories()->where('is_latest', true)->update([
            'nama_jabatan' => 'Jabatan Setelah Persetujuan',
        ]);
        $leaveRequest->employee->leaveBalances()->where('tahun', 2026)->update([
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 1,
        ]);

        $readAllViewer = User::factory()->adminKepegawaian()->create();
        foreach ([
            'pemohon' => $fixture['requester_user'],
            'approver dari snapshot' => $fixture['approver_users'][0],
            'pemilik permission cuti.read_all' => $readAllViewer,
        ] as $scenario => $user) {
            $response = $this->actingAs($user)
                ->get($this->formUrl($leaveRequest));

            $response->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertHeader(
                    'content-disposition',
                    'attachment; filename=Formulir_Cuti_'.$leaveRequest->id.'.pdf',
                );
            $this->assertSame($storedBytes, $this->responseBytes($response), $scenario);
        }
    }

    public function test_official_form_fails_closed_when_adopted_stored_artifact_bytes_change(): void
    {
        Storage::fake('local');
        $fixture = $this->makeOfficialFormFixture();
        $leaveRequest = $fixture['leave_request'];
        $originalBytes = "%PDF-1.4\n% artifact resmi asli\n%%EOF\n";
        $changedBytes = "%PDF-1.4\n% artifact berubah setelah adopsi\n%%EOF\n";
        $path = 'leave-proofs/'.$leaveRequest->id.'/00000000-0000-4000-8000-000000000f05.pdf';

        $this->assertTrue(Storage::disk('local')->put($path, $originalBytes));
        $fixture['proof']->update([
            'document_path' => $path,
            'document_mime' => 'application/pdf',
        ]);
        $task = $this->adoptStoredProofArtifact($leaveRequest, $path, $originalBytes);
        $this->assertTrue(Storage::disk('local')->put($path, $changedBytes));

        $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($leaveRequest))
            ->assertNotFound()
            ->assertDontSee('artifact berubah setelah adopsi');
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function invalidStoredOfficialFormCases(): array
    {
        return [
            'file tidak tersedia' => ['missing'],
            'MIME bukan PDF' => ['wrong_mime'],
            'folder milik pengajuan lain' => ['cross_request'],
        ];
    }

    #[DataProvider('invalidStoredOfficialFormCases')]
    public function test_official_form_fails_closed_when_stored_artifact_is_invalid(string $case): void
    {
        Storage::fake('local');
        $fixture = $this->makeOfficialFormFixture();
        $leaveRequest = $fixture['leave_request'];
        $canonicalPath = 'leave-proofs/'.$leaveRequest->id.'/00000000-0000-4000-8000-000000000f02.pdf';
        $path = $case === 'cross_request'
            ? 'leave-proofs/00000000-0000-4000-8000-000000000f03/00000000-0000-4000-8000-000000000f04.pdf'
            : $canonicalPath;

        if ($case !== 'missing') {
            $this->assertTrue(Storage::disk('local')->put($path, "%PDF-1.4\n% artifact tidak sah\n%%EOF\n"));
        }

        $fixture['proof']->update([
            'document_path' => $path,
            'document_mime' => $case === 'wrong_mime' ? 'image/png' : 'application/pdf',
        ]);

        $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($leaveRequest))
            ->assertNotFound()
            ->assertDontSee('artifact tidak sah');
    }

    public function test_legacy_final_proof_without_document_path_still_renders_dynamic_official_pdf(): void
    {
        Storage::fake('local');
        $fixture = $this->makeOfficialFormFixture();
        $fixture['proof']->update([
            'document_path' => null,
            'document_mime' => null,
        ]);

        $response = $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($fixture['leave_request']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $this->responseBytes($response));
    }

    public function test_unrelated_user_is_forbidden_even_when_knowing_proof_token(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $unrelated = $this->makeUnrelatedUser();

        $this->actingAs($unrelated)
            ->get($this->formUrl($fixture['leave_request']).'?token='.$fixture['proof']->token)
            ->assertForbidden();
    }

    public function test_detail_pdf_control_matches_centralized_authorization_and_endpoint_contract(): void
    {
        $action = app(DownloadOfficialLeavePdfAction::class);
        $fixture = $this->makeOfficialFormFixture();
        $leaveRequest = $fixture['leave_request'];
        $readAllViewer = User::factory()->adminKepegawaian()->create();
        $unrelated = $this->makeUnrelatedUser();
        $nonFinalFixture = $this->makeOfficialFormFixture(true, '198601012026041002', str_repeat('b', 64));
        $nonFinalFixture['leave_request']->update(['status' => 'menunggu_approval']);
        $prooflessFixture = $this->makeOfficialFormFixture(false, '198601012026041003');

        foreach ([
            'requester' => [$leaveRequest, $fixture['requester_user'], true, 200],
            'historical snapshot approver' => [$leaveRequest, $fixture['approver_users'][0], true, 200],
            'final snapshot approver' => [$leaveRequest, $fixture['approver_users'][1], true, 200],
            'cuti.read_all viewer' => [$leaveRequest, $readAllViewer, true, 200],
            'non-final requester' => [$nonFinalFixture['leave_request'], $nonFinalFixture['requester_user'], false, 404],
            'proofless requester' => [$prooflessFixture['leave_request'], $prooflessFixture['requester_user'], false, 404],
        ] as $scenario => [$request, $user, $expectedCanDownload, $expectedEndpointStatus]) {
            $this->assertSame($expectedCanDownload, $action->canDownload($request->fresh(), $user), $scenario);

            $detail = $this->actingAs($user)->get(route('cuti.show', $request->id));

            $detail->assertOk();
            if ($expectedCanDownload) {
                $detail->assertSee('Unduh Formulir Cuti (PDF)', false);
                $detail->assertSee('aria-label="Unduh Formulir Cuti (PDF)"', false);
                $detail->assertSee($this->formUrl($request), false);
            } else {
                $detail->assertDontSee('Unduh Formulir Cuti (PDF)', false);
                $detail->assertDontSee('aria-label="Unduh Formulir Cuti (PDF)"', false);
                $detail->assertDontSee($this->formUrl($request), false);
            }

            $this->actingAs($user)
                ->get($this->formUrl($request))
                ->assertStatus($expectedEndpointStatus);
        }

        $this->assertFalse($action->canDownload($leaveRequest->fresh(), $unrelated));
        $this->actingAs($unrelated)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertForbidden()
            ->assertDontSee('Unduh Formulir Cuti (PDF)');
        $this->actingAs($unrelated)
            ->get($this->formUrl($leaveRequest))
            ->assertForbidden();
    }

    public function test_non_final_request_is_not_found_before_eligibility_is_checked(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->update(['status' => 'menunggu_approval']);

        $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($fixture['leave_request']))
            ->assertNotFound();
    }

    public function test_unrelated_user_gets_not_found_for_non_final_request_with_proof_before_eligibility_is_checked(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->update(['status' => 'menunggu_approval']);

        $this->actingAs($this->makeUnrelatedUser())
            ->get($this->formUrl($fixture['leave_request']))
            ->assertNotFound();
    }

    public function test_final_request_without_proof_is_not_found_before_eligibility_is_checked(): void
    {
        $fixture = $this->makeOfficialFormFixture(false);

        $this->actingAs($fixture['requester_user'])
            ->get($this->formUrl($fixture['leave_request']))
            ->assertNotFound();
    }

    public function test_unrelated_user_gets_not_found_for_final_request_without_proof_before_eligibility_is_checked(): void
    {
        $fixture = $this->makeOfficialFormFixture(false);

        $this->actingAs($this->makeUnrelatedUser())
            ->get($this->formUrl($fixture['leave_request']))
            ->assertNotFound();
    }

    public function test_guest_follows_existing_login_behavior(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        $this->get($this->formUrl($fixture['leave_request']))
            ->assertRedirect(route('login'));
    }

    public function test_proof_token_path_does_not_serve_official_form(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        $this->get('/cuti/verifikasi/'.$fixture['proof']->token.'/formulir-pdf')
            ->assertNotFound();
    }

    public function test_final_official_form_uses_bounded_deterministic_scalar_view_data(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame([
            'institution', 'issuePlace', 'issueDateLabel', 'issueDateTimeLabel', 'employeeName', 'employeeNip',
            'employeePosition', 'employeeServiceLength', 'employeeUnit', 'leaveTypeCode', 'leaveTypeName', 'reason',
            'startDateLabel', 'endDateLabel', 'workdayCount', 'addressDuringLeave', 'phoneDuringLeave', 'balanceN2',
            'balanceN1', 'balanceN', 'steps', 'finalApproverName', 'finalApproverRole', 'finalDecisionLabel',
            'finalActedAtLabel', 'verificationUrl', 'qrDataUri',
        ], array_keys($data));
        $this->assertArrayNotHasKey('leaveRequest', $data);
        $this->assertSame('LLDIKTI Wilayah XVI', $data['institution']);
        $this->assertSame('Gorontalo', $data['issuePlace']);
        $this->assertSame('15 Juli 2026', $data['issueDateLabel']);
        $this->assertSame('15 Juli 2026 16:30 WITA', $data['issueDateTimeLabel']);
        $this->assertSame('Pemohon Formulir', $data['employeeName']);
        $this->assertSame('198601012026041001', $data['employeeNip']);
        $this->assertSame('Analis Kepegawaian', $data['employeePosition']);
        $this->assertSame('6 tahun 6 bulan', $data['employeeServiceLength']);
        $this->assertSame('Bagian Kepegawaian', $data['employeeUnit']);
        $this->assertSame('tahunan', $data['leaveTypeCode']);
        $this->assertSame('Cuti Tahunan', $data['leaveTypeName']);
        $this->assertSame('Keperluan keluarga.', $data['reason']);
        $this->assertSame('10 Agustus 2026', $data['startDateLabel']);
        $this->assertSame('12 Agustus 2026', $data['endDateLabel']);
        $this->assertSame(3, $data['workdayCount']);
        $this->assertSame('Jalan Aman 16', $data['addressDuringLeave']);
        $this->assertSame('081234567890', $data['phoneDuringLeave']);
        $this->assertSame(2, $data['balanceN2']);
        $this->assertSame(4, $data['balanceN1']);
        $this->assertSame(6, $data['balanceN']);
        $this->assertSame([
            [
                'order' => 1,
                'role' => 'Kepala Bagian',
                'approver' => 'Penyetuju Historis',
                'statusLabel' => 'Dilewati',
                'note' => '<catatan>&',
                'actedAtLabel' => '14 Juli 2026 16:30 WITA',
            ],
            [
                'order' => 2,
                'role' => 'PYBMC',
                'approver' => 'Penyetuju Final',
                'statusLabel' => 'Disetujui',
                'note' => 'Final disetujui',
                'actedAtLabel' => '15 Juli 2026 15:30 WITA',
            ],
        ], $data['steps']);
        $this->assertSame('Penyetuju Final', $data['finalApproverName']);
        $this->assertSame('PYBMC', $data['finalApproverRole']);
        $this->assertSame('Disetujui', $data['finalDecisionLabel']);
        $this->assertSame('15 Juli 2026 15:30 WITA', $data['finalActedAtLabel']);
        $this->assertSame(route('cuti.verify', $fixture['proof']->token), $data['verificationUrl']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data['qrDataUri']);
        $this->assertStringContainsString('&lt;catatan&gt;&amp;', $this->renderFormHtml($data));

        Carbon::setTestNow('2030-01-01 00:00:00 UTC');
        try {
            $this->session(['last_activity_at' => now()->timestamp]);
            $afterClockTravel = $this->viewDataFor($fixture['leave_request']);

            $this->assertSame($data['issueDateLabel'], $afterClockTravel['issueDateLabel']);
            $this->assertSame($data['issueDateTimeLabel'], $afterClockTravel['issueDateTimeLabel']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_legacy_proof_without_issuance_timestamp_keeps_authorized_form_available_with_fallbacks(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['proof']->update(['generated_at' => null]);

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame('-', $data['issueDateLabel']);
        $this->assertSame('-', $data['issueDateTimeLabel']);
        $this->assertSame('-', $data['employeeServiceLength']);
        $this->assertSame(route('cuti.verify', $fixture['proof']->token), $data['verificationUrl']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data['qrDataUri']);
    }

    public function test_official_service_length_uses_earliest_non_null_appointment_date(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->employee->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2018-01-01',
            'no_sk' => 'SK-PNS-2018-001',
            'tanggal_sk' => '2017-12-15',
        ]);
        $fixture['leave_request']->employee->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2022-01-01',
            'no_sk' => 'SK-PNS-2022-001',
            'tanggal_sk' => '2021-12-15',
        ]);

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame('8 tahun 6 bulan', $data['employeeServiceLength']);
    }

    public function test_official_form_limits_appointment_eager_load_to_earliest_row(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->employee->appointments()->createMany([
            [
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2018-01-01',
                'no_sk' => 'SK-PNS-2018-001',
                'tanggal_sk' => '2017-12-15',
            ],
            [
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2022-01-01',
                'no_sk' => 'SK-PNS-2022-001',
                'tanggal_sk' => '2021-12-15',
            ],
        ]);
        $appointmentQueries = [];
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        // Dispatcher sementara menghindari listener pengukuran memengaruhi test PDF berikutnya.
        $connection->setEventDispatcher(new Dispatcher($this->app));
        DB::listen(function (QueryExecuted $query) use (&$appointmentQueries): void {
            if (str_contains(strtolower($query->sql), 'from "appointments"')) {
                $appointmentQueries[] = strtolower($query->sql);
            }
        });

        try {
            $response = $this->actingAs($fixture['requester_user'])
                ->get($this->formUrl($fixture['leave_request']));
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $response->assertOk();
        $this->assertCount(1, $appointmentQueries);
        $this->assertStringContainsString('"laravel_row" <= 1', $appointmentQueries[0]);
    }

    public function test_official_form_uses_employee_name_with_academic_title_when_available(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->employee->update([
            'nama_lengkap' => 'Nama Tanpa Gelar',
            'nama_dengan_gelar' => 'Dr. Nama Dengan Gelar, M.Si.',
        ]);

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame('Dr. Nama Dengan Gelar, M.Si.', $data['employeeName']);
    }

    public function test_official_form_falls_back_to_full_employee_name_when_academic_title_is_null_or_blank(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        foreach ([null, '   '] as $namaDenganGelar) {
            $fixture['leave_request']->employee->update([
                'nama_lengkap' => 'Nama Lengkap Cadangan',
                'nama_dengan_gelar' => $namaDenganGelar,
            ]);

            $data = $this->viewDataFor($fixture['leave_request']);

            $this->assertSame('Nama Lengkap Cadangan', $data['employeeName']);
        }
    }

    public function test_official_form_falls_back_to_dash_when_employee_names_are_blank(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->employee->update([
            'nama_lengkap' => '   ',
            'nama_dengan_gelar' => '   ',
        ]);

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame('-', $data['employeeName']);
    }

    public function test_official_form_uses_one_deterministic_latest_position_and_bounded_eager_query(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $employee = $fixture['leave_request']->employee;
        $employee->positionHistories()->where('is_latest', true)->update([
            'nama_jabatan' => 'Jabatan Lama',
            'tmt_jabatan' => '2023-01-01',
        ]);
        $employee->positionHistories()->create([
            'nama_jabatan' => 'Jabatan Resmi Terbaru',
            'jenis_jabatan_id' => RefJenisJabatan::query()->firstOrFail()->id,
            'unit_kerja_id' => RefUnitKerja::query()->where('nama', 'Bagian Kepegawaian')->firstOrFail()->id,
            'tmt_jabatan' => '2025-01-01',
            'no_sk' => 'SK-2025-001',
            'tanggal_sk' => '2024-12-15',
            'is_latest' => true,
        ]);
        $positionQueries = [];
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        // Dua data legacy bertanda terkini wajib tetap menghasilkan satu jabatan resmi yang deterministik.
        $connection->setEventDispatcher(new Dispatcher($this->app));
        DB::listen(function (QueryExecuted $query) use (&$positionQueries): void {
            if (str_contains(strtolower($query->sql), 'from "position_histories"')) {
                $positionQueries[] = strtolower($query->sql);
            }
        });

        try {
            $data = $this->viewDataFor($fixture['leave_request']);
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertSame('Jabatan Resmi Terbaru', $data['employeePosition']);
        $this->assertSame('Bagian Kepegawaian', $data['employeeUnit']);
        $this->assertCount(1, $positionQueries);
        $this->assertStringContainsString('order by "tmt_jabatan" desc, "id" desc', $positionQueries[0]);
        $this->assertStringContainsString('"laravel_row" <= 1', $positionQueries[0]);
    }

    public function test_official_form_uses_configured_canonical_host_for_verification_url_and_qr(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        config(['app.url' => 'https://canonical.simpeg.test/']);

        $data = $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->viewDataFor($fixture['leave_request']);

        $verificationUrl = 'https://canonical.simpeg.test/cuti/verifikasi/'.$fixture['proof']->token;
        $svg = base64_decode(substr($data['qrDataUri'], strlen('data:image/svg+xml;base64,')), true);

        $this->assertSame($verificationUrl, $data['verificationUrl']);
        $this->assertStringNotContainsString('attacker.example', $data['verificationUrl']);
        $this->assertIsString($svg);
        $this->assertSame(app(LeaveProofService::class)->qrSvgForUrl($verificationUrl), $svg);
        $this->assertStringNotContainsString('attacker.example', $svg);
    }

    public function test_nonannual_leave_hides_all_balance_buckets(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $nonAnnual = RefJenisCuti::firstOrCreate([
            'code' => 'sakit',
        ], [
            'nama' => 'Cuti Sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $fixture['leave_request']->forceFill(['jenis_cuti_id' => $nonAnnual->id])->save();
        $fixture['leave_request']->unsetRelation('jenisCuti');

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame('-', $data['balanceN2']);
        $this->assertSame('-', $data['balanceN1']);
        $this->assertSame('-', $data['balanceN']);
    }

    public function test_annual_leave_without_request_year_balance_hides_all_balance_buckets(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->update([
            'tanggal_mulai' => '2027-08-10',
            'tanggal_selesai' => '2027-08-12',
        ]);

        $data = $this->viewDataFor($fixture['leave_request']);

        $this->assertSame('-', $data['balanceN2']);
        $this->assertSame('-', $data['balanceN1']);
        $this->assertSame('-', $data['balanceN']);
    }

    /** @return array{leave_request: LeaveRequest, requester_user: User, approver_users: list<User>, proof: LeaveProof|null} */
    private function makeOfficialFormFixture(
        bool $withProof = true,
        string $requesterNip = '198601012026041001',
        string $proofToken = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): array {
        $requester = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Formulir',
            'nip' => $requesterNip,
            'jabatan_terakhir' => 'Analis Kepegawaian',
        ]);
        $jenisJabatan = RefJenisJabatan::firstOrCreate([
            'nama' => 'Fungsional',
        ], [
            'maks_usia_pensiun' => 60,
        ]);
        $unitKerja = RefUnitKerja::firstOrCreate(['nama' => 'Bagian Kepegawaian']);
        $requester->positionHistories()->create([
            'nama_jabatan' => 'Analis Kepegawaian',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2024-01-01',
            'no_sk' => 'SK-2024-001',
            'tanggal_sk' => '2023-12-15',
            'is_latest' => true,
        ]);
        $requester->appointments()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-PNS-2020-001',
            'tanggal_sk' => '2019-12-15',
        ]);
        LeaveBalance::create([
            'employee_id' => $requester->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'terpakai' => 2,
            'sisa' => 10,
            'sisa_n2' => 2,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 6,
        ]);

        $jenisCuti = RefJenisCuti::firstOrCreate([
            'code' => 'tahunan',
        ], [
            'nama' => 'Cuti Tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $requester->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'alamat_selama_cuti' => 'Jalan Aman 16',
            'nomor_telepon' => '081234567890',
            'status' => 'disetujui',
        ]);

        $historicalApprover = Employee::factory()->create(['nama_lengkap' => 'Penyetuju Historis']);
        $finalApprover = Employee::factory()->create(['nama_lengkap' => 'Penyetuju Final']);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $historicalApprover->id,
            'status' => 'skipped',
            'is_final' => false,
            'decision_note' => '<catatan>&',
            'acted_at' => '2026-07-14 16:30:00',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $finalApprover->id,
            'status' => 'approved',
            'is_final' => true,
            'decision_note' => 'Final disetujui',
            'acted_at' => '2026-07-15 15:30:00',
        ]);

        $proof = $withProof ? LeaveProof::create([
            'leave_request_id' => $leaveRequest->id,
            'token' => $proofToken,
            'generated_at' => '2026-07-15 16:30:00',
        ]) : null;

        return [
            'leave_request' => $leaveRequest,
            'requester_user' => User::factory()->pegawai()->create(['employee_id' => $requester->id]),
            'approver_users' => [
                User::factory()->pegawai()->create(['employee_id' => $historicalApprover->id]),
                User::factory()->pegawai()->create(['employee_id' => $finalApprover->id]),
            ],
            'proof' => $proof,
        ];
    }

    private function formUrl(LeaveRequest $leaveRequest): string
    {
        return route('cuti.formulir-pdf', $leaveRequest);
    }

    /** Membentuk manifest realistis tanpa memakai koneksi intent independen di dalam transaksi test. */
    private function adoptStoredProofArtifact(
        LeaveRequest $leaveRequest,
        string $path,
        string $bytes,
    ): StorageRecoveryTask {
        $sha256 = hash('sha256', $bytes);
        $task = StorageRecoveryTask::query()->create([
            'idempotency_key' => hash('sha256', 'fixture|'.$leaveRequest->id.'|'.$path.'|'.$sha256),
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_PROOF,
            'disk' => 'local',
            'path' => $path,
            'owner_id' => $leaveRequest->id,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ]);

        // Adoption produksi membuktikan metadata proof sudah mereferensikan byte yang hash-nya dipin.
        app(StorageRecoveryService::class)->markLeaveProofCreationTargetAdopted(
            $task->id,
            $leaveRequest->id,
            $path,
        );
        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $task->fresh()->status);

        return $task;
    }

    private function responseBytes(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        $content = $response->getContent();
        $this->assertIsString($content);

        return $content;
    }

    private function makeUnrelatedUser(): User
    {
        return User::factory()->pegawai()->create([
            'employee_id' => Employee::factory()->create()->id,
        ]);
    }

    public function test_official_template_has_required_sections_selected_leave_type_and_final_verification_block(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $data = $this->viewDataFor($fixture['leave_request']);
        $html = $this->renderFormHtml($data);

        foreach ([
            'Kepada :',
            'Yth. Kepala Lembaga Layanan Pendidikan Tinggi Wilayah XVI',
            'di-',
            'Tempat',
            'I. DATA PEGAWAI',
            'II. JENIS CUTI YANG DIAMBIL',
            'III. ALASAN CUTI',
            'IV. LAMANYA CUTI',
            'V. CATATAN CUTI',
            'VI. ALAMAT SELAMA MENJALANKAN CUTI',
            'VII. PERTIMBANGAN ATASAN LANGSUNG DAN KEPUTUSAN PEJABAT BERWENANG',
            'N-2',
            'N-1',
            'N',
            'Penyetuju Final',
            'PYBMC',
            'Disetujui',
            '15 Juli 2026 15:30 WITA',
            'QR memverifikasi alur persetujuan elektronik lengkap dan data dokumen',
            'bukan tanda tangan elektronik tersertifikasi',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertStringContainsString('&#9745; Cuti Tahunan', $html);
        foreach ([
            'Cuti Besar',
            'Cuti Sakit',
            'Cuti Melahirkan',
            'Cuti Karena Alasan Penting',
            'Cuti di Luar Tanggungan Negara',
        ] as $unselectedType) {
            $this->assertStringContainsString('&#9744; '.$unselectedType, $html);
            $this->assertStringNotContainsString('&#9745; '.$unselectedType, $html);
        }

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="'.$data['qrDataUri'].'"', $html);
        $this->assertStringContainsString($data['verificationUrl'], $html);
        $this->assertStringNotContainsString('Ditolak', $html);
        $this->assertStringNotContainsString('bukan pengganti tanda tangan basah', $html);
        $this->assertSame(1, substr_count($html, '&#9745;'));
    }

    public function test_official_template_has_required_qr_dimensions_and_leave_balance_notes(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $html = $this->renderFormHtml($this->viewDataFor($fixture['leave_request']));
        $template = file_get_contents(resource_path('views/admin/cuti/pdf/formulir-cuti.blade.php'));

        $this->assertIsString($template);
        $this->assertStringContainsString('.qr-cell img { height: 25mm; width: 25mm; }', $template);
        $this->assertStringContainsString('.qr-cell img { height: 25mm; width: 25mm; }', $html);

        foreach ([
            'N = Cuti tahun berjalan',
            'N-1 = Sisa cuti 1 tahun sebelumnya',
            'N-2 = Sisa cuti 2 tahun sebelumnya',
        ] as $note) {
            $this->assertStringContainsString("{{ '".$note."' }}", $template);
            $this->assertStringContainsString($note, $html);
        }
    }

    public function test_official_template_selects_exactly_one_checkbox_for_each_stable_official_leave_code(): void
    {
        $fixture = $this->makeOfficialFormFixture();

        foreach ([
            'tahunan' => 'Cuti Tahunan',
            'besar' => 'Cuti Besar',
            'sakit' => 'Cuti Sakit',
            'melahirkan' => 'Cuti Melahirkan',
            'alasan_penting' => 'Cuti Karena Alasan Penting',
            'cltn' => 'Cuti di Luar Tanggungan Negara',
        ] as $leaveTypeCode => $selectedLabel) {
            $fixture['leave_request']->jenisCuti()->update([
                'code' => $leaveTypeCode,
                'nama' => $leaveTypeCode === 'cltn' ? 'Cuti Luar Tanggungan Negara (CLTN)' : 'Nama yang dapat berubah',
                'mengurangi_saldo_tahunan' => $leaveTypeCode === 'tahunan',
            ]);

            $html = $this->renderFormHtml($this->viewDataFor($fixture['leave_request']));

            $this->assertSame(1, substr_count($html, '&#9745;'), $leaveTypeCode);
            $this->assertStringContainsString('&#9745; '.$selectedLabel, $html, $leaveTypeCode);
        }
    }

    public function test_official_template_escapes_hostile_values_and_does_not_request_remote_assets(): void
    {
        $fixture = $this->makeOfficialFormFixture();
        $fixture['leave_request']->employee->update([
            'nama_lengkap' => '<script>alert("nama")</script><img src="https://attacker.test/name.png">',
        ]);
        $fixture['leave_request']->update([
            'alasan' => '<script>alert("alasan")</script><img src="https://attacker.test/reason.png">',
            'alamat_selama_cuti' => '<script>alert("alamat")</script><img src="https://attacker.test/address.png">',
        ]);
        $fixture['leave_request']->steps()->where('step_order', 1)->update([
            'decision_note' => '<script>alert("catatan")</script><img src="https://attacker.test/note.png">',
        ]);

        $html = $this->renderFormHtml($this->viewDataFor($fixture['leave_request']));

        foreach ([
            '&lt;script&gt;alert(&quot;nama&quot;)&lt;/script&gt;&lt;img src=&quot;https://attacker.test/name.png&quot;&gt;',
            '&lt;script&gt;alert(&quot;alasan&quot;)&lt;/script&gt;&lt;img src=&quot;https://attacker.test/reason.png&quot;&gt;',
            '&lt;script&gt;alert(&quot;alamat&quot;)&lt;/script&gt;&lt;img src=&quot;https://attacker.test/address.png&quot;&gt;',
            '&lt;script&gt;alert(&quot;catatan&quot;)&lt;/script&gt;&lt;img src=&quot;https://attacker.test/note.png&quot;&gt;',
        ] as $escapedValue) {
            $this->assertStringContainsString($escapedValue, $html);
        }

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src="https://attacker.test', $html);
        $this->assertStringNotContainsString('javascript:', strtolower($html));
        $this->assertMatchesRegularExpression('/<img[^>]+src="data:image\/svg\+xml;base64,[^"]+"/', $html);
        $this->assertFalse(config('dompdf.options.enable_remote'));
        $this->assertFalse(config('dompdf.options.enable_php'));
        $this->assertTrue(config('dompdf.options.enable_html5_parser'));
        $this->assertSame(realpath(base_path()), config('dompdf.options.chroot'));
    }

    /** @return array<string, mixed> */
    private function viewDataFor(LeaveRequest $leaveRequest): array
    {
        return app(DownloadOfficialLeavePdfAction::class)->viewData($leaveRequest);
    }

    /** @param array<string, mixed> $data */
    private function renderFormHtml(array $data): string
    {
        return view('admin.cuti.pdf.formulir-cuti', $data)->render();
    }
}
