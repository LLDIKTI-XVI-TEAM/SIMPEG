<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PegawaiController extends Controller
{
    public static $pegawaiList = [
        [
            'id' => 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19850312201001 1 001',
            'jabatan' => 'Analis Kepegawaian',
            'unit' => 'Bag. Umum',
            'golongan' => 'III/c',
            'jenis' => 'PNS',
            'tmt' => '01-10-2010',
            'status' => 'aktif',
            'dok' => 'ok',
            'email' => 'ahmadfauzi@gmail.com',
            'telepon' => '081234567890'
        ],
        [
            'id' => 2,
            'nama' => 'Siti Rahayu',
            'nip' => '19901120201501 2 003',
            'jabatan' => 'Analis Ahli Madya',
            'unit' => 'Bag. Keuangan',
            'golongan' => 'II/d',
            'jenis' => 'PNS',
            'tmt' => '01-01-2015',
            'status' => 'aktif',
            'dok' => 'warn',
            'email' => 'sitirahayu@gmail.com',
            'telepon' => '085298765432'
        ],
        [
            'id' => 3,
            'nama' => 'Sabrina Rossa Adriani Wibowo',
            'nip' => '20261210820500 0 04',
            'jabatan' => 'Analis SDM Aparatur Ahli Pertama',
            'unit' => 'Bag. SDM',
            'golongan' => 'III/a',
            'jenis' => 'CPNS',
            'tmt' => '01-01-2026',
            'status' => 'aktif',
            'dok' => 'ok',
            'email' => 'sabrinarossa24@gmail.com',
            'telepon' => '081285066001'
        ],
        [
            'id' => 4,
            'nama' => 'Cimma Sari Oktariani Di Silapu',
            'nip' => '26110820520600 0 04',
            'jabatan' => 'Pranata SDM Terampil',
            'unit' => 'Bag. IT',
            'golongan' => 'III/c',
            'jenis' => 'CPNS',
            'tmt' => '01-10-2020',
            'status' => 'aktif',
            'dok' => 'danger',
            'email' => 'sikaemma@gmail.com',
            'telepon' => '081258206006'
        ],
        [
            'id' => 5,
            'nama' => 'Nurarningsih Dumbea, S.P.',
            'nip' => '19930315201903 2 002',
            'jabatan' => 'Pejabat Lelang Operational',
            'unit' => 'Bag. Umum',
            'golongan' => 'II/b',
            'jenis' => 'PPPK',
            'tmt' => '01-11-2021',
            'status' => 'aktif',
            'dok' => 'ok',
            'email' => 'rainingdumbea47@gmail.com',
            'telepon' => '082302200526'
        ]
    ];

    public function index()
    {
        return view('admin.pegawai.index');
    }

    public function create()
    {
        return view('admin.pegawai.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:255',
            'nip' => 'required|string|max:50',
            'jabatan' => 'required|string|max:255',
            'unit' => 'required|string',
            'golongan' => 'required|string',
            'jenis' => 'required|string',
            'tmt' => 'required|string',
        ]);

        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai ' . $request->input('nama') . ' berhasil ditambahkan.');
    }

    public function show($id)
    {
        $p = collect(self::$pegawaiList)->firstWhere('id', (int)$id);
        if (!$p) {
            abort(404);
        }
        return view('admin.pegawai.show', compact('p'));
    }

    public function edit($id)
    {
        $p = collect(self::$pegawaiList)->firstWhere('id', (int)$id);
        if (!$p) {
            abort(404);
        }
        return view('admin.pegawai.edit', compact('p'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nama' => 'required|string|max:255',
            'nip' => 'required|string|max:50',
            'jabatan' => 'required|string|max:255',
            'unit' => 'required|string',
            'golongan' => 'required|string',
            'jenis' => 'required|string',
            'tmt' => 'required|string',
        ]);

        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai ' . $request->input('nama') . ' berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $p = collect(self::$pegawaiList)->firstWhere('id', (int)$id);
        $nama = $p ? $p['nama'] : 'Pegawai';
        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai ' . $nama . ' berhasil dihapus dari sistem.');
    }
}
