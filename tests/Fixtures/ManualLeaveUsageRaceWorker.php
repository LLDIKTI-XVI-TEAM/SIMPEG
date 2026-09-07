<?php

namespace Tests\Fixtures;

use App\Actions\Cuti\CancelManualLeaveUsageAction;
use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManualLeaveUsageRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        Carbon::setTestNow($input['now']);
        DB::selectOne("select set_config('application_name', ?, false)", [$input['application_name']]);
        config()->set('filesystems.disks.local.root', $input['storage_root']);
        Storage::forgetDisk('local');
        $employee = Employee::query()->findOrFail($input['employee_id']);
        $actor = User::query()->findOrFail($input['actor_id']);
        if ($input['lock_acquired'] !== '') {
            $paused = false;
            // Tahan hanya mutex pegawai pertama; rekalkulasi dapat mengambil lock yang sama lagi dalam transaksi.
            DB::listen(function (QueryExecuted $query) use ($input, &$paused): void {
                if ($paused || ! str_contains($query->sql, 'from "employees"') || ! str_contains($query->sql, 'for update')) {
                    return;
                }
                $paused = true;
                file_put_contents($input['lock_acquired'], 'locked');
                $deadline = microtime(true) + 90;
                while (! is_file($input['release_lock'])) {
                    if (microtime(true) >= $deadline) {
                        throw new \RuntimeException('Batas tunggu pelepasan mutex pegawai terlampaui.');
                    }
                    usleep(10_000);
                }
            });
        }
        file_put_contents($input['ready'], 'ready');

        while (! is_file($input['barrier'])) {
            usleep(10_000);
        }

        try {
            if ($input['mode'] === 'submit') {
                $request = Request::create('/dashboard/cuti', 'POST');
                $request->setUserResolver(fn (): User => $actor);
                $record = app(SubmitLeaveRequestAction::class)->execute(
                    $employee,
                    [
                        'jenis_cuti_id' => $input['leave_type_id'],
                        'leave_request_case_id' => null,
                        'tanggal_mulai' => $input['start_date'],
                        'tanggal_selesai' => $input['end_date'],
                        'alasan' => 'Pengajuan normal pada race overlap.',
                        'alamat_selama_cuti' => 'Alamat race overlap',
                        'nomor_telepon' => '+62 811 2222',
                    ],
                    $request,
                );
            } elseif ($input['mode'] === 'cancel') {
                $record = app(CancelManualLeaveUsageAction::class)->execute(
                    $input['record_id'],
                    'Pembatalan fakta tahunan pada race.',
                    $actor,
                );
            } else {
                $request = Request::create('/cuti/pemakaian-manual', 'POST');
                $request->setUserResolver(fn (): User => $actor);
                $data = [
                    'leave_type_id' => $input['leave_type_id'],
                    'leave_request_case_id' => null,
                    'tanggal_mulai' => $input['start_date'],
                    'tanggal_selesai' => $input['end_date'],
                    'alasan' => 'Cuti eksternal pada race overlap.',
                    'approval_document_number' => 'RACE/FIXTURE/001',
                    'approval_steps' => $this->approvalSteps(),
                ];
                $document = UploadedFile::fake()->create('race.pdf', 20, 'application/pdf');
                $record = $input['mode'] === 'correct'
                    ? app(CorrectManualLeaveUsageAction::class)->execute(
                        $input['record_id'],
                        [...$data, 'correction_reason' => 'Koreksi fakta tahunan pada race.'],
                        $document,
                        $actor,
                        $request,
                    )
                    : app(StoreManualLeaveUsageAction::class)->execute($employee->id, $data, $document, $actor, $request);
            }

            $result = [
                'ok' => true,
                'mode' => $input['mode'],
                'record_id' => $record->id,
            ];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'mode' => $input['mode'],
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        }

        file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, string|null>> */
    private function approvalSteps(): array
    {
        return [
            [
                'step_type' => 'kepala_bagian',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => 'Kepala Bagian Race',
                'approver_position' => 'Kepala Bagian',
                'approver_institution' => 'Instansi Race',
                'acted_on' => '2026-08-17',
                'decision_note' => null,
            ],
            [
                'step_type' => 'pybmc',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => 'PYBMC Race',
                'approver_position' => 'Pejabat Yang Berwenang Memberikan Cuti',
                'approver_institution' => 'Instansi Race',
                'acted_on' => '2026-08-18',
                'decision_note' => null,
            ],
        ];
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new ManualLeaveUsageRaceWorker)->run($input);
}
