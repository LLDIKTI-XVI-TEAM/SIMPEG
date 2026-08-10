# Compliance Issues Tracking Checklist

**Branch**: `feat/user-stories-grantly`  
**Last Updated**: August 10, 2026  
**Baseline**: PRD-SIMPEG-Fase1-Core v1.4

---

## Completed ✅

### ✅ TASK 1: Import Duplicate NIP Detection Priority
- [x] Remove unique constraints from validation rules
- [x] Prioritize duplicate-in-file errors before skip logic
- [x] Add manual database check
- [x] Test: duplicate_nip_in_file_is_error_even_if_nip_exists_in_database
- [x] All 19 EmployeeImportTest tests passing
- [x] **Committed**: (previous session)

### ✅ TASK 2: Manual Pension Date Priority (US-5.5)
- [x] Fix TmtCalculatorService line 125 conditional logic
- [x] Only check $hadManualPensionDate flag
- [x] Add metadata: is_manual, source, bup, jabatan
- [x] Test: test_pension_milestone_prioritizes_manual_tanggal_pensiun_over_calculated_bup
- [x] Test: test_pension_milestone_falls_back_to_bup_calculation_when_manual_date_absent
- [x] Test: test_pension_milestone_updates_when_manual_date_changes
- [x] All 3 pension tests passing
- [x] **Committed**: `30cce8c` (August 10, 2026)

### ✅ TASK 3: Config Version Validation (US-5.5)
- [x] Add required_years validation in EwsEngineService
- [x] Return null when milestone config doesn't match
- [x] Invalidate milestones on config change in UpdateEwsConfigAction
- [x] Create EwsConfigMilestoneValidationTest.php (6 tests)
- [x] All 6 config validation tests passing
- [x] **Committed**: `30cce8c` (August 10, 2026)

---

## In Progress 🟡

### 🔴 ISSUE 1: Editable Column Mapping (US-3.2) - P0 BLOCKER
**Status**: Not Started  
**Assigned**: TBD  
**Est. Time**: 3-5 days  
**Priority**: P0 - BLOCKER

#### Phase 1: Data Model (1 day)
- [ ] Add column_mapping JSON column to import_batches table
- [ ] Create migration with default empty object
- [ ] Update ImportBatch model with array cast
- [ ] Define validation rules for mapping structure

#### Phase 2: Backend Logic (2 days)
- [ ] Modify UploadImportBatchAction to accept any headers
- [ ] Create UpdateColumnMappingAction
- [ ] Update ValidateImportBatchAction to use mapping
- [ ] Update ImportEmployeesAction to use mapping
- [ ] Remove Role from valid import targets

#### Phase 3: Frontend UI (1-2 days)
- [ ] Replace read-only panel with editable form
- [ ] Add dropdown for each source column
- [ ] Populate dropdown with valid fields + "Tidak dipakai"
- [ ] Show validation errors (duplicate targets, missing required)
- [ ] Add "Simpan Mapping" button
- [ ] Show warning for Role column
- [ ] Add AJAX call to save mapping
- [ ] Real-time duplicate detection

#### Phase 4: Testing (1 day)
- [ ] Test: Unknown headers can be mapped to valid fields
- [ ] Test: Duplicate target mapping is rejected
- [ ] Test: Required fields not mapped block validation
- [ ] Test: "Tidak dipakai" columns are ignored
- [ ] Test: Role column cannot be selected as target
- [ ] Test: Mapping persists across preview/validation/execution
- [ ] Test: Changing mapping triggers re-validation

#### Definition of Done
- [ ] All tests passing
- [ ] Code formatting verified
- [ ] Manual QA completed
- [ ] Documentation updated
- [ ] Committed to branch

---

### 🔴 ISSUE 2: Branch Rebase/Merge - P0 BLOCKER
**Status**: Not Started  
**Assigned**: TBD  
**Est. Time**: 4-6 hours  
**Priority**: P0 - BLOCKER

#### Preparation
- [ ] Backup branch: `git branch feat/user-stories-grantly-backup`
- [ ] Fetch latest: `git fetch origin`
- [ ] Review conflict areas (EwsEngineService, EwsAlert)

