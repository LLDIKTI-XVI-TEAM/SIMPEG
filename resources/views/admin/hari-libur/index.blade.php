<x-layouts.app title="Hari Libur">

    @php
        // Nilai filter aktif dipakai ulang untuk tautan tab tahun, form per-halaman,
        // dan link paginasi supaya konteks filter tidak hilang saat berpindah.
        $filterAktif = array_filter([
            'tahun' => $filters['tahun'],
            'tipe' => $filters['tipe'] !== '' ? $filters['tipe'] : null,
            'search' => $filters['search'] !== '' ? $filters['search'] : null,
            'per_page' => $filters['per_page'],
        ], static fn ($nilai) => $nilai !== null);
    @endphp

    <div x-data="{ showAddForm: @json($errors->any()) }" class="space-y-6">

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Hari Libur</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Hari Libur']
                ]" />
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <span>&bull;</span>
                    <span class="text-muted italic font-sans">Akses: Khusus Super Admin</span>
                </nav>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button href="{{ route('audit-log') }}" variant="muted">
                    <svg class="w-4 h-4 mr-1.5 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                    Lihat Audit Log Master
                </x-ui.button>
                <button
                    type="button"
                    @click="showAddForm = !showAddForm"
                    :aria-expanded="showAddForm ? 'true' : 'false'"
                    aria-controls="form-tambah-hari-libur"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft shadow-sm font-sans"
                >
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Tambah Hari Libur
                </button>
            </div>
        </div>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Perubahan hari libur belum tersimpan">
                Periksa kembali kolom yang ditandai pada formulir.
            </x-ui.alert>
        @endif

        {{-- Penjelasan dampak data ini terhadap kalkulasi cuti dan EWS --}}
        <x-ui.alert variant="info" title="Penting untuk integritas sistem">
            Tanggal pada halaman ini dibaca langsung oleh sistem untuk menghitung jumlah
            <strong>hari kerja efektif pengajuan cuti</strong> dan menentukan jadwal peringatan
            <strong>Early Warning System (EWS)</strong>. Perubahan di sini berlaku pada kalkulasi berikutnya.
        </x-ui.alert>

        {{-- Form Tambah Hari Libur --}}
        <x-ui.card id="form-tambah-hari-libur" padding="lg" x-show="showAddForm" x-cloak class="space-y-4">
            <form action="{{ route('hari-libur.store') }}" method="POST" class="space-y-4">
                @csrf
                <h3 class="text-sm font-semibold text-primary font-sans">Tambah Hari Libur Baru</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-form.date
                        name="tanggal"
                        label="Tanggal"
                        required
                        size="lg"
                    />
                    <x-form.input
                        name="nama"
                        label="Nama Hari Libur"
                        type="text"
                        placeholder="Contoh: Hari Raya Idul Fitri"
                        required
                        size="lg"
                    />
                    <x-form.select
                        name="tipe"
                        label="Jenis Libur"
                        :value="old('tipe', 'libur_nasional')"
                        required
                    >
                        <option value="libur_nasional">Libur Nasional</option>
                        <option value="cuti_bersama">Cuti Bersama</option>
                    </x-form.select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button type="button" variant="link" size="xs" @click="showAddForm = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="xs">Simpan</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- Filter Bar --}}
        <x-ui.card padding="sm" class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            {{-- Tab tahun dari data yang benar-benar ada di database --}}
            <nav aria-label="Filter tahun" class="flex flex-wrap items-center gap-4 border-b border-border pb-2 md:border-b-0 md:pb-0">
                @forelse ($tahunTersedia as $tahun => $jumlah)
                    <a
                        href="{{ route('hari-libur', array_merge($filterAktif, ['tahun' => $tahun])) }}"
                        @class([
                            'text-sm pb-1 font-sans focus:outline-none focus:ring-2 focus:ring-primary/20 rounded',
                            'text-primary font-semibold border-b-2 border-primary' => (int) $tahun === $filters['tahun'],
                            'text-muted hover:text-ink' => (int) $tahun !== $filters['tahun'],
                        ])
                        @if ((int) $tahun === $filters['tahun']) aria-current="page" @endif
                    >
                        {{ $tahun }} ({{ $jumlah }})
                    </a>
                @empty
                    <span class="text-sm text-muted font-sans">Belum ada tahun yang terdaftar</span>
                @endforelse
            </nav>

            {{-- Pencarian & filter tipe, keduanya diproses di server --}}
            <form method="GET" action="{{ route('hari-libur') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <input type="hidden" name="tahun" value="{{ $filters['tahun'] }}">
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">

                <div class="w-full sm:w-64">
                    <label for="search" class="sr-only">Cari nama hari libur</label>
                    <div class="relative">
                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="{{ $filters['search'] }}"
                            placeholder="Cari nama hari libur..."
                            class="w-full rounded-lg border border-border bg-surface pl-9 pr-4 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"
                        >
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.602 10.602Z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <x-form.select
                    id="tipe"
                    name="tipe"
                    label="Tipe Libur"
                    size="sm"
                    :value="$filters['tipe']"
                    onchange="this.form.submit()"
                    wrapperClass="w-full sm:w-44"
                >
                    <option value="">Semua Tipe</option>
                    <option value="libur_nasional">Libur Nasional</option>
                    <option value="cuti_bersama">Cuti Bersama</option>
                </x-form.select>

                <x-ui.button type="submit" variant="secondary" size="xs">Terapkan</x-ui.button>
            </form>
        </x-ui.card>

        {{-- Table --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Tanggal</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Hari</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Nama Hari Libur</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Jenis Libur</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Tahun</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Aksi</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse ($hariLibur as $item)
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td padding="comfortable" class="text-sm font-medium">
                                    {{ $item->tanggal?->translatedFormat('d M Y') }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-sm">
                                    {{ $item->tanggal?->translatedFormat('l') }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-sm font-medium">
                                    {{ $item->nama }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <span @class([
                                        'text-xs font-semibold font-sans',
                                        'text-secondary' => $item->is_cuti_bersama,
                                        'text-primary' => ! $item->is_cuti_bersama,
                                    ])>
                                        {{ $item->labelTipe() }}
                                    </span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-sm text-muted">
                                    {{ $item->tahun }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <div class="flex items-center gap-1.5">
                                        <x-ui.button
                                            href="{{ route('hari-libur.edit', $item) }}"
                                            variant="secondary"
                                            size="icon"
                                            title="Edit {{ $item->nama }}"
                                            aria-label="Edit {{ $item->nama }}"
                                        >
                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                            </svg>
                                        </x-ui.button>

                                        {{-- Hapus selalu melalui form DELETE ber-CSRF, bukan manipulasi DOM --}}
                                        <x-ui.confirm-dialog
                                            id="hapus-hari-libur-{{ $item->id }}"
                                            title="Hapus Hari Libur"
                                            message="Hapus {{ $item->nama }} ({{ $item->tanggal?->translatedFormat('d M Y') }})? Kalkulasi hari kerja cuti akan menghitung tanggal ini sebagai hari kerja."
                                            confirm-text="Hapus"
                                            variant="danger"
                                            :action="route('hari-libur.destroy', $item)"
                                            method="DELETE"
                                        >
                                            <x-slot:trigger>
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-danger/20"
                                                    title="Hapus {{ $item->nama }}"
                                                    aria-label="Hapus {{ $item->nama }}"
                                                >
                                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </x-slot:trigger>
                                        </x-ui.confirm-dialog>
                                    </div>
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="6" align="center" class="px-0 py-0 text-muted bg-surface">
                                    <x-ui.empty-state
                                        icon="document"
                                        title="Belum ada hari libur untuk filter yang dipilih."
                                        message="Tambahkan hari libur agar kalkulasi hari kerja cuti dan jadwal EWS mengikuti kalender resmi."
                                    />
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col gap-3 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-surface">
                <div class="flex items-center gap-3">
                    <p class="text-xs text-muted font-sans">
                        Menampilkan
                        <span class="font-semibold text-ink">{{ $hariLibur->total() === 0 ? 0 : $hariLibur->firstItem() }}</span> -
                        <span class="font-semibold text-ink">{{ $hariLibur->total() === 0 ? 0 : $hariLibur->lastItem() }}</span> dari
                        <span class="font-semibold text-ink">{{ $hariLibur->total() }}</span> data
                    </p>

                    <form method="GET" action="{{ route('hari-libur') }}">
                        <input type="hidden" name="tahun" value="{{ $filters['tahun'] }}">
                        <input type="hidden" name="tipe" value="{{ $filters['tipe'] }}">
                        <input type="hidden" name="search" value="{{ $filters['search'] }}">
                        <label for="per_page" class="sr-only">Jumlah baris per halaman</label>
                        <x-form.select id="per_page" name="per_page" size="sm" :value="$filters['per_page']" onchange="this.form.submit()">
                            @foreach ($perPageOptions as $opsi)
                                <option value="{{ $opsi }}">{{ $opsi }} / halaman</option>
                            @endforeach
                        </x-form.select>
                    </form>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $hariLibur->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>

    </div>

</x-layouts.app>
