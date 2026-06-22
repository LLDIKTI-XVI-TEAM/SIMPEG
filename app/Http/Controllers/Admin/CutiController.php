<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CutiController extends Controller
{
    public static $riwayatCuti = [
        ['id' => 1, 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-06-20', 'selesai' => '2026-06-24', 'hari' => 5, 'status' => 'menunggu', 'tgl_pengajuan' => '2026-06-18', 'alasan' => 'Acara keluarga di luar kota', 'nama' => 'Ahmad Fauzi'],
        ['id' => 2, 'jenis' => 'Cuti Sakit', 'mulai' => '2026-04-10', 'selesai' => '2026-04-12', 'hari' => 3, 'status' => 'disetujui', 'tgl_pengajuan' => '2026-04-09', 'alasan' => 'Sakit demam berdarah', 'nama' => 'Siti Rahayu'],
        ['id' => 3, 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-02-01', 'selesai' => '2026-02-05', 'hari' => 5, 'status' => 'disetujui', 'tgl_pengajuan' => '2026-01-28', 'alasan' => 'Urusan keluarga mendesak', 'nama' => 'Budi Santoso'],
        ['id' => 4, 'jenis' => 'Cuti Melahirkan', 'mulai' => '2025-10-01', 'selesai' => '2025-12-29', 'hari' => 90, 'status' => 'disetujui', 'tgl_pengajuan' => '2025-09-15', 'alasan' => 'Persalinan anak pertama', 'nama' => 'Dewi Pertiwi'],
    ];

    public function index()
    {
        return view('admin.cuti.index');
    }

    public function show($id)
    {
        $c = collect(self::$riwayatCuti)->firstWhere('id', (int)$id);
        if (!$c) {
            abort(404);
        }
        return view('admin.cuti.show', compact('c'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'jenis_cuti' => 'required|string',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'alasan' => 'required|string|max:500',
        ]);

        return redirect()->route('cuti')
            ->with('success', 'Pengajuan cuti ' . $request->input('jenis_cuti') . ' berhasil dikirim dan menunggu persetujuan.');
    }

    public function approval()
    {
        // Lists pending requests
        $pendingRequests = collect(self::$riwayatCuti)->where('status', 'menunggu')->all();
        return view('admin.cuti.approval', compact('pendingRequests'));
    }

    public function approve($id)
    {
        $c = collect(self::$riwayatCuti)->firstWhere('id', (int)$id);
        $nama = $c ? $c['nama'] : 'Pegawai';
        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti ' . $nama . ' telah disetujui.');
    }

    public function postpone($id)
    {
        $c = collect(self::$riwayatCuti)->firstWhere('id', (int)$id);
        $nama = $c ? $c['nama'] : 'Pegawai';
        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti ' . $nama . ' telah ditunda.');
    }
}
