<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class EmployeeDeactivateModalBrowserTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_single_and_bulk_deactivation_modals_do_not_claim_automatic_purge(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->superAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/pegawai')
                ->waitForText('Data Pegawai')
                ->script(<<<'JS'
                    Alpine.$data(document.querySelector('[x-data*="showDeleteModal"]'))
                        .deletePegawai('browser-test-id', 'Pegawai Browser');
                JS);

            $browser->waitFor('@deactivate-employee-modal')
                ->waitForText('Nonaktifkan Pegawai')
                ->assertSee('Pegawai Browser')
                ->assertSee('Data tidak dihapus permanen secara otomatis.')
                ->assertDontSee('30 hari')
                ->assertDontSee('dihapus permanen otomatis')
                ->script("window.dispatchEvent(new CustomEvent('open-confirm-bulk-delete'));");

            $browser->waitFor('@bulk-deactivate-employee-modal')
                ->waitForText('Pegawai terpilih akan dinonaktifkan')
                ->assertSee('Data tidak dihapus permanen secara otomatis.')
                ->assertDontSee('30 hari')
                ->assertDontSee('dihapus permanen otomatis');
        });
    }
}
