<?php

namespace Tests\Concerns;

use App\Models\LeaveRequest;
use App\Services\EmployeeFileStorageService;
use App\Services\StorageRecoveryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Menyediakan fixture lampiran melalui kontrak manifest produksi tanpa meninggalkan row committed. */
trait CreatesAdoptedLeaveAttachment
{
    private const LEAVE_ATTACHMENT_CLEANUP_CONNECTION = 'pgsql_leave_attachment_fixture_cleanup';

    /** @var list<string> */
    private array $leaveAttachmentRecoveryTaskIds = [];

    protected function setUpAdoptedLeaveAttachmentFixtures(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        config([
            'database.connections.'.self::LEAVE_ATTACHMENT_CLEANUP_CONNECTION => DB::connection()->getConfig(),
        ]);
        DB::purge(self::LEAVE_ATTACHMENT_CLEANUP_CONNECTION);

        // Callback berjalan setelah rollback trait database sehingga lease default connection sudah dilepas.
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $taskIds = array_values(array_unique($this->leaveAttachmentRecoveryTaskIds));
                if ($taskIds !== []) {
                    DB::connection(self::LEAVE_ATTACHMENT_CLEANUP_CONNECTION)
                        ->table('storage_recovery_tasks')
                        ->whereIn('id', $taskIds)
                        ->delete();
                }
            } finally {
                DB::disconnect(self::LEAVE_ATTACHMENT_CLEANUP_CONNECTION);
            }
        });
    }

    protected function createAdoptedLeaveAttachment(LeaveRequest $leave, string $contents): string
    {
        $files = app(EmployeeFileStorageService::class);
        $stored = $files->storeLampiran(
            UploadedFile::fake()
                ->createWithContent('lampiran-'.Str::uuid().'.pdf', $contents)
                ->mimeType('application/pdf'),
            $leave->employee_id,
        );
        $leave->forceFill(['lampiran_path' => $stored['path']])->save();
        app(StorageRecoveryService::class)->markLeaveAttachmentCreationTargetAdopted(
            $stored['recovery_task_id'],
            $leave->employee_id,
            $stored['path'],
        );
        $this->leaveAttachmentRecoveryTaskIds[] = $stored['recovery_task_id'];

        return $stored['path'];
    }
}
