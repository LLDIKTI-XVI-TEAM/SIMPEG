<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PimpinanEwsController extends Controller
{
    public function index(Request $request)
    {
        // Dummy data for EWS list
        $ewsList = [
            ['id' => (string) Str::uuid(), 'nama' => 'Budi Santoso', 'nip' => '198001012005011001', 'jenis' => 'Kenaikan Pangkat', 'sisa_hari' => 15, 'status' => 'Belum Diproses', 'indikator' => 'merah'],
            ['id' => (string) Str::uuid(), 'nama' => 'Siti Aminah', 'nip' => '198205122008012003', 'jenis' => 'Kenaikan Gaji Berkala', 'sisa_hari' => 28, 'status' => 'Belum Diproses', 'indikator' => 'merah'],
            ['id' => (string) Str::uuid(), 'nama' => 'Andi Darmawan', 'nip' => '197511202000121001', 'jenis' => 'Batas Usia Pensiun', 'sisa_hari' => 45, 'status' => 'Diproses', 'indikator' => 'kuning'],
            ['id' => (string) Str::uuid(), 'nama' => 'Rina Mulyani', 'nip' => '198509152010122002', 'jenis' => 'Kenaikan Pangkat', 'sisa_hari' => 80, 'status' => 'Belum Diproses', 'indikator' => 'kuning'],
            ['id' => (string) Str::uuid(), 'nama' => 'Herman Susilo', 'nip' => '199003052015041001', 'jenis' => 'Satyalancana Karya Satya', 'sisa_hari' => 120, 'status' => 'Aman', 'indikator' => 'hijau'],
            ['id' => (string) Str::uuid(), 'nama' => 'Agus Pranoto', 'nip' => '198802142010121004', 'jenis' => 'Berakhir Tugas Belajar', 'sisa_hari' => 150, 'status' => 'Aman', 'indikator' => 'hijau'],
        ];

        // Apply dummy filter if requested
        $ewsList = collect($ewsList)->filter(function($item) use ($request) {
            $match = true;
            if ($request->filled('search')) {
                $search = strtolower($request->search);
                if (!str_contains(strtolower($item['nama']), $search) && !str_contains($item['nip'], $search)) {
                    $match = false;
                }
            }
            if ($request->filled('jenis')) {
                if ($item['jenis'] !== $request->jenis) $match = false;
            }
            if ($request->filled('status')) {
                if ($item['status'] !== $request->status) $match = false;
            }
            if ($request->filled('indikator')) {
                if ($item['indikator'] !== $request->indikator) $match = false;
            }
            return $match;
        })->values()->all();

        return view('pimpinan.ews.index', compact('ewsList'));
    }
}
