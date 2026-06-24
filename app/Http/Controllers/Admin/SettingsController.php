<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        if (session('active_role') !== 'super_admin') {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin.');
        }

        return view('admin.settings.index');
    }

    public function update(Request $request)
    {
        if (session('active_role') !== 'super_admin') {
            abort(403, 'Aksi tidak diizinkan. Halaman ini hanya untuk Super Admin.');
        }

        // Write Audit Log
        $dynamicLogs = session('dynamic_audit_logs', []);
        $newId = count($dynamicLogs) + count(\App\Http\Controllers\Admin\AuditController::$auditLogs) + 1;

        $dynamicLogs[] = [
            'id' => $newId,
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'operator' => auth()->user()->name ?? 'super_admin',
            'event' => 'UPDATE_SETTINGS',
            'kategori' => 'konfigurasi_sistem',
            'modul' => 'Settings',
            'record_id' => 'System Config',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => [
                'note' => 'Konfigurasi lama'
            ],
            'new_values' => [
                'note' => 'Konfigurasi sistem diperbarui'
            ]
        ];

        session(['dynamic_audit_logs' => $dynamicLogs]);

        return redirect()->route('pengaturan')
            ->with('success', 'Konfigurasi sistem berhasil disimpan.');
    }
}
