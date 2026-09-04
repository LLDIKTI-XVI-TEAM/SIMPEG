<x-layouts.app title="Data Master">

    @php
        $tabs = [
            'golongan' => 'Golongan',
            'jenis_jabatan' => 'Jenis Jabatan',
            'jabatan' => 'Jabatan',
            'eselon' => 'Eselon',
            'status_pegawai' => 'Status Pegawai',
            'jenis_cuti' => 'Jenis Cuti',
            'agama' => 'Agama',
            'jenis_kelamin' => 'Jenis Kelamin',
            'status_perkawinan' => 'Status Perkawinan',
            'jenjang_pendidikan' => 'Jenjang Pendidikan',
            'program_studi' => 'Program Studi',
            'unit_kerja' => 'Unit Kerja',
        ];

        $dataJenisCuti = [
            ['id' => 1, 'nama' => 'Cuti Tahunan', 'khusus_pns' => 'Tidak'],
            ['id' => 2, 'nama' => 'Cuti Sakit', 'khusus_pns' => 'Tidak'],
            ['id' => 3, 'nama' => 'Cuti Melahirkan', 'khusus_pns' => 'Tidak'],
            ['id' => 4, 'nama' => 'Cuti Karena Alasan Penting', 'khusus_pns' => 'Tidak'],
            ['id' => 5, 'nama' => 'Cuti Besar', 'khusus_pns' => 'Ya'],
            ['id' => 6, 'nama' => 'Cuti Luar Tanggungan Negara (CLTN)', 'khusus_pns' => 'Ya'],
        ];

        $dataAgama = [
            ['id' => 1, 'nama' => 'Islam'],
            ['id' => 2, 'nama' => 'Kristen Protestan'],
            ['id' => 3, 'nama' => 'Katolik'],
            ['id' => 4, 'nama' => 'Hindu'],
            ['id' => 5, 'nama' => 'Buddha'],
            ['id' => 6, 'nama' => 'Konghucu'],
        ];

        $dataJenisKelamin = [
            ['id' => 1, 'kode' => 'L', 'nama' => 'Laki-laki'],
            ['id' => 2, 'kode' => 'P', 'nama' => 'Perempuan'],
        ];

        $dataStatusPerkawinan = [
            ['id' => 1, 'nama' => 'Belum Kawin'],
            ['id' => 2, 'nama' => 'Kawin'],
            ['id' => 3, 'nama' => 'Cerai Hidup'],
            ['id' => 4, 'nama' => 'Cerai Mati'],
        ];

    @endphp


    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink font-sans">Data Master / Reference Tables</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Data Master']
            ]" />
        </div>
    </div>

    <div x-data="{ 
        activeTab: '{{ old('tab', session('data_master_tab') ?: 'golongan') }}',
        isModalOpen: false, 
        modalMode: 'tambah',
        isDeleteModalOpen: false,
        deleteItemName: '',
        tabs: {{ json_encode($tabs) }},
        openModal(mode) { this.modalMode = mode; this.isModalOpen = true; }, 
        closeModal() { this.isModalOpen = false; },
        openDeleteConfirm(item) { this.deleteItemName = item.name || item.nama || item.kode || item.jenis; $dispatch('open-confirm-delete-master'); },
        closeDeleteModal() { this.isDeleteModalOpen = false; }
    }" class="flex flex-col lg:flex-row gap-6 relative">

        {{-- Tab Sidebar Kiri --}}
        <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-6 lg:self-start">
            <div class="rounded-lg bg-surface p-4 shadow-sm space-y-1">
                <p
                    class="text-[10px] font-bold text-muted uppercase tracking-wide px-3 pb-2 mb-2 font-sans">
                    Kategori Referensi</p>

                <x-ui.tabs variant="sidebar-soft" label="Kategori referensi">
                    @foreach($tabs as $key => $label)
                    <x-ui.tab variant="sidebar-soft" active="activeTab === '{{ $key }}'" click="activeTab = '{{ $key }}'">
                        {{ $label }}
                    </x-ui.tab>
                    @endforeach
                </x-ui.tabs>
            </div>
        </aside>

        {{-- Konten Utama Kanan --}}
        <main class="flex-1 min-w-0 space-y-4">

            {{-- UNIVERSAL WARNING DATA SEED & AUDIT LOG CARD --}}
            <div class="rounded-lg border border-info/20 bg-info/5 p-4 shadow-sm flex flex-col gap-2.5">
                <div class="flex gap-2.5 text-xs text-info items-start">
                    <svg class="w-5 h-5 shrink-0 text-info mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                    </svg>
                    <div>
                        <span class="font-bold">Informasi Integritas Data Master & Audit Log:</span> 
                        <ul class="list-disc pl-4 mt-1 space-y-1 font-sans">
                            <li><strong>Ketentuan Data Seed:</strong> Seluruh data master bawaan (seperti golongan PNS, jenis cuti utama, dan status dasar) dikategorikan sebagai data seed fundamental. Modifikasi atau penghapusan disarankan hanya untuk keperluan penyesuaian khusus.</li>
                            <li><strong>Pencatatan Audit Trail:</strong> Setiap aktivitas penambahan, pembaruan, dan penghapusan data master akan dicatat secara otomatis dalam sistem Audit Log SIMPEG demi menjaga akuntabilitas.</li>
                        </ul>
                    </div>
                </div>
            </div>

            @if (session('success'))
                <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
            @endif
            @if ($errors->any())
                <x-ui.alert variant="danger">
                    <p class="font-bold">Periksa kembali isian Anda:</p>
                    <ul class="list-disc pl-4 mt-1 space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            {{-- TAB: GOLONGAN --}}
            @include('admin.data-master.partials.tab-golongan')

            {{-- TAB: JENIS JABATAN --}}
            @include('admin.data-master.partials.tab-jenis-jabatan')

            {{-- TAB: JABATAN --}}
            @include('admin.data-master.partials.tab-jabatan')

            {{-- TAB: ESELON --}}
            @include('admin.data-master.partials.tab-eselon')

            {{-- TAB: STATUS PEGAWAI --}}
            @include('admin.data-master.partials.tab-status-pegawai')

            {{-- TAB: PROGRAM STUDI --}}
            @include('admin.data-master.partials.tab-program-studi')

            {{-- TAB: JENIS CUTI --}}
            <div x-show="activeTab === 'jenis_cuti'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-border pb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Jenis Cuti</h2>
                        <p class="mt-0.5 max-w-2xl text-[11px] text-muted font-sans leading-normal">Data referensi jenis-jenis
                            cuti dan aturannya.</p>
                    </div>
                    <x-ui.button @click="openModal('tambah')" variant="secondary" class="shrink-0">
                        Tambah
                    </x-ui.button>
                </div>
                <div class="rounded-lg overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th>
                                    ID</x-ui.table-th>
                                <x-ui.table-th>
                                    Nama Jenis Cuti</x-ui.table-th>
                                <x-ui.table-th align="center">
                                    Khusus PNS</x-ui.table-th>
                                <x-ui.table-th>Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach($dataJenisCuti as $item)
                                <x-ui.table-row :interactive="true">
                                    <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $item['id'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item['nama'] }}</x-ui.table-td>
                                    <x-ui.table-td align="center" padding="sm">
                                        @if($item['khusus_pns'] === 'Ya')
                                            <span
                                                class="text-[11px] font-semibold text-primary">Ya</span>
                                        @else
                                            <span
                                                class="text-[11px] font-semibold text-muted">Tidak</span>
                                        @endif
                                    </x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <div class="flex items-center justify-start gap-1.5">
                                            <x-ui.button type="button" @click="openModal('edit')" variant="secondary" size="icon" title="Edit" aria-label="Edit jenis cuti">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </x-ui.button>
                                            <x-ui.button type="button" @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" variant="danger" size="icon" title="Hapus" aria-label="Hapus jenis cuti {{ $item['nama'] }}">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </x-ui.button>
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB: AGAMA --}}
            <div x-show="activeTab === 'agama'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-border pb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Agama</h2>
                        <p class="mt-0.5 max-w-2xl text-[11px] text-muted font-sans leading-normal">Data referensi agama resmi.
                        </p>
                    </div>
                    <x-ui.button @click="openModal('tambah')" variant="secondary" class="shrink-0">
                        Tambah
                    </x-ui.button>
                </div>
                <div class="rounded-lg overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th>
                                    ID</x-ui.table-th>
                                <x-ui.table-th>
                                    Nama Agama</x-ui.table-th>
                                <x-ui.table-th>Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach($dataAgama as $item)
                                <x-ui.table-row :interactive="true">
                                    <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $item['id'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item['nama'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <div class="flex items-center justify-start gap-1.5">
                                            <x-ui.button type="button" @click="openModal('edit')" variant="secondary" size="icon" title="Edit" aria-label="Edit agama">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </x-ui.button>
                                            <x-ui.button type="button" @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" variant="danger" size="icon" title="Hapus" aria-label="Hapus agama {{ $item['nama'] }}">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </x-ui.button>
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB: JENIS KELAMIN --}}
            <div x-show="activeTab === 'jenis_kelamin'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-border pb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Jenis Kelamin</h2>
                        <p class="mt-0.5 max-w-2xl text-[11px] text-muted font-sans leading-normal">Data referensi jenis kelamin.
                        </p>
                    </div>
                    <x-ui.button @click="openModal('tambah')" variant="secondary" class="shrink-0">
                        Tambah
                    </x-ui.button>
                </div>
                <div class="rounded-lg overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th>
                                    ID</x-ui.table-th>
                                <x-ui.table-th>
                                    Kode</x-ui.table-th>
                                <x-ui.table-th>
                                    Nama</x-ui.table-th>
                                <x-ui.table-th>Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach($dataJenisKelamin as $item)
                                <x-ui.table-row :interactive="true">
                                    <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $item['id'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item['kode'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $item['nama'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <div class="flex items-center justify-start gap-1.5">
                                            <x-ui.button type="button" @click="openModal('edit')" variant="secondary" size="icon" title="Edit" aria-label="Edit jenis kelamin">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </x-ui.button>
                                            <x-ui.button type="button" @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" variant="danger" size="icon" title="Hapus" aria-label="Hapus jenis kelamin {{ $item['nama'] }}">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </x-ui.button>
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB: STATUS PERKAWINAN --}}
            <div x-show="activeTab === 'status_perkawinan'"
                class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
                <div class="border-b border-border pb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Status Perkawinan</h2>
                        <p class="mt-0.5 max-w-2xl text-[11px] text-muted font-sans leading-normal">Data referensi status
                            perkawinan.</p>
                    </div>
                    <x-ui.button @click="openModal('tambah')" variant="secondary" class="shrink-0">
                        Tambah
                    </x-ui.button>
                </div>
                <div class="rounded-lg overflow-x-auto">
                    <x-ui.table>
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th>
                                    ID</x-ui.table-th>
                                <x-ui.table-th>
                                    Status</x-ui.table-th>
                                <x-ui.table-th>Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            @foreach($dataStatusPerkawinan as $item)
                                <x-ui.table-row :interactive="true">
                                    <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $item['id'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item['nama'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="sm">
                                        <div class="flex items-center justify-start gap-1.5">
                                            <x-ui.button type="button" @click="openModal('edit')" variant="secondary" size="icon" title="Edit" aria-label="Edit status perkawinan">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </x-ui.button>
                                            <x-ui.button type="button" @click="openDeleteConfirm({ name: '{{ $item['nama'] }}' })" variant="danger" size="icon" title="Hapus" aria-label="Hapus status perkawinan {{ $item['nama'] }}">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </x-ui.button>
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforeach
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- TAB: JENJANG PENDIDIKAN --}}
            @include('admin.data-master.partials.tab-jenjang-pendidikan')

            {{-- TAB: UNIT KERJA --}}
            @include('admin.data-master.partials.tab-unit-kerja')

        </main>

        {{-- MODAL OVERLAY --}}
        <div x-show="isModalOpen" style="display: none;"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 backdrop-blur-sm transition-opacity"
            x-transition.opacity>
            {{-- MODAL CONTENT --}}
            <div @click.away="closeModal()"
                class="w-full max-w-lg rounded-xl bg-surface p-6 shadow-xl" x-transition>

                {{-- HEADER --}}
                <div class="mb-6 flex items-center justify-between pb-4">
                    <h3 class="text-xl font-bold text-ink font-sans"
                        x-text="(modalMode === 'tambah' ? 'Tambah ' : 'Edit ') + tabs[activeTab]"></h3>
                    <x-ui.button type="button" variant="ghost" size="icon" @click="closeModal()" title="Tutup dialog" aria-label="Tutup dialog">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </x-ui.button>
                </div>

                {{-- BODY: Dynamic Forms based on activeTab --}}
                <div class="space-y-4">

                    {{-- Form untuk tab golongan, jenis jabatan, eselon,
                         status pegawai, dan jenjang pendidikan sudah pindah
                         ke form nyata di partial masing-masing tab. Modal ini
                         hanya melayani tab yang masih mock. --}}

                    {{-- Form Jenis Cuti --}}
                    <div x-show="activeTab === 'jenis_cuti'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Jenis Cuti</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Cuti Tahunan">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Khusus PNS?</label>
                            <x-form.select
                               >
                                <option value="Tidak">Tidak</option>
                                <option value="Ya">Ya</option>
                            </x-form.select>
                        </div>
                    </div>

                    {{-- Form Agama --}}
                    <div x-show="activeTab === 'agama'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama Agama</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Islam">
                        </div>
                    </div>

                    {{-- Form Jenis Kelamin --}}
                    <div x-show="activeTab === 'jenis_kelamin'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Kode</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: L">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Nama</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Laki-laki">
                        </div>
                    </div>

                    {{-- Form Status Perkawinan --}}
                    <div x-show="activeTab === 'status_perkawinan'" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink">Status Perkawinan</label>
                            <input type="text"
                                class="w-full rounded-lg border border-primary/15 bg-transparent px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary/30"
                                placeholder="Contoh: Menikah">
                        </div>
                    </div>

                </div>

                {{-- FOOTER --}}
                <div class="mt-8 flex justify-end gap-3 pt-5">
                    <x-ui.button type="button" variant="secondary" @click="closeModal()">Batal</x-ui.button>
                    <x-ui.button type="button" @click="closeModal()">Simpan</x-ui.button>
                </div>

            </div>
        </div>

        {{-- DELETE CONFIRMATION MODAL --}}

        <x-ui.confirm-dialog
            id="delete-master"
            title="Konfirmasi Hapus Data Master"
            message=""
            confirm-text="Hapus (Soft Delete)"
            variant="danger"
        >
            <div class="space-y-4">
                <p class="text-sm text-ink leading-relaxed font-sans font-medium">
                    Apakah Anda yakin ingin menghapus data referensi <strong class="text-danger" x-text="deleteItemName"></strong> ini?
                </p>
                <div class="rounded-lg border border-danger/20 bg-danger/5 p-3 text-xs text-danger flex gap-2 font-sans">
                    <svg class="w-4.5 h-4.5 shrink-0 mt-0.5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <span class="font-bold">⚠️ RESTRIKSI INTEGRITAS:</span> Data master yang saat ini aktif digunakan oleh data pegawai <strong>tidak diperkenankan untuk dihapus</strong> dari sistem.
                    </div>
                </div>
                <p class="text-xs text-muted leading-relaxed font-sans">
                    Metode penghapusan akan menerapkan **Soft Delete** guna menjaga integritas data riwayat historis kepegawaian (audit trail).
                </p>
            </div>
        </x-ui.confirm-dialog>


    </div>

</x-layouts.app>
