@props([
    'employee',
    'photoUrl' => null,
    'primaryBadgeLabel' => null,
    'primaryBadgeClasses' => 'bg-primary/10 text-primary',
])

@php
    $displayName = $employee->nama_dengan_gelar ?: $employee->nama_lengkap;
    $initial = mb_strtoupper(mb_substr($displayName ?: 'P', 0, 1));
@endphp

<div data-employee-detail-header class="flex items-center justify-between gap-4 border-b border-border pb-6">
    <div class="flex min-w-0 items-center gap-4">
        <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-soft">
            @if($photoUrl)
                <img
                    src="{{ $photoUrl }}"
                    alt="Foto {{ $displayName }}"
                    class="h-full w-full object-cover object-[center_25%]"
                >
            @else
                <div class="flex h-full w-full items-center justify-center bg-primary/10 text-xl font-bold uppercase text-primary font-sans">
                    {{ $initial }}
                </div>
            @endif
        </div>

        <div class="min-w-0">
            <h2 class="text-xl font-bold leading-tight text-ink font-sans">{{ $displayName }}</h2>
            @if($employee->nama_dengan_gelar)
                <p class="mt-0.5 text-xs text-muted font-sans">{{ $employee->nama_lengkap }}</p>
            @endif
            <p class="text-xs text-muted">NIP. {{ $employee->nip ?? '-' }}</p>

            @if($primaryBadgeLabel || isset($badges))
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    @if($primaryBadgeLabel)
                        <x-ui.badge variant="primary" size="md" class="{{ $primaryBadgeClasses }} !font-bold">
                            {{ $primaryBadgeLabel }}
                        </x-ui.badge>
                    @endif

                    @isset($badges)
                        {{ $badges }}
                    @endisset
                </div>
            @endif
        </div>
    </div>
</div>
