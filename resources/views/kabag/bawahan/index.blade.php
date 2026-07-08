<x-layouts.app title="Daftar Bawahan" subtitle="Kelola dan pantau seluruh pegawai di bawah naungan Anda.">

    @php
        // DUMMY DATA UNTUK UI
        $daftarBawahan = [
            ['id' => '9b6574f2-959c-4876-880f-90e822e11fa1', 'nama' => 'Ahmad Fauzi', 'nip' => '198123456789100000', 'jabatan' => 'Analis Kepegawaian Ahli Muda', 'unit' => 'Bagian Umum', 'golongan' => 'I/d', 'jenis' => 'PNS', 'status' => 'Aktif'],
            ['id' => '9b6574f2-959c-4876-880f-90e822e11fa2', 'nama' => 'Siti Rahayu', 'nip' => '198512345678910000', 'jabatan' => 'Pranata Komputer Ahli Pertama', 'unit' => 'Subbagian Tata Usaha', 'golongan' => 'III/a', 'jenis' => 'PNS', 'status' => 'Cuti Tahunan'],
            ['id' => '9b6574f2-959c-4876-880f-90e822e11fa3', 'nama' => 'Budi Santoso', 'nip' => '199012345678910000', 'jabatan' => 'Pengelola Keuangan', 'unit' => 'Subbagian Perencanaan', 'golongan' => 'II/c', 'jenis' => 'PNS', 'status' => 'Dinas Luar'],
            ['id' => '9b6574f2-959c-4876-880f-90e822e11fa4', 'nama' => 'Dewi Pertiwi', 'nip' => '737741487614535936', 'jabatan' => 'Pengelola Data', 'unit' => 'Subbagian Informasi', 'golongan' => 'III/b', 'jenis' => 'PPPK', 'status' => 'Aktif'],
            ['id' => '9b6574f2-959c-4876-880f-90e822e11fa5', 'nama' => 'Rudi Hermawan', 'nip' => '198812345678910000', 'jabatan' => 'Pranata Humas Ahli Muda', 'unit' => 'Bagian Humas', 'golongan' => 'III/b', 'jenis' => 'PNS', 'status' => 'Aktif'],
            ['id' => '9b6574f2-959c-4876-880f-90e822e11fa6', 'nama' => 'Nadia Kusuma', 'nip' => '199512345678910000', 'jabatan' => 'Analis Hukum Ahli Pertama', 'unit' => 'Subbagian Hukum', 'golongan' => 'III/a', 'jenis' => 'PNS', 'status' => 'Cuti Sakit'],
        ];
    @endphp

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Daftar Bawahan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Daftar Bawahan']
            ]" />
        </div>
    </div>

    {{-- FILTER BAR --}}
    <form id="filter-form" method="GET" action="{{ route('kepala-bagian.bawahan.index') }}">
        <x-ui.filter-bar 
            searchId="search-input"
            searchName="search"
            searchValue=""
            searchPlaceholder="Cari nama atau NIP..." 
            class="lg:grid-cols-5"
        >
            {{-- Filter Golongan --}}
            <div>
                <x-form.select id="filter-golongan" name="golongan" size="md" onchange="this.form.submit()">
                    <option value="">Semua Golongan</option>
                    <option value="I" {{ request('golongan') === 'I' ? 'selected' : '' }}>Golongan I</option>
                    <option value="II" {{ request('golongan') === 'II' ? 'selected' : '' }}>Golongan II</option>
                    <option value="III" {{ request('golongan') === 'III' ? 'selected' : '' }}>Golongan III</option>
                    <option value="IV" {{ request('golongan') === 'IV' ? 'selected' : '' }}>Golongan IV</option>
                </x-form.select>
            </div>

            {{-- Filter Unit --}}
            <div>
                <x-form.select id="filter-unit" name="unit_kerja_id" size="md" onchange="this.form.submit()">
                    <option value="">Semua Unit</option>
                    <option value="1" {{ request('unit_kerja_id') === '1' ? 'selected' : '' }}>Bagian Umum</option>
                    <option value="2" {{ request('unit_kerja_id') === '2' ? 'selected' : '' }}>Subbagian Tata Usaha</option>
                </x-form.select>
            </div>

            {{-- Filter Jenis --}}
            <div>
                <x-form.select id="filter-jenis" name="jenis_pegawai_id" size="md" onchange="this.form.submit()">
                    <option value="">Semua Jenis</option>
                    <option value="1" {{ request('jenis_pegawai_id') === '1' ? 'selected' : '' }}>PNS</option>
                    <option value="2" {{ request('jenis_pegawai_id') === '2' ? 'selected' : '' }}>PPPK</option>
                </x-form.select>
            </div>

            {{-- Filter Status --}}
            <div>
                <x-form.select id="filter-status" name="status_pegawai_id" size="md" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="1" {{ request('status_pegawai_id') === '1' ? 'selected' : '' }}>Aktif</option>
                    <option value="2" {{ request('status_pegawai_id') === '2' ? 'selected' : '' }}>Cuti</option>
                    <option value="3" {{ request('status_pegawai_id') === '3' ? 'selected' : '' }}>Pensiun</option>
                </x-form.select>
            </div>
        </x-ui.filter-bar>
    </form>

    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">PEGAWAI</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">JABATAN & UNIT</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">GOL. / JENIS</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">STATUS</x-ui.table-th>
                        <x-ui.table-th padding="comfortable" class="text-xs text-muted uppercase tracking-wider font-bold">AKSI</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @foreach($daftarBawahan as $bawahan)
                    <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                        <x-ui.table-td padding="comfortable">
                            <div class="flex items-center gap-3">
                                <x-ui.tooltip text="Buka detail {{ $bawahan['nama'] }}" position="right">
                                    <a href="{{ route('kepala-bagian.bawahan.show', ['id' => $bawahan['id']]) }}" class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-primary/10 text-xs font-bold text-primary transition hover:border-primary hover:ring-2 hover:ring-primary/20 focus:outline-none focus:ring-2 focus:ring-primary/30" aria-label="Buka detail profil {{ $bawahan['nama'] }}">
                                        <span>{{ substr($bawahan['nama'], 0, 1) }}</span>
                                    </a>
                                </x-ui.tooltip>
                                <div class="min-w-0">
                                    <x-ui.tooltip text="Buka detail {{ $bawahan['nama'] }}" position="right">
                                        <a href="{{ route('kepala-bagian.bawahan.show', ['id' => $bawahan['id']]) }}" class="block truncate text-sm font-semibold text-ink transition hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20 rounded leading-tight">{{ $bawahan['nama'] }}</a>
                                    </x-ui.tooltip>
                                    <p class="text-[11px] text-muted font-sans leading-none mt-1 font-mono">NIP. {{ $bawahan['nip'] }}</p>
                                </div>
                            </div>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <p class="text-sm font-medium text-ink leading-tight">{{ $bawahan['jabatan'] }}</p>
                            <p class="text-xs text-muted mt-1">{{ $bawahan['unit'] }}</p>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <span class="text-sm font-medium text-ink">{{ $bawahan['golongan'] }} / {{ $bawahan['jenis'] }}</span>
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            @if($bawahan['status'] === 'Aktif')
                                <x-ui.badge variant="success" dot>{{ $bawahan['status'] }}</x-ui.badge>
                            @elseif(str_contains($bawahan['status'], 'Cuti'))
                                <x-ui.badge variant="warning" dot>{{ $bawahan['status'] }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="ink" dot>{{ $bawahan['status'] }}</x-ui.badge>
                            @endif
                        </x-ui.table-td>
                        <x-ui.table-td padding="comfortable">
                            <div class="flex items-center gap-1">
                                <x-ui.button as="a" href="{{ route('kepala-bagian.bawahan.show', ['id' => $bawahan['id']]) }}" variant="secondary" size="icon" title="Lihat Detail">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                </x-ui.button>
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforeach
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-surface px-6 py-4 sm:flex-row" x-data="{ currentPage: 1, totalPages: 2 }">
            <div class="flex items-center gap-4">
                <p class="text-sm text-muted hidden sm:block">
                    Menampilkan <span class="font-semibold text-ink">1</span> hingga <span class="font-semibold text-ink">6</span> dari <span class="font-semibold text-ink">12</span> hasil
                </p>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.pagination />
            </div>
        </div>
    </x-ui.card>

</x-layouts.app>
