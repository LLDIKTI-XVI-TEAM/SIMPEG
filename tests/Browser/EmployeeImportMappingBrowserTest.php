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
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));

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
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));
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
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));

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

    public function test_skip_result_is_informational_read_only_and_separate_from_errors(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/pegawai/import-data')
                ->waitForText('Import Data Pegawai');

            $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));

                component.batchId = 'browser-skip-batch';
                component.mainHeaders = ['NIP', 'Email Pegawai'];
                component.columnMapping = { NIP: 'NIP', 'Email Pegawai': 'Email Pegawai' };
                component.requiredTargetFields = ['NIP', 'Email Pegawai'];
                component.allRows = [
                    { row: 2, data: { NIP: '123456789012345678', 'Email Pegawai': 'existing@example.test' } },
                    { row: 3, data: { NIP: '123456789012345679', 'Email Pegawai': 'duplicate@example.test' } },
                    { row: 4, data: { NIP: '123456789012345680', 'Email Pegawai': 'email-used@example.test' } },
                    { row: 5, data: { NIP: '123456789012345681', 'Email Pegawai': 'legacy@example.test' } },
                ];
                window.fetch = (url, options = {}) => {
                    const path = new URL(url, window.location.origin).pathname;
                    const response = path.endsWith('/mapping')
                        ? { mapping: component.columnMapping, warnings: { unmatched_columns: [], missing_required: [] } }
                        : {
                            total_rows: 4,
                            valid_count: 0,
                            skip_count: 2,
                            error_count: 2,
                            results: [
                                { row: 2, status: 'skip', errors: { NIP: ['NIP sudah terdaftar di database.'] } },
                                { row: 3, status: 'error', errors: { NIP: ['NIP ganda dalam file.'] } },
                                { row: 4, status: 'error', errors: { 'Email Pegawai': ['Email sudah terdaftar.'] } },
                                { row: 5, status: 'skip', errors: {} },
                            ],
                        };

                    return Promise.resolve({ ok: true, json: () => Promise.resolve(response) });
                };
                component.runValidation();
            JS);

            $browser->waitForText('Sudah ada — akan dilewati')
                ->assertSee('Terlewat (sudah ada)')
                ->assertSeeIn('@validation-skip-count', '2')
                ->assertPresent('@validation-value-2-nip')
                ->assertMissing('@validation-input-2-nip')
                ->assertPresent('@validation-input-3-nip')
                ->assertPresent('@validation-input-4-email-pegawai');

            $statusPresentation = $browser->script(<<<'JS'
                return {
                    row2Description: document.querySelector('[dusk="validation-description-2"]')?.textContent?.trim() ?? '',
                    row5Description: document.querySelector('[dusk="validation-description-5"]')?.textContent?.trim() ?? '',
                    skipRowIsDanger: document.querySelector('[dusk="validation-row-2"]')?.classList.contains('bg-danger/[0.03]') ?? true,
                    skipInputIsDanger: document.querySelector('[dusk="validation-input-2-nip"]')?.classList.contains('border-danger/50') ?? false,
                    duplicateNipIsDanger: document.querySelector('[dusk="validation-input-3-nip"]')?.classList.contains('border-danger/50') ?? false,
                    existingEmailIsDanger: document.querySelector('[dusk="validation-input-4-email-pegawai"]')?.classList.contains('border-danger/50') ?? false,
                };
            JS)[0];

            $this->assertSame([
                // Baris 2 membawa alasan dari server sehingga teks server yang tampil.
                'row2Description' => 'NIP sudah terdaftar di database.',
                // Baris 5 tanpa alasan dari server sehingga keterangan fallback tampil.
                'row5Description' => 'NIP sudah terdaftar di database. Baris ini tidak akan diimpor.',
                'skipRowIsDanger' => false,
                'skipInputIsDanger' => false,
                'duplicateNipIsDanger' => true,
                'existingEmailIsDanger' => true,
            ], $statusPresentation);
        });
    }

    public function test_import_is_blocked_until_an_edited_error_row_is_revalidated(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/pegawai/import-data')
                ->waitForText('Import Data Pegawai');

            $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));

                component.batchId = 'browser-edits-batch';
                component.mainHeaders = ['NIP', 'Email Pegawai'];
                component.columnMapping = { NIP: 'NIP', 'Email Pegawai': 'Email Pegawai' };
                component.requiredTargetFields = ['NIP', 'Email Pegawai'];
                component.allRows = [{
                    row: 2,
                    data: { NIP: '123456789012345678', 'Email Pegawai': 'invalid-email' },
                }];
                component.validations = [{
                    row: 2,
                    name: '-',
                    status: 'error',
                    errorSourceHeaders: ['Email Pegawai'],
                    col: 'Email Pegawai',
                    error: 'Email Pegawai tidak valid.',
                    dataIndex: 0,
                }];
                component.totalRows = 1;
                component.validRows = 1;
                component.errorRows = 1;
                component.skipRows = 0;
                component.step = 3;
                window.fetch = (url, options = {}) => {
                    const path = new URL(url, window.location.origin).pathname;
                    const response = path.endsWith('/mapping')
                        ? { mapping: component.columnMapping, warnings: { unmatched_columns: [], missing_required: [] } }
                        : {
                            total_rows: 1,
                            valid_count: 1,
                            skip_count: 0,
                            error_count: 0,
                            results: [{ row: 2, status: 'valid', errors: {} }],
                        };

                    return Promise.resolve({ ok: true, json: () => Promise.resolve(response) });
                };
            JS);

            $browser->waitFor('@validation-import')
                ->assertEnabled('@validation-import')
                ->type('@validation-input-2-email-pegawai', 'valid@example.test')
                ->assertSee('Validasi ulang perubahan sebelum mengimpor.')
                ->assertDisabled('@validation-import')
                ->click('@validation-revalidate')
                ->waitForText('Siap diimpor')
                ->assertEnabled('@validation-import');
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
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));

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
                ->waitUntil(<<<'JS'
                    Alpine.$data(document.querySelector('[x-data="employeeImport"]')).hasDuplicateMapping === true
                JS)
                ->assertDisabled('@mapping-continue')
                ->select('#mapping-5-kolom-cadangan', 'tidak_dipakai')
                ->waitUntil(<<<'JS'
                    const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));
                    return component.hasDuplicateMapping === false && component.missingRequiredTargets.length === 0;
                JS);

            $alertsRemainHidden = $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));
                return component.hasDuplicateMapping === false && component.missingRequiredTargets.length === 0;
            JS)[0];

            $this->assertTrue($alertsRemainHidden);
            $browser->assertEnabled('@mapping-continue');

            $skippedHeaderCategories = $browser->script(<<<'JS'
                const component = Alpine.$data(document.querySelector('[x-data="employeeImport"]'));

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
