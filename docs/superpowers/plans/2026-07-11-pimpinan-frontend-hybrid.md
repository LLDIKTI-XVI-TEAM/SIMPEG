# Pimpinan Frontend Hybrid Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Pimpinan Blade UI truthful, accessible, and responsive while leaving unfinished approval, data, and export backends untouched.

**Architecture:** Keep the existing Pimpinan controllers, routes, and Blade component system. Each view expresses whether its current route can execute a real action; unsupported operations become semantic disabled controls with visible explanatory copy. No controller, Action, Service, model, migration, or exporter behavior changes are part of this plan.

**Tech Stack:** Laravel 12, Blade SSR, Alpine small UI state, existing `x-ui.*` components, Tailwind utility classes, Playwright browser QA, PHPUnit feature tests where an existing view test can lock markup.

## Global Constraints

- Modify only Pimpinan Blade views and focused view tests required by this frontend-only scope.
- Do not add dependencies, routes, controllers, Actions, Services, migrations, or custom PDF behavior.
- Keep real existing routes active only when their backend behavior is real; do not submit endpoints known to return dummy success.
- Use only official leave labels: `Disetujui`, `Perubahan`, `Ditangguhkan`, `Tidak Disetujui`.
- All touched form controls require connected `label[for]`/`id` and help/error association through `aria-describedby`.
- Verify browser behavior at 375 px, 768 px, and 1280 px; do not treat source inspection as UI verification.

---

### Task 1: Establish frontend-only regression coverage

**Files:**
- Modify: `tests/Feature/PimpinanFrontendViewTest.php` (create only if no equivalent Pimpinan Blade view test exists)
- Verify: `resources/views/pimpinan/cuti/show.blade.php`
- Verify: `resources/views/pimpinan/laporan/pegawai.blade.php`
- Verify: `resources/views/pimpinan/laporan/kepangkatan.blade.php`

**Interfaces:**
- Consumes: existing `PimpinanLeaveController`, `PimpinanReportController`, and current protected Pimpinan routes.
- Produces: assertions that prevent fake export/decision controls and non-official decision copy from returning.

- [ ] **Step 1: Inspect existing Pimpinan Feature tests**

Run:

```powershell
php artisan test --filter=Pimpinan
```

Expected: identify whether a Pimpinan view test already exists; do not duplicate its factory/session setup.

- [ ] **Step 2: Add a failing rendered-view assertion for official leave wording**

Use the existing authenticated Pimpinan fixture. Assert the detail response includes `Perubahan`, includes the note-required help text, and does not include `Disetujui dengan Perubahan`.

```php
$response->assertSee('Perubahan')
    ->assertDontSee('Disetujui dengan Perubahan');
```

- [ ] **Step 3: Add a failing rendered-view assertion for truthful unavailable exports**

Assert the Pimpinan report views do not include `onclick="alert(` and that the custom employee report does not offer `PDF Document`.

```php
$response->assertDontSee('onclick="alert(')
    ->assertDontSee('PDF Document');
```

- [ ] **Step 4: Run the focused test to establish red state**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
```

Expected: fail against current views because the old wording/fake export UI is still present.

- [ ] **Step 5: Preserve the test as the regression boundary**

Do not weaken assertion scope. The later view tasks must turn this test green without adding controller behavior.

### Task 2: Make the leave-decision UI semantically correct without activating the dummy endpoint

**Files:**
- Modify: `resources/views/pimpinan/cuti/show.blade.php`
- Test: `tests/Feature/PimpinanFrontendViewTest.php`

**Interfaces:**
- Consumes: existing `$leaveData` and current Blade/Alpine form state.
- Produces: official vocabulary, accessible guidance, and a non-mutating unavailable state until the decision endpoint delegates to the approval engine.

- [ ] **Step 1: Replace the non-official option copy**

Change only the visible option text, retaining the existing backend value:

```blade
<option value="PERUBAHAN">2. Perubahan</option>
```

- [ ] **Step 2: Connect form labels and decision guidance to their controls**

Give the select and textarea stable IDs. Add a help block that becomes visible for `PERUBAHAN` and `DITANGGUHKAN`, and attach it to the textarea.

```blade
<select id="keputusan" name="keputusan" x-model="keputusan" aria-describedby="keputusan-help" required>
...
<p id="keputusan-help" class="text-xs text-muted">
    Pilih keputusan final sesuai kewenangan approval yang berlaku.
