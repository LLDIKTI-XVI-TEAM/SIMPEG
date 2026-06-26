<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use Illuminate\Http\Request;

class EwsController extends Controller
{
    /**
     * Display the EWS Active page.
     */
    public function index(Request $request)
    {


        $typeLabels = [
            'KENAIKAN_PANGKAT' => 'Kenaikan Pangkat',
            'KGB' => 'KGB',
            'PENSIUN' => 'Pensiun',
            'KONTRAK_PPPK' => 'Kontrak PPPK',
        ];

        $configDays = fn (string $key, int $default): int => (int) EwsConfig::getVal($key, (string) $default);
        $dayLabel = fn (int $days): string => 'H-' . $days;
        $monthLabel = function (int $days): string {
            if ($days >= 365 && $days % 365 === 0) {
                return 'H-' . ((int) ($days / 365)) . ' tahun';
            }

            if ($days >= 28) {
                return 'H-' . ((int) round($days / 30)) . ' bulan';
            }

            return 'H-' . $days . ' hari';
        };

        $points = function (array $days, callable $labeler): array {
            $mapped = [];
            foreach ($days as $day) {
                $mapped[$day] = $labeler($day);
            }

            return $mapped;
        };

        $thresholdMap = [
            'KENAIKAN_PANGKAT' => [
                'days' => [
                    $configDays('pangkat_h90', 90),
                    $configDays('pangkat_h60', 60),
                    $configDays('pangkat_h30', 30),
                ],
                'labeler' => $dayLabel,
            ],
            'KGB' => [
                'days' => [
                    $configDays('kgb_h60', 60),
                    $configDays('kgb_h30', 30),
                    $configDays('kgb_h14', 14),
                ],
                'labeler' => $dayLabel,
            ],
            'PENSIUN' => [
                'days' => [
                    $configDays('pensiun_y1', 365),
                    $configDays('pensiun_m6', 180),
                    $configDays('pensiun_m3', 90),
                ],
                'labeler' => $monthLabel,
            ],
            'KONTRAK_PPPK' => [
                'days' => [
                    $configDays('pppk_m6', 180),
                    $configDays('pppk_m3', 90),
                    $configDays('pppk_m1', 30),
                ],
                'labeler' => $monthLabel,
            ],
        ];

        $filterEvent = $request->query('event', '');
        $query = EwsAlert::with(['employee.disciplineRecords'])->where('is_processed', false);

        if ($filterEvent !== '') {
            $filterType = array_search($filterEvent, $typeLabels, true);
            $query->where('type', $filterType ?: '__invalid__');
        }

        $alerts = $query
            ->orderBy('target_date')
            ->get()
            ->filter(fn (EwsAlert $alert) => $alert->employee !== null)
            ->map(function (EwsAlert $alert) use ($typeLabels, $thresholdMap, $points): array {
                $employee = $alert->employee;
                $thresholdConfig = $thresholdMap[$alert->type] ?? ['days' => [], 'labeler' => fn (int $days): string => 'H-' . $days];
                $thresholdPoints = $points($thresholdConfig['days'], $thresholdConfig['labeler']);
                $thresholdSchedule = array_values($thresholdPoints);
                $sisaHari = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($alert->target_date)->startOfDay(), false);
                $activeThreshold = $thresholdPoints[$alert->interval_days] ?? 'H-' . $alert->interval_days;

                $isEligible = true;
                $reason = 'Perlu tindak lanjut';
                $eligibilityChecks = [];

                if ($alert->type === 'KENAIKAN_PANGKAT') {
                    $isKinerjaBaik = $employee->is_kinerja_baik === true;
                    $hasActiveDiscipline = $employee->disciplineRecords->contains('is_active', true);
                    $isEligible = $isKinerjaBaik && !$hasActiveDiscipline;

                    $passedChecks = [];
                    if (!$isKinerjaBaik) {
                        $passedChecks[] = 'Kinerja perlu ditinjau';
                    }
                    if ($hasActiveDiscipline) {
                        $passedChecks[] = 'Hukuman disiplin aktif';
                    }
                    $reason = empty($passedChecks) ? 'Kinerja baik' : implode(', ', $passedChecks);

                    $eligibilityChecks[] = [
                        'label' => 'Kinerja baik',
                        'passed' => $isKinerjaBaik,
                    ];
                    $eligibilityChecks[] = [
                        'label' => 'Bebas hukuman disiplin',
                        'passed' => !$hasActiveDiscipline,
                    ];
                }

                return [
                    'pegawai_id' => $employee->id,
                    'nama' => $employee->nama_lengkap,
                    'nip' => $employee->nip,
                    'jenis_event' => $typeLabels[$alert->type] ?? $alert->type,
                    'tanggal_target' => \Carbon\Carbon::parse($alert->target_date)->format('Y-m-d'),
                    'sisa_hari' => $sisaHari,
                    'threshold_label' => $activeThreshold,
                    'threshold_schedule' => $thresholdSchedule,
                    'is_eligible' => $isEligible,
                    'eligibility_reason' => $reason,
                    'eligibility_checks' => $eligibilityChecks,
                ];
            })
            ->values()
            ->all();

        usort($alerts, function ($a, $b) {
            return $a['sisa_hari'] - $b['sisa_hari'];
        });

        return view('admin.ews.aktif', [
            'alerts' => $alerts,
            'filterEvent' => $filterEvent,
            'title' => 'EWS Aktif'
        ]);
    }
}
