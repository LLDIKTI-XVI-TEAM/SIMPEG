<?php

namespace Tests\Feature;

use App\Actions\Employees\UploadImportBatchAction;
use App\Actions\Employees\ValidateImportBatchAction;
use App\Models\Employee;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test suite for Employee Import Error Priority (K-US-02)
 *
 * These tests verify error priority handling in import validation.
 * Currently marked as @group skip because they require implementation
 * of complex error priority logic that differs from single-pass validation.
 *
 * @group skip
 * @group enhancement
 * @group error-priority
 */
class EmployeeImportErrorPriorityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: K-US-02 - Email existing DB takes priority over NIP existing DB.
     *
     * Issue: ValidateImportBatchAction returned "skip" for rows with NIP existing + email existing,
     * when it should return "error" because email existing is higher priority.
     *
     * K-US-02 Priority:
     * 1. Duplicate NIP/Email within file → ERROR (highest)
     * 2. Email existing in DB → ERROR
     * 3. NIP existing in DB → SKIP (lowest, only if no other errors)
     *
     * @group skip
     * @group enhancement
     */
    /** Email terdaftar tidak boleh tertutupi outcome skip dari NIP yang sudah ada. */
    public function test_email_existing_db_takes_priority_over_nip_existing_db(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create existing employee with NIP and Email
        Employee::factory()->create([
            'nip' => '199001012020121001',
            'email_pribadi' => 'existing@example.com',
            'nama_lengkap' => 'Existing Employee',
        ]);

        // Prepare import batch with same NIP and same Email
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
            'headers' => $this->importHeaders(),
            'total_rows' => 1,
            'rows' => [
                [
                    'row' => 2,
                    'data' => [
                        'Nama Pegawai' => 'Test Import',
                        'NIP' => '199001012020121001', // ← Same NIP (existing DB)
                        'NIK' => '1234567890123456',
                        'Email Pegawai' => 'existing@example.com', // ← Same Email (existing DB)
                        'Tanggal Lahir' => '1990-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/a',
                        'Pangkat' => 'Penata Muda',
                        'Jabatan' => 'Staf',
                        'Kelas Jabatan' => '5',
                        'Pendidikan Terakhir' => 'S1',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567890',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        // Assert: Should be ERROR (not SKIP) because email existing is higher priority
        $this->assertEquals(1, $result['error_count'], 'Should have 1 error');
        $this->assertEquals(0, $result['skip_count'], 'Should have 0 skips');
        $this->assertEquals('error', $result['results'][0]['status'], 'Row should be ERROR, not SKIP');
        $this->assertArrayHasKey('Email Pegawai', $result['results'][0]['errors'], 'Should have email error');
    }

    /**
     * Semua kemunculan NIP yang sama menjadi error ketika duplikasi berkas ditemukan.
     */
    public function test_second_duplicate_nip_in_file_is_error_when_first_nip_exists_in_database(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create existing employee
        Employee::factory()->create([
            'nip' => '199001012020121001',
            'email_pribadi' => 'existing@example.com',
        ]);

        // Prepare import batch with duplicate NIP within file
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
            'headers' => $this->importHeaders(),
            'total_rows' => 2,
            'rows' => [
                [
                    'row' => 2,
                    'data' => [
                        'Nama Pegawai' => 'First Entry',
                        'NIP' => '199001012020121001', // ← First occurrence (also in DB)
                        'NIK' => '1234567890123456',
                        'Email Pegawai' => 'first@example.com',
                        'Tanggal Lahir' => '1990-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/a',
                        'Pangkat' => 'Penata Muda',
                        'Jabatan' => 'Staf',
                        'Kelas Jabatan' => '5',
                        'Pendidikan Terakhir' => 'S1',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567890',
                    ],
                ],
                [
                    'row' => 3,
                    'data' => [
                        'Nama Pegawai' => 'Second Entry',
                        'NIP' => '199001012020121001', // ← Duplicate within file
                        'NIK' => '9876543210123456',
                        'Email Pegawai' => 'second@example.com',
                        'Tanggal Lahir' => '1991-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/b',
                        'Pangkat' => 'Penata Muda Tingkat I',
                        'Jabatan' => 'Staf Senior',
                        'Kelas Jabatan' => '6',
                        'Pendidikan Terakhir' => 'S2',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081298765432',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        $this->assertSame(2, $result['error_count']);
        $this->assertSame(0, $result['skip_count']);
        $this->assertSame('error', $result['results'][0]['status']);
        $this->assertSame('error', $result['results'][1]['status']);
        $this->assertArrayHasKey('NIP', $result['results'][1]['errors']);
    }

    /**
     * Semua kemunculan NIP baru yang sama menjadi error ketika duplikasi berkas ditemukan.
     */
    public function test_second_duplicate_new_nip_in_file_is_error_after_first_valid_row(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Siapkan dua kemunculan NIP baru yang sama dalam berkas.
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
            'headers' => $this->importHeaders(),
            'total_rows' => 2,
            'rows' => [
                [
                    'row' => 2,
                    'data' => [
                        'Nama Pegawai' => 'Test Import',
                        'NIP' => '199004012020121004',
                        'NIK' => '1234567890123456',
                        'Email Pegawai' => 'newemail@example.com', // ← Different Email
                        'Tanggal Lahir' => '1990-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/a',
                        'Pangkat' => 'Penata Muda',
                        'Jabatan' => 'Staf',
                        'Kelas Jabatan' => '5',
                        'Pendidikan Terakhir' => 'S1',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567890',
                    ],
                ],
                [
                    'row' => 3,
                    'data' => [
                        'Nama Pegawai' => 'Second Import',
                        'NIP' => '199004012020121004',
                        'NIK' => '9876543210123456',
                        'Email Pegawai' => 'second-new-nip@example.com',
                        'Tanggal Lahir' => '1991-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/b',
                        'Pangkat' => 'Penata Muda Tingkat I',
                        'Jabatan' => 'Staf Senior',
                        'Kelas Jabatan' => '6',
                        'Pendidikan Terakhir' => 'S2',
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081298765432',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(2, $result['error_count']);
        $this->assertSame(0, $result['skip_count']);
        $this->assertSame('error', $result['results'][0]['status']);
        $this->assertSame('error', $result['results'][1]['status']);
        $this->assertArrayHasKey('NIP', $result['results'][1]['errors']);
    }

    /**
     * Semua kemunculan email yang sama menjadi error ketika duplikasi berkas ditemukan.
     */
    public function test_second_duplicate_email_in_file_is_error_after_first_valid_row(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Siapkan dua NIP baru dengan email yang sama dalam berkas.
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
            'headers' => $this->importHeaders(),
            'total_rows' => 2,
            'rows' => [
                [
                    'row' => 2,
                    'data' => [
                        'Nama Pegawai' => 'First Entry',
                        'NIP' => '199002012020121002',
                        'NIK' => '1234567890123456',
                        'Email Pegawai' => 'duplicate@example.com', // ← First occurrence
                        'Tanggal Lahir' => '1990-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/a',
                        'Pangkat' => 'Penata Muda',
                        'Jabatan' => 'Staf',
                        'Kelas Jabatan' => '5',
                        'Pendidikan Terakhir' => 'S1',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567890',
                    ],
                ],
                [
                    'row' => 3,
                    'data' => [
                        'Nama Pegawai' => 'Second Entry',
                        'NIP' => '199003012020121003',
                        'NIK' => '9876543210123456',
                        'Email Pegawai' => 'duplicate@example.com', // ← Duplicate within file
                        'Tanggal Lahir' => '1991-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/b',
                        'Pangkat' => 'Penata Muda Tingkat I',
                        'Jabatan' => 'Staf Senior',
                        'Kelas Jabatan' => '6',
                        'Pendidikan Terakhir' => 'S2',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081298765432',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        $this->assertSame(0, $result['valid_count']);
        $this->assertSame(2, $result['error_count']);
        $this->assertSame(0, $result['skip_count']);
        $this->assertSame('error', $result['results'][0]['status']);
        $this->assertSame('error', $result['results'][1]['status']);
        $this->assertStringContainsString('baris 2', $result['results'][1]['errors']['Email Pegawai'][0]);
    }

    /**
     * NIP database dan email duplikat membuat kedua baris berstatus error.
     */
    public function test_nip_existing_with_later_duplicate_email_in_file_keeps_occurrence_semantics(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create existing employee
        Employee::factory()->create([
            'nip' => '199001012020121001',
            'email_pribadi' => 'existing@example.com',
        ]);

        // Prepare batch: Row 1 has existing NIP + unique email, Row 2 has new NIP + duplicate email
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
            'headers' => $this->importHeaders(),
            'total_rows' => 2,
            'rows' => [
                [
                    'row' => 2,
                    'data' => [
                        'Nama Pegawai' => 'First Entry',
                        'NIP' => '199001012020121001', // ← Existing in DB
                        'NIK' => '1234567890123456',
                        'Email Pegawai' => 'shared@example.com', // ← First occurrence
                        'Tanggal Lahir' => '1990-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/a',
                        'Pangkat' => 'Penata Muda',
                        'Jabatan' => 'Staf',
                        'Kelas Jabatan' => '5',
                        'Pendidikan Terakhir' => 'S1',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567890',
                    ],
                ],
                [
                    'row' => 3,
                    'data' => [
                        'Nama Pegawai' => 'Second Entry',
                        'NIP' => '199002012020121002', // ← New NIP
                        'NIK' => '9876543210123456',
                        'Email Pegawai' => 'shared@example.com', // ← Duplicate email
                        'Tanggal Lahir' => '1991-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/b',
                        'Pangkat' => 'Penata Muda Tingkat I',
                        'Jabatan' => 'Staf Senior',
                        'Kelas Jabatan' => '6',
                        'Pendidikan Terakhir' => 'S2',
                        'Role' => 'pegawai',
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081298765432',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        $this->assertSame(2, $result['error_count']);
        $this->assertSame(0, $result['skip_count']);
        $this->assertSame('error', $result['results'][0]['status']);
        $this->assertSame('error', $result['results'][1]['status']);
        $this->assertArrayHasKey('Email Pegawai', $result['results'][1]['errors']);
    }

    /** @return list<string> */
    private function importHeaders(): array
    {
        return [
            'Nama Pegawai',
            'NIP',
            'NIK',
            'Email Pegawai',
            'Tanggal Lahir',
            'Status Kepegawaian',
            'Golongan',
            'Pangkat',
            'Jabatan',
            'Kelas Jabatan',
            'Pendidikan Terakhir',
            'Prodi Pendidikan Terakhir',
            'Nomor Telepon',
        ];
    }
}
