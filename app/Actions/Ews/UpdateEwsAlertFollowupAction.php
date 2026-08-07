<?php

namespace App\Actions\Ews;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EwsAlert;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\EmployeeHistoryService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEwsAlertFollowupAction
{
    public function __construct(
        private readonly EmployeeHistoryService $histories,
        private readonly EmployeeFileStorageService $files,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Menangani alert EWS. Pangkat/KGB membuat riwayat baru, sedangkan Pensiun
     * mengarsipkan SK dan mengubah status pegawai dalam transaksi yang sama.
     */
    public function execute(EwsAlert $alert, string $followupStatus, string $handledNote, Request $request): EwsAlert
    {
        $before = [];
        $employee = null;
        $storedSkPaths = [];

        try {
            $alert = DB::transaction(function () use ($alert, $followupStatus, $handledNote, $request, &$before, &$employee, &$storedSkPaths): EwsAlert {
                $alert = EwsAlert::query()->lockForUpdate()->findOrFail($alert->id);
                if ($alert->followup_status !== EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'followup_status' => 'Alert EWS hanya dapat ditindaklanjuti saat status masih aktif.',
                    ]);
                }

                $before = $this->alertSnapshot($alert);
                $employee = Employee::query()->lockForUpdate()->findOrFail($alert->employee_id);

                if ($followupStatus === EwsAlert::FOLLOWUP_STATUS_HANDLED) {
                    if (in_array($alert->type, ['KENAIKAN_PANGKAT', 'KGB'], true)) {
                        $storedSkPaths[] = $this->createApprovedHistory($employee, $alert->type, $request);
                    } elseif ($alert->type === 'PENSIUN') {
                        $this->approveRetirement($employee, $request, $storedSkPaths);
                    }

                    // Semua tipe (termasuk KONTRAK_PPPK dan SATYALANCANA) harus menutup
                    // alert satu tipe beserta notifikasinya. Untuk tipe yang target
                    // datanya tidak berubah setelah ditangani, notifikasi yang masih
                    // unread akan membuat scheduler menghidupkan kembali pengingat dan
                    // menghapus acknowledgement pada run berikutnya.
                    $this->resolveCurrentTypeAlerts($employee, $alert->type, $followupStatus, $handledNote, $request);
                } elseif ($followupStatus === EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED) {
                    // Tidak mengubah riwayat maupun status pegawai; hanya menutup EWS terkait.
                    $this->resolveCurrentTypeAlerts($employee, $alert->type, $followupStatus, $handledNote, $request);
                }

                return $alert;
            });
        } catch (\Throwable $exception) {
            // File SK diunggah ke storage di dalam alur transaksi; rollback database
            // tidak menghapusnya, sehingga kompensasi manual diperlukan agar tidak
            // ada orphan file tanpa riwayat/dokumen yang merujuknya.
            foreach (array_filter($storedSkPaths) as $storedSkPath) {
                $this->files->deletePublicFile($storedSkPath);
            }

            throw $exception;
        }

        $alert->refresh();
        AuditService::log('UPDATE', 'EwsAlert', $alert->id, $before, $this->alertSnapshot($alert), $request);

        if ($employee instanceof Employee) {
            $typeLabel = EwsAlert::typeLabels()[$alert->type] ?? $alert->type;

            if ($followupStatus === EwsAlert::FOLLOWUP_STATUS_HANDLED) {
                $notificationType = match ($alert->type) {
                    'KENAIKAN_PANGKAT' => 'ews.kenaikan_pangkat',
                    'KGB' => 'ews.kgb',
                    'PENSIUN' => 'ews.pensiun',
                    'KONTRAK_PPPK' => 'ews.kontrak_pppk',
                    'SATYALANCANA' => 'ews.satyalancana',
                    default => 'ews.'.strtolower($alert->type),
                };
                $title = 'Tindak Lanjut EWS: Disetujui';
                $body = trim($handledNote) !== ''
                    ? $handledNote
                    : "Tindak lanjut EWS {$typeLabel} Anda telah disetujui / ditangani oleh Admin.";
            } else {
                $notificationType = 'ews.tidak_perlu';
                $title = 'Tindak Lanjut EWS: Tidak Perlu';
                $body = trim($handledNote) !== ''
                    ? $handledNote
                    : "Tindak lanjut EWS {$typeLabel} Anda telah ditandai tidak perlu oleh Admin.";
            }

            $this->notifications->createForEmployee(
                $employee,
                $notificationType,
                $title,
                $body,
                [
                    'ews_alert_id' => $alert->id,
                    'followup_status' => $followupStatus,
                    'event_type' => $alert->type,
                    'handled_note' => $handledNote,
                    'url' => route('notifications.index'),
                ],
            );
        }

