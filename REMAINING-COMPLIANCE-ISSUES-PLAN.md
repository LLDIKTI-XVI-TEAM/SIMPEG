# Remaining Compliance Issues - Implementation Plan

**Branch**: `feat/user-stories-grantly`  
**Date**: August 10, 2026  
**Status**: Planning Phase  
**Baseline**: PRD-SIMPEG-Fase1-Core v1.4 + User Stories SIMPEG Fase 1

## Executive Summary

After completing TASK 1 (duplicate NIP detection), TASK 2 (pension milestone priority), and TASK 3 (config version validation), there remain **5 critical compliance issues** that must be resolved before this branch can be merged to development.

### Current Status
- ✅ **COMPLETED**: Duplicate NIP detection priority (TASK 1)
- ✅ **COMPLETED**: Manual pension date prioritization (TASK 2)  
- ✅ **COMPLETED**: Config version validation for milestones (TASK 3)
- ⏳ **PENDING**: 5 compliance issues (detailed below)

### Branch Status
⚠️ **CRITICAL**: Branch is 13 commits behind `development` and GitHub reports **NOT MERGEABLE**. This must be resolved before any code review or merge.

---

## Issue Priority Matrix

| Priority | Issue | User Story | Complexity | Est. Time | Blocker? |
|----------|-------|------------|------------|-----------|----------|
| 🔴 P0 | Editable Column Mapping | US-3.2 | High | 3-5 days | YES |
| 🔴 P0 | Branch Rebase/Merge | N/A | Medium | 4-6 hours | YES |
| 🔴 P1 | Complete Verifier Context | US-4.5 | Medium | 1-2 days | YES |
| 🟠 P2 | Legacy Import Endpoint | US-3.3 | Low | 2-4 hours | NO |
| 🟠 P2 | TMT Update Flow | US-5.5 | Medium | 1 day | NO |

---

## ISSUE 1: Editable Column Mapping (US-3.2) 🔴

### Problem Description
**File**: `resources/views/admin/pegawai/import.blade.php`

The current implementation shows a **read-only** hardcoded mapping panel. The PRD mandates:
- Each source column must be mapped to a SIMPEG field or "Tidak dipakai"
- Admin can change mapping via dropdown **before validation**
- Mapping is saved on the batch and reused by preview, validation, and execution
- Duplicate targets must be rejected
- Required targets that aren't mapped must block validation
- **Role must NOT be a valid import target** and should be warned as extra column

### Current Behavior
- `UploadImportBatchAction` rejects headers that don't match template before user can do manual mapping
- Role is still considered a "known header" and mapped to `role` field
- No UI for editing mappings
- Mapping state is not persisted on batch

### Acceptance Criteria (US-3.2 AC-4/AC-5)
1. Admin sees dropdown for each source column with:
   - All valid SIMPEG field targets
   - "Tidak dipakai" option
2. Changes are saved to `import_batches.column_mapping` JSON field
3. Validation uses the saved mapping
4. Duplicate target fields are rejected with error
5. Required fields not mapped block validation
6. Role field shows warning and is excluded from valid targets

### Implementation Steps

#### Phase 1: Data Model (1 day)
- [ ] Add `column_mapping` JSON column to `import_batches` table
- [ ] Create migration with default empty object `{}`
- [ ] Update `ImportBatch` model with cast: `'column_mapping' => 'array'`
- [ ] Define validation rules for mapping structure

