<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Memastikan endpoint impor lama mempertahankan prioritas konflik yang sama dengan alur impor utama. */
class LegacyEmployeeImportKus02Test extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_ENDPOINT = '/api/v1/pegawai/import';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    /** NIP yang telah ada dilewati tanpa menggandakan data pegawai. */
    public function test_legacy_endpoint_skips_nip_existing_in_database(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Pre-create employee with same NIP as first row
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);

        // Import file with 2 rows, first row has duplicate NIP
        $csv = $this->buildCsv([
            ['Budi Santoso', 'budi@example.com', '198001012006041001'], // <- NIP exists in DB
            ['Siti Aminah', 'siti@example.com', '198502122010042002'],
        ]);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        // Should succeed with 1 inserted and 1 skipped
        $response->assertOk();
        $response->assertJsonPath('inserted', 1);  // Only Siti inserted
        $response->assertJsonPath('skipped', 1);    // Budi skipped (NIP exists)
        $response->assertJsonPath('failed', 0);

        // Verify only Siti was inserted
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Siti',
            'nip' => '198502122010042002',
        ]);

        // Original employee still exists, no duplicate
        $this->assertDatabaseCount('employees', 3); // Aktor autentikasi + 1 original + 1 new.
    }

    /** Duplikasi NIP dalam satu berkas harus menggagalkan impor atomik. */
    public function test_legacy_endpoint_rejects_duplicate_nip_within_file(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // Two rows with same NIP
        $csv = $this->buildCsv([
            ['Budi Santoso', 'budi@example.com', '198001012006041001'],
            ['Budi Duplikat', 'budi2@example.com', '198001012006041001'], // <- Same NIP
        ]);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        // Should fail because of in-file duplicate
        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('skipped', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.errors.nip.0', 'NIP sudah ada pada baris 2.');

        // No rows inserted (all-or-nothing)
        $this->assertDatabaseCount('employees', 1); // Hanya aktor autentikasi.
    }

    /** Email terdaftar harus ditolak karena dapat menunjuk pegawai berbeda. */
    public function test_legacy_endpoint_rejects_email_existing_in_database(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Pre-create employee with same email
        Employee::factory()->create(['email_pribadi' => 'budi@example.com']);

        $this->actingAs($user);

        $csv = $this->buildCsv([
            ['Budi Santoso', 'budi@example.com', '198001012006041001'], // <- Email exists
        ]);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        // Should fail (not skip)
        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.errors.email_pribadi.0', 'Email pegawai sudah terdaftar di database.');

        // No new employees created
        $this->assertDatabaseCount('employees', 2); // Aktor autentikasi + original.
    }

    /** Email pegawai nonaktif tetap merupakan identitas yang tidak boleh dipakai ulang. */
    public function test_legacy_endpoint_rejects_email_owned_by_soft_deleted_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $inactiveEmployee = Employee::factory()->create([
            'email_pribadi' => 'budi@example.com',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($this->buildCsv([
                ['Budi Santoso', 'BUDI@example.com', '198001012006041001'],
            ])),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath(
            'errors.0.errors.email_pribadi.0',
            'Email pegawai sudah terdaftar di database.',
        );
        $this->assertSame(2, Employee::count());
    }

    /** NIP pegawai nonaktif tetap diperlakukan sebagai baris lama yang dilewati. */
    public function test_legacy_endpoint_skips_nip_owned_by_soft_deleted_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $inactiveEmployee = Employee::factory()->create([
            'nip' => '198001012006041001',
            'email_pribadi' => 'arsip@example.com',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($this->buildCsv([
                ['Budi Santoso', 'budi@example.com', '198001012006041001'],
            ])),
        ]);

        $response->assertOk();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('skipped', 1);
        $response->assertJsonPath('failed', 0);
        $this->assertSame(2, Employee::count());
    }

    /**
     * Error email tidak boleh tertutup oleh status skip saat NIP pada baris yang sama sudah terdaftar.
     */
    public function test_legacy_endpoint_prioritizes_existing_email_error_over_existing_nip_skip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'nip' => '198001012006041001',
            'email_pribadi' => 'budi@example.com',
        ]);

        $this->actingAs($user);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($this->buildCsv([
                ['Budi Santoso', 'budi@example.com', '198001012006041001'],
            ])),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('skipped', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath(
            'errors.0.errors.email_pribadi.0',
            'Email pegawai sudah terdaftar di database.',
        );
        $this->assertDatabaseCount('employees', 2);
    }

    /** Duplikasi NIP dalam berkas harus tetap dilaporkan walau NIP tersebut telah ada. */
    public function test_legacy_endpoint_prioritizes_infile_duplicate_over_database_duplicate(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Pre-create employee with same NIP
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);

        // Create file where row 1 and row 2 both have the same NIP (which also exists in DB)
        $csv = $this->buildCsv([
            ['Budi Santoso', 'budi@example.com', '198001012006041001'],
            ['Budi Duplikat', 'budi2@example.com', '198001012006041001'],
        ]);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        // Should fail because row 2 has in-file duplicate (even though NIP exists in DB)
        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.row', 3); // Row 2 (after header)
        $response->assertJsonPath('errors.0.errors.nip.0', 'NIP sudah ada pada baris 2.');

        // No new employees created
        $this->assertDatabaseCount('employees', 2); // Aktor autentikasi + original.
    }

    /** Satu error harus menggagalkan seluruh impor meski baris lain valid atau dapat dilewati. */
    public function test_legacy_endpoint_handles_mixed_skip_and_error_scenarios(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Pre-create employees
        Employee::factory()->create(['nip' => '198001012006041001']);
        Employee::factory()->create(['email_pribadi' => 'conflict@example.com']);

        $this->actingAs($user);

        // Row 1: NIP exists in DB → should skip
        // Row 2: Email exists in DB → should error
        // Row 3: Valid → should insert
        $csv = $this->buildCsv([
            ['Budi Santoso', 'budi@example.com', '198001012006041001'],        // Skip (NIP exists)
            ['Ahmad Yani', 'conflict@example.com', '198101012007041001'],      // Error (email exists)
            ['Siti Aminah', 'siti@example.com', '198502122010042002'],          // Valid
        ]);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        // Should fail because row 2 has error (all-or-nothing)
        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('skipped', 1);    // Row 1 skipped
        $response->assertJsonPath('failed', 1);      // Row 2 failed
        $response->assertJsonPath('message', 'Import gagal. Perbaiki baris bermasalah lalu unggah ulang. 1 baris dilewati karena NIP sudah terdaftar.');

        // All-or-nothing: no new rows inserted because of error
        $this->assertDatabaseCount('employees', 3); // Aktor autentikasi + 2 original.
    }

    /**
     * Successful scenario: all rows either valid or skip (no errors)
     */
    public function test_legacy_endpoint_succeeds_when_only_valid_and_skip_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Pre-create employee
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);

        // Row 1: NIP exists → skip
        // Row 2: Valid → insert
        $csv = $this->buildCsv([
            ['Budi Santoso', 'budi@example.com', '198001012006041001'],    // Skip
            ['Siti Aminah', 'siti@example.com', '198502122010042002'],      // Valid
        ]);

        $response = $this->postJsonWithCsrf(self::LEGACY_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        // Should succeed
        $response->assertOk();
        $response->assertJsonPath('inserted', 1);
        $response->assertJsonPath('skipped', 1);
        $response->assertJsonPath('failed', 0);

        // Only Siti inserted
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Siti',
            'nip' => '198502122010042002',
        ]);
        $this->assertDatabaseCount('employees', 3); // Aktor autentikasi + 1 original + 1 new.
    }

    private function buildCsv(array $rows): string
    {
        $headers = [
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
            'Role',
        ];

        $csv = implode(',', $headers)."\n";

        foreach ($rows as $row) {
            $fullRow = [
                $row[0],                    // Nama Pegawai
                $row[1],                    // Email Pegawai
                'III/a',                    // Golongan
                'Analis Kepegawaian',       // Jabatan
                '7',                        // Kelas Jabatan
                $row[2],                    // NIP
                '081234567890',             // Nomor Telepon
                'Penata Muda',              // Pangkat
                'S1',                       // Pendidikan Terakhir
                '2038-01-01',               // Pensiun
                explode(' ', $row[0])[0],   // Person (first name)
                explode(' ', $row[0])[0],   // Person Formula
                'Manajemen',                // Prodi Pendidikan Terakhir
                'PNS',                      // Status Kepegawaian
                '1980-01-01',               // Tanggal Lahir
                'pegawai',                  // Role
            ];

            $csv .= implode(',', $fullRow)."\n";
        }

        return $csv;
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
