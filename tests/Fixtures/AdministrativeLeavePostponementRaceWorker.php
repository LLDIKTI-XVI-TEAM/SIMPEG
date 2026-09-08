<?php

namespace Tests\Fixtures;

use App\Actions\Cuti\RecordAdministrativeLeavePostponementAction;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdministrativeLeavePostponementRaceWorker
{
    /**
     * Worker hanya menyentuh database disposable; stdout menjadi kanal hasil tanpa artefak lokal.
     *
     * @param  array<string, string>  $input
     */
    public function run(array $input): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'simpeg_test') {
            throw new \RuntimeException('Worker penangguhan hanya boleh memakai PostgreSQL simpeg_test.');
        }
        Carbon::setTestNow(Carbon::parse('2026-09-06 09:00:00', 'Asia/Makassar'));
        Queue::fake();
        $leave = LeaveRequest::query()->findOrFail($input['leave_request_id']);
        $actor = User::query()->findOrFail($input['actor_id']);
        echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
        flush();

        try {
            if ($input['operation'] === 'recalculate') {
                app(LeaveBalanceRecalculationService::class)->recalculate($leave->employee, 2026, $actor, 'Rekalkulasi saat penangguhan bersamaan.');
            } else {
                $http = Request::create('/cuti/'.$leave->id.'/penangguhan-administratif', 'POST');
                $http->setUserResolver(fn (): User => $actor);
                app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $actor, 'Keputusan administratif saat kontensi.', $http);
            }
            $result = ['ok' => true, 'operation' => $input['operation']];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false, 'operation' => $input['operation'], 'class' => $exception::class,
                'message' => $exception->getMessage(),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ];
        }

        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    (new AdministrativeLeavePostponementRaceWorker)->run($input);
}
