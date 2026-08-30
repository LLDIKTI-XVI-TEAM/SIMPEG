<x-layouts.app title="Data Pegawai">
    {{-- Capability halaman (isReadOnly, canManageSkRequirements, dst.) dihitung
         permission-driven oleh PimpinanEmployeeController dan diwariskan include. --}}
    @include('admin.pegawai.index', [
        'employeeShowUrlPrefix' => route('pimpinan.pegawai.index')
    ])
</x-layouts.app>
