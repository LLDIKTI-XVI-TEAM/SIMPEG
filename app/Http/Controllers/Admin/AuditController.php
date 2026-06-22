<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public static $auditLogs = [
        [
            'id' => 1,
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
            'id' => 2,
            'timestamp' => '2026-06-19 11:20:04',
            'operator' => 'Demo Klabat',
            'event' => 'CREATE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => 'c87f2807-ea0d-400f-bd34-f45d17da89db',
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
            'id' => 3,
            'timestamp' => '2026-06-19 10:15:30',
            'operator' => 'Ahmad Fauzi',
            'event' => 'APPROVE',
            'kategori' => 'transaksi_cuti',
            'modul' => 'LeaveRequest',
            'record_id' => '23',
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
            'id' => 4,
            'timestamp' => '2026-06-18 16:05:00',
            'operator' => 'Demo Klabat',
            'event' => 'UPDATE',
            'kategori' => 'data_pegawai',
            'modul' => 'Employee',
            'record_id' => 'c87f2807-ea0d-400f-bd34-f45d17da89db',
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
        ],
        [
            'id' => 5,
            'timestamp' => '2026-06-18 09:30:00',
            'operator' => 'Demo Klabat',
            'event' => 'IMPORT',
            'kategori' => 'system_import',
            'modul' => 'ExcelImport',
            'record_id' => 'import-20260618093000',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Console (CLI / Queue Worker)',
            'old_values' => null,
            'new_values' => [
                'file_name' => 'daftar_pegawai.xlsx',
                'status' => 'sukses',
                'records_imported' => 8,
                'records_skipped' => 0
            ]
        ]
    ];

    public function index()
    {
        return view('admin.audit.index');
    }

    public function show($id)
    {
        $log = collect(self::$auditLogs)->firstWhere('id', (int)$id);
        if (!$log) {
            abort(404);
        }
        return view('admin.audit.show', compact('log'));
    }
}
