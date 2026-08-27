<?php

namespace Tests;

use App\Data\Cuti\ManualExternalApprovalStepData;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Services\Cuti\ManualExternalApprovalChainService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    private const STORAGE_RECOVERY_CLEANUP_CONNECTION = 'pgsql_storage_recovery_test_cleanup';

    protected function setUp(): void
    {
        parent::setUp();

        // Mitigasi race condition pada Windows/Podman bind mount di mana proses eksternal
        // (seperti wkhtmltopdf) mungkin masih memegang file handle untuk sepersekian detik.
        $attempts = 0;
        while (true) {
            try {
                Storage::fake('local');
                Storage::fake('public');
                break;
            } catch (\UnexpectedValueException $e) {
                $attempts++;
                if ($attempts >= 5) {
                    throw $e;
                }
                usleep(100_000); // Tunggu 100ms sebelum mencoba lagi
            }
        }

        $this->registerStorageRecoveryCleanup();
    }

    /**
     * Manifest durability memakai koneksi PostgreSQL kedua sehingga sengaja lolos dari rollback test.
     * Cleanup test juga harus memakai koneksi independen setelah rollback agar worker paralel tetap terisolasi.
     */
    private function registerStorageRecoveryCleanup(): void
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();

        if (! app()->environment('testing')
            || $connection->getDriverName() !== 'pgsql'
            || ! str_contains($database, 'test')) {
            return;
        }

        config([
            'database.connections.'.self::STORAGE_RECOVERY_CLEANUP_CONNECTION => $connection->getConfig(),
        ]);
        DB::purge(self::STORAGE_RECOVERY_CLEANUP_CONNECTION);

        $this->beforeApplicationDestroyed(function (): void {
            try {
                $cleanup = DB::connection(self::STORAGE_RECOVERY_CLEANUP_CONNECTION);
                if ($cleanup->getSchemaBuilder()->hasTable('storage_recovery_tasks')) {
                    $cleanup->table('storage_recovery_tasks')->delete();
                }
            } finally {
                DB::disconnect(self::STORAGE_RECOVERY_CLEANUP_CONNECTION);
            }
        });
    }

    /**
     * Menyediakan reference data yang konsisten bagi test yang membutuhkan relasi master data.
     */
    protected function seedReferenceData(): void
    {
        $this->seed(ReferenceSeeder::class);
    }

    /**
     * Menyediakan permission matrix sebelum test melakukan pemeriksaan otorisasi.
     */
    protected function seedRbac(): void
    {
        $this->seed(RbacSeeder::class);
    }

    /**
     * Membuat dan mengautentikasi user dengan role SIMPEG tanpa mengulang wiring sesi di setiap test.
     */
    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Menyediakan payload chain minimal yang sah bagi producer fixture cuti manual.
     *
     * @return list<array<string, string|null>>
     */
    protected function validManualApprovalPayload(): array
    {
        return [
            [
                'step_type' => LeaveUsageExternalApprovalStep::TYPE_KEPALA_BAGIAN,
                'approver_source' => LeaveUsageExternalApprovalStep::SOURCE_EXTERNAL_OFFICIAL,
                'approver_employee_id' => null,
                'approver_name' => 'Kepala Bagian Fixture',
                'approver_position' => 'Kepala Bagian',
                'approver_institution' => 'Instansi Fixture',
                'acted_on' => '2020-01-01',
                'decision_note' => 'Diketahui untuk fixture pengujian.',
            ],
            [
                'step_type' => LeaveUsageExternalApprovalStep::TYPE_PYBMC,
                'approver_source' => LeaveUsageExternalApprovalStep::SOURCE_EXTERNAL_OFFICIAL,
                'approver_employee_id' => null,
                'approver_name' => 'PYBMC Fixture',
                'approver_position' => 'Pejabat Yang Berwenang Memberikan Cuti',
                'approver_institution' => 'Instansi Fixture',
                'acted_on' => '2020-01-02',
                'decision_note' => 'Disetujui untuk fixture pengujian.',
            ],
        ];
    }

    /** @return list<ManualExternalApprovalStepData> */
    protected function validManualApprovalStepData(): array
    {
        return app(ManualExternalApprovalChainService::class)
            ->normalize($this->validManualApprovalPayload());
    }

    /** Melengkapi raw fixture lama dengan snapshot melalui service domain yang sama dengan production. */
    protected function attachValidManualApprovalSnapshot(LeaveUsageRecord $record): LeaveUsageRecord
    {
        app(ManualExternalApprovalChainService::class)
            ->storeSnapshot($record, $this->validManualApprovalStepData());

        return $record->load('externalApprovalSteps');
    }
}
