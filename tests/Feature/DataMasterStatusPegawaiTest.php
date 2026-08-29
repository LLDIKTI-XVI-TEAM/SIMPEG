<?php

namespace Tests\Feature;

use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Catatan konteks data: baris ref_status_pegawai (AKTIF, PENSIUN, dst.)
 * sudah ditanam oleh migration 2026_07_20 saat migrate, jadi test di kelas
 * ini memakai baris bawaan tersebut dan hanya menambah kode baru yang unik.
 */
class DataMasterStatusPegawaiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_status_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        // is_default sengaja dikirim untuk membuktikan field itu TIDAK bisa
        // di-mass-assign dari form; status default hanya boleh berubah lewat
        // keputusan terpisah, bukan input CRUD biasa.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'UJI_COBA',
                'nama' => 'Uji Coba',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Status percobaan.',
                'is_default' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'kode' => 'UJI_COBA',
            'nama' => 'Uji Coba',
            'kelompok' => 'Nonaktif',
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefStatusPegawai']);
    }

    public function test_store_menormalisasi_kelompok_aktif_tanpa_membatasi_kelompok_nonaktif_bebas(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'AKTIF_TAMBAHAN',
                'nama' => 'Aktif Tambahan',
                'kelompok' => ' aktif ',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'NONAKTIF_BEBAS',
                'nama' => 'Nonaktif Bebas',
                'kelompok' => 'Kontrak/khusus',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'kode' => 'AKTIF_TAMBAHAN',
            'kelompok' => 'Aktif',
        ]);
        $this->assertDatabaseHas('ref_status_pegawai', [
            'kode' => 'NONAKTIF_BEBAS',
            'kelompok' => 'Kontrak/khusus',
        ]);
    }

    public function test_update_menormalisasi_kelompok_aktif_khusus_tanpa_mengubah_kelompok_nonaktif_bebas(): void
    {
        $user = User::factory()->superAdmin()->create();
        $tugasBelajar = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $nonaktifBebas = RefStatusPegawai::query()->create([
            'kode' => 'STATUS_KONTRAK',
            'nama' => 'Status Kontrak',
            'kelompok' => 'Kontrak',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $tugasBelajar), [
                'kode' => 'TUGAS_BELAJAR',
                'nama' => 'Tugas Belajar',
                'kelompok' => ' AKTIF/KHUSUS ',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $nonaktifBebas), [
                'kode' => 'STATUS_KONTRAK',
                'nama' => 'Status Kontrak',
                'kelompok' => 'Kontrak/penugasan',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Aktif/khusus', $tugasBelajar->refresh()->kelompok);
        $this->assertSame('Kontrak/penugasan', $nonaktifBebas->refresh()->kelompok);
    }

    public function test_tambah_status_menolak_kode_dan_nama_duplikat(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'AKTIF',
                'nama' => 'Status Baru',
                'kelompok' => 'Aktif',
            ])
            ->assertSessionHasErrors(['kode']);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'STATUS_BARU',
                'nama' => 'Aktif',
                'kelompok' => 'Aktif',
            ])
            ->assertSessionHasErrors(['nama']);
    }

    public function test_super_admin_dapat_mengubah_status_biasa_dengan_audit(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $status), [
                'kode' => 'TUGAS_BELAJAR',
                'nama' => 'Tugas Belajar',
                'kelompok' => 'Aktif/khusus',
                'keterangan' => 'Pegawai sedang menempuh tugas belajar resmi.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'id' => $status->id,
            'keterangan' => 'Pegawai sedang menempuh tugas belajar resmi.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefStatusPegawai',
            'auditable_id' => $status->id,
        ]);
    }

    public function test_status_custom_terpakai_boleh_diubah_dalam_klasifikasi_yang_sama(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'CUSTOM_NONAKTIF',
            'nama' => 'Custom Nonaktif',
            'kelompok' => 'Kontrak',
        ]);
        $employee = Employee::factory()->create();
        DB::table('employees')->where('id', $employee->id)->update([
            'status_pegawai_id' => $status->id,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('data-master.status-pegawai.update', $status), [
                'kode' => $status->kode,
                'nama' => 'Custom Nonaktif Diperbarui',
                'kelompok' => 'Nonaktif Lain',
                'keterangan' => 'Metadata aman dalam klasifikasi nonaktif.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Nonaktif Lain', $status->refresh()->kelompok);
        $this->assertSame('Custom Nonaktif Diperbarui', $status->nama);
    }

    public function test_kode_dan_nama_status_sistem_tidak_dapat_diubah(): void
    {
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $pensiun = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $aktif), [
                'kode' => 'AKTIF',
                'nama' => 'Aktif Penuh',
                'kelompok' => 'Aktif',
            ])
            ->assertSessionHasErrors(['nama']);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $pensiun), [
                'kode' => 'PENSIUN_BARU',
                'nama' => 'Pensiun',
                'kelompok' => 'Nonaktif',
            ])
            ->assertSessionHasErrors(['kode']);

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $aktif->id, 'nama' => 'Aktif']);
        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $pensiun->id, 'kode' => 'PENSIUN']);
    }

    public function test_keterangan_status_sistem_masih_boleh_diubah(): void
    {
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $aktif), [
                'kode' => 'AKTIF',
                'nama' => 'Aktif',
                'kelompok' => 'Aktif',
                'keterangan' => 'Pegawai aktif bekerja penuh.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'id' => $aktif->id,
            'keterangan' => 'Pegawai aktif bekerja penuh.',
        ]);
    }

    public function test_toggle_status_biasa_dengan_audit_config_update(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.toggle', $status), [])
            ->assertRedirect();

        $this->assertFalse($status->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefStatusPegawai',
            'auditable_id' => $status->id,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.toggle', $status), [])
            ->assertRedirect();

        $this->assertTrue($status->refresh()->is_active);
    }

    public function test_status_sistem_tidak_dapat_dinonaktifkan(): void
    {
        // AKTIF, NONAKTIF, dan PENSIUN dipakai langsung oleh lifecycle sistem;
        // AKTIF juga menjadi default pegawai baru. Menonaktifkan salah satunya
        // dapat memutus alur walaupun status tersebut belum memiliki pemakaian.
        $user = User::factory()->superAdmin()->create();

        foreach (['AKTIF', 'NONAKTIF', 'PENSIUN'] as $kode) {
            $status = RefStatusPegawai::query()->where('kode', $kode)->firstOrFail();

            $this->actingAs($user)
                ->postWithCsrf(route('data-master.status-pegawai.toggle', $status), [])
                ->assertSessionHasErrors();

            $this->assertTrue($status->refresh()->is_active);
        }
    }

    public function test_status_sistem_tidak_dapat_dihapus_meski_belum_dipakai(): void
    {
        // Guard kode sistem melengkapi K-1: cek pemakaian saja tidak cukup
        // karena PENSIUN bisa saja belum dipakai pegawai mana pun padahal
        // proses followup EWS mencarinya dengan firstOrFail (crash bila hilang).
        $pensiun = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $pensiun), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $pensiun->id]);
    }

    public function test_status_yang_dipakai_pegawai_tidak_dapat_dihapus(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_DIPAKAI',
            'nama' => 'Uji Dipakai',
            'kelompok' => 'Nonaktif',
        ]);
        // FK employees.status_pegawai_id bersifat nullOnDelete sehingga
        // database tidak memblokir penghapusan; guard aplikasi satu-satunya
        // pelindung agar status pegawai tidak hilang diam-diam. Tautan FK
        // dibuat lewat query builder karena simpan via model ikut menyalin
        // nama status ke kolom legacy status_aktif yang masih dibatasi
        // CHECK constraint enum lama.
        $employee = Employee::factory()->create();
        DB::table('employees')->where('id', $employee->id)->update(['status_pegawai_id' => $status->id]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
    }

    public function test_status_yang_dipakai_riwayat_status_tidak_dapat_dihapus(): void
    {
        // Riwayat status bersifat append-only: pegawai yang sudah berpindah
        // status meninggalkan baris lama sebagai satu-satunya perujuk status
        // tersebut. Kolom status terkini pegawai sengaja dibiarkan menunjuk
        // status lain supaya penolakan benar-benar berasal dari pemeriksaan
        // riwayat, bukan dari pemeriksaan data pegawai yang sudah ada.
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_RIWAYAT',
            'nama' => 'Uji Riwayat',
            'kelompok' => 'Nonaktif',
        ]);
        $employee = Employee::factory()->create();
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => 'Uji Riwayat',
            'tanggal_efektif' => '2026-01-01',
            'is_latest' => false,
        ]);

        $this->assertNotSame($status->id, $employee->refresh()->status_pegawai_id);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
        ]);
    }

    public function test_status_yang_dipakai_transisi_terjadwal_tidak_dapat_dihapus(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_TRANSISI',
            'nama' => 'Uji Transisi',
            'kelompok' => 'Nonaktif',
        ]);
        $employee = Employee::factory()->create();
        EmployeeStatusTransition::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'tanggal_efektif' => now()->addMonth()->toDateString(),
            'kind' => EmployeeStatusTransition::KIND_STATUS,
            'keterangan' => 'Perubahan status terjadwal.',
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
    }

    public function test_status_sistem_dan_status_terpakai_tidak_boleh_berpindah_klasifikasi(): void
    {
        $user = User::factory()->superAdmin()->create();
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $nonaktif), [
                'kode' => $nonaktif->kode,
                'nama' => $nonaktif->nama,
                'kelompok' => ' aktif ',
                'keterangan' => 'Metadata tidak boleh mengubah klasifikasi.',
            ])
            ->assertSessionHasErrors('kelompok');

        $terpakai = RefStatusPegawai::create([
            'kode' => 'UJI_KLASIFIKASI',
            'nama' => 'Uji Klasifikasi',
            'kelompok' => 'Kontrak',
        ]);
        $employee = Employee::factory()->create();
        EmployeeStatusTransition::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $terpakai->id,
            'tanggal_efektif' => now()->addMonths(2)->toDateString(),
            'kind' => EmployeeStatusTransition::KIND_STATUS,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $terpakai), [
                'kode' => $terpakai->kode,
                'nama' => $terpakai->nama,
                'kelompok' => 'AKTIF/KHUSUS',
                'keterangan' => 'Perubahan klasifikasi massal.',
            ])
            ->assertSessionHasErrors('kelompok');

        $this->assertSame('Nonaktif', $nonaktif->refresh()->kelompok);
        $this->assertSame('Kontrak', $terpakai->refresh()->kelompok);
    }

    public function test_status_inti_tetap_tidak_boleh_berpindah_klasifikasi_saat_belum_dipakai(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'HILANG')->firstOrFail();
        $this->assertSame(0, DB::table('employees')->where('status_pegawai_id', $status->id)->count());
        $this->assertSame(0, DB::table('employee_status_histories')->where('status_pegawai_id', $status->id)->count());
        $this->assertSame(0, DB::table('employee_status_transitions')->where('status_pegawai_id', $status->id)->count());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('data-master.status-pegawai.update', $status), [
                'kode' => $status->kode,
                'nama' => $status->nama,
                'kelompok' => 'Aktif',
                'keterangan' => $status->keterangan,
            ])
            ->assertSessionHasErrors('kelompok');

        $this->assertSame('Nonaktif/khusus', $status->refresh()->kelompok);
    }

    public function test_action_menolak_bypass_perubahan_klasifikasi_status_terpakai(): void
    {
        $user = User::factory()->superAdmin()->create();
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_BYPASS_KLASIFIKASI',
            'nama' => 'Uji Bypass Klasifikasi',
            'kelompok' => 'Nonaktif',
        ]);
        $employee = Employee::factory()->create();
        DB::table('employees')->where('id', $employee->id)->update([
            'status_pegawai_id' => $status->id,
        ]);
        $request = Request::create('/uji-bypass-klasifikasi', 'POST');
        $request->setUserResolver(fn (): User => $user);

        $this->actingAs($user);

        try {
            app(UpdateReferenceItemAction::class)->execute($status, [
                'kode' => $status->kode,
                'nama' => $status->nama,
                'kelompok' => 'Aktif',
                'keterangan' => 'Mencoba melewati FormRequest.',
            ], $request);
            $this->fail('Action wajib menolak perpindahan klasifikasi status yang sudah dipakai.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('kelompok', $exception->errors());
        }

        $this->assertSame('Nonaktif', $status->refresh()->kelompok);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_action_mengunci_kode_dan_nama_seluruh_status_inti(): void
    {
        $user = User::factory()->superAdmin()->create();
        $request = $this->referenceRequest($user);
        $this->actingAs($user);

        foreach ([
            'AKTIF',
            'NONAKTIF',
            'PENSIUN',
            'MUTASI',
            'CLTN',
            'PERPANJANGAN_CLTN',
            'TUGAS_BELAJAR',
            'PEMBERHENTIAN_SEMENTARA',
            'WAJIB_MILITER',
            'HILANG',
        ] as $kode) {
            $status = RefStatusPegawai::query()->where('kode', $kode)->firstOrFail();

            $kodeError = $this->executeStatusUpdateExpectingValidation($status, [
                'kode' => $kode.'_UBAH',
            ], $request);
            $this->assertArrayHasKey('kode', $kodeError->errors());

            $namaError = $this->executeStatusUpdateExpectingValidation($status->refresh(), [
                'nama' => $status->nama.' Ubah',
            ], $request);
            $this->assertArrayHasKey('nama', $namaError->errors());

            $this->assertSame($kode, $status->refresh()->kode);
        }
    }

    public function test_action_menolak_laundering_identitas_status_inti_sebelum_reklasifikasi(): void
    {
        $user = User::factory()->superAdmin()->create();
        $request = $this->referenceRequest($user);
        $status = RefStatusPegawai::query()->where('kode', 'HILANG')->firstOrFail();
        $this->actingAs($user);

        $identityError = $this->executeStatusUpdateExpectingValidation($status, [
            'kode' => 'CUSTOM_HILANG',
            'nama' => 'Custom Hilang',
        ], $request);

        $this->assertArrayHasKey('kode', $identityError->errors());
        $this->assertArrayHasKey('nama', $identityError->errors());
        $this->assertSame('HILANG', $status->refresh()->kode);
        $this->assertSame('PNS Dinyatakan Hilang', $status->nama);

        $classificationError = $this->executeStatusUpdateExpectingValidation($status, [
            'kelompok' => 'Aktif',
        ], $request);
        $this->assertArrayHasKey('kelompok', $classificationError->errors());
        $this->assertSame('Nonaktif/khusus', $status->refresh()->kelompok);
    }

    public function test_action_membedakan_kelompok_absen_dari_kelompok_invalid(): void
    {
        $user = User::factory()->superAdmin()->create();
        $request = $this->referenceRequest($user);
        $status = RefStatusPegawai::query()->where('kode', 'HILANG')->firstOrFail();
        $this->actingAs($user);

        app(UpdateReferenceItemAction::class)->execute($status, [
            'keterangan' => 'Metadata tanpa payload kelompok tetap diizinkan.',
        ], $request);
        $this->assertSame('Metadata tanpa payload kelompok tetap diizinkan.', $status->refresh()->keterangan);

        foreach ([null, ['Aktif']] as $invalidGroup) {
            $exception = $this->executeStatusUpdateExpectingValidation($status, [
                'kelompok' => $invalidGroup,
            ], $request);

            $this->assertArrayHasKey('kelompok', $exception->errors());
        }

        $this->assertSame('Nonaktif/khusus', $status->refresh()->kelompok);
    }

    public function test_form_request_dan_action_memakai_pesan_validasi_status_yang_sama(): void
    {
        $user = User::factory()->superAdmin()->create();
        $request = $this->referenceRequest($user);
        $status = RefStatusPegawai::query()->where('kode', 'HILANG')->firstOrFail();
        $this->actingAs($user);

        $identityException = $this->executeStatusUpdateExpectingValidation($status, [
            'kode' => 'HILANG_UBAH',
        ], $request);
        $identityMessage = $identityException->errors()['kode'][0];

        $this->postWithCsrf(route('data-master.status-pegawai.update', $status), [
            'kode' => 'HILANG_UBAH',
            'nama' => $status->nama,
            'kelompok' => $status->kelompok,
            'keterangan' => $status->keterangan,
        ])->assertSessionHasErrors(['kode' => $identityMessage]);

        $groupException = $this->executeStatusUpdateExpectingValidation($status, [
            'kelompok' => null,
        ], $request);
        $groupMessage = $groupException->errors()['kelompok'][0];

        $this->postWithCsrf(route('data-master.status-pegawai.update', $status), [
            'kode' => $status->kode,
            'nama' => $status->nama,
            'kelompok' => null,
            'keterangan' => $status->keterangan,
        ])->assertSessionHasErrors(['kelompok' => $groupMessage]);
    }

    public function test_status_custom_belum_dipakai_boleh_berpindah_klasifikasi(): void
    {
        $user = User::factory()->superAdmin()->create();
        $status = RefStatusPegawai::create([
            'kode' => 'CUSTOM_BEBAS',
            'nama' => 'Custom Bebas',
            'kelompok' => 'Nonaktif',
        ]);
        $this->actingAs($user);

        app(UpdateReferenceItemAction::class)->execute($status, [
            'kelompok' => ' aktif/KHUSUS ',
        ], $this->referenceRequest($user));

        $this->assertSame('Aktif/khusus', $status->refresh()->kelompok);
    }

    public function test_collision_case_identitas_kanonis_tetap_dianggap_status_custom(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        foreach ([
            [
                'kode' => 'aktif',
                'nama' => 'Status Aktif Lowercase',
                'kode_baru' => 'aktif-custom',
                'nama_baru' => 'Status Aktif Lowercase Diperbarui',
            ],
            [
                'kode' => 'CUSTOM_NAMA_AKTIF',
                'nama' => 'aktif',
                'kode_baru' => 'CUSTOM_NAMA_AKTIF_UBAH',
                'nama_baru' => 'aktif custom',
            ],
        ] as $identity) {
            $status = RefStatusPegawai::create([
                'kode' => $identity['kode'],
                'nama' => $identity['nama'],
                'kelompok' => 'Nonaktif',
            ]);

            app(UpdateReferenceItemAction::class)->execute($status, [
                'kode' => $identity['kode_baru'],
                'nama' => $identity['nama_baru'],
                'kelompok' => 'Aktif',
            ], $this->referenceRequest($user));

            $this->assertSame($identity['kode_baru'], $status->refresh()->kode);
            $this->assertSame($identity['nama_baru'], $status->nama);
            $this->assertSame('Aktif', $status->kelompok);
        }
    }

    public function test_fk_status_pegawai_menolak_penghapusan_langsung_saat_dipakai_riwayat(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_FK_RIWAYAT',
            'nama' => 'Uji FK Riwayat',
            'kelompok' => 'Nonaktif',
        ]);
        $employee = Employee::factory()->create();
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => 'Uji FK Riwayat',
            'tanggal_efektif' => '2026-01-01',
            'is_latest' => false,
        ]);

        try {
            DB::transaction(function () use ($status): void {
                RefStatusPegawai::query()->whereKey($status->id)->delete();
                self::fail('Basis data harus menolak penghapusan status yang masih dirujuk riwayat.');
            });
        } catch (QueryException) {
            // FK RESTRICT adalah lapisan terakhir saat penghapusan tidak melewati Action.
        }

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
        ]);
    }

    public function test_status_yang_belum_dipakai_dapat_dihapus_permanen(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_HAPUS',
            'nama' => 'Uji Hapus',
            'kelompok' => 'Nonaktif',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_status_pegawai', ['id' => $status->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefStatusPegawai',
            'auditable_id' => $status->id,
        ]);
    }

    public function test_mutasi_status_menghapus_cache_dropdown(): void
    {
        Cache::put('ref.status_pegawai', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'UJI_CACHE',
                'nama' => 'Uji Cache',
                'kelompok' => 'Nonaktif',
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.status_pegawai'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_status(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'BARU',
                'nama' => 'Baru',
                'kelompok' => 'Nonaktif',
            ])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertForbidden();

        $this->assertDatabaseMissing('ref_status_pegawai', ['kode' => 'BARU']);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function referenceRequest(User $user): Request
    {
        $request = Request::create('/uji-kontrol-status', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    /** @param array<string, mixed> $data */
    private function executeStatusUpdateExpectingValidation(
        RefStatusPegawai $status,
        array $data,
        Request $request,
    ): ValidationException {
        try {
            app(UpdateReferenceItemAction::class)->execute($status, $data, $request);
            self::fail('Action wajib menolak mutasi control-plane status yang tidak valid.');
        } catch (ValidationException $exception) {
            return $exception;
        }
    }
}
