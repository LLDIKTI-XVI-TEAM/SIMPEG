<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Permission;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeAttachmentLinkPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Storage::fake(Document::STORAGE_DISK);
    }

    /** @return array<string, array{string}> */
    public static function detailSurfaces(): array
    {
        return [
            'detail RBAC' => ['rbac'],
            'detail Pimpinan' => ['pimpinan'],
        ];
    }

    #[DataProvider('detailSurfaces')]
    public function test_pencabutan_hak_dokumen_menghilangkan_tautan_tanpa_menghilangkan_metadata_riwayat(string $surface): void
    {
        [$employee, $rank, $appointment, $discipline, $status] = $this->attachmentFixture();
        $viewer = User::factory()->pimpinan()->create();
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $documentPermission = Permission::where('name', 'dokumen_sk.read')->firstOrFail();
        $role->permissions()->detach($documentPermission->id);

        $urls = $this->attachmentUrls($surface, $employee, $rank, $appointment, $discipline, $status);
        $response = $this->actingAs($viewer)
            ->get(route($surface.'.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('SK-PANGKAT-PERMISSION')
            ->assertSee('SK-PENGANGKATAN-PERMISSION')
            ->assertSee('Disiplin metadata tetap tersedia')
            ->assertSee('Status metadata tetap tersedia');

        $this->assertTrue($viewer->hasPermission('employee_histories.read'));
        $this->assertTrue($viewer->hasPermission('discipline_records.read'));
        $this->assertFalse($viewer->hasPermission('dokumen_sk.read'));
        /** @var Employee $presented */
        $presented = $response->viewData('p');
        foreach ($this->presentedAttachmentUrls($surface, $presented) as $url) {
            $this->assertNull($url, 'Hak membaca metadata riwayat tidak memberikan hak tautan berkas.');
        }
        foreach ($urls as $url) {
            $response->assertDontSee($url, false);
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
    }

    #[DataProvider('detailSurfaces')]
    public function test_grant_dokumen_membuka_unduhan_dan_revoke_berikutnya_kembali_menolak(string $surface): void
    {
        [$employee, $rank, $appointment, $discipline, $status] = $this->attachmentFixture();
        $viewer = User::factory()->pimpinan()->create();
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $documentPermission = Permission::where('name', 'dokumen_sk.read')->firstOrFail();
        $role->permissions()->detach($documentPermission->id);
        $role->permissions()->syncWithoutDetaching([$documentPermission->id]);

        $urls = $this->attachmentUrls($surface, $employee, $rank, $appointment, $discipline, $status);
        $response = $this->actingAs($viewer)->get(route($surface.'.pegawai.show', $employee))->assertOk();
        /** @var Employee $presented */
        $presented = $response->viewData('p');
        $this->assertSame($urls, $this->presentedAttachmentUrls($surface, $presented));
        foreach ($urls as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()->assertDownload();
        }

        $role->permissions()->detach($documentPermission->id);
        $revoked = $this->actingAs($viewer)->get(route($surface.'.pegawai.show', $employee))->assertOk();
        foreach ($this->presentedAttachmentUrls($surface, $revoked->viewData('p')) as $url) {
            $this->assertNull($url, 'Pencabutan permission harus berlaku pada halaman berikutnya.');
        }
        foreach ($urls as $url) {
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
    }

    /** @return array<string, array{string, string}> */
    public static function revokedDomainPermissions(): array
    {
        return [
            'RBAC tanpa histori' => ['rbac', 'employee_histories.read'],
            'RBAC tanpa disiplin' => ['rbac', 'discipline_records.read'],
            'Pimpinan tanpa histori' => ['pimpinan', 'employee_histories.read'],
            'Pimpinan tanpa disiplin' => ['pimpinan', 'discipline_records.read'],
        ];
    }

    #[DataProvider('revokedDomainPermissions')]
    public function test_hak_dokumen_tidak_menggantikan_permission_baca_domain(string $surface, string $revokedPermission): void
    {
        [$employee, $rank, $appointment, $discipline, $status] = $this->attachmentFixture();
        $viewer = User::factory()->pimpinan()->create();
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id,
        ]);
        $role->permissions()->detach(Permission::where('name', $revokedPermission)->firstOrFail()->id);

        $urls = $this->attachmentUrls($surface, $employee, $rank, $appointment, $discipline, $status);
        $response = $this->actingAs($viewer)->get(route($surface.'.pegawai.show', $employee))->assertOk();
        /** @var Employee $presented */
        $presented = $response->viewData('p');
        $this->assertTrue($viewer->hasPermission('dokumen_sk.read'));
        $attribute = $surface.'_attachment_download_url';

        if ($revokedPermission === 'employee_histories.read') {
            $this->assertCount(0, $presented->rankHistories);
            $this->assertNull($presented->appointment);
            $this->assertCount(0, $presented->statusHistories);
            $response->assertDontSee('SK-PANGKAT-PERMISSION')->assertSee('Disiplin metadata tetap tersedia');
            $this->assertSame($urls[2], $presented->disciplineRecords->first()->getAttribute($attribute));
            $allowedIndexes = [2];
        } else {
            $this->assertCount(0, $presented->disciplineRecords);
            $response->assertDontSee('Disiplin metadata tetap tersedia')->assertSee('SK-PANGKAT-PERMISSION');
            $this->assertSame($urls[0], $presented->rankHistories->first()->getAttribute($attribute));
            $allowedIndexes = [0, 1, 3, 4];
        }

        foreach ($urls as $index => $url) {
            $download = $this->actingAs($viewer)->get($url);
            if (in_array($index, $allowedIndexes, true)) {
                $download->assertOk()->assertDownload();
            } else {
                $download->assertForbidden();
            }
        }
    }

    /**
     * Berkas privat nyata pada disk uji memastikan penolakan bukan akibat file hilang.
     *
     * @return array{Employee, RankHistory, Appointment, DisciplineRecord, EmployeeStatusHistory}
     */
    private function attachmentFixture(): array
    {
        $employee = Employee::factory()->create([
            'status_berkas_path' => 'pegawai/permission-status-snapshot.pdf',
            'status_nomor_berkas' => 'SK-SNAPSHOT-PERMISSION',
        ]);
        $rank = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::firstOrFail()->id,
            'no_sk' => 'SK-PANGKAT-PERMISSION',
            'tmt_pangkat' => '2026-01-01',
            'file_sk' => 'pegawai/permission-rank.pdf',
            'is_latest' => true,
        ]);
        $appointment = Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'no_sk' => 'SK-PENGANGKATAN-PERMISSION',
            'tmt_pengangkatan' => '2020-01-01',
            'file_sk' => 'pegawai/permission-appointment.pdf',
        ]);
        $discipline = DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Disiplin metadata tetap tersedia',
            'no_sk' => 'SK-DISIPLIN-PERMISSION',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_sk' => '2026-01-01',
            'file_sk' => 'pegawai/permission-discipline.pdf',
        ]);
        $status = EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Status metadata tetap tersedia',
            'tanggal_efektif' => '2026-01-01',
            'nomor_berkas' => 'SK-STATUS-PERMISSION',
            'file_sk' => 'pegawai/permission-status-history.pdf',
            'is_latest' => true,
        ]);
        foreach ([$rank->file_sk, $appointment->file_sk, $discipline->file_sk, $status->file_sk, $employee->status_berkas_path] as $path) {
            Storage::disk(Document::STORAGE_DISK)->put($path, 'Berkas privat pengujian permission');
        }

        return [$employee, $rank, $appointment, $discipline, $status];
    }

    /** @return list<string> */
    private function attachmentUrls(string $surface, Employee $employee, RankHistory $rank, Appointment $appointment, DisciplineRecord $discipline, EmployeeStatusHistory $status): array
    {
        return [
            route($surface.'.pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'rank', 'history' => $rank]),
            route($surface.'.pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'appointment', 'history' => $appointment]),
            route($surface.'.pegawai.discipline-attachments.download', ['employee' => $employee, 'history' => $discipline]),
            route($surface.'.pegawai.status-attachments.download', ['employee' => $employee, 'history' => $status]),
            route($surface.'.pegawai.status-attachments.download', ['employee' => $employee, 'history' => $employee]),
        ];
    }

    /** @return list<string|null> */
    private function presentedAttachmentUrls(string $surface, Employee $employee): array
    {
        $attribute = $surface.'_attachment_download_url';

        return [
            $employee->rankHistories->first()->getAttribute($attribute),
            $employee->appointment->getAttribute($attribute),
            $employee->disciplineRecords->first()->getAttribute($attribute),
            $employee->statusHistories->first()->getAttribute($attribute),
            $employee->getAttribute($surface.'_status_attachment_download_url'),
        ];
    }
}
