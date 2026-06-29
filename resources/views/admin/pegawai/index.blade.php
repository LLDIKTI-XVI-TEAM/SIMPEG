<x-layouts.app title="Data Pegawai">



    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
            <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <span class="font-medium text-ink">Data Pegawai</span>
            </nav>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            {{-- Export button --}}
            <button
                onclick="exportFilteredData()"
                id="export-btn"
                class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Export Excel
            </button>
            <a
                href="{{ route('data-nonaktif') }}"
                id="nonaktif-list-btn"
                class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-4 py-2 text-sm font-semibold text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer"
            >
                <svg class="w-4 h-4 mr-1.5 text-danger shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
                </svg>
                Pegawai Nonaktif
            </a>
            <a
                href="{{ route('pegawai.create') }}"
                id="add-pegawai-btn"
                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 animate-fade-in"
            >
                <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Tambah Pegawai
            </a>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <form id="filter-form" method="GET" action="{{ route('data-pegawai') }}" class="mb-6 rounded-lg border border-border bg-surface p-4 flex flex-col gap-4 shadow-sm">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
        <input type="hidden" name="per_page" value="{{ $perPage ?? request('per_page', 10) }}">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {{-- Search input --}}
            <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5">
                <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input id="search-input" name="search" value="{{ $filters['search'] }}" type="text" placeholder="Cari nama atau NIP..." class="flex-1 bg-transparent text-sm text-ink placeholder:text-muted focus:outline-none font-sans">
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

            {{-- Filter Status --}}
            <div class="relative">
                <select id="filter-status" name="status_aktif" class="w-full appearance-none rounded-lg border border-border bg-surface pl-3 pr-10 py-1.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <option value="">Semua Status</option>
                    @foreach($statusOptions as $status)
                        <option value="{{ $status }}" @selected($filters['status_aktif'] === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </form>

    {{-- TABLE --}}
    @php
        $nextDirection = fn (string $column) => $sort === $column && $direction === 'asc' ? 'desc' : 'asc';
        $sortUrl = fn (string $column) => route('data-pegawai', array_merge(request()->except('page'), [
            'sort' => $column,
            'direction' => $nextDirection($column),
        ]));
        $sortIconClass = fn (string $column) => 'w-3.5 h-3.5 shrink-0 transition ' . ($sort === $column ? 'text-primary ' . ($direction === 'asc' ? 'rotate-180' : '') : '');
    @endphp
    <div class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full" id="pegawai-table">
                <thead class="bg-soft">
                    <tr>
                        <th class="w-10 px-4 py-3 select-none">
                            <input id="check-all" type="checkbox" class="h-4 w-4 rounded border-border text-primary focus:ring-primary/20">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">
                            <a href="{{ $sortUrl('pegawai') }}" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Pegawai
                                <svg class="{{ $sortIconClass('pegawai') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">
                            <a href="{{ $sortUrl('jabatan') }}" class="flex items-center gap-1 hover:text-ink transition-colors">
                                Jabatan & Unit
                                <svg class="{{ $sortIconClass('jabatan') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted select-none">
                            <a href="{{ $sortUrl('golongan') }}" class="flex items-center justify-center gap-1 hover:text-ink transition-colors">
                                Gol. / Jenis
                                <svg class="{{ $sortIconClass('golongan') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">
                            <a href="{{ $sortUrl('tmt') }}" class="flex items-center gap-1 hover:text-ink transition-colors">
                                TMT
                                <svg class="{{ $sortIconClass('tmt') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">Dokumen</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted select-none">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($pegawaiData as $p)
                    @php
                        $currentPosition = $p->positionHistories->first();
                        $currentUnit = $currentPosition?->unitKerja?->nama ?? '-';
                        $tmt = $currentPosition?->tmt_jabatan ?? $p->appointment?->tmt_pengangkatan;
                        $fotoUrl = $p->foto_url;
                    @endphp
                    <tr class="transition-colors hover:bg-soft/50" data-nama="{{ $p->nama_lengkap }}" data-nip="{{ $p->nip }}" data-unit="{{ $currentUnit }}" data-jenis="{{ $p->jenisPegawai->nama ?? '-' }}" data-status="{{ strtolower($p->status_aktif) }}" data-golongan="{{ $p->golongan_terakhir ?? '-' }}">
                        <td class="px-4 py-3.5">
                            <input type="checkbox" class="row-check h-4 w-4 rounded border-border text-primary focus:ring-primary/20">
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <a
                                    href="{{ route('pegawai.show', $p->id) }}"
                                    class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-sm font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30"
                                    title="Buka detail profil {{ $p->nama_lengkap }}"
                                    aria-label="Buka detail profil {{ $p->nama_lengkap }}"
                                >
                                    @if($fotoUrl)
                                        <img
                                            src="{{ $fotoUrl }}"
                                            alt="Foto {{ $p->nama_lengkap }}"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden')"
                                        >
                                    @endif
                                    <span class="{{ $fotoUrl ? 'hidden' : '' }}" aria-hidden="true">
                                        {{ strtoupper(substr($p->nama_lengkap, 0, 1)) }}
                                    </span>
                                </a>
                                <div class="min-w-0">
                                    <a
                                        href="{{ route('pegawai.show', $p->id) }}"
                                        class="block truncate text-sm font-semibold text-ink transition hover:text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary/20 rounded"
                                        title="Buka detail {{ $p->nama_lengkap }}"
                                    >
                                        {{ $p->nama_lengkap }}
                                    </a>
                                    <p class="font-mono text-xs text-muted">{{ $p->nip }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm font-medium text-ink">{{ $p->jabatan_terakhir ?? '-' }}</p>
                            <p class="text-xs text-muted">{{ $currentUnit }}</p>
                        </td>
                        <td class="px-4 py-3.5 text-left">
                            <span class="text-sm font-medium text-ink">{{ $p->golongan_terakhir ?? '-' }} / {{ $p->jenisPegawai->nama ?? '-' }}</span>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-sm text-ink font-mono">
                                {{ $tmt?->format('d-m-Y') ?? '-' }}
                            </p>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
                            $status_lower = strtolower($p->status_aktif);
                            $statusClasses = [
                                'aktif' => 'text-success',
                                'cuti' => 'text-warning',
                                'non-aktif' => 'text-danger',
                                'pensiun' => 'text-danger',
                                'mutasi' => 'text-warning'
                            ];
                            $statusDots = [
                                'aktif' => 'bg-success',
                                'cuti' => 'bg-warning',
                                'non-aktif' => 'bg-danger',
                                'pensiun' => 'bg-danger',
                                'mutasi' => 'bg-warning'
                            ];
                            $stClass = $statusClasses[$status_lower] ?? 'text-muted';
                            $stDot = $statusDots[$status_lower] ?? 'bg-muted';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $stClass }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $stDot }}"></span>
                                {{ $p->status_aktif }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
                            // Default mock for 'dok' since we don't have it on model
                            $dok = 'ok';
                            $dokClasses = [
                                'ok' => 'text-success',
                                'warn' => 'text-warning',
                                'danger' => 'text-danger'
                            ];
                            $dokDots = [
                                'ok' => 'bg-success',
                                'warn' => 'bg-warning',
                                'danger' => 'bg-danger'
                            ];
                            $dokLabels = [
                                'ok' => 'Lengkap',
                                'warn' => 'H-60',
                                'danger' => 'H-30'
                            ];
                            $dkClass = $dokClasses[$dok] ?? 'text-muted';
                            $dkDot = $dokDots[$dok] ?? 'bg-muted';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $dkClass }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dkDot }}"></span>
                                {{ $dokLabels[$dok] ?? $dok }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-left">
                            <div class="flex items-center justify-start gap-1.5">
                                {{-- Detail --}}
                                <a href="{{ route('pegawai.show', $p->id) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Detail">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                                {{-- Edit --}}
                                <a href="{{ route('pegawai.edit', $p->id) }}" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm" title="Edit">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                    </svg>
                                </a>
                                {{-- Nonaktifkan --}}
                                <form action="{{ route('pegawai.destroy', $p->id) }}" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menonaktifkan pegawai ini?')">
                                    @csrf
                                    <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-danger/5 shadow-sm cursor-pointer" title="Nonaktifkan">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
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
                @if($pegawaiData->total() > 0)
                <p class="text-sm text-muted hidden sm:block">
                    Menampilkan <span class="font-semibold text-ink">{{ $pegawaiData->firstItem() }}</span> hingga <span class="font-semibold text-ink">{{ $pegawaiData->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ $pegawaiData->total() }}</span> hasil
                </p>
                @endif
            </div>
            <div class="w-full sm:w-auto">
                {{ $pegawaiData->onEachSide(1)->links('vendor.pagination.simpeg') }}
            </div>
        </div>
    </div>

    {{-- BULK ACTION FLOATING BAR --}}
    <div id="bulk-bar" class="fixed bottom-6 left-1/2 z-40 hidden -translate-x-1/2 items-center gap-3 rounded-lg border border-border bg-surface px-6 py-3.5 shadow-lg">
        <p class="text-sm font-semibold text-ink"><span id="selected-count">0</span> pegawai dipilih</p>
        <div class="h-4 w-px bg-border"></div>
        <button class="inline-flex items-center gap-1.5 text-xs font-semibold text-warning hover:underline transition-colors cursor-pointer">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            Export Pilihan
        </button>
        <button class="inline-flex items-center gap-1.5 text-xs font-semibold text-danger hover:underline transition-colors cursor-pointer">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" />
            </svg>
            Nonaktifkan Pilihan
        </button>
        <button onclick="clearBulk()" class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted hover:text-ink hover:underline transition-colors cursor-pointer">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Batal
        </button>
    </div>

    @push('scripts')
    <script>
    const filterForm = document.getElementById('filter-form');
    const searchInput = document.getElementById('search-input');
    const filterGolongan = document.getElementById('filter-golongan');
    const filterUnit = document.getElementById('filter-unit');
    const filterJenis = document.getElementById('filter-jenis');
    const filterStatus = document.getElementById('filter-status');

    let filterSubmitTimer;

    function submitFilterForm(delay = 0) {
        if (!filterForm) return;

        window.clearTimeout(filterSubmitTimer);
        filterSubmitTimer = window.setTimeout(() => {
            clearBulk();
            filterForm.submit();
        }, delay);
    }

    if (searchInput) searchInput.addEventListener('input', () => submitFilterForm(450));
    if (filterGolongan) filterGolongan.addEventListener('change', () => submitFilterForm());
    if (filterUnit) filterUnit.addEventListener('change', () => submitFilterForm());
    if (filterJenis) filterJenis.addEventListener('change', () => submitFilterForm());
    if (filterStatus) filterStatus.addEventListener('change', () => submitFilterForm());

    // Checkbox bulk
    const checkAll = document.getElementById('check-all');
    const bulkBar  = document.getElementById('bulk-bar');
    const countEl  = document.getElementById('selected-count');

    function updateBulk() {
        const n = document.querySelectorAll('.row-check:checked').length;
        countEl.textContent = n;
        bulkBar.classList.toggle('hidden', n === 0);
        bulkBar.classList.toggle('flex',   n > 0);
    }

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.row-check').forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = checkAll.checked;
                }
            });
            updateBulk();
        });
    }

    document.querySelectorAll('.row-check').forEach(cb => {
        cb.addEventListener('change', () => {
            const all    = document.querySelectorAll('.row-check').length;
            const ticked = document.querySelectorAll('.row-check:checked').length;
            if (checkAll) {
                checkAll.indeterminate = ticked > 0 && ticked < all;
                checkAll.checked       = ticked === all;
            }
            updateBulk();
        });
    });

    function clearBulk() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        if (checkAll) {
            checkAll.checked       = false;
            checkAll.indeterminate = false;
        }
        updateBulk();
    }

    // Export baris yang sedang terlihat melalui generator XLSX di backend.
    function exportFilteredData() {
        const visibleNips = Array.from(document.querySelectorAll('tbody tr'))
            .filter(row => row.style.display !== 'none' && row.dataset.nip)
            .map(row => row.dataset.nip);

        if (visibleNips.length === 0) {
            window.alert('Tidak ada data pegawai yang dapat diekspor.');
            return;
        }

        const exportUrl = new URL(@json(route('pegawai.export')), window.location.origin);
        visibleNips.forEach(nip => exportUrl.searchParams.append('nips[]', nip));
        window.location.href = exportUrl.toString();
    }

    function updatePerPage(val) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', val);
        url.searchParams.delete('page');
        window.location.assign(url.href);
    }
    </script>
    @endpush

</x-layouts.app>
