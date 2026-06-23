<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EwsConfig;
use Illuminate\Http\Request;

class EwsController extends Controller
{
    /**
     * Display the EWS Active page.
     */
    public function index(Request $request)
    {
        // Enforce authorization (Super Admin, Admin Kepegawaian, Pimpinan)
        $activeRole = session('active_role');
        $allowedRoles = ['Super Admin', 'Admin Kepegawaian', 'Pimpinan'];
        if (!in_array($activeRole, $allowedRoles)) {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin, Admin Kepegawaian, dan Pimpinan.');
        }

        // We build the alerts dynamically relative to current date so that they always show correctly.
        // We match employees in PegawaiController::$pegawaiList.
        $pegawaiList = PegawaiController::$pegawaiList;
        
        $alertsData = [
            [
                'pegawai_id' => 1,
                'jenis_event' => 'KGB',
                'sisa_hari' => 12,
            ],
            [
                'pegawai_id' => 4,
                'jenis_event' => 'Kenaikan Pangkat',
                'sisa_hari' => 25,
            ],
            [
                'pegawai_id' => 5,
                'jenis_event' => 'Kontrak PPPK',
                'sisa_hari' => 45,
            ],
            [
                'pegawai_id' => 2,
                'jenis_event' => 'KGB',
                'sisa_hari' => 75,
            ],
            [
                'pegawai_id' => 7,
                'jenis_event' => 'Kenaikan Pangkat',
                'sisa_hari' => 95,
            ],
            [
                'pegawai_id' => 6,
                'jenis_event' => 'Pensiun',
                'sisa_hari' => 120,
            ],
        ];

        $alerts = [];
        $thresholdMap = [
            'Kenaikan Pangkat' => [
                'schedule' => ['H-90', 'H-60', 'H-30'],
                'points' => [
                    90 => 'H-90',
                    60 => 'H-60',
                    30 => 'H-30',
                ],
            ],
            'KGB' => [
                'schedule' => ['H-60', 'H-30', 'H-14'],
                'points' => [
                    60 => 'H-60',
                    30 => 'H-30',
                    14 => 'H-14',
                ],
            ],
            'Pensiun' => [
                'schedule' => ['H-1 tahun', 'H-6 bulan', 'H-3 bulan'],
                'points' => [
                    365 => 'H-1 tahun',
                    180 => 'H-6 bulan',
                    90 => 'H-3 bulan',
                ],
            ],
            'Kontrak PPPK' => [
                'schedule' => ['H-6 bulan', 'H-3 bulan', 'H-1 bulan'],
                'points' => [
                    180 => 'H-6 bulan',
                    90 => 'H-3 bulan',
                    30 => 'H-1 bulan',
                ],
            ],
        ];

        foreach ($alertsData as $data) {
            $pegawai = null;
            foreach ($pegawaiList as $p) {
                if ($p['id'] == $data['pegawai_id']) {
                    $pegawai = $p;
                    break;
                }
            }

            if ($pegawai) {
                // Sisa hari is determined relative to the current real date
                $targetDate = now()->addDays($data['sisa_hari'])->format('Y-m-d');
                $thresholdConfig = $thresholdMap[$data['jenis_event']] ?? ['schedule' => [], 'points' => []];
                $activeThreshold = 'Di luar ambang';

                foreach ($thresholdConfig['points'] as $dayLimit => $label) {
                    if ($data['sisa_hari'] <= $dayLimit) {
                        $activeThreshold = $label;
                    }
                }
                
                // Determine eligibility and reason. For Kenaikan Pangkat, surface
                // the three design checks required by the EWS document.
                $isEligible = true;
                $reason = 'Layak';
                $eligibilityChecks = [];

                if ($data['jenis_event'] === 'Kenaikan Pangkat') {
                    $eligibilityChecks = [
                        [
                            'label' => '4 tahun terpenuhi',
                            'passed' => true,
                        ],
                        [
                            'label' => 'Tidak ada hukuman disiplin aktif',
                            'passed' => true,
                        ],
                        [
                            'label' => 'Kinerja baik',
                            'passed' => $pegawai['kinerja_baik'] === true,
                        ],
                    ];

                    if ($pegawai['kinerja_baik'] !== true) {
                        $isEligible = false;
                        $reason = 'Kinerja kurang baik';
                    } else {
                        $reason = 'Memenuhi 3 syarat';
                    }
                }

                $alerts[] = [
                    'pegawai_id' => $pegawai['id'],
                    'nama' => $pegawai['nama'],
                    'nip' => $pegawai['nip'],
                    'jenis_event' => $data['jenis_event'],
                    'tanggal_target' => $targetDate,
                    'sisa_hari' => $data['sisa_hari'],
                    'threshold_label' => $activeThreshold,
                    'threshold_schedule' => $thresholdConfig['schedule'],
                    'is_eligible' => $isEligible,
                    'eligibility_reason' => $reason,
                    'eligibility_checks' => $eligibilityChecks,
                ];
            }
        }

        // Apply event filter if set
        $filterEvent = $request->query('event', '');
        if ($filterEvent !== '') {
            $alerts = array_filter($alerts, function ($alert) use ($filterEvent) {
                return $alert['jenis_event'] === $filterEvent;
            });
        }

        // Sort by sisa_hari ascending (AC-1)
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
