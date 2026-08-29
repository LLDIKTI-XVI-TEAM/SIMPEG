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
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <span class="font-medium text-ink">EWS Saya</span>
                </nav>
            </div>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-border bg-surface px-6 py-4">
                <h3 class="text-sm font-bold text-ink font-sans">Peringatan EWS Pribadi</h3>
                <p class="mt-0.5 text-[10px] text-muted font-sans">Data dibatasi untuk pegawai yang sedang login.</p>
            </div>

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
            @if($alerts->hasPages())
                <div class="border-t border-border bg-surface px-6 py-4">
                    {{ $alerts->links('vendor.pagination.simpeg') }}
                </div>
            @elseif($alerts->total() > 0)
                <div class="border-t border-border bg-surface px-6 py-3">
                    <p class="text-sm text-muted">Menampilkan {{ $alerts->total() }} peringatan EWS pribadi.</p>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
