<?php

use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Models\RefJabatan;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Menahan aksi penghapusan tepat setelah pemeriksaan pemakaian nol. Barrier ini
 * membuat test dapat membuktikan interleaving PostgreSQL tanpa mengubah kode
 * produksi atau memakai jeda waktu sebagai pengganti sinkronisasi.
 */
final class PausingReferenceUsageService extends ReferenceUsageService
{
    /** @param array{usage_checked: string, continue_delete: string} $input */
    public function __construct(private readonly array $input) {}

    /** @return array<string, int> */
    public function usageDetail(Model $item): array
    {
        $detail = parent::usageDetail($item);

        if ($detail === []) {
            file_put_contents($this->input['usage_checked'], 'empty');

            while (! is_file($this->input['continue_delete'])) {
                usleep(10_000);
            }
        }

        return $detail;
    }
}

/** @param array<string, mixed> $input */
function waitForBarrier(array $input, string $key): void
{
    while (! is_file($input[$key])) {
        usleep(10_000);
    }
}

$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);

try {
    file_put_contents($input['ready'], 'ready');
    waitForBarrier($input, 'start');

    if ($input['mode'] === 'create_history') {
        // Transaksi pembuat memegang key-share lock dari FK sampai test mengizinkan
        // commit. Penghapus tanpa lock bisa menghitung nol pada jendela ini.
        DB::transaction(function () use ($input): void {
            DB::table('position_histories')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $input['employee_id'],
                'jabatan_id' => $input['jabatan_id'],
                'nama_jabatan' => 'Analis Kepegawaian',
                'tmt_jabatan' => '2026-01-01',
                'is_latest' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            file_put_contents($input['inserted'], 'inserted');
            waitForBarrier($input, 'release_creator');
        });
    } else {
        Auth::loginUsingId($input['user_id']);
        app()->instance(ReferenceUsageService::class, new PausingReferenceUsageService($input));

        $jabatan = RefJabatan::query()->findOrFail($input['jabatan_id']);
        app(DeleteReferenceItemAction::class)->execute($jabatan, Request::create('/', 'POST'));
    }

    file_put_contents($input['result'], json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($input['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