</p>
<textarea id="catatan" name="catatan" aria-describedby="catatan-help" ...></textarea>
<p id="catatan-help" class="text-xs text-muted">
    Catatan wajib untuk Perubahan atau Ditangguhkan.
</p>
```

- [ ] **Step 3: Replace the submit affordance with an honest unavailable state**

Do not submit the known dummy route. Use the existing component’s disabled support and explanatory text.

```blade
<x-ui.button type="button" variant="primary" class="w-full justify-center" disabled aria-describedby="decision-unavailable">
    Simpan Keputusan Final
</x-ui.button>
<p id="decision-unavailable" class="mt-2 text-xs text-muted">
    Keputusan final akan tersedia setelah proses approval cuti terhubung ke data operasional.
</p>
```

- [ ] **Step 4: Run the focused feature test**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
```

Expected: the leave wording assertions pass.

- [ ] **Step 5: Perform browser QA without submitting a decision**

At 375 px, 768 px, and 1280 px: open one pending leave detail, select `Perubahan`, verify required guidance is visible, verify the disabled button exposes its explanation, and verify no POST request occurs.

### Task 3: Make employee detail tabs and report entry points truthful

**Files:**
- Modify: `resources/views/pimpinan/pegawai/show.blade.php`
- Test: `tests/Feature/PimpinanFrontendViewTest.php`

**Interfaces:**
- Consumes: existing `$employeeData`, `$infoOtomatis`, and Alpine `activeTab`.
- Produces: an accessible Info Otomatis panel and a disabled history-export affordance.

- [ ] **Step 1: Remove the `visiblePanels` dead state**

Keep only the tab state actually consumed by each `x-show` panel:

```blade
<div class="space-y-6" x-data="{ activeTab: 'profil' }">
```

- [ ] **Step 2: Verify and correct the Info Otomatis panel**

Ensure its panel uses the same contract as other panels:

```blade
<div x-show="activeTab === 'info'" x-cloak>
    {{-- existing Info Otomatis card --}}
</div>
```

Each sidebar tab must render a real `<button type="button">`, expose `aria-selected`, and connect to its panel by `aria-controls`/panel `id` if the existing `x-ui.tab` component supports attributes. If it does not, make only the smallest component-compatible improvement needed to keep keyboard navigation intact.

- [ ] **Step 3: Replace the POST history-export form**

The current endpoint is dummy. Replace the form with a disabled button and visible status copy.

```blade
<x-ui.button type="button" variant="secondary" disabled aria-describedby="history-export-unavailable">
    Cetak Riwayat
</x-ui.button>
<p id="history-export-unavailable" class="mt-2 text-xs text-muted">
    Export riwayat tersedia setelah laporan Excel terhubung ke data operasional.
</p>
```

