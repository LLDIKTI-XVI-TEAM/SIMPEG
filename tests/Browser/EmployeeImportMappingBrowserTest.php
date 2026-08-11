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
                    errorSourceHeaders: component.sourceHeadersForErrors(['Nama Lengkap (Person)']),
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
                    .querySelector('input[aria-label="Baris validasi 2, Full Name"]')
                    ?.classList.contains('border-danger/50') ?? false;
            JS)[0];

            $this->assertSame(['Full Name'], $mappedSourceHeaders);
            $this->assertTrue($isHighlighted);
        });
    }

    public function test_validation_error_highlights_only_the_exact_mapped_source_header(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/pegawai/import-data')
                ->waitForText('Import Data Pegawai');

            $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data*="simpegTargetFields"]'));

                component.mainHeaders = ['Email', 'Email Address', 'NIP'];
                component.columnMapping = {
                    Email: 'tidak_dipakai',
                    'Email Address': 'Email Pegawai',
                    NIP: 'NIP',
                };
                component.allRows = [{
                    row: 2,
                    data: {
                        Email: 'unused@example.test',
                        'Email Address': 'invalid-email',
                        NIP: '999999999999999999',
                    },
                }];
                const errorSourceHeaders = component.sourceHeadersForErrors(['Email Pegawai']);
                component.validations = [{
                    row: 2,
                    name: '-',
                    status: 'error',
                    errorSourceHeaders,
                    col: errorSourceHeaders.join(', '),
                    error: 'Email Pegawai tidak valid.',
                    dataIndex: 0,
                }];
                component.step = 3;
            JS);

            $browser->pause(200)
                ->waitForText('Hasil Validasi');

            $highlightStates = $browser->script(<<<'JS'
                return ['Email', 'Email Address'].map((header) => ({
                    header,
                    highlighted: document
                        .querySelector(`input[aria-label="Baris validasi 2, ${header}"]`)
                        ?.classList.contains('border-danger/50') ?? false,
                }));
            JS)[0];

            $this->assertSame([
                ['header' => 'Email', 'highlighted' => false],
                ['header' => 'Email Address', 'highlighted' => true],
            ], $highlightStates);
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
                component.mainHeaders = ['No', 'Role', 'NIK', 'NIP', 'Email Pegawai', 'Kolom Cadangan', 'Email-Pegawai'];
                component.columnMapping = {
                    No: 'tidak_dipakai',
                    Role: 'tidak_dipakai',
                    NIK: 'tidak_dipakai',
                    NIP: 'NIP',
                    'Email Pegawai': 'Email Pegawai',
                    'Kolom Cadangan': 'tidak_dipakai',
                    'Email-Pegawai': 'tidak_dipakai',
                };
                component.requiredTargetFields = ['NIP', 'Email Pegawai'];
                component.allRows = [{
                    row: 2,
                    data: {
                        No: '1',
                        Role: 'pegawai',
                        NIK: '7171000000000001',
                        NIP: '999999999999999999',
                        'Email Pegawai': 'mapping@example.test',
                        'Kolom Cadangan': 'diabaikan',
                        'Email-Pegawai': 'email-lama@example.test',
                    },
                }];
                component.previewRowCount = 1;
                component.totalRows = 1;
            JS);

            $browser->pause(200)
                ->waitForText('Kolom SIMPEG sengaja tidak dipakai')
                ->assertDisabled('#mapping-0-no')
                ->assertSelected('#mapping-0-no', 'tidak_dipakai')
                ->assertDisabled('#mapping-1-role')
                ->assertSelected('#mapping-1-role', 'tidak_dipakai')
                ->select('#mapping-3-nip', 'tidak_dipakai')
                ->waitFor('@mapping-required-warning')
                ->assertDisabled('@mapping-continue')
                ->select('#mapping-3-nip', 'NIP')
                ->select('#mapping-5-kolom-cadangan', 'NIP')
                ->waitFor('@mapping-duplicate-warning')
                ->assertDisabled('@mapping-continue')
                ->select('#mapping-5-kolom-cadangan', 'tidak_dipakai')
                ->waitUntil(<<<'JS'
                    ['mapping-duplicate-warning', 'mapping-required-warning'].every((name) => {
                        const alert = document.querySelector(`[dusk="${name}"]`);
                        return !alert || getComputedStyle(alert).display === 'none';
                    })
                JS);

            $alertsRemainHidden = $browser->script(<<<'JS'
                return ['mapping-duplicate-warning', 'mapping-required-warning'].every((name) => {
                    const alert = document.querySelector(`[dusk="${name}"]`);
                    return alert !== null && getComputedStyle(alert).display === 'none';
                });
            JS)[0];

            $this->assertTrue($alertsRemainHidden);
            $browser->assertEnabled('@mapping-continue');

            $skippedHeaderCategories = $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data*="simpegTargetFields"]'));

                return {
                    unknown: component.unknownSourceHeaders,
                    intentionallySkipped: component.intentionallySkippedSourceHeaders,
                    alwaysIgnored: component.knownIgnoredSourceHeaders,
                };
            JS)[0];

            $this->assertSame([
                'unknown' => ['Kolom Cadangan', 'Email-Pegawai'],
                'intentionallySkipped' => ['NIK'],
                'alwaysIgnored' => ['No', 'Role'],
            ], $skippedHeaderCategories);

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
