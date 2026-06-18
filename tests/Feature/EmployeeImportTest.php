<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_import_employees(): void
    {
        $response = $this->postJson('/api/employees/import', [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_import_valid_csv(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/employees/import', [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('inserted', 2);
        $response->assertJsonPath('failed', 0);
        $this->assertDatabaseHas('employees', [
            'nama_pegawai' => 'Budi Santoso',
            'email_pegawai' => 'budi@example.com',
            'created_by' => $user->id,
        ]);
        $this->assertSame('1985-02-12', Employee::where('nama_pegawai', 'Siti Aminah')->firstOrFail()->tanggal_lahir->format('Y-m-d'));
    }

    public function test_import_rejects_missing_header(): void
    {
        $user = User::factory()->create();
        $csv = "Nama Pegawai,Email Pegawai\nBudi,budi@example.com\n";

        $response = $this->actingAs($user)->postJson('/api/employees/import', [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
    }

    public function test_import_reports_row_errors_and_keeps_valid_rows(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);

        $response = $this->actingAs($user)->postJson('/api/employees/import', [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('inserted', 1);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertDatabaseHas('employees', ['nama_pegawai' => 'Siti Aminah']);
    }

    public function test_import_rejects_xlsx_file(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/employees/import', [
            'file' => UploadedFile::fake()->create('pegawai.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
    }

    private function validCsv(): string
    {
        return implode(',', [
            'Nama Pegawai',
            'Email Pegawai',
            'Golongan',
            'Jabatan',
            'Kelas Jabatan',
            'NIP',
            'Nomor Telepon',
            'Pangkat',
            'Pendidikan Terakhir',
            'Pensiun',
            'Person',
            'Person Formula',
            'Prodi Pendidikan Terakhir',
            'Status Kepegawaian',
            'Tanggal Lahir',
        ])."\n".
        implode(',', [
            'Budi Santoso',
            'budi@example.com',
            'III/a',
            'Analis Kepegawaian',
            '7',
            '198001012006041001',
            '081234567890',
            'Penata Muda',
            'S1',
            '2038-01-01',
            'Budi',
            'Budi',
            'Manajemen',
            'PNS',
            '1980-01-01',
        ])."\n".
        implode(',', [
            'Siti Aminah',
            'siti@example.com',
            'III/b',
            'Pranata Komputer',
            '8',
            '198502122010042002',
            '081298765432',
            'Penata Muda Tingkat I',
            'S2',
            '2043-02-12',
            'Siti',
            'Siti',
            'Teknik Informatika',
            'PNS',
            '12/02/1985',
        ])."\n";
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }
}
