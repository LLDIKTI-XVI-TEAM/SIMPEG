<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PimpinanEwsController extends Controller
{
    public function index(Request $request)
    {
        // Dummy data for EWS list
        $ewsList = [
            ['nama' => 'Budi Santoso', 'nip' => '198001012005011001', 'jenis' => 'Kenaikan Pangkat', 'sisa_hari' => 15, 'status' => 'Belum Diproses', 'indikator' => 'merah'],
            ['nama' => 'Siti Aminah', 'nip' => '198205122008012003', 'jenis' => 'Kenaikan Gaji Berkala', 'sisa_hari' => 28, 'status' => 'Belum Diproses', 'indikator' => 'merah'],
            ['nama' => 'Andi Darmawan', 'nip' => '197511202000121001', 'jenis' => 'Batas Usia Pensiun', 'sisa_hari' => 45, 'status' => 'Diproses', 'indikator' => 'kuning'],
            ['nama' => 'Rina Mulyani', 'nip' => '198509152010122002', 'jenis' => 'Kenaikan Pangkat', 'sisa_hari' => 80, 'status' => 'Belum Diproses', 'indikator' => 'kuning'],
            ['nama' => 'Herman Susilo', 'nip' => '199003052015041001', 'jenis' => 'Satyalancana Karya Satya', 'sisa_hari' => 120, 'status' => 'Aman', 'indikator' => 'hijau'],
            ['nama' => 'Agus Pranoto', 'nip' => '198802142010121004', 'jenis' => 'Berakhir Tugas Belajar', 'sisa_hari' => 150, 'status' => 'Aman', 'indikator' => 'hijau'],
        ];

        // Apply dummy filter if requested
        if ($request->has('jenis')) {
            $jenis = str_replace('_', ' ', strtolower($request->jenis));
            $ewsList = array_filter($ewsList, function($item) use ($jenis) {
                return str_contains(strtolower($item['jenis']), $jenis);
            });
        }

        return view('pimpinan.ews.index', compact('ewsList'));
    }
}