- [ ] **Step 4: Run the focused test and browser tab interaction**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
```

Browser check: click `Info Otomatis`, confirm its content is visible and all other panels are hidden; tab through the control and confirm focus is visible.

### Task 4: Align EWS presentation with the Fase 1 table contract

**Files:**
- Modify: `resources/views/pimpinan/ews/index.blade.php`
- Test: `tests/Feature/PimpinanFrontendViewTest.php`

**Interfaces:**
- Consumes: existing `$ewsList` dummy shape (`nama`, `nip`, `jenis`, `sisa_hari`, `status`, `indikator`).
- Produces: truthful placeholders and filters without inventing backend fields or client-side sorting.

- [ ] **Step 1: Add the required event filter option**

Add the exact UI option while retaining existing GET behavior:

```blade
<option value="Kontrak PPPK" {{ request('jenis') == 'Kontrak PPPK' ? 'selected' : '' }}>Kontrak PPPK</option>
```

- [ ] **Step 2: Add required missing table columns as unavailable placeholders**

Insert `Tanggal Target` and `Status Eligibility` headers after `Jenis Event`. For the current data shape, render explicit non-operational text rather than derive values in Blade:

```blade
<x-ui.table-td><span class="text-sm text-muted">Belum tersedia dari sumber data</span></x-ui.table-td>
<x-ui.table-td><span class="text-sm text-muted">Belum tersedia dari sumber data</span></x-ui.table-td>
```

- [ ] **Step 3: Remove non-functional sort and pagination controls**

Replace `href="#"` sort anchors and page controls with plain text/disabled controls explaining that the current preview is ordered by the source data. Do not implement client-side sorting or fake pagination.

- [ ] **Step 4: Remove color-only severity communication**

Keep the badge color and append its severity text, for example `Mendesak · H-15`, so red/yellow/green is not the sole signal.

- [ ] **Step 5: Run focused tests and responsive browser QA**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
```

Browser check at 375/768/1280 px: filter `Kontrak PPPK`, inspect empty state, confirm table can scroll horizontally only inside its wrapper, and confirm severity/status remains readable without color perception.

### Task 5: Make reports truthful and align selectable formats/filters with Fase 1

**Files:**
- Modify: `resources/views/pimpinan/laporan/pegawai.blade.php`
- Modify: `resources/views/pimpinan/laporan/cuti.blade.php`
- Modify: `resources/views/pimpinan/laporan/kepangkatan.blade.php`
- Test: `tests/Feature/PimpinanFrontendViewTest.php`

**Interfaces:**
- Consumes: existing preview data and current routes. Does not change export request semantics.
- Produces: honest, accessible report cards that do not offer unsupported custom PDF or submit dummy actions.

- [ ] **Step 1: Reframe nominatif custom report as Excel-only unavailable UI**

Remove the PDF/Excel format select and the POST submit. Add disabled checkboxes/selects for columns and documented filters: status, unit, jenis pegawai, golongan, jabatan, periode pensiun. Use explicit `id`, `for`, and one help block.

```blade
<x-ui.button type="button" variant="primary" disabled aria-describedby="custom-report-unavailable">
    Export Excel
</x-ui.button>
<p id="custom-report-unavailable" class="mt-2 text-xs text-muted">
    Export nominatif Excel tersedia setelah pilihan kolom dan filter terhubung ke data operasional.
</p>
```

Never list NIK, NPWP, or another sensitive field as a selectable column.

- [ ] **Step 2: Keep only real cuti export controls active**

The existing GET route is real, so keep the period form. Add `id`/`for` pairs to year/month, describe that only available backend filters are submitted, and label preview as sample data until the controller supplies real rows.

```blade
<p class="text-xs text-muted" role="status">
    Preview menggunakan data contoh sampai sumber laporan cuti terhubung ke data operasional.
</p>
```

- [ ] **Step 3: Replace rank-history alert-only export with fixed-report availability state**

Remove `action="#"`, `onclick`, and any format selector that suggests a custom report. Keep a disabled format summary rather than controls:

```blade
<p class="text-sm text-muted">Format laporan fixed yang direncanakan: PDF dan Excel (.xlsx).</p>
<x-ui.button type="button" variant="primary" disabled aria-describedby="rank-report-unavailable">
    Export Laporan
</x-ui.button>
<p id="rank-report-unavailable" class="mt-2 text-xs text-muted">
    Export riwayat kepangkatan tersedia setelah route laporan fixed terhubung ke data operasional.
</p>
```

