<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public static $auditLogs = [
        [
            'id' => 8,
            'timestamp' => '2026-06-20 11:45:00',
            'operator' => 'Ahmad Fauzi',
            'event' => 'POSTPONE',
            'kategori' => 'transaksi_cuti',
            'modul' => 'LeaveRequest',
            'record_id' => '2',
            'ip_address' => '192.168.1.102',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/127.0',
            'old_values' => [
                'status' => 'menunggu'
            ],
            'new_values' => [
                'status' => 'ditunda'
            ]
        ],
        [
            'id' => 7,
            'timestamp' => '2026-06-20 10:30:00',
            'operator' => 'Demo Klabat',
            'event' => 'RESTORE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => '3',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'old_values' => [
                'status' => 'nonaktif'
            ],
            'new_values' => [
                'status' => 'aktif'
            ]
        ],
        [
            'id' => 6,
            'timestamp' => '2026-06-20 09:15:00',
            'operator' => 'Demo Klabat',
            'event' => 'SOFT_DELETE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => '3',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'old_values' => [
                'status' => 'aktif'
            ],
            'new_values' => [
                'status' => 'nonaktif'
            ]
        ],
        [
            'id' => 5,
            'timestamp' => '2026-06-19 14:00:00',
            'operator' => 'Ahmad Fauzi',
            'event' => 'LOGOUT',
            'kategori' => 'autentikasi',
            'modul' => 'User',
            'record_id' => '1',
            'ip_address' => '192.168.1.102',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
            'old_values' => null,
            'new_values' => [
                'status' => 'logged_out'
            ]
        ],
        [
            'id' => 4,
            'timestamp' => '2026-06-19 13:42:15',
            'operator' => 'Ahmad Fauzi',
            'event' => 'LOGIN',
            'kategori' => 'autentikasi',
            'modul' => 'User',
            'record_id' => '1',
            'ip_address' => '192.168.1.102',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
            'old_values' => null,
            'new_values' => [
                'last_login_at' => '2026-06-19 13:42:15',
                'last_login_ip' => '192.168.1.102'
            ]
        ],
        [
            'id' => 3,
            'timestamp' => '2026-06-19 11:20:04',
            'operator' => 'Demo Klabat',
            'event' => 'CREATE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => '3',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'old_values' => null,
            'new_values' => [
                'nama' => 'Sabrina Rossa',
                'nip' => '20261210820500004',
                'email' => 'sabrinarossa24@gmail.com',
                'status' => 'aktif'
            ]
        ],
        [
            'id' => 2,
            'timestamp' => '2026-06-19 10:15:30',
            'operator' => 'Ahmad Fauzi',
            'event' => 'APPROVE',
            'kategori' => 'transaksi_cuti',
            'modul' => 'LeaveRequest',
            'record_id' => '2',
            'ip_address' => '192.168.1.102',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/127.0',
            'old_values' => [
                'status' => 'menunggu',
                'approved_by_atasan' => false
            ],
            'new_values' => [
                'status' => 'menunggu_kabag',
                'approved_by_atasan' => true,
                'approved_by_atasan_at' => '2026-06-19 10:15:30'
            ]
        ],
        [
            'id' => 1,
            'timestamp' => '2026-06-18 16:05:00',
            'operator' => 'Demo Klabat',
            'event' => 'UPDATE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => '3',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'old_values' => [
                'email' => 'sabrinarossa@gmail.com',
                'status' => 'cuti'
            ],
            'new_values' => [
                'email' => 'sabrinarossa24@gmail.com',
                'status' => 'aktif'
            ]
        ]
    ];

    public function index()
    {
        if (!in_array(session('active_role'), ['Super Admin', 'Admin Kepegawaian'])) {
            abort(403, 'Unauthorized action.');
        }

        $dynamicLogs = session('dynamic_audit_logs', []);
        $allLogs = array_merge($dynamicLogs, self::$auditLogs);

        usort($allLogs, function ($a, $b) {
            return $b['id'] - $a['id'];
        });

        return view('admin.audit.index', [
            'auditLogs' => $allLogs
        ]);
    }

    public function show($id)
    {
        if (!in_array(session('active_role'), ['Super Admin', 'Admin Kepegawaian'])) {
            abort(403, 'Unauthorized action.');
        }

        $dynamicLogs = session('dynamic_audit_logs', []);
        $allLogs = array_merge($dynamicLogs, self::$auditLogs);
        $log = collect($allLogs)->firstWhere('id', (int)$id);
        if (!$log) {
            abort(404);
        }
        return view('admin.audit.show', compact('log'));
    }
}
