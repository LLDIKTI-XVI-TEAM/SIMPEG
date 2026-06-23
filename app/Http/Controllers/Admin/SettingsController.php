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
        // Mock save configurations
        return redirect()->route('pengaturan')
            ->with('success', 'Konfigurasi sistem berhasil disimpan.');
    }
}
