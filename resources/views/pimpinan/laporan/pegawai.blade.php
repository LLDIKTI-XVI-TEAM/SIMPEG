<x-layouts.app title="Laporan Pegawai">
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Laporan Pegawai</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Laporan & Statistik', 'url' => route('pimpinan.laporan.index')],
                ['label' => 'Laporan Pegawai'],
            ]" />
        </div>

        <x-ui.card>
            <p id="employee-report-help" class="mb-4 text-sm text-muted">
                Pilih kolom yang ingin ditampilkan dan tentukan filter untuk menyaring data pegawai sebelum mengunduh.
            </p>

            <form action="{{ route('pimpinan.laporan.pegawai') }}" method="GET" class="space-y-6" aria-describedby="employee-report-help">

                {{-- Pilih Kolom --}}
                <fieldset>
                    <legend class="mb-2 text-sm font-semibold text-ink">Kolom yang Ditampilkan</legend>
                    <div class="flex flex-wrap gap-3">
                        @foreach($allowedColumns as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-soft/40 px-3 py-2 text-sm transition hover:bg-soft">
                                <input id="column-{{ $key }}" name="columns[]" value="{{ $key }}" type="checkbox"{{ in_array($key, $selectedColumns, true) ? ' checked' : '' }} class="h-4 w-4 rounded border-border text-primary focus:ring-primary">
                                <span class="text-ink">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- Filter --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink" for="filter-search">Cari Nama / NIP</label>
                        <x-form.input id="filter-search" name="search" type="text" placeholder="Nama atau NIP..."
                            :value="$filters['search'] ?? ''" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink" for="filter-golongan">Golongan</label>
                        <x-form.input id="filter-golongan" name="golongan" type="text" placeholder="Contoh: IV"
                            :value="$filters['golongan'] ?? ''" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink" for="filter-status">Status</label>
                        <x-form.input id="filter-status" name="status" type="text" placeholder="Contoh: Aktif"
                            :value="$filters['status'] ?? ''" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink" for="filter-pensiun-dari">Pensiun Dari</label>
                        <x-form.input id="filter-pensiun-dari" name="pensiun_dari" type="date"
                            :value="$filters['pensiun_dari'] ?? ''" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink" for="filter-pensiun-sampai">Pensiun Sampai</label>
                        <x-form.input id="filter-pensiun-sampai" name="pensiun_sampai" type="date"
                            :value="$filters['pensiun_sampai'] ?? ''" />
                    </div>
                </div>

                @if($errors->any())
                    <div class="rounded-lg border border-danger/20 bg-danger/10 p-3">
                        <ul class="list-disc space-y-1 pl-4 text-sm text-danger">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Actions --}}
                <div class="flex flex-wrap items-center gap-3 border-t border-border pt-4">
                    <x-ui.button type="submit" variant="secondary">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        Terapkan Filter
                    </x-ui.button>

                    <x-ui.button
                        type="submit"
                        variant="primary"
                        formaction="{{ route('pimpinan.laporan.pegawai.custom') }}"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        Unduh Excel (.xlsx)
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
