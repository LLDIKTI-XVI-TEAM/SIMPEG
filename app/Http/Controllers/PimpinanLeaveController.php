<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PimpinanLeaveController extends Controller
{
    public function index(Request $request)
    {
        // Dummy data for leave list
        $leaves = [
            ['id' => Str::uuid(), 'nip' => '198001012005011001', 'nama' => 'Budi Santoso', 'jenis_cuti' => 'Cuti Tahunan', 'tanggal_mulai' => '2026-08-01', 'tanggal_selesai' => '2026-08-05', 'status' => 'Menunggu Keputusan Pimpinan'],
            ['id' => Str::uuid(), 'nip' => '198205122008012003', 'nama' => 'Siti Aminah', 'jenis_cuti' => 'Cuti Melahirkan', 'tanggal_mulai' => '2026-09-01', 'tanggal_selesai' => '2026-11-30', 'status' => 'Disetujui'],
            ['id' => Str::uuid(), 'nip' => '197511202000121001', 'nama' => 'Andi Darmawan', 'jenis_cuti' => 'Cuti Besar', 'tanggal_mulai' => '2026-10-15', 'tanggal_selesai' => '2026-11-15', 'status' => 'Menunggu Keputusan Pimpinan'],
            ['id' => Str::uuid(), 'nip' => '198509152010122002', 'nama' => 'Rina Mulyani', 'jenis_cuti' => 'Cuti Alasan Penting', 'tanggal_mulai' => '2026-07-20', 'tanggal_selesai' => '2026-07-25', 'status' => 'Ditangguhkan'],
        ];

        return view('pimpinan.cuti.index', compact('leaves'));
    }

    public function show($leave)
    {
        // Dummy data for leave detail
        $leaveData = [
            'id' => $leave,
            'nip' => '198001012005011001',
            'nama' => 'Budi Santoso, S.Kom., M.T.',
            'jenis_cuti' => 'Cuti Tahunan',
            'alasan' => 'Menjenguk orang tua yang sedang sakit di kampung halaman.',
            'tanggal_mulai' => '2026-08-01',
            'tanggal_selesai' => '2026-08-05',
            'lama_cuti' => '5 Hari Kerja',
            'alamat_cuti' => 'Jl. Pahlawan No. 45, Surabaya, Jawa Timur',
            'telepon' => '081234567890',
            'status' => 'Menunggu Keputusan Pimpinan',
            'approval_atasan' => [
                'nama' => 'Dr. H. Sudirman, M.Si.',
                'jabatan' => 'Kepala Bagian Kepegawaian',
                'status' => 'Disetujui',
                'tanggal' => '2026-07-06 14:30:00',
                'catatan' => 'Silakan, tugas sudah didelegasikan.'
            ],
            'sisa_cuti' => [
                'N' => 12,
                'N_1' => 4,
                'N_2' => 0
            ]
        ];

        return view('pimpinan.cuti.show', compact('leaveData'));
    }
}
