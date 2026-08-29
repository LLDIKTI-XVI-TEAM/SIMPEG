<?php

namespace Tests\Fixtures;

use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReferenceStatusControlPlaneRaceWorker
{
    /** @param array<string, string> $input */
    public function run(array $input): void
    {
        DB::statement("SET lock_timeout TO '15s'");
        file_put_contents($input['ready'], (string) DB::scalar('select pg_backend_pid()'), LOCK_EX);

        try {
            if ($input['mode'] === 'insert_usage') {
                $this->insertUsage($input);
            } elseif ($input['mode'] === 'force_reclassification') {
                $this->forceReclassification($input);
            } else {
                $this->updateClassification($input);
            }

            $result = ['ok' => true];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        } finally {
            Auth::logout();
        }

        file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /** @param array<string, string> $input */
    private function insertUsage(array $input): void
    {
        DB::transaction(function () use ($input): void {
            EmployeeStatusTransition::create([
                'employee_id' => $input['employee_id'],
                'status_pegawai_id' => $input['status_id'],
                'tanggal_efektif' => now()->addMonth()->toDateString(),
                'kind' => EmployeeStatusTransition::KIND_STATUS,
                'keterangan' => 'Fixture race pemakaian status.',
            ]);

            file_put_contents($input['inserted'], 'inserted', LOCK_EX);
            while (! is_file($input['release_usage'])) {
                usleep(10_000);
            }
        });
    }

    /** @param array<string, string> $input */
    private function updateClassification(array $input): void
    {
        $actor = User::query()->findOrFail($input['actor_id']);
        $status = RefStatusPegawai::query()->findOrFail($input['status_id']);
        Auth::login($actor);

        $request = Request::create('/uji-race-status', 'POST');
        $request->setUserResolver(static fn (): User => $actor);

        DB::transaction(function () use ($input, $status, $request): void {
            $data = ['kelompok' => $input['kelompok'] ?? 'Aktif'];
            foreach (['kode', 'nama'] as $field) {
                if (isset($input[$field])) {
                    $data[$field] = $input[$field];
                }
            }

            app(UpdateReferenceItemAction::class)->execute($status, $data, $request);

            if (isset($input['updated'], $input['release_update'])) {
                file_put_contents($input['updated'], 'updated', LOCK_EX);
                while (! is_file($input['release_update'])) {
                    usleep(10_000);
                }
            }
        });
    }

    /** Simulasi workflow migrasi massal yang boleh mereklasifikasi status terpakai. */
    private function forceReclassification(array $input): void
    {
        DB::transaction(function () use ($input): void {
            $status = RefStatusPegawai::query()
                ->whereKey($input['status_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $status->forceFill(['kelompok' => $input['kelompok']])->saveOrFail();

            file_put_contents($input['updated'], 'updated', LOCK_EX);
            while (! is_file($input['release_update'])) {
                usleep(10_000);
            }
        });
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new ReferenceStatusControlPlaneRaceWorker)->run($input);
}
