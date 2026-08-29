<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ShowEwsConfigPageAction;
use App\Actions\Ews\UpdateEwsConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ews\UpdateEwsConfigRequest;
use Illuminate\Http\Request;

class EwsConfigController extends Controller
{
    /**
     * Menampilkan halaman konfigurasi EWS.
     */
    public function index(Request $request, ShowEwsConfigPageAction $action)
    {
        $logPerPage = $request->integer('log_per_page', 10);
        $logPerPage = in_array($logPerPage, [10, 25, 50], true) ? $logPerPage : 10;

        return view('admin.ews.konfigurasi', $action->execute($logPerPage));
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
