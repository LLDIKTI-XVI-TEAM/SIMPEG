@props([
    'employee',
    'statusPresentation',
    'activePosition' => null,
    'latestRank' => null,
    'latestStatusHistory' => null,
    'activeSupervisorAssignments' => null,
    'retirementDate' => null,
    'canReadHistories' => true,
    'maskSensitive' => true,
    'downloadSurface' => 'pimpinan',
])

<div data-employee-detail-profile class="space-y-6">
    @isset($controls)
        {{ $controls }}
    @endisset

    @include('pegawai.partials.detail.profile-readonly', [
        'employee' => $employee,
        'statusPresentation' => $statusPresentation,
        'activePosition' => $activePosition,
        'latestRank' => $latestRank,
        'latestStatusHistory' => $latestStatusHistory,
        'activeSupervisorAssignments' => $activeSupervisorAssignments ?? collect(),
        'retirementDate' => $retirementDate,
        'canReadHistories' => $canReadHistories ?? true,
        'maskSensitive' => $maskSensitive,
        'downloadSurface' => $downloadSurface,
    ])
</div>
