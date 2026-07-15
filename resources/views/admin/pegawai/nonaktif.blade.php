<x-layouts.app title="Data Nonaktif / Restore Pegawai">

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Pegawai Nonaktif & Restore</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Data Pegawai', 'url' => route('data-pegawai')],
                ['label' => 'Data Nonaktif']
            ]" />
        </div>
    </div>


    {{-- FILTER BAR --}}
    <x-ui.card padding="none" class="mb-6">
        <form id="filter-form" method="GET" action="{{ route('data-nonaktif') }}" class="flex flex-col gap-4 p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Search input --}}
                <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input id="search-input" name="search" type="text" value="{{ $filters['search'] ?? '' }}" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
                </div>

                {{-- Filter Golongan --}}
                <div class="relative">
                    <select id="filter-golongan" name="golongan" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Golongan</option>
                        @foreach($golonganOptions as $golongan)
                            <option value="{{ $golongan }}" @selected($filters['golongan'] === $golongan)>Golongan {{ $golongan }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Filter Unit --}}
                <div class="relative">
                    <select id="filter-unit" name="unit_kerja_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Unit</option>
                        @foreach($unitKerjaOptions as $unit)
                            <option value="{{ $unit->id }}" @selected($filters['unit_kerja_id'] === $unit->id)>{{ $unit->nama }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Filter Jenis --}}
                <div class="relative">
                    <select id="filter-jenis" name="jenis_pegawai_id" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                        <option value="">Semua Jenis</option>
                        @foreach($jenisPegawaiOptions as $jenis)
                            <option value="{{ $jenis->id }}" @selected($filters['jenis_pegawai_id'] === $jenis->id)>{{ $jenis->nama }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>
    </x-ui.card>


    {{-- TABLE SECTION --}}
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table class="min-w-[760px]">
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th class="select-none">Pegawai</x-ui.table-th>
                        <x-ui.table-th class="select-none">Jabatan & Unit</x-ui.table-th>
                        <x-ui.table-th class="select-none">Gol. / Jenis</x-ui.table-th>
                        <x-ui.table-th class="select-none">Status</x-ui.table-th>
                        <x-ui.table-th class="select-none">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>

                        @forelse ($employees as $employee)
                            @php
                                $latestPosition = $employee->positionHistories->first();
                                $unitKerja = $latestPosition?->unitKerja?->nama ?? '-';
                                $statusNama = $employee->statusPegawai?->nama ?? $employee->status_aktif ?? 'Non-Aktif';
                                $initial = mb_substr($employee->nama_lengkap, 0, 1);
                                $fotoUrl = $employee->foto_url;
                            @endphp
                            <x-ui.table-row :interactive="true" class="nonaktif-record" data-nama="{{ mb_strtolower($employee->nama_lengkap) }}"
                                data-nip="{{ mb_strtolower($employee->nip) }}">
                                <x-ui.table-td>
                                    <div class="flex items-center gap-3">
                                        <div class="relative flex h-8 w-8 shrink-0 overflow-hidden items-center justify-center rounded-full border border-border bg-danger/10 text-sm font-bold text-danger">
                                            @if($fotoUrl)
                                                <img
                                                    src="{{ $fotoUrl }}"
                                                    alt="Foto {{ $employee->nama_lengkap }}"
                                                    class="h-full w-full object-cover object-[center_25%]"
                                                    loading="lazy"
                                                    onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden')"
                                                >
                                            @endif
                                            <span class="{{ $fotoUrl ? 'hidden' : '' }}">
                                                {{ $initial }}
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="block truncate text-sm font-semibold text-ink transition hover:text-primary">{{ $employee->nama_lengkap }}</p>
                                            <p class="text-xs text-muted">NIP. {{ $employee->nip }}</p>
                                        </div>
                                    </div>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <p class="text-sm font-medium text-ink">{{ $latestPosition?->jabatan?->nama ?? $employee->jabatan_terakhir ?? '-' }}</p>
                                    <p class="text-xs text-muted">{{ $unitKerja }}</p>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <span class="text-sm font-medium text-ink">{{ $employee->golongan_terakhir ?? '-' }} / {{ $employee->jenisPegawai?->nama ?? '-' }}</span>
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <x-ui.badge variant="danger" size="md" dot>
                                        {{ $statusNama }}
                                    </x-ui.badge>
                                    @if($employee->updated_at)
                                        <p class="mt-1 text-xs text-muted">{{ $employee->updated_at->format('d/m/Y H:i') }}</p>
                                    @endif
                                </x-ui.table-td>
                                <x-ui.table-td>
                                    <div class="inline-flex items-center gap-2">
                                        <x-ui.confirm-dialog
                                            id="restore-{{ $employee->id }}"
                                            title="Aktifkan Kembali Pegawai"
                                            message="Apakah Anda yakin ingin mengaktifkan kembali pegawai ini?"
                                            confirm-text="Aktifkan"
                                            variant="primary"
                                            action="{{ route('pegawai.restore', $employee->id) }}"
                                            method="POST"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.button type="button" variant="secondary" size="icon" class="!border-success/50 !text-success hover:!bg-success/10 hover:!border-success" title="Aktifkan Kembali" tooltip-position="top-end" aria-label="Aktifkan Kembali">
                                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                                    </svg>
                                                </x-ui.button>
                                            </x-slot:trigger>
                                        </x-ui.confirm-dialog>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row id="empty-state">
                                <x-ui.table-td colspan="5" align="center" class="px-0 py-0 text-sm text-muted">
                                    <x-ui.empty-state icon="document" title="Tidak ada data pegawai non-aktif." />
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-muted">Tampilkan</span>
                        <select onchange="updatePerPage(this.value)" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                            <option value="10" {{ request('per_page', 10) == 10 ? 'selected' : '' }}>10</option>
                            <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                        </select>
                        <span class="text-sm text-muted">data per halaman</span>
                    </div>
                    @if($employees->total() > 0)
                    <p class="text-sm text-muted hidden sm:block">
                        Menampilkan <span class="font-semibold text-ink">{{ $employees->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $employees->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $employees->total() }}</span> hasil
                    </p>
                    @endif
                </div>

                <div class="w-full sm:w-auto">
                    {{ $employees->onEachSide(1)->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>

    <script>
        let filterTimeout;
        const form = document.getElementById('filter-form');

        function updatePerPage(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', value);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        function submitFilterForm(delay = 0) {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const url = new URL(form.action);
                const formData = new FormData(form);

                for (let [key, value] of formData.entries()) {
                    if (value) {
                        url.searchParams.set(key, value);
                    } else {
                        url.searchParams.delete(key);
                    }
                }

                url.searchParams.delete('page');

                window.location.href = url.toString();
            }, delay);
        }

        const selects = form.querySelectorAll('select');
        selects.forEach(select => {
            select.addEventListener('change', () => submitFilterForm(0));
        });

        const searchInput = document.getElementById('search-input');
        if (searchInput) searchInput.addEventListener('input', () => submitFilterForm(450));
    </script>
</x-layouts.app>