        return $alert;
    }

    /**
     * Membuat riwayat pangkat/KGB dan mengembalikan path file SK yang tersimpan
     * agar pemanggil dapat menghapusnya bila transaksi luar akhirnya rollback.
     */
    private function createApprovedHistory(Employee $employee, string $type, Request $request): ?string
    {
        if ($type === 'KENAIKAN_PANGKAT') {
            $tmt = (string) $request->input('tmt_pangkat');
            $this->ensureNewerTmt($employee, 'rankHistories', 'tmt_pangkat', $tmt, 'tmt_pangkat');
            $history = $this->histories->createRankHistory($employee, [
                'golongan_id' => (string) $request->input('golongan_id'),
                'tmt_pangkat' => $tmt,
                'no_sk' => (string) $request->input('no_sk'),
                'tanggal_sk' => (string) $request->input('tanggal_sk'),
                'file_sk' => $request->file('file_sk'),
            ], $request);

            return $history->file_sk;
        }

        $tmt = (string) $request->input('tmt_kgb');
        $this->ensureNewerTmt($employee, 'salaryHistories', 'tmt_kgb', $tmt, 'tmt_kgb');
        $history = $this->histories->createKgbHistory($employee, [
            'tmt_kgb' => $tmt,
            'gaji_pokok' => $request->input('gaji_pokok'),
            'no_sk' => (string) $request->input('no_sk'),
            'tanggal_sk' => (string) $request->input('tanggal_sk'),
            'file_sk' => $request->file('file_sk'),
        ], $request);

        return $history->file_sk;
    }

    /**
     * Menyetujui pensiun. Path SK dicatat ke $storedSkPaths segera setelah file
     * tersimpan (bukan lewat return) supaya kompensasi rollback tetap bisa
     * menghapus file walaupun langkah setelah penyimpanan yang gagal.
     *
     * @param  array<int, string|null>  $storedSkPaths
     */
    private function approveRetirement(Employee $employee, Request $request, array &$storedSkPaths): void
    {
        $oldValues = $employee->getAttributes();
        $pensionStatus = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $filePath = $this->files->storeSk($request->file('file_sk'));
        $storedSkPaths[] = $filePath;

        $employee->documents()->create([
            'jenis_dokumen' => 'sk_pensiun',
            'nama_dokumen' => 'SK Pensiun',
            'nomor_dokumen' => (string) $request->input('no_sk'),
            'tanggal_dokumen' => (string) $request->input('tanggal_sk'),
            'file_path' => $filePath,
            'keterangan' => 'Diunggah saat persetujuan EWS Pensiun.',
        ]);

        // Mark semua history record lama sebagai not latest
        EmployeeStatusHistory::where('employee_id', $employee->id)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

        // Create status history entry agar muncul di riwayat status pegawai
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $pensionStatus->id,
            'status_nama' => $pensionStatus->nama,
            'keterangan' => 'Status diubah menjadi Pensiun melalui persetujuan EWS.',
            'tanggal_efektif' => (string) $request->input('tanggal_sk'),
            'nomor_berkas' => (string) $request->input('no_sk'),
            'file_sk' => $filePath,
            'changed_by_user_id' => $request->user()?->id,
            'is_latest' => true,
        ]);

        $employee->update([
            'status_pegawai_id' => $pensionStatus->id,
            'status_keterangan' => 'Status diubah menjadi Pensiun melalui persetujuan EWS.',
            'status_tanggal' => (string) $request->input('tanggal_sk'),
            'status_berkas_path' => $filePath,
            'status_nomor_berkas' => (string) $request->input('no_sk'),
        ]);

        AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->fresh()->getAttributes(), $request);
    }

    private function ensureNewerTmt(Employee $employee, string $relation, string $column, string $tmt, string $attribute): void
    {
        $latestTmt = $employee->{$relation}()->max($column);
        if ($latestTmt !== null && Carbon::parse($tmt)->lessThanOrEqualTo(Carbon::parse($latestTmt))) {
            throw ValidationException::withMessages([
                $attribute => 'TMT baru harus lebih besar dari TMT riwayat terakhir agar target EWS dapat direset.',
            ]);
        }
    }

    private function resolveCurrentTypeAlerts(Employee $employee, string $type, string $status, string $note, Request $request): void
    {
        $alerts = EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();
        $alertIds = $alerts->modelKeys();
        $now = now();

        EwsAlert::query()->whereKey($alertIds)->update($this->followupAttributes($status, $note, $request, $now));
        SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('is_read', false)
            ->where(function ($query) use ($alertIds): void {
                $query->whereIn('ews_alert_id', $alertIds);
                foreach ($alertIds as $alertId) {
                    $query->orWhereJsonContains('data->ews_alert_id', $alertId);
                }
            })
            ->update(['is_read' => true, 'read_at' => $now, 'updated_at' => $now]);
    }

    /** @return array<string, mixed> */
    private function followupAttributes(string $status, string $note, Request $request, mixed $handledAt = null): array
    {
        $handledAt ??= now();

        return [
            'followup_status' => $status,
            'handled_at' => $handledAt,
            'handled_by' => $request->user()?->id,
            'handled_note' => $note,
            'is_processed' => true,
            'notification_acknowledged_at' => $handledAt,
        ];
    }

    /** @return array<string, mixed> */
    private function alertSnapshot(EwsAlert $alert): array
    {
        return [
            'followup_status' => $alert->followup_status,
            'handled_at' => $alert->handled_at?->toDateTimeString(),
            'handled_by' => $alert->handled_by,
            'handled_note' => $alert->handled_note,
        ];
    }
}
