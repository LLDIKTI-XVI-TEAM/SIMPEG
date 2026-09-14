<x-layouts.app title="Data Pegawai">
    {{-- Capability halaman granular per-aksi dihitung permission-driven oleh
         PimpinanEmployeeController (canUpdate/canDeactivate/canRestore/canCreate/
         canImport/canManageSk/canExport/canChangeStatus) dan diwariskan include.
         Link detail diarahkan ke canonical RBAC agar scope dan permission granular konsisten. --}}
    @include('admin.pegawai.index', [
        'employeeShowUrlPrefix' => url('/pimpinan/pegawai'),
        'isReadOnly' => $isReadOnly ?? false,
        'isPimpinan' => true,
        'canManageSkRequirements' => $canManageSkRequirements ?? false,
        'canCreateEmployee' => $canCreateEmployee ?? false,
        'canImportEmployees' => $canImportEmployees ?? false,
        'canUpdateEmployee' => $canUpdateEmployee ?? false,
        'canDeactivateEmployee' => $canDeactivateEmployee ?? false,
        'canRestoreEmployee' => $canRestoreEmployee ?? false,
        'canExportEmployees' => $canExportEmployees ?? false,
        'canChangeStatus' => $canChangeStatus ?? false,
        'skRequirementMatrix' => $skRequirementMatrix ?? ['skPool' => [], 'current' => [], 'namesByType' => [], 'lockedTypes' => []],
        'skRequirementVersion' => $skRequirementVersion ?? 'unversioned',
    ])
</x-layouts.app>
