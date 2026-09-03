<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Queries\Cuti\CurrentApprovalChainPreviewQuery;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveUsageAdminPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-19 10:00:00');
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_workspace_merender_form_fakta_dan_manual_tanpa_input_saldo_legacy_atau_hari_client(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Form Fakta']);

        $queue = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
        ]));

        $queue->assertOk();
        $queue->assertSee('Catat cuti eksternal', false);
        $queue->assertSee(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'pegawai' => $employee->id,
            'tab' => 'manual',
        ]));
        $queueDocument = new DOMDocument;
        @$queueDocument->loadHTML($queue->getContent());
        $manualAction = (new DOMXPath($queueDocument))->query(sprintf(
            '//a[@aria-label="Catat cuti eksternal" and @href="%s"]',
            route('cuti.saldo.administrasi', [
                'status' => 'semua_pegawai',
                'pegawai' => $employee->id,
                'tab' => 'manual',
            ]),
        ))->item(0);

        $this->assertInstanceOf(DOMElement::class, $manualAction);
        $this->assertSame(route('cuti.saldo.administrasi', [
            'status' => 'semua_pegawai',
            'pegawai' => $employee->id,
            'tab' => 'manual',
        ]), $manualAction->getAttribute('href'));

        $initial = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
        ]));

        $initial->assertOk();
        $initial->assertSee('Administrasi Pemakaian Cuti', false);
        $initial->assertSee('manual-external-approval-', false);
        $initial->assertSee('Catat Pemakaian Tahunan', false);
        $initial->assertSee('Keterangan atau sumber data', false);
        $initial->assertSee('Simpan Pemakaian Tahunan', false);
        $initial->assertSee('Cuti di Luar SIMPEG', false);
        $initial->assertSee('Perbaiki Data Pemakaian', false);
        $initial->assertDontSee('Rekonsiliasi Pemakaian Tahunan', false);
        $initial->assertDontSee('Keterangan rekonsiliasi', false);
        $initial->assertDontSee('Simpan Rekonsiliasi', false);
        $initial->assertSee('id="tab-manual"', false);
        $initial->assertSee('aria-controls="panel-manual"', false);
        $initial->assertSee(route('cuti.reconciliation.store', $employee), false);
        $manualUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
        ]);
        $initial->assertDontSee('Muat Editor Cuti Eksternal', false);
        $initial->assertDontSee(route('cuti.manual.store', $employee), false);
        foreach (['usage_n2', 'usage_n1', 'usage_current'] as $field) {
            $initial->assertSee('name="'.$field.'"', false);
        }
        $initial->assertDontSee('Pendaftaran saldo awal', false);
        $initial->assertDontSee('Daftarkan saldo awal', false);
        $initial->assertDontSee('max="24"', false);
        $initial->assertDontSee('name="workdays"', false);
        $initial->assertDontSee('name="jumlah_hari_kerja"', false);

        $manual = $this->actingAs($admin)->get($manualUrl);

        $manual->assertOk();
        $manual->assertSee("activeTab: 'manual'", false);
        $manual->assertSee('id="panel-manual"', false);
        $manual->assertSee('Catat Cuti di Luar SIMPEG', false);
        $manual->assertSee('Simpan Cuti Eksternal', false);
        $manual->assertSee('Kelompok Pengajuan Cuti (opsional)', false);
        $manual->assertSee('Buat baru atau pilih kelompok pengajuan cuti', false);
        $manual->assertSee('Hanya kelompok pengajuan cuti aktif milik pegawai ini yang dapat dipilih.', false);
        $manual->assertSee('Contoh: nomor SK atau surat keputusan. Boleh dikosongkan bila tidak tersedia.', false);
        $manual->assertSee('Pratinjau Rangkaian Saat Ini', false);
        $manual->assertSee('Editor masih kosong. Tambahkan tahap satu per satu atau buka pratinjau, lalu salin rangkaian aktif pegawai.', false);
        $manual->assertDontSee('Preview Chain Saat Ini', false);
        $manual->assertSee(route('cuti.manual.store', $employee), false);
        $manual->assertSee('enctype="multipart/form-data"', false);
        foreach (['leave_type_id', 'leave_request_case_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan', 'dokumen'] as $field) {
            $manual->assertSee('name="'.$field.'"', false);
        }
        $manual->assertDontSee('name="workdays"', false);
        $manual->assertDontSee('name="jumlah_hari_kerja"', false);
        $manual->assertDontSee('Administrasi Saldo Cuti', false);

        $set = $this->reconciliation($employee, $admin, [2024 => 0, 2025 => 0, 2026 => 0]);
        $history = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'riwayat',
        ]));

        $history->assertOk();
        $history->assertSee('Perbaiki Data Pemakaian', false);
        $history->assertSee('Riwayat perubahan dan bukti', false);
        $history->assertSee('Perbaikan mengganti data pemakaian tiga tahun dan memicu hitung ulang.', false);
        $history->assertSee(route('cuti.reconciliation.correct', $set), false);
        foreach (['usage_n2', 'usage_n1', 'usage_current', 'correction_reason', 'dokumen'] as $field) {
            $history->assertSee('name="'.$field.'"', false);
        }
        foreach (['saldo', 'delta', 'koreksi_administratif'] as $field) {
            $history->assertDontSee('name="'.$field.'"', false);
        }
        $history->assertDontSee('Koreksi Saldo', false);
        $history->assertDontSee('Koreksi Rekonsiliasi', false);
        $history->assertSee('value="0"', false);
    }

    public function test_workspace_tahun_baru_menawarkan_pencatatan_tahun_berjalan_dan_koreksi_set_historis_aktif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Koreksi Historis']);
        $set = $this->reconciliation($employee, $admin, [2024 => 1, 2025 => 2, 2026 => 3]);
        Carbon::setTestNow('2027-02-03 10:00:00');

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'riwayat',
        ]));

        $response->assertOk();
        $response->assertSee(route('cuti.reconciliation.store', $employee), false);
        $response->assertSee(route('cuti.reconciliation.correct', $set), false);
        $response->assertSee('name="balance_year" value="2027"', false);
        $response->assertSee('name="balance_year" value="2026"', false);
        $response->assertSee('Fakta pemakaian tahun 2027', false);
        $response->assertSee('Fakta pemakaian tahun 2024', false);
        $response->assertSee('Fakta pemakaian tahun 2025', false);
        $response->assertSee('Fakta pemakaian tahun 2026', false);

        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $historyTab = (new DOMXPath($document))->query('//*[@id="tab-riwayat"]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $historyTab);
        $this->assertSame('false', $historyTab->getAttribute('aria-disabled'));
        $this->assertSame("selectTab('riwayat')", $historyTab->getAttribute('x-on:click'));
        $this->assertSame('false', $historyTab->getAttribute('x-on:click.prevent'));
        $this->assertStringNotContainsString('cursor-not-allowed', $historyTab->getAttribute('class'));
        $response->assertDontSee('id="riwayat-locked-message"', false);
    }

    public function test_antrean_administrasi_memakai_per_page_tervalidasi_dan_mempertahankan_filter(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        foreach (range(1, 50) as $index) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Pegawai Pagination Saldo %02d', $index),
            ]);
        }

        foreach ([10, 25, 50] as $perPage) {
            $parameters = [
                'status' => 'semua_pegawai',
                'search' => 'Pegawai Pagination Saldo',
                'per_page' => $perPage,
            ];

            $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', $parameters));

            $response->assertOk();
            $rows = $response->viewData('employeeRows');
            $this->assertSame(50, $rows->total());
            $this->assertSame($perPage, $rows->perPage());
            $this->assertSame(min($perPage, 50), $rows->count());
            $this->assertSame(
                array_map('strval', array_merge($parameters, ['page_pegawai' => 2])),
                $this->queryParameters($rows->url(2)),
            );
        }
    }

    public function test_tautan_kembali_workspace_mempertahankan_per_page_antrian(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Kembali Antrean']);
        $parameters = [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'search' => 'Kembali Antrean',
            'page_pegawai' => 2,
            'per_page' => 25,
        ];

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', $parameters));

        $response
            ->assertOk()
            ->assertSee(e(route('cuti.saldo.administrasi', [
                'status' => 'semua_pegawai',
                'search' => 'Kembali Antrean',
                'page_pegawai' => 2,
                'per_page' => 25,
            ])), false)
            ->assertSee(e(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'search' => 'Kembali Antrean',
                'tab' => 'manual',
                'page_pegawai' => 2,
                'per_page' => 25,
            ])), false);
    }

    public function test_tab_cuti_di_luar_simpeg_memuat_editor_tanpa_tombol_perantara_dan_fragment_scroll(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $initialUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
        ]);
        $manualUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
        ]);

        $initial = $this->actingAs($admin)->get($initialUrl)->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($initial->getContent());
        $manualTab = (new DOMXPath($document))->query('//*[@id="tab-manual"]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $manualTab);
        $this->assertSame('a', $manualTab->tagName);
        $this->assertSame($manualUrl, $manualTab->getAttribute('href'));
        $this->assertStringNotContainsString('#', $manualTab->getAttribute('href'));
        $this->assertSame(1, (new DOMXPath($document))->query('//main')->length);
        $tabList = (new DOMXPath($document))->query('//*[@role="tablist" and @aria-label="Kategori administrasi pemakaian cuti"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $tabList);
        $this->assertSame('vertical', $tabList->getAttribute('aria-orientation'));
        $initial->assertDontSee('Muat Editor Cuti Eksternal', false);
        $initial->assertDontSee(route('cuti.manual.store', $employee), false);

        $this->actingAs($admin)
            ->get($manualUrl)
            ->assertOk()
            ->assertSee(route('cuti.manual.store', $employee), false)
            ->assertSee('Simpan Cuti Eksternal', false);
    }

    public function test_workspace_menyediakan_preview_chain_current_dengan_satu_chain_valid_tanpa_menulis_data(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Preview',
            'nip' => '198765432100000011',
            'jabatan_terakhir' => 'Kepala Bagian',
            'status_aktif' => 'Aktif',
        ]);
        $pybmc = Employee::factory()->create([
            'nama_lengkap' => 'PYBMC Preview',
            'nip' => '198765432100000012',
            'jabatan_terakhir' => 'Pejabat Berwenang',
            'status_aktif' => 'Aktif',
        ]);
        $chain = LeaveApprovalChain::query()->forceCreate([
            'id' => (string) str()->uuid(),
            'employee_id' => $employee->id,
            'name' => 'Chain preview pegawai.',
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
        ]);
        foreach ([
            [1, 'kepala_bagian', 'Kepala Bagian', $kepalaBagian->id, false],
            [2, 'pybmc', 'PYBMC', $pybmc->id, true],
        ] as [$order, $type, $label, $approverId, $isFinal]) {
            LeaveApprovalChainStep::query()->forceCreate([
                'id' => (string) str()->uuid(),
                'leave_approval_chain_id' => $chain->id,
                'step_order' => $order,
                'step_type' => $type,
                'role_label' => $label,
                'approver_role_key' => null,
                'approver_employee_id' => $approverId,
                'is_final' => $isFinal,
            ]);
        }

        $stateBeforePreview = $this->approvalPreviewState();

        $response = $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'tab' => 'manual',
            ]));

        $response->assertOk()
            ->assertViewHas('currentApprovalChainPreview', function (array $preview) use ($kepalaBagian, $pybmc): bool {
                return $preview['available'] === true
                    && $preview['valid'] === true
                    && $preview['warnings'] === []
                    && $preview['steps'] === [
                        [
                            'step_order' => 1,
                            'step_type' => 'kepala_bagian',
                            'role_label' => 'Atasan Langsung',
                            'is_final' => false,
                            'approver' => [
                                'id' => $kepalaBagian->id,
                                'nama_lengkap' => 'Kepala Bagian Preview',
                                'nip' => '198765432100000011',
                                'jabatan_terakhir' => 'Kepala Bagian',
                                'status_aktif' => 'Aktif',
                            ],
                        ],
                        [
                            'step_order' => 2,
                            'step_type' => 'pybmc',
                            'role_label' => 'PYBMC',
                            'is_final' => true,
                            'approver' => [
                                'id' => $pybmc->id,
                                'nama_lengkap' => 'PYBMC Preview',
                                'nip' => '198765432100000012',
                                'jabatan_terakhir' => 'Pejabat Berwenang',
                                'status_aktif' => 'Aktif',
                            ],
                        ],
                    ];
            });

        $this->assertSame($stateBeforePreview, $this->approvalPreviewState());
    }

    public function test_preview_menolak_tanpa_fallback_saat_tidak_ada_chain_aktif(): void
    {
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $this->previewChain($employee, [
            $this->previewStep(1, 'kepala_bagian', $kepalaBagian->id),
            $this->previewStep(2, 'pybmc', $pybmc->id, isFinal: true),
        ], isActive: false);

        $this->assertUnavailablePreview($employee, 'Chain persetujuan aktif belum tersedia.');
    }

    public function test_preview_menolak_tanpa_fallback_saat_dua_chain_aktif_ambigu(): void
    {
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $firstChain = $this->previewChain($employee, [
            $this->previewStep(1, 'kepala_bagian', $kepalaBagian->id),
            $this->previewStep(2, 'pybmc', $pybmc->id, isFinal: true),
        ]);
        $secondChainId = null;

        $this->dropOneActiveApprovalChainIndex();

        try {
            $secondChain = $this->previewChain($employee, [
                $this->previewStep(1, 'kepala_bagian', $kepalaBagian->id),
                $this->previewStep(2, 'pybmc', $pybmc->id, isFinal: true),
            ]);
            $secondChainId = $secondChain->id;

            $this->assertUnavailablePreview($employee, 'Konfigurasi chain aktif ambigu dan tidak dapat dipreview.');
        } finally {
            if ($secondChainId !== null) {
                DB::table('leave_approval_chains')
                    ->where('id', $secondChainId)
                    ->update(['is_active' => false]);
            }

            $this->createOneActiveApprovalChainIndex();
        }

        $this->assertSame(1, LeaveApprovalChain::query()
            ->whereKey($firstChain->id)
            ->where('is_active', true)
            ->count());
    }

    public function test_preview_menandai_chain_lebih_dari_sepuluh_tahap_sebagai_tidak_valid(): void
    {
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $steps = [];

        foreach (range(1, 9) as $order) {
            $steps[] = $this->previewStep($order, 'verifier', $approver->id);
        }

        $steps[] = $this->previewStep(10, 'kepala_bagian', $approver->id);
        $steps[] = $this->previewStep(11, 'pybmc', $approver->id, isFinal: true);
        $this->previewChain($employee, $steps);

        $preview = $this->assertInvalidPreview($employee);

        $this->assertContains('Jumlah tahap chain harus berada antara 2 dan 10.', $preview['warnings']);
        $this->assertCount(10, $preview['steps']);
    }

    public function test_preview_menandai_approver_hilang_sebagai_tidak_valid(): void
    {
        $employee = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $this->previewChain($employee, [
            $this->previewStep(1, 'kepala_bagian', null),
            $this->previewStep(2, 'pybmc', $pybmc->id, isFinal: true),
        ]);

        $preview = $this->assertInvalidPreview($employee);

        $this->assertContains('Approver pada salah satu tahap chain tidak tersedia.', $preview['warnings']);
    }

    public function test_preview_menolak_approver_berstatus_nonaktif_yang_tetap_tersimpan(): void
    {
        $employee = Employee::factory()->create();
        $statusNonaktif = RefStatusPegawai::query()
            ->where('kode', 'NONAKTIF')
            ->where('kelompok', 'Nonaktif')
            ->firstOrFail();
        $inactiveApprover = Employee::factory()->create();
        $inactiveApprover->status_pegawai_id = $statusNonaktif->id;
        $inactiveApprover->save();
        $pybmc = Employee::factory()->create();
        $this->previewChain($employee, [
            $this->previewStep(1, 'kepala_bagian', $inactiveApprover->id),
            $this->previewStep(2, 'pybmc', $pybmc->id, isFinal: true),
        ]);

        $this->assertTrue(Employee::query()->whereKey($inactiveApprover->id)->exists());
        $this->assertSame('Nonaktif', $inactiveApprover->statusPegawai()->value('kelompok'));
        $preview = $this->assertInvalidPreview($employee);

        $this->assertContains('Approver pada salah satu tahap chain tidak aktif.', $preview['warnings']);
    }

    public function test_preview_menandai_urutan_tidak_kontigu_dan_verifier_setelah_kepala_bagian_sebagai_tidak_valid(): void
    {
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $this->previewChain($employee, [
            $this->previewStep(1, 'kepala_bagian', $approver->id),
            $this->previewStep(3, 'verifier', $approver->id),
            $this->previewStep(4, 'pybmc', $approver->id, isFinal: true),
        ]);

        $preview = $this->assertInvalidPreview($employee);

        $this->assertContains('Urutan tahap chain tidak kontigu.', $preview['warnings']);
        $this->assertContains('Verifier tidak boleh berada setelah Atasan Langsung.', $preview['warnings']);
    }

    public function test_preview_menandai_nol_atau_dua_approver_final_sebagai_tidak_valid(): void
    {
        $zeroFinalEmployee = Employee::factory()->create();
        $zeroFinalApprover = Employee::factory()->create();
        $this->previewChain($zeroFinalEmployee, [
            $this->previewStep(1, 'kepala_bagian', $zeroFinalApprover->id),
            $this->previewStep(2, 'pybmc', $zeroFinalApprover->id),
        ]);
        $zeroFinalPreview = $this->assertInvalidPreview($zeroFinalEmployee);

        $twoFinalEmployee = Employee::factory()->create();
        $twoFinalApprover = Employee::factory()->create();
        $this->previewChain($twoFinalEmployee, [
            $this->previewStep(1, 'verifier', $twoFinalApprover->id, isFinal: true),
            $this->previewStep(2, 'kepala_bagian', $twoFinalApprover->id),
            $this->previewStep(3, 'pybmc', $twoFinalApprover->id, isFinal: true),
        ]);
        $twoFinalPreview = $this->assertInvalidPreview($twoFinalEmployee);

        $this->assertContains('Chain harus memiliki tepat satu approver final.', $zeroFinalPreview['warnings']);
        $this->assertContains('Chain harus memiliki tepat satu approver final.', $twoFinalPreview['warnings']);
    }

    public function test_preview_menandai_final_non_pybmc_dan_sebelum_tahap_terakhir_sebagai_tidak_valid(): void
    {
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $this->previewChain($employee, [
            $this->previewStep(1, 'verifier', $approver->id, isFinal: true),
            $this->previewStep(2, 'kepala_bagian', $approver->id),
            $this->previewStep(3, 'pybmc', $approver->id),
        ]);

        $preview = $this->assertInvalidPreview($employee);

        $this->assertContains('Approver final harus berada pada tahap terakhir.', $preview['warnings']);
        $this->assertContains('Approver final harus bertipe PYBMC.', $preview['warnings']);
    }

    public function test_workspace_menyediakan_opsi_rangkaian_milik_pegawai_terpilih_dengan_uuid_hanya_sebagai_value(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $type = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $selectedCase = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'created_by' => $admin->id,
        ]);
        $otherCase = LeaveRequestCase::query()->create([
            'employee_id' => $otherEmployee->id,
            'jenis_cuti_id' => $type->id,
            'created_by' => $admin->id,
        ]);
        $selectedUsage = $this->usage($employee, $type, '00000000-0000-4000-8000-000000000801', [
            'leave_request_case_id' => $selectedCase->id,
        ]);
        $this->usage($otherEmployee, $type, '00000000-0000-4000-8000-000000000802', [
            'leave_request_case_id' => $otherCase->id,
        ]);

        $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'edit_usage' => $selectedUsage->id,
                'manual_action' => 'correct',
            ]))
            ->assertOk()
            ->assertViewHas('manualLeaveCaseOptions', function ($options) use ($selectedCase, $otherCase): bool {
                return $options->count() === 1
                    && $options->first()['id'] === $selectedCase->id
                    && str_contains($options->first()['label'], 'Cuti Melahirkan')
                    && str_contains($options->first()['label'], 'Aktif')
                    && ! $options->contains(fn (array $option): bool => $option['id'] === $otherCase->id);
            });
    }

    public function test_workspace_merender_ringkasan_ketersediaan_projection_sebagai_nilai_baca_saja(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Ringkasan Saldo',
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $type = $this->annualType();
        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 5],
            Carbon::parse('2026-08-19'),
            'Catatan pemakaian tahunan untuk ringkasan saldo.',
            $admin,
        );
        $projection = LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->sole();
        $this->assertSame([24, 19, 1, 6, 12, 5, 6], [
            $projection->jatah_awal + $projection->carry_over,
            $projection->sisa,
            $projection->sisa_n2,
            $projection->sisa_n1,
            $projection->sisa_tahun_berjalan,
            $projection->terpakai,
            $projection->hangus,
        ]);
        $activeRequest = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-02',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan aktif dengan alokasi saldo.',
            'status' => 'menunggu_approval',
        ]);
        $dutyRequest = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-10-05',
            'tanggal_selesai' => '2026-10-07',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Hak dilindungi karena tugas dinas.',
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);

        app(LeaveBalanceReservationService::class)->reserveForNewRequest($activeRequest, $admin);
        app(LeaveBalanceService::class)->recordDutyPostponement(
            $dutyRequest,
            $admin,
            'Cuti ditunda karena penugasan instansi.',
        );

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
        ]));

        $response->assertOk();
        $response->assertSee('Status data pemakaian', false);
        $response->assertSee('Catatan pemakaian tahunan aktif', false);
        $response->assertDontSee('Status rekonsiliasi', false);
        $response->assertDontSee('Fakta rekonsiliasi aktif', false);
        $response->assertViewHas('balanceSummary', [
            'total_hak' => 24,
            'saldo_aktual' => 19,
            'dialokasikan_aktif' => 2,
            'dilindungi_penangguhan_dinas' => 3,
            'saldo_dapat_diajukan' => 14,
        ]);
        foreach ([
            'total_hak' => ['Total hak', 24],
            'saldo_aktual' => ['Saldo aktual', 19],
            'dialokasikan_aktif' => ['Dialokasikan aktif', 2],
            'dilindungi_penangguhan_dinas' => ['Dilindungi penangguhan dinas', 3],
            'saldo_dapat_diajukan' => ['Saldo dapat diajukan', 14],
        ] as $key => [$label, $value]) {
            $response->assertSee($label, false);
            $response->assertSee('data-balance-value="'.$key.'">'.$value.' hari</dd>', false);
            $response->assertDontSee('name="'.$key.'"', false);
        }
        $response->assertSeeInOrder([
            'Hak N-2 (2024)',
            '1 hari',
            'Hak N-1 (2025)',
            '6 hari',
            'Hak tahun berjalan (2026)',
            '12 hari',
            'Terpakai',
            '5 hari',
            'Hangus',
            '6 hari',
        ], false);
    }

    public function test_history_merender_sumber_status_dokumen_privat_dan_aksi_hanya_untuk_manual_aktif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Histori Manual']);
        $type = $this->annualType();
        $request = $this->leaveRequest($employee, $type, '00000000-0000-4000-8000-000000000710');
        $active = $this->usage($employee, $type, '00000000-0000-4000-8000-000000000711', [
            'effective_date' => '2026-03-02',
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-04',
            'workdays' => 3,
            'administrative_note' => 'Cuti eksternal aktif untuk koreksi.',
        ]);
        $superseded = $this->usage($employee, $type, '00000000-0000-4000-8000-000000000712', [
            'effective_date' => '2026-02-02',
            'start_date' => '2026-02-02',
            'end_date' => '2026-02-03',
            'workdays' => 2,
            'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
            'correction_reason' => 'Diganti oleh versi baru.',
        ]);
        $approved = $this->usage($employee, $type, '00000000-0000-4000-8000-000000000713', [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $request->id,
            'effective_date' => '2026-01-05',
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
        ]);
        $document = LeaveUsageDocument::query()->forceCreate([
            'id' => '00000000-0000-4000-8000-000000000714',
            'leave_usage_record_id' => $active->id,
            'leave_usage_reconciliation_set_id' => null,
            'original_name' => 'bukti-cuti-eksternal.pdf',
            'stored_name' => 'nama-rahasia.pdf',
            'path' => 'cuti/pemakaian/nama-rahasia.pdf',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_by' => $admin->id,
        ]);

        $listResponse = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
        ]));

        $listResponse->assertOk();
        $listResponse->assertSee('Riwayat Fakta Pemakaian', false);
        $listResponse->assertSee('Di luar SIMPEG', false);
        $listResponse->assertSee('Melalui SIMPEG', false);
        $listResponse->assertSee('Digantikan', false);
        $listResponse->assertSee('bukti-cuti-eksternal.pdf', false);
        $listResponse->assertSee(route('cuti.manual.download', [$active->id, $document->id]), false);
        $listResponse->assertDontSee('nama-rahasia.pdf', false);
        $listResponse->assertDontSee('cuti/pemakaian/nama-rahasia.pdf', false);

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'edit_usage' => $active->id,
            'manual_action' => 'correct',
        ]));

        $response->assertOk();
        $response->assertSee(route('cuti.manual.correct', $active->id), false);
        $response->assertDontSee(route('cuti.manual.correct', $superseded->id), false);
        $response->assertDontSee(route('cuti.manual.correct', $approved->id), false);
        $response->assertSee('value="2026-03-02"', false);
        $response->assertSee('value="2026-03-04"', false);
        $response->assertSee('Cuti eksternal aktif untuk koreksi.', false);
        $response->assertDontSee('nama-rahasia.pdf', false);
        $response->assertDontSee('cuti/pemakaian/nama-rahasia.pdf', false);
        $response->assertSee('Perbaiki fakta pemakaian', false);
        $response->assertSee('Alasan perbaikan', false);
        $response->assertSee('Dokumen bukti perbaikan', false);
        $response->assertSee('Simpan Perbaikan', false);
        $response->assertDontSee('Koreksi fakta pemakaian', false);

        $cancellation = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'edit_usage' => $active->id,
            'manual_action' => 'cancel',
        ]));

        $cancellation->assertOk();
        $cancellation->assertSee(route('cuti.manual.cancel', $active->id), false);
        $cancellation->assertDontSee(route('cuti.manual.cancel', $superseded->id), false);
        $cancellation->assertDontSee(route('cuti.manual.cancel', $approved->id), false);
        $cancellation->assertDontSee('enctype="multipart/form-data"', false);
        $cancellation->assertSee('name="correction_reason"', false);
        $cancellation->assertDontSee('name="dokumen"', false);
        $cancellation->assertSee('Pembatalan hanya mengubah status fakta aktif', false);
        $cancellation->assertDontSee('Dokumen pembatalan privat', false);
        $cancellation->assertSee('Buka perbaikan', false);
        $cancellation->assertDontSee('Buka koreksi', false);
    }

    public function test_dokumen_koreksi_hanya_menampilkan_metadata_aman_dan_diunduh_admin_berotorisasi_dengan_header_privat(): void
    {
        Storage::fake(LeaveUsageDocument::STORAGE_DISK);
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Bukti Koreksi']);
        $set = $this->reconciliation($employee, $admin, [2024 => 1, 2025 => 2, 2026 => 3]);
        $storedName = '00000000-0000-4000-8000-000000000901.pdf';
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/'.$storedName;
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, 'bukti koreksi privat');
        $documentMetadata = [
            'leave_usage_record_id' => null,
            'leave_usage_reconciliation_set_id' => $set->id,
            'original_name' => 'rekap-koreksi.pdf',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
            'uploaded_by' => $admin->id,
        ];
        $document = LeaveUsageDocument::query()->forceCreate([
            ...$documentMetadata,
            'id' => '00000000-0000-4000-8000-000000000901',
            'stored_name' => $storedName,
            'path' => $path,
        ]);
        $this->adoptUsageDocumentArtifact($document, $employee->id, 'bukti koreksi privat');

        $url = route('cuti.reconciliation.document.download', [$set, $document]);
        $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'riwayat',
        ]))
            ->assertOk()
            ->assertSee('rekap-koreksi.pdf', false)
            ->assertSee($url, false)
            ->assertDontSee($storedName, false)
            ->assertDontSee($path, false);

        $download = $this->actingAs($admin)->get($url);
        $download
            ->assertOk()
            ->assertDownload('rekap-koreksi.pdf')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, (string) $download->headers->get('Cache-Control'));
        }

        $this->actingAs(User::factory()->pegawai()->create())->get($url)->assertForbidden();
        Auth::logout();
        $this->get($url)->assertRedirect('/login');

        $otherSet = $this->reconciliation(Employee::factory()->create(), $admin, [2024 => 0, 2025 => 0, 2026 => 0]);
        $this->actingAs($admin)->get(route('cuti.reconciliation.document.download', [$otherSet, $document]))->assertNotFound();

        $pathTraversalDocument = LeaveUsageDocument::query()->forceCreate([
            ...$documentMetadata,
            'id' => '00000000-0000-4000-8000-000000000902',
            'stored_name' => $storedName,
            'path' => '../'.$storedName,
        ]);
        $this->actingAs($admin)
            ->get(route('cuti.reconciliation.document.download', [$set, $pathTraversalDocument]))
            ->assertNotFound();

        $nestedStoredName = '00000000-0000-4000-8000-000000000903.pdf';
        $nestedSameOwner = LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/nested/'.$nestedStoredName;
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($nestedSameOwner, 'nested correction document');
        $nestedDocument = LeaveUsageDocument::query()->forceCreate([
            ...$documentMetadata,
            'id' => '00000000-0000-4000-8000-000000000903',
            'stored_name' => $nestedStoredName,
            'path' => $nestedSameOwner,
        ]);
        $this->actingAs($admin)
            ->get(route('cuti.reconciliation.document.download', [$set, $nestedDocument]))
            ->assertNotFound();
    }

    public function test_unduh_bukti_rekonsiliasi_menolak_artifact_adopted_yang_byte_nya_berubah(): void
    {
        Storage::fake(LeaveUsageDocument::STORAGE_DISK);
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $set = $this->reconciliation($employee, $admin, [2024 => 1, 2025 => 2, 2026 => 3]);
        $storedName = '00000000-0000-4000-8000-000000000904.pdf';
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/'.$storedName;
        $originalBytes = 'bukti rekonsiliasi asli';
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, $originalBytes);
        $document = LeaveUsageDocument::query()->forceCreate([
            'id' => '00000000-0000-4000-8000-000000000904',
            'leave_usage_record_id' => null,
            'leave_usage_reconciliation_set_id' => $set->id,
            'original_name' => 'rekap-integritas.pdf',
            'stored_name' => $storedName,
            'path' => $path,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($originalBytes),
            'uploaded_by' => $admin->id,
        ]);
        $task = $this->adoptUsageDocumentArtifact($document, $employee->id, $originalBytes);
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, 'bukti rekonsiliasi telah diubah');

        $this->actingAs($admin)
            ->get(route('cuti.reconciliation.document.download', [$set, $document]))
            ->assertNotFound()
            ->assertDontSee('bukti rekonsiliasi telah diubah');

        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_kontrol_manual_hilang_bila_permission_manual_dicabut_tanpa_membuka_bypass_role(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $active = $this->usage(
            $employee,
            $this->annualType(),
            '00000000-0000-4000-8000-000000000715',
        );
        $url = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
        ]);
        $manualUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
        ]);
        $editorUrl = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'edit_usage' => $active->id,
            'manual_action' => 'correct',
        ]);

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertDontSee('Muat Editor Cuti Eksternal', false)
            ->assertDontSee(route('cuti.manual.store', $employee), false);
        $this->actingAs($admin)
            ->get($manualUrl)
            ->assertOk()
            ->assertSee(route('cuti.manual.store', $employee), false);
        $this->actingAs($admin)
            ->get($editorUrl)
            ->assertOk()
            ->assertSee(route('cuti.manual.correct', $active), false);
        $this->actingAs($admin)
            ->getJson(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'edit_usage' => $active->id,
                'manual_action' => 'delete',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manual_action');

        $manualPermission = Permission::query()->where('name', 'cuti.manual.manage')->sole();
        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $adminRole->permissions()->detach($manualPermission->id);

        $response = $this->actingAs($admin)->get($url);

        $response->assertOk();
        $response->assertSee(route('cuti.reconciliation.store', $employee), false);
        $response->assertDontSee(e($manualUrl), false);
        $response->assertDontSee(route('cuti.manual.store', $employee), false);
        $response->assertDontSee('Catat cuti eksternal', false);
        $response->assertDontSee('Simpan Cuti Eksternal', false);

        $this->actingAs($admin)
            ->get($manualUrl)
            ->assertOk()
            ->assertDontSee(route('cuti.manual.store', $employee), false);
        $this->actingAs($admin)
            ->get($editorUrl)
            ->assertOk()
            ->assertDontSee(route('cuti.manual.correct', $active), false)
            ->assertDontSee(route('cuti.manual.cancel', $active), false);
    }

    public function test_opsi_jenis_cuti_dibatasi_dan_memuat_id_nama_serta_code(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $selected = $this->leaveType('zz-terpilih', 'ZZ Jenis Terpilih');

        foreach (range(1, 105) as $index) {
            $this->leaveType(sprintf('opsi-%03d', $index), sprintf('Opsi Cuti %03d', $index));
        }

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'leave_type' => $selected->id,
        ]));

        $response->assertOk()->assertViewHas('leaveTypeOptions');
        $options = $response->viewData('leaveTypeOptions');
        $this->assertLessThanOrEqual(100, $options->count());
        $this->assertSame([
            'id' => $selected->id,
            'nama' => 'ZZ Jenis Terpilih',
            'code' => 'zz-terpilih',
        ], $options->first());
    }

    public function test_kontrol_filter_history_pagination_query_dan_html_tetap_bounded(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $type = $this->annualType();

        foreach (range(1, 12) as $index) {
            $day = sprintf('2026-04-%02d', $index);
            $this->usage($employee, $type, sprintf('00000000-0000-4000-8000-%012d', 800 + $index), [
                'effective_date' => $day,
                'start_date' => $day,
                'end_date' => $day,
                'administrative_note' => "Fakta manual terpagasi {$index}.",
            ]);
        }

        $parameters = [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'usage_year' => 2026,
            'leave_type' => $type->id,
            'sort' => 'effective_date',
            'direction' => 'desc',
            'per_page_usage' => 10,
        ];
        $url = route('cuti.saldo.administrasi', $parameters);
        $response = $this->actingAs($admin)->get($url);

        $response->assertOk();
        $response->assertDontSee('manualExternalApprovalEditor(', false);
        $response->assertSee('Kepala Bagian Fixture');
        $response->assertSee('PYBMC Fixture');
        foreach (['source_type', 'record_status', 'usage_year', 'leave_type', 'sort', 'direction', 'per_page_usage'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        $rows = $response->viewData('usageRows');
        $this->assertSame(12, $rows->total());
        $this->assertSame(10, $rows->count());
        $this->assertSame(array_map('strval', array_merge($parameters, ['page_usage' => 2])), $this->queryParameters($rows->url(2)));
        $listHtmlBytes = strlen($response->getContent());
        $listQueryCount = $this->pageQueryCount($url);
        // Header aplikasi dan kontrol aksesibel bersifat tetap; 122 KiB masih menjaga
        // respons list tetap bounded tanpa memotong markup operasional yang diperlukan.
        $this->assertLessThan(122 * 1024, $listHtmlBytes);
        $this->assertLessThanOrEqual(22, $listQueryCount);

        $editorParameters = array_merge($parameters, [
            'edit_usage' => '00000000-0000-4000-8000-000000000812',
            'manual_action' => 'correct',
        ]);
        $editorUrl = route('cuti.saldo.administrasi', $editorParameters);
        $editorResponse = $this->actingAs($admin)->get($editorUrl);

        $editorResponse->assertOk();
        $editorResponse->assertSee(route('cuti.manual.correct', $editorParameters['edit_usage']), false);
        $editorHtmlBytes = strlen($editorResponse->getContent());
        $editorQueryCount = $this->pageQueryCount($editorUrl);
        $this->assertLessThan(122 * 1024, $editorHtmlBytes);
        $this->assertLessThanOrEqual(23, $editorQueryCount);
    }

    public function test_workspace_buat_pemakaian_manual_memuat_preview_dan_opsi_rangkaian_tetap_bounded(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $type = $this->leaveType('melahirkan', 'Cuti Melahirkan');
        $firstCase = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'created_by' => $admin->id,
        ]);
        $secondCase = LeaveRequestCase::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'created_by' => $admin->id,
        ]);
        $this->usage($employee, $type, '00000000-0000-4000-8000-000000000821', [
            'leave_request_case_id' => $firstCase->id,
        ]);
        $this->usage($employee, $type, '00000000-0000-4000-8000-000000000822', [
            'leave_request_case_id' => $secondCase->id,
            'effective_date' => '2026-01-06',
            'start_date' => '2026-01-06',
            'end_date' => '2026-01-06',
        ]);
        $this->previewChain($employee, [
            $this->previewStep(1, 'kepala_bagian', $kepalaBagian->id),
            $this->previewStep(2, 'pybmc', $pybmc->id, isFinal: true),
        ]);
        $url = route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'manual',
        ]);

        $response = $this->actingAs($admin)->get($url);

        $response->assertOk()
            ->assertSee('manualExternalApprovalEditor(', false)
            ->assertViewHas('currentApprovalChainPreview', fn (array $preview): bool => $preview['available'] && $preview['valid'])
            ->assertViewHas('manualLeaveCaseOptions', function ($options) use ($firstCase, $secondCase): bool {
                return $options->count() === 2
                    && $options->contains('id', $firstCase->id)
                    && $options->contains('id', $secondCase->id);
            })
            ->assertViewHas('usageRowsUseScalarType', true)
            ->assertViewHas('usageRows', fn ($rows): bool => $rows->count() === 2
                && $rows->every(fn (LeaveUsageRecord $row): bool => ! $row->relationLoaded('jenisCuti')
                    && $row->getAttribute('workspace_usage_type_name') === 'Cuti Melahirkan'));

        $this->assertLessThan(120 * 1024, strlen($response->getContent()));
        $this->assertLessThanOrEqual(23, $this->pageQueryCount($url));
    }

    public function test_halaman_pribadi_menjelaskan_projection_dan_tetap_memakai_riwayat_pengajuan_saja(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $type = $this->annualType();
        $this->leaveRequest($employee, $type, '00000000-0000-4000-8000-000000000901');
        $this->usage($employee, $type, '00000000-0000-4000-8000-000000000902', [
            'administrative_note' => 'Catatan manual privat tidak menjadi baris pengajuan pribadi.',
        ]);

        $response = $this->actingAs($user)->get(route('cuti.saldo'));

        $response->assertOk();
        $response->assertSee('Saldo dihitung dari pemakaian cuti yang tercatat dan tidak dapat diubah langsung.', false);
        $response->assertSee('Fixture riwayat API.', false);
        $response->assertDontSee('Catatan manual privat tidak menjadi baris pengajuan pribadi.', false);
        $this->assertInstanceOf(LengthAwarePaginator::class, $response->viewData('history'));
    }

    public function test_history_hanya_memuat_pegawai_terpilih_dengan_urutan_stabil_dan_relasi_aman(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $selected = Employee::factory()->create(['nama_lengkap' => 'Pegawai Riwayat Terpilih']);
        $other = Employee::factory()->create(['nama_lengkap' => 'Pegawai Lain']);
        $type = $this->annualType();
        $request = $this->leaveRequest($selected, $type, '00000000-0000-4000-8000-000000000010');

        $older = $this->usage($selected, $type, '00000000-0000-4000-8000-000000000101', [
            'effective_date' => '2026-02-01',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-01',
            'created_at' => '2026-02-01 08:00:00',
        ]);
        $sameTimeLowerId = $this->usage($selected, $type, '00000000-0000-4000-8000-000000000102', [
            'effective_date' => '2026-02-02',
            'start_date' => '2026-02-02',
            'end_date' => '2026-02-02',
            'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
            'correction_reason' => 'Versi lama untuk menguji urutan stabil.',
            'created_at' => '2026-02-02 08:00:00',
        ]);
        $sameTimeHigherId = $this->usage($selected, $type, '00000000-0000-4000-8000-000000000103', [
            'effective_date' => '2026-02-02',
            'start_date' => '2026-02-02',
            'end_date' => '2026-02-02',
            'created_at' => '2026-02-02 08:00:00',
        ]);
        $approved = $this->usage($selected, $type, '00000000-0000-4000-8000-000000000104', [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $request->id,
            'effective_date' => '2026-02-03',
            'start_date' => '2026-02-03',
            'end_date' => '2026-02-03',
            'created_at' => '2026-02-03 08:00:00',
        ]);
        $this->usage($other, $type, '00000000-0000-4000-8000-000000000999', [
            'effective_date' => '2026-12-01',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'created_at' => '2026-12-01 08:00:00',
        ]);
        $document = LeaveUsageDocument::query()->forceCreate([
            'id' => '00000000-0000-4000-8000-000000000201',
            'leave_usage_record_id' => $sameTimeHigherId->id,
            'leave_usage_reconciliation_set_id' => null,
            'original_name' => 'bukti-riwayat.pdf',
            'stored_name' => 'rahasia-tersimpan.pdf',
            'path' => 'cuti/pemakaian/rahasia-tersimpan.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'uploaded_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            'pegawai' => $selected->id,
            'status' => 'semua_pegawai',
        ]));

        $response->assertOk()->assertViewHas('usageRows');
        $rows = $response->viewData('usageRows');
        $this->assertInstanceOf(LengthAwarePaginator::class, $rows);
        $this->assertSame('page_usage', $rows->getPageName());
        $this->assertSame([
            $approved->id,
            $sameTimeHigherId->id,
            $sameTimeLowerId->id,
            $older->id,
        ], $rows->pluck('id')->all());
        $this->assertSame([$selected->id], $rows->pluck('employee_id')->unique()->values()->all());

        $manualRow = $rows->firstWhere('id', $sameTimeHigherId->id);
        $this->assertTrue($manualRow->relationLoaded('employee'));
        $this->assertTrue($manualRow->relationLoaded('jenisCuti'));
        $this->assertTrue($manualRow->relationLoaded('leaveRequest'));
        $this->assertTrue($manualRow->relationLoaded('documents'));
        $this->assertNull($manualRow->leaveRequest);
        $this->assertSame($selected->id, $manualRow->employee->id);
        $this->assertSame($type->id, $manualRow->jenisCuti->id);

        $documentPayload = $manualRow->documents->sole()->getAttributes();
        $this->assertSame($document->id, $documentPayload['id']);
        $this->assertSame('bukti-riwayat.pdf', $documentPayload['original_name']);
        $this->assertSame('application/pdf', $documentPayload['mime_type']);
        $this->assertSame(2048, $documentPayload['size_bytes']);
        $this->assertArrayNotHasKey('path', $documentPayload);
        $this->assertArrayNotHasKey('disk', $documentPayload);
        $this->assertArrayNotHasKey('stored_name', $documentPayload);
        $this->assertSame($request->id, $rows->firstWhere('id', $approved->id)->leaveRequest->id);
        $this->assertStringNotContainsString('rahasia-tersimpan.pdf', $response->getContent());
        $this->assertStringNotContainsString('cuti/pemakaian/rahasia-tersimpan.pdf', $response->getContent());
    }

    public function test_filter_sort_dan_pagination_history_dijalankan_server_side_dan_mempertahankan_query_string(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $sick = $this->leaveType('sakit', 'Cuti Sakit');
        $annual = $this->annualType();

        foreach (range(1, 12) as $index) {
            $this->usage($employee, $sick, sprintf('00000000-0000-4000-8000-%012d', 300 + $index), [
                'effective_date' => sprintf('2026-01-%02d', $index),
                'start_date' => sprintf('2026-01-%02d', $index),
                'end_date' => sprintf('2026-01-%02d', $index),
                'workdays' => $index,
                'created_at' => sprintf('2026-01-%02d 08:00:00', $index),
            ]);
        }
        $this->usage($employee, $annual, '00000000-0000-4000-8000-000000000401');
        $this->usage($employee, $sick, '00000000-0000-4000-8000-000000000402', [
            'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
            'correction_reason' => 'Dibatalkan untuk fixture filter.',
            'effective_date' => '2026-02-01',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-01',
        ]);
        $this->usage($employee, $sick, '00000000-0000-4000-8000-000000000403', [
            'usage_year' => 2025,
            'effective_date' => '2025-02-01',
            'start_date' => '2025-02-01',
            'end_date' => '2025-02-01',
        ]);

        $parameters = [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'usage_year' => 2026,
            'leave_type' => $sick->id,
            'sort' => 'workdays',
            'direction' => 'asc',
            'per_page_usage' => 10,
            'page_usage' => 2,
        ];
        $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', $parameters));

        $response->assertOk();
        $rows = $response->viewData('usageRows');
        $this->assertInstanceOf(LengthAwarePaginator::class, $rows);
        $this->assertSame(12, $rows->total());
        $this->assertSame(2, $rows->count());
        $this->assertSame(2, $rows->currentPage());
        $this->assertSame([11, 12], $rows->pluck('workdays')->all());
        $this->assertSame('page_usage', $rows->getPageName());
        $this->assertSame(array_map('strval', array_merge($parameters, ['page_usage' => 1])), $this->queryParameters($rows->url(1)));
    }

    public function test_filter_sort_dan_per_page_invalid_ditolak_bukan_diganti_default(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $base = ['pegawai' => $employee->id];

        foreach ([
            ['source_type' => 'legacy'],
            ['record_status' => 'deleted'],
            ['usage_year' => 1899],
            ['leave_type' => '00000000-0000-4000-8000-000000000999'],
            ['sort' => 'employee_id'],
            ['direction' => 'sideways'],
            ['per_page' => 999],
            ['per_page_usage' => 999],
            ['page_usage' => 0],
            ['tab' => 'koreksi'],
            ['tab' => 'lainnya'],
        ] as $invalid) {
            $field = array_key_first($invalid);
            $this->actingAs($admin)
                ->getJson(route('cuti.saldo.administrasi', array_merge($base, $invalid)))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->actingAs($admin)
            ->getJson(route('cuti.saldo.administrasi', array_merge($base, ['tab' => 'riwayat'])))
            ->assertOk();
    }

    public function test_uuid_filter_rusak_gagal_tertutup_sebelum_mencapai_postgresql(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        foreach ([
            ['pegawai' => 'bukan-uuid'],
            ['leave_type' => 'bukan-uuid'],
        ] as $invalid) {
            $this->actingAs($admin)
                ->getJson(route('cuti.saldo.administrasi', $invalid))
                ->assertNotFound();
        }
    }

    public function test_default_history_dibatasi_sepuluh_baris_dan_query_tidak_tumbuh_bersama_data(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $type = $this->annualType();
        $url = route('cuti.saldo.administrasi', ['pegawai' => $employee->id, 'status' => 'semua_pegawai']);
        $this->usage($employee, $type, '00000000-0000-4000-8000-000000000501', [
            'effective_date' => '2026-01-01',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-01',
        ]);
        $this->actingAs($admin)->get($url)->assertOk();

        $smallQueryCount = $this->pageQueryCount($url);

        foreach (range(1, 30) as $index) {
            $day = Carbon::create(2026, 1, 1)->addDays($index)->toDateString();
            $this->usage($employee, $type, sprintf('00000000-0000-4000-8000-%012d', 510 + $index), [
                'effective_date' => $day,
                'start_date' => $day,
                'end_date' => $day,
            ]);
        }

        $largeQueryCount = $this->pageQueryCount($url);
        $response = $this->actingAs($admin)->get($url)->assertOk();
        $rows = $response->viewData('usageRows');

        $this->assertSame(31, $rows->total());
        $this->assertSame(10, $rows->count());
        $this->assertSame(10, $rows->perPage());
        // Variasi satu query saat dataset melewati halaman pertama tetap bounded;
        // pertumbuhan per baris akan melampaui toleransi ini dan menggagalkan test.
        $this->assertLessThanOrEqual($smallQueryCount + 1, $largeQueryCount);
        $this->assertLessThanOrEqual(22, $largeQueryCount);
    }

    public function test_tidak_ada_rekonsiliasi_dibedakan_dari_rekonsiliasi_eksplisit_bernilai_nol(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $without = Employee::factory()->create();
        $withZero = Employee::factory()->create();
        $type = $this->annualType();

        $withoutData = $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', ['pegawai' => $without->id, 'status' => 'semua_pegawai']))
            ->assertOk()
            ->viewData('balanceReconciliation');
        $this->assertFalse($withoutData['reconciled']);
        $this->assertNull($withoutData['usage']);

        $set = LeaveUsageReconciliationSet::query()->forceCreate([
            'id' => '00000000-0000-4000-8000-000000000601',
            'employee_id' => $withZero->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-19',
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'administrative_note' => 'Nol eksplisit untuk tiga tahun.',
            'recorded_by' => $admin->id,
        ]);
        foreach ([2024, 2025, 2026] as $year) {
            LeaveUsageRecord::query()->forceCreate([
                'id' => sprintf('00000000-0000-4000-8000-%012d', 600 + $year),
                'employee_id' => $withZero->id,
                'leave_type_id' => $type->id,
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'reconciliation_set_id' => $set->id,
                'leave_request_id' => null,
                'leave_request_case_id' => null,
                'usage_year' => $year,
                'effective_date' => "{$year}-12-31",
                'start_date' => null,
                'end_date' => null,
                'workdays' => 0,
                'administrative_note' => 'Nol eksplisit.',
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'recorded_by' => $admin->id,
            ]);
        }

        $zeroData = $this->actingAs($admin)
            ->get(route('cuti.saldo.administrasi', ['pegawai' => $withZero->id, 'status' => 'semua_pegawai']))
            ->assertOk()
            ->viewData('balanceReconciliation');
        $this->assertTrue($zeroData['reconciled']);
        $this->assertSame(['n2' => 0, 'n1' => 0, 'current' => 0], $zeroData['usage']);
    }

    public function test_tab_riwayat_memuat_riwayat_dokumen_terbatas_tanpa_melewati_anggaran_query(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $set = $this->reconciliation($employee, $admin, [2024 => 0, 2025 => 0, 2026 => 0]);
        LeaveUsageDocument::query()->forceCreate([
            'id' => '00000000-0000-4000-8000-000000000951',
            'leave_usage_record_id' => null,
            'leave_usage_reconciliation_set_id' => $set->id,
            'original_name' => 'riwayat.pdf',
            'stored_name' => '00000000-0000-4000-8000-000000000951.pdf',
            'path' => LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000951.pdf',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'uploaded_by' => $admin->id,
        ]);
        $url = route('cuti.saldo.administrasi', ['pegawai' => $employee->id, 'status' => 'semua_pegawai', 'tab' => 'riwayat']);

        $this->actingAs($admin);
        $this->assertLessThanOrEqual(24, $this->pageQueryCount($url));
        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertSee('riwayat.pdf', false);
    }

    public function test_tab_riwayat_tetap_menemukan_dokumen_tahun_lama_setelah_set_tahun_baru_aktif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $oldSet = $this->reconciliation($employee, $admin, [2024 => 0, 2025 => 0, 2026 => 0]);
        $document = LeaveUsageDocument::query()->forceCreate([
            'id' => '00000000-0000-4000-8000-000000000952',
            'leave_usage_record_id' => null,
            'leave_usage_reconciliation_set_id' => $oldSet->id,
            'original_name' => 'bukti-perbaikan-2026.pdf',
            'stored_name' => '00000000-0000-4000-8000-000000000952.pdf',
            'path' => LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000952.pdf',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'uploaded_by' => $admin->id,
        ]);
        Carbon::setTestNow('2027-01-02 08:00:00');

        try {
            $newSet = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
                $employee,
                2027,
                [2025 => 0, 2026 => 0, 2027 => 0],
                now(),
                'Snapshot tahun baru.',
                $admin,
            );
            $response = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
                'pegawai' => $employee->id,
                'status' => 'semua_pegawai',
                'tab' => 'riwayat',
            ]));
        } finally {
            Carbon::setTestNow('2026-08-19 10:00:00');
        }

        $response->assertOk()
            ->assertSee('bukti-perbaikan-2026.pdf', false)
            ->assertSee('2026', false)
            ->assertSee(route('cuti.reconciliation.document.download', [$oldSet, $document]), false)
            ->assertSee(route('cuti.reconciliation.correct', $newSet), false)
            ->assertDontSee($document->stored_name, false)
            ->assertDontSee($document->path, false);
    }

    public function test_tab_riwayat_menyediakan_pagination_untuk_bukti_yang_melebihi_dua_puluh_set(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $activeSet = $this->reconciliation($employee, $admin, [2024 => 0, 2025 => 0, 2026 => 0]);
        $oldest = null;

        foreach (range(1, 20) as $index) {
            $set = LeaveUsageReconciliationSet::query()->forceCreate([
                'employee_id' => $employee->id,
                'balance_year' => 2025,
                'reconciled_at' => Carbon::parse('2025-01-01')->addDays($index),
                'status' => LeaveUsageReconciliationSet::STATUS_SUPERSEDED,
                'administrative_note' => "Versi historis {$index}.",
                'recorded_by' => $admin->id,
            ]);
            $oldest ??= $set;
        }
        $document = LeaveUsageDocument::query()->forceCreate([
            'leave_usage_record_id' => null,
            'leave_usage_reconciliation_set_id' => $oldest->id,
            'original_name' => 'bukti-halaman-kedua.pdf',
            'stored_name' => '00000000-0000-4000-8000-000000000954.pdf',
            'path' => LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000954.pdf',
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'uploaded_by' => $admin->id,
        ]);
        $base = [
            'pegawai' => $employee->id,
            'status' => 'semua_pegawai',
            'tab' => 'riwayat',
        ];

        $firstPage = $this->actingAs($admin)->get(route('cuti.saldo.administrasi', $base));
        $firstPage->assertOk()
            ->assertDontSee('bukti-halaman-kedua.pdf', false)
            ->assertSee('page_reconciliation_history=2', false);

        $this->actingAs($admin)->get(route('cuti.saldo.administrasi', [
            ...$base,
            'page_reconciliation_history' => 2,
        ]))
            ->assertOk()
            ->assertSee('bukti-halaman-kedua.pdf', false)
            ->assertSee(route('cuti.reconciliation.document.download', [$oldest, $document]), false)
            ->assertSee(route('cuti.reconciliation.correct', $activeSet), false);
    }

    public function test_api_saldo_pribadi_mempertahankan_shape_koleksi_dan_hanya_mengembalikan_seratus_terbaru_secara_stabil(): void
    {
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $type = $this->annualType();
        $ids = [];

        foreach (range(1, 101) as $index) {
            $id = sprintf('00000000-0000-4000-8000-%012d', $index);
            $ids[] = $id;
            $createdAt = Carbon::create(2026, 1, 1)->addMinutes(intdiv($index - 1, 10));
            $this->leaveRequest($employee, $type, $id, $createdAt);
        }
        $this->leaveRequest($other, $type, '00000000-0000-4000-8000-999999999999', Carbon::create(2026, 12, 1));

        $response = $this->actingAs($user)->getJson('/api/v1/profil-saya/saldo-cuti');

        $response->assertOk()->assertJsonCount(100, 'history');
        $history = $response->json('history');
        $this->assertIsArray($history);
        $this->assertSame(array_reverse(array_slice($ids, 1)), array_column($history, 'id'));
        $this->assertSame([
            'id',
            'jenis_cuti',
            'tanggal_mulai',
            'tanggal_selesai',
            'jumlah_hari_kerja',
            'alasan',
            'status',
            'current_step',
            'created_at',
        ], array_keys($history[0]));
        $this->assertArrayNotHasKey('current_page', $response->json());
    }

    /** @param array<int, int> $usage */
    private function reconciliation(Employee $employee, User $actor, array $usage): LeaveUsageReconciliationSet
    {
        $set = LeaveUsageReconciliationSet::query()->forceCreate([
            'id' => (string) str()->uuid(),
            'employee_id' => $employee->id,
            'balance_year' => 2026,
            'reconciled_at' => '2026-08-19',
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'administrative_note' => 'Rekonsiliasi fixture antarmuka.',
            'recorded_by' => $actor->id,
        ]);
        $type = $this->annualType();

        foreach ($usage as $year => $workdays) {
            LeaveUsageRecord::query()->forceCreate([
                'id' => (string) str()->uuid(),
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'reconciliation_set_id' => $set->id,
                'leave_request_id' => null,
                'leave_request_case_id' => null,
                'usage_year' => $year,
                'effective_date' => $year === 2026 ? '2026-08-19' : "{$year}-12-31",
                'start_date' => null,
                'end_date' => null,
                'workdays' => $workdays,
                'administrative_note' => 'Fakta rekonsiliasi fixture.',
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'recorded_by' => $actor->id,
            ]);
        }

        return $set;
    }

    /** @param array<string, mixed> $overrides */
    private function usage(Employee $employee, RefJenisCuti $type, string $id, array $overrides = []): LeaveUsageRecord
    {
        $payload = array_merge([
            'id' => $id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-01-05',
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
            'workdays' => 1,
            'administrative_note' => 'Fixture read model pemakaian cuti.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => null,
            'created_at' => '2026-01-05 08:00:00',
            'updated_at' => '2026-01-05 08:00:00',
        ], $overrides);

        $record = LeaveUsageRecord::query()->forceCreate($payload);

        return $record->source_type === LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL
            ? $this->attachValidManualApprovalSnapshot($record)
            : $record;
    }

    /**
     * @param  list<array{step_order:int,step_type:string,role_label:string,approver_employee_id:?string,is_final:bool}>  $steps
     */
    private function previewChain(Employee $employee, array $steps, bool $isActive = true): LeaveApprovalChain
    {
        $chain = LeaveApprovalChain::query()->forceCreate([
            'id' => (string) str()->uuid(),
            'employee_id' => $employee->id,
            'name' => 'Rantai fixture preview.',
            'is_active' => $isActive,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
        ]);

        foreach ($steps as $step) {
            LeaveApprovalChainStep::query()->forceCreate([
                'id' => (string) str()->uuid(),
                'leave_approval_chain_id' => $chain->id,
                'step_order' => $step['step_order'],
                'step_type' => $step['step_type'],
                'role_label' => $step['role_label'],
                'approver_role_key' => null,
                'approver_employee_id' => $step['approver_employee_id'],
                'is_final' => $step['is_final'],
            ]);
        }

        return $chain;
    }

    /** @return array{step_order:int,step_type:string,role_label:string,approver_employee_id:?string,is_final:bool} */
    private function previewStep(int $order, string $type, ?string $approverId, bool $isFinal = false): array
    {
        return [
            'step_order' => $order,
            'step_type' => $type,
            'role_label' => match ($type) {
                'kepala_bagian' => 'Kepala Bagian',
                'pybmc' => 'PYBMC',
                default => 'Verifier',
            },
            'approver_employee_id' => $approverId,
            'is_final' => $isFinal,
        ];
    }

    private function assertUnavailablePreview(Employee $employee, string $warning): void
    {
        $stateBeforePreview = $this->approvalPreviewState();
        $preview = app(CurrentApprovalChainPreviewQuery::class)->forEmployee($employee->id);

        $this->assertSame(false, $preview['available']);
        $this->assertSame(false, $preview['valid']);
        $this->assertSame([$warning], $preview['warnings']);
        $this->assertSame([], $preview['steps']);
        $this->assertSame($stateBeforePreview, $this->approvalPreviewState());
    }

    /** @return array{available:bool,valid:bool,warnings:list<string>,steps:list<array<string,mixed>>} */
    private function assertInvalidPreview(Employee $employee): array
    {
        $stateBeforePreview = $this->approvalPreviewState();
        $preview = app(CurrentApprovalChainPreviewQuery::class)->forEmployee($employee->id);

        $this->assertSame(true, $preview['available']);
        $this->assertSame(false, $preview['valid']);
        $this->assertNotSame([], $preview['warnings']);
        $this->assertSame($stateBeforePreview, $this->approvalPreviewState());

        return $preview;
    }

    /** @return array{chains:int,steps:int} */
    private function approvalPreviewState(): array
    {
        return [
            'chains' => LeaveApprovalChain::query()->count(),
            'steps' => LeaveApprovalChainStep::query()->count(),
        ];
    }

    private function dropOneActiveApprovalChainIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS leave_approval_chains_one_active_per_employee');
    }

    private function createOneActiveApprovalChainIndex(): void
    {
        $isActive = DB::connection()->getDriverName() === 'sqlite' ? '1' : 'true';

        DB::statement("CREATE UNIQUE INDEX leave_approval_chains_one_active_per_employee ON leave_approval_chains (employee_id) WHERE is_active = {$isActive}");
    }

    private function leaveRequest(
        Employee $employee,
        RefJenisCuti $type,
        string $id,
        ?Carbon $createdAt = null,
    ): LeaveRequest {
        $createdAt ??= Carbon::create(2026, 2, 3, 8);

        return LeaveRequest::query()->forceCreate([
            'id' => $id,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-02-03',
            'tanggal_selesai' => '2026-02-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture riwayat API.',
            'status' => 'disetujui',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function annualType(): RefJenisCuti
    {
        return RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
    }

    /** Menyiapkan artifact fixture melalui kontrak manifest produksi sebelum diuji sebagai dokumen sah. */
    private function adoptUsageDocumentArtifact(
        LeaveUsageDocument $document,
        string $employeeId,
        string $contents,
    ): StorageRecoveryTask {
        $sha256 = hash('sha256', $contents);
        $task = StorageRecoveryTask::query()->create([
            'idempotency_key' => hash('sha256', implode('|', [$employeeId, $document->path, $sha256])),
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $document->path,
            'owner_id' => $employeeId,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ]);

        app(StorageRecoveryService::class)->markLeaveUsageDocumentCreationTargetAdopted(
            $task->id,
            $employeeId,
            $document->path,
        );

        return $task;
    }

    private function leaveType(string $code, string $name): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => $code],
            [
                'nama' => $name,
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ],
        );
    }

    /** @return array<string, string> */
    private function queryParameters(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function pageQueryCount(string $url): int
    {
        $connection = $this->app['db']->connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->get($url)->assertOk();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }
}
