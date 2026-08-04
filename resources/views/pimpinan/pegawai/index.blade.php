<x-layouts.app title="Data Pegawai">
    @include('admin.pegawai.index', [
        'isReadOnly' => true,
        'employeeShowUrlPrefix' => route('pimpinan.pegawai.index')
    ])
</x-layouts.app>
