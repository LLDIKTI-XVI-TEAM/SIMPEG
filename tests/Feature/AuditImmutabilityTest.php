<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableAuditLogException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_audit_log_menolak_pembaruan_lewat_aplikasi(): void
    {
        $log = $this->auditLog(['user_name' => 'Nama Asli']);

        try {
            $log->update(['user_name' => 'Nama Diubah']);
            $this->fail('Pembaruan audit log wajib ditolak.');
        } catch (ImmutableAuditLogException) {
            $this->assertDatabaseHas('audit_logs', [
                'id' => $log->id,
                'user_name' => 'Nama Asli',
            ]);
        }
    }

    public function test_audit_log_menolak_penghapusan_lewat_aplikasi(): void
    {
        $log = $this->auditLog();

        try {
            $log->delete();
            $this->fail('Penghapusan audit log wajib ditolak.');
        } catch (ImmutableAuditLogException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        }
    }

    public function test_audit_log_menolak_pembaruan_lewat_force_fill(): void
    {
        $log = $this->auditLog();

        try {
            $log->forceFill(['created_at' => Carbon::parse('2020-01-01 00:00:00')])->save();
            $this->fail('Pembaruan audit log lewat force fill wajib ditolak.');
        } catch (ImmutableAuditLogException) {
            $this->assertNotSame(
                '2020-01-01 00:00:00',
                (string) DB::table('audit_logs')->where('id', $log->id)->value('created_at'),
            );
        }
    }

    public function test_nomor_induk_dan_nomor_kartu_keluarga_tidak_tersimpan_utuh_pada_audit(): void
    {
        AuditService::log(
            'UPDATE',
            'Employee',
            (string) Str::uuid(),
            ['nik' => '3171234567890123', 'no_kk' => '3171999888777666', 'nama_lengkap' => 'Sebelum'],
            ['nik' => '3171234567890999', 'no_kk' => '3171999888777000', 'nama_lengkap' => 'Sesudah'],
        );

        $log = AuditLog::query()->sole();

        $this->assertSame('************0123', $log->old_values['nik']);
        $this->assertSame('************0999', $log->new_values['nik']);
        $this->assertSame('************7666', $log->old_values['no_kk']);
        $this->assertSame('************7000', $log->new_values['no_kk']);
        $this->assertSame('Sebelum', $log->old_values['nama_lengkap']);
        $this->assertSame('Sesudah', $log->new_values['nama_lengkap']);
    }

    public function test_pembaruan_pegawai_tidak_meninggalkan_nomor_induk_terang_di_audit(): void
    {
        $nik = '3171234567890123';
        $noKk = '3171999888777666';
        $employee = Employee::factory()->create(['nik' => $nik, 'no_kk' => $noKk]);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin);
        $response = $this->withSession(['_token' => 'test-token'])->putJson(
            "/api/v1/pegawai/{$employee->id}",
            $this->payloadPembaruan($employee),
            ['X-CSRF-TOKEN' => 'test-token'],
        );
        $response->assertOk();

        // Audit bersifat append-only sehingga nomor identitas yang lolos ke payload tidak akan
        // dapat dihapus lagi. Karena itu isi seluruh tabel diperiksa, bukan hanya baris terakhir.
        $isiAudit = DB::table('audit_logs')
            ->select('old_values', 'new_values')
            ->get()
            ->flatMap(fn (object $row): array => [$row->old_values, $row->new_values])
            ->implode(' ');

        $this->assertStringNotContainsString($nik, $isiAudit);
        $this->assertStringNotContainsString($noKk, $isiAudit);
        // Hash NIK ikut disamarkan karena nomor induk memiliki pola tetap sehingga nilai hash
        // dapat dicocokkan kembali ke pemiliknya dengan pencarian yang tidak mahal.
        $this->assertStringNotContainsString(hash('sha256', $nik), $isiAudit);
    }

    public function test_basis_data_menolak_pembaruan_audit_yang_melewati_model(): void
    {
        $this->lewatiJikaBukanPostgres();
        $log = $this->auditLog(['user_name' => 'Nama Asli']);

        try {
            // Penolakan dari basis data membatalkan transaksi, karena itu percobaannya dijalankan
            // di dalam transaksi bersarang supaya transaksi milik test tetap dapat dipakai.
            DB::transaction(function () use ($log): void {
                DB::table('audit_logs')->where('id', $log->id)->update(['user_name' => 'Nama Diubah']);
            });
            $this->fail('Pembaruan audit lewat query builder wajib ditolak basis data.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
            $this->assertSame('Nama Asli', DB::table('audit_logs')->where('id', $log->id)->value('user_name'));
        }
    }

    public function test_basis_data_menolak_penghapusan_audit_yang_melewati_model(): void
    {
        $this->lewatiJikaBukanPostgres();
        $log = $this->auditLog();

        try {
            DB::transaction(function () use ($log): void {
                DB::table('audit_logs')->where('id', $log->id)->delete();
            });
            $this->fail('Penghapusan audit lewat query builder wajib ditolak basis data.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
            $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        }
    }

    public function test_basis_data_menolak_pembaruan_audit_yang_melewati_event_model(): void
    {
        $this->lewatiJikaBukanPostgres();
        $log = $this->auditLog(['user_name' => 'Nama Asli']);

        // saveQuietly melewati event model, sehingga penolakan hanya dapat datang dari basis data.
        try {
            DB::transaction(function () use ($log): void {
                $log->user_name = 'Nama Diubah';
                $log->saveQuietly();
            });
            $this->fail('Penyimpanan senyap pada audit wajib ditolak basis data.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
            $this->assertSame('Nama Asli', DB::table('audit_logs')->where('id', $log->id)->value('user_name'));
        }
    }

    public function test_basis_data_tetap_mengizinkan_penambahan_baris_audit(): void
    {
        $this->lewatiJikaBukanPostgres();
        $this->auditLog();

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'user_name' => 'Penulis Kedua',
            'event' => 'CREATE',
            'auditable_type' => 'Employee',
            'auditable_id' => (string) Str::uuid(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ]);

        $this->assertSame(2, AuditLog::query()->count());
    }

    private function lewatiJikaBukanPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penjaga tingkat basis data hanya dipasang pada PostgreSQL.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function auditLog(array $overrides = []): AuditLog
    {
        return AuditLog::query()->create(array_merge([
            'user_id' => null,
            'user_name' => 'Petugas Uji',
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => (string) Str::uuid(),
            'old_values' => ['nama_lengkap' => 'Sebelum'],
            'new_values' => ['nama_lengkap' => 'Sesudah'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadPembaruan(Employee $employee): array
    {
        return [
            'nama_lengkap' => $employee->nama_lengkap.' Diperbarui',
            'email_pribadi' => $employee->email_pribadi,
            'golongan_terakhir' => $employee->golongan_terakhir,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'kelas_jabatan_terakhir' => $employee->kelas_jabatan_terakhir,
            'nip' => $employee->nip,
            'no_hp' => $employee->no_hp,
            'pangkat_terakhir' => $employee->pangkat_terakhir,
            'pendidikan_terakhir' => $employee->pendidikan_terakhir,
            'tanggal_pensiun' => $employee->tanggal_pensiun
                ? Carbon::parse($employee->tanggal_pensiun)->format('Y-m-d')
                : null,
            'prodi_pendidikan_terakhir' => $employee->prodi_pendidikan_terakhir,
            'jenis_pegawai_id' => $employee->jenis_pegawai_id
                ?: RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail()->id,
            'tanggal_lahir' => Carbon::parse($employee->tanggal_lahir)->format('Y-m-d'),
        ];
    }
}
