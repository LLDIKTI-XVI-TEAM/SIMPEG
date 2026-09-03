<x-layouts.app title="EWS Saya">
    @php
        $statusVariant = fn (string $status): string => match ($status) {
            'ditangani' => 'success',
            'tidak_perlu' => 'muted',
            'kedaluwarsa' => 'warning',
            default => 'primary',
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">EWS Saya</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'EWS Saya']
                ]" />
            </div>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table class="min-w-[820px]">
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th padding="lg">Event</x-ui.table-th>
                            <x-ui.table-th padding="lg">Target</x-ui.table-th>
                            <x-ui.table-th padding="lg">Sisa Waktu</x-ui.table-th>
                            <x-ui.table-th padding="lg">Status</x-ui.table-th>
                            <x-ui.table-th padding="lg">Keterangan</x-ui.table-th>
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse($alerts as $alert)
                            @php
                                $urgencyVariant = match ($alert['urgency']) {
                                    'danger' => 'danger',
                                    'warning' => 'warning',
                                    default => 'success',
                                };
                            @endphp
                            <x-ui.table-row :interactive="false">
                                <x-ui.table-td padding="lg">
                                    <div class="font-semibold text-ink">{{ $alert['jenis_event'] }}</div>
                                    <div class="mt-1 text-xs text-muted">{{ $alert['threshold_label'] }}</div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg">
                                    <div class="text-sm font-semibold text-ink">{{ date('d M Y', strtotime($alert['tanggal_target'])) }}</div>
                                    <div class="mt-1 text-xs text-muted">Tanggal target</div>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg">
                                    <x-ui.badge :variant="$urgencyVariant" size="md" dot>
                                        {{ $alert['sisa_hari'] }} Hari
                                    </x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg">
                                    <x-ui.badge :variant="$statusVariant($alert['followup_status'])" size="md" dot>
                                        {{ $alert['followup_status_label'] }}
                                    </x-ui.badge>
                                </x-ui.table-td>
                                <x-ui.table-td padding="lg" class="text-sm text-muted">
                                    {{ $alert['eligibility_reason'] }}
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td colspan="5" align="center" class="px-6 py-12 text-sm text-muted">
                                    Tidak ada peringatan EWS aktif untuk akun ini.
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>
            {{-- TABLE FOOTER --}}
            <div class="flex flex-col items-center justify-between gap-4 border-t border-border bg-soft/20 px-6 py-4 sm:flex-row">
                <div class="flex flex-wrap items-center justify-center gap-4 sm:justify-start text-sm text-muted">
                    <form method="GET" action="{{ route('ews.saya') }}" class="flex items-center gap-2">
                        <span class="whitespace-nowrap">Tampilkan</span>
                        <label for="per_page" class="sr-only">Jumlah baris per halaman</label>
                        <select
                            id="per_page"
                            name="per_page"
                            onchange="this.form.submit()"
                            class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center"
                        >
                            @foreach ([10, 25, 50] as $opsi)
                                <option value="{{ $opsi }}" @selected((int) request('per_page', 10) === $opsi)>{{ $opsi }}</option>
                            @endforeach
                        </select>
                        <span class="hidden sm:inline">data</span>
                    </form>

                    <div class="hidden md:block ml-2 border-l border-border pl-4">
                        Menampilkan <span class="font-medium text-ink">{{ $alerts->firstItem() ?? 0 }}</span>
                        - <span class="font-medium text-ink">{{ $alerts->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium text-ink">{{ $alerts->total() }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $alerts->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
