<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {


        return view('admin.settings.index');
    }

    public function update(Request $request)
    {


        // Write Audit Log
        $dynamicLogs = session('dynamic_audit_logs', []);
        $newId = count($dynamicLogs) + 1;

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
