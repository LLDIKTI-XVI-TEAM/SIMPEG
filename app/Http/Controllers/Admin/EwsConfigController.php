<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ShowEwsConfigPageAction;
use App\Actions\Ews\UpdateEwsConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ews\UpdateEwsConfigRequest;

class EwsConfigController extends Controller
{
    /**
     * Menampilkan halaman konfigurasi EWS.
     */
    public function index(ShowEwsConfigPageAction $action)
    {
        return view('admin.ews.konfigurasi', $action->execute());
    }

    /**
     * Menyimpan perubahan konfigurasi EWS.
     */
    public function update(UpdateEwsConfigRequest $request, UpdateEwsConfigAction $action)
    {
        $action->execute($request);

        return redirect()->route('ews.config')->with('success', 'Konfigurasi EWS berhasil diperbarui.');
    }
}
