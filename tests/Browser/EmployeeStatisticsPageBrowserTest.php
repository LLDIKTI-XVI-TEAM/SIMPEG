<?php

namespace Tests\Browser;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class EmployeeStatisticsPageBrowserTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** @var array<string, array{int, int}> */
    private const VIEWPORTS = [
        'mobile' => [390, 844],
        'tablet' => [768, 1024],
        'desktop' => [1440, 900],
    ];

    public function test_chart_dan_alternatif_tabel_statistik_terbaca_tanpa_error_console_pada_semua_viewport(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'jenis_kelamin' => 'L',
            'jabatan_terakhir' => 'Analis Statistik Browser',
        ]);

        $this->browse(function (Browser $browser) use ($admin): void {
            foreach (self::VIEWPORTS as $viewport => [$width, $height]) {
                $browser->loginAs($admin)
                    ->resize($width, $height)
                    ->visit('/reporting-statistik-kepegawaian')
                    ->waitForText('Statistik Kepegawaian')
                    ->waitUntil("return document.querySelector('#chart-jenis_pegawai')?.dataset.statisticsChartReady === 'true';")
                    ->assertVisible('#chart-jenis_pegawai')
                    ->assertVisible('#chart-golongan')
                    ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
                    ->assertScript(<<<'JS'
                        const heading = [...document.querySelectorAll('h2')]
                            .find((element) => element.textContent.trim() === 'Golongan');
                        const header = heading?.closest('.border-b');
                        const controls = header?.querySelector('[role="group"]');

                        if (!heading || !header || !controls) {
                            return false;
                        }

                        const headerRect = header.getBoundingClientRect();
                        const controlRect = controls.getBoundingClientRect();
                        const headingRect = heading.getBoundingClientRect();

                        return controlRect.top <= headingRect.top + 4
                            && controlRect.right <= headerRect.right + 1;
                    JS)
                    ->script(<<<'JS'
                    document.querySelector('[aria-label="Tampilan Tabel"]')?.click();
                JS);

                $browser->waitUntil(<<<'JS'
                const chart = document.querySelector('#chart-jenis_pegawai')?.closest('[x-show]');
                return chart?.style.display === 'none';
            JS)
                    ->assertSee('pegawai');

                $this->assertNoConsoleErrors($browser, $viewport);
            }
        });
    }

    private function assertNoConsoleErrors(Browser $browser, string $viewport): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(static fn (array $entry): bool => in_array(
                strtoupper((string) ($entry['level'] ?? '')),
                ['SEVERE', 'ERROR'],
                true,
            ))
            ->map(static fn (array $entry): string => (string) ($entry['message'] ?? 'Console error tanpa pesan.'))
            ->values()
            ->all();

        $this->assertSame([], $errors, "Browser console error pada viewport {$viewport}.");
    }
}
