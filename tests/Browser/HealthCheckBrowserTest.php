<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class HealthCheckBrowserTest extends DuskTestCase
{
    public function test_browser_can_reach_local_application_health_endpoint(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/up')
                ->assertPathIs('/up')
                ->assertSee('Application up')
                ->assertSee('HTTP request received.');
        });
    }
}