#### Rebase Execution
- [ ] Start rebase: `git rebase origin/development`
- [ ] Resolve conflicts in EwsEngineService
- [ ] Preserve US-5.4 is_eligible sync from development
- [ ] Preserve milestone invalidation from this branch
- [ ] Run tests after each conflict resolution
- [ ] Continue rebase: `git rebase --continue`

#### Verification
- [ ] All Feature tests pass
- [ ] All Unit tests pass
- [ ] Code formatting passes
- [ ] US-5.4 behavior NOT regressed
- [ ] US-5.5 behavior still working

#### Push
- [ ] Force push with lease: `git push origin feat/user-stories-grantly --force-with-lease`
- [ ] Verify GitHub shows mergeable

#### Definition of Done
- [ ] Branch is even with or ahead of development
- [ ] GitHub reports "able to merge"
- [ ] All tests passing
- [ ] No conflicts
- [ ] Team notified of rebase completion

---

### 🔴 ISSUE 3: Complete Verifier Context (US-4.5) - P1 CRITICAL
**Status**: Not Started  
**Assigned**: TBD  
**Est. Time**: 1-2 days  
**Priority**: P1 - CRITICAL

#### Phase 1: Cuti Bersama Integration (4 hours)
- [ ] Modify PreviewLeaveBalanceAction to accept exclude_request_id
- [ ] Query RefHariLibur for cuti bersama dates
- [ ] Exclude current request from reservation calculation
- [ ] Return cuti bersama dates in response

#### Phase 2: Leave History (4 hours)
- [ ] Create GetLeaveHistoryAction
- [ ] Query approved LeaveRequest for N, N-1, N-2
- [ ] Filter by annual leave only
- [ ] Return structured history by year
- [ ] Order by date DESC, limit 50

#### Phase 3: UI Updates (4 hours)
- [ ] Add "Cuti Bersama" section showing dates
- [ ] Add "Riwayat Cuti Tahunan" accordion
- [ ] Show Tahun N, N-1, N-2 sections
- [ ] Display: dates, days, status badge
- [ ] Change balance display: Available / Requested / Remaining

#### Phase 4: Controller Integration (2 hours)
- [ ] Update approval controllers to pass leave_request_id
- [ ] Pass preview data to view
- [ ] Update decision actions to use corrected balance

#### Phase 5: Testing (4 hours)
- [ ] Test: Cuti bersama dates appear
- [ ] Test: Leave history shows correct requests
- [ ] Test: Balance excludes current reservation
- [ ] Test: Multi-year history separated correctly
- [ ] Test: Large history (>50) is paginated
- [ ] Test: UI renders without layout breaks

#### Definition of Done
- [ ] All tests passing
- [ ] Manual QA with real data
- [ ] Performance verified (<200ms queries)
- [ ] Committed to branch

---

### 🟠 ISSUE 4: Legacy Import Endpoint (US-3.3) - P2
**Status**: Not Started  
**Assigned**: TBD  
**Est. Time**: 2-4 hours  
**Priority**: P2

#### Implementation
- [ ] Update ImportEmployeesAction: NIP existing → skip (not error)
- [ ] Prioritize $databaseErrors before $skipErrors
- [ ] Return skip list and error list separately
- [ ] Update API response to include skipped rows
- [ ] Update API documentation

#### Testing
- [ ] Test: Legacy endpoint follows K-US-02
- [ ] Test: NIP existing returns skip status
- [ ] Test: Email existing returns error
- [ ] Test: Duplicate NIP in file returns error
- [ ] Test: API response includes skip_count

#### Definition of Done
- [ ] All tests passing
- [ ] API docs updated
- [ ] Postman collection updated
- [ ] Committed to branch

---

### 🟠 ISSUE 5: TMT Update Flow (US-5.5) - P2
**Status**: Not Started  
**Assigned**: TBD  
**Est. Time**: 1 day  
**Priority**: P2

#### Phase 1: Fix Update Flow (2 hours)
- [ ] Update UpdateEmployeeAction to sync after appointment change
- [ ] OR: Create AppointmentObserver
- [ ] Register observer in EventServiceProvider
- [ ] Verify sync only triggers when tmt_pengangkatan changes

