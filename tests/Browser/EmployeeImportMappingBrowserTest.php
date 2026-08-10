<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class EmployeeImportMappingBrowserTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_person_validation_error_highlights_the_custom_source_header(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/pegawai/import-data')
                ->waitForText('Import Data Pegawai');

            $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data*="simpegTargetFields"]'));

                component.mainHeaders = ['Full Name', 'NIP'];
                component.columnMapping = {
                    'Full Name': 'Person',
                    NIP: 'NIP',
                };
                component.allRows = [{
                    row: 2,
                    data: {
                        'Full Name': '',
                        NIP: '999999999999999999',
                    },
                }];
                component.validations = [{
                    row: 2,
                    name: '-',
                    status: 'error',
                    col: component.sourceHeadersForErrors(['Nama Lengkap (Person)']).join(', '),
                    error: 'Nama Lengkap wajib diisi.',
                    dataIndex: 0,
                }];
                component.step = 3;
            JS);

            $browser->pause(200)
                ->waitForText('Hasil Validasi');

            $mappedSourceHeaders = $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data*="simpegTargetFields"]'));
                return component.sourceHeadersForErrors(['Nama Lengkap (Person)']);
            JS)[0];
            $isHighlighted = $browser->script(<<<'JS'
                return document
                    .querySelector('input[aria-label="Baris 2, Full Name"]')
                    ?.classList.contains('border-danger/50') ?? false;
            JS)[0];

            $this->assertSame(['Full Name'], $mappedSourceHeaders);
            $this->assertTrue($isHighlighted);
        });
    }

    public function test_mapping_controls_block_invalid_selection_and_persist_before_validation(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/pegawai/import-data')
                ->waitForText('Import Data Pegawai');

            $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data*="simpegTargetFields"]'));

                component.batchId = 'browser-test-batch';
                component.step = 2;
                component.mainHeaders = ['No', 'Role', 'NIP', 'Email Pegawai', 'Kolom Cadangan'];
                component.columnMapping = {
                    No: 'tidak_dipakai',
                    Role: 'tidak_dipakai',
                    NIP: 'NIP',
                    'Email Pegawai': 'Email Pegawai',
                    'Kolom Cadangan': 'tidak_dipakai',
                };
                component.requiredTargetFields = ['NIP', 'Email Pegawai'];
                component.allRows = [{
                    row: 2,
                    data: {
                        No: '1',
                        Role: 'pegawai',
                        NIP: '999999999999999999',
                        'Email Pegawai': 'mapping@example.test',
                        'Kolom Cadangan': 'diabaikan',
                    },
                }];
                component.previewRowCount = 1;
                component.totalRows = 1;
            JS);

            $browser->pause(200)
                ->assertDisabled('#mapping-0-no')
                ->assertSelected('#mapping-0-no', 'tidak_dipakai')
                ->assertDisabled('#mapping-1-role')
                ->assertSelected('#mapping-1-role', 'tidak_dipakai')
                ->select('#mapping-2-nip', 'tidak_dipakai')
                ->waitFor('@mapping-required-warning')
                ->assertDisabled('@mapping-continue')
                ->select('#mapping-2-nip', 'NIP')
                ->select('#mapping-4-kolom-cadangan', 'NIP')
                ->waitFor('@mapping-duplicate-warning')
                ->assertDisabled('@mapping-continue')
                ->select('#mapping-4-kolom-cadangan', 'tidak_dipakai')
                ->waitUntilMissing('@mapping-duplicate-warning')
                ->waitUntilMissing('@mapping-required-warning')
                ->assertEnabled('@mapping-continue');

            $browser->script(<<<'JS'
                window.__mappingRequests = [];
                window.fetch = (url, options = {}) => {
                    const request = {
                        path: new URL(url, window.location.origin).pathname,
                        mapping: options.body ? JSON.parse(options.body).mapping : null,
                    };
                    window.__mappingRequests.push(request);

                    const response = request.path.endsWith('/mapping')
                        ? { mapping: request.mapping, warnings: { unmatched_columns: [], missing_required: [] } }
                        : { total_rows: 1, valid_count: 1, error_count: 0, skip_count: 0, results: [] };

                    return Promise.resolve({ ok: true, json: () => Promise.resolve(response) });
                };
            JS);

            $browser
                ->click('@mapping-continue')
                ->waitForText('Hasil Validasi');

            $requests = $browser->script(<<<'JS'
                return window.__mappingRequests.map((request) => ({
                    path: request.path,
                    mapping: request.mapping,
                }));
            JS)[0];

            $this->assertSame([
                '/api/pegawai/import/browser-test-batch/mapping',
                '/api/pegawai/import/browser-test-batch/validate',
            ], array_column($requests, 'path'));
            $this->assertSame('tidak_dipakai', $requests[0]['mapping']['Role']);
            $this->assertSame('tidak_dipakai', $requests[0]['mapping']['No']);
        });
    }
}
