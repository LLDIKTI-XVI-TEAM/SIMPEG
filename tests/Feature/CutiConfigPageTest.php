<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Memastikan halaman konfigurasi menampilkan pembuat chain per pegawai,
 * bukan lagi permukaan konfigurasi approval tetap.
 */
class CutiConfigPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_melihat_kontrol_chain_dinamis_untuk_pegawai_terpilih(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Konfigurasi',
            'nip' => '100000000000000001',
        ]);
        $pegawai = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Konfigurasi Chain',
            'nip' => '100000000000000002',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
        $verifikator = Employee::factory()->create();
        User::factory()->adminKepegawaian()->create(['employee_id' => $verifikator->id]);
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'user_name' => 'Admin Konfigurasi',
            'event' => 'UPDATE',
            'auditable_type' => 'LeaveApprovalChain',
            'auditable_id' => $pegawai->id,
            'old_values' => [],
            'new_values' => ['reason' => 'Penyesuaian verifikator karena perubahan struktur jabatan dan kebutuhan pemeriksaan berlapis.'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $response = $this->actingAs($actor)->get(route('cuti.config', [
            'search' => 'Pegawai Konfigurasi',
            'employee_id' => $pegawai->id,
        ]));

        $response->assertOk()
            ->assertSee('Chain Approval Pegawai')
            ->assertSee('class="rounded-xl border border-border bg-surface shadow-sm"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('x-show="false"', false)
            ->assertSee("document.getElementById('employee-search')?.form?.requestSubmit()", false)
            ->assertSee('@keydown.enter="selectActive($event)"', false)
            ->assertSee(':name="selectedId ? null :', false)
            ->assertSee('x-show="selectedId"', false)
            ->assertSee($pegawai->nama_lengkap)
            ->assertSee($kepalaBagian->nama_lengkap)
            ->assertSee('Kepala Bagian')
            ->assertSee('Tambah Verifikator')
            ->assertSee('x-for="(verifier, index) in verifiers"', false)
            ->assertSee('maxVerifierSteps: 8', false)
            ->assertSee('Label Verifikator ${index + 1}', false)
            ->assertSee('Naikkan urutan verifikator', false)
            ->assertSee('Turunkan urutan verifikator', false)
            ->assertSee('Hapus verifikator ${index + 1}', false)
            ->assertSee('h-11 w-11', false)
            ->assertSee('sm:h-8 sm:w-8', false)
            ->assertSee('Tanpa Verifikator', false)
            ->assertSee('Verifikator ×${verifiers.length}', false)
            ->assertSee(':name="`steps[${index}][step_type]`"', false)
            ->assertSee(':name="`steps[${verifiers.length}][step_type]`"', false)
            ->assertSee(':key="verifier.client_key"', false)
            ->assertSee(':data-verifier-key="verifier.client_key"', false)
            ->assertSee('x-ref="addVerifierButton"', false)
            ->assertSee('aria-live="polite" aria-atomic="true" x-text="announcement"', false)
            ->assertSee('Dilewati karena actor digunakan lagi pada tahap yang lebih akhir.', false)
            ->assertSee('Tahap efektif untuk actor ini.', false)
            ->assertSee('verifier.validation_errors.role_label', false)
            ->assertSee('verifier.validation_errors.approver_employee_id', false)
            ->assertSee('kepalaBagianError', false)
            ->assertSee('pybmcError', false)
            ->assertSee(':aria-describedby="verifier.validation_errors.role_label ? `verifier-label-error-${verifier.client_key}` : null"', false)
            ->assertSee(':id="`verifier-label-error-${verifier.client_key}`"', false)
            ->assertDontSee('errorFor(`steps.${index}', false)
            ->assertDontSee('errorFor(`steps.${verifiers.length}', false)
            ->assertSee('action="'.route('cuti.config.employee-chain.store', $pegawai).'"', false)
            ->assertSee('sticky top-0 z-10', false)
            ->assertSee('sm:hidden', false)
            ->assertSee('for="employee-pybmc"', false)
            ->assertSee('>PYBMC</label>', false)
            ->assertDontSee('PYBMC Khusus')
            ->assertSee(':name="pybmcEmployeeId ? \'steps[_pybmc][approver_employee_id]\' : null" x-model="pybmcEmployeeId"', false)
            ->assertSee('PYBMC Global')
            ->assertSee('Override global mengubah PYBMC pada semua chain aktif', false)
            ->assertSee('Pelajari Backfill Chain Dinamis')
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('id="backfill-help-dialog"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-labelledby="backfill-help-title"', false)
            ->assertSee('aria-describedby="backfill-help-description"', false)
            ->assertSee('Apa itu Backfill Chain Dinamis?')
            ->assertSee('Kepala Bagian')
            ->assertSee('Verifikator')
            ->assertSee('PYBMC')
            ->assertSee('Alasan Backfill disimpan pada setiap chain yang berhasil dibuat dan catatan auditnya.')
            ->assertSee('data-modal-initial-focus="true"', false)
            ->assertSee('closeBackfillHelp()', false)
            ->assertSee('hidden overflow-x-auto md:block', false)
            ->assertSee('space-y-3 p-5 md:hidden', false)
            ->assertSee('break-words text-ink', false)
            ->assertSee('Chain pegawai')
            ->assertSee('Admin Konfigurasi')
            ->assertSee('Penyesuaian verifikator karena perubahan struktur jabatan dan kebutuhan pemeriksaan berlapis.')
            ->assertDontSee('Approver Stage 2', false)
            ->assertDontSee('Approver Stage 3', false)
            ->assertDontSee('stage2_approver_id', false)
            ->assertDontSee('stage3_approver_id', false);

        $html = $response->getContent();
        $verifierPosition = strpos($html, ':name="`steps[${index}][step_type]`"');
        $kepalaBagianPosition = strpos($html, ':name="`steps[${verifiers.length}][step_type]`"');
        $pybmcPosition = strpos($html, 'name="steps[_pybmc][step_type]"');

        $this->assertNotFalse($verifierPosition);
        $this->assertNotFalse($kepalaBagianPosition);
        $this->assertNotFalse($pybmcPosition);
        $this->assertTrue(
            $verifierPosition < $kepalaBagianPosition && $kepalaBagianPosition < $pybmcPosition,
            'Urutan DOM harus Verifikator, Kepala Bagian, lalu PYBMC khusus.',
        );

        $component = file_get_contents(resource_path('views/components/cuti/employee-combobox.blade.php'));
        $this->assertIsString($component);
        $this->assertStringContainsString('name="{{ $queryName }}"', $component);
        $this->assertStringContainsString(':name="selectedId ? null : @js($queryName)"', $component);
    }

    public function test_pencarian_pegawai_dibatasi_lima_puluh_hasil(): void
    {
        $actor = User::factory()->superAdmin()->create();

        foreach (range(1, 51) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Target Konfigurasi %03d', $number),
            ]);
        }

        $response = $this->actingAs($actor)->get(route('cuti.config', ['search' => 'Target Konfigurasi']));

        $response->assertOk()
            ->assertSee('Target Konfigurasi 001')
            ->assertDontSee('Target Konfigurasi 051');
    }

    public function test_penugasan_kepala_bagian_terbuka_yang_belum_dimulai_tidak_ditampilkan_atau_dipilih(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $actor = User::factory()->superAdmin()->create();
            $kepalaBagianMendatang = Employee::factory()->create([
                'nama_lengkap' => 'Kepala Bagian Mendatang',
            ]);
            $pegawai = Employee::factory()->create(['kepala_bagian_id' => null]);
            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagianMendatang->id,
                'tanggal_mulai' => '2026-07-24',
                'tanggal_berakhir' => null,
            ]);

            $response = $this->actingAs($actor)->get(route('cuti.config', [
                'employee_id' => $pegawai->id,
            ]));

            $response->assertOk()
                ->assertSee('Belum ditetapkan')
                ->assertSee('Pegawai belum memiliki Kepala Bagian efektif. Tetapkan struktur pegawai sebelum menyimpan chain.')
                ->assertDontSee($kepalaBagianMendatang->nama_lengkap)
                ->assertDontSee(':name="`steps[${verifiers.length}][approver_employee_id]`"', false)
                ->assertDontSee('value="'.$kepalaBagianMendatang->id.'"', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_penugasan_kepala_bagian_terbuka_yang_sudah_dimulai_tetap_ditampilkan_dan_dipilih(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $actor = User::factory()->superAdmin()->create();
            $kepalaBagianAktif = Employee::factory()->create([
                'nama_lengkap' => 'Kepala Bagian Aktif',
            ]);
            $pegawai = Employee::factory()->create(['kepala_bagian_id' => null]);
            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagianAktif->id,
                'tanggal_mulai' => '2026-07-23',
                'tanggal_berakhir' => null,
            ]);

            $response = $this->actingAs($actor)->get(route('cuti.config', [
                'employee_id' => $pegawai->id,
            ]));

            $response->assertOk()
                ->assertSee($kepalaBagianAktif->nama_lengkap)
                ->assertSee(':name="`steps[${verifiers.length}][approver_employee_id]`" value="'.$kepalaBagianAktif->id.'"', false)
                ->assertDontSee('Pegawai belum memiliki Kepala Bagian efektif. Tetapkan struktur pegawai sebelum menyimpan chain.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_penugasan_kepala_bagian_yang_berakhir_hari_ini_tetap_ditampilkan_dan_dipilih(): void
    {
        Carbon::setTestNow('2026-07-23 08:00:00');

        try {
            $actor = User::factory()->superAdmin()->create();
            $kepalaBagianHariTerakhir = Employee::factory()->create([
                'nama_lengkap' => 'Kepala Bagian Hari Terakhir',
            ]);
            $pegawai = Employee::factory()->create(['kepala_bagian_id' => null]);
            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagianHariTerakhir->id,
                'tanggal_mulai' => '2026-07-01',
                'tanggal_berakhir' => '2026-07-23',
            ]);

            $response = $this->actingAs($actor)->get(route('cuti.config', [
                'employee_id' => $pegawai->id,
            ]));

            $response->assertOk()
                ->assertSee($kepalaBagianHariTerakhir->nama_lengkap)
                ->assertSee(':name="`steps[${verifiers.length}][approver_employee_id]`" value="'.$kepalaBagianHariTerakhir->id.'"', false)
                ->assertDontSee('Pegawai belum memiliki Kepala Bagian efektif. Tetapkan struktur pegawai sebelum menyimpan chain.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_halaman_memakai_kepala_bagian_efektif_dan_mengabaikan_pointer_snapshot_serta_penugasan_mendatang(): void
    {
        Carbon::setTestNow('2026-08-30 08:00:00');

        try {
            $actor = User::factory()->superAdmin()->create();
            $kepalaBagianSnapshot = Employee::factory()->create([
                'nama_lengkap' => 'Kepala Bagian Snapshot A',
            ]);
            $kepalaBagianEfektif = Employee::factory()->create([
                'nama_lengkap' => 'Kepala Bagian Efektif B',
            ]);
            $kepalaBagianMendatang = Employee::factory()->create([
                'nama_lengkap' => 'Kepala Bagian Mendatang C',
            ]);
            $pegawai = Employee::factory()->create([
                'kepala_bagian_id' => $kepalaBagianSnapshot->id,
            ]);

            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagianEfektif->id,
                'tanggal_mulai' => '2026-08-29',
                'tanggal_berakhir' => null,
            ]);
            SupervisorAssignment::create([
                'employee_id' => $pegawai->id,
                'kepala_bagian_id' => $kepalaBagianMendatang->id,
                'tanggal_mulai' => '2026-08-31',
                'tanggal_berakhir' => null,
            ]);

            $response = $this->actingAs($actor)->get(route('cuti.config', [
                'employee_id' => $pegawai->id,
            ]));

            $response->assertOk()
                ->assertSee($kepalaBagianEfektif->nama_lengkap)
                ->assertSee(':name="`steps[${verifiers.length}][approver_employee_id]`" value="'.$kepalaBagianEfektif->id.'"', false)
                ->assertDontSee($kepalaBagianSnapshot->nama_lengkap)
                ->assertDontSee('value="'.$kepalaBagianSnapshot->id.'"', false)
                ->assertDontSee($kepalaBagianMendatang->nama_lengkap)
                ->assertDontSee('value="'.$kepalaBagianMendatang->id.'"', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_halaman_mengabaikan_old_step_malformed_saat_merender_ulang_editor(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        $response = $this->actingAs($actor)
            ->withSession([
                '_old_input' => [
                    'steps' => [
                        'bukan-array',
                        ['step_type' => 'verifier', 'role_label' => 'Ketua Tim Kerja', 'approver_employee_id' => ''],
                    ],
                ],
            ])
            ->get(route('cuti.config', ['employee_id' => $pegawai->id]));

        $response->assertOk()
            ->assertSee('Ketua Tim Kerja')
            ->assertDontSee('bukan-array');
    }

    public function test_error_validasi_verifier_menempel_pada_row_stabil_dan_error_semantik_tidak_mengikuti_index_dinamis(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $verifikatorPertama = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat AJAX Verifikator Pertama',
            'nip' => '198001012026000101',
        ]);
        $verifikatorKedua = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat AJAX Verifikator Kedua',
            'nip' => '198001012026000102',
        ]);
        $pybmc = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat AJAX PYBMC',
            'nip' => '198001012026000103',
        ]);

        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'steps.0.role_label' => ['Label verifier pertama tidak valid.'],
            'steps.0.approver_employee_id' => ['Actor verifier pertama tidak valid.'],
            'steps.1.role_label' => ['Label verifier kedua tidak valid.'],
            'steps.1.approver_employee_id' => ['Actor verifier kedua tidak valid.'],
            'steps.2.approver_employee_id' => ['Kepala Bagian tidak sesuai penugasan efektif.'],
            'steps.3.approver_employee_id' => ['PYBMC tidak dapat digunakan.'],
            'steps' => ['Chain approval tidak valid.'],
        ]));

        $response = $this->actingAs($actor)
            ->withSession([
                '_old_input' => [
                    'steps' => [
                        ['step_type' => 'verifier', 'role_label' => 'Verifier Stabil Pertama', 'approver_employee_id' => $verifikatorPertama->id],
                        ['step_type' => 'verifier', 'role_label' => 'Verifier Stabil Kedua', 'approver_employee_id' => $verifikatorKedua->id],
                        ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                        '_pybmc' => ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
                    ],
                ],
                'errors' => $errors,
            ])
            ->get(route('cuti.config', ['employee_id' => $pegawai->id]));

        $response->assertOk()
            ->assertSee('client_key', false)
            ->assertSee('validation_errors', false)
            ->assertSee('validation_errors: {', false)
            ->assertSee("role_label: ''", false)
            ->assertSee('kepalaBagianError', false)
            ->assertSee('Kepala Bagian tidak sesuai penugasan efektif.')
            ->assertSee('pybmcError', false)
            ->assertSee('PYBMC tidak dapat digunakan.')
            ->assertSee('Chain approval tidak valid.')
            ->assertSee(':aria-describedby="verifier.validation_errors.role_label ? `verifier-label-error-${verifier.client_key}` : null"', false)
            ->assertSee(':id="`verifier-label-error-${verifier.client_key}`"', false)
            ->assertSee('<option value="'.$verifikatorPertama->id.'">Kandidat AJAX Verifikator Pertama (198001012026000101)</option>', false)
            ->assertSee('<option value="'.$verifikatorKedua->id.'">Kandidat AJAX Verifikator Kedua (198001012026000102)</option>', false)
            ->assertSee('<option value="'.$pybmc->id.'">Kandidat AJAX PYBMC (198001012026000103)</option>', false);

        $html = $response->getContent();
        $firstRowStart = strpos($html, 'Verifier Stabil Pertama');
        $secondRowStart = strpos($html, 'Verifier Stabil Kedua');
        $verifierStateEnd = strpos($html, 'pybmcEmployeeId:', $secondRowStart ?: 0);

        $this->assertNotFalse($firstRowStart);
        $this->assertNotFalse($secondRowStart);
        $this->assertNotFalse($verifierStateEnd);

        $firstRowState = substr($html, $firstRowStart, $secondRowStart - $firstRowStart);
        $secondRowState = substr($html, $secondRowStart, $verifierStateEnd - $secondRowStart);

        $this->assertStringContainsString('Label verifier pertama tidak valid.', $firstRowState);
        $this->assertStringContainsString('Actor verifier pertama tidak valid.', $firstRowState);
        $this->assertStringNotContainsString('Label verifier kedua tidak valid.', $firstRowState);
        $this->assertStringContainsString('Label verifier kedua tidak valid.', $secondRowState);
        $this->assertStringContainsString('Actor verifier kedua tidak valid.', $secondRowState);
        $this->assertStringNotContainsString('Label verifier pertama tidak valid.', $secondRowState);
    }

    public function test_old_input_legacy_memetakan_error_dari_key_asli_tanpa_digeser_scalar_sparse(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'steps.0.approver_employee_id' => ['Error Kepala Bagian legacy.'],
            'steps.1.role_label' => ['Error label verifier indeks satu.'],
            'steps.1.approver_employee_id' => ['Error actor verifier indeks satu.'],
            'steps.2.approver_employee_id' => ['Error PYBMC legacy.'],
            'steps.7' => ['Error scalar sparse.'],
        ]));

        $response = $this->actingAs($actor)
            ->withSession([
                '_old_input' => [
                    'steps' => [
                        0 => ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                        1 => ['step_type' => 'verifier', 'role_label' => 'Verifier Legacy Indeks Satu', 'approver_employee_id' => $verifikator->id],
                        2 => ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
                        7 => 'payload-scalar-sparse',
                    ],
                ],
                'errors' => $errors,
            ])
            ->get(route('cuti.config', ['employee_id' => $pegawai->id]));

        $response->assertOk()
            ->assertSee('Verifier Legacy Indeks Satu')
            ->assertSee('Error label verifier indeks satu.')
            ->assertSee('Error actor verifier indeks satu.')
            ->assertSee('Error Kepala Bagian legacy.')
            ->assertSee('Error PYBMC legacy.')
            ->assertDontSee('payload-scalar-sparse')
            ->assertDontSee('Error scalar sparse.');

        $html = $response->getContent();
        $verifierStateStart = strpos($html, 'Verifier Legacy Indeks Satu');
        $verifierStateEnd = strpos($html, 'pybmcEmployeeId:', $verifierStateStart ?: 0);
        $kepalaBagianStateStart = strpos($html, 'kepalaBagianError:', $verifierStateEnd ?: 0);
        $pybmcStateStart = strpos($html, 'pybmcError:', $kepalaBagianStateStart ?: 0);
        $announcementStateStart = strpos($html, 'announcement:', $pybmcStateStart ?: 0);

        $this->assertNotFalse($verifierStateStart);
        $this->assertNotFalse($verifierStateEnd);
        $this->assertNotFalse($kepalaBagianStateStart);
        $this->assertNotFalse($pybmcStateStart);
        $this->assertNotFalse($announcementStateStart);

        $verifierState = substr($html, $verifierStateStart, $verifierStateEnd - $verifierStateStart);
        $kepalaBagianState = substr($html, $kepalaBagianStateStart, $pybmcStateStart - $kepalaBagianStateStart);
        $pybmcState = substr($html, $pybmcStateStart, $announcementStateStart - $pybmcStateStart);

        $this->assertStringContainsString('Error label verifier indeks satu.', $verifierState);
        $this->assertStringContainsString('Error actor verifier indeks satu.', $verifierState);
        $this->assertStringNotContainsString('Error Kepala Bagian legacy.', $verifierState);
        $this->assertStringContainsString('Error Kepala Bagian legacy.', $kepalaBagianState);
        $this->assertStringNotContainsString('Error actor verifier indeks satu.', $kepalaBagianState);
        $this->assertStringContainsString('Error PYBMC legacy.', $pybmcState);
    }

    public function test_key_step_invalid_mencegah_fallback_numeric_pybmc_mengambil_error_step_lain(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pegawai = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $verifikator = Employee::factory()->create();
        $pybmc = Employee::factory()->create();

        SupervisorAssignment::create([
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);

        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'steps.2.approver_employee_id' => ['Error Kepala Bagian pada key numeric dua.'],
        ]));

        $response = $this->actingAs($actor)
            ->withSession([
                '_old_input' => [
                    'steps' => [
                        0 => ['step_type' => 'verifier', 'role_label' => 'Verifier Key Nol', 'approver_employee_id' => $verifikator->id],
                        2 => ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id],
                        'foo' => 'payload-key-invalid',
                        '_pybmc' => ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id],
                    ],
                ],
                'errors' => $errors,
            ])
            ->get(route('cuti.config', ['employee_id' => $pegawai->id]));

        $response->assertOk()
            ->assertSee('Error Kepala Bagian pada key numeric dua.')
            ->assertDontSee('payload-key-invalid');

        $html = $response->getContent();
        $kepalaBagianStateStart = strpos($html, 'kepalaBagianError:');
        $pybmcStateStart = strpos($html, 'pybmcError:', $kepalaBagianStateStart ?: 0);
        $announcementStateStart = strpos($html, 'announcement:', $pybmcStateStart ?: 0);

        $this->assertNotFalse($kepalaBagianStateStart);
        $this->assertNotFalse($pybmcStateStart);
        $this->assertNotFalse($announcementStateStart);

        $kepalaBagianState = substr($html, $kepalaBagianStateStart, $pybmcStateStart - $kepalaBagianStateStart);
        $pybmcState = substr($html, $pybmcStateStart, $announcementStateStart - $pybmcStateStart);

        $this->assertStringContainsString('Error Kepala Bagian pada key numeric dua.', $kepalaBagianState);
        $this->assertStringNotContainsString('Error Kepala Bagian pada key numeric dua.', $pybmcState);
    }
}
