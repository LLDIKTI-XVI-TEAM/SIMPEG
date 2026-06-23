<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HariLiburController extends Controller
{
    public static $hariLiburData = [
        ['id' => 1, 'tanggal' => '2026-01-01', 'nama' => 'Tahun Baru 2026 Masehi', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 2, 'tanggal' => '2026-02-17', 'nama' => 'Isra Mikraj Nabi Muhammad SAW', 'tipe' => 'libur_nasional', 'hari' => 'Selasa'],
        ['id' => 3, 'tanggal' => '2026-03-19', 'nama' => 'Hari Suci Nyepi Saka 1948', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 4, 'tanggal' => '2026-03-20', 'nama' => 'Cuti Bersama Nyepi', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
        ['id' => 5, 'tanggal' => '2026-04-03', 'nama' => 'Wafat Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 6, 'tanggal' => '2026-04-05', 'nama' => 'Hari Raya Paskah', 'tipe' => 'libur_nasional', 'hari' => 'Minggu'],
        ['id' => 7, 'tanggal' => '2026-05-01', 'nama' => 'Hari Buruh Internasional', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
        ['id' => 8, 'tanggal' => '2026-05-13', 'nama' => 'Hari Raya Waisak 2570 BE', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 9, 'tanggal' => '2026-05-14', 'nama' => 'Kenaikan Yesus Kristus', 'tipe' => 'libur_nasional', 'hari' => 'Kamis'],
        ['id' => 10, 'tanggal' => '2026-05-15', 'nama' => 'Cuti Bersama Kenaikan Yesus', 'tipe' => 'cuti_bersama', 'hari' => 'Jumat'],
        ['id' => 11, 'tanggal' => '2026-06-01', 'nama' => 'Hari Lahir Pancasila', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 12, 'tanggal' => '2026-06-17', 'nama' => 'Hari Raya Idul Adha 1447 H', 'tipe' => 'libur_nasional', 'hari' => 'Rabu'],
        ['id' => 13, 'tanggal' => '2026-08-17', 'nama' => 'HUT Kemerdekaan RI', 'tipe' => 'libur_nasional', 'hari' => 'Senin'],
        ['id' => 14, 'tanggal' => '2026-12-25', 'nama' => 'Hari Raya Natal', 'tipe' => 'libur_nasional', 'hari' => 'Jumat'],
    ];

    public function index()
    {
        return view('admin.hari-libur.index');
    }

    public function store(Request $request)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'nama' => 'required|string|max:255',
            'tipe' => 'required|string',
        ]);

        return redirect()->route('hari-libur')
            ->with('success', 'Hari libur "' . $request->input('nama') . '" berhasil ditambahkan.');
    }

    public function edit($id)
    {
        $hl = collect(self::$hariLiburData)->firstWhere('id', (int)$id);
        if (!$hl) {
            abort(404);
        }
        return view('admin.hari-libur.edit', compact('hl'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'nama' => 'required|string|max:255',
            'tipe' => 'required|string',
        ]);

        return redirect()->route('hari-libur')
            ->with('success', 'Hari libur "' . $request->input('nama') . '" berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $hl = collect(self::$hariLiburData)->firstWhere('id', (int)$id);
        $nama = $hl ? $hl['nama'] : 'Hari Libur';
        return redirect()->route('hari-libur')
            ->with('success', 'Hari libur "' . $nama . '" berhasil dihapus dari daftar.');
    }
}
