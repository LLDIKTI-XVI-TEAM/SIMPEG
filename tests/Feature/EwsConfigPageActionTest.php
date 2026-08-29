<?php

namespace Tests\Feature;

use App\Actions\Ews\ShowEwsConfigPageAction;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EwsConfigPageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_konfigurasi_ews_memakai_id_sebagai_tie_breaker_pagination(): void
    {
        $timestamp = now()->startOfSecond();

        foreach ([3, 11, 1, 8, 5, 10, 2, 9, 4, 7, 6] as $index) {
            AuditLog::forceCreate([
                'id' => sprintf('00000000-0000-4000-8000-%012d', $index),
                'user_name' => 'Admin EWS',
                'event' => 'UPDATE',
                'auditable_type' => 'EwsConfig',
                'auditable_id' => null,
                'new_values' => [
                    'key' => 'pangkat_h30',
                    'value' => (string) $index,
                    'reason' => "Perubahan konfigurasi {$index}",
                ],
                'created_at' => $timestamp,
            ]);
        }

        $action = app(ShowEwsConfigPageAction::class);
        $firstPage = $action->execute(10)['auditRows'];
        $this->app['request']->query->set('log_page', 2);
        $secondPage = $action->execute(10)['auditRows'];

        $expectedReasons = AuditLog::query()
            ->where('auditable_type', 'EwsConfig')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log): string => $log->new_values['reason'])
            ->all();
        $actualReasons = collect($firstPage->items())
            ->pluck('reason')
            ->merge(collect($secondPage->items())->pluck('reason'))
            ->all();

        $this->assertSame($expectedReasons, $actualReasons);
    }
}
