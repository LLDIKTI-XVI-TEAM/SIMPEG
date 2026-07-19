<x-layouts.app title="EWS Pimpinan">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-ink">Early Warning System</h1>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                    ['label' => 'EWS'],
                ]" />
            </div>
        </div>

        <form method="GET" action="{{ route('pimpinan.ews.index') }}" id="filter-form" class="mb-6">
            <x-ui.filter-bar 
                searchId="search" 
                searchName="search" 
                :searchValue="request('search')"
                searchPlaceholder="Cari pegawai"
                gridClass="grid-cols-1 md:grid-cols-3"
            >
                <div>
                    <x-form.select id="event" name="event" size="md" onchange="this.form.submit()">
                        <option value="">Semua jenis event</option>
                        @foreach ($typeLabels as $label)
                            <option value="{{ $label }}" @selected($filterEvent === $label)>{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div>
                    <x-form.select id="status" name="status" size="md" onchange="this.form.submit()">
                        <option value="semua" @selected($filterStatus === 'semua' || $filterStatus === '')>Semua Status Tindak Lanjut</option>
                        @foreach ($followupStatusLabels as $value => $label)
                            <option value="{{ $value }}" @selected($filterStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </div>
            </x-ui.filter-bar>
        </form>

        <x-ui.card padding="none">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <caption class="sr-only">Daftar peringatan dini pegawai aktif</caption>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Pegawai</x-ui.table-th>
                            <x-ui.table-th padding="lg">Jenis Event</x-ui.table-th>
                            <x-ui.table-th padding="lg">Tanggal Target</x-ui.table-th>
                            <x-ui.table-th padding="lg">Sisa Hari</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status Kelayakan</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status Tindak Lanjut</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse ($alerts as $alert)
                            @php
                                $eligibility = $alert['is_eligible'] ? ['label' => 'Layak', 'variant' => 'success'] : ['label' => 'Tidak Layak', 'variant' => 'danger'];
                                $remaining = $alert['sisa_hari'] < 0 ? 'Lewat '.abs($alert['sisa_hari']).' hari' : $alert['sisa_hari'].' hari';
                            @endphp
                            <x-ui.table-row class="hover:bg-soft transition-colors border-b border-border/50 group">
                                <x-ui.table-td class="px-5 py-3"><a href="{{ route('pimpinan.pegawai.show', $alert['pegawai_id']) }}" class="font-semibold text-ink transition hover:text-primary focus:outline-none rounded">{{ $alert['nama'] }}</a><span class="mt-1 block font-sans text-[10px] text-muted">NIP. {{ $alert['nip'] }}</span></x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5 text-sm text-ink">{{ $alert['jenis_event'] }}<p class="mt-1 text-xs text-muted">{{ $alert['threshold_label'] }}</span></x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5 text-sm text-ink">{{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5"><x-ui.badge :variant="$alert['urgency']" size="sm" dot>{{ $remaining }}</x-ui.badge></x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5"><x-ui.badge :variant="$eligibility['variant']" size="sm" dot>{{ $eligibility['label'] }}</x-ui.badge><p class="mt-1 text-xs text-muted">{{ $alert['eligibility_reason'] }}</span></x-ui.table-td>
                                <x-ui.table-td class="px-4 py-3.5"><x-ui.badge variant="info" size="sm" dot>{{ $alert['followup_status_label'] }}</x-ui.badge>@if ($alert['handled_note'])<p class="mt-1 text-xs text-muted">{{ $alert['handled_note'] }}</p>@endif</x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row><x-ui.table-td colspan="6" class="px-4 py-10 text-center text-sm text-muted">Tidak ada peringatan EWS yang sesuai dengan filter.</x-ui.table-td></x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
            
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <form method="GET" action="{{ route('pimpinan.ews.index') }}" class="flex items-center gap-3 text-sm text-muted">
                    <input type="hidden" name="search" value="{{ request('search') }}">
                    <input type="hidden" name="event" value="{{ request('event') }}">
                    <input type="hidden" name="status" value="{{ request('status') }}">
                    <span class="whitespace-nowrap">Tampilkan</span>
                    <select name="per_page" onchange="this.form.submit()" class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center">
                        @foreach ([10, 25, 50] as $optPerPage)
                            <option value="{{ $optPerPage }}" @selected((int) request('per_page', 10) === $optPerPage)>{{ $optPerPage }}</option>
                        @endforeach
                    </select>
                    <span class="hidden sm:inline">data</span>

                    @if($alerts->total() > 0)
                        <div class="hidden md:block ml-2 border-l border-border pl-4">
                            Menampilkan <span class="font-medium text-ink">{{ $alerts->firstItem() }}</span>
                            - <span class="font-medium text-ink">{{ $alerts->lastItem() }}</span>
                            dari <span class="font-medium text-ink">{{ $alerts->total() }}</span>
                        </div>
                    @endif
                </form>

                <div class="w-full sm:w-auto">
                    {{ $alerts->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
