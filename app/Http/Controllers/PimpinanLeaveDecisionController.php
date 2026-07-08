<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PimpinanLeaveDecisionController extends Controller
{
    public function store(Request $request, $leave)
    {
        // This is a dummy endpoint that just redirects back with a success message
        $request->validate([
            'keputusan' => 'required|in:DISETUJUI,PERUBAHAN,DITANGGUHKAN,TIDAK_DISETUJUI',
            'catatan' => 'nullable|string'
        ]);

        $keputusan = $request->keputusan;
        $label = match($keputusan) {
            'DISETUJUI' => 'disetujui',
            'PERUBAHAN' => 'disetujui dengan perubahan',
            'DITANGGUHKAN' => 'ditangguhkan',
            'TIDAK_DISETUJUI' => 'tidak disetujui',
            default => 'diproses'
        };

        return redirect()->route('pimpinan.cuti.index')
            ->with('success', "Keputusan pengajuan cuti berhasil disimpan (Status: {$label}).");
    }
}