- [ ] **Step 4: Label every dummy preview**

At each report preview, add a concise `role="status"` data-example marker. This is presentation only; do not calculate report values in Blade.

- [ ] **Step 5: Run focused test and browser checks**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
```

Browser check:
- Report Pegawai has no PDF custom option and no POST on its disabled export action.
- Report Cuti submits only the existing GET export route with selected period.
- Report Kepangkatan has no `alert()` and no fake form submission.
- All report cards remain usable at 375/768/1280 px.

### Task 6: Declare sample dashboard data and finish browser QA

**Files:**
- Modify: `resources/views/pimpinan/dashboard.blade.php`
- Test: `tests/Feature/PimpinanFrontendViewTest.php`

**Interfaces:**
- Consumes: existing seven dashboard-widget variables.
- Produces: an unobtrusive server-rendered sample-data status until backend integration replaces the static controller data.

- [ ] **Step 1: Add one dashboard-level sample-data status**

Place this after the page header, not in every widget:

```blade
<x-ui.alert variant="warning" role="status">
    Ringkasan ini masih menggunakan data contoh dan belum menggambarkan data operasional terbaru.
</x-ui.alert>
```

- [ ] **Step 2: Remove or disable any dashboard approval shortcut that posts to the dummy Pimpinan decision endpoint**

Use one disabled Pimpinan-specific action label with the same unavailable explanation used by leave detail. Keep navigation links to Monitoring Cuti and EWS active.

- [ ] **Step 3: Run the focused view test**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
```

- [ ] **Step 4: Run full browser QA on actual updated runtime**

Use Playwright after ensuring the server is serving the current checkout and Vite assets are present. At each viewport (375, 768, 1280), cover:

```text
/pimpinan/dashboard
/pimpinan/pegawai
/pimpinan/cuti
/pimpinan/ews
/notifications
/pimpinan/laporan
/pimpinan/laporan/pegawai
/pimpinan/laporan/cuti
/pimpinan/laporan/kepangkatan
```

Expected: no console errors, no page-level horizontal overflow, no fake active actions, disabled controls explain why, tabs work, and all real navigation remains reachable.

### Task 7: Final verification and review

**Files:**
- Verify: all files changed in Tasks 1-6

**Interfaces:**
- Consumes: completed frontend-only Blade changes.
- Produces: evidence that the source, tests, and actual browser surface agree.

- [ ] **Step 1: Run formatting check**

Run:

```powershell
composer format:check
```

Expected: pass. If pre-existing files outside this scope fail, report their exact paths and do not format unrelated code.

- [ ] **Step 2: Run focused tests then full suite**

Run:

```powershell
php artisan test --filter=PimpinanFrontendViewTest
php artisan test
```

Expected: focused test passes. Record any unrelated full-suite failures verbatim; do not weaken tests.

- [ ] **Step 3: Run diagnostics and inspect the diff**

Run diagnostics for every changed Blade/test file and inspect:

```powershell
git diff --check
git diff -- resources/views/pimpinan tests/Feature
```

Expected: no whitespace errors; diff limited to frontend-only views and focused tests.

- [ ] **Step 4: Conduct final manual QA**

Repeat Task 6 browser matrix after the latest server reload. Capture screenshots for desktop dashboard, mobile EWS, mobile report, and leave detail unavailable state.

- [ ] **Step 5: Request a focused code review before any merge or commit**

Provide reviewers the diff and this contract: no backend behavior changes, no fake action, official cuti vocabulary, no custom PDF, accessible/responsive controls.

## Plan Self-Review

- Spec coverage: Tasks 2-6 map to leave, employee detail, EWS, reports, dashboard, accessibility, and responsive requirements respectively.
- No placeholders: every task names target files, concrete state, exact commands, and expected outcomes.
- Scope consistency: no task changes routes, controllers, Actions, Services, database, or export implementation; Task 5 only preserves the existing real cuti export GET route.
