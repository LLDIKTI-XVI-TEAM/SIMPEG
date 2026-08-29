@props([
    'dashboardUrl',
    'employeesUrl',
    'backUrl' => null,
])

@php
    $fallbackUrl = $backUrl ?: $employeesUrl;
@endphp

<div
    data-employee-detail-page-header
    class="mb-2 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
>
    <div>
        <h1 class="mb-1 text-2xl font-extrabold text-ink tracking-tight font-sans">Detail Pegawai</h1>
        <nav aria-label="Breadcrumb" class="mb-4 flex items-center gap-1.5 text-xs text-muted">
            <a href="{{ $dashboardUrl }}" wire:navigate class="transition-colors hover:text-ink">Dashboard</a>
            <span aria-hidden="true">/</span>
            <a href="{{ $employeesUrl }}" wire:navigate class="transition-colors hover:text-ink">Data Pegawai</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page" class="font-medium text-ink">Detail Pegawai</span>
        </nav>
    </div>

    <div class="flex shrink-0 items-center gap-3">
        <x-ui.button
            href="{{ $fallbackUrl }}"
            variant="secondary"
            onclick="if (document.referrer.includes(window.location.hostname)) { event.preventDefault(); history.back(); }"
        >
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
            </svg>
            Kembali
        </x-ui.button>

        {{ $slot }}
    </div>
</div>
