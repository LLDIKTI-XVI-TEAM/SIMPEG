<x-layouts.app title="Data Pegawai">
    {{-- Capability halaman (isReadOnly, canManageSkRequirements, dst.) dihitung
         permission-driven oleh PimpinanEmployeeController dan diwariskan include.
         Link detail diarahkan ke canonical RBAC agar scope dan permission granular konsisten. --}}
    @include('admin.pegawai.index', [
        'employeeShowUrlPrefix' => url('/rbac/pegawai')
    ])
</x-layouts.app>
