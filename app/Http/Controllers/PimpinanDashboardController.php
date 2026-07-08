<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PimpinanDashboardController extends Controller
{
    public function index()
    {
        // Dummy data for W1 Komposisi Pegawai
        $totalPegawai = 125;
        $komposisi = [
            'PNS' => 80,
            'PPPK' => 30,
            'CPNS' => 15,
        ];

        // Dummy data for W2 Kenaikan Pangkat
        $naikPangkatBulanIni = 3;
        $naikPangkatTahunIni = 12;

        // Dummy data for W3 Status Cuti
        $cutiPending = 5;
        $cutiDisetujuiBulanIni = 18;
        $cutiDitunda = 2;

        // Dummy data for W4 EWS Aktif (Top 5)
        $ewsAktif = [
            ['nama' => 'Budi Santoso', 'nip' => '198001012005011001', 'jenis' => 'Kenaikan Pangkat', 'sisa_hari' => 15, 'status' => 'Belum Diproses', 'indikator' => 'merah'],
            ['nama' => 'Siti Aminah', 'nip' => '198205122008012003', 'jenis' => 'KGB', 'sisa_hari' => 28, 'status' => 'Belum Diproses', 'indikator' => 'merah'],
            ['nama' => 'Andi Darmawan', 'nip' => '197511202000121001', 'jenis' => 'BUP', 'sisa_hari' => 45, 'status' => 'Diproses', 'indikator' => 'kuning'],
            ['nama' => 'Rina Mulyani', 'nip' => '198509152010122002', 'jenis' => 'Kenaikan Pangkat', 'sisa_hari' => 80, 'status' => 'Belum Diproses', 'indikator' => 'kuning'],
            ['nama' => 'Herman Susilo', 'nip' => '199003052015041001', 'jenis' => 'Satyalancana', 'sisa_hari' => 120, 'status' => 'Aman', 'indikator' => 'hijau'],
        ];

        // Dummy data for W5 Distribusi Golongan
        $distribusiGolongan = [
            'I' => 5,
            'II' => 35,
            'III' => 60,
            'IV' => 25,
        ];

        // Dummy data for W6 Audit Terbaru (Top 5 Read-Only)
        $auditTerbaru = [
            ['aksi' => 'Update Data Pegawai', 'user' => 'Admin Kepegawaian', 'waktu' => now()->subMinutes(15)->format('d M Y H:i')],
            ['aksi' => 'Approval Cuti Tahunan', 'user' => 'Atasan Langsung', 'waktu' => now()->subHours(2)->format('d M Y H:i')],
            ['aksi' => 'Tambah Dokumen SK', 'user' => 'Pegawai (Budi)', 'waktu' => now()->subHours(5)->format('d M Y H:i')],
            ['aksi' => 'Update Riwayat Jabatan', 'user' => 'Super Admin', 'waktu' => now()->subDay()->format('d M Y H:i')],
            ['aksi' => 'Login Sistem', 'user' => 'Pimpinan', 'waktu' => now()->subDays(2)->format('d M Y H:i')],
        ];

        // Dummy data for W7 Tren Pegawai 12 Bulan Terakhir
        $trenPegawai = [
            'Jan' => 110, 'Feb' => 112, 'Mar' => 112, 'Apr' => 115,
            'Mei' => 115, 'Jun' => 118, 'Jul' => 120, 'Ags' => 121,
            'Sep' => 121, 'Okt' => 124, 'Nov' => 124, 'Des' => 125,
        ];

        return view('pimpinan.dashboard', compact(
            'totalPegawai',
            'komposisi',
            'naikPangkatBulanIni',
            'naikPangkatTahunIni',
            'cutiPending',
            'cutiDisetujuiBulanIni',
            'cutiDitunda',
            'ewsAktif',
            'distribusiGolongan',
            'auditTerbaru',
            'trenPegawai'
        ));
    }
}
