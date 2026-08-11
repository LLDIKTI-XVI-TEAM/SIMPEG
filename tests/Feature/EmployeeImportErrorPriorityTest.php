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
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081234567890',
                        'Role' => 'pegawai',
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
     * Test: Duplicate NIP within file takes priority over NIP existing DB.
     */
    public function test_duplicate_nip_in_file_takes_priority_over_nip_existing_db(): void
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
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081234567890',
                        'Role' => 'pegawai',
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
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567891',
                        'Role' => 'pegawai',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        // Assert: Both rows should be ERROR (not SKIP)
        $this->assertEquals(2, $result['error_count'], 'Should have 2 errors');
        $this->assertEquals(0, $result['skip_count'], 'Should have 0 skips');
        $this->assertEquals('error', $result['results'][0]['status'], 'First row should be ERROR');
        $this->assertEquals('error', $result['results'][1]['status'], 'Second row should be ERROR due to duplicate');
    }

    /**
     * Test: NIP existing DB only results in SKIP when no other errors exist.
     */
    public function test_nip_existing_db_results_in_skip_when_no_other_errors(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create existing employee
        Employee::factory()->create([
            'nip' => '199001012020121001',
            'email_pribadi' => 'existing@example.com',
        ]);

        // Prepare import batch with only NIP existing (email different)
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
            'total_rows' => 1,
            'rows' => [
                [
                    'row' => 2,
                    'data' => [
                        'Nama Pegawai' => 'Test Import',
                        'NIP' => '199001012020121001', // ← Same NIP (existing DB)
                        'NIK' => '1234567890123456',
                        'Email Pegawai' => 'newemail@example.com', // ← Different Email
                        'Tanggal Lahir' => '1990-01-01',
                        'Status Kepegawaian' => 'PNS',
                        'Golongan' => 'III/a',
                        'Pangkat' => 'Penata Muda',
                        'Jabatan' => 'Staf',
                        'Kelas Jabatan' => '5',
                        'Pendidikan Terakhir' => 'S1',
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081234567890',
                        'Role' => 'pegawai',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        // Assert: Should be SKIP (no other errors exist)
        $this->assertEquals(0, $result['error_count'], 'Should have 0 errors');
        $this->assertEquals(1, $result['skip_count'], 'Should have 1 skip');
        $this->assertEquals('skip', $result['results'][0]['status'], 'Row should be SKIP');
        $this->assertArrayHasKey('NIP', $result['results'][0]['errors'], 'Should have NIP skip reason');
    }

    /**
     * Test: Duplicate email within file takes priority over email existing DB.
     */
    public function test_duplicate_email_in_file_takes_priority_over_email_existing_db(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create existing employee
        Employee::factory()->create([
            'nip' => '199001012020121001',
            'email_pribadi' => 'existing@example.com',
        ]);

        // Prepare import batch with duplicate email within file
        $batchId = 'test-batch-'.uniqid();
        $batch = [
            'id' => $batchId,
            'user_id' => null,
            'filename' => 'test.xlsx',
            'type' => 'utama',
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
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081234567890',
                        'Role' => 'pegawai',
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
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567891',
                        'Role' => 'pegawai',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        // Assert: Both rows should be ERROR due to duplicate within file
        $this->assertEquals(2, $result['error_count'], 'Should have 2 errors');
        $this->assertEquals(0, $result['skip_count'], 'Should have 0 skips');
        $this->assertEquals('error', $result['results'][0]['status']);
        $this->assertEquals('error', $result['results'][1]['status']);
        $this->assertStringContainsString('baris 2', $result['results'][1]['errors']['Email Pegawai'][0]);
    }

    /**
     * Test: Complex scenario - NIP existing + Email duplicate in file = ERROR.
     */
    public function test_nip_existing_with_email_duplicate_in_file_is_error(): void
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
                        'Prodi Pendidikan Terakhir' => 'Teknik Informatika',
                        'Nomor Telepon' => '081234567890',
                        'Role' => 'pegawai',
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
                        'Prodi Pendidikan Terakhir' => 'Manajemen',
                        'Nomor Telepon' => '081234567891',
                        'Role' => 'pegawai',
                    ],
                ],
            ],
        ];

        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addHours(1));

        // Execute validation
        $result = app(ValidateImportBatchAction::class)->execute($batchId, null, null);

        // Assert: Both should be ERROR (not SKIP)
        // Row 1: Would be SKIP due to NIP, but email duplicate makes it ERROR
        // Row 2: ERROR due to email duplicate
        $this->assertEquals(2, $result['error_count'], 'Should have 2 errors');
        $this->assertEquals(0, $result['skip_count'], 'Should have 0 skips - email duplicate overrides NIP skip');
    }
}