#### Phase 2: Backend Logic (2 days)
- [ ] Modify `UploadImportBatchAction`:
  - Accept ANY headers (don't reject unknown headers early)
  - Initialize default mapping for known headers
  - Mark unknown headers as "Tidak dipakai"
  - Save mapping to batch
- [ ] Create `UpdateColumnMappingAction`:
  - Accept mapping payload from UI
  - Validate: no duplicate targets, all required fields mapped
  - Reject "Role" as valid target
  - Save to `import_batches.column_mapping`
  - Return validation result
- [ ] Update `ValidateImportBatchAction`:
  - Read mapping from `batch->column_mapping`
  - Apply mapping to each row
  - Use mapped fields for validation
- [ ] Update `ImportEmployeesAction`:
  - Use mapping from batch, not hardcoded template

#### Phase 3: Frontend UI (1-2 days)
- [ ] Update `import.blade.php`:
  - Replace read-only mapping panel with editable form
  - Add dropdown for each source column
  - Populate dropdown with valid SIMPEG fields + "Tidak dipakai"
  - Show validation errors (duplicate targets, missing required)
  - Add "Simpan Mapping" button
  - Show warning for Role column
- [ ] Add JavaScript for:
  - AJAX call to save mapping
  - Real-time duplicate detection
  - Visual feedback for validation errors
  - Enable/disable validation button based on mapping state

#### Phase 4: Testing (1 day)
- [ ] Test: Unknown headers can be mapped to valid fields
- [ ] Test: Duplicate target mapping is rejected
- [ ] Test: Required fields not mapped block validation
- [ ] Test: "Tidak dipakai" columns are ignored in validation
- [ ] Test: Role column cannot be selected as target
- [ ] Test: Mapping persists across preview/validation/execution
- [ ] Test: Changing mapping triggers re-validation

### Files to Modify
- `database/migrations/YYYY_MM_DD_add_column_mapping_to_import_batches.php` (new)
- `app/Models/ImportBatch.php`
- `app/Actions/Employees/UploadImportBatchAction.php`
- `app/Actions/Employees/UpdateColumnMappingAction.php` (new)
- `app/Actions/Employees/ValidateImportBatchAction.php`
- `app/Actions/Employees/ImportEmployeesAction.php`
- `resources/views/admin/pegawai/import.blade.php`
- `routes/web.php` (add mapping update route)
- `tests/Feature/EmployeeImportMappingTest.php` (new)

### Risks & Mitigation
- **Risk**: Breaking existing imports in production
  - **Mitigation**: Add feature flag, migrate existing batches with default mapping
- **Risk**: Complex UI state management
  - **Mitigation**: Use Alpine.js or Vue for reactive dropdown management

---

## ISSUE 2: Branch 13 Commits Behind Development 🔴

### Problem Description
Branch `feat/user-stories-grantly` is **13 commits behind** `development` and GitHub reports **NOT MERGEABLE**. This creates:
- High risk of merge conflicts
- Cannot be reviewed or merged in current state
- Potential loss of newer fixes from development (e.g., US-5.4 completion)

### Solution Options

#### Option A: Rebase onto Development (RECOMMENDED)
**Pros**:
- Clean linear history
- All conflicts resolved locally before push
- Easier to review

**Cons**:
- May require force push if already shared
- Time-consuming conflict resolution

**Steps**:
1. Backup current branch: `git branch feat/user-stories-grantly-backup`
2. Fetch latest: `git fetch origin`
3. Rebase: `git rebase origin/development`
4. Resolve conflicts carefully:
   - **CRITICAL**: Don't overwrite US-5.4 `EwsAlert.is_eligible` sync from development
   - Preserve milestone invalidation logic from this branch
   - Merge both implementations for `EwsEngineService`
5. Run full test suite after each conflict resolution
6. Force push: `git push origin feat/user-stories-grantly --force-with-lease`

#### Option B: Merge Development into Branch
**Pros**:
- Preserves exact commit history
- No force push needed

**Cons**:
- Creates merge commit
- Harder to review changes
- May hide conflicts

**Steps**:
1. Fetch latest: `git fetch origin`
2. Merge: `git merge origin/development`
3. Resolve conflicts
4. Commit merge
5. Push: `git push origin feat/user-stories-grantly`

### Recommended Approach
**Use Option A (Rebase)** with these precautions:
- Do rebase in dedicated session (4-6 hours)
- Test after EACH conflict resolution
- Pay special attention to `EwsEngineService` and `EwsAlert` changes
- Verify US-5.4 behavior is NOT regressed

### Files with High Conflict Risk
- `app/Services/EwsEngineService.php`
- `app/Models/EwsAlert.php`
- `app/Actions/Ews/UpdateEwsConfigAction.php`
- `tests/Feature/EwsEngineServiceTest.php`

### Estimated Time
- **4-6 hours** (includes testing)

---

## ISSUE 3: Complete Verifier Context (US-4.5) 🔴

### Problem Description
**File**: `app/Actions/Cuti/PreviewLeaveBalanceAction.php`

The verifier sees incomplete context when reviewing leave requests. PRD requires:
1. Balance for current year
2. Carry-over from N-1 and N-2
3. **Cuti bersama** (shared holidays)
4. **Leave history** (approved annual leave for N, N-1, N-2)
5. **Exclude current request's reservation** from balance calculation

### Current Behavior
- Shows snapshot balance (current/N-1/N-2)
- "Riwayat Penggunaan" only shows aggregate numbers, not actual request history
- No `RefHariLibur` data for cuti bersama
- `PreviewLeaveBalanceAction` includes ALL active reservations, including the one being reviewed
- Result: If balance is 12 and current request is 10 days, verifier sees "2 days remaining" instead of "12 days, 10 requested"

### Acceptance Criteria (US-4.5 AC-2)
1. Verifier sees:
   - Current year balance
   - N-1 and N-2 carry-over amounts
   - List of cuti bersama dates for target year
   - List of approved leave requests (N, N-1, N-2) with dates and days
2. Balance calculation excludes the current request being reviewed
3. UI clearly separates "Available Balance" from "Requested Days"

### Implementation Steps

#### Phase 1: Cuti Bersama Integration (4 hours)
- [ ] Modify `PreviewLeaveBalanceAction`:
  - Accept `exclude_request_id` parameter
  - Query `RefHariLibur` for target year where `jenis_libur` = 'Cuti Bersama'
  - Exclude specified request from reservation calculation
  - Return cuti bersama dates in response
- [ ] Update balance calculation:
  ```php
  $reservations = LeaveBalanceReservation::where('employee_id', $employeeId)
      ->where('year', $targetYear)
      ->where('is_active', true)
      ->when($excludeRequestId, fn($q) => $q->where('leave_request_id', '!=', $excludeRequestId))
      ->sum('days');
  ```

#### Phase 2: Leave History (4 hours)
- [ ] Create `GetLeaveHistoryAction`:
  - Query approved `LeaveRequest` for employee
  - Filter by years: N, N-1, N-2
  - Only include annual leave (`jenis_cuti` = 'Tahunan')
  - Return: request ID, dates, days, type, status
  - Order by date DESC
  - Limit to 50 most recent
- [ ] Add to preview response:
  ```php
  'leave_history' => [
      'current_year' => [...],
      'n_minus_1' => [...],
      'n_minus_2' => [...]
  ]
  ```

#### Phase 3: UI Updates (4 hours)
- [ ] Update `resources/views/admin/cuti/show.blade.php`:
  - Add "Cuti Bersama" section showing dates
  - Add "Riwayat Cuti Tahunan" accordion with:
    - Tahun N (list of requests)
    - Tahun N-1 (list of requests)
    - Tahun N-2 (list of requests)
  - Each item shows: dates, days, status badge
  - Change balance display:
    - "Saldo Tersedia: X hari"
    - "Pengajuan Ini: Y hari"
    - "Sisa Setelah Disetujui: Z hari"

#### Phase 4: Controller Integration (2 hours)
- [ ] Update approval controllers:
  - Pass current `leave_request_id` to `PreviewLeaveBalanceAction`
  - Pass preview data to view
  - Update decision actions to use corrected balance

#### Phase 5: Testing (4 hours)
- [ ] Test: Cuti bersama dates appear in preview
- [ ] Test: Leave history shows correct approved requests
- [ ] Test: Balance excludes current request's reservation
- [ ] Test: Multi-year history is separated correctly
- [ ] Test: Large history (>50 requests) is paginated
- [ ] Test: UI renders all data without layout breaks

### Files to Modify
- `app/Actions/Cuti/PreviewLeaveBalanceAction.php`
- `app/Actions/Cuti/GetLeaveHistoryAction.php` (new)
- `app/Http/Controllers/Admin/Cuti/ApprovalController.php`
- `resources/views/admin/cuti/show.blade.php`
- `tests/Feature/CutiVerifierContextTest.php` (new)

### Estimated Time
- **1-2 days** (18 hours total)

---

## ISSUE 4: Legacy Import Endpoint Alignment (US-3.3) 🟠

### Problem Description
**File**: `app/Actions/Employees/ImportEmployeesAction.php`

Two import paths exist with **different business rules**:
1. **Wizard**: `/admin/pegawai/import` (US-3.3 compliant)
   - NIP existing in DB → skip
   - Duplicate NIP in file → error
   - Email existing in DB → error
2. **Legacy**: `/api/v1/pegawai/import` (NOT compliant)
   - NIP existing in DB → **error** (should be skip)
   - Uses `ImportEmployeesAction` directly

### Canonical Rule (K-US-02)
- NIP existing in database → **skip**
- Duplicate NIP within file → **error**
- Email existing in database → **error**

### Current Problem
`ImportEmployeesAction` treats NIP existing as error, violating K-US-02. Additionally, in wizard flow, when a row has both "NIP existing" (skip) and "email existing" (error), the skip check happens first and the email error is hidden.

### Implementation Steps

#### Option A: Align Legacy Endpoint (RECOMMENDED)
- [ ] Update `ImportEmployeesAction`:
  - Change NIP existing from error to skip
  - Prioritize `$databaseErrors` before `$skipErrors`
  - Return skip list and error list separately
- [ ] Update API response to include skipped rows
- [ ] Add test: legacy endpoint follows K-US-02

#### Option B: Deprecate Legacy Endpoint
- [ ] Remove `/api/v1/pegawai/import` route
- [ ] Update API documentation
- [ ] Force all clients to use wizard

### Recommended: Option A
Align the legacy endpoint to K-US-02 for consistency.

### Files to Modify
- `app/Actions/Employees/ImportEmployeesAction.php`
- `app/Http/Controllers/Api/V1/EmployeeController.php`
- `tests/Feature/EmployeeImportTest.php`

### Estimated Time
- **2-4 hours**

---

## ISSUE 5: TMT Update Flow Milestone Sync (US-5.5) 🟠

### Problem Description
**File**: `app/Actions/Employees/UpdateEmployeeAction.php`

When admin updates `tmt_pengangkatan` via UI:
1. `UpdateEmployeeAction` calls `TmtCalculatorService::syncForEmployee()` **before** appointment is updated
2. Appointment is updated
3. No second sync happens
4. Satyalancana 10/20/30 milestones remain stale until another event triggers sync

Test `test_invalidates_old_satyalancana_milestones_when_tmt_changes` manually calls `syncForEmployee()` after update, so it doesn't catch this production flow bug.

### Acceptance Criteria (US-5.5 AC-4)
- Changing TMT pengangkatan through UI must trigger milestone sync **after** appointment is saved
- Regression test must use actual production update flow, not manual sync

### Implementation Steps

#### Phase 1: Fix Update Flow (2 hours)
- [ ] Update `UpdateEmployeeAction`:
  ```php
  // After appointment update
  if ($appointment->wasChanged('tmt_pengangkatan')) {
      app(TmtCalculatorService::class)->syncForEmployee($employee);
  }
  ```
- [ ] Alternative: Use Eloquent observer on `Appointment` model
  ```php
  public function updated(Appointment $appointment) {
      if ($appointment->wasChanged('tmt_pengangkatan')) {
          $employee = $appointment->employee;
          app(TmtCalculatorService::class)->syncForEmployee($employee);
      }
  }
  ```

#### Phase 2: Production Flow Test (2 hours)
- [ ] Create `TmtUpdateIntegrationTest.php`:
  - Create employee with appointment
  - Sync milestones (creates Satyalancana)
  - Update TMT via `UpdateEmployeeAction` (NOT manual sync)
  - Assert old milestones invalidated
  - Assert new milestones created with correct dates
- [ ] Verify existing invalidation tests still pass

### Files to Modify
- `app/Actions/Employees/UpdateEmployeeAction.php` OR
- `app/Observers/AppointmentObserver.php` (new)
- `app/Providers/EventServiceProvider.php` (register observer)
- `tests/Feature/TmtUpdateIntegrationTest.php` (new)

### Estimated Time
- **1 day** (4-6 hours)

---

## Recommended Implementation Sequence

### Phase 1: Blockers (Week 1)
1. **Branch Rebase** (4-6 hours, HIGH RISK)
   - Do this FIRST before any other work
   - Resolve conflicts carefully
   - Test thoroughly
2. **Editable Column Mapping** (3-5 days)
   - Highest complexity
   - Most user-facing impact

### Phase 2: Critical (Week 2)
3. **Complete Verifier Context** (1-2 days)
   - User-facing, impacts decision quality
4. **Legacy Endpoint Alignment** (2-4 hours)
   - Quick fix, high consistency impact

### Phase 3: Production Safety (Week 2-3)
5. **TMT Update Flow** (1 day)
   - Less urgent, but important for data accuracy

---

## Testing Strategy

### Unit Tests
- Each Action must have dedicated test class
- Test all error paths and edge cases
- Mock external dependencies

### Integration Tests
- Test full UI → Controller → Action → Database flows
- Use production-like data scenarios
- Verify audit logs are written

### Regression Tests
- Run FULL test suite after each issue fix
- Verify no existing tests break
- Pay special attention to:
  - `EmployeeImportTest` (19 tests)
  - `TmtCalculatorMilestoneInvalidationTest` (10 tests)
  - `EwsConfigMilestoneValidationTest` (6 tests)
  - `EwsFollowupTest` (pension tests)

### Manual QA Checklist
- [ ] Import employee with unknown column, map manually
- [ ] Try to map Role column (should be rejected)
- [ ] Approve leave request, verify context is complete
- [ ] Update TMT via UI, verify milestones update
- [ ] Legacy import endpoint follows same rules as wizard

---

## Risk Assessment

### High Risk
- **Branch Rebase**: Conflicts with US-5.4 could break EWS eligibility sync
- **Column Mapping**: Complex UI state, breaking change to import flow

### Medium Risk
- **Verifier Context**: Query performance with large leave history
- **TMT Update**: Observer pattern could cause cascade issues

### Low Risk
- **Legacy Endpoint**: Isolated change, well-tested

---

## Definition of Done

For each issue:
- [ ] Implementation complete and code-reviewed
- [ ] All new tests passing (unit + integration)
- [ ] No regressions in existing test suite
- [ ] Code formatting passes `composer format:check`
- [ ] Documentation updated (if API changes)
- [ ] Manual QA completed
- [ ] Audit logs verified

For branch:
- [ ] All 5 issues resolved
- [ ] Branch rebased/merged with development
- [ ] Full test suite passes (Feature + Unit)
- [ ] No merge conflicts
- [ ] PR updated with implementation summary
- [ ] QA checklist completed

---

## Resources

### Key Files Reference
- Import: `app/Actions/Employees/*.php`, `resources/views/admin/pegawai/import.blade.php`
- Leave: `app/Actions/Cuti/*.php`, `resources/views/admin/cuti/show.blade.php`
- Milestones: `app/Services/Employees/TmtCalculatorService.php`, `app/Services/EwsEngineService.php`
- Tests: `tests/Feature/*Test.php`

### Documentation
- PRD: `PRD-SIMPEG-Fase1-Core v1.4`
- User Stories: `User Stories SIMPEG Fase 1`
- QA Checklist: `QA-CHECKLIST-GRANTLY.md`
- Completion Report: `COMPLETION-REPORT-GRANTLY-7AUG2026.md`

### Team Contacts
- Product Owner: [Name]
- Tech Lead: [Name]
- QA Lead: [Name]

---

## Next Steps

1. **Immediate** (Today):
   - ✅ Commit current fixes (TASK 2 & 3)
   - ✅ Create this implementation plan
   - ⏳ Review plan with team
   - ⏳ Decide: Rebase now or after Issue 1?

2. **This Week**:
   - Start Issue 2 (Branch Rebase) OR Issue 1 (Column Mapping)
   - Daily standup progress updates

3. **Next Week**:
   - Complete remaining issues
   - Full regression testing
   - Prepare for PR review

---

**Plan Created**: August 10, 2026  
**Last Updated**: August 10, 2026  
**Author**: Development Team  
**Status**: Ready for Review
