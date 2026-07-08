<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PimpinanReportController extends Controller
{
    public function index()
    {
        return view('pimpinan.laporan.index');
    }

    public function employees()
    {
        // Dummy table for employees report preview
        $previewData = [
            ['nip' => '198001012005011001', 'nama' => 'Budi Santoso', 'golongan' => 'IV/a', 'jabatan' => 'Analis Kepegawaian Muda', 'status' => 'aktif'],
            ['nip' => '198205122008012003', 'nama' => 'Siti Aminah', 'golongan' => 'III/d', 'jabatan' => 'Perancang Peraturan', 'status' => 'aktif'],
            ['nip' => '197511202000121001', 'nama' => 'Andi Darmawan', 'golongan' => 'IV/c', 'jabatan' => 'Auditor Utama', 'status' => 'aktif'],
        ];
        return view('pimpinan.laporan.pegawai', compact('previewData'));
    }

    public function customEmployees(Request $request)
    {
        // Dummy redirect back with success message for export trigger
        return redirect()->route('pimpinan.laporan.pegawai')
            ->with('success', 'Laporan Custom Pegawai berhasil di-generate dan akan segera diunduh.');
    }

    public function leaves()
    {
        // Dummy table for leaves report preview
        $previewData = [
            ['nama' => 'Budi Santoso', 'jenis' => 'Cuti Tahunan', 'mulai' => '2026-08-01', 'selesai' => '2026-08-05', 'lama' => '5 Hari', 'status' => 'Disetujui'],
            ['nama' => 'Rina Mulyani', 'jenis' => 'Cuti Alasan Penting', 'mulai' => '2026-07-20', 'selesai' => '2026-07-25', 'lama' => '5 Hari', 'status' => 'Ditangguhkan'],
        ];
        return view('pimpinan.laporan.cuti', compact('previewData'));
    }

    public function rankHistories()
    {
        // Dummy table for rank histories report preview
        $previewData = [
            ['nama' => 'Budi Santoso', 'golongan_lama' => 'III/d', 'golongan_baru' => 'IV/a', 'tmt' => '2025-10-01', 'sk' => '01/KEP/2025', 'status' => 'Selesai'],
            ['nama' => 'Siti Aminah', 'golongan_lama' => 'III/c', 'golongan_baru' => 'III/d', 'tmt' => '2026-04-01', 'sk' => '45/KEP/2026', 'status' => 'Selesai'],
        ];
        return view('pimpinan.laporan.kepangkatan', compact('previewData'));
    }
}
