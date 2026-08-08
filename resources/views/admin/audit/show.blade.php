<x-layouts.app title="Detail Audit Log">
    @php
        $oldVals = $log['old_values'] ?? [];
        $newVals = $log['new_values'] ?? [];
        
        if (!is_array($oldVals)) $oldVals = [];
        if (!is_array($newVals)) $newVals = [];
        
        $allKeys = array_unique(array_merge(array_keys($oldVals), array_keys($newVals)));
        $diffs = [];
        
        foreach ($allKeys as $key) {
            $oldVal = $oldVals[$key] ?? null;
            $newVal = $newVals[$key] ?? null;
            
            if ($log['event'] === 'UPDATE') {
                if (json_encode($oldVal) !== json_encode($newVal)) {
                    $diffs[] = [
                        'field' => $key,
                        'old' => $oldVal !== null ? (is_array($oldVal) ? json_encode($oldVal, JSON_UNESCAPED_SLASHES) : (is_bool($oldVal) ? ($oldVal ? 'true' : 'false') : $oldVal)) : '-',
                        'new' => $newVal !== null ? (is_array($newVal) ? json_encode($newVal, JSON_UNESCAPED_SLASHES) : (is_bool($newVal) ? ($newVal ? 'true' : 'false') : $newVal)) : '-',
                    ];
                }
            } else {
                $diffs[] = [
                    'field' => $key,
                    'old' => $oldVal !== null ? (is_array($oldVal) ? json_encode($oldVal, JSON_UNESCAPED_SLASHES) : (is_bool($oldVal) ? ($oldVal ? 'true' : 'false') : $oldVal)) : '-',
                    'new' => $newVal !== null ? (is_array($newVal) ? json_encode($newVal, JSON_UNESCAPED_SLASHES) : (is_bool($newVal) ? ($newVal ? 'true' : 'false') : $newVal)) : '-',
                ];
            }
        }
    @endphp
    <div class="mx-auto max-w-3xl space-y-6">
        
        <x-admin.page-header title="Detail Log Aktivitas">
            <x-slot:breadcrumb>
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('audit-log') }}" class="transition-colors hover:text-ink">Audit Log</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Log #{{ $log['id'] }}</span>
            </x-slot:breadcrumb>
        </x-admin.page-header>

        {{-- Detail Card --}}
        <x-ui.card padding="lg" class="space-y-6">
            
            {{-- Header info --}}
            <div class="border-b border-border pb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-bold text-ink font-sans leading-tight">Aktivitas: {{ $log['event_label'] }}</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Waktu Operasional: {{ $log['timestamp'] }}</p>
                </div>
                <div>
                    <x-ui.badge variant="primary" size="md">
                        Modul: {{ $log['modul'] }}
                    </x-ui.badge>
                </div>
            </div>

            {{-- Metadata Grid --}}
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Operator</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $log['operator'] }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">IP Address</span>
                    <p class="text-sm font-semibold text-ink">{{ $log['ip_address'] }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">ID Record</span>
                    <p class="text-sm font-semibold text-ink truncate">{{ $log['record_id'] }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Kategori</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ ucfirst(str_replace('_', ' ', $log['kategori'])) }}</p>
                </div>
                <div class="col-span-1 sm:col-span-2 space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">User Agent / Browser Info</span>
                    <p class="text-xs text-muted font-sans leading-normal bg-soft/50 rounded-lg p-3 border border-border">{{ $log['user_agent'] }}</p>
                </div>
            </div>

            {{-- Diff Panel --}}
            <div class="border-t border-border pt-6 space-y-4">
                <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Perubahan Nilai Data</h4>
                
                <div class="overflow-hidden rounded-lg border border-border bg-soft">
                    <x-ui.table class="text-left border-collapse">
                        <x-ui.table-head>
                            <x-ui.table-row class="bg-border/40 text-[10px] border-b border-border">
                                <x-ui.table-th padding="xs">Nama Field</x-ui.table-th>
                                <x-ui.table-th padding="xs">Sebelum</x-ui.table-th>
                                <x-ui.table-th padding="xs">Sesudah</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body class="text-[11px] font-sans">
                            @forelse($diffs as $diff)
                                <x-ui.table-row>
                                    <x-ui.table-td padding="xs" class="font-semibold">{{ $diff['field'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="xs" class="text-danger bg-danger/5">{{ $diff['old'] }}</x-ui.table-td>
                                    <x-ui.table-td padding="xs" class="text-success bg-success/5">{{ $diff['new'] }}</x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="3" align="center" class="px-3 py-4 text-muted">
                                        Tidak ada detail perubahan nilai data (misal: event login/logout).
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforelse
                        </x-ui.table-body>
                    </x-ui.table>
                </div>
            </div>

            {{-- Footer actions --}}
            <div class="border-t border-border pt-6 flex justify-between items-center gap-3">
                <div>
                    @if($log['modul'] === 'Employee' || $log['modul'] === 'LeaveRequest')
                        <x-ui.button href="{{ $log['modul'] === 'Employee' ? '/pegawai/' . $log['record_id'] : '/dashboard/cuti/' . $log['record_id'] }}" variant="primary">
                            Lihat Record
                        </x-ui.button>
                    @endif
                </div>
                <x-ui.button href="{{ route('audit-log') }}" variant="secondary">
                    Kembali ke Log
                </x-ui.button>
            </div>

        </x-ui.card>
    </div>
</x-layouts.app>