#### Phase 2: Production Flow Test (2 hours)
- [ ] Create TmtUpdateIntegrationTest.php
- [ ] Test: Update TMT via UpdateEmployeeAction (not manual sync)
- [ ] Assert old milestones invalidated
- [ ] Assert new milestones created
- [ ] Verify existing invalidation tests still pass

#### Definition of Done
- [ ] All tests passing
- [ ] Production flow verified
- [ ] No duplicate sync calls
- [ ] Committed to branch

---

## Overall Status

### Completion Summary
- ✅ **Completed**: 3 tasks (import duplicate, pension priority, config validation)
- 🟡 **In Progress**: 5 issues (mapping, rebase, verifier, legacy, tmt)
- ⏸️ **Blocked**: 0 issues

### Test Status
- ✅ EmployeeImportTest: 19/19 passing
- ✅ TmtCalculatorMilestoneInvalidationTest: 10/10 passing
- ✅ EwsConfigMilestoneValidationTest: 6/6 passing
- ✅ Code Formatting: PASS (654 files, 0 issues)

### Branch Status
- **Current Head**: `30cce8c`
- **Behind Development**: 13 commits ⚠️
- **Mergeable**: NO ⚠️
- **Action Required**: Rebase

### Timeline
- **Completed Work**: ~3 days
- **Remaining Work**: 6-10 days
- **Total Estimate**: 9-13 days
- **Target Completion**: TBD (after team planning)

---

## Decision Points

### High Priority Decisions Needed
1. **Rebase Timing**: Do we rebase before or after Issue 1 (Column Mapping)?
   - **Option A**: Rebase now (clean slate, but conflicts may complicate mapping work)
   - **Option B**: Complete Issue 1 first, then rebase (less disruption, but more divergence)
   - **Recommendation**: Rebase now to minimize divergence

2. **Column Mapping Approach**: UI framework choice
   - **Option A**: Plain JavaScript + Blade
   - **Option B**: Alpine.js (already in project)
   - **Option C**: Vue component
   - **Recommendation**: Alpine.js (consistency with existing code)

3. **TMT Update Implementation**: Action vs Observer pattern
   - **Option A**: Add sync call in UpdateEmployeeAction
   - **Option B**: Create AppointmentObserver
   - **Recommendation**: Observer pattern (better separation of concerns)

### Medium Priority Decisions
4. **Legacy Endpoint**: Align or deprecate?
   - **Recommendation**: Align (maintain backward compatibility)

5. **Verifier History Limit**: 50 requests or paginated?
   - **Recommendation**: 50 with "View All" link for edge cases

---

## Resources

### Key Contacts
- Product Owner: [Name]
- Tech Lead: [Name]
- QA Lead: [Name]

### Reference Documents
- [REMAINING-COMPLIANCE-ISSUES-PLAN.md](./REMAINING-COMPLIANCE-ISSUES-PLAN.md) - Detailed implementation guide
- [SESSION-SUMMARY-AUG10-2026.md](./SESSION-SUMMARY-AUG10-2026.md) - What was completed this session
- [PULL-REQUEST-DESCRIPTION.md](../PULL-REQUEST-DESCRIPTION.md) - PR description
- [QA-CHECKLIST-GRANTLY.md](../../simpeg-diagram-lldikti/DOCUMENT/QA-CHECKLIST-GRANTLY.md) - QA testing checklist

---

## Notes

### Risk Flags 🚩
- Branch 13 commits behind development - MUST resolve before merge
- US-5.4 in development must not be regressed during rebase
- Column mapping is breaking change - needs migration strategy

### Quick Wins 🎯
- Issue 4 (Legacy Endpoint): 2-4 hours, low risk, high consistency value
- Issue 5 (TMT Update): 1 day, well-isolated, clear test path

### Long Poles 🐌
- Issue 1 (Column Mapping): 3-5 days, complex UI, breaking change
- Issue 2 (Rebase): 4-6 hours, high risk of conflicts

---

**Checklist Owner**: Development Team  
**Last Review**: August 10, 2026  
**Next Review**: TBD
