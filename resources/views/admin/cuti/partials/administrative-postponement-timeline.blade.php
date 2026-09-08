@if ($administrativePostponement !== null)
    <x-ui.timeline-item variant="warning" title="Ditangguhkan (Administratif)" :description="$administrativePostponement['occurredAt']">
        <p class="mt-2 text-sm text-ink">Seluruh periode cuti telah dibatalkan secara administratif. Persetujuan sebelumnya tetap tercatat sebagai riwayat.</p>
        @if ($administrativePostponement['actorName'] !== null)
            <p class="mt-2 text-xs text-muted">Dicatat oleh {{ $administrativePostponement['actorName'] }}</p>
        @endif
        @if ($administrativePostponement['reason'] !== null)
            <p class="mt-3 text-xs font-semibold text-ink">Alasan penangguhan administratif</p>
            <p class="mt-1 whitespace-pre-wrap break-words rounded-lg border border-border bg-soft p-3 text-sm text-ink">{{ $administrativePostponement['reason'] }}</p>
        @endif
    </x-ui.timeline-item>
@endif
