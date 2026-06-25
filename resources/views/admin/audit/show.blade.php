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
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Detail Log Aktivitas</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('audit-log') }}" class="transition-colors hover:text-ink">Audit Log</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Log #{{ $log['id'] }}</span>
            </nav>
        </div>

        {{-- Detail Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            
            {{-- Header info --}}
            <div class="border-b border-border pb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-bold text-ink font-sans leading-tight">Aktivitas: {{ $log['event'] }}</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Waktu Operasional: {{ $log['timestamp'] }}</p>
                </div>
                <div>
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold font-sans text-primary">
                        Modul: {{ $log['modul'] }}
                    </span>
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
                    <p class="text-sm font-semibold text-ink font-mono">{{ $log['ip_address'] }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">ID Record</span>
                    <p class="text-sm font-semibold text-ink font-mono truncate">{{ $log['record_id'] }}</p>
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
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-border/40 text-[10px] uppercase font-semibold text-muted font-sans border-b border-border">
                                <th class="px-3 py-2">Nama Field</th>
                                <th class="px-3 py-2">Sebelum</th>
                                <th class="px-3 py-2">Sesudah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-[11px] font-sans">
                            @forelse($diffs as $diff)
                                <tr>
                                    <td class="px-3 py-2 font-semibold text-ink font-mono">{{ $diff['field'] }}</td>
                                    <td class="px-3 py-2 text-danger font-mono bg-danger/5">{{ $diff['old'] }}</td>
                                    <td class="px-3 py-2 text-success font-mono bg-success/5">{{ $diff['new'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-3 py-4 text-center text-muted font-sans">
                                        Tidak ada detail perubahan nilai data (misal: event login/logout).
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Footer actions --}}
            <div class="border-t border-border pt-6 flex justify-between items-center gap-3">
                <div>
                    @if($log['modul'] === 'Employee' || $log['modul'] === 'LeaveRequest')
                        <a href="{{ $log['modul'] === 'Employee' ? '/pegawai/' . $log['record_id'] : '/dashboard/cuti/' . $log['record_id'] }}" class="inline-flex items-center justify-center rounded-lg border border-primary bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90">
                            Lihat Record
                        </a>
                    @endif
                </div>
                <a href="{{ route('audit-log') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                    Kembali ke Log
                </a>
            </div>

        </div>
    </div>
</x-layouts.app>
