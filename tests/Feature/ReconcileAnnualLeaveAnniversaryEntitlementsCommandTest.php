<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveAnniversaryAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[Group('serial')]
class ReconcileAnnualLeaveAnniversaryEntitlementsCommandTest extends TestCase
{
    /** Input operator yang tidak valid harus dihentikan sebelum query rekonsiliasi dijalankan. */
    public function test_command_menolak_limit_invalid_sebelum_action_dijalankan(): void
    {
        $reconcile = $this->bindReconcileActionSpy();

        $invalidLimits = [
            'nol' => '0',
            'negatif' => '-5',
            'non-numerik' => 'banyak',
            'desimal' => '1.5',
            'overflow' => '9223372036854775808',
        ];

        foreach ($invalidLimits as $case => $limit) {
            $exitCode = Artisan::call('cuti:reconcile-anniversary-entitlements', ['--limit' => $limit]);

            $this->assertSame(0, $reconcile->executeCalls, "Kasus {$case} tidak boleh menjalankan action.");
            $this->assertSame(Command::INVALID, $exitCode);
            $this->assertStringContainsString(
                'Opsi --limit wajib berupa bilangan bulat positif.',
                Artisan::output(),
            );
        }
    }

    /** Bilangan bulat positif berawalan nol tetap diteruskan sebagai nilai integer yang sama. */
    public function test_command_meneruskan_limit_berawalan_nol_sebagai_integer(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00 Asia/Makassar');
        $reconcile = $this->bindReconcileActionSpy();

        $exitCode = Artisan::call('cuti:reconcile-anniversary-entitlements', ['--limit' => '01']);

        $this->assertSame(1, $reconcile->executeCalls);
        $this->assertSame([1], $reconcile->receivedLimits);
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'Rekonsiliasi anniversary selesai untuk 0 projection saldo.',
            Artisan::output(),
        );
    }

    public function test_command_mengembalikan_failure_dan_hanya_mencatat_metadata_aman(): void
    {
        Carbon::setTestNow('2026-08-25 00:20:00 Asia/Makassar');
        $reconcile = $this->bindReconcileActionSpy();
        $reconcile->result = [
            'attempted' => 2,
            'processed' => 1,
            'failed' => 1,
            'failures' => [[
                'employee_id' => '00000000-0000-4000-8000-000000000901',
                'business_date' => '2026-08-25',
                'exception_class' => 'RuntimeException',
                'correlation_id' => '00000000-0000-4000-8000-000000000902',
            ]],
        ];
        $logSpy = Log::spy();

        $exitCode = Artisan::call('cuti:reconcile-anniversary-entitlements', ['--limit' => '2']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Berhasil: 1, gagal: 1.', Artisan::output());
        $this->assertStringNotContainsString('pesan internal', Artisan::output());
        $logSpy->shouldHaveReceived(
            'error',
            function (string $message, array $context): bool {
                $this->assertSame('Rekonsiliasi anniversary pegawai gagal.', $message);
                $this->assertSame([
                    'employee_id',
                    'business_date',
                    'exception_class',
                    'correlation_id',
                ], array_keys($context));

                return true;
            },
        );
    }

    private function bindReconcileActionSpy(): ReconcileAnnualLeaveAnniversaryActionSpy
    {
        if (! class_alias(
            ReconcileAnnualLeaveAnniversaryActionSpy::class,
            ReconcileAnnualLeaveAnniversaryAction::class,
        )) {
            throw new \LogicException('Test double action rekonsiliasi tidak dapat didaftarkan.');
        }

        $reconcile = new ReconcileAnnualLeaveAnniversaryActionSpy;
        $this->app->instance(ReconcileAnnualLeaveAnniversaryAction::class, $reconcile);

        return $reconcile;
    }
}

/** Test double terikat yang merekam pemanggilan action tanpa mengakses database. */
final class ReconcileAnnualLeaveAnniversaryActionSpy
{
    public int $executeCalls = 0;

    /** @var list<int> */
    public array $receivedLimits = [];

    /** @var array{attempted:int,processed:int,failed:int,failures:list<array{employee_id:string,business_date:string,exception_class:string,correlation_id:string}>} */
    public array $result = [
        'attempted' => 0,
        'processed' => 0,
        'failed' => 0,
        'failures' => [],
    ];

    /** Merekam argumen command dan mengembalikan hasil rekonsiliasi kosong. */
    public function execute(Carbon $asOf, int $limit): array
    {
        $this->executeCalls++;
        $this->receivedLimits[] = $limit;

        return $this->result;
    }
}
