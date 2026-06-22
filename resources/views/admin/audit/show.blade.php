<x-layouts.app title="Detail Audit Log">
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
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold font-sans bg-primary/10 text-primary border border-primary/20">
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
                
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    
                    {{-- Old Values --}}
                    <div class="space-y-1.5">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sebelum (Data Lama)</span>
                        <div class="rounded bg-soft p-3 text-[11px] font-mono text-danger leading-relaxed whitespace-pre-wrap max-h-64 overflow-y-auto border border-border">
                            @if($log['old_values'])
                                {{ json_encode($log['old_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
                            @else
                                <span class="text-muted font-sans font-medium">Tidak ada data perubahan (kosong)</span>
                            @endif
                        </div>
                    </div>

                    {{-- New Values --}}
                    <div class="space-y-1.5">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Sesudah (Data Baru)</span>
                        <div class="rounded bg-soft p-3 text-[11px] font-mono text-success leading-relaxed whitespace-pre-wrap max-h-64 overflow-y-auto border border-border">
                            @if($log['new_values'])
                                {{ json_encode($log['new_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
                            @else
                                <span class="text-muted font-sans font-medium">Tidak ada data baru</span>
                            @endif
                        </div>
                    </div>

                </div>
            </div>

            {{-- Footer actions --}}
            <div class="border-t border-border pt-6 flex justify-end gap-3">
                <a href="{{ route('audit-log') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                    Kembali ke Log
                </a>
            </div>

        </div>
    </div>
</x-layouts.app>
